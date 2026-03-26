<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Project.php';
require_once __DIR__ . '/../../includes/models/User.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../src/MailService.php';

// Only board members can access
if (!Auth::check() || !Auth::isBoard()) {
    header('Location: ../auth/login.php');
    exit;
}

$message = '';
$error = '';

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
            $stmt = $db->prepare("SELECT * FROM project_applications WHERE id = ?");
            $stmt->execute([$applicationId]);
            $application = $stmt->fetch();

            if (!$application) {
                throw new Exception('Bewerbung nicht gefunden');
            }

            if ($application['status'] === 'accepted') {
                throw new Exception('Diese Bewerbung wurde bereits akzeptiert');
            }

            $projectId = $application['project_id'];
            $project = Project::getById($projectId);

            if (!$project) {
                throw new Exception('Projekt nicht gefunden');
            }

            $db->beginTransaction();

            try {
                Project::assignMember($projectId, $application['user_id'], $role);

                $stmt = $db->prepare("UPDATE project_applications SET status = 'accepted' WHERE id = ?");
                $stmt->execute([$applicationId]);

                $db->commit();

                $user = User::getById($application['user_id']);

                $clientData = null;
                if (!empty($project['client_name']) || !empty($project['client_contact_details'])) {
                    $clientData = [
                        'name' => $project['client_name'] ?? '',
                        'contact' => $project['client_contact_details'] ?? ''
                    ];
                }

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
                    }
                }

                $maxConsultants = isset($project['max_consultants']) ? intval($project['max_consultants']) : 0;

                if ($maxConsultants > 0 && !in_array($project['status'], ['assigned', 'running', 'completed', 'archived'])) {
                    $stmt = $db->prepare("SELECT COUNT(*) as assignment_count FROM project_assignments WHERE project_id = ?");
                    $stmt->execute([$projectId]);
                    $assignmentResult = $stmt->fetch();
                    $assignmentCount = $assignmentResult ? intval($assignmentResult['assignment_count']) : 0;

                    if ($assignmentCount >= $maxConsultants) {
                        $stmt = $db->prepare("UPDATE projects SET status = 'assigned' WHERE id = ?");
                        $stmt->execute([$projectId]);

                        $leadUserIds = Project::getProjectLeads($projectId);
                        $leadNotificationsSent = 0;

                        foreach ($leadUserIds as $leadUserId) {
                            $leadUser = User::getById($leadUserId);
                            if ($leadUser && !empty($leadUser['email'])) {
                                try {
                                    if (MailService::sendTeamCompletionNotification($leadUser['email'], $project['title'])) {
                                        $leadNotificationsSent++;
                                    }
                                } catch (Exception $emailError) {
                                    error_log("Failed to send team completion notification to lead {$leadUserId}: " . $emailError->getMessage());
                                }
                            }
                        }

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

        $stmt = $db->prepare("SELECT * FROM project_applications WHERE id = ?");
        $stmt->execute([$applicationId]);
        $application = $stmt->fetch();

        if (!$application) {
            throw new Exception('Bewerbung nicht gefunden');
        }

        $projectId = $application['project_id'];
        $project = Project::getById($projectId);

        if (!$project) {
            throw new Exception('Projekt nicht gefunden');
        }

        $stmt = $db->prepare("UPDATE project_applications SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$applicationId]);

        $user = User::getById($application['user_id']);

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
            }
        }

        $message = $emailSent ? 'Status aktualisiert und Benachrichtigung versendet' : 'Status aktualisiert';

    } catch (Exception $e) {
        $error = 'Fehler beim Ablehnen: ' . $e->getMessage();
    }
}

// Load all pending applications across all projects
$db = Database::getContentDB();
$stmt = $db->prepare("
    SELECT
        pa.id,
        pa.project_id,
        pa.user_id,
        pa.motivation,
        pa.experience_count,
        pa.status,
        pa.created_at,
        p.title as project_title
    FROM project_applications pa
    JOIN projects p ON pa.project_id = p.id
    WHERE pa.status = 'pending'
    ORDER BY pa.created_at ASC
");
$stmt->execute();
$applications = $stmt->fetchAll();

// Enrich with user email
foreach ($applications as &$application) {
    $user = User::getById($application['user_id']);
    $application['user_email'] = $user ? $user['email'] : 'Unbekannt';
}
unset($application);

$title = 'Bewerbungsverwaltung - IBC Intranet';
ob_start();
?>

<div class="mb-8">
    <div class="flex flex-col md:flex-row items-start md:items-center justify-between mb-4 gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-users text-purple-600 mr-2"></i>
                Bewerbungsverwaltung
            </h1>
            <p class="text-gray-600 dark:text-gray-400">Alle offenen Projektbewerbungen</p>
        </div>
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

<!-- Applications List -->
<div class="card p-4 md:p-6">
    <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
        <i class="fas fa-file-alt text-purple-600 mr-2"></i>
        Offene Bewerbungen (<?php echo count($applications); ?>)
    </h2>

    <?php if (empty($applications)): ?>
    <div class="text-center py-12">
        <i class="fas fa-inbox text-gray-400 text-6xl mb-4"></i>
        <h3 class="text-base sm:text-xl font-semibold text-gray-600 dark:text-gray-400 mb-2">Keine offenen Bewerbungen</h3>
        <p class="text-gray-500 dark:text-gray-400">Aktuell liegen keine ausstehenden Bewerbungen vor.</p>
    </div>
    <?php else: ?>
    <div class="space-y-4">
        <?php foreach ($applications as $application): ?>
        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 md:p-6 hover:shadow-md transition bg-white dark:bg-gray-800">
            <div class="flex flex-col md:flex-row md:items-start justify-between mb-4 gap-3">
                <div class="flex-1">
                    <div class="flex items-center mb-1">
                        <i class="fas fa-briefcase text-purple-600 mr-2"></i>
                        <span class="text-sm font-semibold text-purple-700 dark:text-purple-400">
                            <?php echo htmlspecialchars($application['project_title']); ?>
                        </span>
                    </div>
                    <div class="flex items-center mb-2">
                        <i class="fas fa-user text-gray-500 mr-2"></i>
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
                <span class="px-3 py-1 text-sm font-semibold rounded-full bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300 text-center w-full md:w-auto">
                    Ausstehend
                </span>
            </div>

            <?php if (!empty($application['motivation'])): ?>
            <div class="mb-4">
                <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Motivation:</span>
                <p class="text-gray-800 dark:text-gray-200 mt-1 break-words hyphens-auto"><?php echo nl2br(htmlspecialchars($application['motivation'])); ?></p>
            </div>
            <?php endif; ?>

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
document.querySelectorAll('.accept-application-btn').forEach(button => {
    button.addEventListener('click', function() {
        const applicationId = this.getAttribute('data-application-id');
        const userEmail = this.getAttribute('data-user-email');
        showAcceptModal(applicationId, userEmail);
    });
});

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

document.getElementById('closeAcceptModalBtn')?.addEventListener('click', closeAcceptModal);
document.getElementById('closeRejectModalBtn')?.addEventListener('click', closeRejectModal);

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeAcceptModal();
        closeRejectModal();
    }
});

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
