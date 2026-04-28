<?php
/**
 * Database Maintenance Tool – redesigned
 * Admin page for database cleanup and maintenance (board members only)
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';

if (!Auth::check() || !Auth::isBoard()) {
    header('Location: ../auth/login.php');
    exit;
}

$actionResult = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection for all destructive actions
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    try {
        if (isset($_POST['clean_logs'])) {
            $userDb    = Database::getUserDB();
            $contentDb = Database::getContentDB();
            $stmt = $userDb->prepare("DELETE FROM user_sessions WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $stmt->execute(); $s1 = $stmt->rowCount();
            $stmt = $contentDb->prepare("DELETE FROM system_logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL 1 YEAR)");
            $stmt->execute(); $s2 = $stmt->rowCount();
            $stmt = $contentDb->prepare("DELETE FROM inventory_history WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)");
            $stmt->execute(); $s3 = $stmt->rowCount();
            $stmt = $contentDb->prepare("DELETE FROM event_history WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)");
            $stmt->execute(); $s4 = $stmt->rowCount();
            $actionResult = ['type'=>'success','title'=>'Logs bereinigt','details'=>["User Sessions gelöscht: $s1","System Logs gelöscht: $s2","Inventory History gelöscht: $s3","Event History gelöscht: $s4"]];
            $stmt = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$_SESSION['user_id'],'cleanup_logs','maintenance',null,json_encode($actionResult['details']),$_SERVER['REMOTE_ADDR']??null,$_SERVER['HTTP_USER_AGENT']??null]);
        } elseif (isset($_POST['clear_cache'])) {
            $cacheDir = __DIR__ . '/../../cache';
            $filesDeleted = 0; $spaceFreed = 0;
            if (is_dir($cacheDir)) {
                foreach (glob($cacheDir . '/*') as $file) {
                    if (is_file($file)) { $spaceFreed += filesize($file); if (unlink($file)) $filesDeleted++; }
                }
            }
            $actionResult = ['type'=>'success','title'=>'Cache geleert','details'=>["Dateien gelöscht: $filesDeleted","Speicherplatz freigegeben: ".formatBytes($spaceFreed)]];
            $contentDb = Database::getContentDB();
            $stmt = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$_SESSION['user_id'],'clear_cache','maintenance',null,json_encode($actionResult['details']),$_SERVER['REMOTE_ADDR']??null,$_SERVER['HTTP_USER_AGENT']??null]);
        }
    } catch (Exception $e) {
        $actionResult = ['type'=>'error','title'=>'Fehler','details'=>[$e->getMessage()]];
    }
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B','KB','MB','GB'];
    $bytes = max($bytes, 0);
    $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow   = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}

function getTableSizes() {
    try {
        $userDb = Database::getUserDB(); $contentDb = Database::getContentDB();
        $sql = "SELECT table_name as `table`, ROUND((data_length+index_length)/1024/1024,2) as size_mb, table_rows as `rows` FROM information_schema.TABLES WHERE table_schema = ? ORDER BY (data_length+index_length) DESC";
        $stmt = $userDb->prepare($sql); $stmt->execute([DB_USER_NAME]); $userTables = $stmt->fetchAll();
        $stmt = $contentDb->prepare($sql); $stmt->execute([DB_CONTENT_NAME]); $contentTables = $stmt->fetchAll();
        return ['user'=>$userTables,'content'=>$contentTables];
    } catch (Exception $e) { return ['user'=>[],'content'=>[],'error'=>$e->getMessage()]; }
}

function getSystemHealth() {
    $h = [];
    try {
        $userDb = Database::getUserDB(); $contentDb = Database::getContentDB();
        $h['database_status']  = 'healthy';
        $h['database_message'] = 'Beide Datenbanken sind erreichbar';
        $stmt = $contentDb->query("SELECT COUNT(*) as c FROM system_logs WHERE action LIKE '%error%' AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $h['error_count_24h'] = $stmt->fetch()['c'] ?? 0;
        $h['error_status']    = $h['error_count_24h'] > 10 ? 'warning' : 'healthy';
        $stmt = $userDb->prepare("SELECT ROUND(SUM(data_length+index_length)/1024/1024,2) as size FROM information_schema.TABLES WHERE table_schema IN (?,?)");
        $stmt->execute([DB_USER_NAME, DB_CONTENT_NAME]);
        $h['disk_usage_mb']   = $stmt->fetch()['size'] ?? 0;
        $stmt = $userDb->query("SELECT TIMESTAMPDIFF(HOUR, MIN(created_at), NOW()) as uptime FROM user_sessions WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $h['uptime_days']     = floor(($stmt->fetch()['uptime'] ?? 0) / 24);
        $stmt = $userDb->query("SELECT COUNT(*) as c FROM user_sessions WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $h['active_sessions'] = $stmt->fetch()['c'] ?? 0;
        $stmt = $contentDb->query("SELECT COUNT(*) as c FROM system_logs WHERE action IN ('login','login_success') AND timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $h['recent_logins']   = $stmt->fetch()['c'] ?? 0;
        $stmt = $contentDb->query("SELECT COUNT(*) as c FROM system_logs WHERE action='login_failed' AND timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $h['failed_logins']   = $stmt->fetch()['c'] ?? 0;
        $h['security_status'] = $h['failed_logins'] > 20 ? 'warning' : 'healthy';
        $h['overall_status']  = ($h['database_status']==='healthy' && $h['error_status']==='healthy' && $h['security_status']==='healthy') ? 'healthy' : 'warning';
    } catch (Exception $e) {
        $h['database_status']  = 'error';
        $h['database_message'] = 'Verbindung fehlgeschlagen: ' . $e->getMessage();
        $h['overall_status']   = 'error';
    }
    return $h;
}

$tableSizes    = getTableSizes();
$userDbTotal   = array_sum(array_column($tableSizes['user'],    'size_mb'));
$contentDbTotal= array_sum(array_column($tableSizes['content'], 'size_mb'));
$totalSize     = $userDbTotal + $contentDbTotal;
$systemHealth  = getSystemHealth();

// Health colors (RGBA — no dark: classes)
function healthColor(string $status): array {
    return match($status) {
        'healthy' => ['bg'=>'rgba(34,197,94,.12)',  'color'=>'rgba(21,128,61,1)',   'border'=>'rgba(34,197,94,.3)',   'icon'=>'check-circle'],
        'warning' => ['bg'=>'rgba(234,179,8,.12)',  'color'=>'rgba(161,98,7,1)',   'border'=>'rgba(234,179,8,.3)',   'icon'=>'exclamation-circle'],
        default   => ['bg'=>'rgba(239,68,68,.12)',  'color'=>'rgba(185,28,28,1)',  'border'=>'rgba(239,68,68,.3)',   'icon'=>'times-circle'],
    };
}

$title = 'System Health & Wartung - IBC Intranet';
ob_start();
?>

<style>
/* ── System Health & Wartung ──────────────────────────── */
@keyframes dbmSlideUp {
    from { opacity:0; transform:translateY(18px) scale(.98); }
    to   { opacity:1; transform:translateY(0)    scale(1);   }
}
.dbm-page { animation: dbmSlideUp .4s cubic-bezier(.22,.68,0,1.2) both; }

