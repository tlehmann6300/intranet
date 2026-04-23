<?php
/**
 * Admin Dashboard - Overview of all administration sections
 * Provides quick access and metrics for all admin features
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';

// Check if user is a board member
if (!Auth::check() || !Auth::isBoard()) {
    header('Location: ../auth/login.php');
    exit;
}

$userDb    = Database::getUserDB();
$contentDb = Database::getContentDB();

// Get quick metrics
$metrics    = [];
$topActions = [];

try {
    // Total users count
    $stmt = $userDb->query("SELECT COUNT(*) as count FROM users WHERE deleted_at IS NULL");
    $metrics['total_users'] = $stmt->fetch()['count'] ?? 0;

    // Active users (7 days)
    $stmt = $userDb->query("
        SELECT COUNT(*) as count
        FROM users
        WHERE last_login > DATE_SUB(NOW(), INTERVAL 7 DAY)
        AND deleted_at IS NULL
    ");
    $metrics['active_users_7d'] = $stmt->fetch()['count'] ?? 0;

    // Recent errors (24h)
    $stmt = $contentDb->query("
        SELECT COUNT(*) as count
        FROM system_logs
        WHERE action LIKE '%error%'
        AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $metrics['recent_errors'] = $stmt->fetch()['count'] ?? 0;

    // Failed logins (24h)
    $stmt = $contentDb->query("
        SELECT COUNT(*) as count
        FROM system_logs
        WHERE action = 'login_failed'
        AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $metrics['failed_logins_24h'] = $stmt->fetch()['count'] ?? 0;

    // Recent audit logs count
    $stmt = $contentDb->query("
        SELECT COUNT(*) as count
        FROM system_logs
        WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $metrics['recent_logs'] = $stmt->fetch()['count'] ?? 0;

    // Database size
    $stmt = $userDb->prepare("
        SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size
        FROM information_schema.TABLES
        WHERE table_schema IN (?, ?)
    ");
    $stmt->execute([DB_USER_NAME, DB_CONTENT_NAME]);
    $metrics['db_size_mb'] = $stmt->fetch()['size'] ?? 0;

    // Recent system actions
    $stmt = $contentDb->query("
        SELECT action, COUNT(*) as count
        FROM system_logs
        WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        GROUP BY action
        ORDER BY count DESC
        LIMIT 5
    ");
    $topActions = $stmt->fetchAll();

} catch (Exception $e) {
    error_log("Error fetching admin metrics: " . $e->getMessage());
}

// Error state color (dynamic based on count)
$errColor  = ($metrics['recent_errors'] ?? 0) > 10 ? 'rgba(239,68,68,1)' : 'rgba(234,179,8,1)';
$errBg     = ($metrics['recent_errors'] ?? 0) > 10 ? 'rgba(239,68,68,.12)' : 'rgba(234,179,8,.12)';
$errBorder = ($metrics['recent_errors'] ?? 0) > 10 ? 'rgba(239,68,68,.3)'  : 'rgba(234,179,8,.3)';

$showSecAlert = (($metrics['failed_logins_24h'] ?? 0) > 10) || (($metrics['recent_errors'] ?? 0) > 20);

$title = 'Admin Dashboard - IBC Intranet';
ob_start();
?>

<style>
/* ═══════════════════════════════════════════════
   Admin Dashboard — vollständig responsive
   Breakpoints: 480 · 640 · 900 · 1200
═══════════════════════════════════════════════ */

/* ── Keyframes ── */
@keyframes admSlideUp   { from { opacity:0; transform:translateY(18px) scale(.98); } to { opacity:1; transform:translateY(0) scale(1); } }
@keyframes admFadeIn    { from { opacity:0; } to { opacity:1; } }
@keyframes admSlideRight{ from { opacity:0; transform:translateX(-10px); } to { opacity:1; transform:translateX(0); } }

.adm-page {
    animation: admSlideUp .4s cubic-bezier(.22,.68,0,1.2) both;
}

