<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/models/Inventory.php';
require_once __DIR__ . '/../../includes/models/Project.php';

if (!Auth::canViewAdminStats()) die('Zugriff verweigert');
$user = Auth::user();
if (!$user) die('Zugriff verweigert');

$userDb    = Database::getUserDB();
$contentDb = Database::getContentDB();

// Active Users (7 days)
$stmt = $userDb->query("SELECT COUNT(*) as c FROM users WHERE last_login IS NOT NULL AND last_login > DATE_SUB(NOW(), INTERVAL 7 DAY) AND deleted_at IS NULL");
$activeUsersCount = $stmt->fetch()['c'] ?? 0;
$stmt = $userDb->query("SELECT COUNT(*) as c FROM users WHERE last_login IS NOT NULL AND DATE(last_login) BETWEEN DATE(DATE_SUB(NOW(), INTERVAL 14 DAY)) AND DATE(DATE_SUB(NOW(), INTERVAL 7 DAY)) AND deleted_at IS NULL");
$activeUsersPrev  = $stmt->fetch()['c'] ?? 0;
$activeUsersTrend = $activeUsersPrev > 0 ? (($activeUsersCount - $activeUsersPrev) / $activeUsersPrev) * 100 : 0;

// Total Users
$stmt = $userDb->query("SELECT COUNT(*) as c FROM users WHERE deleted_at IS NULL");
$totalUsersCount = $stmt->fetch()['c'] ?? 0;
$stmt = $userDb->query("SELECT COUNT(*) as c FROM users WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) AND deleted_at IS NULL");
$newUsersCount   = $stmt->fetch()['c'] ?? 0;
$totalUsersTrend = $newUsersCount;

// Recent activity
$recentActivity = [];
try {
    $stmt = $userDb->query("SELECT id, email, firstname, lastname, last_login, created_at FROM users WHERE last_login IS NOT NULL AND deleted_at IS NULL ORDER BY last_login DESC LIMIT 10");
    $recentActivity = $stmt->fetchAll();
} catch (PDOException $e) { error_log("stats: " . $e->getMessage()); }

// Inventory stats
$stats           = Inventory::getDashboardStats();
$inStockStats    = Inventory::getInStockStats();
$checkedOutStats = Inventory::getCheckedOutStats();
$writeOffStats   = Inventory::getWriteOffStatsThisMonth();

// Active checkouts
$activeCheckouts = [];
try {
    $stmt = $contentDb->query("SELECT ic.id, ic.item_id, ic.user_id, ic.checked_out_at, ic.due_date, i.name as item_name, i.quantity as total_quantity FROM inventory_checkouts ic JOIN inventory_items i ON ic.item_id = i.id WHERE ic.returned_at IS NULL ORDER BY ic.checked_out_at DESC");
    $checkouts = $stmt->fetchAll();
    $userIds   = array_unique(array_column($checkouts, 'user_id'));
    $userInfoMap = [];
    if (!empty($userIds)) {
        $ph = str_repeat('?,', count($userIds) - 1) . '?';
        $s  = $userDb->prepare("SELECT id, email, firstname, lastname FROM users WHERE id IN ($ph) AND deleted_at IS NULL");
        $s->execute($userIds);
        foreach ($s->fetchAll() as $u2) $userInfoMap[$u2['id']] = $u2;
    }
    foreach ($checkouts as $co) {
        $ui = $userInfoMap[$co['user_id']] ?? null;
        $uName  = 'Unbekannt';
        $uEmail = '';
        if ($ui) {
            $uEmail = $ui['email'] ?? '';
            if (!empty($ui['firstname']) && !empty($ui['lastname'])) $uName = $ui['firstname'] . ' ' . $ui['lastname'];
            elseif (!empty($ui['firstname'])) $uName = $ui['firstname'];
            elseif (!empty($uEmail)) $uName = explode('@', $uEmail)[0];
        }
        $activeCheckouts[] = ['checkout_id'=>$co['id'],'item_name'=>$co['item_name'],'user_name'=>$uName,'user_email'=>$uEmail,'checked_out_at'=>$co['checked_out_at'],'due_date'=>$co['due_date'],'is_overdue'=>!empty($co['due_date']) && strtotime($co['due_date']) < time()];
    }
} catch (PDOException $e) { error_log("stats checkouts: " . $e->getMessage()); }

// Project applications
$stmt = $contentDb->query("SELECT p.id, p.title, p.type, p.status, COUNT(pa.id) as application_count FROM projects p LEFT JOIN project_applications pa ON p.id = pa.project_id WHERE p.status != 'draft' GROUP BY p.id, p.title, p.type, p.status ORDER BY p.created_at DESC");
$projectApplications = $stmt->fetchAll();

