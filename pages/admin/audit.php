<?php
require_once __DIR__ . '/../../src/Auth.php';

if (!Auth::isBoard()) {
    header('Location: /index.php');
    exit;
}

// Get audit logs from content database
$db = Database::getContentDB();

$limit = 100;
$page = intval($_GET['page'] ?? 1);
$offset = ($page - 1) * $limit;

$params = [];
$sql = "SELECT * FROM system_logs WHERE 1=1";

if (!empty($_GET['action'])) {
    $sql .= " AND action LIKE ?";
    $params[] = '%' . $_GET['action'] . '%';
}

if (!empty($_GET['user_id'])) {
    $sql .= " AND user_id = ?";
    $params[] = $_GET['user_id'];
}

$sql .= " ORDER BY timestamp DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get total count
$countSql = "SELECT COUNT(*) as total FROM system_logs WHERE 1=1";
$countParams = [];
if (!empty($_GET['action'])) {
    $countSql .= " AND action LIKE ?";
    $countParams[] = '%' . $_GET['action'] . '%';
}
if (!empty($_GET['user_id'])) {
    $countSql .= " AND user_id = ?";
    $countParams[] = $_GET['user_id'];
}
$stmt = $db->prepare($countSql);
$stmt->execute($countParams);
$totalLogs = $stmt->fetch()['total'];
$totalPages = ceil($totalLogs / $limit);

function getActionStyle(string $action): array {
    $action = strtolower($action);
    if (str_contains($action, 'delete'))     return ['bg'=>'rgba(239,68,68,.12)',   'color'=>'rgba(185,28,28,1)',   'border'=>'rgba(239,68,68,.3)',   'dot'=>'rgba(239,68,68,1)',   'icon'=>'fas fa-trash-alt'];
    if (str_contains($action, 'login_fail')) return ['bg'=>'rgba(239,68,68,.12)',   'color'=>'rgba(185,28,28,1)',   'border'=>'rgba(239,68,68,.3)',   'dot'=>'rgba(239,68,68,1)',   'icon'=>'fas fa-times-circle'];
    if (str_contains($action, 'create'))     return ['bg'=>'rgba(34,197,94,.12)',   'color'=>'rgba(21,128,61,1)',   'border'=>'rgba(34,197,94,.3)',   'dot'=>'rgba(34,197,94,1)',   'icon'=>'fas fa-plus-circle'];
    if (str_contains($action, 'login'))      return ['bg'=>'rgba(34,197,94,.12)',   'color'=>'rgba(21,128,61,1)',   'border'=>'rgba(34,197,94,.3)',   'dot'=>'rgba(34,197,94,1)',   'icon'=>'fas fa-sign-in-alt'];
    if (str_contains($action, 'logout'))     return ['bg'=>'rgba(156,163,175,.12)', 'color'=>'var(--text-muted)',  'border'=>'rgba(156,163,175,.3)', 'dot'=>'rgba(156,163,175,1)', 'icon'=>'fas fa-sign-out-alt'];
    if (str_contains($action, 'update'))     return ['bg'=>'rgba(234,179,8,.12)',   'color'=>'rgba(161,98,7,1)',   'border'=>'rgba(234,179,8,.3)',   'dot'=>'rgba(234,179,8,1)',   'icon'=>'fas fa-pencil-alt'];
    if (str_contains($action, 'invitation')) return ['bg'=>'rgba(139,92,246,.12)',  'color'=>'rgba(109,40,217,1)', 'border'=>'rgba(139,92,246,.3)',  'dot'=>'rgba(139,92,246,1)',  'icon'=>'fas fa-envelope'];
    return                                          ['bg'=>'rgba(156,163,175,.12)', 'color'=>'var(--text-muted)',  'border'=>'rgba(156,163,175,.3)', 'dot'=>'rgba(156,163,175,1)', 'icon'=>'fas fa-circle'];
}

$title = 'Audit-Logs - IBC Intranet';
ob_start();
?>

<style>
/* ── Audit-Logs ───────────────────────────────────────── */
@keyframes audSlideUp {
    from { opacity:0; transform:translateY(18px) scale(.98); }
    to   { opacity:1; transform:translateY(0)    scale(1);   }
}
.aud-page { animation: audSlideUp .4s cubic-bezier(.22,.68,0,1.2) both; }

