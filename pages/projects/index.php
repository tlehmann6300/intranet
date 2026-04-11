<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Project.php';
require_once __DIR__ . '/../../src/Database.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user       = Auth::user();
$userRole   = $_SESSION['user_role'] ?? 'mitglied';
$typeFilter = $_GET['type'] ?? 'all';
$validTypes = ['all','internal','external'];
if (!in_array($typeFilter, $validTypes)) $typeFilter = 'all';

$searchQuery = trim($_GET['q'] ?? '');
$db          = Database::getContentDB();
$isAdmin     = Auth::isBoard() || Auth::hasPermission('manage_projects');

if ($typeFilter === 'all') {
    if ($isAdmin) {
        $stmt = $db->query("SELECT * FROM projects WHERE status != 'draft' ORDER BY created_at DESC");
    } else {
        $stmt = $db->query("SELECT * FROM projects WHERE status IN ('open','running','applying','completed') ORDER BY created_at DESC");
    }
} else {
    if ($isAdmin) {
        $stmt = $db->prepare("SELECT * FROM projects WHERE status != 'draft' AND type = ? ORDER BY created_at DESC");
        $stmt->execute([$typeFilter]);
    } else {
        $stmt = $db->prepare("SELECT * FROM projects WHERE status IN ('open','running','applying','completed') AND type = ? ORDER BY created_at DESC");
        $stmt->execute([$typeFilter]);
    }
}
$projects = $stmt->fetchAll();

$filteredProjects = array_map(fn($p) => Project::filterSensitiveData($p, $userRole, $user['id']), $projects);

if ($searchQuery !== '') {
    $sl = mb_strtolower($searchQuery);
    $filteredProjects = array_filter($filteredProjects, fn($p) =>
        str_contains(mb_strtolower($p['title'] ?? ''), $sl)
        || str_contains(mb_strtolower($p['description'] ?? ''), $sl)
        || str_contains(mb_strtolower($p['client_name'] ?? ''), $sl)
    );
}

// Status style map
$statusStyles = [
    'open'      => ['c'=>'#3b82f6','b'=>'rgba(59,130,246,0.1)', 'icon'=>'fa-door-open',      'label'=>'Offen',           'badge_bg'=>'rgba(59,130,246,0.88)'],
    'applying'  => ['c'=>'#f59e0b','b'=>'rgba(245,158,11,0.1)', 'icon'=>'fa-hourglass-half', 'label'=>'Bewerbungsphase', 'badge_bg'=>'rgba(245,158,11,0.88)'],
    'assigned'  => ['c'=>'#22c55e','b'=>'rgba(34,197,94,0.1)',  'icon'=>'fa-user-check',     'label'=>'Vergeben',        'badge_bg'=>'rgba(34,197,94,0.88)'],
    'running'   => ['c'=>'#8b5cf6','b'=>'rgba(139,92,246,0.1)', 'icon'=>'fa-play',           'label'=>'Laufend',         'badge_bg'=>'rgba(139,92,246,0.88)'],
    'completed' => ['c'=>'#0d9488','b'=>'rgba(13,148,136,0.1)', 'icon'=>'fa-flag-checkered', 'label'=>'Abgeschlossen',   'badge_bg'=>'rgba(13,148,136,0.88)'],
    'archived'  => ['c'=>'#6b7280','b'=>'rgba(107,114,128,0.1)','icon'=>'fa-archive',        'label'=>'Archiviert',      'badge_bg'=>'rgba(75,85,99,0.82)'],
];
$priorityStyles = [
    'low'    => ['c'=>'#3b82f6','icon'=>'fa-arrow-down', 'label'=>'Niedrig', 'badge_bg'=>'rgba(59,130,246,0.88)'],
    'medium' => ['c'=>'#f59e0b','icon'=>'fa-minus',      'label'=>'Mittel',  'badge_bg'=>'rgba(245,158,11,0.88)'],
    'high'   => ['c'=>'#ef4444','icon'=>'fa-arrow-up',   'label'=>'Hoch',    'badge_bg'=>'rgba(239,68,68,0.88)'],
];