// Database storage
$databaseStats = [];
$databaseQuota = 2048;
try {
    $databases = [
        ['name'=>DB_USER_NAME,    'label'=>'User Database',    'color'=>'rgba(37,99,235,1)',   'bg'=>'rgba(37,99,235,.12)'],
        ['name'=>DB_CONTENT_NAME, 'label'=>'Content Database', 'color'=>'rgba(124,58,237,1)',  'bg'=>'rgba(124,58,237,.12)'],
        ['name'=>DB_RECH_NAME,    'label'=>'Invoice Database', 'color'=>'rgba(22,163,74,1)',   'bg'=>'rgba(22,163,74,.12)'],
    ];
    $validDatabases = array_filter($databases, fn($d) => !empty($d['name']));
    if (!empty($validDatabases)) {
        $dbNames = array_column($validDatabases, 'name');
        $ph = str_repeat('?,', count($dbNames) - 1) . '?';
        $stmt = $userDb->prepare("SELECT table_schema as db, ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb FROM information_schema.TABLES WHERE table_schema IN ($ph) GROUP BY table_schema");
        $stmt->execute($dbNames);
        $sizeMap = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $sizeMap[$r['db']] = $r['size_mb'];
        foreach ($validDatabases as $db) {
            $sizeMb = $sizeMap[$db['name']] ?? 0;
            $pct    = min(($sizeMb / $databaseQuota) * 100, 100);
            $databaseStats[] = array_merge($db, ['size_mb'=>$sizeMb, 'percentage'=>$pct]);
        }
    }
} catch (PDOException $e) { error_log("stats db: " . $e->getMessage()); }

// CSV helper sanitizeCsvValue() lives in includes/helpers.php (auto-loaded
// via the layout). The previous local copy here caused a fatal redeclare,
// so we simply reuse the canonical implementation.

$title = 'Statistiken - IBC Intranet';
ob_start();
?>

<style>
/* ── Statistiken ──────────────────────────────────────── */
@keyframes stSlideUp {
    from { opacity:0; transform:translateY(18px) scale(.98); }
    to   { opacity:1; transform:translateY(0)    scale(1);   }
}
.st-page { animation: stSlideUp .4s cubic-bezier(.22,.68,0,1.2) both; }

/* Page header */
.st-page-header {
    display:flex; flex-wrap:wrap; align-items:flex-start;
    justify-content:space-between; gap:1rem; margin-bottom:1.75rem;
}
.st-page-header-left { display:flex; align-items:center; gap:.875rem; min-width:0; }
.st-header-icon {
    width:3rem; height:3rem; border-radius:.875rem; flex-shrink:0;
    background: linear-gradient(135deg, rgba(124,58,237,1), rgba(99,102,241,1));
    box-shadow: 0 4px 14px rgba(124,58,237,.35);
    display:flex; align-items:center; justify-content:center;
}
.st-page-title { font-size:1.6rem; font-weight:800; color:var(--text-main); margin:0; line-height:1.2; }
.st-page-sub   { font-size:.85rem; color:var(--text-muted); margin:.2rem 0 0; }

/* Export button */
.st-export-btn {
    display:inline-flex; align-items:center; gap:.5rem;
    padding:.65rem 1.25rem; min-height:44px;
    border-radius:.875rem; font-size:.875rem; font-weight:700;
    background: linear-gradient(135deg, rgba(22,163,74,1), rgba(37,99,235,1));
    color:#fff; border:none; cursor:pointer; flex-shrink:0;
    box-shadow: 0 3px 12px rgba(37,99,235,.3);
    transition: opacity .2s, transform .15s, box-shadow .2s;
}
.st-export-btn:hover { opacity:.9; transform:translateY(-1px); box-shadow: 0 5px 18px rgba(37,99,235,.45); }
.st-export-btn:active { opacity:1; transform:none; }

/* Metric cards */
.st-metric-grid { display:grid; grid-template-columns:repeat(2,1fr); gap:1rem; margin-bottom:1.5rem; }

.st-metric {
    border-radius:1rem; padding:1.25rem 1.5rem;
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    border-left-width:4px;
    box-shadow: 0 2px 10px rgba(0,0,0,.06);
    animation: stSlideUp .4s cubic-bezier(.22,.68,0,1.2) both;
    transition: box-shadow .2s, transform .2s;
}
.st-metric:hover { box-shadow:0 6px 22px rgba(0,0,0,.09); transform:translateY(-1px); }
.st-metric:nth-child(1) { animation-delay:.05s; }
.st-metric:nth-child(2) { animation-delay:.10s; }

.st-metric-top { display:flex; align-items:flex-start; justify-content:space-between; gap:.75rem; }
.st-metric-icon { width:2.75rem; height:2.75rem; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1.1rem; flex-shrink:0; }
.st-metric-foot { display:flex; align-items:center; gap:.35rem; margin-top:.75rem; padding-top:.75rem; border-top:1px solid var(--border-color); font-size:.78rem; }

/* Generic section card */
.st-card {
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius:1rem;
    overflow:hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,.06);
    margin-bottom:1.5rem;
    animation: stSlideUp .4s .05s cubic-bezier(.22,.68,0,1.2) both;
    transition: box-shadow .25s;
}
.st-card:hover { box-shadow:0 5px 20px rgba(0,0,0,.08); }
.st-card-head {
    padding:1rem 1.5rem;
    border-bottom:1px solid var(--border-color);
    display:flex; align-items:center; gap:.625rem;
    background:rgba(156,163,175,.05);
}
.st-card-body { padding:1.25rem 1.5rem; }

/* Quick action links */
.st-quick-link {
    display:flex; align-items:center; gap:.875rem;
    padding:.875rem 1rem; min-height:44px; border-radius:.75rem;
    text-decoration:none; font-weight:600; font-size:.875rem; color:var(--text-main);
    transition: background .2s, transform .15s;
    margin-bottom:.625rem;
}
.st-quick-link:last-child { margin-bottom:0; }
.st-quick-link:hover { transform:translateX(3px); }
.st-quick-link-icon { width:2.25rem; height:2.25rem; border-radius:.5rem; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:.95rem; }