/* Page header */
.dbm-page-header {
    display:flex; flex-wrap:wrap; align-items:flex-start;
    justify-content:space-between; gap:1rem; margin-bottom:1.75rem;
}
.dbm-page-header-left { display:flex; align-items:center; gap:.875rem; min-width:0; }
.dbm-header-icon {
    width:3rem; height:3rem; border-radius:.875rem; flex-shrink:0;
    background: linear-gradient(135deg, rgba(37,99,235,1), rgba(99,102,241,1));
    box-shadow: 0 4px 14px rgba(37,99,235,.35);
    display:flex; align-items:center; justify-content:center;
}
.dbm-page-title { font-size:1.6rem; font-weight:800; color:var(--text-main); margin:0; line-height:1.2; }
.dbm-page-sub   { font-size:.85rem; color:var(--text-muted); margin:.2rem 0 0; }

/* Action result banner */
.dbm-result-banner {
    padding:.875rem 1.25rem; border-radius:.875rem; margin-bottom:1.25rem;
    display:flex; flex-direction:column; gap:.35rem;
    animation: dbmSlideUp .35s cubic-bezier(.22,.68,0,1.2) both;
}
.dbm-result-banner.ok  { background:rgba(34,197,94,.1); border:1px solid rgba(34,197,94,.3); color:rgba(21,128,61,1); }
.dbm-result-banner.err { background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.3); color:rgba(185,28,28,1); }
.dbm-result-title { display:flex; align-items:center; gap:.5rem; font-weight:700; font-size:.9rem; }
.dbm-result-detail { font-size:.82rem; padding-left:1.4rem; }