$title = 'Projekte - IBC Intranet';
ob_start();
?>
<style>
/* ── Projekte Page ────────────────────────────────────────────── */
.proj-filter-bar {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    -ms-overflow-style: none;
    padding: 0.125rem 0 0.375rem;
}
.proj-filter-bar::-webkit-scrollbar { display: none; }
.proj-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.45rem 1rem;
    border-radius: 9999px;
    font-size: 0.8125rem;
    font-weight: 600;
    white-space: nowrap;
    text-decoration: none;
    cursor: pointer;
    transition: opacity 0.18s, transform 0.18s;
    border: 1.5px solid var(--border-color);
    background: var(--bg-card);
    color: var(--text-muted);
    flex-shrink: 0;
    min-height: 2.375rem;
    -webkit-tap-highlight-color: transparent;
}
.proj-chip:hover { opacity: 0.85; transform: translateY(-1px); }
.proj-chip--active {
    background: linear-gradient(135deg,#7c3aed,#4f46e5);
    color: #fff;
    border-color: transparent;
    box-shadow: 0 2px 12px rgba(124,58,237,0.3);
}

/* ── Search Input ────────────────────────────────────────────── */
.proj-search-wrap {
    position: relative;
    flex: 1;
    min-width: 0;
}
.proj-search-icon {
    position: absolute;
    left: 0.875rem;
    top: 50%;
    transform: translateY(-50%);
    pointer-events: none;
    color: var(--text-muted);
    font-size: 0.8rem;
}
.proj-search-input {
    width: 100%;
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 9999px;
    padding: 0.5rem 1rem 0.5rem 2.375rem;
    font-size: 0.875rem;
    color: var(--text-main);
    outline: none;
    transition: border-color 0.18s, box-shadow 0.18s;
    -webkit-appearance: none;
    min-height: 2.375rem;
}
.proj-search-input::placeholder { color: var(--text-muted); opacity: 0.7; }
.proj-search-input:focus {
    border-color: #8b5cf6;
    box-shadow: 0 0 0 3px rgba(139,92,246,0.12);
}

/* ── Project Card ────────────────────────────────────────────── */
.proj-card {
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 1rem;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    text-decoration: none;
    color: inherit;
    position: relative;
    transition: transform 0.28s cubic-bezier(0.34,1.2,0.64,1),
                box-shadow 0.28s ease,
                border-color 0.22s ease;
}
.proj-card:hover {
    transform: translateY(-5px) scale(1.01);
    box-shadow: 0 16px 36px rgba(124,58,237,0.14), 0 6px 14px rgba(0,0,0,0.09);
    border-color: #8b5cf6;
}
.proj-card--archived { opacity: 0.62; filter: grayscale(55%); }

/* Status accent bar (top) */
.proj-accent {
    height: 4px;
    width: 100%;
    flex-shrink: 0;
    background: #8b5cf6;
}
.proj-card--open      .proj-accent { background: #3b82f6; }
.proj-card--applying  .proj-accent { background: #f59e0b; }
.proj-card--assigned  .proj-accent { background: #22c55e; }
.proj-card--running   .proj-accent { background: #8b5cf6; }
.proj-card--completed .proj-accent { background: #0d9488; }
.proj-card--archived  .proj-accent { background: #9ca3af; }

/* Image area */
.proj-img-wrap {
    position: relative;
    overflow: hidden;
    height: 12rem;
    flex-shrink: 0;
    background: #e5e7eb;
}
.proj-img-wrap img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.5s ease;
}
.proj-card:hover .proj-img-wrap img { transform: scale(1.06); }
.proj-img-wrap::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(to bottom, rgba(0,0,0,0.08) 0%, rgba(0,0,0,0.46) 100%);
    pointer-events: none;
}
.proj-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #7c3aed, #4f46e5 60%, #1e1b4b);
}
.proj-card--open      .proj-placeholder { background: linear-gradient(135deg,#2563eb,#1d4ed8 60%,#1e3a8a); }
.proj-card--applying  .proj-placeholder { background: linear-gradient(135deg,#d97706,#b45309 60%,#78350f); }
.proj-card--assigned  .proj-placeholder { background: linear-gradient(135deg,#16a34a,#15803d 60%,#14532d); }
.proj-card--completed .proj-placeholder { background: linear-gradient(135deg,#0d9488,#0f766e 60%,#134e4a); }
.proj-card--archived  .proj-placeholder { background: linear-gradient(135deg,#374151,#1f2937); }

/* Overlay badge */
.proj-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.22rem 0.6rem;
    border-radius: 9999px;
    font-size: 0.6875rem;
    font-weight: 700;
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    white-space: nowrap;
    color: #fff;
}

/* Meta rows */
.proj-meta-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8125rem;
    color: var(--text-muted);
}
.proj-meta-icon {
    width: 1.125rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.proj-arrow-circle {
    width: 2rem;
    height: 2rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(124,58,237,0.1);
    flex-shrink: 0;
    transition: background 0.22s, transform 0.22s cubic-bezier(0.34,1.56,0.64,1);
}
.proj-card:hover .proj-arrow-circle { background: #7c3aed; transform: scale(1.15); }
.proj-arrow-icon { font-size: 0.7rem; color: #7c3aed; transition: color 0.22s; }
.proj-card:hover .proj-arrow-icon { color: #fff; }

/* Apply CTA */
.proj-apply-circle {
    width: 2rem;
    height: 2rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(0,166,81,0.1);
    flex-shrink: 0;
    transition: background 0.22s, transform 0.22s cubic-bezier(0.34,1.56,0.64,1);
}
.proj-card:hover .proj-apply-circle { background: var(--ibc-green); transform: scale(1.15); }
.proj-apply-icon { font-size: 0.65rem; color: var(--ibc-green); transition: color 0.22s; }
.proj-card:hover .proj-apply-icon { color: #fff; }

/* Flash messages */
.proj-flash {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.875rem 1.125rem;
    border-radius: 0.75rem;
    font-size: 0.875rem;
    font-weight: 500;
    margin-bottom: 1.25rem;
}
.proj-flash--success { background: rgba(0,166,81,0.08); border: 1.5px solid rgba(0,166,81,0.2); color: var(--ibc-green); }
.proj-flash--error   { background: rgba(239,68,68,0.08); border: 1.5px solid rgba(239,68,68,0.2); color: #ef4444; }

/* Stagger animations */
@keyframes projCardIn {
    from { opacity: 0; transform: translateY(16px); }
    to   { opacity: 1; transform: none; }
}
.proj-card { animation: projCardIn 0.32s ease both; }
.proj-card:nth-child(2) { animation-delay: 0.06s; }
.proj-card:nth-child(3) { animation-delay: 0.12s; }
.proj-card:nth-child(4) { animation-delay: 0.18s; }
.proj-card:nth-child(5) { animation-delay: 0.22s; }
.proj-card:nth-child(6) { animation-delay: 0.26s; }
.proj-card:nth-child(n+7) { animation-delay: 0.28s; }

.proj-empty {
    background: var(--bg-card);
    border: 2px dashed var(--border-color);
    border-radius: 1rem;
    padding: 4rem 2rem;
    text-align: center;
}

@media (prefers-reduced-motion: reduce) {
    .proj-card, .proj-card:nth-child(n) { animation: none; }
    .proj-card:hover { transform: none; }
}
</style>

<!-- ── Flash Messages ─────────────────────────────────────────── -->
<?php if (isset($_SESSION['error'])): ?>
<div class="proj-flash proj-flash--error">
    <i class="fas fa-exclamation-circle" style="flex-shrink:0;" aria-hidden="true"></i>
    <span><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></span>
</div>
<?php endif; ?>
<?php if (isset($_SESSION['success'])): ?>
<div class="proj-flash proj-flash--success">
    <i class="fas fa-check-circle" style="flex-shrink:0;" aria-hidden="true"></i>
    <span><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></span>
</div>
<?php endif; ?>

<!-- ── Page Header ────────────────────────────────────────────── -->
<div style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.75rem;">
    <div style="display:flex;align-items:center;gap:1rem;flex:1;min-width:0;">
        <div style="width:3rem;height:3rem;border-radius:0.875rem;background:linear-gradient(135deg,#7c3aed,#4f46e5);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(124,58,237,0.3);flex-shrink:0;">
            <i class="fas fa-folder-open" style="color:#fff;font-size:1.2rem;" aria-hidden="true"></i>
        </div>
        <div>
            <h1 style="font-size:1.625rem;font-weight:800;color:var(--text-main);letter-spacing:-0.02em;line-height:1.2;margin:0;">Projekte</h1>
            <p style="font-size:0.875rem;color:var(--text-muted);margin:0.125rem 0 0;">Entdecke aktuelle Projekte und bewirb Dich</p>
        </div>
    </div>
    <?php if (Auth::hasPermission('manage_projects') || Auth::isBoard() || Auth::hasRole(['ressortleiter','alumni_vorstand'])): ?>
    <a href="manage.php?new=1"
       style="display:inline-flex;align-items:center;gap:0.45rem;padding:0.6rem 1.1rem;background:linear-gradient(135deg,#7c3aed,#4f46e5);color:#fff;border-radius:0.75rem;font-size:0.875rem;font-weight:700;text-decoration:none;white-space:nowrap;box-shadow:0 3px 12px rgba(124,58,237,0.3);transition:opacity 0.18s,transform 0.18s;flex-shrink:0;"
       onmouseover="this.style.opacity='0.9';this.style.transform='translateY(-1px)'"
       onmouseout="this.style.opacity='1';this.style.transform='none'">
        <i class="fas fa-plus" aria-hidden="true"></i>
        Neues Projekt
    </a>
    <?php endif; ?>
</div>

<!-- ── Filter + Search ────────────────────────────────────────── -->
<div style="background:var(--bg-card);border:1.5px solid var(--border-color);border-radius:0.875rem;padding:0.875rem 1rem;margin-bottom:1.75rem;">
    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:0.75rem;">
        <div class="proj-filter-bar" role="navigation" aria-label="Projekttyp filtern" style="flex-shrink:0;">
            <?php
            $chips = [
                'all'      => ['icon'=>'fa-th-large',  'label'=>'Alle'],
                'internal' => ['icon'=>'fa-building',  'label'=>'Intern'],
                'external' => ['icon'=>'fa-users',     'label'=>'Extern'],
            ];
            foreach ($chips as $val => $cfg):
            ?>
            <a href="index.php?type=<?php echo $val; ?><?php echo $searchQuery ? '&q='.urlencode($searchQuery) : ''; ?>"
               class="proj-chip <?php echo $typeFilter === $val ? 'proj-chip--active' : ''; ?>">
                <i class="fas <?php echo $cfg['icon']; ?>" style="font-size:0.7rem;" aria-hidden="true"></i>
                <?php echo $cfg['label']; ?>
            </a>
            <?php endforeach; ?>
        </div>
        <form method="get" action="index.php" style="flex:1;min-width:10rem;">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($typeFilter); ?>">
            <div class="proj-search-wrap">
                <i class="fas fa-search proj-search-icon" aria-hidden="true"></i>
                <input type="text"
                       name="q"
                       value="<?php echo htmlspecialchars($searchQuery); ?>"
                       placeholder="Projekte suchen…"
                       class="proj-search-input"
                       aria-label="Projekte durchsuchen">
            </div>
        </form>
    </div>
</div>

<?php if (empty($filteredProjects)): ?>
<!-- ── Empty State ────────────────────────────────────────────── -->
<div class="proj-empty">
    <div style="width:4.5rem;height:4.5rem;margin:0 auto 1.25rem;border-radius:50%;background:rgba(124,58,237,0.07);border:1.5px solid rgba(124,58,237,0.14);display:flex;align-items:center;justify-content:center;">
        <i class="fas fa-folder-open" style="font-size:1.75rem;color:var(--text-muted);" aria-hidden="true"></i>
    </div>
    <p style="font-weight:700;color:var(--text-main);font-size:1.0625rem;margin:0 0 0.375rem;">
        <?php echo $searchQuery ? 'Keine Projekte gefunden' : 'Aktuell gibt es keine aktiven Projekte.'; ?>
    </p>
    <p style="font-size:0.875rem;color:var(--text-muted);margin:0;">
        <?php echo $searchQuery ? 'Versuche einen anderen Suchbegriff oder wähle einen anderen Filter.' : 'Schau später wieder vorbei!'; ?>
    </p>
</div>

<?php else: ?>
<!-- ── Projects Grid ──────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,20rem),1fr));gap:1.125rem;">
    <?php foreach ($filteredProjects as $project):
        $status     = $project['status'] ?? 'open';
        $ss         = $statusStyles[$status] ?? $statusStyles['open'];
        $isArchived = $status === 'archived';
        $canApply   = in_array($status, ['open','applying']) && $userRole !== 'alumni';
        $pType      = $project['type'] ?? 'internal';

        $hasImage = false;
        if (!empty($project['image_path'])) {
            $fp = realpath(__DIR__ . '/../../' . $project['image_path']);
            $bd = realpath(__DIR__ . '/../../');
            $hasImage = $fp && $bd && str_starts_with($fp, $bd) && file_exists($fp);
        }
    ?>
    <a href="view.php?id=<?php echo (int)$project['id']; ?>"
       class="proj-card proj-card--<?php echo htmlspecialchars($status); ?> <?php echo $isArchived ? 'proj-card--archived' : ''; ?>">

        <!-- Accent bar -->
        <div class="proj-accent"></div>

        <!-- Image / Placeholder -->
        <div class="proj-img-wrap">
            <?php if ($hasImage): ?>
                <img src="<?php echo htmlspecialchars(BASE_URL . '/' . $project['image_path']); ?>"
                     alt="<?php echo htmlspecialchars($project['title']); ?>"
                     loading="lazy">
            <?php else: ?>
                <div class="proj-placeholder">
                    <i class="fas fa-folder-open" style="font-size:2.75rem;color:rgba(255,255,255,0.22);margin-bottom:0.5rem;" aria-hidden="true"></i>
                    <span style="font-size:0.6875rem;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:rgba(255,255,255,0.42);">Projekt</span>
                </div>
            <?php endif; ?>

            <!-- Top-left badges -->
            <div style="position:absolute;top:0.75rem;left:0.75rem;display:flex;flex-direction:column;gap:0.3rem;z-index:2;">
                <span class="proj-badge" style="background:<?php echo $ss['badge_bg']; ?>;">
                    <i class="fas <?php echo $ss['icon']; ?>" style="font-size:0.55rem;" aria-hidden="true"></i>
                    <?php echo $ss['label']; ?>
                </span>
                <span class="proj-badge" style="background:<?php echo $pType === 'internal' ? 'rgba(99,102,241,0.88)' : 'rgba(34,197,94,0.88)'; ?>;">
                    <i class="fas <?php echo $pType === 'internal' ? 'fa-building' : 'fa-users'; ?>" style="font-size:0.55rem;" aria-hidden="true"></i>
                    <?php echo $pType === 'internal' ? 'Intern' : 'Extern'; ?>
                </span>
            </div>

            <!-- Top-right priority -->
            <?php if (!empty($project['priority']) && isset($priorityStyles[$project['priority']])): ?>
            <?php $ps = $priorityStyles[$project['priority']]; ?>
            <div style="position:absolute;top:0.75rem;right:0.75rem;z-index:2;">
                <span class="proj-badge" style="background:<?php echo $ps['badge_bg']; ?>;">
                    <i class="fas <?php echo $ps['icon']; ?>" style="font-size:0.55rem;" aria-hidden="true"></i>
                    <?php echo $ps['label']; ?>
                </span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Card Body -->
        <div style="display:flex;flex-direction:column;flex:1;padding:1.125rem 1.125rem 1rem;">
            <h3 style="font-size:1rem;font-weight:700;color:var(--text-main);line-height:1.35;margin:0 0 0.75rem;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">
                <?php echo htmlspecialchars($project['title']); ?>
            </h3>

            <!-- Meta rows -->
            <div style="display:flex;flex-direction:column;gap:0.4rem;margin-bottom:0.875rem;">
                <?php if (!empty($project['client_name'])): ?>
                <div class="proj-meta-row">
                    <span class="proj-meta-icon"><i class="fas fa-user-tie" style="color:#8b5cf6;font-size:0.7rem;" aria-hidden="true"></i></span>
                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo htmlspecialchars($project['client_name']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($project['start_date'])): ?>
                <div class="proj-meta-row">
                    <span class="proj-meta-icon"><i class="fas fa-calendar-alt" style="color:var(--ibc-blue);font-size:0.7rem;" aria-hidden="true"></i></span>
                    <span>Start: <?php echo date('d.m.Y', strtotime($project['start_date'])); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($project['end_date'])): ?>
                <div class="proj-meta-row">
                    <span class="proj-meta-icon"><i class="fas fa-calendar-check" style="color:var(--ibc-green);font-size:0.7rem;" aria-hidden="true"></i></span>
                    <span>Ende: <?php echo date('d.m.Y', strtotime($project['end_date'])); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Description excerpt -->
            <?php if (!empty($project['description'])): ?>
            <p style="font-size:0.8125rem;color:var(--text-muted);line-height:1.55;margin:0 0 0.875rem;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;flex:1;">
                <?php echo htmlspecialchars(mb_substr($project['description'], 0, 130)) . (mb_strlen($project['description']) > 130 ? '…' : ''); ?>
            </p>
            <?php else: ?>
            <div style="flex:1;"></div>
            <?php endif; ?>

            <!-- Footer CTA -->
            <div style="display:flex;align-items:center;justify-content:space-between;padding-top:0.75rem;border-top:1px solid var(--border-color);margin-top:auto;">
                <?php if ($canApply): ?>
                <span style="font-size:0.8125rem;font-weight:700;color:var(--ibc-green);">Jetzt bewerben</span>
                <div class="proj-apply-circle">
                    <i class="fas fa-paper-plane proj-apply-icon" aria-hidden="true"></i>
                </div>
                <?php else: ?>
                <span style="font-size:0.8125rem;font-weight:700;color:#7c3aed;">Details ansehen</span>
                <div class="proj-arrow-circle">
                    <i class="fas fa-arrow-right proj-arrow-icon" aria-hidden="true"></i>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