/* Status row */
.st-status-row { display:flex; align-items:center; justify-content:space-between; padding:.875rem 1rem; border-radius:.75rem; margin-bottom:.625rem; }
.st-status-row:last-child { margin-bottom:0; }

/* Section heading */
.st-section-heading {
    font-size:1.1rem; font-weight:700; color:var(--text-main);
    margin:0 0 .875rem; display:flex; align-items:center; gap:.5rem;
}

/* DB storage cards */
.st-db-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; margin-bottom:1.5rem; }

.st-db-card {
    border-radius:1rem; padding:1.25rem;
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    border-left-width:4px;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
    animation: stSlideUp .4s cubic-bezier(.22,.68,0,1.2) both;
    transition: box-shadow .2s, transform .2s;
}
.st-db-card:hover { box-shadow:0 6px 22px rgba(0,0,0,.09); transform:translateY(-1px); }
.st-db-card:nth-child(1) { animation-delay:.05s; }
.st-db-card:nth-child(2) { animation-delay:.10s; }
.st-db-card:nth-child(3) { animation-delay:.15s; }

/* Progress bar */
.st-progress-track { width:100%; height:.5rem; border-radius:9999px; background:rgba(156,163,175,.2); overflow:hidden; }
.st-progress-bar   { height:100%; border-radius:9999px; transition:width .6s cubic-bezier(.22,.68,0,1.2); }

/* Two col layout */
.st-two-col { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.5rem; }

/* Sub stat rows in Lager/Unterwegs */
.st-sub-stat { display:flex; align-items:center; justify-content:space-between; padding:.875rem 1rem; border-radius:.75rem; margin-bottom:.625rem; }
.st-sub-stat:last-child { margin-bottom:0; }

/* Table */
.st-table-wrap { overflow-x:auto; width:100%; }
.st-table { width:100%; border-collapse:collapse; }
.st-table thead tr { background:rgba(156,163,175,.07); border-bottom:1px solid var(--border-color); }
.st-table th { padding:.65rem 1rem; text-align:left; font-size:.7rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.06em; white-space:nowrap; }
.st-table td { padding:.8rem 1rem; font-size:.85rem; color:var(--text-main); border-bottom:1px solid var(--border-color); vertical-align:middle; }
.st-table tbody tr:last-child td { border-bottom:none; }
.st-table tbody tr { transition:background .15s; }
.st-table tbody tr:hover { background:rgba(124,58,237,.04); }

/* Avatar */
.st-avatar { width:2.25rem; height:2.25rem; border-radius:50%; background:rgba(124,58,237,.12); display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; color:rgba(124,58,237,1); font-size:.9rem; }

/* Badge */
.st-badge { display:inline-flex; align-items:center; gap:.25rem; padding:.15rem .6rem; border-radius:9999px; font-size:.72rem; font-weight:600; border:1px solid transparent; }

/* Empty state */
.st-empty { padding:3rem 1rem; text-align:center; }
.st-empty-icon { width:3rem; height:3rem; border-radius:50%; background:rgba(156,163,175,.12); display:inline-flex; align-items:center; justify-content:center; margin-bottom:.875rem; font-size:1.25rem; color:rgba(156,163,175,1); }

/* ── Responsive ─────────────────────────────────────── */
@media (max-width:900px) {
    .st-db-grid { grid-template-columns:repeat(2,1fr); }
}
@media (max-width:640px) {
    .st-two-col { grid-template-columns:1fr; }
    .st-table, .st-table thead, .st-table tbody, .st-table th, .st-table td, .st-table tr { display:block; }
    .st-table thead { display:none; }
    .st-table td { border-bottom:none; padding:.5rem .875rem; display:flex; align-items:baseline; gap:.75rem; }
    .st-table td::before { content:attr(data-label); font-size:.72rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; white-space:nowrap; flex-shrink:0; }
    .st-table tbody tr { border-bottom:1px solid var(--border-color); padding:.25rem 0; }
    .st-card-head { padding:.875rem 1.25rem; }
    .st-card-body { padding:1rem 1.25rem; }
}
@media (max-width:480px) {
    .st-metric-grid { grid-template-columns:1fr; }
    .st-db-grid { grid-template-columns:1fr; }
    .st-page-title { font-size:1.35rem; }
    .st-export-btn { width:100%; justify-content:center; }
    .st-page-header { flex-direction:column; }
}
</style>

<div class="st-page" style="max-width:72rem;margin:0 auto;">

<!-- Header -->
<div class="st-page-header">
    <div class="st-page-header-left">
        <div class="st-header-icon"><i class="fas fa-chart-bar" style="color:#fff;font-size:1.15rem;"></i></div>
        <div>
            <h1 class="st-page-title">Statistiken</h1>
            <p class="st-page-sub">Übersicht über wichtige Kennzahlen und Aktivitäten</p>
        </div>
    </div>
    <button id="exportStats" class="st-export-btn">
        <i class="fas fa-download"></i>Export Report
    </button>
</div>

