<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Project.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../src/Database.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$userRole = $_SESSION['user_role'] ?? 'mitglied';

// Get project ID from query parameter
$projectId = intval($_GET['id'] ?? 0);
if ($projectId <= 0) {
    header('Location: index.php');
    exit;
}

// Get project details
$project = Project::getById($projectId);
if (!$project) {
    header('Location: index.php');
    exit;
}

// SECURITY: Zugriff auf Entwürfe verweigern für Nicht-Manager
if (isset($project['status']) && $project['status'] === 'draft' && !Auth::hasPermission('manage_projects')) {
    $_SESSION['error'] = 'Zugriff verweigert. Dieses Projekt ist noch im Entwurf.';
    header('Location: index.php');
    exit;
}

// Filter sensitive data based on user role
$project = Project::filterSensitiveData($project, $userRole, $user['id']);

// Check if user has already applied
$userApplication = null;
if ($userRole !== 'alumni') {
    $userApplication = Project::getUserApplication($projectId, $user['id']);
}

// Check if user is project lead or admin/board
$isLead = Project::isLead($projectId, $user['id']);
$canComplete = $isLead || Auth::isBoard() || $userRole === 'manager';

// Get team size info
$teamSize = Project::getTeamSize($projectId);
$maxConsultants = intval($project['max_consultants'] ?? 1);
$teamPercentage = $maxConsultants > 0 ? min(100, round(($teamSize / $maxConsultants) * 100)) : 0;

// Internal project flag
$isInternalProject = ($project['type'] ?? 'internal') === 'internal';

// Requires application flag – only meaningful for internal projects; external projects always require an application
$requiresApplication = !$isInternalProject || (bool)($project['requires_application'] ?? 1);

// Check if user is already a participant (for internal projects)
$isParticipant = false;
if ($isInternalProject && $userRole !== 'alumni') {
    $db = Database::getContentDB();
    $stmt = $db->prepare("SELECT id FROM project_assignments WHERE project_id = ? AND user_id = ?");
    $stmt->execute([$projectId, $user['id']]);
    $isParticipant = (bool)$stmt->fetch();
}

// Load participants list for internal projects (visible to all) or for leads/admins
$participants = [];
if ($isInternalProject || $isLead || Auth::isBoard() || Auth::hasPermission('manage_projects')) {
    $participants = Project::getParticipants($projectId);
}

// Load feedback contact info
$feedbackContact = Project::getFeedbackContact($projectId);
$feedbackContactRoles = ['alumni', 'alumni_vorstand', 'alumni_finanz', 'ehrenmitglied'];
$canBecomeFeedbackContact = in_array($userRole, $feedbackContactRoles);
$isFeedbackContact = $feedbackContact && (int)($feedbackContact['user_id'] ?? 0) === (int)$user['id'];

// Handle application submission
$message = '';
$error = '';