/* Section card */
.dbm-card {
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius:1rem;
    overflow:hidden;
    box-shadow: 0 2px 12px rgba(0,0,0,.06);
    margin-bottom:1.25rem;
    animation: dbmSlideUp .4s cubic-bezier(.22,.68,0,1.2) both;
    transition: box-shadow .25s;
}
.dbm-card:nth-of-type(1) { animation-delay:.05s; }
.dbm-card:nth-of-type(2) { animation-delay:.10s; }
.dbm-card:nth-of-type(3) { animation-delay:.15s; }
.dbm-card:hover { box-shadow:0 5px 22px rgba(0,0,0,.09); }
.dbm-card-head {
    padding:1rem 1.5rem;
    border-bottom:1px solid var(--border-color);
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.75rem;
    background:rgba(156,163,175,.04);
}
.dbm-card-title {
    font-size:1rem; font-weight:700; color:var(--text-main);
    margin:0; display:flex; align-items:center; gap:.5rem;
}
.dbm-card-body { padding:1.25rem 1.5rem; }

/* Health metric tiles */
.dbm-health-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:.875rem; }

.dbm-health-tile {
    padding:1rem; border-radius:.875rem;
    border-width:1px; border-style:solid;
    transition: transform .2s, box-shadow .2s;
    animation: dbmSlideUp .35s cubic-bezier(.22,.68,0,1.2) both;
}
.dbm-health-tile:nth-child(1) { animation-delay:.1s; }
.dbm-health-tile:nth-child(2) { animation-delay:.15s; }
.dbm-health-tile:nth-child(3) { animation-delay:.2s; }
.dbm-health-tile:nth-child(4) { animation-delay:.25s; }
.dbm-health-tile:hover { transform:translateY(-2px); box-shadow:0 4px 14px rgba(0,0,0,.08); }
.dbm-health-label { font-size:.72rem; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin:0 0 .3rem; }
.dbm-health-value { font-size:1.15rem; font-weight:800; margin:.25rem 0 0; }
.dbm-health-desc  { font-size:.72rem; color:var(--text-muted); margin:.25rem 0 0; }

/* Additional metrics */
.dbm-mini-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:.75rem; margin-top:1rem; }
.dbm-mini {
    padding:.75rem 1rem; border-radius:.75rem;
    background:rgba(156,163,175,.08); border:1px solid var(--border-color);
    transition: background .2s;
}
.dbm-mini:hover { background:rgba(156,163,175,.14); }
.dbm-mini-label { font-size:.72rem; font-weight:600; color:var(--text-muted); margin:0 0 .25rem; }
.dbm-mini-value { font-size:1.05rem; font-weight:700; color:var(--text-main); display:flex; align-items:center; gap:.4rem; }

/* DB overview cards */
.dbm-db-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:.875rem; margin-bottom:1.25rem; }
.dbm-db-card {
    padding:1rem; border-radius:.875rem; border:1px solid var(--border-color);
    transition: transform .2s, box-shadow .2s;
}
.dbm-db-card:hover { transform:translateY(-1px); box-shadow:0 4px 12px rgba(0,0,0,.07); }

/* Sub-section headings */
.dbm-sub-title { font-size:.875rem; font-weight:700; color:var(--text-main); margin:0 0 .625rem; }

/* Tables two-col */
.dbm-tables-wrap { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; }