<!-- Metrics -->
<div class="st-metric-grid">
    <!-- Active Users -->
    <div class="st-metric" style="border-left-color:rgba(37,99,235,1);">
        <div class="st-metric-top">
            <div>
                <p style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin:0 0 .3rem;">Aktive Nutzer</p>
                <p style="font-size:2rem;font-weight:800;color:rgba(37,99,235,1);margin:0;line-height:1;"><?php echo number_format($activeUsersCount); ?></p>
                <p style="font-size:.75rem;color:var(--text-muted);margin:.25rem 0 0;">Letzte 7 Tage</p>
            </div>
            <div class="st-metric-icon" style="background:rgba(37,99,235,.1);color:rgba(37,99,235,1);">
                <i class="fas fa-users"></i>
            </div>
        </div>
        <?php if ($activeUsersTrend != 0): ?>
        <div class="st-metric-foot">
            <?php if ($activeUsersTrend > 0): ?>
            <i class="fas fa-arrow-up" style="color:rgba(22,163,74,1);"></i>
            <span style="color:rgba(22,163,74,1);font-weight:700;"><?php echo number_format(abs($activeUsersTrend),1); ?>%</span>
            <span style="color:var(--text-muted);">vs. vorherige Woche</span>
            <?php else: ?>
            <i class="fas fa-arrow-down" style="color:rgba(239,68,68,1);"></i>
            <span style="color:rgba(239,68,68,1);font-weight:700;"><?php echo number_format(abs($activeUsersTrend),1); ?>%</span>
            <span style="color:var(--text-muted);">vs. vorherige Woche</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Total Users -->
    <div class="st-metric" style="border-left-color:rgba(124,58,237,1);">
        <div class="st-metric-top">
            <div>
                <p style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted);margin:0 0 .3rem;">Gesamtanzahl User</p>
                <p style="font-size:2rem;font-weight:800;color:rgba(124,58,237,1);margin:0;line-height:1;"><?php echo number_format($totalUsersCount); ?></p>
                <p style="font-size:.75rem;color:var(--text-muted);margin:.25rem 0 0;">Registriert</p>
            </div>
            <div class="st-metric-icon" style="background:rgba(124,58,237,.1);color:rgba(124,58,237,1);">
                <i class="fas fa-user-friends"></i>
            </div>
        </div>
        <div class="st-metric-foot">
            <?php if ($totalUsersTrend > 0): ?>
            <i class="fas fa-user-plus" style="color:rgba(22,163,74,1);"></i>
            <span style="color:rgba(22,163,74,1);font-weight:700;">+<?php echo number_format($totalUsersTrend); ?></span>
            <span style="color:var(--text-muted);">neue in 7 Tagen</span>
            <?php else: ?>
            <i class="fas fa-minus" style="color:var(--text-muted);"></i>
            <span style="color:var(--text-muted);">Keine neuen User</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Quick Actions + Status -->
<div class="st-two-col">
    <!-- Quick Actions -->
    <div class="st-card">
        <div class="st-card-head">
            <i class="fas fa-bolt" style="color:rgba(234,179,8,1);"></i>
            <span style="font-size:.95rem;font-weight:700;color:var(--text-main);">Schnellaktionen</span>
        </div>
        <div class="st-card-body">
            <a href="../inventory/index.php" class="st-quick-link" style="background:rgba(124,58,237,.07);">
                <div class="st-quick-link-icon" style="background:rgba(124,58,237,.15);color:rgba(124,58,237,1);"><i class="fas fa-boxes"></i></div>
                Inventar durchsuchen
            </a>
            <a href="../inventory/add.php" class="st-quick-link" style="background:rgba(22,163,74,.07);">
                <div class="st-quick-link-icon" style="background:rgba(22,163,74,.15);color:rgba(22,163,74,1);"><i class="fas fa-plus-circle"></i></div>
                Neuen Artikel hinzufügen
            </a>
            <?php if (Auth::canManageUsers()): ?>
            <a href="../admin/users.php" class="st-quick-link" style="background:rgba(37,99,235,.07);">
                <div class="st-quick-link-icon" style="background:rgba(37,99,235,.15);color:rgba(37,99,235,1);"><i class="fas fa-users-cog"></i></div>
                Benutzerverwaltung
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Status Overview -->
    <div class="st-card">
        <div class="st-card-head">
            <i class="fas fa-info-circle" style="color:rgba(37,99,235,1);"></i>
            <span style="font-size:.95rem;font-weight:700;color:var(--text-main);">Status-Übersicht</span>
        </div>
        <div class="st-card-body">
            <div class="st-status-row" style="background:rgba(234,179,8,.08);">
                <div style="display:flex;align-items:center;gap:.75rem;">
                    <i class="fas fa-exclamation-triangle" style="color:rgba(234,179,8,1);font-size:1.1rem;"></i>
                    <span style="font-weight:600;color:var(--text-main);">Niedriger Bestand</span>
                </div>
                <span style="font-size:1.5rem;font-weight:800;color:rgba(161,98,7,1);"><?php echo number_format($stats['low_stock']); ?></span>
            </div>
            <div class="st-status-row" style="background:rgba(22,163,74,.08);">
                <div style="display:flex;align-items:center;gap:.75rem;">
                    <i class="fas fa-warehouse" style="color:rgba(22,163,74,1);font-size:1.1rem;"></i>
                    <span style="font-weight:600;color:var(--text-main);">Im Lager</span>
                </div>
                <span style="font-size:1.5rem;font-weight:800;color:rgba(21,128,61,1);"><?php echo number_format($inStockStats['total_in_stock']); ?></span>
            </div>
            <?php if ($user): ?>
            <div style="padding:.875rem 1rem;border-radius:.75rem;background:rgba(124,58,237,.07);margin-top:.625rem;">
                <p style="font-size:.82rem;color:var(--text-muted);margin:0;">
                    <i class="fas fa-user-circle" style="margin-right:.4rem;color:rgba(124,58,237,1);"></i>
                    Angemeldet als <strong style="color:var(--text-main);"><?php echo htmlspecialchars($user['email']); ?></strong>
                </p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Recent User Activity -->