/* Page header */
.aud-page-header {
    display:flex; flex-wrap:wrap; align-items:flex-start;
    justify-content:space-between; gap:1rem; margin-bottom:1.75rem;
}
.aud-page-header-left { display:flex; align-items:center; gap:.875rem; min-width:0; }
.aud-header-icon {
    width:3rem; height:3rem; border-radius:.875rem; flex-shrink:0;
    background: linear-gradient(135deg, rgba(124,58,237,1), rgba(99,102,241,1));
    box-shadow: 0 4px 14px rgba(124,58,237,.35);
    display:flex; align-items:center; justify-content:center;
}
.aud-page-title { font-size:1.6rem; font-weight:800; color:var(--text-main); margin:0; line-height:1.2; }
.aud-page-sub   { font-size:.85rem; color:var(--text-muted); margin:.2rem 0 0; }

/* Filter card */
.aud-filter {
    border-radius:1rem;
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    padding: 1.25rem 1.5rem;
    margin-bottom: 1.25rem;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    animation: audSlideUp .35s .05s cubic-bezier(.22,.68,0,1.2) both;
}
.aud-filter-grid {
    display:grid;
    grid-template-columns: 1fr 1fr auto;
    gap:.75rem; align-items:end;
}
.aud-filter-label {
    display:block; font-size:.8rem; font-weight:600;
    color:var(--text-muted); margin-bottom:.4rem;
}
.aud-input {
    width:100%; padding:.6rem 1rem; border-radius:.625rem; font-size:.875rem;
    background-color: var(--bg-body);
    border: 1.5px solid var(--border-color);
    color: var(--text-main);
    transition: border-color .2s, box-shadow .2s;
    outline: none; min-height:42px;
}
.aud-input:focus {
    border-color: rgba(124,58,237,.6);
    box-shadow: 0 0 0 3px rgba(124,58,237,.12);
}
.aud-input::placeholder { color: var(--text-muted); }
.aud-filter-actions { display:flex; gap:.5rem; align-items:flex-end; }
.aud-filter-btn {
    padding:.6rem 1.25rem; border-radius:.625rem; font-size:.875rem; font-weight:600;
    background: linear-gradient(135deg, rgba(124,58,237,1), rgba(99,102,241,1));
    color:#fff; border:none; cursor:pointer;
    transition: opacity .2s, transform .15s, box-shadow .2s;
    box-shadow: 0 2px 8px rgba(124,58,237,.3);
    min-height:42px; white-space:nowrap;
}
.aud-filter-btn:hover { opacity:.92; transform:translateY(-1px); box-shadow: 0 4px 14px rgba(124,58,237,.4); }
.aud-reset-btn {
    padding:.6rem .9rem; border-radius:.625rem; font-size:.875rem; font-weight:600;
    background: rgba(156,163,175,.12); color: var(--text-muted);
    border: 1.5px solid var(--border-color); cursor:pointer;
    transition: background .2s, border-color .2s; min-height:42px;
    text-decoration:none; display:inline-flex; align-items:center; justify-content:center;
}
.aud-reset-btn:hover { background: rgba(156,163,175,.22); border-color:rgba(156,163,175,.4); }

/* Log wrapper */
.aud-wrap {
    border-radius:1rem;
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    overflow: hidden;
    box-shadow: 0 2px 12px rgba(0,0,0,.06);
    animation: audSlideUp .4s .1s cubic-bezier(.22,.68,0,1.2) both;
}

/* Legend */
.aud-legend {
    padding:.875rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
    display:flex; flex-wrap:wrap; gap:.75rem;
    background:rgba(156,163,175,.04);
}
.aud-legend-item {
    display:inline-flex; align-items:center; gap:.4rem;
    font-size:.75rem; color:var(--text-muted);
}
.aud-dot { width:.5rem; height:.5rem; border-radius:50%; flex-shrink:0; }

/* Log rows */
.aud-row {
    display:flex; align-items:flex-start; gap:1rem;
    padding:.875rem 1.5rem;
    border-bottom: 1px solid var(--border-color);
    transition: background .15s;
    animation: audSlideUp .3s cubic-bezier(.22,.68,0,1.2) both;
}
.aud-row:nth-child(1) { animation-delay:.12s; }
.aud-row:nth-child(2) { animation-delay:.16s; }
.aud-row:nth-child(3) { animation-delay:.20s; }
.aud-row:nth-child(4) { animation-delay:.24s; }
.aud-row:nth-child(5) { animation-delay:.28s; }
.aud-row:last-child { border-bottom:none; }
.aud-row:hover { background: rgba(124,58,237,.04); }

