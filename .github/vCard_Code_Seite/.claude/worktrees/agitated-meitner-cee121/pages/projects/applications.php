<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Project.php';
require_once __DIR__ . '/../../includes/models/User.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/MailService.php';

// Only board and manager can access
Auth::requireRole('manager');

$message = '';
$error = '';

// Get project_id from GET parameter
$projectId = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

if ($projectId <= 0) {
    header('Location: manage.php');
    exit;
}

// Get project details
$project = Project::getById($projectId);

if (!$project) {
    header('Location: manage.php?error=' . urlencode('Projekt nicht gefunden'));
    exit;
}

// Handle accept action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_application'])) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

    $applicationId = intval($_POST['application_id'] ?? 0);
    $role = $_POST['role'] ?? 'member';

    if (!in_array($role, ['lead', 'member'])) {
        $error = 'Ungültige Rolle ausgewählt';
    } else {
        try {
            $db = Database::getContentDB();

            // Get application details
            $stmt = $db->prepare("SELECT * FROM project_applications WHERE id = ? AND project_id = ?");
            $stmt->execute([$applicationId, $projectId]);
            $application = $stmt->fetch();

            if (!$application) {
                throw new Exception('Bewerbung nicht gefunden');
            }

            // Check if already accepted
            if ($application['status'] === 'accepted') {
                throw new Exception('Diese Bewerbung wurde bereits akzeptiert');
            }

            // Start transaction
            $db->beginTransaction();

            try {
                // Create assignment
                Project::assignMember($projectId, $application['user_id'], $role);

                // Update application status
                $stmt = $db->prepare("UPDATE project_applications SET status = 'accepted' WHERE id = ?");
                $stmt->execute([$applicationId]);

                // Commit transaction
                $db->commit();

                // Get user details for email
                $user = User::getById($application['user_id']);

                // Prepare client data for the email (only for accepted status)
                $clientData = null;
                if (!empty($project['client_name']) || !empty($project['client_contact_details'])) {
                    $clientData = [
                        'name' => $project['client_name'] ?? '',
                        'contact' => $project['client_contact_details'] ?? ''
                    ];
                }

                // Send acceptance email with client data
                $emailSent = false;
                if ($user) {
                    try {
                        $emailSent = MailService::sendProjectApplicationStatus(
                            $user['email'],
                            $project['title'],
                            'accepted',
                            $application['project_id'],
                            $clientData
                        );
                    } catch (Exception $emailError) {
                        error_log("Failed to send project acceptance email: " . $emailError->getMessage());
                        // Don't fail the whole operation if email fails
                    }
                }

                // Check if team is now fully staffed
                // Only check if max_consultants is defined and greater than 0
                // Skip check if project is already in 'assigned' or later status
                $maxConsultants = isset($project['max_consultants']) ? intval($project['max_consultants']) : 0;

                if ($maxConsultants > 0 && !in_array($project['status'], ['assigned', 'running', 'completed', 'archived'])) {
                    $stmt = $db->prepare("SELECT COUNT(*) as assignment_count FROM project_assignments WHERE project_id = ?");
                    $stmt->execute([$projectId]);
                    $assignmentResult = $stmt->fetch();
                    $assignmentCount = $assignmentResult ? intval($assignmentResult['assignment_count']) : 0;

                    // If team is fully staffed, update project status and notify leads
                    if ($assignmentCount >= $maxConsultants) {
                        // Update project status to 'assigned' (team is fully staffed)
                        $stmt = $db->prepare("UPDATE projects SET status = 'assigned' WHERE id = ?");
                        $stmt->execute([$projectId]);

                        // Get all project leads
                        $leadUserIds = Project::getProjectLeads($projectId);

                        // Track if lead notifications were sent successfully
                        $leadNotificationsSent = 0;

                        // Send notification to each lead
                        foreach ($leadUserIds as $leadUserId) {
                            $leadUser = User::getById($leadUserId);
                            if ($leadUser && !empty($leadUser['email'])) {
                                try {
                                    if (MailService::sendTeamCompletionNotification($leadUser['email'], $project['title'])) {
                                        $leadNotificationsSent++;
                                    }
                                } catch (Exception $emailError) {
                                    error_log("Failed to send team completion notification to lead {$leadUserId}: " . $emailError->getMessage());
                                    // Don't fail the whole operation if email fails
                                }
                            }
                        }

                        // Build success message based on email results
                        if ($emailSent && $leadNotificationsSent > 0) {
                            $message = "Status aktualisiert, Team vollständig und Benachrichtigungen versendet (inkl. {$leadNotificationsSent} Lead(s))";
                        } elseif ($emailSent) {
                            $message = "Status aktualisiert, Team vollständig und Benachrichtigung an Bewerber versendet";
                        } elseif ($leadNotificationsSent > 0) {
                            $message = "Status aktualisiert und Team vollständig (Benachrichtigungen an {$leadNotificationsSent} Lead(s) versendet)";
                        } else {
                            $message = "Status aktualisiert und Team vollständig";
                        }
                    } else {
                        $message = $emailSent ? 'Status aktualisiert und Benachrichtigung versendet' : 'Status aktualisiert';
                    }
                } else {
                    // No max_consultants defined or project already staffed, use default success message
                    $message = $emailSent ? 'Status aktualisiert und Benachrichtigung versendet' : 'Status aktualisiert';
                }

            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }

        } catch (Exception $e) {
            $error = 'Fehler beim Akzeptieren: ' . $e->getMessage();
        }
    }
}