<?php if (!empty($recentActivity)): ?>
<div class="st-card">
    <div class="st-card-head">
        <i class="fas fa-history" style="color:rgba(249,115,22,1);"></i>
        <span style="font-size:.95rem;font-weight:700;color:var(--text-main);">Letzte Benutzeraktivitäten</span>
    </div>
    <div class="st-table-wrap">
    <table class="st-table">
        <thead><tr>
            <th>Benutzer</th>
            <th>E-Mail</th>
            <th>Letzter Login</th>
            <th>Mitglied seit</th>
        </tr></thead>
        <tbody>
        <?php foreach ($recentActivity as $act):
            $loginTs = strtotime($act['last_login']);
            $diff    = time() - $loginTs;
            if ($diff < 3600)      { $ago = floor($diff/60).' Min';    $agoColor = 'rgba(22,163,74,1)'; }
            elseif ($diff < 86400) { $ago = floor($diff/3600).' Std';  $agoColor = 'rgba(37,99,235,1)'; }
            else                   { $ago = floor($diff/86400).' Tage'; $agoColor = 'var(--text-muted)'; }
            $dispName = '';
            if (!empty($act['firstname']) && !empty($act['lastname'])) $dispName = $act['firstname'].' '.$act['lastname'];
            elseif (!empty($act['firstname'])) $dispName = $act['firstname'];
        ?>
        <tr>
            <td data-label="Benutzer">
                <div style="display:flex;align-items:center;gap:.625rem;">
                    <div class="st-avatar"><i class="fas fa-user"></i></div>
                    <div>
                        <div style="font-weight:600;font-size:.85rem;"><?php echo $dispName ? htmlspecialchars($dispName) : 'Unbekannt'; ?></div>
                        <div style="font-size:.72rem;color:var(--text-muted);">ID: <?php echo $act['id']; ?></div>
                    </div>
                </div>
            </td>
            <td data-label="E-Mail" style="font-size:.82rem;color:var(--text-muted);"><?php echo htmlspecialchars($act['email']); ?></td>
            <td data-label="Letzter Login">
                <div style="font-size:.82rem;font-weight:600;color:<?php echo $agoColor; ?>;display:flex;align-items:center;gap:.3rem;">
                    <i class="fas fa-clock" style="font-size:.7rem;"></i>vor <?php echo $ago; ?>
                </div>
                <div style="font-size:.72rem;color:var(--text-muted);"><?php echo date('d.m.Y H:i', $loginTs); ?></div>
            </td>
            <td data-label="Mitglied seit" style="font-size:.82rem;color:var(--text-muted);"><?php echo date('d.m.Y', strtotime($act['created_at'])); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- Database Storage -->
<?php if (!empty($databaseStats)): ?>
<div style="margin-bottom:1.5rem;">
    <h2 class="st-section-heading">
        <i class="fas fa-database" style="color:rgba(99,102,241,1);"></i>Datenbank Speicherverbrauch
    </h2>
    <div class="st-db-grid">
        <?php foreach ($databaseStats as $db): ?>
        <div class="st-db-card" style="border-left-color:<?php echo $db['color']; ?>;">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;margin-bottom:.875rem;">
                <div>
                    <p style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin:0 0 .3rem;"><?php echo htmlspecialchars($db['label']); ?></p>
                    <p style="font-size:1.4rem;font-weight:800;color:<?php echo $db['color']; ?>;margin:0;"><?php echo number_format($db['size_mb'],2); ?> MB</p>
                </div>
                <div style="width:2.5rem;height:2.5rem;border-radius:50%;background:<?php echo $db['bg']; ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="fas fa-hdd" style="color:<?php echo $db['color']; ?>;"></i>
                </div>
            </div>
            <p style="font-size:.72rem;color:var(--text-muted);margin:0 0 .5rem;">
                <i class="fas fa-tag" style="margin-right:.3rem;"></i><?php echo htmlspecialchars($db['name']); ?>
            </p>
            <div style="display:flex;justify-content:space-between;font-size:.72rem;color:var(--text-muted);margin-bottom:.3rem;">
                <span>Auslastung</span>
                <span><?php echo number_format($db['percentage'],1); ?>% von 2 GB</span>
            </div>
            <div class="st-progress-track">
                <div class="st-progress-bar" style="width:<?php echo $db['percentage']; ?>%;background:<?php echo $db['color']; ?>;"></div>
            </div>
            <?php if ($db['percentage'] >= 90): ?>
            <p style="font-size:.72rem;color:rgba(185,28,28,1);margin:.4rem 0 0;"><i class="fas fa-exclamation-triangle" style="margin-right:.3rem;"></i>Warnung: Hohe Auslastung</p>
            <?php elseif ($db['percentage'] >= 75): ?>
            <p style="font-size:.72rem;color:rgba(161,98,7,1);margin:.4rem 0 0;"><i class="fas fa-info-circle" style="margin-right:.3rem;"></i>Auslastung über 75%</p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Im Lager + Unterwegs -->