.aud-icon-wrap {
    flex-shrink:0; margin-top:.15rem;
    width:2rem; height:2rem; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:.7rem;
}
.aud-badge {
    display:inline-flex; align-items:center; gap:.35rem;
    padding:.15rem .65rem; border-radius:9999px;
    font-size:.72rem; font-weight:600; border-width:1px; border-style:solid;
    max-width:100%; word-break:break-word;
}
.aud-meta {
    display:flex; flex-wrap:wrap; align-items:center; gap:.75rem;
    margin-top:.4rem; font-size:.72rem; color:var(--text-muted);
}
.aud-meta-item { display:inline-flex; align-items:center; gap:.3rem; }

/* Empty state */
.aud-empty { padding:4rem 1rem; text-align:center; }
.aud-empty-icon {
    width:3.5rem; height:3.5rem; border-radius:50%;
    background:rgba(156,163,175,.12);
    display:inline-flex; align-items:center; justify-content:center;
    margin-bottom:1rem; font-size:1.5rem; color:rgba(156,163,175,1);
}

/* Pagination */
.aud-pagination {
    padding:.875rem 1.5rem;
    background: rgba(156,163,175,.06);
    border-top: 1px solid var(--border-color);
    display:flex; align-items:center; justify-content:space-between;
    gap:1rem; flex-wrap:wrap;
}
.aud-page-btn {
    display:inline-flex; align-items:center; gap:.4rem; min-height:40px;
    padding:.5rem 1rem; border-radius:.625rem; font-size:.8rem; font-weight:600;
    background-color: var(--bg-card);
    border: 1.5px solid var(--border-color);
    color: var(--text-main); text-decoration:none;
    transition: border-color .2s, background .2s, transform .15s;
}
.aud-page-btn:hover { border-color: rgba(124,58,237,.5); background:rgba(124,58,237,.06); transform:translateY(-1px); }

/* ── Responsive ─────────────────────────────────────── */
@media (max-width:640px) {
    .aud-filter-grid { grid-template-columns:1fr; }
    .aud-filter-actions { flex-direction:row; }
    .aud-filter-btn { flex:1; justify-content:center; }
    .aud-row { padding:.75rem 1rem; gap:.75rem; }
    .aud-legend { padding:.75rem 1rem; }
    .aud-filter { padding:1rem 1.25rem; }
    .aud-pagination { flex-direction:column; align-items:stretch; text-align:center; }
    .aud-pagination > div { display:flex; gap:.5rem; }
    .aud-page-btn { flex:1; justify-content:center; }
}
@media (max-width:480px) {
    .aud-page-title { font-size:1.35rem; }
    .aud-row { gap:.625rem; }
    .aud-icon-wrap { width:1.75rem; height:1.75rem; }
    .aud-meta { gap:.5rem; }
}
</style>

<div class="aud-page" style="max-width:72rem;margin:0 auto;">

<!-- Header -->
<div class="aud-page-header">
    <div class="aud-page-header-left">
        <div class="aud-header-icon">
            <i class="fas fa-clipboard-list" style="color:#fff;font-size:1.15rem;"></i>
        </div>
        <div>
            <h1 class="aud-page-title">Audit-Logs</h1>
            <p class="aud-page-sub"><?php echo number_format($totalLogs); ?> Einträge insgesamt</p>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="aud-filter">
    <form method="GET" class="aud-filter-grid">
        <div>
            <label class="aud-filter-label">Aktion</label>
            <input type="text" name="action" class="aud-input"
                placeholder="z.B. login, create, update…"
                value="<?php echo htmlspecialchars($_GET['action'] ?? ''); ?>">
        </div>
        <div>
            <label class="aud-filter-label">Benutzer-ID</label>
            <input type="number" name="user_id" class="aud-input"
                placeholder="Benutzer-ID"
                value="<?php echo htmlspecialchars($_GET['user_id'] ?? ''); ?>">
        </div>
        <div class="aud-filter-actions">
            <button type="submit" class="aud-filter-btn">
                <i class="fas fa-search" style="margin-right:.4rem;"></i>Filtern
            </button>
            <a href="audit.php" class="aud-reset-btn" title="Filter zurücksetzen">
                <i class="fas fa-times"></i>
            </a>
        </div>
    </form>