// Handle reject action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_application'])) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

    $applicationId = intval($_POST['application_id'] ?? 0);

    try {
        $db = Database::getContentDB();

        // Get application details
        $stmt = $db->prepare("SELECT * FROM project_applications WHERE id = ? AND project_id = ?");
        $stmt->execute([$applicationId, $projectId]);
        $application = $stmt->fetch();

        if (!$application) {
            throw new Exception('Bewerbung nicht gefunden');
        }

        // Update application status to rejected
        $stmt = $db->prepare("UPDATE project_applications SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$applicationId]);

        // Get user details for email
        $user = User::getById($application['user_id']);

        // Send rejection email
        $emailSent = false;
        if ($user) {
            try {
                $emailSent = MailService::sendProjectApplicationStatus(
                    $user['email'],
                    $project['title'],
                    'rejected',
                    $application['project_id']
                );
            } catch (Exception $emailError) {
                error_log("Failed to send project rejection email: " . $emailError->getMessage());
                // Don't fail the whole operation if email fails
            }
        }

        $message = $emailSent ? 'Status aktualisiert und Benachrichtigung versendet' : 'Status aktualisiert';

    } catch (Exception $e) {
        $error = 'Fehler beim Ablehnen: ' . $e->getMessage();
    }
}

// Get all applications for this project with user information
$allApplications = Project::getApplications($projectId);

// Calculate counts for filters
$allCount = count($allApplications);
$pendingCount = count(array_filter($allApplications, function($a) { return $a['status'] === 'pending'; }));
$acceptedCount = count(array_filter($allApplications, function($a) { return $a['status'] === 'accepted'; }));
$rejectedCount = count(array_filter($allApplications, function($a) { return $a['status'] === 'rejected'; }));

// Get filter from query parameter
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Filter applications based on status
if ($statusFilter !== 'all') {
    $applications = array_filter($allApplications, function($app) use ($statusFilter) {
        return $app['status'] === $statusFilter;
    });
} else {
    $applications = $allApplications;
}

// Enrich applications with user details
foreach ($applications as &$application) {
    $user = User::getById($application['user_id']);
    $application['user_email'] = $user ? $user['email'] : 'Unbekannt';
}
unset($application);

$title = 'Bewerbungen für ' . htmlspecialchars($project['title']) . ' - IBC Intranet';
ob_start();
?>

