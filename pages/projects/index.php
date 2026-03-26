<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Project.php';
require_once __DIR__ . '/../../src/Database.php';

// Check authentication - any logged-in user can view projects
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$userRole = $_SESSION['user_role'] ?? 'mitglied';

// Get filter parameter from URL
$typeFilter = $_GET['type'] ?? 'all';
$validTypes = ['all', 'internal', 'external'];
if (!in_array($typeFilter, $validTypes)) {
    $typeFilter = 'all';
}

$searchQuery = trim($_GET['q'] ?? '');

// Get all projects based on filter
$db = Database::getContentDB();

// Check if user is admin - they can see archived projects
$isAdmin = Auth::isBoard() || Auth::hasPermission('manage_projects');

if ($typeFilter === 'all') {
    if ($isAdmin) {
        // Admins see all non-draft projects (including archived)
        $stmt = $db->query("
            SELECT * FROM projects 
            WHERE status != 'draft'
            ORDER BY created_at DESC
        ");
    } else {
        // Regular users only see active projects: open, running, applying, completed
        $stmt = $db->query("
            SELECT * FROM projects 
            WHERE status IN ('open', 'running', 'applying', 'completed')
            ORDER BY created_at DESC
        ");
    }
} else {
    // For specific type filters (internal/external)
    if ($isAdmin) {
        // Admins see all non-draft projects of the specified type
        $stmt = $db->prepare("
            SELECT * FROM projects 
            WHERE status != 'draft' AND type = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$typeFilter]);
    } else {
        // Regular users only see active projects of the specified type
        $stmt = $db->prepare("
            SELECT * FROM projects 
            WHERE status IN ('open', 'running', 'applying', 'completed') AND type = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$typeFilter]);
    }
}

$projects = $stmt->fetchAll();

// Filter sensitive data for each project based on user role
$filteredProjects = array_map(function($project) use ($userRole, $user) {
    return Project::filterSensitiveData($project, $userRole, $user['id']);
}, $projects);

// Apply search filter
if ($searchQuery !== '') {
    $searchLower = mb_strtolower($searchQuery);
    $filteredProjects = array_filter($filteredProjects, function($project) use ($searchLower) {
        return str_contains(mb_strtolower($project['title'] ?? ''), $searchLower)
            || str_contains(mb_strtolower($project['description'] ?? ''), $searchLower)
            || str_contains(mb_strtolower($project['client_name'] ?? ''), $searchLower);
    });
}

$title = 'Projekte - IBC Intranet';
ob_start();
?>