/* ── Header Icon ── */
.adm-header-icon {
    width:3rem; height:3rem; border-radius:.875rem; flex-shrink:0;
    background: linear-gradient(135deg, rgba(124,58,237,1), rgba(99,102,241,1));
    box-shadow: 0 4px 18px rgba(124,58,237,.4);
    display:flex; align-items:center; justify-content:center;
    transition: box-shadow .25s, transform .2s;
}
.adm-header-icon:hover { transform:scale(1.06); box-shadow:0 6px 22px rgba(124,58,237,.5); }

/* ── Metric Grid ── */
.adm-metric-grid {
    display: grid;
    grid-template-columns: repeat(4,1fr);
    gap: .875rem;
    margin-bottom: 1.25rem;
}
@media (max-width: 900px)  { .adm-metric-grid { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 480px)  { .adm-metric-grid { grid-template-columns: 1fr; gap:.625rem; } }

.adm-metric {
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 1rem;
    padding: 1.1rem 1.25rem;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
    border-left-width: 3px; border-left-style: solid;
    display: flex; flex-direction: column; gap: .75rem;
    animation: admSlideUp .42s cubic-bezier(.22,.68,0,1.2) both;
    transition: box-shadow .25s, transform .22s;
}
.adm-metric:hover { box-shadow:0 6px 20px rgba(0,0,0,.09); transform:translateY(-2px); }
.adm-metric:nth-child(1) { animation-delay:.04s; }
.adm-metric:nth-child(2) { animation-delay:.09s; }
.adm-metric:nth-child(3) { animation-delay:.14s; }
.adm-metric:nth-child(4) { animation-delay:.19s; }

/* On mobile, show metrics horizontally */
@media (max-width: 480px) {
    .adm-metric { flex-direction:row; align-items:center; padding:1rem; }
    .adm-metric-top { flex:1; flex-direction:column; justify-content:flex-start; }
}

.adm-metric-top { display:flex; align-items:flex-start; justify-content:space-between; }
.adm-metric-icon {
    width:2.5rem; height:2.5rem; border-radius:.625rem; flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-size:1.1rem;
}
@media (max-width:480px) { .adm-metric-icon { width:2.25rem; height:2.25rem; font-size:1rem; } }

.adm-metric-link {
    display:inline-flex; align-items:center; gap:.3rem;
    font-size:.77rem; font-weight:600; text-decoration:none;
    transition: gap .2s, opacity .2s;
    min-height:28px;
}
.adm-metric-link:hover { gap:.5rem; opacity:.8; }
@media (max-width:480px) { .adm-metric-link { display:none; } }

/* ── Two-col layout ── */
.adm-two-col {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .875rem;
    margin-bottom: 1.25rem;
}
@media (max-width: 900px) { .adm-two-col { grid-template-columns: 1fr; } }

.adm-panel {
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 1rem;
    padding: 1.25rem 1.3rem;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
    animation: admSlideUp .42s cubic-bezier(.22,.68,0,1.2) .22s both;
}
.adm-panel-title {
    font-size:.9rem; font-weight:700; color:var(--text-main);
    margin:0 0 .875rem; display:flex; align-items:center; gap:.5rem;
}
.adm-panel-title-icon {
    width:1.5rem; height:1.5rem; border-radius:.35rem;
    display:inline-flex; align-items:center; justify-content:center;
}

/* ── Action items ── */
.adm-action-item {
    display:flex; align-items:center; justify-content:space-between;
    padding:.75rem .875rem; border-radius:.75rem;
    background:rgba(156,163,175,.06); border:1.5px solid transparent;
    text-decoration:none;
    transition: background .18s, border-color .18s, transform .18s;
    margin-bottom:.5rem;
    min-height:52px; /* touch target */
}
.adm-action-item:last-child { margin-bottom:0; }
.adm-action-item:hover { background:rgba(124,58,237,.07); border-color:rgba(124,58,237,.22); transform:translateX(2px); }
.adm-action-icon {
    width:2.1rem; height:2.1rem; border-radius:.55rem; flex-shrink:0;
    display:flex; align-items:center; justify-content:center; font-size:.9rem;
}
.adm-action-item:nth-child(1) { animation: admSlideRight .35s .28s both; }
.adm-action-item:nth-child(2) { animation: admSlideRight .35s .33s both; }
.adm-action-item:nth-child(3) { animation: admSlideRight .35s .38s both; }
.adm-action-item:nth-child(4) { animation: admSlideRight .35s .43s both; }
.adm-action-item:nth-child(5) { animation: admSlideRight .35s .48s both; }

/* ── Activity items ── */
.adm-activity-item {
    display:flex; align-items:center; justify-content:space-between;
    padding:.7rem .875rem; border-radius:.75rem;
    background:rgba(156,163,175,.06); margin-bottom:.45rem;
    animation: admFadeIn .3s ease both;
}
.adm-activity-item:last-child { margin-bottom:0; }
.adm-activity-badge {
    padding:.2rem .6rem; border-radius:999px;
    font-size:.73rem; font-weight:700; white-space:nowrap;
    background:rgba(37,99,235,.12); color:rgba(37,99,235,1); border:1px solid rgba(37,99,235,.25);
}
.adm-activity-count {
    width:1.9rem; height:1.9rem; border-radius:.45rem; flex-shrink:0;
    background: linear-gradient(135deg, rgba(99,102,241,1), rgba(124,58,237,1));
    display:flex; align-items:center; justify-content:center;
    font-size:.72rem; font-weight:800; color:#fff;
}
.adm-activity-label {
    font-size:.82rem; font-weight:500; color:var(--text-main);
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:140px;
}
@media (max-width:480px) { .adm-activity-label { max-width:120px; font-size:.78rem; } }

/* ── Security alert ── */
.adm-sec-alert {
    border-radius:1rem; padding:1.25rem 1.4rem; margin-bottom:1.25rem;
    display:flex; align-items:flex-start; gap:.875rem;
    background:rgba(239,68,68,.07); border:1.5px solid rgba(239,68,68,.28);
    animation: admSlideUp .4s cubic-bezier(.22,.68,0,1.2) both;
}
@media (max-width:480px) { .adm-sec-alert { flex-direction:column; gap:.75rem; padding:1rem; } }
.adm-sec-icon {
    width:2.6rem; height:2.6rem; border-radius:.75rem; flex-shrink:0;
    background:rgba(239,68,68,1); display:flex; align-items:center; justify-content:center;
    font-size:.95rem; color:#fff;
}
.adm-sec-btn {
    display:inline-flex; align-items:center; gap:.4rem;
    padding:.5rem 1rem; border-radius:.6rem; font-size:.8rem; font-weight:700;
    background:rgba(239,68,68,1); color:#fff; text-decoration:none; border:none;
    cursor:pointer; transition: opacity .2s, transform .15s;
    min-height:36px;
}
.adm-sec-btn:hover { opacity:.88; transform:translateY(-1px); }

/* ── Mini grid ── */
.adm-mini-grid {
    display:grid; grid-template-columns:1fr 1fr; gap:.875rem;
}
@media (max-width:640px) { .adm-mini-grid { grid-template-columns:1fr; } }

.adm-mini {
    background-color: var(--bg-card);
    border:1px solid var(--border-color);
    border-radius:1rem; padding:1.1rem 1.25rem;
    box-shadow: 0 2px 8px rgba(0,0,0,.05);
    animation: admSlideUp .42s cubic-bezier(.22,.68,0,1.2) both;
    transition: box-shadow .25s, transform .2s;
}
.adm-mini:hover { box-shadow:0 5px 16px rgba(0,0,0,.08); transform:translateY(-1px); }
.adm-mini:nth-child(1) { animation-delay:.25s; }
.adm-mini:nth-child(2) { animation-delay:.3s; }

.adm-mini-link {
    font-size:.79rem; font-weight:600; text-decoration:none;
    transition: gap .2s, opacity .2s; display:inline-flex; align-items:center; gap:.25rem;
}
.adm-mini-link:hover { gap:.4rem; opacity:.8; }

/* ── Page header ── */
.adm-page-header {
    display:flex; flex-wrap:wrap; align-items:flex-start;
    justify-content:space-between; gap:.875rem; margin-bottom:1.5rem;
}
.adm-page-header-left { display:flex; align-items:center; gap:.875rem; }
@media (max-width:480px) {
    .adm-page-header { margin-bottom:1.1rem; }
    .adm-page-header-left h1 { font-size:1.35rem; }
}
</style>

<div class="adm-page" style="max-width:72rem;margin:0 auto;">

<!-- Header -->
<div class="adm-page-header">
    <div class="adm-page-header-left">
        <div class="adm-header-icon">
            <i class="fas fa-tachometer-alt" style="color:#fff;font-size:1.1rem;"></i>
        </div>
        <div>
            <h1 style="font-size:1.6rem;font-weight:800;color:var(--text-main);margin:0;line-height:1.2;">Admin Dashboard</h1>
            <p style="font-size:.85rem;color:var(--text-muted);margin:.2rem 0 0;">Zentrale Übersicht aller Administrationsfunktionen</p>
        </div>
    </div>
</div>

<!-- Key Metrics -->
<div class="adm-metric-grid">

    <!-- Total Users -->
    <div class="adm-metric" style="border-left-color:rgba(37,99,235,1);">
        <div class="adm-metric-top">
            <div>
                <p style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin:0 0 .3rem;letter-spacing:.05em;">Gesamtbenutzer</p>
                <p style="font-size:2rem;font-weight:800;color:rgba(37,99,235,1);margin:0;line-height:1.1;"><?php echo number_format($metrics['total_users'] ?? 0); ?></p>
            </div>
            <div class="adm-metric-icon" style="background:rgba(37,99,235,.12);">
                <i class="fas fa-users" style="color:rgba(37,99,235,1);"></i>
            </div>
        </div>
        <a href="users.php" class="adm-metric-link" style="color:rgba(37,99,235,1);">
            Benutzerverwaltung <i class="fas fa-arrow-right" style="font-size:.7rem;"></i>
        </a>
    </div>

    <!-- Active Users -->
    <div class="adm-metric" style="border-left-color:rgba(22,163,74,1);">
        <div class="adm-metric-top">
            <div>
                <p style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin:0 0 .3rem;letter-spacing:.05em;">Aktiv (7 Tage)</p>
                <p style="font-size:2rem;font-weight:800;color:rgba(22,163,74,1);margin:0;line-height:1.1;"><?php echo number_format($metrics['active_users_7d'] ?? 0); ?></p>
            </div>
            <div class="adm-metric-icon" style="background:rgba(22,163,74,.12);">
                <i class="fas fa-user-check" style="color:rgba(22,163,74,1);"></i>
            </div>
        </div>
        <a href="stats.php" class="adm-metric-link" style="color:rgba(22,163,74,1);">
            Statistiken ansehen <i class="fas fa-arrow-right" style="font-size:.7rem;"></i>
        </a>
    </div>

    <!-- Errors -->
    <div class="adm-metric" style="border-left-color:<?php echo $errColor; ?>;">
        <div class="adm-metric-top">
            <div>
                <p style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin:0 0 .3rem;letter-spacing:.05em;">Fehler (24h)</p>
                <p style="font-size:2rem;font-weight:800;color:<?php echo $errColor; ?>;margin:0;line-height:1.1;"><?php echo number_format($metrics['recent_errors'] ?? 0); ?></p>
            </div>
            <div class="adm-metric-icon" style="background:<?php echo $errBg; ?>;">
                <i class="fas fa-exclamation-triangle" style="color:<?php echo $errColor; ?>;"></i>
            </div>
        </div>
        <a href="audit.php" class="adm-metric-link" style="color:<?php echo $errColor; ?>;">
            Audit Logs prüfen <i class="fas fa-arrow-right" style="font-size:.7rem;"></i>
        </a>
    </div>

    <!-- DB Size -->
    <div class="adm-metric" style="border-left-color:rgba(124,58,237,1);">
        <div class="adm-metric-top">
            <div>
                <p style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin:0 0 .3rem;letter-spacing:.05em;">Datenbank-Größe</p>
                <p style="font-size:2rem;font-weight:800;color:rgba(124,58,237,1);margin:0;line-height:1.1;"><?php echo number_format($metrics['db_size_mb'] ?? 0, 1); ?> <span style="font-size:1rem;">MB</span></p>
            </div>
            <div class="adm-metric-icon" style="background:rgba(124,58,237,.12);">
                <i class="fas fa-database" style="color:rgba(124,58,237,1);"></i>
            </div>
        </div>
        <a href="db_maintenance.php" class="adm-metric-link" style="color:rgba(124,58,237,1);">
            System Health <i class="fas fa-arrow-right" style="font-size:.7rem;"></i>
        </a>
    </div>

</div>

<!-- Security alert (conditional) -->
<?php if ($showSecAlert): ?>
<div class="adm-sec-alert">
    <div class="adm-sec-icon"><i class="fas fa-exclamation-triangle"></i></div>
    <div style="flex:1;">
        <p style="font-size:.95rem;font-weight:700;color:rgba(185,28,28,1);margin:0 0 .35rem;">Sicherheitswarnung</p>
        <p style="font-size:.875rem;color:rgba(185,28,28,1);margin:0 0 .875rem;line-height:1.5;">
            <?php if (($metrics['failed_logins_24h'] ?? 0) > 10): ?>
            Es wurden <strong><?php echo $metrics['failed_logins_24h']; ?> fehlgeschlagene Login-Versuche</strong> in den letzten 24 Stunden festgestellt.<?php if (($metrics['recent_errors'] ?? 0) > 20): ?><br><?php endif; ?>
            <?php endif; ?>
            <?php if (($metrics['recent_errors'] ?? 0) > 20): ?>
            Es gibt <strong><?php echo $metrics['recent_errors']; ?> Systemfehler</strong> in den letzten 24 Stunden.
            <?php endif; ?>
        </p>
        <a href="audit.php" class="adm-sec-btn">
            <i class="fas fa-search"></i>Logs untersuchen
        </a>
    </div>
</div>
<?php endif; ?>

<!-- Quick Actions + Activity -->
<div class="adm-two-col">

    <!-- Quick Actions -->
    <div class="adm-panel">
        <h2 class="adm-panel-title">
            <span class="adm-panel-title-icon" style="background:rgba(234,179,8,.15);">
                <i class="fas fa-bolt" style="font-size:.7rem;color:rgba(161,98,7,1);"></i>
            </span>
            Schnellaktionen
        </h2>

        <a href="users.php" class="adm-action-item">
            <div style="display:flex;align-items:center;gap:.75rem;">
                <div class="adm-action-icon" style="background:rgba(37,99,235,.12);">
                    <i class="fas fa-users" style="color:rgba(37,99,235,1);"></i>
                </div>
                <div>
                    <p style="font-size:.875rem;font-weight:600;color:var(--text-main);margin:0 0 .15rem;">Benutzerverwaltung</p>
                    <p style="font-size:.78rem;color:var(--text-muted);margin:0;">Benutzer und Rollen verwalten</p>
                </div>
            </div>
            <i class="fas fa-chevron-right" style="color:var(--text-muted);font-size:.75rem;"></i>
        </a>

        <a href="stats.php" class="adm-action-item">
            <div style="display:flex;align-items:center;gap:.75rem;">
                <div class="adm-action-icon" style="background:rgba(22,163,74,.12);">
                    <i class="fas fa-chart-bar" style="color:rgba(22,163,74,1);"></i>
                </div>
                <div>
                    <p style="font-size:.875rem;font-weight:600;color:var(--text-main);margin:0 0 .15rem;">Statistiken</p>
                    <p style="font-size:.78rem;color:var(--text-muted);margin:0;">Systemstatistiken anzeigen</p>
                </div>
            </div>
            <i class="fas fa-chevron-right" style="color:var(--text-muted);font-size:.75rem;"></i>
        </a>

        <a href="audit.php" class="adm-action-item">
            <div style="display:flex;align-items:center;gap:.75rem;">
                <div class="adm-action-icon" style="background:rgba(124,58,237,.12);">
                    <i class="fas fa-clipboard-list" style="color:rgba(124,58,237,1);"></i>
                </div>
                <div>
                    <p style="font-size:.875rem;font-weight:600;color:var(--text-main);margin:0 0 .15rem;">Audit Logs</p>
                    <p style="font-size:.78rem;color:var(--text-muted);margin:0;">Systemaktivitäten überwachen</p>
                </div>
            </div>
            <i class="fas fa-chevron-right" style="color:var(--text-muted);font-size:.75rem;"></i>
        </a>

        <a href="db_maintenance.php" class="adm-action-item">
            <div style="display:flex;align-items:center;gap:.75rem;">
                <div class="adm-action-icon" style="background:rgba(99,102,241,.12);">
                    <i class="fas fa-heartbeat" style="color:rgba(99,102,241,1);"></i>
                </div>
                <div>
                    <p style="font-size:.875rem;font-weight:600;color:var(--text-main);margin:0 0 .15rem;">System Health</p>
                    <p style="font-size:.78rem;color:var(--text-muted);margin:0;">Systemzustand &amp; Wartung</p>
                </div>
            </div>
            <i class="fas fa-chevron-right" style="color:var(--text-muted);font-size:.75rem;"></i>
        </a>

        <a href="settings.php" class="adm-action-item">
            <div style="display:flex;align-items:center;gap:.75rem;">
                <div class="adm-action-icon" style="background:rgba(234,88,12,.12);">
                    <i class="fas fa-cog" style="color:rgba(234,88,12,1);"></i>
                </div>
                <div>
                    <p style="font-size:.875rem;font-weight:600;color:var(--text-main);margin:0 0 .15rem;">Einstellungen</p>
                    <p style="font-size:.78rem;color:var(--text-muted);margin:0;">System konfigurieren</p>
                </div>
            </div>
            <i class="fas fa-chevron-right" style="color:var(--text-muted);font-size:.75rem;"></i>
        </a>
    </div>

    <!-- Top Activities -->
    <div class="adm-panel">
        <h2 class="adm-panel-title">
            <span class="adm-panel-title-icon" style="background:rgba(22,163,74,.12);">
                <i class="fas fa-chart-line" style="font-size:.7rem;color:rgba(22,163,74,1);"></i>
            </span>
            Top Aktivitäten (24h)
        </h2>

        <?php if (!empty($topActions)): ?>
            <?php foreach ($topActions as $action): ?>
            <div class="adm-activity-item">
                <div style="display:flex;align-items:center;gap:.75rem;">
                    <div class="adm-activity-count"><?php echo number_format($action['count']); ?></div>
                    <span class="adm-activity-label"><?php echo htmlspecialchars($action['action']); ?></span>
                </div>
                <span class="adm-activity-badge"><?php echo number_format($action['count']); ?>x</span>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
        <div style="padding:3rem 1rem;text-align:center;">
            <div style="width:3rem;height:3rem;border-radius:50%;background:rgba(156,163,175,.12);display:inline-flex;align-items:center;justify-content:center;margin-bottom:.75rem;font-size:1.25rem;color:rgba(156,163,175,1);">
                <i class="fas fa-inbox"></i>
            </div>
            <p style="font-size:.875rem;color:var(--text-muted);margin:0;">Keine Aktivitäten in den letzten 24 Stunden</p>
        </div>
        <?php endif; ?>
    </div>

</div>

<!-- Bottom mini cards -->
<div class="adm-mini-grid">

    <div class="adm-mini">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem;">
            <p style="font-size:.78rem;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin:0;letter-spacing:.05em;">Aktivitätslogs (24h)</p>
            <i class="fas fa-list" style="color:rgba(124,58,237,1);font-size:.85rem;"></i>
        </div>
        <p style="font-size:1.9rem;font-weight:800;color:var(--text-main);margin:0 0 .5rem;"><?php echo number_format($metrics['recent_logs'] ?? 0); ?></p>
        <a href="audit.php" class="adm-mini-link" style="color:rgba(124,58,237,1);">Logs ansehen →</a>
    </div>

    <div class="adm-mini">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem;">
            <p style="font-size:.78rem;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin:0;letter-spacing:.05em;">Fehlgeschlagene Logins</p>
            <i class="fas fa-shield-alt" style="color:rgba(239,68,68,1);font-size:.85rem;"></i>
        </div>
        <p style="font-size:1.9rem;font-weight:800;color:var(--text-main);margin:0 0 .5rem;"><?php echo number_format($metrics['failed_logins_24h'] ?? 0); ?></p>
        <a href="audit.php?action=login_failed" class="adm-mini-link" style="color:rgba(239,68,68,1);">Details ansehen →</a>
    </div>

</div>

</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
?>