<div class="pra-container mb-8">
    <div class="pra-header flex flex-col md:flex-row items-start md:items-center justify-between mb-4 gap-4">
        <div>
            <h1 class="pra-title text-2xl sm:text-3xl font-bold mb-2">
                <i class="fas fa-users mr-2"></i>
                Bewerbungen verwalten
            </h1>
            <p class="pra-subtitle">Projekt: <?php echo htmlspecialchars($project['title']); ?></p>
        </div>
        <a href="manage.php" class="pra-back-btn w-full md:w-auto text-center">
            <i class="fas fa-arrow-left mr-2"></i>Zurück zur Übersicht
        </a>
    </div>

    <!-- Filter Buttons -->
    <div class="pra-filters flex flex-wrap gap-2 mt-4">
        <a href="?project_id=<?php echo $projectId; ?>&status=all"
           class="pra-filter-btn pra-filter-btn--all <?php echo $statusFilter === 'all' ? 'pra-filter-btn--active' : ''; ?>">
            <i class="fas fa-list mr-2"></i>Alle (<?php echo $allCount; ?>)
        </a>
        <a href="?project_id=<?php echo $projectId; ?>&status=pending"
           class="pra-filter-btn pra-filter-btn--pending <?php echo $statusFilter === 'pending' ? 'pra-filter-btn--active' : ''; ?>">
            <i class="fas fa-clock mr-2"></i>Offen (<?php echo $pendingCount; ?>)
        </a>
        <a href="?project_id=<?php echo $projectId; ?>&status=accepted"
           class="pra-filter-btn pra-filter-btn--accepted <?php echo $statusFilter === 'accepted' ? 'pra-filter-btn--active' : ''; ?>">
            <i class="fas fa-check mr-2"></i>Angenommen (<?php echo $acceptedCount; ?>)
        </a>
        <a href="?project_id=<?php echo $projectId; ?>&status=rejected"
           class="pra-filter-btn pra-filter-btn--rejected <?php echo $statusFilter === 'rejected' ? 'pra-filter-btn--active' : ''; ?>">
            <i class="fas fa-times mr-2"></i>Abgelehnt (<?php echo $rejectedCount; ?>)
        </a>
    </div>
</div>

<?php if ($message): ?>
<div class="pra-message pra-message--success mb-6">
    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="pra-message pra-message--error mb-6">
    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<!-- Project Details Card -->
<div class="pra-card pra-card--details card p-6 mb-6">
    <h2 class="pra-card-title text-lg sm:text-xl font-bold mb-4">
        <i class="fas fa-briefcase mr-2"></i>
        Projekt-Details
    </h2>

    <div class="pra-details-grid grid grid-cols-1 sm:grid-cols-2 gap-3 md:gap-4">
        <?php if (!empty($project['image_path'])): ?>
        <div class="md:col-span-2">
            <img src="/<?php echo htmlspecialchars($project['image_path']); ?>"
                 alt="<?php echo htmlspecialchars($project['title']); ?>"
                 class="pra-project-image w-full h-48 object-cover rounded-lg">
        </div>
        <?php endif; ?>

        <div class="pra-detail-item">
            <span class="pra-detail-label text-sm font-medium">Status</span>
            <p class="pra-detail-value mt-1">
                <span class="pra-status-badge
                    <?php
                    switch($project['status']) {
                        case 'draft': echo 'pra-status-draft'; break;
                        case 'open': echo 'pra-status-open'; break;
                        case 'applying': echo 'pra-status-applying'; break;
                        case 'assigned': echo 'pra-status-assigned'; break;
                        case 'running': echo 'pra-status-running'; break;
                        case 'completed': echo 'pra-status-completed'; break;
                        case 'archived': echo 'pra-status-archived'; break;
                    }
                    ?>">
                    <?php
                    switch($project['status']) {
                        case 'draft': echo 'Entwurf'; break;
                        case 'open': echo 'Offen'; break;
                        case 'applying': echo 'Bewerbungsphase'; break;
                        case 'assigned': echo 'Vergeben'; break;
                        case 'running': echo 'Laufend'; break;
                        case 'completed': echo 'Abgeschlossen'; break;
                        case 'archived': echo 'Archiviert'; break;
                    }
                    ?>
                </span>
            </p>
        </div>

        <div class="pra-detail-item">
            <span class="pra-detail-label text-sm font-medium">Priorität</span>
            <p class="pra-detail-value mt-1">
                <span class="pra-priority-badge
                    <?php
                    switch($project['priority']) {
                        case 'low': echo 'pra-priority-low'; break;
                        case 'medium': echo 'pra-priority-medium'; break;
                        case 'high': echo 'pra-priority-high'; break;
                    }
                    ?>">
                    <?php
                    switch($project['priority']) {
                        case 'low': echo '<i class="fas fa-arrow-down"></i> Niedrig'; break;
                        case 'medium': echo '<i class="fas fa-minus"></i> Mittel'; break;
                        case 'high': echo '<i class="fas fa-arrow-up"></i> Hoch'; break;
                    }
                    ?>
                </span>
            </p>
        </div>

        <?php if (!empty($project['client_name'])): ?>
        <div class="pra-detail-item">
            <span class="pra-detail-label text-sm font-medium">Kunde</span>
            <p class="pra-detail-value mt-1"><?php echo htmlspecialchars($project['client_name']); ?></p>
        </div>
        <?php endif; ?>

        <?php if (!empty($project['start_date'])): ?>
        <div class="pra-detail-item">
            <span class="pra-detail-label text-sm font-medium">Startdatum</span>
            <p class="pra-detail-value mt-1"><?php echo date('d.m.Y', strtotime($project['start_date'])); ?></p>
        </div>
        <?php endif; ?>

        <?php if (!empty($project['end_date'])): ?>
        <div class="pra-detail-item">
            <span class="pra-detail-label text-sm font-medium">Enddatum</span>
            <p class="pra-detail-value mt-1"><?php echo date('d.m.Y', strtotime($project['end_date'])); ?></p>
        </div>
        <?php endif; ?>

        <?php if (!empty($project['description'])): ?>
        <div class="pra-detail-item md:col-span-2">
            <span class="pra-detail-label text-sm font-medium">Beschreibung</span>
            <p class="pra-detail-value mt-1 break-words hyphens-auto"><?php echo nl2br(htmlspecialchars($project['description'])); ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Applications List -->