// Handle project completion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_project'])) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    
    if (!$canComplete) {
        $error = 'Du hast keine Berechtigung, dieses Projekt abzuschließen';
    } elseif ($project['status'] !== 'running') {
        $error = 'Nur laufende Projekte können abgeschlossen werden';
    } else {
        try {
            $documentation = strip_tags(trim($_POST['documentation'] ?? ''));
            if (empty($documentation)) {
                throw new Exception('Bitte geben Sie eine Projektdokumentation an');
            }
            
            Project::update($projectId, [
                'status' => 'completed',
                'documentation' => $documentation
            ]);
            
            $message = 'Projekt wurde erfolgreich abgeschlossen';
            
            // Reload project data
            $project = Project::getById($projectId);
            $project = Project::filterSensitiveData($project, $userRole, $user['id']);
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply'])) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    
    // Check if user can apply
    if ($userRole === 'alumni') {
        $error = 'Alumni können sich nicht auf Projekte bewerben';
    } elseif ($project['status'] !== 'open' && $project['status'] !== 'applying') {
        $error = 'Bewerbungen für dieses Projekt sind nicht möglich';
    } else {
        try {
            $motivation = strip_tags(trim($_POST['motivation'] ?? ''));

            // Validate motivation only when application text is required
            if ($requiresApplication && empty($motivation)) {
                throw new Exception('Bitte gib Deine Motivation an');
            }
            
            // Validate experience count confirmation checkbox (only when application required)
            if ($requiresApplication && (!isset($_POST['experience_confirmed']) || $_POST['experience_confirmed'] !== '1')) {
                throw new Exception('Bitte bestätigen Sie, dass Sie die Anzahl bisheriger Projekte korrekt angegeben haben');
            }
            
            // Validate GDPR consent checkbox (only when application required)
            if ($requiresApplication && (!isset($_POST['gdpr_consent']) || $_POST['gdpr_consent'] !== '1')) {
                throw new Exception('Sie müssen der Datenverarbeitung gemäß DSGVO zustimmen');
            }
            
            $applicationData = [
                'motivation' => $motivation,
                'experience_count' => intval($_POST['experience_count'] ?? 0)
            ];
            
            Project::apply($projectId, $user['id'], $applicationData);
            $message = 'Deine Bewerbung wurde erfolgreich eingereicht';
            
            // Reload application status
            $userApplication = Project::getUserApplication($projectId, $user['id']);
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$title = htmlspecialchars($project['title']) . ' - IBC Intranet';
ob_start();
?>

<div class="prv-container">
    <!-- Back Button -->
    <div class="prv-header mb-6">
        <a href="index.php" class="prv-back-btn">
            <i class="fas fa-arrow-left mr-2"></i>
            Zurück zur Übersicht
        </a>
        <?php if ($user['id'] === ($project['created_by'] ?? null) || Auth::isBoard() || Auth::hasPermission('manage_projects')): ?>
        <a href="manage.php?edit=<?= (int)$project['id'] ?>" class="prv-edit-btn">
            <i class="fas fa-edit mr-2"></i>
            Projekt bearbeiten
        </a>
        <?php endif; ?>
    </div>
    
    <!-- Draft Warning -->
    <?php if ($project['status'] === 'draft' && Auth::hasPermission('manage_projects')): ?>
    <div class="prv-draft-warning">
        Status: ENTWURF - Für Mitglieder noch nicht sichtbar.
    </div>
    <?php endif; ?>

    <?php if ($message): ?>
    <div class="prv-message prv-message--success mb-6">
        <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="prv-message prv-message--error mb-6">
        <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <!-- Project Card with Enhanced Design -->
    <div class="prv-card card p-8">
        
        <!-- Image with Hero Effect -->
        <?php if (!empty($project['image_path'])): ?>
        <div class="prv-hero-image">
            <img src="/<?php echo htmlspecialchars($project['image_path']); ?>"
                 alt="<?php echo htmlspecialchars($project['title']); ?>"
                 class="w-full h-48 sm:h-64 md:h-96 object-cover">
        </div>
        <?php endif; ?>
        
        <!-- PDF Download Button -->
        <?php
        // Check if PDF file exists with security validation
        $showPdfButton = false;
        if (!empty($project['file_path'])) {
            $baseDirPath = __DIR__ . '/../../';
            $baseDir = realpath($baseDirPath);
            $filePath = realpath($baseDirPath . $project['file_path']);
            // Verify file exists and is within allowed directory
            if ($filePath && strpos($filePath, $baseDir) === 0 && file_exists($filePath)) {
                $showPdfButton = true;
            }
        }
        if ($showPdfButton):
        ?>
        <a href="/<?php echo htmlspecialchars($project['file_path']); ?>"
           class="prv-pdf-btn"
           download>
            <i class="fas fa-file-pdf mr-3 text-xl"></i>
            <span>Projekt-Datei herunterladen (PDF)</span>
        </a>
        <?php endif; ?>
        
        <!-- Status and Priority with Modern Badges -->
        <div class="prv-badges">
            <span class="prv-badge
                <?php
                switch($project['status']) {
                    case 'draft': echo 'prv-status-draft'; break;
                    case 'open': echo 'prv-status-open'; break;
                    case 'applying': echo 'prv-status-applying'; break;
                    case 'assigned': echo 'prv-status-assigned'; break;
                    case 'running': echo 'prv-status-running'; break;
                    case 'completed': echo 'prv-status-completed'; break;
                    case 'archived': echo 'prv-status-archived'; break;
                }
                ?>">
                <i class="fas fa-circle text-[10px] mr-2"></i>
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

            <span class="prv-badge
                <?php
                switch($project['priority']) {
                    case 'low': echo 'prv-priority-low'; break;
                    case 'medium': echo 'prv-priority-medium'; break;
                    case 'high': echo 'prv-priority-high'; break;
                }
                ?>">
                <?php
                switch($project['priority']) {
                    case 'low': echo '<i class="fas fa-arrow-down mr-1"></i> Niedrig'; break;
                    case 'medium': echo '<i class="fas fa-minus mr-1"></i> Mittel'; break;
                    case 'high': echo '<i class="fas fa-arrow-up mr-1"></i> Hoch'; break;
                }
                ?>
            </span>

            <span class="prv-badge
                <?php
                $projectType = $project['type'] ?? 'internal';
                echo $projectType === 'internal' ? 'prv-type-internal' : 'prv-type-external';
                ?>">
                <i class="fas fa-tag mr-2"></i>
                <?php echo $projectType === 'internal' ? 'Intern' : 'Extern'; ?>
            </span>
        </div>
        
        <!-- Title with Gradient Effect -->
        <h1 class="prv-title break-words hyphens-auto">
            <?php echo htmlspecialchars($project['title']); ?>
        </h1>

        <!-- Project Information with Modern Cards -->
        <div class="prv-info-grid mb-8">
            <?php if (!empty($project['client_name'])): ?>
            <div class="prv-info-item">
                <div class="prv-info-label">Kunde</div>
                <div class="prv-info-value"><?php echo htmlspecialchars($project['client_name']); ?></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($project['start_date'])): ?>
            <div class="prv-info-item">
                <div class="prv-info-label">Startdatum</div>
                <div class="prv-info-value"><?php echo date('d.m.Y', strtotime($project['start_date'])); ?></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($project['end_date'])): ?>
            <div class="prv-info-item">
                <div class="prv-info-label">Enddatum</div>
                <div class="prv-info-value"><?php echo date('d.m.Y', strtotime($project['end_date'])); ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Team Progress Bar -->
        <div class="prv-team-progress mb-8">
            <div class="prv-progress-header">
                <h3 class="prv-progress-title">
                    <div class="prv-progress-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    Team Status
                </h3>
                <span class="prv-progress-count">
                    <?php echo $teamSize; ?> / <?php echo $maxConsultants; ?>
                </span>
            </div>
            <div class="prv-progress-bar-container">
                <div class="prv-progress-bar-fill"
                     style="width: <?php echo $teamPercentage; ?>%;">
                    <?php if ($teamSize > 0): ?>
                    <span class="prv-progress-text">
                        <?php echo $teamPercentage; ?>%
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mt-3 text-sm font-medium" style="color: var(--text-muted);">
                <?php if ($teamPercentage >= 100): ?>
                    <i class="fas fa-check-circle text-green-500 mr-1"></i> Team vollständig besetzt
                <?php elseif ($teamPercentage >= 75): ?>
                    <i class="fas fa-info-circle mr-1"></i> Nur noch wenige Plätze verfügbar
                <?php else: ?>
                    <i class="fas fa-users mr-1"></i> Weitere Teammitglieder gesucht
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Description -->
        <?php if (!empty($project['description'])): ?>
        <div class="prv-description mb-6">
            <h2 class="prv-description-title">Beschreibung</h2>
            <div class="prv-description-content">
                <?php echo htmlspecialchars($project['description']); ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Project Documentation (Only for completed projects) -->
        <?php if ($project['status'] === 'completed' && !empty($project['documentation'])): ?>
        <div class="mb-6 p-6 bg-gradient-to-r from-green-50 to-teal-50 rounded-lg border border-green-200">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-file-alt text-green-600 mr-2"></i>
                Projekt-Dokumentation
            </h2>
            <div class="text-gray-700 leading-relaxed whitespace-pre-line bg-white p-4 rounded-lg shadow-sm break-words hyphens-auto">
                <?php echo htmlspecialchars($project['documentation']); ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Complete Project Button (Only for leads/admins when status is running) -->
        <?php if ($canComplete && $project['status'] === 'running'): ?>
        <div class="mb-6">
            <?php if (isset($_GET['action']) && $_GET['action'] === 'complete'): ?>
                <!-- Show Completion Form -->
                <div class="bg-gradient-to-r from-green-50 to-teal-50 border border-green-200 rounded-xl p-8">
                    <h2 class="text-xl sm:text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">
                        <i class="fas fa-check-circle text-green-600 mr-2"></i>
                        Projekt abschließen
                    </h2>
                    
                    <p class="text-gray-600 mb-6">
                        Bitte geben Sie einen Abschlussbericht für das Projekt ein. Dieser wird nach dem Abschluss für alle Teammitglieder sichtbar sein.
                    </p>
                    
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
                        <input type="hidden" name="complete_project" value="1">
                        
                        <div>
                            <label class="flex items-center text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                <i class="fas fa-file-alt text-green-600 mr-2"></i>
                                Abschlussbericht / Dokumentation <span class="text-red-500 ml-1">*</span>
                            </label>
                            <textarea 
                                name="documentation" 
                                rows="8"
                                required
                                class="w-full bg-white border-gray-300 text-gray-900 rounded-lg p-4 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 transition duration-200"
                                placeholder="Beschreiben Sie die Ergebnisse des Projekts, wichtige Erkenntnisse und weitere relevante Informationen..."
                            ></textarea>
                        </div>
                        
                        <div class="flex flex-col md:flex-row gap-4">
                            <button type="submit" 
                                    class="flex-1 bg-gradient-to-r from-green-600 to-teal-600 text-white font-bold py-4 rounded-lg shadow-md hover:shadow-xl transform hover:-translate-y-1 transition duration-200">
                                <i class="fas fa-check-circle mr-2"></i>
                                Projekt abschließen
                            </button>
                            <a href="view.php?id=<?php echo (int)$project['id']; ?>" 
                               class="flex-1 text-center px-6 py-4 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition font-bold">
                                Abbrechen
                            </a>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <!-- Show Complete Button -->
                <a href="view.php?id=<?php echo (int)$project['id']; ?>&action=complete" 
                   class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-green-600 to-teal-600 text-white rounded-lg font-semibold hover:from-green-700 hover:to-teal-700 shadow-md hover:shadow-lg transform hover:-translate-y-1 transition duration-200">
                    <i class="fas fa-check-circle mr-2"></i>
                    Projekt abschließen
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Application / Participation Section -->
        <?php if (($project['status'] === 'open' || $project['status'] === 'applying' || $project['status'] === 'running') && $userRole !== 'alumni'): ?>
        <div class="prv-section-divider">

            <?php if ($isInternalProject && !$requiresApplication): ?>
                <!-- Internal project with no application required: direct join/leave button -->
                <?php if ($isParticipant): ?>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex items-center text-green-700 bg-green-50 border border-green-200 rounded-lg px-4 py-3">
                        <i class="fas fa-check-circle mr-2"></i>
                        Du nimmst an diesem Projekt teil
                    </div>
                    <?php if (!$isLead): ?>
                    <button id="leaveProjectBtn"
                            class="w-full sm:w-auto px-5 py-3 min-h-[44px] bg-red-100 text-red-700 rounded-lg font-semibold hover:bg-red-200 transition">
                        <i class="fas fa-sign-out-alt mr-2"></i>Verlassen
                    </button>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <button id="joinProjectBtn"
                        class="inline-flex items-center justify-center w-full sm:w-auto px-6 py-3 min-h-[44px] bg-indigo-600 text-white rounded-lg font-semibold hover:bg-indigo-700 transition shadow-md">
                    <i class="fas fa-user-plus mr-2"></i>
                    Jetzt Teilnehmen
                </button>
                <?php endif; ?>

            <?php elseif (!$requiresApplication): ?>
                <!-- External project with no application required: direct join button -->
                <?php if ($userApplication): ?>
                <div class="flex items-center text-green-700 bg-green-50 border border-green-200 rounded-lg px-4 py-3">
                    <i class="fas fa-check-circle mr-2"></i>
                    Du hast Dich für dieses Projekt registriert
                </div>
                <?php elseif ($project['status'] === 'open' || $project['status'] === 'applying'): ?>
                <form method="POST" action="view.php?id=<?php echo (int)$project['id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
                    <input type="hidden" name="apply" value="1">
                    <input type="hidden" name="motivation" value="">
                    <input type="hidden" name="experience_count" value="0">
                    <input type="hidden" name="experience_confirmed" value="1">
                    <input type="hidden" name="gdpr_consent" value="1">
                    <button type="submit"
                            class="inline-flex items-center justify-center w-full sm:w-auto px-6 py-3 min-h-[44px] bg-green-600 text-white rounded-lg font-semibold hover:bg-green-700 transition shadow-md">
                        <i class="fas fa-user-plus mr-2"></i>
                        Jetzt teilnehmen
                    </button>
                </form>
                <?php endif; ?>

            <?php else: ?>
                <!-- Application required (internal or external with requires_application=1) -->
                <?php if ($project['status'] === 'open' || $project['status'] === 'applying'): ?>
                <?php if ($userApplication): ?>
                    <!-- Show Application Status -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-6">
                        <h2 class="text-xl sm:text-2xl font-bold text-gray-800 dark:text-gray-100 mb-4">
                            <i class="fas fa-check-circle text-blue-600 mr-2"></i>
                            Deine Bewerbung
                        </h2>
                        
                        <div class="space-y-3">
                            <div>
                                <span class="text-sm text-gray-600">Status:</span>
                                <span class="ml-2 px-3 py-1 text-sm font-semibold rounded-full
                                    <?php 
                                    switch($userApplication['status']) {
                                        case 'pending': echo 'bg-yellow-100 text-yellow-800'; break;
                                        case 'reviewing': echo 'bg-blue-100 text-blue-800'; break;
                                        case 'accepted': echo 'bg-green-100 text-green-800'; break;
                                        case 'rejected': echo 'bg-red-100 text-red-800'; break;
                                        default: echo 'bg-gray-100 text-gray-800'; break;
                                    }
                                    ?>">
                                    <?php 
                                    switch($userApplication['status']) {
                                        case 'pending': echo 'In Prüfung'; break;
                                        case 'reviewing': echo 'Wird geprüft'; break;
                                        case 'accepted': echo 'Akzeptiert'; break;
                                        case 'rejected': echo 'Abgelehnt'; break;
                                        default: echo htmlspecialchars(ucfirst($userApplication['status']), ENT_QUOTES, 'UTF-8'); break;
                                    }
                                    ?>
                                </span>
                            </div>
                            
                            <div>
                                <span class="text-sm text-gray-600">Bewerbungsdatum:</span>
                                <span class="ml-2 font-semibold"><?php echo date('d.m.Y H:i', strtotime($userApplication['created_at'])); ?> Uhr</span>
                            </div>
                            
                            <?php if (!empty($userApplication['motivation'])): ?>
                            <div class="mt-4">
                                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Deine Motivation:</span>
                                <div class="mt-2 p-3 bg-white rounded border border-gray-200 text-gray-700 whitespace-pre-line break-words hyphens-auto">
                                    <?php echo htmlspecialchars($userApplication['motivation']); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif (isset($_GET['action']) && $_GET['action'] === 'apply'): ?>
                    <!-- Show Application Form -->
                    <div class="bg-white shadow-lg rounded-xl p-8 border border-gray-100">
                        <h2 class="text-xl sm:text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6">
                            <i class="fas fa-paper-plane text-blue-600 mr-2" aria-hidden="true"></i>
                            Jetzt bewerben
                        </h2>
                        
                        <!-- Motivational Text -->
                        <p class="text-gray-600 mb-6">
                            Möchtest du Teil dieses Projekts sein? Bewirb dich jetzt in wenigen Schritten.
                        </p>
                        
                        <form method="POST" action="view.php?id=<?php echo (int)$project['id']; ?>" class="space-y-6">
                            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
                            <input type="hidden" name="apply" value="1">
                            
                            <div>
                                <label class="flex items-center text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-comment-dots text-blue-600 mr-2" aria-hidden="true"></i>
                                    Motivation <span class="text-red-500 ml-1">*</span>
                                </label>
                                <textarea 
                                    name="motivation" 
                                    rows="5"
                                    required
                                    class="w-full bg-gray-50 border border-gray-300 rounded-lg p-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                                    placeholder="Warum möchten Sie an diesem Projekt teilnehmen?"
                                ></textarea>
                            </div>
                            
                            <div>
                                <label class="flex items-center text-sm font-medium text-gray-700 mb-2">
                                    <i class="fas fa-briefcase text-blue-600 mr-2" aria-hidden="true"></i>
                                    Anzahl bisheriger Projekterfahrungen
                                </label>
                                <input 
                                    type="number" 
                                    name="experience_count" 
                                    min="0"
                                    value="0"
                                    class="w-full bg-gray-50 border border-gray-300 rounded-lg p-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition duration-200"
                                >
                            </div>
                            
                            <!-- Experience Confirmation Checkbox -->
                            <div class="flex items-start min-h-[44px]">
                                <input 
                                    type="checkbox" 
                                    id="experience_confirmed" 
                                    name="experience_confirmed" 
                                    value="1"
                                    required
                                    class="mt-1 h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                                >
                                <label for="experience_confirmed" class="ml-3 text-sm text-gray-700">
                                    Ich bestätige, dass ich die Anzahl bisheriger Projekte korrekt angegeben habe <span class="text-red-500">*</span>
                                </label>
                            </div>
                            
                            <!-- GDPR Consent Checkbox -->
                            <div class="flex items-start min-h-[44px]">
                                <input 
                                    type="checkbox" 
                                    id="gdpr_consent" 
                                    name="gdpr_consent" 
                                    value="1"
                                    required
                                    class="mt-1 h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                                >
                                <label for="gdpr_consent" class="ml-3 text-sm text-gray-700">
                                    Ich willige in die Verarbeitung meiner Daten zwecks Projektvergabe ein (DSGVO) <span class="text-red-500">*</span>
                                </label>
                            </div>
                            
                            <div class="flex flex-col space-y-4 pt-4">
                                <button type="submit" 
                                        class="w-full bg-gradient-to-r from-blue-600 to-blue-800 text-white font-bold py-4 rounded-lg shadow-md hover:shadow-xl transform hover:-translate-y-1 focus:scale-105 focus:ring-4 focus:ring-blue-300 transition duration-200">
                                    <i class="fas fa-paper-plane mr-2" aria-hidden="true"></i>
                                    Bewerbung absenden
                                </button>
                                <a href="view.php?id=<?php echo (int)$project['id']; ?>" 
                                   class="w-full text-center px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 focus:ring-2 focus:ring-gray-400 focus:outline-none transition font-medium">
                                    Abbrechen
                                </a>
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                    <!-- Show "Apply Now" button when user hasn't applied yet -->
                    <a href="view.php?id=<?php echo (int)$project['id']; ?>&action=apply" 
                       class="inline-block px-6 py-3 bg-green-600 text-white rounded-lg font-semibold hover:bg-green-700 transition">
                        <i class="fas fa-paper-plane mr-2"></i>
                        Jetzt bewerben
                    </a>
                <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>

        </div>
        <?php endif; ?>

        <!-- Feedback Ansprechpartner Section -->
        <?php if ($feedbackContact): ?>
        <div class="prv-section-divider">
            <div class="bg-gradient-to-r from-purple-50 to-indigo-50 dark:from-purple-900/20 dark:to-indigo-900/20 rounded-2xl p-6 border border-purple-100 dark:border-purple-800">
                <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">
                    <span class="w-8 h-8 rounded-lg bg-purple-600/10 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-comment-dots text-purple-600 text-sm"></i>
                    </span>
                    Feedback Ansprechpartner
                </h2>
                <div class="flex items-center gap-4">
                    <?php if (!empty($feedbackContact['image_path'])): ?>
                    <img src="/<?php echo htmlspecialchars($feedbackContact['image_path']); ?>"
                         alt="<?php echo htmlspecialchars(trim($feedbackContact['first_name'] . ' ' . $feedbackContact['last_name'])); ?>"
                         class="w-16 h-16 rounded-full object-cover border-2 border-purple-300 shadow-md flex-shrink-0">
                    <?php else: ?>
                    <div class="w-16 h-16 rounded-full bg-purple-100 dark:bg-purple-900/40 flex items-center justify-center flex-shrink-0 border-2 border-purple-300">
                        <i class="fas fa-user text-purple-600 text-xl"></i>
                    </div>
                    <?php endif; ?>
                    <div>
                        <div class="font-bold text-gray-900 dark:text-white text-base">
                            <?php echo htmlspecialchars(trim($feedbackContact['first_name'] . ' ' . $feedbackContact['last_name'])); ?>
                        </div>
                        <?php if (!empty($feedbackContact['position']) || !empty($feedbackContact['company'])): ?>
                        <div class="text-sm text-gray-600 dark:text-gray-300 mt-0.5">
                            <?php
                            $parts = array_filter([$feedbackContact['position'] ?? '', $feedbackContact['company'] ?? '']);
                            echo htmlspecialchars(implode(' · ', $parts));
                            ?>
                        </div>
                        <?php endif; ?>
                        <div class="text-xs text-purple-600 dark:text-purple-400 mt-1 font-medium">
                            <i class="fas fa-star mr-1"></i>Stellt sich für Feedback zur Verfügung
                        </div>
                    </div>
                    <?php if ($isFeedbackContact): ?>
                    <button id="removeFeedbackContactBtn"
                            class="ml-auto px-4 py-2 bg-red-100 text-red-700 rounded-lg font-semibold hover:bg-red-200 transition text-sm">
                        <i class="fas fa-times mr-1"></i>Zurückziehen
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php elseif ($canBecomeFeedbackContact): ?>
        <div class="border-t border-gray-200 pt-6 mt-6">
            <button id="becomeFeedbackContactBtn"
                    class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-purple-600 to-indigo-600 text-white rounded-xl font-semibold hover:from-purple-700 hover:to-indigo-700 transition shadow-md text-sm">
                <i class="fas fa-comment-dots mr-2"></i>
                Feedback Ansprechpartner werden
            </button>
        </div>
        <?php endif; ?>

        <!-- Participant List -->
        <?php if (!empty($participants)): ?>
        <div class="prv-section-divider">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
                <i class="fas fa-users text-purple-600 mr-2"></i>
                Teilnehmer (<?php echo count($participants); ?>)
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <?php foreach ($participants as $participant): ?>
                <div class="flex items-center gap-3 bg-gray-50 dark:bg-gray-800/50 rounded-xl p-3 border border-gray-100 dark:border-gray-700">
                    <div class="w-9 h-9 rounded-full bg-purple-100 dark:bg-purple-900/40 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-user text-purple-600 dark:text-purple-400 text-sm"></i>
                    </div>
                    <div class="min-w-0">
                        <div class="font-semibold text-gray-900 dark:text-white text-sm truncate">
                            <?php echo htmlspecialchars(trim(($participant['first_name'] ?? '') . ' ' . ($participant['last_name'] ?? ''))); ?>
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            <?php echo $participant['role'] === 'lead' ? '<i class="fas fa-star text-yellow-500 mr-1"></i>Projektleiter' : 'Mitglied'; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<style>
/* PRV (Project View) scoped styles */
.prv-container {
    max-width: 56rem;
    margin: 0 auto;
}

.prv-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
}

.prv-back-btn {
    display: inline-flex;
    align-items: center;
    color: var(--ibc-blue);
    text-decoration: none;
    transition: all 0.2s ease;
}

.prv-back-btn:hover {
    transform: translateX(-2px);
}

.prv-edit-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 44px;
    padding: 0.5rem 1rem;
    background-color: var(--ibc-blue);
    color: white;
    border-radius: 0.5rem;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.2s ease;
}

