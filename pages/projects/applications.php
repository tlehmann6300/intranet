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

<div class="mb-8">
    <div class="flex flex-col md:flex-row items-start md:items-center justify-between mb-4 gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-users text-purple-600 mr-2"></i>
                Bewerbungen verwalten
            </h1>
            <p class="text-gray-600 dark:text-gray-400">Projekt: <?php echo htmlspecialchars($project['title']); ?></p>
        </div>
        <a href="manage.php" class="btn-primary w-full md:w-auto text-center">
            <i class="fas fa-arrow-left mr-2"></i>Zurück zur Übersicht
        </a>
    </div>
    
    <!-- Filter Buttons -->
    <div class="flex flex-wrap gap-2 mt-4">
        <a href="?project_id=<?php echo $projectId; ?>&status=all" 
           class="px-4 py-2 rounded-lg font-medium transition <?php echo $statusFilter === 'all' ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-300 dark:hover:bg-gray-600'; ?>">
            <i class="fas fa-list mr-2"></i>Alle (<?php echo $allCount; ?>)
        </a>
        <a href="?project_id=<?php echo $projectId; ?>&status=pending" 
           class="px-4 py-2 rounded-lg font-medium transition <?php echo $statusFilter === 'pending' ? 'bg-yellow-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-300 dark:hover:bg-gray-600'; ?>">
            <i class="fas fa-clock mr-2"></i>Offen (<?php echo $pendingCount; ?>)
        </a>
        <a href="?project_id=<?php echo $projectId; ?>&status=accepted" 
           class="px-4 py-2 rounded-lg font-medium transition <?php echo $statusFilter === 'accepted' ? 'bg-green-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-300 dark:hover:bg-gray-600'; ?>">
            <i class="fas fa-check mr-2"></i>Angenommen (<?php echo $acceptedCount; ?>)
        </a>
        <a href="?project_id=<?php echo $projectId; ?>&status=rejected" 
           class="px-4 py-2 rounded-lg font-medium transition <?php echo $statusFilter === 'rejected' ? 'bg-red-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-300 dark:hover:bg-gray-600'; ?>">
            <i class="fas fa-times mr-2"></i>Abgelehnt (<?php echo $rejectedCount; ?>)
        </a>
    </div>
</div>

<?php if ($message): ?>
<div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<!-- Project Details Card -->
<div class="card p-6 mb-6">
    <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4">
        <i class="fas fa-briefcase text-purple-600 mr-2"></i>
        Projekt-Details
    </h2>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php if (!empty($project['image_path'])): ?>
        <div class="md:col-span-2">
            <img src="/<?php echo htmlspecialchars($project['image_path']); ?>" 
                 alt="<?php echo htmlspecialchars($project['title']); ?>"
                 class="w-full h-48 object-cover rounded-lg">
        </div>
        <?php endif; ?>
        
        <div>
            <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Status</span>
            <p class="text-gray-800 dark:text-gray-200 mt-1">
                <span class="px-3 py-1 text-sm font-semibold rounded-full
                    <?php 
                    switch($project['status']) {
                        case 'draft': echo 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'; break;
                        case 'open': echo 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200'; break;
                        case 'applying': echo 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'; break;
                        case 'assigned': echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'; break;
                        case 'running': echo 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200'; break;
                        case 'completed': echo 'bg-teal-100 text-teal-800 dark:bg-teal-900 dark:text-teal-200'; break;
                        case 'archived': echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'; break;
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
        
        <div>
            <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Priorität</span>
            <p class="text-gray-800 dark:text-gray-200 mt-1">
                <span class="px-3 py-1 text-sm font-semibold rounded-full
                    <?php 
                    switch($project['priority']) {
                        case 'low': echo 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200'; break;
                        case 'medium': echo 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'; break;
                        case 'high': echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'; break;
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
        <div>
            <span class="text-sm font-medium text-gray-600">Kunde</span>
            <p class="text-gray-800 mt-1"><?php echo htmlspecialchars($project['client_name']); ?></p>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($project['start_date'])): ?>
        <div>
            <span class="text-sm font-medium text-gray-600">Startdatum</span>
            <p class="text-gray-800 mt-1"><?php echo date('d.m.Y', strtotime($project['start_date'])); ?></p>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($project['end_date'])): ?>
        <div>
            <span class="text-sm font-medium text-gray-600">Enddatum</span>
            <p class="text-gray-800 mt-1"><?php echo date('d.m.Y', strtotime($project['end_date'])); ?></p>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($project['description'])): ?>
        <div class="md:col-span-2">
            <span class="text-sm font-medium text-gray-600">Beschreibung</span>
            <p class="text-gray-800 mt-1 break-words hyphens-auto"><?php echo nl2br(htmlspecialchars($project['description'])); ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Applications List -->