<div class="pra-card pra-card--list card p-4 md:p-6">
    <h2 class="pra-card-title text-lg sm:text-xl font-bold mb-4">
        <i class="fas fa-file-alt mr-2"></i>
        Bewerbungen (<?php echo count($applications); ?>)
    </h2>

    <?php if (empty($applications)): ?>
    <div class="pra-empty text-center py-12">
        <i class="fas fa-inbox text-6xl mb-4"></i>
        <h3 class="text-base sm:text-xl font-semibold mb-2">Keine Bewerbungen</h3>
        <p class="pra-empty-text">
            <?php if ($statusFilter !== 'all'): ?>
                Für diesen Filter sind keine Bewerbungen vorhanden.
            <?php else: ?>
                Für dieses Projekt sind noch keine Bewerbungen eingegangen.
            <?php endif; ?>
        </p>
    </div>
    <?php else: ?>
    <!-- Mobile: Card View, Desktop: Can show more compact -->
    <div class="pra-applications-list space-y-4">
        <?php foreach ($applications as $application): ?>
        <div class="pra-application-card border rounded-lg p-4 md:p-6 transition">
            <div class="pra-application-header flex flex-col md:flex-row md:items-start justify-between mb-4 gap-3">
                <div class="pra-application-info flex-1">
                    <div class="pra-application-email flex items-center mb-2">
                        <i class="fas fa-user mr-2"></i>
                        <h3 class="text-lg font-bold break-all">
                            <?php echo htmlspecialchars($application['user_email']); ?>
                        </h3>
                    </div>
                    <div class="pra-application-meta flex flex-col md:flex-row md:items-center gap-2 md:gap-4 text-sm">
                        <span>
                            <i class="fas fa-calendar mr-1"></i>
                            <?php echo date('d.m.Y H:i', strtotime($application['created_at'])); ?>
                        </span>
                        <span>
                            <i class="fas fa-briefcase mr-1"></i>
                            <?php echo $application['experience_count']; ?> Projekt(e) Erfahrung
                        </span>
                    </div>
                </div>
                <span class="pra-application-status text-center w-full md:w-auto
                    <?php
                    switch($application['status']) {
                        case 'pending': echo 'pra-status-pending'; break;
                        case 'reviewing': echo 'pra-status-reviewing'; break;
                        case 'accepted': echo 'pra-status-accepted'; break;
                        case 'rejected': echo 'pra-status-rejected'; break;
                    }
                    ?>">
                    <?php
                    switch($application['status']) {
                        case 'pending': echo 'Ausstehend'; break;
                        case 'reviewing': echo 'In Prüfung'; break;
                        case 'accepted': echo 'Akzeptiert'; break;
                        case 'rejected': echo 'Abgelehnt'; break;
                    }
                    ?>
                </span>
            </div>

            <?php if (!empty($application['motivation'])): ?>
            <div class="pra-application-motivation mb-4">
                <span class="text-sm font-medium">Motivation:</span>
                <p class="pra-motivation-text mt-1 break-words hyphens-auto"><?php echo nl2br(htmlspecialchars($application['motivation'])); ?></p>
            </div>
            <?php endif; ?>

            <?php if ($application['status'] === 'pending' || $application['status'] === 'reviewing'): ?>
            <div class="pra-application-actions flex flex-col md:flex-row gap-3 pt-4 border-t">
                <button
                    class="pra-accept-btn accept-application-btn flex-1 px-4 py-2 md:py-3 rounded-lg transition"
                    data-application-id="<?php echo $application['id']; ?>"
                    data-user-email="<?php echo htmlspecialchars($application['user_email']); ?>"
                >
                    <i class="fas fa-check mr-2"></i>Akzeptieren
                </button>
                <button
                    class="pra-reject-btn reject-application-btn flex-1 px-4 py-2 md:py-3 rounded-lg transition"
                    data-application-id="<?php echo $application['id']; ?>"
                    data-user-email="<?php echo htmlspecialchars($application['user_email']); ?>"
                >
                    <i class="fas fa-times mr-2"></i>Ablehnen
                </button>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Accept Modal -->
