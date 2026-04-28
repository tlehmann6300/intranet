<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/EventDocumentation.php';
require_once __DIR__ . '/../../src/Database.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user     = Auth::user();
$userRole = $_SESSION['user_role'] ?? 'mitglied';

$allowedDocRoles = array_merge(Auth::BOARD_ROLES, ['alumni_vorstand']);
if (!in_array($userRole, $allowedDocRoles)) {
    header('Location: ../events/index.php');
    exit;
}

$allDocs = EventDocumentation::getAllWithEvents();

// Aggregate totals
$totalEvents  = count($allDocs);
$totalSellers = 0;
$totalSales   = 0.0;
foreach ($allDocs as $doc) {
    if (!empty($doc['sellers_data'])) $totalSellers += count($doc['sellers_data']);
    if (!empty($doc['sales_data'])) {
        foreach ($doc['sales_data'] as $sale) $totalSales += floatval($sale['amount'] ?? 0);
    }
}

$title = 'Event-Statistiken - IBC Intranet';
ob_start();
?>

<style>
/* ── Event-Statistiken ──────────────────────────────── */
@keyframes evsSlideUp {
    from { opacity:0; transform:translateY(18px) scale(.98); }
    to   { opacity:1; transform:translateY(0)    scale(1);   }
}
.evs-page { animation: evsSlideUp .4s cubic-bezier(.22,.68,0,1.2) both; }

/* Page header */
.evs-page-header {
    display:flex; flex-wrap:wrap; align-items:flex-start;
    justify-content:space-between; gap:1rem; margin-bottom:1.75rem;
}
.evs-page-header-left { display:flex; align-items:center; gap:.875rem; min-width:0; }
.evs-header-icon {
    width:3rem; height:3rem; border-radius:.875rem; flex-shrink:0;
    background:linear-gradient(135deg,rgba(124,58,237,1),rgba(99,102,241,1));
    box-shadow:0 4px 14px rgba(124,58,237,.35);
    display:flex; align-items:center; justify-content:center;
}
.evs-page-title { font-size:1.6rem; font-weight:800; color:var(--text-main); margin:0; line-height:1.2; }
.evs-page-sub   { font-size:.85rem; color:var(--text-muted); margin:.2rem 0 0; }

/* Back link */
.evs-back {
    display:inline-flex; align-items:center; gap:.5rem;
    padding:.6rem 1.1rem; border-radius:.75rem; font-size:.85rem; font-weight:600;
    background:rgba(156,163,175,.1); color:var(--text-muted);
    border:1.5px solid var(--border-color); text-decoration:none;
    transition:background .2s, color .2s, border-color .2s; white-space:nowrap;
    min-height:42px;
}
.evs-back:hover { background:rgba(124,58,237,.08); color:rgba(124,58,237,1); border-color:rgba(124,58,237,.3); }

/* Stat grid */
.evs-stat-grid {
    display:grid; grid-template-columns:repeat(3,1fr);
    gap:1rem; margin-bottom:1.5rem;
}
@media (max-width:640px) { .evs-stat-grid { grid-template-columns:repeat(2,1fr); gap:.75rem; } }
@media (max-width:400px) { .evs-stat-grid { grid-template-columns:1fr; } }

.evs-stat {
    border-radius:1rem; padding:1.25rem;
    background-color:var(--bg-card);
    border:1px solid var(--border-color);
    box-shadow:0 2px 10px rgba(0,0,0,.06);
    display:flex; align-items:center; justify-content:space-between;
    animation:evsSlideUp .4s cubic-bezier(.22,.68,0,1.2) both;
    transition:box-shadow .2s, transform .2s;
}
.evs-stat:hover { box-shadow:0 6px 20px rgba(0,0,0,.09); transform:translateY(-2px); }
.evs-stat:nth-child(1) { animation-delay:.05s; }
.evs-stat:nth-child(2) { animation-delay:.10s; }
.evs-stat:nth-child(3) { animation-delay:.15s; }

.evs-stat-icon { font-size:1.9rem; opacity:.18; }

/* Doc cards */
.evs-doc-card {
    background-color:var(--bg-card);
    border:1px solid var(--border-color);
    border-radius:1rem; overflow:hidden;
    box-shadow:0 2px 12px rgba(0,0,0,.06);
    margin-bottom:1.25rem;
    animation:evsSlideUp .4s cubic-bezier(.22,.68,0,1.2) both;
    transition:box-shadow .25s, transform .2s;
}
.evs-doc-card:hover { box-shadow:0 6px 22px rgba(124,58,237,.1); transform:translateY(-2px); }
.evs-doc-card:nth-child(1) { animation-delay:.06s; }
.evs-doc-card:nth-child(2) { animation-delay:.11s; }
.evs-doc-card:nth-child(3) { animation-delay:.16s; }
.evs-doc-card:nth-child(4) { animation-delay:.21s; }
.evs-doc-card:nth-child(n+5) { animation-delay:.26s; }

.evs-doc-head {
    padding:1rem 1.5rem;
    border-bottom:1px solid var(--border-color);
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.75rem;
}