</div>

<!-- Log list -->
<div class="aud-wrap">
    <?php if (empty($logs)): ?>
    <div class="aud-empty">
        <div class="aud-empty-icon"><i class="fas fa-clipboard"></i></div>
        <p style="font-size:1rem;font-weight:600;color:var(--text-main);margin:0 0 .35rem;">Keine Logs gefunden</p>
        <p style="font-size:.85rem;color:var(--text-muted);margin:0;">Versuche andere Filterkriterien</p>
    </div>
    <?php else: ?>

    <!-- Legend -->
    <div class="aud-legend">
        <span class="aud-legend-item"><span class="aud-dot" style="background:rgba(34,197,94,1);"></span>Erstellt / Login</span>
        <span class="aud-legend-item"><span class="aud-dot" style="background:rgba(234,179,8,1);"></span>Aktualisiert</span>
        <span class="aud-legend-item"><span class="aud-dot" style="background:rgba(239,68,68,1);"></span>Gelöscht / Fehlgeschlagen</span>
        <span class="aud-legend-item"><span class="aud-dot" style="background:rgba(139,92,246,1);"></span>Einladung</span>
        <span class="aud-legend-item"><span class="aud-dot" style="background:rgba(156,163,175,1);"></span>Sonstige</span>
    </div>

    <?php foreach ($logs as $log):
        $s = getActionStyle($log['action']);
    ?>
    <div class="aud-row">
        <div class="aud-icon-wrap" style="background:<?php echo $s['bg']; ?>;color:<?php echo $s['color']; ?>;">
            <i class="<?php echo $s['icon']; ?>"></i>
        </div>
        <div style="flex:1;min-width:0;">
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;margin-bottom:.3rem;">
                <span class="aud-badge"
                    style="background:<?php echo $s['bg']; ?>;color:<?php echo $s['color']; ?>;border-color:<?php echo $s['border']; ?>;">
                    <span class="aud-dot" style="background:<?php echo $s['dot']; ?>;flex-shrink:0;"></span>
                    <?php echo htmlspecialchars($log['action']); ?>
                </span>
                <?php if ($log['entity_type']): ?>
                <span style="font-size:.75rem;color:var(--text-muted);">
                    <?php echo htmlspecialchars($log['entity_type']); ?>
                    <?php if ($log['entity_id']): ?>
                    <span style="color:rgba(156,163,175,1);">#<?php echo (int)$log['entity_id']; ?></span>
                    <?php endif; ?>
                </span>
                <?php endif; ?>
            </div>

            <?php if (!empty($log['details']) && $log['details'] !== '-'): ?>
            <p style="font-size:.82rem;color:var(--text-main);margin:0 0 .3rem;word-break:break-all;"><?php echo htmlspecialchars($log['details']); ?></p>
            <?php endif; ?>

            <div class="aud-meta">
                <span class="aud-meta-item"><i class="fas fa-clock"></i><?php echo date('d.m.Y H:i:s', strtotime($log['timestamp'])); ?></span>
                <?php if ($log['user_id']): ?>
                <span class="aud-meta-item"><i class="fas fa-user"></i>ID: <?php echo (int)$log['user_id']; ?></span>
                <?php else: ?>
                <span class="aud-meta-item" style="font-style:italic;"><i class="fas fa-robot"></i>System</span>
                <?php endif; ?>
                <?php if (!empty($log['ip_address'])): ?>
                <span class="aud-meta-item"><i class="fas fa-network-wired"></i><?php echo htmlspecialchars($log['ip_address']); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <?php
    $qs = '';
    if (!empty($_GET['action']))  $qs .= '&action='  . urlencode($_GET['action']);
    if (!empty($_GET['user_id'])) $qs .= '&user_id=' . urlencode($_GET['user_id']);
    ?>
    <div class="aud-pagination">
        <span style="font-size:.82rem;color:var(--text-muted);">Seite <?php echo $page; ?> von <?php echo $totalPages; ?></span>
        <div style="display:flex;gap:.5rem;">
            <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page - 1; ?><?php echo $qs; ?>" class="aud-page-btn">
                <i class="fas fa-chevron-left" style="font-size:.7rem;"></i> Zurück
            </a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
            <a href="?page=<?php echo $page + 1; ?><?php echo $qs; ?>" class="aud-page-btn">
                Weiter <i class="fas fa-chevron-right" style="font-size:.7rem;"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