<div class="max-w-7xl mx-auto">
    <!-- Error/Success Messages -->
    <?php if (isset($_SESSION['error'])): ?>
    <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
        <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($_SESSION['error']); ?>
    </div>
    <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['success'])): ?>
    <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
        <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($_SESSION['success']); ?>
    </div>
    <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <!-- Header -->
    <div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl md:text-4xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-briefcase mr-3 text-purple-600 dark:text-purple-400"></i>
                Projekte
            </h1>
            <p class="text-gray-600 dark:text-gray-300">Entdecke aktuelle Projekte und bewirb Dich</p>
        </div>
        
        <!-- Neues Projekt Button - Board/Head/Manager only -->
        <?php if (Auth::hasPermission('manage_projects') || Auth::isBoard() || Auth::hasRole(['ressortleiter', 'alumni_vorstand'])): ?>
        <a href="manage.php?new=1" class="btn-primary w-full sm:w-auto">
            <i class="fas fa-plus mr-2"></i>Neues Projekt
        </a>
        <?php endif; ?>
    </div>

    <!-- Filter & Search Bar -->
    <div class="mb-6 flex flex-col sm:flex-row gap-3 items-start sm:items-center">
        <div class="flex gap-4 flex-wrap">
            <a href="index.php?type=all<?php echo !empty($_GET['q']) ? '&q='.urlencode($_GET['q']) : ''; ?>"
               class="projects-filter-tab <?php echo $typeFilter === 'all' ? 'projects-filter-tab--active text-white' : ''; ?>">
                <i class="fas fa-list mr-2"></i>Alle
            </a>
            <a href="index.php?type=internal<?php echo !empty($_GET['q']) ? '&q='.urlencode($_GET['q']) : ''; ?>"
               class="projects-filter-tab <?php echo $typeFilter === 'internal' ? 'projects-filter-tab--active text-white' : ''; ?>">
                <i class="fas fa-building mr-2"></i>Intern
            </a>
            <a href="index.php?type=external<?php echo !empty($_GET['q']) ? '&q='.urlencode($_GET['q']) : ''; ?>"
               class="projects-filter-tab <?php echo $typeFilter === 'external' ? 'projects-filter-tab--active text-white' : ''; ?>">
                <i class="fas fa-users mr-2"></i>Extern
            </a>
        </div>
        <form method="get" action="index.php" class="flex-1 min-w-[200px]">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($typeFilter); ?>">
            <div class="relative">
                <span class="absolute inset-y-0 left-3 flex items-center pointer-events-none">
                    <i class="fas fa-search text-gray-400 text-sm"></i>
                </span>
                <input type="text" name="q" value="<?php echo htmlspecialchars($_GET['q'] ?? ''); ?>"
                       placeholder="Projekte suchen…"
                       class="w-full pl-9 pr-4 py-2.5 rounded-full border border-gray-200 dark:border-gray-600 bg-white dark:bg-gray-800 text-sm focus:outline-none focus:ring-2 focus:ring-purple-400 focus:border-transparent transition shadow-sm">
            </div>
        </form>
    </div>

    <!-- Projects Grid -->
    <?php if (empty($filteredProjects)): ?>
        <div class="card p-12 text-center rounded-2xl border border-dashed border-gray-300 dark:border-gray-600">
            <img src="<?php echo htmlspecialchars(BASE_URL); ?>/assets/img/cropped_maskottchen_270x270.webp"
                 alt="Keine Projekte"
                 class="w-32 h-32 mx-auto mb-5 opacity-60">
            <?php if ($searchQuery !== ''): ?>
                <p class="text-base sm:text-xl font-semibold text-gray-600 dark:text-gray-300 mb-2">Keine Projekte gefunden</p>
                <p class="text-sm text-gray-400 dark:text-gray-500">Versuche einen anderen Suchbegriff oder wähle einen anderen Filter.</p>
            <?php else: ?>
                <p class="text-base sm:text-xl font-semibold text-gray-600 dark:text-gray-300 mb-2">Aktuell gibt es keine aktiven Projekte.</p>
                <p class="text-sm text-gray-400 dark:text-gray-500">Schau später wieder vorbei!</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($filteredProjects as $project): ?>
                <?php
                    $isArchived = $project['status'] === 'archived';
                    $canApply = ($project['status'] === 'open' || $project['status'] === 'applying') && $userRole !== 'alumni';
                    $projectType = $project['type'] ?? 'internal';

                    // Validate image path
                    $hasImage = false;
                    if (!empty($project['image_path'])) {
                        $fullImagePath = __DIR__ . '/../../' . $project['image_path'];
                        $realPath = realpath($fullImagePath);
                        $baseDir = realpath(__DIR__ . '/../../');
                        $hasImage = $realPath && $baseDir && str_starts_with($realPath, $baseDir . DIRECTORY_SEPARATOR) && file_exists($realPath);
                    }
                ?>
                
                <a href="view.php?id=<?php echo $project['id']; ?>" class="project-card card flex flex-col overflow-hidden group no-underline project-card--<?php echo htmlspecialchars($project['status']); ?> <?php echo $isArchived ? 'project-card--archived' : ''; ?>" style="text-decoration:none;">
                    <!-- Status accent strip -->
                    <div class="project-card-accent"></div>

                    <!-- Image / Placeholder -->
                    <div class="project-card-image relative overflow-hidden flex-shrink-0">
                        <?php if ($hasImage): ?>
                            <img src="<?php echo htmlspecialchars(BASE_URL . '/' . $project['image_path']); ?>"
                                 alt="<?php echo htmlspecialchars($project['title']); ?>"
                                 loading="lazy"
                                 class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105">
                        <?php else: ?>
                            <div class="w-full h-full flex flex-col items-center justify-center project-card-placeholder">
                                <i class="fas fa-briefcase text-white/30 text-5xl mb-2"></i>
                                <span class="text-white/50 text-xs font-semibold tracking-widest uppercase">Projekt</span>
                            </div>
                        <?php endif; ?>

                        <!-- Overlay badges -->
                        <div class="absolute top-3 left-3 flex flex-col gap-1">
                            <?php
                            $statusClass = '';
                            $statusIcon  = '';
                            $statusLabel = '';
                            switch ($project['status']) {
                                case 'open':      $statusClass = 'bg-blue-500/90';    $statusIcon = 'fa-door-open';       $statusLabel = 'Offen';           break;
                                case 'applying':  $statusClass = 'bg-yellow-500/90';  $statusIcon = 'fa-hourglass-half';  $statusLabel = 'Bewerbungsphase'; break;
                                case 'assigned':  $statusClass = 'bg-green-500/90';   $statusIcon = 'fa-user-check';      $statusLabel = 'Vergeben';        break;
                                case 'running':   $statusClass = 'bg-purple-600/90';  $statusIcon = 'fa-play';            $statusLabel = 'Laufend';         break;
                                case 'completed': $statusClass = 'bg-teal-500/90';    $statusIcon = 'fa-flag-checkered';  $statusLabel = 'Abgeschlossen';   break;
                                case 'archived':  $statusClass = 'bg-gray-600/80';    $statusIcon = 'fa-archive';         $statusLabel = 'Archiviert';      break;
                                default:          $statusClass = 'bg-gray-500/80';    $statusIcon = 'fa-circle';          $statusLabel = ucfirst($project['status']); break;
                            }
                            ?>
                            <span class="px-2.5 py-1 <?php echo $statusClass; ?> backdrop-blur-sm text-white text-xs font-semibold rounded-full">
                                <i class="fas <?php echo $statusIcon; ?> mr-1"></i><?php echo $statusLabel; ?>
                            </span>
                            <span class="px-2.5 py-1 backdrop-blur-sm text-white text-xs font-semibold rounded-full <?php echo $projectType === 'internal' ? 'bg-indigo-500/90' : 'bg-green-500/90'; ?>">
                                <i class="fas <?php echo $projectType === 'internal' ? 'fa-building' : 'fa-users'; ?> mr-1"></i><?php echo $projectType === 'internal' ? 'Intern' : 'Extern'; ?>
                            </span>
                        </div>

                        <?php if (!empty($project['priority'])): ?>
                        <div class="absolute top-3 right-3">
                            <?php
                            $priClass = '';
                            $priIcon  = '';
                            $priLabel = '';
                            switch ($project['priority']) {
                                case 'low':    $priClass = 'bg-blue-400/90';   $priIcon = 'fa-arrow-down'; $priLabel = 'Niedrig'; break;
                                case 'medium': $priClass = 'bg-yellow-400/90'; $priIcon = 'fa-minus';      $priLabel = 'Mittel';  break;
                                case 'high':   $priClass = 'bg-red-500/90';    $priIcon = 'fa-arrow-up';   $priLabel = 'Hoch';    break;
                            }
                            ?>
                            <?php if ($priClass): ?>
                            <span class="px-2.5 py-1 <?php echo $priClass; ?> backdrop-blur-sm text-white text-xs font-semibold rounded-full">
                                <i class="fas <?php echo $priIcon; ?> mr-1"></i><?php echo $priLabel; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Card Body -->
                    <div class="flex flex-col flex-1 p-5">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-3 leading-snug line-clamp-2">
                            <?php echo htmlspecialchars($project['title']); ?>
                        </h3>

                        <!-- Meta Info -->
                        <div class="space-y-2 text-sm text-gray-600 dark:text-gray-400 mb-4">
                            <?php if (!empty($project['client_name'])): ?>
                                <div class="flex items-center gap-2">
                                    <span class="project-meta-icon"><i class="fas fa-user-tie text-purple-500"></i></span>
                                    <span class="truncate"><?php echo htmlspecialchars($project['client_name']); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($project['start_date'])): ?>
                                <div class="flex items-center gap-2">
                                    <span class="project-meta-icon"><i class="fas fa-calendar-alt text-ibc-blue"></i></span>
                                    <span>Start: <?php echo date('d.m.Y', strtotime($project['start_date'])); ?></span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($project['end_date'])): ?>
                                <div class="flex items-center gap-2">
                                    <span class="project-meta-icon"><i class="fas fa-calendar-check text-ibc-green"></i></span>
                                    <span>Ende: <?php echo date('d.m.Y', strtotime($project['end_date'])); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Description Preview -->
                        <?php if (!empty($project['description'])): ?>
                            <p class="text-gray-500 dark:text-gray-400 text-sm line-clamp-2 flex-1 mb-4">
                                <?php echo htmlspecialchars(substr($project['description'], 0, 120)); ?><?php echo strlen($project['description']) > 120 ? '…' : ''; ?>
                            </p>
                        <?php else: ?>
                            <div class="flex-1"></div>
                        <?php endif; ?>

                        <!-- CTA -->
                        <div class="flex items-center justify-between pt-3 border-t border-gray-100 dark:border-gray-700">
                            <?php if ($canApply): ?>
                                <span class="text-sm font-semibold text-ibc-green group-hover:text-ibc-green-dark transition-colors">
                                    Jetzt bewerben
                                </span>
                                <span class="w-8 h-8 rounded-full bg-ibc-green/10 flex items-center justify-center group-hover:bg-ibc-green transition-all">
                                    <i class="fas fa-paper-plane text-xs text-ibc-green group-hover:text-white transition-colors"></i>
                                </span>
                            <?php else: ?>
                                <span class="text-sm font-semibold project-cta-link group-hover:text-purple-700 transition-colors">
                                    Details ansehen
                                </span>
                                <span class="w-8 h-8 rounded-full bg-purple-100 flex items-center justify-center group-hover:bg-purple-600 transition-all">
                                    <i class="fas fa-arrow-right text-xs project-cta-link group-hover:text-white transition-colors"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
    /* ── Filter Tabs ────────────────────────────────── */
    .projects-filter-tab {
        display: inline-flex;
        align-items: center;
        padding: 0.6rem 1.5rem;
        min-height: 44px;
        border-radius: 9999px;
        font-weight: 600;
        font-size: 0.9rem;
        transition: all 0.25s ease;
        background: var(--bg-card);
        color: var(--text-muted);
        border: 1.5px solid var(--border-color);
        text-decoration: none !important;
    }
    .projects-filter-tab:hover {
        border-color: #8b5cf6;
        color: #8b5cf6 !important;
        box-shadow: 0 2px 8px rgba(139,92,246,0.12);
    }
    .projects-filter-tab--active {
        background: linear-gradient(135deg, #7c3aed 0%, #4f46e5 100%) !important;
        color: #ffffff !important;
        border-color: transparent !important;
        box-shadow: 0 4px 14px rgba(124,58,237,0.35);
    }

    /* ── Project Card ───────────────────────────────── */
    .project-card {
        transition: transform 0.25s ease, box-shadow 0.25s ease;
        color: inherit;
        border: 1.5px solid var(--border-color) !important;
    }
    .project-card:hover {
        transform: translateY(-5px);
        box-shadow: var(--shadow-card-hover);
        border-color: #8b5cf6 !important;
    }
    .project-card--archived {
        opacity: 0.65;
        filter: grayscale(60%);
    }

    /* Status accent strip */
    .project-card-accent {
        height: 4px;
        flex-shrink: 0;
        background: #8b5cf6;
    }
    .project-card--open      .project-card-accent { background: var(--ibc-blue); }
    .project-card--applying  .project-card-accent { background: #f59e0b; }
    .project-card--assigned  .project-card-accent { background: var(--ibc-green); }
    .project-card--running   .project-card-accent { background: #7c3aed; }
    .project-card--completed .project-card-accent { background: #0d9488; }
    .project-card--archived  .project-card-accent { background: var(--ibc-gray-400); }

    /* ── Card Image ─────────────────────────────────── */
    .project-card-image {
        height: 200px;
        background: #e5e7eb;
        flex-shrink: 0;
    }
    .project-card-image::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(to bottom, rgba(0,0,0,0.08) 0%, rgba(0,0,0,0.45) 100%);
        pointer-events: none;
    }

    /* Placeholder gradient per status */
    .project-card-placeholder {
        background: linear-gradient(135deg, #7c3aed 0%, #4f46e5 60%, #1e1b4b 100%);
    }
    .project-card--open      .project-card-placeholder { background: linear-gradient(135deg, var(--ibc-blue) 0%, var(--ibc-blue-dark) 60%, #001f3a 100%); }
    .project-card--applying  .project-card-placeholder { background: linear-gradient(135deg, #d97706 0%, #b45309 60%, #78350f 100%); }
    .project-card--assigned  .project-card-placeholder { background: linear-gradient(135deg, var(--ibc-green) 0%, var(--ibc-green-dark) 60%, #004a24 100%); }
    .project-card--completed .project-card-placeholder { background: linear-gradient(135deg, #0d9488 0%, #0f766e 60%, #134e4a 100%); }
    .project-card--archived  .project-card-placeholder { background: linear-gradient(135deg, #374151 0%, #1f2937 100%); }

    /* ── Meta Icon ──────────────────────────────────── */
    .project-meta-icon {
        width: 1.25rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    /* ── Text Clamp ─────────────────────────────────── */
    .line-clamp-2 {
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    .project-cta-link {
        color: #7c3aed;
    }

    /* ── Reduced Motion ─────────────────────────────── */
    @media (prefers-reduced-motion: reduce) {
        .project-card { transition: none; }
        .project-card:hover { transform: none; }
        .project-card-image img { transition: none; }
    }
</style>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
