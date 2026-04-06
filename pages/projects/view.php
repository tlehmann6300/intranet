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

<div class="max-w-4xl mx-auto">
    <!-- Back Button -->
    <div class="mb-6 flex flex-wrap items-center justify-between gap-2">
        <a href="index.php" class="inline-flex items-center text-purple-600 dark:text-purple-400 hover:text-purple-700 dark:hover:text-purple-300 transition font-medium">
            <i class="fas fa-arrow-left mr-2"></i>
            Zurück zur Übersicht
        </a>
        <?php if ($user['id'] === ($project['created_by'] ?? null) || Auth::isBoard() || Auth::hasPermission('manage_projects')): ?>
        <a href="manage.php?edit=<?= (int)$project['id'] ?>" class="inline-flex items-center px-4 py-2 min-h-[44px] bg-purple-600 text-white rounded-xl hover:bg-purple-700 transition font-semibold text-sm shadow-soft">
            <i class="fas fa-edit mr-2"></i>
            Projekt bearbeiten
        </a>
        <?php endif; ?>
    </div>
    
    <!-- Draft Warning -->
    <?php if ($project['status'] === 'draft' && Auth::hasPermission('manage_projects')): ?>
    <div class="bg-yellow-50 dark:bg-yellow-900/20 border-l-4 border-yellow-500 text-yellow-700 dark:text-yellow-300 p-4 mb-4 rounded-r-xl">
        <i class="fas fa-exclamation-triangle mr-2"></i>Status: ENTWURF – Für Mitglieder noch nicht sichtbar.
    </div>
    <?php endif; ?>
    
    <?php if ($message): ?>
    <div class="mb-6 p-4 bg-green-50 dark:bg-green-900/20 border border-green-400 dark:border-green-700 text-green-700 dark:text-green-300 rounded-xl flex items-center gap-2">
        <i class="fas fa-check-circle flex-shrink-0"></i><?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-400 dark:border-red-700 text-red-700 dark:text-red-300 rounded-xl flex items-center gap-2">
        <i class="fas fa-exclamation-circle flex-shrink-0"></i><?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <!-- Project Card with Enhanced Design -->
    <div class="project-detail-card card relative overflow-hidden">
        <!-- Top accent gradient bar -->
        <div class="h-1.5 bg-gradient-to-r from-purple-600 via-blue-600 to-green-500" aria-hidden="true"></div>

        <!-- Hero: Image or gradient banner -->
        <?php
        $heroImageValid = false;
        if (!empty($project['image_path'])) {
            $baseDirPath = __DIR__ . '/../../';
            $baseDir = realpath($baseDirPath);
            $imgRealPath = realpath($baseDirPath . $project['image_path']);
            $heroImageValid = $imgRealPath && $baseDir && strpos($imgRealPath, $baseDir) === 0 && file_exists($imgRealPath);
        }
        ?>
        <div class="project-detail-hero relative overflow-hidden">
            <?php if ($heroImageValid): ?>
                <img src="/<?php echo htmlspecialchars($project['image_path']); ?>"
                     alt="<?php echo htmlspecialchars($project['title']); ?>"
                     class="w-full h-full object-cover">
            <?php else: ?>
                <div class="w-full h-full project-detail-hero-placeholder flex items-center justify-center">
                    <i class="fas fa-briefcase text-white/15 text-8xl sm:text-9xl"></i>
                </div>
            <?php endif; ?>
            <!-- Overlay -->
            <div class="absolute inset-0 bg-gradient-to-t from-black/75 via-black/30 to-transparent"></div>
            <!-- Title overlay -->
            <div class="absolute bottom-0 left-0 right-0 p-5 sm:p-8">
                <!-- Badges -->
                <div class="flex flex-wrap items-center gap-2 mb-3">
                    <?php
                    $sBadge = '';
                    $sIcon  = 'fa-circle';
                    switch($project['status']) {
                        case 'draft':     $sBadge = 'bg-gray-500/80';   $sIcon = 'fa-pencil-alt';     $sLabel = 'Entwurf'; break;
                        case 'open':      $sBadge = 'bg-blue-600/80';   $sIcon = 'fa-door-open';      $sLabel = 'Offen'; break;
                        case 'applying':  $sBadge = 'bg-amber-500/80';  $sIcon = 'fa-hourglass-half'; $sLabel = 'Bewerbungsphase'; break;
                        case 'assigned':  $sBadge = 'bg-green-600/80';  $sIcon = 'fa-user-check';     $sLabel = 'Vergeben'; break;
                        case 'running':   $sBadge = 'bg-purple-700/80'; $sIcon = 'fa-play';           $sLabel = 'Laufend'; break;
                        case 'completed': $sBadge = 'bg-teal-600/80';   $sIcon = 'fa-flag-checkered'; $sLabel = 'Abgeschlossen'; break;
                        case 'archived':  $sBadge = 'bg-gray-600/70';   $sIcon = 'fa-archive';        $sLabel = 'Archiviert'; break;
                        default:          $sBadge = 'bg-gray-500/80';   $sLabel = ucfirst($project['status']); break;
                    }
                    ?>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 <?php echo $sBadge; ?> backdrop-blur-sm text-white text-xs font-semibold rounded-full border border-white/20">
                        <i class="fas <?php echo $sIcon; ?> text-[10px]"></i><?php echo $sLabel; ?>
                    </span>
                    <?php $pType = $project['type'] ?? 'internal'; ?>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 <?php echo $pType === 'internal' ? 'bg-indigo-600/80' : 'bg-green-600/80'; ?> backdrop-blur-sm text-white text-xs font-semibold rounded-full border border-white/20">
                        <i class="fas <?php echo $pType === 'internal' ? 'fa-building' : 'fa-users'; ?> text-[10px]"></i><?php echo $pType === 'internal' ? 'Intern' : 'Extern'; ?>
                    </span>
                    <?php if (!empty($project['priority'])): ?>
                    <?php
                    switch($project['priority']) {
                        case 'low':    $priB = 'bg-blue-400/80';    $priI = 'fa-arrow-down'; $priL = 'Niedrig'; break;
                        case 'medium': $priB = 'bg-yellow-500/80';  $priI = 'fa-minus';      $priL = 'Mittel';  break;
                        case 'high':   $priB = 'bg-red-600/80';     $priI = 'fa-arrow-up';   $priL = 'Hoch';    break;
                        default:       $priB = 'bg-gray-500/80';    $priI = 'fa-circle';     $priL = ucfirst($project['priority']); break;
                    }
                    ?>
                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 <?php echo $priB; ?> backdrop-blur-sm text-white text-xs font-semibold rounded-full border border-white/20">
                        <i class="fas <?php echo $priI; ?> text-[10px]"></i><?php echo $priL; ?>
                    </span>
                    <?php endif; ?>
                </div>
                <!-- Project Title -->
                <h1 class="text-2xl sm:text-3xl font-bold text-white drop-shadow-lg leading-tight mt-2">
                    <?php echo htmlspecialchars($project['title']); ?>
                </h1>
            </div><!-- /title overlay -->
        </div><!-- /project-detail-hero -->

        <!-- Card Body -->
        <div class="p-5 sm:p-8">
        
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
        <div class="mb-6">
            <a href="/<?php echo htmlspecialchars($project['file_path']); ?>" 
               class="inline-flex items-center gap-2 px-5 py-3 bg-gradient-to-r from-red-600 to-rose-600 text-white rounded-xl font-semibold hover:from-red-700 hover:to-rose-700 shadow-soft hover:shadow-md transition-all"
               download>
                <i class="fas fa-file-pdf text-lg flex-shrink-0"></i>
                <span>Projekt-Datei herunterladen (PDF)</span>
            </a>
        </div>
        <?php endif; ?>
        
        <!-- Project Information with Modern Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5 mb-8">
            <?php if (!empty($project['client_name'])): ?>
            <div class="bg-gray-50 dark:bg-gray-800/50 rounded-2xl p-5 border border-gray-100 dark:border-gray-700 shadow-sm" aria-label="Kundeninformation">
                <div class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Kunde</div>
                <div class="text-lg font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($project['client_name']); ?></div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($project['start_date'])): ?>
            <div class="bg-gray-50 dark:bg-gray-800/50 rounded-2xl p-5 border border-gray-100 dark:border-gray-700 shadow-sm" aria-label="Startdatum">
                <div class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Startdatum</div>
                <div class="text-lg font-semibold text-gray-900 dark:text-white"><?php echo date('d.m.Y', strtotime($project['start_date'])); ?></div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($project['end_date'])): ?>
            <div class="bg-gray-50 dark:bg-gray-800/50 rounded-2xl p-5 border border-gray-100 dark:border-gray-700 shadow-sm" aria-label="Enddatum">
                <div class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Enddatum</div>
                <div class="text-lg font-semibold text-gray-900 dark:text-white"><?php echo date('d.m.Y', strtotime($project['end_date'])); ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Team Progress Bar -->
        <div class="mb-8 p-5 sm:p-6 bg-purple-50 dark:bg-purple-900/20 rounded-2xl border border-purple-200 dark:border-purple-800/50">
            <div class="flex items-center justify-between mb-4 gap-3">
                <h3 class="text-base sm:text-lg font-bold text-gray-800 dark:text-gray-100 flex items-center">
                    <div class="w-9 h-9 rounded-xl bg-purple-600 flex items-center justify-center mr-3 flex-shrink-0">
                        <i class="fas fa-users text-white text-sm"></i>
                    </div>
                    Team Status
                </h3>
                <span class="text-lg sm:text-xl font-bold text-purple-600 dark:text-purple-400 whitespace-nowrap">
                    <?php echo $teamSize; ?> / <?php echo $maxConsultants; ?>
                </span>
            </div>
            <div class="relative w-full bg-gray-200 dark:bg-gray-700 rounded-full h-4 overflow-hidden">
                <div class="h-4 rounded-full transition-all duration-500 flex items-center justify-end pr-3 bg-purple-600"
                     style="width: <?php echo $teamPercentage; ?>%;">
                    <?php if ($teamSize > 0): ?>
                    <span class="text-xs font-bold text-white leading-none">
                        <?php echo $teamPercentage; ?>%
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="mt-3 text-sm text-gray-600 dark:text-gray-400 font-medium">
                <?php if ($teamPercentage >= 100): ?>
                    <i class="fas fa-check-circle text-green-500 mr-1"></i> Team vollständig besetzt
                <?php elseif ($teamPercentage >= 75): ?>
                    <i class="fas fa-info-circle text-purple-500 dark:text-purple-400 mr-1"></i> Nur noch wenige Plätze verfügbar
                <?php else: ?>
                    <i class="fas fa-users text-purple-500 dark:text-purple-400 mr-1"></i> Weitere Teammitglieder gesucht
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Description -->
        <?php if (!empty($project['description'])): ?>
        <div class="mb-6">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-3">Beschreibung</h2>
            <div class="text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-line break-words hyphens-auto">
                <?php echo htmlspecialchars($project['description']); ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Project Documentation (Only for completed projects) -->
        <?php if ($project['status'] === 'completed' && !empty($project['documentation'])): ?>
        <div class="mb-6 p-5 sm:p-6 bg-gradient-to-r from-green-50 to-teal-50 dark:from-green-900/20 dark:to-teal-900/20 rounded-2xl border border-green-200 dark:border-green-800/50">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">
                <span class="w-8 h-8 rounded-lg bg-green-600/10 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-file-alt text-green-600 text-sm"></i>
                </span>
                Projekt-Dokumentation
            </h2>
            <div class="text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-line bg-white dark:bg-gray-800/50 p-4 rounded-xl shadow-sm break-words hyphens-auto border border-gray-100 dark:border-gray-700">
                <?php echo htmlspecialchars($project['documentation']); ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Complete Project Button (Only for leads/admins when status is running) -->
        <?php if ($canComplete && $project['status'] === 'running'): ?>
        <div class="mb-6">
            <?php if (isset($_GET['action']) && $_GET['action'] === 'complete'): ?>
                <!-- Show Completion Form -->
                <div class="bg-gradient-to-r from-green-50 to-teal-50 dark:from-green-900/20 dark:to-teal-900/20 border border-green-200 dark:border-green-800/50 rounded-2xl p-5 sm:p-8">
                    <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-5 flex items-center gap-2">
                        <span class="w-8 h-8 rounded-lg bg-green-600/10 flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-check-circle text-green-600 text-sm"></i>
                        </span>
                        Projekt abschließen
                    </h2>
                    
                    <p class="text-gray-600 dark:text-gray-400 mb-5 text-sm leading-relaxed">
                        Bitte gib einen Abschlussbericht für das Projekt ein. Dieser wird nach dem Abschluss für alle Teammitglieder sichtbar sein.
                    </p>
                    
                    <form method="POST" class="space-y-5">
                        <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
                        <input type="hidden" name="complete_project" value="1">
                        
                        <div>
                            <label class="flex items-center text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                <i class="fas fa-file-alt text-green-600 mr-2"></i>
                                Abschlussbericht / Dokumentation <span class="text-red-500 ml-1">*</span>
                            </label>
                            <textarea 
                                name="documentation" 
                                rows="7"
                                required
                                class="w-full rounded-xl p-4"
                                placeholder="Beschreibe die Ergebnisse des Projekts, wichtige Erkenntnisse und weitere relevante Informationen..."
                            ></textarea>
                        </div>
                        
                        <div class="flex flex-col sm:flex-row gap-3">
                            <button type="submit" 
                                    class="flex-1 inline-flex items-center justify-center gap-2 bg-gradient-to-r from-green-600 to-teal-600 text-white font-bold py-3.5 rounded-xl shadow-soft hover:shadow-md transition">
                                <i class="fas fa-check-circle"></i>
                                Projekt abschließen
                            </button>
                            <a href="view.php?id=<?php echo (int)$project['id']; ?>" 
                               class="flex-1 text-center px-6 py-3.5 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-700 transition font-semibold">
                                Abbrechen
                            </a>
                        </div>
                    </form>
                </div>
            <?php else: ?>
                <!-- Show Complete Button -->
                <a href="view.php?id=<?php echo (int)$project['id']; ?>&action=complete" 
                   class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-green-600 to-teal-600 text-white rounded-xl font-semibold hover:from-green-700 hover:to-teal-700 shadow-soft hover:shadow-md transition">
                    <i class="fas fa-check-circle"></i>
                    Projekt abschließen
                </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Application / Participation Section -->
        <?php if (($project['status'] === 'open' || $project['status'] === 'applying' || $project['status'] === 'running') && $userRole !== 'alumni'): ?>
        <div class="border-t border-gray-200 dark:border-gray-700 pt-6 mt-6">

            <?php if ($isInternalProject && !$requiresApplication): ?>
                <!-- Internal project with no application required: direct join/leave button -->
                <?php if ($isParticipant): ?>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex items-center text-green-700 dark:text-green-300 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl px-4 py-3">
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
                <div class="flex items-center text-green-700 dark:text-green-300 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl px-4 py-3">
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
                    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800/50 rounded-2xl p-5 sm:p-6">
                        <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-4 flex items-center gap-2">
                            <span class="w-8 h-8 rounded-lg bg-blue-600/10 flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-check-circle text-blue-600 text-sm"></i>
                            </span>
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
                    <div class="card dark:bg-gray-800 rounded-2xl p-5 sm:p-8 border border-gray-100 dark:border-gray-700 shadow-soft">
                        <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-5 flex items-center gap-2">
                            <span class="w-8 h-8 rounded-lg bg-blue-600/10 flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-paper-plane text-blue-600 text-sm" aria-hidden="true"></i>
                            </span>
                            Jetzt bewerben
                        </h2>
                        
                        <p class="text-gray-600 dark:text-gray-400 mb-5 text-sm leading-relaxed">
                            Möchtest du Teil dieses Projekts sein? Bewirb dich jetzt in wenigen Schritten.
                        </p>
                        
                        <form method="POST" action="view.php?id=<?php echo (int)$project['id']; ?>" class="space-y-5">
                            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
                            <input type="hidden" name="apply" value="1">
                            
                            <div>
                                <label class="flex items-center text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-comment-dots text-blue-600 mr-2" aria-hidden="true"></i>
                                    Motivation <span class="text-red-500 ml-1">*</span>
                                </label>
                                <textarea 
                                    name="motivation" 
                                    rows="5"
                                    required
                                    class="w-full rounded-xl p-3"
                                    placeholder="Warum möchtest du an diesem Projekt teilnehmen?"
                                ></textarea>
                            </div>
                            
                            <div>
                                <label class="flex items-center text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-briefcase text-blue-600 mr-2" aria-hidden="true"></i>
                                    Anzahl bisheriger Projekterfahrungen
                                </label>
                                <input 
                                    type="number" 
                                    name="experience_count" 
                                    min="0"
                                    value="0"
                                    class="w-full rounded-xl p-3"
                                >
                            </div>
                            
                            <!-- Experience Confirmation Checkbox -->
                            <div class="flex items-start gap-3 min-h-[44px]">
                                <input 
                                    type="checkbox" 
                                    id="experience_confirmed" 
                                    name="experience_confirmed" 
                                    value="1"
                                    required
                                    class="mt-1 h-5 w-5 text-blue-600 border-gray-300 rounded focus:ring-blue-500 flex-shrink-0"
                                >
                                <label for="experience_confirmed" class="text-sm text-gray-700 dark:text-gray-300 leading-relaxed">
                                    Ich bestätige, dass ich die Anzahl bisheriger Projekte korrekt angegeben habe <span class="text-red-500">*</span>
                                </label>
                            </div>
                            
                            <!-- GDPR Consent Checkbox -->
                            <div class="flex items-start gap-3 min-h-[44px]">
                                <input 
                                    type="checkbox" 
                                    id="gdpr_consent" 
                                    name="gdpr_consent" 
                                    value="1"
                                    required
                                    class="mt-1 h-5 w-5 text-blue-600 border-gray-300 rounded focus:ring-blue-500 flex-shrink-0"
                                >
                                <label for="gdpr_consent" class="text-sm text-gray-700 dark:text-gray-300 leading-relaxed">
                                    Ich willige in die Verarbeitung meiner Daten zwecks Projektvergabe ein (DSGVO) <span class="text-red-500">*</span>
                                </label>
                            </div>
                            
                            <div class="flex flex-col sm:flex-row gap-3 pt-2">
                                <button type="submit" 
                                        class="flex-1 inline-flex items-center justify-center gap-2 bg-gradient-to-r from-blue-600 to-blue-800 text-white font-bold py-3.5 rounded-xl shadow-soft hover:shadow-md transition">
                                    <i class="fas fa-paper-plane" aria-hidden="true"></i>
                                    Bewerbung absenden
                                </button>
                                <a href="view.php?id=<?php echo (int)$project['id']; ?>" 
                                   class="flex-1 text-center px-6 py-3.5 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-xl hover:bg-gray-200 dark:hover:bg-gray-600 transition font-semibold">
                                    Abbrechen
                                </a>
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                    <!-- Show "Apply Now" button when user hasn't applied yet -->
                    <a href="view.php?id=<?php echo (int)$project['id']; ?>&action=apply" 
                       class="inline-flex items-center gap-2 px-6 py-3 bg-green-600 text-white rounded-xl font-semibold hover:bg-green-700 transition shadow-soft">
                        <i class="fas fa-paper-plane"></i>
                        Jetzt bewerben
                    </a>
                <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>

        </div>
        <?php endif; ?>

        <!-- Feedback Ansprechpartner Section -->
        <?php if ($feedbackContact): ?>
        <div class="border-t border-gray-200 pt-6 mt-6">
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
        <div class="border-t border-gray-200 dark:border-gray-700 pt-6 mt-6">
            <button id="becomeFeedbackContactBtn"
                    class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-purple-600 to-indigo-600 text-white rounded-xl font-semibold hover:from-purple-700 hover:to-indigo-700 transition shadow-md text-sm">
                <i class="fas fa-comment-dots mr-2"></i>
                Feedback Ansprechpartner werden
            </button>
        </div>
        <?php endif; ?>

        <!-- Participant List -->
        <?php if (!empty($participants)): ?>
        <div class="border-t border-gray-200 dark:border-gray-700 pt-6 mt-6">
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

    </div><!-- /card body -->
    </div><!-- /project-detail-card -->