/* View button */
.evs-view-btn {
    display:inline-flex; align-items:center; gap:.4rem;
    padding:.5rem 1rem; border-radius:.625rem; font-size:.8rem; font-weight:600;
    background:linear-gradient(135deg,rgba(124,58,237,1),rgba(99,102,241,1));
    color:#fff; text-decoration:none; border:none; cursor:pointer;
    transition:opacity .2s, transform .15s; min-height:38px;
}
.evs-view-btn:hover { opacity:.88; transform:translateY(-1px); }

.evs-section { padding:1.1rem 1.5rem; border-top:1px solid var(--border-color); }
.evs-section-title {
    font-size:.9rem; font-weight:700; color:var(--text-main);
    margin:0 0 .875rem; display:flex; align-items:center; gap:.5rem;
}

/* Table */
.evs-table-wrap { overflow-x:auto; width:100%; }
.evs-table { width:100%; border-collapse:collapse; }
.evs-table thead tr { background:rgba(124,58,237,.05); border-bottom:1px solid var(--border-color); }
.evs-table th { padding:.65rem 1rem; text-align:left; font-size:.7rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.05em; }
.evs-table td { padding:.75rem 1rem; font-size:.85rem; color:var(--text-main); border-bottom:1px solid var(--border-color); vertical-align:middle; }
.evs-table tbody tr:last-child td { border-bottom:none; }
.evs-table tbody tr { transition:background .15s; }
.evs-table tbody tr:hover { background:rgba(124,58,237,.04); }

/* Sales grid */
.evs-sales-grid {
    display:grid; grid-template-columns:repeat(3,1fr); gap:.75rem;
}
@media (max-width:640px) { .evs-sales-grid { grid-template-columns:repeat(2,1fr); } }
@media (max-width:400px) { .evs-sales-grid { grid-template-columns:1fr; } }

.evs-sale-item {
    padding:.875rem 1rem; border-radius:.75rem;
    background:rgba(124,58,237,.07); border:1px solid rgba(124,58,237,.15);
    transition:background .2s;
}
.evs-sale-item:hover { background:rgba(124,58,237,.12); }

/* Calc link */
.evs-calc-link {
    display:inline-flex; align-items:center; gap:.45rem;
    padding:.75rem 1rem; border-radius:.75rem; font-size:.875rem; font-weight:600;
    background:rgba(37,99,235,.07); border:1.5px solid rgba(37,99,235,.2);
    color:rgba(37,99,235,1); text-decoration:none;
    transition:background .2s, transform .15s; word-break:break-all;
}
.evs-calc-link:hover { background:rgba(37,99,235,.14); transform:translateY(-1px); }

/* Empty state */
.evs-empty { padding:4rem 1rem; text-align:center; }
.evs-empty-icon {
    width:3.5rem; height:3.5rem; border-radius:50%;
    background:rgba(156,163,175,.12);
    display:inline-flex; align-items:center; justify-content:center;
    margin-bottom:.875rem; font-size:1.5rem; color:rgba(156,163,175,1);
}

/* Mobile table cards */
@media (max-width:640px) {
    .evs-table, .evs-table thead, .evs-table tbody, .evs-table th, .evs-table td, .evs-table tr { display:block; }
    .evs-table thead { display:none; }
    .evs-table td { border-bottom:none; padding:.35rem .875rem; display:flex; justify-content:space-between; align-items:baseline; gap:.5rem; flex-wrap:wrap; }
    .evs-table td::before { content:attr(data-label); font-size:.7rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; flex-shrink:0; }
    .evs-table tbody tr { border-bottom:1px solid var(--border-color); padding:.25rem 0; }
    .evs-doc-head { flex-direction:column; align-items:flex-start; }
    .evs-view-btn { width:100%; justify-content:center; }
}
@media (max-width:480px) {
    .evs-page-header { flex-direction:column; }
    .evs-back { width:100%; justify-content:center; }
    .evs-page-title { font-size:1.35rem; }
}
</style>

<div class="evs-page" style="max-width:72rem;margin:0 auto;">

<!-- Header -->
<div class="evs-page-header">
    <div class="evs-page-header-left">
        <div class="evs-header-icon"><i class="fas fa-chart-bar" style="color:#fff;font-size:1.1rem;"></i></div>
        <div>
            <h1 class="evs-page-title">Event-Statistiken</h1>
            <p class="evs-page-sub">Übersicht aller Verkäufer und Statistiken vergangener Events</p>
        </div>
    </div>
    <a href="../events/index.php" class="evs-back">
        <i class="fas fa-arrow-left"></i>Zur Übersicht
    </a>
</div>

<?php if (empty($allDocs)): ?>
<div class="evs-empty" style="background-color:var(--bg-card);border:1px solid var(--border-color);border-radius:1rem;">
    <div class="evs-empty-icon"><i class="fas fa-chart-line"></i></div>
    <p style="font-size:1rem;font-weight:600;color:var(--text-main);margin:0 0 .35rem;">Noch keine Statistiken vorhanden</p>
    <p style="font-size:.85rem;color:var(--text-muted);margin:0;">Erstelle Event-Dokumentationen, um hier Statistiken zu sehen.</p>
