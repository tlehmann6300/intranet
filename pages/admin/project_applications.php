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
<div class="mb-6 p-4 bg-green-50 dark:bg-green-900/30 border border-green-300 dark:border-green-700 text-green-800 dark:text-green-200 rounded-xl flex items-start gap-3">
    <i class="fas fa-check-circle text-green-500 dark:text-green-400 mt-0.5 shrink-0 text-lg"></i>
    <span><?php echo htmlspecialchars($message); ?></span>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="mb-6 p-4 bg-red-50 dark:bg-red-900/30 border border-red-300 dark:border-red-700 text-red-800 dark:text-red-200 rounded-xl flex items-start gap-3">
    <i class="fas fa-exclamation-circle text-red-500 dark:text-red-400 mt-0.5 shrink-0 text-lg"></i>
    <span><?php echo htmlspecialchars($error); ?></span>
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
        <i class="fas fa-inbox text-gray-300 dark:text-gray-600 text-6xl mb-4"></i>
        <h3 class="text-base sm:text-xl font-semibold text-gray-600 dark:text-gray-400 mb-2">Keine offenen Bewerbungen</h3>
        <p class="text-gray-500 dark:text-gray-400">Aktuell liegen keine ausstehenden Bewerbungen vor.</p>
    </div>
    <?php else: ?>
    <div class="space-y-4">
        <?php foreach ($applications as $application): ?>
        <div class="border border-gray-200 dark:border-gray-700 rounded-xl p-4 md:p-5 hover:shadow-md transition-all bg-white dark:bg-gray-800">
            <div class="flex flex-col md:flex-row md:items-start justify-between mb-4 gap-3">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center mb-1">
                        <i class="fas fa-briefcase text-purple-600 mr-2 shrink-0"></i>
                        <span class="text-sm font-semibold text-purple-700 dark:text-purple-400 truncate">
                            <?php echo htmlspecialchars($application['project_title']); ?>
                        </span>
                    </div>
                    <div class="flex items-center mb-2">
                        <i class="fas fa-user text-gray-400 dark:text-gray-500 mr-2 shrink-0"></i>
                        <h3 class="text-base font-bold text-gray-800 dark:text-gray-100 break-all">
                            <?php echo htmlspecialchars($application['user_email']); ?>
                        </h3>
                    </div>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
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
                <span class="inline-flex items-center px-3 py-1.5 text-xs font-semibold rounded-full bg-yellow-100 dark:bg-yellow-900/40 text-yellow-800 dark:text-yellow-300 shrink-0 self-start">
                    <i class="fas fa-clock mr-1.5"></i>Ausstehend
                </span>
            </div>

            <?php if (!empty($application['motivation'])): ?>
            <div class="mb-4 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-100 dark:border-gray-700">
                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Motivation</span>
                <p class="text-sm text-gray-800 dark:text-gray-200 mt-1 break-words leading-relaxed"><?php echo nl2br(htmlspecialchars($application['motivation'])); ?></p>
            </div>
            <?php endif; ?>

            <div class="flex flex-col sm:flex-row gap-3 pt-4 border-t border-gray-100 dark:border-gray-700">
                <button
                    class="accept-application-btn flex-1 inline-flex items-center justify-center gap-2 px-5 py-3 min-h-[44px] bg-green-600 hover:bg-green-700 text-white rounded-xl font-semibold transition-colors text-sm"
                    data-application-id="<?php echo $application['id']; ?>"
                    data-user-email="<?php echo htmlspecialchars($application['user_email']); ?>"
                >
                    <i class="fas fa-check"></i>Akzeptieren
                </button>
                <button
                    class="reject-application-btn flex-1 inline-flex items-center justify-center gap-2 px-5 py-3 min-h-[44px] bg-red-600 hover:bg-red-700 text-white rounded-xl font-semibold transition-colors text-sm"
                    data-application-id="<?php echo $application['id']; ?>"
                    data-user-email="<?php echo htmlspecialchars($application['user_email']); ?>"
                >
                    <i class="fas fa-times"></i>Ablehnen
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Accept Modal -->
<div id="acceptModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden items-end sm:items-center justify-center p-0 sm:p-4">
    <div class="bg-white dark:bg-gray-800 w-full sm:max-w-lg sm:rounded-2xl max-h-[92vh] sm:max-h-[85vh] flex flex-col overflow-hidden shadow-2xl rounded-t-2xl">
        <!-- Drag handle (mobile only) -->
        <div class="sm:hidden flex justify-center pt-3 pb-1">
            <div class="w-10 h-1 rounded-full bg-gray-300 dark:bg-gray-600"></div>
        </div>
        <!-- Modal Header -->
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-700 bg-gradient-to-r from-green-50 to-emerald-50 dark:from-green-900/20 dark:to-emerald-900/20">
            <h3 class="text-base font-bold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                <i class="fas fa-check-circle text-green-600 dark:text-green-400"></i>
                Bewerbung akzeptieren
            </h3>
            <button type="button" id="closeAcceptModalBtn" class="w-11 h-11 flex items-center justify-center rounded-lg text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" id="acceptForm" class="flex flex-col flex-1 min-h-0">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
            <input type="hidden" name="application_id" id="acceptApplicationId" value="">
            <input type="hidden" name="accept_application" value="1">
            <div class="p-5 overflow-y-auto flex-1 space-y-4">
                <p class="text-gray-600 dark:text-gray-400 text-sm">
                    Bewerbung von <span id="acceptUserEmail" class="font-semibold text-gray-800 dark:text-gray-200"></span> akzeptieren.
                </p>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
                        Rolle auswählen <span class="text-red-500">*</span>
                    </label>
                    <select name="role" required class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white rounded-xl focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent text-base">
                        <option value="member">Member (Mitglied)</option>
                        <option value="lead">Lead (Projektleitung)</option>
                    </select>
                </div>
            </div>
            <div class="px-5 py-4 border-t border-gray-100 dark:border-gray-700 flex flex-col sm:flex-row gap-3">
                <button type="button" id="closeAcceptModalBtn2" class="flex-1 min-h-[44px] px-6 py-3 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-600 font-semibold transition-colors">
                    Abbrechen
                </button>
                <button type="submit" class="flex-1 min-h-[44px] px-6 py-3 bg-green-600 hover:bg-green-700 text-white rounded-xl font-semibold transition-colors inline-flex items-center justify-center gap-2">
                    <i class="fas fa-check"></i>Akzeptieren
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden items-end sm:items-center justify-center p-0 sm:p-4">
    <div class="bg-white dark:bg-gray-800 w-full sm:max-w-lg sm:rounded-2xl max-h-[92vh] sm:max-h-[85vh] flex flex-col overflow-hidden shadow-2xl rounded-t-2xl">
        <!-- Drag handle (mobile only) -->
        <div class="sm:hidden flex justify-center pt-3 pb-1">
            <div class="w-10 h-1 rounded-full bg-gray-300 dark:bg-gray-600"></div>
        </div>
        <!-- Modal Header -->
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 dark:border-gray-700 bg-gradient-to-r from-red-50 to-rose-50 dark:from-red-900/20 dark:to-rose-900/20">
            <h3 class="text-base font-bold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                <i class="fas fa-exclamation-triangle text-red-600 dark:text-red-400"></i>
                Bewerbung ablehnen
            </h3>
            <button type="button" id="closeRejectModalBtn" class="w-11 h-11 flex items-center justify-center rounded-lg text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="p-5 overflow-y-auto flex-1">
            <p class="text-gray-600 dark:text-gray-400 text-sm">
                Möchtest Du die Bewerbung von <span id="rejectUserEmail" class="font-semibold text-gray-800 dark:text-gray-200"></span> wirklich ablehnen?
            </p>
        </div>
        <form method="POST" id="rejectForm" class="px-5 py-4 border-t border-gray-100 dark:border-gray-700">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
            <input type="hidden" name="application_id" id="rejectApplicationId" value="">
            <input type="hidden" name="reject_application" value="1">
            <div class="flex flex-col sm:flex-row gap-3">
                <button type="button" id="closeRejectModalBtn2" class="flex-1 min-h-[44px] px-6 py-3 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-600 font-semibold transition-colors">
                    Abbrechen
                </button>
                <button type="submit" class="flex-1 min-h-[44px] px-6 py-3 bg-red-600 hover:bg-red-700 text-white rounded-xl font-semibold transition-colors inline-flex items-center justify-center gap-2">
                    <i class="fas fa-times"></i>Ablehnen
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
document.getElementById('closeAcceptModalBtn2')?.addEventListener('click', closeAcceptModal);
document.getElementById('closeRejectModalBtn')?.addEventListener('click', closeRejectModal);
document.getElementById('closeRejectModalBtn2')?.addEventListener('click', closeRejectModal);

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