/* Table */
.dbm-table-wrap { overflow-x:auto; }
.dbm-table { width:100%; border-collapse:collapse; }
.dbm-table thead tr { background:rgba(156,163,175,.07); border-bottom:1px solid var(--border-color); }
.dbm-table th { padding:.55rem .875rem; text-align:left; font-size:.68rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; }
.dbm-table th.right { text-align:right; }
.dbm-table td { padding:.65rem .875rem; font-size:.82rem; color:var(--text-main); border-bottom:1px solid var(--border-color); }
.dbm-table td.right { text-align:right; color:var(--text-muted); }
.dbm-table tbody tr:last-child td { border-bottom:none; }
.dbm-table tbody tr { transition:background .15s; }
.dbm-table tbody tr:hover { background:rgba(37,99,235,.04); }

/* Action cards */
.dbm-action-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
.dbm-action-card {
    border:1.5px solid var(--border-color); border-radius:.875rem; padding:1.25rem;
    transition: border-color .2s, box-shadow .2s;
}
.dbm-action-card:hover { border-color:rgba(124,58,237,.25); box-shadow:0 4px 16px rgba(0,0,0,.06); }
.dbm-action-title { font-size:.95rem; font-weight:700; color:var(--text-main); margin:0 0 .5rem; display:flex; align-items:center; gap:.5rem; }
.dbm-action-desc  { font-size:.82rem; color:var(--text-muted); margin:0 0 .75rem; }
.dbm-action-list  { list-style:none; padding:0; margin:0 0 1rem; display:flex; flex-direction:column; gap:.3rem; }
.dbm-action-list li { font-size:.8rem; color:var(--text-muted); display:flex; align-items:center; gap:.4rem; }
.dbm-action-list li::before { content:'·'; color:var(--text-muted); font-weight:700; }

.dbm-btn-danger, .dbm-btn-blue {
    width:100%; padding:.7rem 1.25rem; min-height:44px;
    border-radius:.75rem; font-size:.875rem; font-weight:700;
    color:#fff; border:none; cursor:pointer;
    display:flex; align-items:center; justify-content:center; gap:.5rem;
    transition: opacity .2s, transform .15s, box-shadow .2s;
}
.dbm-btn-danger {
    background: linear-gradient(135deg, rgba(234,179,8,1), rgba(161,98,7,1));
    box-shadow: 0 2px 8px rgba(234,179,8,.3);
}
.dbm-btn-danger:hover { opacity:.9; transform:translateY(-1px); box-shadow:0 4px 14px rgba(234,179,8,.45); }
.dbm-btn-blue {
    background: linear-gradient(135deg, rgba(37,99,235,1), rgba(29,78,216,1));
    box-shadow: 0 2px 8px rgba(37,99,235,.3);
}
.dbm-btn-blue:hover { opacity:.9; transform:translateY(-1px); box-shadow:0 4px 14px rgba(37,99,235,.45); }

/* Warning notice */
.dbm-warning-notice {
    padding:1rem 1.25rem; border-radius:.875rem;
    background:rgba(234,179,8,.12); border:1.5px solid rgba(234,179,8,.4);
    display:flex; align-items:flex-start; gap:.75rem; margin-top:1.25rem;
    font-size:.85rem; color:rgba(161,98,7,1); font-weight:500;
}

/* Status badge */
.dbm-status-badge {
    padding:.3rem .875rem; border-radius:9999px;
    font-size:.8rem; font-weight:700; border:1px solid transparent;
}

/* ── Responsive ─────────────────────────────────────── */
@media (max-width:900px) {
    .dbm-health-grid { grid-template-columns:repeat(2,1fr); }
    .dbm-tables-wrap { grid-template-columns:1fr; }
}
@media (max-width:640px) {
    .dbm-db-grid { grid-template-columns:1fr; }
    .dbm-action-grid { grid-template-columns:1fr; }
    .dbm-card-body { padding:1rem 1.25rem; }
    .dbm-card-head { padding:.875rem 1.25rem; }
}
@media (max-width:480px) {
    .dbm-health-grid { grid-template-columns:1fr; }
    .dbm-mini-grid { grid-template-columns:1fr; }
    .dbm-page-title { font-size:1.35rem; }
}
</style>

<div class="dbm-page" style="max-width:72rem;margin:0 auto;">