<div id="acceptModal" class="pra-modal fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center p-4">
    <div class="pra-modal-content bg-white rounded-lg w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden">
        <form method="POST" id="acceptForm" class="flex flex-col flex-1 min-h-0">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
            <input type="hidden" name="application_id" id="acceptApplicationId" value="">
            <input type="hidden" name="accept_application" value="1">
            <div class="pra-modal-body p-6 overflow-y-auto flex-1">
                <h3 class="pra-modal-title text-lg sm:text-xl font-bold mb-4">
                    <i class="fas fa-check-circle mr-2"></i>
                    Bewerbung akzeptieren
                </h3>
                <p class="pra-modal-description mb-4">
                    Bewerbung von "<span id="acceptUserEmail" class="font-semibold"></span>" akzeptieren.
                </p>
                <div class="pra-modal-form-group mb-4">
                    <label class="block w-full text-sm font-medium mb-2">
                        Rolle auswählen <span class="text-red-500">*</span>
                    </label>
                    <select name="role" required class="pra-modal-select w-full px-4 py-2 border rounded-lg focus:outline-none focus:ring-2">
                        <option value="member">Member (Mitglied)</option>
                        <option value="lead">Lead (Projektleitung)</option>
                    </select>
                </div>
            </div>
            <div class="pra-modal-footer px-6 pb-6 flex flex-col md:flex-row gap-4">
                <button type="button" id="closeAcceptModalBtn" class="pra-modal-btn-cancel flex-1 px-6 py-3 rounded-lg transition">
                    Abbrechen
                </button>
                <button type="submit" class="pra-modal-btn-submit flex-1 px-6 py-3 rounded-lg transition">
                    <i class="fas fa-check mr-2"></i>Akzeptieren
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="pra-modal fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center p-4">
    <div class="pra-modal-content bg-white rounded-lg w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden">
        <div class="pra-modal-body p-6 overflow-y-auto flex-1">
            <h3 class="pra-modal-title text-lg sm:text-xl font-bold mb-4">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                Bewerbung ablehnen
            </h3>
            <p class="pra-modal-description">
                Möchtest Du die Bewerbung von "<span id="rejectUserEmail" class="font-semibold"></span>" wirklich ablehnen?
            </p>
        </div>
        <form method="POST" id="rejectForm" class="px-6 pb-6">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
            <input type="hidden" name="application_id" id="rejectApplicationId" value="">
            <input type="hidden" name="reject_application" value="1">
            <div class="flex flex-col md:flex-row gap-4">
                <button type="button" id="closeRejectModalBtn" class="pra-modal-btn-cancel flex-1 px-6 py-3 rounded-lg transition">
                    Abbrechen
                </button>
                <button type="submit" class="pra-modal-btn-reject flex-1 px-6 py-3 rounded-lg transition">
                    <i class="fas fa-times mr-2"></i>Ablehnen
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* PRA (Project Applications) scoped styles */
.pra-container {
    animation: fadeIn 0.3s ease-out cubic-bezier(.22,.68,0,1.2);
}