</div>
<?php else: ?>

<!-- Summary stats -->
<div class="evs-stat-grid">
    <div class="evs-stat">
        <div>
            <p style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin:0 0 .3rem;">Events dokumentiert</p>
            <p style="font-size:2rem;font-weight:800;color:rgba(124,58,237,1);margin:0;"><?php echo $totalEvents; ?></p>
        </div>
        <i class="fas fa-calendar-check evs-stat-icon" style="color:rgba(124,58,237,1);"></i>
    </div>
    <div class="evs-stat">
        <div>
            <p style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin:0 0 .3rem;">Verkäufer-Einträge</p>
            <p style="font-size:2rem;font-weight:800;color:rgba(37,99,235,1);margin:0;"><?php echo $totalSellers; ?></p>
        </div>
        <i class="fas fa-user-tie evs-stat-icon" style="color:rgba(37,99,235,1);"></i>
    </div>
    <div class="evs-stat">
        <div>
            <p style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin:0 0 .3rem;">Gesamtumsatz</p>
            <p style="font-size:2rem;font-weight:800;color:rgba(22,163,74,1);margin:0;"><?php echo number_format($totalSales, 2, ',', '.'); ?>€</p>
        </div>
        <i class="fas fa-euro-sign evs-stat-icon" style="color:rgba(22,163,74,1);"></i>
    </div>
</div>

<!-- Event docs -->
<?php foreach ($allDocs as $doc): ?>
<div class="evs-doc-card">
    <div class="evs-doc-head">
        <div>
            <h2 style="font-size:1.1rem;font-weight:700;color:var(--text-main);margin:0 0 .25rem;"><?php echo htmlspecialchars($doc['event_title']); ?></h2>
            <p style="font-size:.82rem;color:var(--text-muted);margin:0;display:flex;align-items:center;gap:.4rem;">
                <i class="fas fa-calendar" style="font-size:.7rem;"></i><?php echo date('d.m.Y', strtotime($doc['start_time'])); ?>
            </p>
        </div>
        <a href="../events/view.php?id=<?php echo $doc['event_id']; ?>" class="evs-view-btn">
            <i class="fas fa-eye"></i>Event ansehen
        </a>
    </div>

    <!-- Sellers -->
    <?php if (!empty($doc['sellers_data'])): ?>
    <div class="evs-section">
        <h3 class="evs-section-title">
            <i class="fas fa-user-tie" style="color:rgba(37,99,235,1);"></i>Verkäufer
        </h3>
        <div class="evs-table-wrap">
        <table class="evs-table">
            <thead><tr>
                <th>Verkäufer/Stand</th><th>Artikel</th><th>Menge</th><th>Umsatz</th>
            </tr></thead>
            <tbody>
            <?php foreach ($doc['sellers_data'] as $seller): ?>
            <tr>
                <td data-label="Verkäufer/Stand" style="font-weight:600;"><?php echo htmlspecialchars($seller['seller_name'] ?? '—'); ?></td>
                <td data-label="Artikel" style="color:var(--text-muted);"><?php echo htmlspecialchars($seller['items'] ?? '—'); ?></td>
                <td data-label="Menge" style="color:var(--text-muted);"><?php echo htmlspecialchars($seller['quantity'] ?? '—'); ?></td>
                <td data-label="Umsatz" style="color:var(--text-muted);"><?php echo htmlspecialchars($seller['revenue'] ?? '—'); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Sales Data -->
    <?php if (!empty($doc['sales_data'])): ?>
    <div class="evs-section">
        <h3 class="evs-section-title">
            <i class="fas fa-chart-line" style="color:rgba(124,58,237,1);"></i>Verkaufsdaten
        </h3>
        <div class="evs-sales-grid">
            <?php foreach ($doc['sales_data'] as $sale): ?>
            <div class="evs-sale-item">
                <p style="font-size:.85rem;font-weight:600;color:var(--text-main);margin:0 0 .3rem;"><?php echo htmlspecialchars($sale['label'] ?? 'Unbenannt'); ?></p>
                <p style="font-size:1.4rem;font-weight:800;color:rgba(124,58,237,1);margin:0;"><?php echo number_format(floatval($sale['amount'] ?? 0), 2, ',', '.'); ?>€</p>
                <?php if (!empty($sale['date'])): ?>
                <p style="font-size:.72rem;color:var(--text-muted);margin:.3rem 0 0;"><?php echo date('d.m.Y', strtotime($sale['date'])); ?></p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Calculations link -->
    <?php if (!empty($doc['calculation_link'])): ?>
    <div class="evs-section">
        <h3 class="evs-section-title">
            <i class="fas fa-calculator" style="color:rgba(22,163,74,1);"></i>Kalkulationen
        </h3>
        <a href="<?php echo htmlspecialchars($doc['calculation_link']); ?>" target="_blank" rel="noopener noreferrer" class="evs-calc-link">
            <i class="fas fa-external-link-alt" style="font-size:.75rem;"></i>
            <?php echo htmlspecialchars($doc['calculation_link']); ?>
        </a>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>

</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