<!-- Header -->
<div class="dbm-page-header">
    <div class="dbm-page-header-left">
        <div class="dbm-header-icon"><i class="fas fa-heartbeat" style="color:#fff;font-size:1.1rem;"></i></div>
        <div>
            <h1 class="dbm-page-title">System Health & Wartung</h1>
            <p class="dbm-page-sub">Systemüberwachung, Datenbankverwaltung und Wartungsaktionen</p>
        </div>
    </div>
</div>

<!-- Action result banner -->
<?php if (!empty($actionResult)): ?>
<?php $isOk = $actionResult['type'] === 'success'; ?>
<div class="dbm-result-banner <?php echo $isOk ? 'ok' : 'err'; ?>">
    <div class="dbm-result-title">
        <i class="fas fa-<?php echo $isOk ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo htmlspecialchars($actionResult['title']); ?>
    </div>
    <?php foreach ($actionResult['details'] as $d): ?>
    <span class="dbm-result-detail"><?php echo htmlspecialchars($d); ?></span>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- System Health Status -->
<?php
$oc   = healthColor($systemHealth['overall_status'] ?? 'healthy');
$statusLabel = match($systemHealth['overall_status'] ?? 'healthy') {
    'healthy' => '✓ System gesund', 'warning' => '⚠ Warnung', default => '✗ Fehler'
};
?>
<div class="dbm-card" style="border-left:4px solid <?php echo $oc['color']; ?>;">
    <div class="dbm-card-head">
        <h2 class="dbm-card-title">
            <i class="fas fa-heartbeat" style="color:<?php echo $oc['color']; ?>;"></i>System Health Status
        </h2>
        <span class="dbm-status-badge" style="background:<?php echo $oc['bg']; ?>;color:<?php echo $oc['color']; ?>;border-color:<?php echo $oc['border']; ?>;">
            <?php echo $statusLabel; ?>
        </span>
    </div>
    <div class="dbm-card-body">
        <div class="dbm-health-grid">
            <?php
            $dbC  = healthColor($systemHealth['database_status']  ?? 'healthy');
            $errC = healthColor($systemHealth['error_status']      ?? 'healthy');
            $secC = healthColor($systemHealth['security_status']   ?? 'healthy');
            $actC = ['bg'=>'rgba(99,102,241,.1)', 'color'=>'rgba(99,102,241,1)', 'border'=>'rgba(99,102,241,.25)', 'icon'=>'chart-line'];
            ?>
            <!-- DB status -->
            <div class="dbm-health-tile" style="background:<?php echo $dbC['bg']; ?>;border-color:<?php echo $dbC['border']; ?>;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem;">
                    <i class="fas fa-database" style="font-size:1.3rem;color:<?php echo $dbC['color']; ?>;"></i>
                    <i class="fas fa-<?php echo $dbC['icon']; ?>" style="color:<?php echo $dbC['color']; ?>;"></i>
                </div>
                <p class="dbm-health-label">Datenbank</p>
                <p class="dbm-health-value" style="color:<?php echo $dbC['color']; ?>;"><?php echo $systemHealth['database_status']==='healthy' ? 'Verbunden' : 'Fehler'; ?></p>
                <p class="dbm-health-desc"><?php echo htmlspecialchars($systemHealth['database_message'] ?? ''); ?></p>
            </div>
            <!-- Error count -->
            <div class="dbm-health-tile" style="background:<?php echo $errC['bg']; ?>;border-color:<?php echo $errC['border']; ?>;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem;">
                    <i class="fas fa-exclamation-triangle" style="font-size:1.3rem;color:<?php echo $errC['color']; ?>;"></i>
                    <i class="fas fa-<?php echo $errC['icon']; ?>" style="color:<?php echo $errC['color']; ?>;"></i>
                </div>
                <p class="dbm-health-label">Fehler (24h)</p>
                <p class="dbm-health-value" style="color:<?php echo $errC['color']; ?>;"><?php echo number_format($systemHealth['error_count_24h'] ?? 0); ?></p>
                <p class="dbm-health-desc"><?php echo $systemHealth['error_status']==='healthy' ? 'Alles OK' : 'Erhöhte Fehlerrate'; ?></p>
            </div>
            <!-- Security -->
            <div class="dbm-health-tile" style="background:<?php echo $secC['bg']; ?>;border-color:<?php echo $secC['border']; ?>;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem;">
                    <i class="fas fa-shield-alt" style="font-size:1.3rem;color:<?php echo $secC['color']; ?>;"></i>
                    <i class="fas fa-<?php echo $secC['icon']; ?>" style="color:<?php echo $secC['color']; ?>;"></i>
                </div>
                <p class="dbm-health-label">Sicherheit</p>
                <p class="dbm-health-value" style="color:<?php echo $secC['color']; ?>;"><?php echo number_format($systemHealth['failed_logins'] ?? 0); ?> Fehlversuche</p>
                <p class="dbm-health-desc">Letzte Stunde</p>
            </div>
            <!-- Activity -->
            <div class="dbm-health-tile" style="background:<?php echo $actC['bg']; ?>;border-color:<?php echo $actC['border']; ?>;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem;">
                    <i class="fas fa-chart-line" style="font-size:1.3rem;color:<?php echo $actC['color']; ?>;"></i>
                    <i class="fas fa-info-circle" style="color:<?php echo $actC['color']; ?>;"></i>
                </div>
                <p class="dbm-health-label">Aktivität</p>
                <p class="dbm-health-value" style="color:<?php echo $actC['color']; ?>;"><?php echo number_format($systemHealth['recent_logins'] ?? 0); ?> Logins</p>
                <p class="dbm-health-desc">Letzte Stunde</p>
            </div>
        </div>

        <!-- Mini metrics row -->
        <div class="dbm-mini-grid">
            <div class="dbm-mini">
                <p class="dbm-mini-label">Aktive Sessions (24h)</p>
                <p class="dbm-mini-value"><i class="fas fa-users" style="color:var(--text-muted);font-size:.8rem;"></i><?php echo number_format($systemHealth['active_sessions'] ?? 0); ?></p>
            </div>
            <div class="dbm-mini">
                <p class="dbm-mini-label">Datenbank-Größe</p>
                <p class="dbm-mini-value"><i class="fas fa-hdd" style="color:var(--text-muted);font-size:.8rem;"></i><?php echo number_format($systemHealth['disk_usage_mb'] ?? 0, 2); ?> MB</p>
            </div>
            <div class="dbm-mini">
                <p class="dbm-mini-label">Betriebszeit (geschätzt)</p>
                <p class="dbm-mini-value"><i class="fas fa-clock" style="color:var(--text-muted);font-size:.8rem;"></i><?php echo number_format($systemHealth['uptime_days'] ?? 0); ?> Tage</p>
            </div>
        </div>
    </div>