.pra-header {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

@media (min-width: 768px) {
    .pra-header {
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
    }
}

.pra-title {
    color: var(--text-main);
    margin-bottom: 0.5rem;
}

.pra-subtitle {
    color: var(--text-muted);
}

.pra-back-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.75rem 1.5rem;
    min-height: 44px;
    background-color: var(--ibc-blue);
    color: white;
    border-radius: 0.5rem;
    font-weight: 600;
    transition: all 0.2s ease;
    text-decoration: none;
}

.pra-back-btn:hover {
    background-color: var(--ibc-blue);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    transform: translateY(-2px);
}

.pra-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 1rem;
}

.pra-filter-btn {
    padding: 0.5rem 1rem;
    border-radius: 0.375rem;
    font-weight: 600;
    font-size: 0.875rem;
    transition: all 0.2s ease;
    text-decoration: none;
    color: var(--text-main);
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
}

.pra-filter-btn:hover {
    box-shadow: var(--shadow-card-hover);
}

.pra-filter-btn--active {
    background-color: var(--ibc-blue);
    color: white;
    border-color: var(--ibc-blue);
}

.pra-message {
    padding: 1rem;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    animation: slideIn 0.3s ease-out cubic-bezier(.22,.68,0,1.2);
}

.pra-message--success {
    background-color: rgba(74, 222, 128, 0.1);
    border: 1px solid rgba(74, 222, 128, 0.3);
    color: var(--ibc-green);
}

.pra-message--error {
    background-color: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: #ef4444;
}

.dark-mode .pra-message--success {
    background-color: rgba(74, 222, 128, 0.15);
    border-color: rgba(74, 222, 128, 0.4);
}

.dark-mode .pra-message--error {
    background-color: rgba(239, 68, 68, 0.15);
    border-color: rgba(239, 68, 68, 0.4);
}

.pra-card {
    background-color: var(--bg-card);
    border-radius: 0.75rem;
    box-shadow: var(--shadow-card);
    transition: all 0.2s ease;
}

.pra-card:hover {
    box-shadow: var(--shadow-card-hover);
}

.pra-card-title {
    color: var(--text-main);
}

.pra-details-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1rem;
}

@media (min-width: 640px) {
    .pra-details-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 0.75rem;
    }
}

@media (min-width: 900px) {
    .pra-details-grid {
        gap: 1rem;
    }
}

.pra-detail-label {
    color: var(--text-muted);
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.05em;
}

.pra-detail-value {
    color: var(--text-main);
}

.pra-status-badge,
.pra-priority-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.375rem 0.75rem;
    border-radius: 9999px;
    font-weight: 600;
    font-size: 0.875rem;
    white-space: nowrap;
}

.pra-status-draft {
    background-color: rgba(107, 114, 128, 0.1);
    color: #4b5563;
}

.pra-status-open {
    background-color: rgba(59, 130, 246, 0.1);
    color: #1e40af;
}

.pra-status-applying {
    background-color: rgba(234, 179, 8, 0.1);
    color: #a16207;
}

.pra-status-assigned {
    background-color: rgba(34, 197, 94, 0.1);
    color: #166534;
}

.pra-status-running {
    background-color: rgba(168, 85, 247, 0.1);
    color: #581c87;
}

.pra-status-completed {
    background-color: rgba(20, 184, 166, 0.1);
    color: #0d9488;
}

.pra-status-archived {
    background-color: rgba(239, 68, 68, 0.1);
    color: #991b1b;
}