<div class="card p-4 md:p-6">
    <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
        <i class="fas fa-file-alt text-purple-600 mr-2"></i>
        Bewerbungen (<?php echo count($applications); ?>)
    </h2>
    
    <?php if (empty($applications)): ?>
    <div class="text-center py-12">
        <i class="fas fa-inbox text-gray-400 text-6xl mb-4"></i>
        <h3 class="text-base sm:text-xl font-semibold text-gray-600 dark:text-gray-400 mb-2">Keine Bewerbungen</h3>
        <p class="text-gray-500 dark:text-gray-400">
            <?php if ($statusFilter !== 'all'): ?>
                Für diesen Filter sind keine Bewerbungen vorhanden.
            <?php else: ?>
                Für dieses Projekt sind noch keine Bewerbungen eingegangen.
            <?php endif; ?>
        </p>
    </div>
    <?php else: ?>
    <!-- Mobile: Card View, Desktop: Can show more compact -->
    <div class="space-y-4">
        <?php foreach ($applications as $application): ?>
        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 md:p-6 hover:shadow-md transition bg-white dark:bg-gray-800">
            <div class="flex flex-col md:flex-row md:items-start justify-between mb-4 gap-3">
                <div class="flex-1">
                    <div class="flex items-center mb-2">
                        <i class="fas fa-user text-purple-600 mr-2"></i>
                        <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 break-all">
                            <?php echo htmlspecialchars($application['user_email']); ?>
                        </h3>
                    </div>
                    <div class="flex flex-col md:flex-row md:items-center gap-2 md:gap-4 text-sm text-gray-600 dark:text-gray-400">
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
                <span class="px-3 py-1 text-sm font-semibold rounded-full text-center w-full md:w-auto
                    <?php 
                    switch($application['status']) {
                        case 'pending': echo 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300'; break;
                        case 'reviewing': echo 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300'; break;
                        case 'accepted': echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300'; break;
                        case 'rejected': echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300'; break;
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
            <div class="mb-4">
                <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Motivation:</span>
                <p class="text-gray-800 dark:text-gray-200 mt-1 break-words hyphens-auto"><?php echo nl2br(htmlspecialchars($application['motivation'])); ?></p>
            </div>
            <?php endif; ?>
            
            <?php if ($application['status'] === 'pending' || $application['status'] === 'reviewing'): ?>
            <div class="flex flex-col md:flex-row gap-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                <button 
                    class="accept-application-btn flex-1 px-4 py-2 md:py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition touch-target"
                    data-application-id="<?php echo $application['id']; ?>"
                    data-user-email="<?php echo htmlspecialchars($application['user_email']); ?>"
                >
                    <i class="fas fa-check mr-2"></i>Akzeptieren
                </button>
                <button 
                    class="reject-application-btn flex-1 px-4 py-2 md:py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 transition touch-target"
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
<div id="acceptModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-lg w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden">
        <form method="POST" id="acceptForm" class="flex flex-col flex-1 min-h-0">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
            <input type="hidden" name="application_id" id="acceptApplicationId" value="">
            <input type="hidden" name="accept_application" value="1">
            <div class="p-6 overflow-y-auto flex-1">
                <h3 class="text-lg sm:text-xl font-bold text-gray-800 mb-4">
                    <i class="fas fa-check-circle text-green-600 mr-2"></i>
                    Bewerbung akzeptieren
                </h3>
                <p class="text-gray-600 mb-4">
                    Bewerbung von "<span id="acceptUserEmail" class="font-semibold"></span>" akzeptieren.
                </p>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Rolle auswählen <span class="text-red-500">*</span>
                    </label>
                    <select name="role" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500">
                        <option value="member">Member (Mitglied)</option>
                        <option value="lead">Lead (Projektleitung)</option>
                    </select>
                </div>
            </div>
            <div class="px-6 pb-6 flex space-x-4">
                <button type="button" id="closeAcceptModalBtn" class="flex-1 px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                    Abbrechen
                </button>
                <button type="submit" class="flex-1 px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                    <i class="fas fa-check mr-2"></i>Akzeptieren
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center p-4">
    <div class="bg-white rounded-lg w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden">
        <div class="p-6 overflow-y-auto flex-1">
            <h3 class="text-lg sm:text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-exclamation-triangle text-red-600 mr-2"></i>
                Bewerbung ablehnen
            </h3>
            <p class="text-gray-600">
                Möchtest Du die Bewerbung von "<span id="rejectUserEmail" class="font-semibold"></span>" wirklich ablehnen?
            </p>
        </div>
        <form method="POST" id="rejectForm" class="px-6 pb-6">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
            <input type="hidden" name="application_id" id="rejectApplicationId" value="">
            <input type="hidden" name="reject_application" value="1">
            <div class="flex space-x-4">
                <button type="button" id="closeRejectModalBtn" class="flex-1 px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                    Abbrechen
                </button>
                <button type="submit" class="flex-1 px-6 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                    <i class="fas fa-times mr-2"></i>Ablehnen
                </button>
            </div>
        </form>
    </div>
</div>

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
        acceptModal.classList.add('flex');
    }
}

function closeAcceptModal() {
    const acceptModal = document.getElementById('acceptModal');
    if (acceptModal) {
        acceptModal.classList.add('hidden');
        acceptModal.classList.remove('flex');
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
        rejectModal.classList.add('flex');
    }
}

function closeRejectModal() {
    const rejectModal = document.getElementById('rejectModal');
    if (rejectModal) {
        rejectModal.classList.add('hidden');
        rejectModal.classList.remove('flex');
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