.prv-edit-btn:hover {
    box-shadow: var(--shadow-card-hover);
    transform: translateY(-2px);
}

.prv-draft-warning {
    padding: 1rem;
    background-color: rgba(234, 179, 8, 0.1);
    border-left: 4px solid #ea b300;
    color: #a16207;
    margin-bottom: 1rem;
    border-radius: 0.375rem;
}

.prv-message {
    padding: 1rem;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    margin-bottom: 1.5rem;
    animation: slideIn 0.3s ease-out cubic-bezier(.22,.68,0,1.2);
}

.prv-message--success {
    background-color: rgba(34, 197, 94, 0.1);
    border: 1px solid rgba(34, 197, 94, 0.3);
    color: var(--ibc-green);
}

.prv-message--error {
    background-color: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: #ef4444;
}

.prv-card {
    background-color: var(--bg-card);
    border-radius: 0.75rem;
    box-shadow: var(--shadow-card);
    padding: 2rem;
    position: relative;
    overflow: hidden;
    transition: all 0.3s ease;
    animation: fadeIn 0.5s ease-out cubic-bezier(.22,.68,0,1.2);
}

.prv-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--ibc-blue), var(--ibc-green), var(--ibc-blue));
}

.prv-card:hover {
    box-shadow: var(--shadow-card-hover);
}