.pra-priority-low {
    background-color: rgba(59, 130, 246, 0.1);
    color: #1e40af;
}

.pra-priority-medium {
    background-color: rgba(234, 179, 8, 0.1);
    color: #a16207;
}

.pra-priority-high {
    background-color: rgba(239, 68, 68, 0.1);
    color: #991b1b;
}

.dark-mode .pra-status-badge,
.dark-mode .pra-priority-badge {
    background-color: var(--bg-body);
    opacity: 0.9;
}

.pra-empty {
    color: var(--text-muted);
}

.pra-empty i {
    color: var(--border-color);
}

.pra-applications-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.pra-application-card {
    background-color: var(--bg-body);
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    transition: all 0.2s ease;
}

.pra-application-card:hover {
    box-shadow: var(--shadow-card-hover);
    border-color: var(--ibc-blue);
}

.pra-application-header {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

@media (min-width: 768px) {
    .pra-application-header {
        flex-direction: row;
        align-items: flex-start;
    }
}

.pra-application-email {
    display: flex;
    align-items: center;
    margin-bottom: 0.5rem;
    color: var(--text-main);
}

.pra-application-meta {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    font-size: 0.875rem;
    color: var(--text-muted);
}

@media (min-width: 768px) {
    .pra-application-meta {
        flex-direction: row;
        gap: 1rem;
    }
}

.pra-application-status {
    display: inline-flex;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-weight: 600;
    font-size: 0.875rem;
    min-height: 44px;
    align-items: center;
}

.pra-status-pending {
    background-color: rgba(234, 179, 8, 0.1);
    color: #a16207;
}

.pra-status-reviewing {
    background-color: rgba(59, 130, 246, 0.1);
    color: #1e40af;
}

.pra-status-accepted {
    background-color: rgba(34, 197, 94, 0.1);
    color: #166534;
}

.pra-status-rejected {
    background-color: rgba(239, 68, 68, 0.1);
    color: #991b1b;
}

.pra-motivation-text {
    color: var(--text-main);
    margin-top: 0.25rem;
}

.pra-application-actions {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border-color);
}

@media (min-width: 768px) {
    .pra-application-actions {
        flex-direction: row;
    }
}

.pra-accept-btn {
    background-color: var(--ibc-green);
    color: white;
    font-weight: 600;
    min-height: 44px;
}

.pra-accept-btn:hover {
    background-color: #22c55e;
    box-shadow: var(--shadow-card-hover);
    transform: translateY(-2px);
}

.pra-reject-btn {
    background-color: #ef4444;
    color: white;
    font-weight: 600;
    min-height: 44px;
}

.pra-reject-btn:hover {
    background-color: #dc2626;
    box-shadow: var(--shadow-card-hover);
    transform: translateY(-2px);
}

.pra-modal {
    display: none;
    position: fixed;
    inset: 0;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 50;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    animation: fadeIn 0.2s ease-out;
}

.pra-modal.hidden {
    display: none;
}

.pra-modal-content {
    background-color: var(--bg-card);
    border-radius: 0.5rem;
    width: 100%;
    max-width: 28rem;
    max-height: 85vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: slideUp 0.3s ease-out cubic-bezier(.22,.68,0,1.2);
}

.pra-modal-body {
    overflow-y: auto;
    flex: 1;
}

.pra-modal-title {
    color: var(--text-main);
    font-weight: 700;
}

.pra-modal-description {
    color: var(--text-muted);
}

.pra-modal-select {
    background-color: var(--bg-body);
    border: 1px solid var(--border-color);
    color: var(--text-main);
    transition: all 0.2s ease;
}

.pra-modal-select:focus {
    outline: none;
    ring-color: var(--ibc-blue);
    border-color: var(--ibc-blue);
}