</div>

<!-- Database Overview -->
<div class="dbm-card">
    <div class="dbm-card-head">
        <h2 class="dbm-card-title">
            <i class="fas fa-chart-pie" style="color:rgba(124,58,237,1);"></i>Datenbank-Übersicht
        </h2>
    </div>
    <div class="dbm-card-body">
        <div class="dbm-db-grid">
            <div class="dbm-db-card" style="background:rgba(37,99,235,.06);">
                <p style="font-size:.72rem;font-weight:700;color:rgba(37,99,235,1);text-transform:uppercase;margin:0 0 .3rem;">User Database</p>
                <p style="font-size:1.5rem;font-weight:800;color:rgba(37,99,235,1);margin:0;"><?php echo number_format($userDbTotal, 2); ?> MB</p>
            </div>
            <div class="dbm-db-card" style="background:rgba(22,163,74,.06);">
                <p style="font-size:.72rem;font-weight:700;color:rgba(22,163,74,1);text-transform:uppercase;margin:0 0 .3rem;">Content Database</p>
                <p style="font-size:1.5rem;font-weight:800;color:rgba(22,163,74,1);margin:0;"><?php echo number_format($contentDbTotal, 2); ?> MB</p>
            </div>
            <div class="dbm-db-card" style="background:rgba(124,58,237,.06);">
                <p style="font-size:.72rem;font-weight:700;color:rgba(124,58,237,1);text-transform:uppercase;margin:0 0 .3rem;">Gesamt</p>
                <p style="font-size:1.5rem;font-weight:800;color:rgba(124,58,237,1);margin:0;"><?php echo number_format($totalSize, 2); ?> MB</p>
            </div>
        </div>

        <div class="dbm-tables-wrap">
            <!-- User DB tables -->
            <div>
                <h3 class="dbm-sub-title">User Database Tabellen</h3>
                <div class="dbm-table-wrap">
                <table class="dbm-table">
                    <thead><tr>
                        <th>Tabelle</th><th class="right">Zeilen</th><th class="right">Größe</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($tableSizes['user'] as $t): ?>
                    <tr>
                        <td data-label="Tabelle" style="font-family:monospace;font-size:.78rem;"><?php echo htmlspecialchars($t['table']); ?></td>
                        <td data-label="Zeilen" class="right"><?php echo number_format($t['rows']); ?></td>
                        <td data-label="Größe" class="right"><?php echo number_format($t['size_mb'],2); ?> MB</td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <!-- Content DB tables -->
            <div>
                <h3 class="dbm-sub-title">Content Database Tabellen</h3>
                <div class="dbm-table-wrap">
                <table class="dbm-table">
                    <thead><tr>
                        <th>Tabelle</th><th class="right">Zeilen</th><th class="right">Größe</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($tableSizes['content'] as $t): ?>
                    <tr>
                        <td data-label="Tabelle" style="font-family:monospace;font-size:.78rem;"><?php echo htmlspecialchars($t['table']); ?></td>
                        <td data-label="Zeilen" class="right"><?php echo number_format($t['rows']); ?></td>
                        <td data-label="Größe" class="right"><?php echo number_format($t['size_mb'],2); ?> MB</td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Maintenance Actions -->