.prv-hero-image {
    width: 100%;
    border-radius: 0.75rem;
    overflow: hidden;
    box-shadow: var(--shadow-card);
    margin-bottom: 2rem;
}

.prv-hero-image img {
    width: 100%;
    height: auto;
    display: block;
    transition: transform 0.3s ease;
}

.prv-hero-image:hover img {
    transform: scale(1.02);
}

.prv-pdf-btn {
    display: inline-flex;
    align-items: center;
    padding: 1rem 1.5rem;
    background: linear-gradient(135deg, #dc2626, #f43f5e);
    color: white;
    border-radius: 0.75rem;
    font-weight: 700;
    text-decoration: none;
    margin-bottom: 2rem;
    box-shadow: var(--shadow-card);
    transition: all 0.3s ease;
}

.prv-pdf-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-card-hover);
}

.prv-badges {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin-bottom: 2rem;
    flex-wrap: wrap;
}

.prv-badge {
    padding: 0.625rem 1.25rem;
    border-radius: 9999px;
    font-size: 0.875rem;
    font-weight: 700;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    transition: all 0.2s ease;
}

.prv-badge:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
}

.prv-status-draft {
    background: linear-gradient(135deg, #9ca3af, #6b7280);
    color: white;
}

.prv-status-open {
    background: linear-gradient(135deg, #3b82f6, #2563eb);
    color: white;
}

.prv-status-applying {
    background: linear-gradient(135deg, #eab308, #d97706);
    color: white;
}

.prv-status-assigned {
    background: linear-gradient(135deg, #22c55e, #16a34a);
    color: white;
}

.prv-status-running {
    background: linear-gradient(135deg, #a855f7, #9333ea);
    color: white;
}

.prv-status-completed {
    background: linear-gradient(135deg, #14b8a6, #0d9488);
    color: white;
}

.prv-status-archived {
    background: #e5e7eb;
    color: #374151;
}

.prv-priority-low {
    background: linear-gradient(135deg, #60a5fa, #3b82f6);
    color: white;
}

.prv-priority-medium {
    background: linear-gradient(135deg, #fbbf24, #f59e0b);
    color: white;
}

.prv-priority-high {
    background: linear-gradient(135deg, #f87171, #ef4444);
    color: white;
}

.prv-type-internal {
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    color: white;
}

.prv-type-external {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.prv-title {
    font-size: 2rem;
    font-weight: 700;
    margin-bottom: 2rem;
    background: linear-gradient(135deg, var(--ibc-blue), var(--ibc-green));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    word-break: break-words;
}

@media (max-width: 640px) {
    .prv-title {
        font-size: 1.5rem;
    }
}

.prv-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.25rem;
    margin-bottom: 2rem;
}

.prv-info-item {
    background-color: var(--bg-body);
    border-radius: 1rem;
    padding: 1.25rem;
    border: 1px solid var(--border-color);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.prv-info-label {
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.5rem;
}

.prv-info-value {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--text-main);
}

.prv-team-progress {
    background: linear-gradient(135deg, rgba(168, 85, 247, 0.1), rgba(59, 130, 246, 0.1));
    border: 1px solid rgba(168, 85, 247, 0.3);
    border-radius: 1rem;
    padding: 1.5rem;
    margin-bottom: 2rem;
}

.prv-progress-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
}

.prv-progress-title {
    display: flex;
    align-items: center;
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--text-main);
}

.prv-progress-icon {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 0.75rem;
    background-color: var(--ibc-blue);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 0.75rem;
}

.prv-progress-count {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--ibc-blue);
}

.prv-progress-bar-container {
    position: relative;
    width: 100%;
    height: 1.25rem;
    background-color: var(--border-color);
    border-radius: 9999px;
    overflow: hidden;
}

.prv-progress-bar-fill {
    background-color: var(--ibc-blue);
    height: 100%;
    border-radius: 9999px;
    transition: width 0.5s ease;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    padding-right: 0.75rem;
}

.prv-progress-text {
    font-size: 0.75rem;
    font-weight: 700;
    color: white;
}

.prv-description {
    margin-bottom: 1.5rem;
}

.prv-description-title {
    font-size: 1.125rem;
    font-weight: 700;
    color: var(--text-main);
    margin-bottom: 0.75rem;
}

.prv-description-content {
    color: var(--text-main);
    line-height: 1.6;
    white-space: pre-line;
    word-break: break-words;
}

.prv-section-divider {
    border-top: 1px solid var(--border-color);
    padding-top: 1.5rem;
    margin-top: 1.5rem;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
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

.dark-mode .prv-info-item {
    background-color: var(--bg-body);
    border-color: var(--border-color);
}

.dark-mode .prv-draft-warning {
    background-color: rgba(234, 179, 8, 0.15);
    border-color: rgba(234, 179, 8, 0.4);
}
</style>

<script>
(function () {
    const csrfToken = '<?php echo CSRFHandler::getToken(); ?>';
    const projectId = <?php echo intval($project['id']); ?>;

    function sendProjectAction(action, btn) {
        btn.disabled = true;
        fetch('/api/project_join.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: action, project_id: projectId, csrf_token: csrfToken})
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Reload page to reflect updated state
                window.location.reload();
            } else {
                alert(data.message || 'Ein Fehler ist aufgetreten');
                btn.disabled = false;
            }
        })
        .catch(() => {
            alert('Ein Fehler ist aufgetreten');
            btn.disabled = false;
        });
    }

    const joinBtn = document.getElementById('joinProjectBtn');
    if (joinBtn) {
        joinBtn.addEventListener('click', function () {
            sendProjectAction('join', this);
        });
    }

    const leaveBtn = document.getElementById('leaveProjectBtn');
    if (leaveBtn) {
        leaveBtn.addEventListener('click', function () {
            if (confirm('Möchtest du das Projekt wirklich verlassen?')) {
                sendProjectAction('leave', this);
            }
        });
    }

    function sendFeedbackContactAction(action, btn) {
        btn.disabled = true;
        fetch('/api/set_feedback_contact.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({type: 'project', id: projectId, action: action, csrf_token: csrfToken})
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                alert(data.message || 'Ein Fehler ist aufgetreten');
                btn.disabled = false;
            }
        })
        .catch(() => {
            alert('Ein Fehler ist aufgetreten');
            btn.disabled = false;
        });
    }

    const becomeBtn = document.getElementById('becomeFeedbackContactBtn');
    if (becomeBtn) {
        becomeBtn.addEventListener('click', function () {
            sendFeedbackContactAction('set', this);
        });
    }

    const removeContactBtn = document.getElementById('removeFeedbackContactBtn');
    if (removeContactBtn) {
        removeContactBtn.addEventListener('click', function () {
            if (confirm('Möchtest du dich als Feedback-Ansprechpartner zurückziehen?')) {
                sendFeedbackContactAction('remove', this);
            }
        });
    }
})();
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