<div class="st-two-col">
    <!-- Im Lager -->
    <div class="st-card">
        <div class="st-card-head">
            <i class="fas fa-warehouse" style="color:rgba(22,163,74,1);"></i>
            <span style="font-size:.95rem;font-weight:700;color:var(--text-main);">Im Lager</span>
        </div>
        <div class="st-card-body">
            <div class="st-sub-stat" style="background:rgba(22,163,74,.08);">
                <div>
                    <p style="font-size:.78rem;color:var(--text-muted);margin:0 0 .2rem;">Gesamtbestand</p>
                    <p style="font-size:1.5rem;font-weight:800;color:rgba(21,128,61,1);margin:0;"><?php echo number_format($inStockStats['total_in_stock']); ?> <span style="font-size:.8rem;font-weight:600;">Einh.</span></p>
                </div>
                <div style="width:2.25rem;height:2.25rem;border-radius:.5rem;background:rgba(22,163,74,.15);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-box-open" style="color:rgba(22,163,74,1);"></i>
                </div>
            </div>
            <div class="st-sub-stat" style="background:rgba(37,99,235,.08);">
                <div>
                    <p style="font-size:.78rem;color:var(--text-muted);margin:0 0 .2rem;">Verschiedene Artikel</p>
                    <p style="font-size:1.5rem;font-weight:800;color:rgba(37,99,235,1);margin:0;"><?php echo number_format($inStockStats['unique_items_in_stock']); ?> <span style="font-size:.8rem;font-weight:600;">Artikel</span></p>
                </div>
                <div style="width:2.25rem;height:2.25rem;border-radius:.5rem;background:rgba(37,99,235,.15);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-boxes" style="color:rgba(37,99,235,1);"></i>
                </div>
            </div>
            <div class="st-sub-stat" style="background:rgba(124,58,237,.08);">
                <div>
                    <p style="font-size:.78rem;color:var(--text-muted);margin:0 0 .2rem;">Wert im Lager</p>
                    <p style="font-size:1.5rem;font-weight:800;color:rgba(124,58,237,1);margin:0;"><?php echo number_format((float)$inStockStats['total_value_in_stock'],2); ?> €</p>
                </div>
                <div style="width:2.25rem;height:2.25rem;border-radius:.5rem;background:rgba(124,58,237,.15);display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-euro-sign" style="color:rgba(124,58,237,1);"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Unterwegs -->
    <div class="st-card">
        <div class="st-card-head">
            <i class="fas fa-truck" style="color:rgba(249,115,22,1);"></i>
            <span style="font-size:.95rem;font-weight:700;color:var(--text-main);">Unterwegs</span>
        </div>
        <div class="st-card-body" style="padding:0;">
            <?php if ($checkedOutStats['total_items_out'] > 0): ?>
            <div style="padding:.875rem 1.25rem;background:rgba(249,115,22,.07);border-bottom:1px solid var(--border-color);display:flex;gap:1.5rem;flex-wrap:wrap;">
                <div>
                    <p style="font-size:.72rem;color:var(--text-muted);margin:0 0 .15rem;">Aktive Ausleihen</p>
                    <p style="font-size:1.3rem;font-weight:800;color:rgba(194,65,12,1);margin:0;"><?php echo count($checkedOutStats['checkouts']); ?></p>
                </div>
                <div>
                    <p style="font-size:.72rem;color:var(--text-muted);margin:0 0 .15rem;">Entliehene Einheiten</p>
                    <p style="font-size:1.3rem;font-weight:800;color:rgba(194,65,12,1);margin:0;"><?php echo $checkedOutStats['total_items_out']; ?></p>
                </div>
            </div>
            <div style="max-height:14rem;overflow-y:auto;">
            <table class="st-table">
                <thead><tr><th>Artikel</th><th>Menge</th><th>Rückgabe</th></tr></thead>
                <tbody>
                <?php foreach ($checkedOutStats['checkouts'] as $co): ?>
                <tr>
                    <td data-label="Artikel">
                        <a href="../inventory/view.php?id=<?php echo $co['item_id']; ?>" style="color:rgba(124,58,237,1);font-weight:600;text-decoration:none;font-size:.8rem;">
                            <?php echo htmlspecialchars($co['item_name']); ?>
                        </a>
                    </td>
                    <td data-label="Menge" style="font-size:.8rem;"><?php echo $co['amount']; ?> <?php echo htmlspecialchars($co['unit']); ?></td>
                    <td data-label="Rückgabe" style="font-size:.8rem;"><?php echo date('d.m.Y', strtotime($co['expected_return'] ?? $co['rented_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
            <div class="st-empty">
                <div class="st-empty-icon" style="background:rgba(22,163,74,.12);color:rgba(22,163,74,1);"><i class="fas fa-check-circle"></i></div>
                <p style="font-size:.9rem;font-weight:600;color:var(--text-main);margin:0 0 .25rem;">Keine aktiven Ausleihen</p>
                <p style="font-size:.8rem;color:var(--text-muted);margin:0;">Alle Artikel sind im Lager</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Active Checkouts -->
<div class="st-card">
    <div class="st-card-head">
        <i class="fas fa-box-open" style="color:rgba(249,115,22,1);"></i>
        <div>
            <span style="font-size:.95rem;font-weight:700;color:var(--text-main);">Wer hat was ausgeliehen?</span>
            <p style="font-size:.78rem;color:var(--text-muted);margin:.1rem 0 0;">Aktive Ausleihen mit Benutzernamen</p>
        </div>
    </div>
    <?php if (empty($activeCheckouts)): ?>
    <div class="st-empty">
        <div class="st-empty-icon"><i class="fas fa-check-circle"></i></div>
        <p style="font-size:.9rem;color:var(--text-muted);margin:0;">Keine Daten verfügbar</p>
    </div>
    <?php else: ?>
    <div class="st-table-wrap">
    <table class="st-table">
        <thead><tr>
            <th>Artikel</th><th>Benutzer</th><th>Ausgeliehen am</th><th>Fällig am</th><th>Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($activeCheckouts as $co): ?>
        <tr>
            <td data-label="Artikel" style="font-weight:600;"><?php echo htmlspecialchars($co['item_name']); ?></td>
            <td data-label="Benutzer">
                <div style="font-weight:600;font-size:.85rem;"><?php echo htmlspecialchars($co['user_name']); ?></div>
                <div style="font-size:.72rem;color:var(--text-muted);"><?php echo htmlspecialchars($co['user_email']); ?></div>
            </td>
            <td data-label="Ausgeliehen am" style="font-size:.8rem;color:var(--text-muted);"><?php echo date('d.m.Y H:i', strtotime($co['checked_out_at'])); ?></td>
            <td data-label="Fällig am" style="font-size:.82rem;">
                <?php if (!empty($co['due_date'])): ?>
                <?php echo date('d.m.Y', strtotime($co['due_date'])); ?>
                <?php else: ?><span style="color:var(--text-muted);">Kein Datum</span><?php endif; ?>
            </td>
            <td data-label="Status">
                <?php if ($co['is_overdue']): ?>
                <span class="st-badge" style="background:rgba(239,68,68,.12);color:rgba(185,28,28,1);border-color:rgba(239,68,68,.3);">
                    <i class="fas fa-exclamation-triangle" style="font-size:.65rem;"></i>Überfällig
                </span>
                <?php else: ?>
                <span class="st-badge" style="background:rgba(34,197,94,.12);color:rgba(21,128,61,1);border-color:rgba(34,197,94,.3);">
                    <i class="fas fa-check-circle" style="font-size:.65rem;"></i>Aktiv
                </span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div style="padding:.75rem 1.25rem;border-top:1px solid var(--border-color);font-size:.82rem;color:var(--text-muted);">
        <strong style="color:var(--text-main);">Gesamt:</strong> <?php echo count($activeCheckouts); ?> aktive Ausleihen
    </div>
    <?php endif; ?>
</div>

<!-- Project Applications -->
<div class="st-card">
    <div class="st-card-head">
        <i class="fas fa-briefcase" style="color:rgba(124,58,237,1);"></i>
        <div>
            <span style="font-size:.95rem;font-weight:700;color:var(--text-main);">Projekt Bewerbungen</span>
            <p style="font-size:.78rem;color:var(--text-muted);margin:.1rem 0 0;">Anzahl Bewerbungen pro Projekt</p>
        </div>
    </div>
    <?php if (empty($projectApplications)): ?>
    <div class="st-empty">
        <div class="st-empty-icon"><i class="fas fa-briefcase"></i></div>
        <p style="font-size:.9rem;color:var(--text-muted);margin:0;">Keine Projekte vorhanden</p>
    </div>
    <?php else: ?>
    <?php
    $typeStyles = [
        'internal' => ['bg'=>'rgba(37,99,235,.12)',  'color'=>'rgba(37,99,235,1)',  'border'=>'rgba(37,99,235,.3)',  'label'=>'Intern'],
        'external' => ['bg'=>'rgba(22,163,74,.12)',  'color'=>'rgba(22,163,74,1)',  'border'=>'rgba(22,163,74,.3)',  'label'=>'Extern'],
    ];
    $statusStyles2 = [
        'open'      => ['bg'=>'rgba(234,179,8,.12)',  'color'=>'rgba(161,98,7,1)',   'border'=>'rgba(234,179,8,.3)',  'label'=>'Offen'],
        'assigned'  => ['bg'=>'rgba(37,99,235,.12)',  'color'=>'rgba(37,99,235,1)',  'border'=>'rgba(37,99,235,.3)',  'label'=>'Zugewiesen'],
        'running'   => ['bg'=>'rgba(34,197,94,.12)',  'color'=>'rgba(21,128,61,1)',  'border'=>'rgba(34,197,94,.3)',  'label'=>'Läuft'],
        'completed' => ['bg'=>'rgba(156,163,175,.1)', 'color'=>'var(--text-muted)', 'border'=>'rgba(156,163,175,.3)','label'=>'Abgeschlossen'],
        'archived'  => ['bg'=>'rgba(156,163,175,.1)', 'color'=>'var(--text-muted)', 'border'=>'rgba(156,163,175,.3)','label'=>'Archiviert'],
    ];
    ?>
    <div class="st-table-wrap">
    <table class="st-table">
        <thead><tr>
            <th>Projekttitel</th><th>Typ</th><th>Status</th><th>Bewerbungen</th>
        </tr></thead>
        <tbody>
        <?php foreach ($projectApplications as $proj):
            $ts = $typeStyles[$proj['type']] ?? ['bg'=>'rgba(156,163,175,.1)','color'=>'var(--text-muted)','border'=>'rgba(156,163,175,.3)','label'=>$proj['type']];
            $ss = $statusStyles2[$proj['status']] ?? ['bg'=>'rgba(156,163,175,.1)','color'=>'var(--text-muted)','border'=>'rgba(156,163,175,.3)','label'=>$proj['status']];
        ?>
        <tr>
            <td data-label="Projekttitel">
                <a href="../projects/view.php?id=<?php echo $proj['id']; ?>" style="color:rgba(124,58,237,1);font-weight:600;text-decoration:none;">
                    <?php echo htmlspecialchars($proj['title']); ?>
                </a>
            </td>
            <td data-label="Typ">
                <span class="st-badge" style="background:<?php echo $ts['bg']; ?>;color:<?php echo $ts['color']; ?>;border-color:<?php echo $ts['border']; ?>;">
                    <?php echo $ts['label']; ?>
                </span>
            </td>
            <td data-label="Status">
                <span class="st-badge" style="background:<?php echo $ss['bg']; ?>;color:<?php echo $ss['color']; ?>;border-color:<?php echo $ss['border']; ?>;">
                    <?php echo $ss['label']; ?>
                </span>
            </td>
            <td data-label="Bewerbungen">
                <div style="display:flex;align-items:center;gap:.5rem;">
                    <span style="font-size:1.1rem;font-weight:800;color:rgba(124,58,237,1);"><?php echo $proj['application_count']; ?></span>
                    <?php if ($proj['application_count'] > 0): ?>
                    <a href="../projects/applications.php?project_id=<?php echo $proj['id']; ?>" style="font-size:.75rem;color:rgba(124,58,237,1);text-decoration:none;font-weight:600;">Details →</a>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div style="padding:.75rem 1.25rem;border-top:1px solid var(--border-color);font-size:.82rem;color:var(--text-muted);">
        <strong style="color:var(--text-main);">Gesamt:</strong> <?php echo count($projectApplications); ?> Projekte &middot; <?php echo array_sum(array_column($projectApplications,'application_count')); ?> Bewerbungen
    </div>
    <?php endif; ?>
</div>

</div><!-- /st-page -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    var exportBtn = document.getElementById('exportStats');
    if (!exportBtn) return;

    exportBtn.addEventListener('click', function() {
        var activeUsers = <?php echo $activeUsersCount; ?>;
        var totalUsers  = <?php echo $totalUsersCount; ?>;

        var csv = 'Statistik Report - IBC Intranet\n';
        csv += 'Generiert am: ' + new Date().toLocaleString('de-DE') + '\n\n';
        csv += 'Metriken\nKategorie,Wert\n';
        csv += 'Aktive Nutzer (7 Tage),' + activeUsers + '\n';
        csv += 'Gesamtanzahl User,' + totalUsers + '\n\n';

        <?php if (!empty($databaseStats)): ?>
        csv += 'Datenbank Speicherverbrauch\nDatenbank,Größe (MB),Auslastung (%)\n';
        <?php foreach ($databaseStats as $db): ?>
        csv += '<?php echo sanitizeCsvValue($db['label']); ?>,<?php echo $db['size_mb']; ?>,<?php echo number_format($db['percentage'],2); ?>\n';
        <?php endforeach; ?>
        csv += '\n';
        <?php endif; ?>

        <?php if (!empty($activeCheckouts)): ?>
        csv += 'Aktive Ausleihen\nArtikel,Benutzer,Ausgeliehen am,Fällig am,Überfällig\n';
        <?php foreach ($activeCheckouts as $co): ?>
        csv += '"<?php echo str_replace('"','""',sanitizeCsvValue($co['item_name'])); ?>","<?php echo str_replace('"','""',sanitizeCsvValue($co['user_name'])); ?>","<?php echo $co['checked_out_at']; ?>","<?php echo $co['due_date'] ?? 'N/A'; ?>","<?php echo $co['is_overdue'] ? 'Ja' : 'Nein'; ?>"\n';
        <?php endforeach; ?>
        csv += '\n';
        <?php endif; ?>

        <?php if (!empty($projectApplications)): ?>
        csv += 'Projekt Bewerbungen\nProjekt,Typ,Status,Bewerbungen\n';
        <?php foreach ($projectApplications as $proj): ?>
        csv += '"<?php echo str_replace('"','""',sanitizeCsvValue($proj['title'])); ?>","<?php echo sanitizeCsvValue($proj['type']); ?>","<?php echo sanitizeCsvValue($proj['status']); ?>",<?php echo $proj['application_count']; ?>\n';
        <?php endforeach; ?>
        <?php endif; ?>

        var blob = new Blob([csv], { type:'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        var url  = URL.createObjectURL(blob);
        var dateStr = new Date().toLocaleDateString('de-DE').replace(/\./g,'-');
        link.setAttribute('href', url);
        link.setAttribute('download', 'statistik_report_' + dateStr + '.csv');
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    });
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