<div class="dbm-card">
    <div class="dbm-card-head">
        <h2 class="dbm-card-title">
            <i class="fas fa-tools" style="color:rgba(249,115,22,1);"></i>Wartungsaktionen
        </h2>
    </div>
    <div class="dbm-card-body">
        <div class="dbm-action-grid">
            <!-- Clean Logs -->
            <div class="dbm-action-card">
                <p class="dbm-action-title">
                    <i class="fas fa-broom" style="color:rgba(234,179,8,1);"></i>Logs bereinigen
                </p>
                <p class="dbm-action-desc">Löscht alte Log-Einträge zur Freigabe von Speicherplatz:</p>
                <ul class="dbm-action-list">
                    <li>User Sessions älter als 30 Tage</li>
                    <li>System Logs älter als 1 Jahr</li>
                    <li>Inventory History älter als 1 Jahr</li>
                    <li>Event History älter als 1 Jahr</li>
                </ul>
                <form method="POST" onsubmit="return confirm('Alte Logs wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.');">
                    <?php echo CSRFHandler::getTokenField(); ?>
                    <button type="submit" name="clean_logs" class="dbm-btn-danger">
                        <i class="fas fa-trash-alt"></i>Logs bereinigen
                    </button>
                </form>
            </div>

            <!-- Clear Cache -->
            <div class="dbm-action-card">
                <p class="dbm-action-title">
                    <i class="fas fa-sync-alt" style="color:rgba(37,99,235,1);"></i>Cache leeren
                </p>
                <p class="dbm-action-desc">Löscht temporäre Cache-Dateien:</p>
                <ul class="dbm-action-list">
                    <li>Alle Dateien im cache/ Ordner</li>
                    <li>Gibt Speicherplatz frei</li>
                    <li>Beeinflusst keine Datenbanken</li>
                </ul>
                <form method="POST" onsubmit="return confirm('Cache wirklich leeren?');">
                    <?php echo CSRFHandler::getTokenField(); ?>
                    <button type="submit" name="clear_cache" class="dbm-btn-blue">
                        <i class="fas fa-eraser"></i>Cache leeren
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Warning notice -->
<div class="dbm-warning-notice">
    <i class="fas fa-exclamation-triangle" style="flex-shrink:0;margin-top:.1rem;"></i>
    <span><strong>Hinweis:</strong> Wartungsaktionen können nicht rückgängig gemacht werden. Stelle sicher, dass vor dem Bereinigen wichtiger Daten ein Backup erstellt wurde.</span>
</div>

</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