.pra-modal-footer {
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

@media (min-width: 768px) {
    .pra-modal-footer {
        flex-direction: row;
    }
}

.pra-modal-btn-cancel {
    background-color: var(--bg-body);
    color: var(--text-main);
    border: 1px solid var(--border-color);
    font-weight: 600;
    flex: 1;
    padding: 0.75rem 1.5rem;
    min-height: 44px;
    transition: all 0.2s ease;
}

.pra-modal-btn-cancel:hover {
    background-color: var(--border-color);
}

.pra-modal-btn-submit {
    background-color: var(--ibc-green);
    color: white;
    font-weight: 600;
    flex: 1;
    padding: 0.75rem 1.5rem;
    min-height: 44px;
    transition: all 0.2s ease;
}

.pra-modal-btn-submit:hover {
    background-color: #22c55e;
    box-shadow: var(--shadow-card-hover);
    transform: translateY(-2px);
}

.pra-modal-btn-reject {
    background-color: #ef4444;
    color: white;
    font-weight: 600;
    flex: 1;
    padding: 0.75rem 1.5rem;
    min-height: 44px;
    transition: all 0.2s ease;
}

.pra-modal-btn-reject:hover {
    background-color: #dc2626;
    box-shadow: var(--shadow-card-hover);
    transform: translateY(-2px);
}

@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateX(-10px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.dark-mode .pra-modal-content {
    background-color: var(--bg-card);
}

.dark-mode .pra-filter-btn {
    background-color: var(--bg-body);
    color: var(--text-main);
}

.dark-mode .pra-filter-btn--active {
    background-color: var(--ibc-blue);
    color: white;
}

.dark-mode .pra-application-card {
    background-color: var(--bg-body);
    border-color: var(--border-color);
}
</style>

<script>
// Accept button event listeners
document.querySelectorAll('.accept-application-btn').forEach(button => {
    button.addEventListener('click', function() {
        const applicationId = this.getAttribute('data-application-id');
        const userEmail = this.getAttribute('data-user-email');
        showAcceptModal(applicationId, userEmail);
    });
});

// Reject button event listeners
document.querySelectorAll('.reject-application-btn').forEach(button => {
    button.addEventListener('click', function() {
        const applicationId = this.getAttribute('data-application-id');
        const userEmail = this.getAttribute('data-user-email');
        showRejectModal(applicationId, userEmail);
    });
});

function showAcceptModal(applicationId, userEmail) {
    const acceptApplicationId = document.getElementById('acceptApplicationId');
    const acceptUserEmail = document.getElementById('acceptUserEmail');
    const acceptModal = document.getElementById('acceptModal');

    if (acceptApplicationId) acceptApplicationId.value = applicationId;
    if (acceptUserEmail) acceptUserEmail.textContent = userEmail;
    if (acceptModal) {
        acceptModal.classList.remove('hidden');
        acceptModal.style.display = 'flex';
    }
}

function closeAcceptModal() {
    const acceptModal = document.getElementById('acceptModal');
    if (acceptModal) {
        acceptModal.classList.add('hidden');
        acceptModal.style.display = 'none';
    }
}

function showRejectModal(applicationId, userEmail) {
    const rejectApplicationId = document.getElementById('rejectApplicationId');
    const rejectUserEmail = document.getElementById('rejectUserEmail');
    const rejectModal = document.getElementById('rejectModal');

    if (rejectApplicationId) rejectApplicationId.value = applicationId;
    if (rejectUserEmail) rejectUserEmail.textContent = userEmail;
    if (rejectModal) {
        rejectModal.classList.remove('hidden');
        rejectModal.style.display = 'flex';
    }
}

function closeRejectModal() {
    const rejectModal = document.getElementById('rejectModal');
    if (rejectModal) {
        rejectModal.classList.add('hidden');
        rejectModal.style.display = 'none';
    }
}

// Close modal buttons
document.getElementById('closeAcceptModalBtn')?.addEventListener('click', closeAcceptModal);
document.getElementById('closeRejectModalBtn')?.addEventListener('click', closeRejectModal);

// Close modal on escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeAcceptModal();
        closeRejectModal();
    }
});

// Close modal when clicking outside
document.getElementById('acceptModal')?.addEventListener('click', (e) => {
    if (e.target.id === 'acceptModal') {
        closeAcceptModal();
    }
});

document.getElementById('rejectModal')?.addEventListener('click', (e) => {
    if (e.target.id === 'rejectModal') {
        closeRejectModal();
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