</div><!-- /max-w-4xl -->

<style>
    /* Enhanced project detail card */
    .project-detail-card {
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        transition: box-shadow 0.3s ease;
        animation: fadeIn 0.5s ease-out;
        overflow: hidden;
    }

    /* ── Hero Section ───────────────────────────────── */
    .project-detail-hero {
        height: 280px;
        position: relative;
        background: #1f2937;
    }
    @media (min-width: 640px) {
        .project-detail-hero { height: 340px; }
    }
    @media (min-width: 768px) {
        .project-detail-hero { height: 420px; }
    }
    @media (max-width: 360px) {
        .project-detail-hero { height: 220px; }
    }
    .project-detail-hero img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }
    .project-detail-hero-placeholder {
        background: linear-gradient(135deg, #7c3aed 0%, #4f46e5 55%, #1e1b4b 100%);
        width: 100%;
        height: 100%;
    }

    /* Animated gradient for progress bar */
    @keyframes gradient {
        0%   { background-position: 0% 50%; }
        50%  { background-position: 100% 50%; }
        100% { background-position: 0% 50%; }
    }

    /* Fade in animation */
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to   { opacity: 1; transform: translateY(0); }
    }

    /* Respect user motion preferences */
    @media (prefers-reduced-motion: reduce) {
        .project-detail-card { animation: none; }
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
