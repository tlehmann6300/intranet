<?php
/**
 * Admin Inventory Dashboard
 * Shows all active rentals, pending inventory requests, and provides CSV export.
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/models/Inventory.php';
require_once __DIR__ . '/../../includes/services/EasyVereinInventory.php';
require_once __DIR__ . '/../../includes/database.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';

if (!Auth::check() || (!Auth::isBoard() && !Auth::hasRole(['alumni_finanz', 'alumni_vorstand']))) {
    header('Location: ../auth/login.php');
    exit;
}

// Read filter param (whitelist)
$allowedFilters = ['all', 'rented'];
$filter = in_array($_GET['filter'] ?? '', $allowedFilters) ? $_GET['filter'] : 'all';

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csvItems = Inventory::getAll();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="inventar_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Artikel', 'Kategorie', 'Gesamtbestand', 'Ausgeliehen', 'Verfügbar', 'Einheit'], ';');

    foreach ($csvItems as $item) {
        fputcsv($out, [
            sanitizeCsvValue($item['name']),
            sanitizeCsvValue($item['category_name'] ?? ''),
            $item['quantity'],
            $item['quantity'] - $item['available_quantity'],
            $item['available_quantity'],
            sanitizeCsvValue($item['unit']),
        ], ';');
    }

    fclose($out);
    exit;
}

// Fetch data
$checkedOutStats = Inventory::getCheckedOutStats();
$activeRentals   = array_filter($checkedOutStats['checkouts'], function($r) {
    return $r['status'] === 'rented';
});
$pendingRequests = Inventory::getPendingRequests();
$inventoryItems  = Inventory::getAll();

$toastSuccess = isset($_GET['toast_success']) ? htmlspecialchars($_GET['toast_success']) : '';
$toastError   = isset($_GET['toast_error'])   ? htmlspecialchars($_GET['toast_error'])   : '';

$csrfToken = CSRFHandler::getToken();

$title = 'Inventar-Dashboard - IBC Intranet';
ob_start();
?>

<style>
/* ═══════════════════════════════════════════════
   Inventar-Dashboard — vollständig responsive
   Breakpoints: 480 · 640 · 900 · 1200
═══════════════════════════════════════════════ */

@keyframes invSlideUp  { from { opacity:0; transform:translateY(16px) scale(.98); } to { opacity:1; transform:translateY(0) scale(1); } }
@keyframes invFadeIn   { from { opacity:0; } to { opacity:1; } }

.invd-page { animation: invSlideUp .4s cubic-bezier(.22,.68,0,1.2) both; }

/* ── Header ── */
.invd-page-header {
  display:flex; align-items:flex-start; justify-content:space-between;
  margin-bottom:1.5rem; gap:1rem; flex-wrap:wrap;
}
.invd-page-header-left { display:flex; align-items:center; gap:.875rem; min-width:0; }
.invd-page-header-actions { display:flex; gap:.5rem; flex-wrap:wrap; flex-shrink:0; }
@media (max-width:480px) {
  .invd-page-header { flex-direction:column; gap:.75rem; }
  .invd-page-header-actions { width:100%; }
  .invd-page-header-actions a { flex:1; justify-content:center; }
}

/* ── Stat Cards ── */
.invd-stat-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: .875rem;
  margin-bottom: 1.25rem;
}
@media (max-width:900px)  { .invd-stat-grid { grid-template-columns: repeat(2,1fr); } }
@media (max-width:480px)  { .invd-stat-grid { grid-template-columns: repeat(2,1fr); gap:.625rem; } }

.invd-stat {
  border-radius: 1rem;
  border: 1px solid var(--border-color);
  background-color: var(--bg-card);
  padding: 1.1rem 1rem;
  display: flex; align-items: center; gap: .875rem;
  transition: box-shadow .25s, transform .22s;
  animation: invSlideUp .42s cubic-bezier(.22,.68,0,1.2) both;
}
.invd-stat:hover { box-shadow:0 6px 20px rgba(0,0,0,.09); transform:translateY(-2px); }
.invd-stat:nth-child(1) { animation-delay:.04s; }
.invd-stat:nth-child(2) { animation-delay:.09s; }
.invd-stat:nth-child(3) { animation-delay:.14s; }
.invd-stat:nth-child(4) { animation-delay:.19s; }

.invd-stat-icon {
  width:2.5rem; height:2.5rem; border-radius:.625rem;
  display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:1.1rem;
}
@media (max-width:480px) { .invd-stat-icon { width:2.1rem; height:2.1rem; font-size:.95rem; } }

.invd-stat-val { font-size:1.6rem; font-weight:800; line-height:1.1; margin-bottom:.15rem; color:var(--text-main); }
.invd-stat-lbl { font-size:.75rem; color:var(--text-muted); font-weight:500; line-height:1.3; }
@media (max-width:480px) { .invd-stat-val { font-size:1.35rem; } .invd-stat-lbl { font-size:.7rem; } }

/* ── Filter Bar ── */
.invd-filter-bar {
  border-radius: 1rem;
  border: 1px solid var(--border-color);
  background-color: var(--bg-card);
  padding: .875rem 1.1rem;
  margin-bottom: 1.25rem;
  display: flex; align-items: center; gap: .875rem; flex-wrap: wrap;
  animation: invFadeIn .35s .2s both;
}
.invd-filter-select {
  padding: .5rem .875rem; border-radius: .625rem;
  border: 1px solid var(--border-color); background: var(--bg-body); color: var(--text-main);
  font-size: .875rem; outline: none; cursor: pointer;
  transition: border-color .2s, box-shadow .2s; min-height:38px;
}
.invd-filter-select:focus { border-color:rgba(124,58,237,.5); box-shadow:0 0 0 3px rgba(124,58,237,.1); }

/* ── Section Cards ── */
.invd-section {
  border-radius: 1rem;
  border: 1px solid var(--border-color);
  background-color: var(--bg-card);
  padding: 1.25rem;
  margin-bottom: 1.25rem; overflow: hidden;
  animation: invSlideUp .42s cubic-bezier(.22,.68,0,1.2) .2s both;
}
@media (max-width:480px) { .invd-section { padding:1rem .875rem; } }

.invd-section-title { display:flex; align-items:center; gap:.625rem; margin-bottom:1rem; flex-wrap:wrap; }
.invd-section-title h2 { font-size:1rem; font-weight:700; color:var(--text-main); margin:0; }

/* ── Tables ── */
.invd-table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.invd-table { width: 100%; border-collapse: collapse; font-size: .875rem; min-width:480px; }
.invd-table thead tr { border-bottom: 2px solid var(--border-color); background:rgba(124,58,237,.03); }
.invd-table th {
  padding: .6rem .875rem; font-weight: 700; font-size: .72rem;
  text-transform: uppercase; letter-spacing: .05em;
  color: var(--text-muted); white-space: nowrap; text-align: left;
}
.invd-table tbody tr {
  border-bottom: 1px solid var(--border-color);
  transition: background .15s, transform .15s;
}
.invd-table tbody tr:last-child { border-bottom: none; }
.invd-table tbody tr:hover { background: rgba(124,58,237,.04); }
.invd-table td { padding: .6rem .875rem; color: var(--text-main); vertical-align: middle; }

/* Mobile card-style table fallback */
@media (max-width:600px) {
  .invd-table-scroll { overflow-x:unset; }
  .invd-table, .invd-table tbody, .invd-table tr, .invd-table td { display:block; width:100%; }
  .invd-table thead { display:none; }
  .invd-table tbody tr {
    border-radius:.75rem; border:1px solid var(--border-color);
    background-color:var(--bg-body); margin-bottom:.5rem; padding:.625rem .875rem;
  }
  .invd-table tbody tr:hover { background:rgba(124,58,237,.04); transform:none; }
  .invd-table td {
    padding:.2rem 0; border:none; display:flex;
    align-items:flex-start; gap:.5rem; font-size:.82rem;
  }
  .invd-table td::before {
    content: attr(data-label);
    font-weight:700; font-size:.7rem; color:var(--text-muted);
    text-transform:uppercase; letter-spacing:.04em;
    min-width:5rem; padding-top:.1rem; flex-shrink:0;
  }
  .invd-table td[data-label=""] { display:none; }
}

/* ── Buttons ── */
.invd-btn-ok {
  display:inline-flex; align-items:center; gap:.3rem;
  padding:.4rem .8rem; font-size:.78rem; font-weight:600; border-radius:.5rem;
  background:rgba(34,197,94,.12); color:rgba(21,128,61,1); border:1px solid rgba(34,197,94,.3);
  cursor:pointer; transition:background .2s, transform .15s; min-height:34px;
}
.invd-btn-ok:hover { background:rgba(34,197,94,.22); transform:translateY(-1px); }
.invd-btn-rej {
  display:inline-flex; align-items:center; gap:.3rem;
  padding:.4rem .8rem; font-size:.78rem; font-weight:600; border-radius:.5rem;
  background:rgba(239,68,68,.1); color:rgba(185,28,28,1); border:1px solid rgba(239,68,68,.3);
  cursor:pointer; transition:background .2s, transform .15s; min-height:34px;
}
.invd-btn-rej:hover { background:rgba(239,68,68,.2); transform:translateY(-1px); }

.invd-csv-btn {
  display:inline-flex; align-items:center; gap:.45rem;
  padding:.55rem 1rem; border-radius:.75rem; min-height:40px;
  background:rgba(34,197,94,.12); color:rgba(21,128,61,1); border:1px solid rgba(34,197,94,.3);
  font-weight:600; font-size:.85rem; text-decoration:none; transition:background .2s, transform .15s;
}
.invd-csv-btn:hover { background:rgba(34,197,94,.22); transform:translateY(-1px); }
.invd-back-btn {
  display:inline-flex; align-items:center; gap:.45rem;
  padding:.55rem 1rem; border-radius:.75rem; min-height:40px;
  background:var(--bg-body); color:var(--text-muted); border:1px solid var(--border-color);
  font-weight:600; font-size:.85rem; text-decoration:none; transition:background .2s;
}
.invd-back-btn:hover { background:rgba(0,0,0,.04); }

/* ── Toast ── */
#invd-toast {
  position:fixed; bottom:1.25rem; right:1.25rem; z-index:9999;
  display:flex; align-items:center; gap:.7rem;
  padding:.75rem 1.2rem; border-radius:.875rem;
  box-shadow:0 8px 28px rgba(0,0,0,.22); color:#fff;
  font-size:.85rem; font-weight:500;
  opacity:0; pointer-events:none; transform:translateY(10px) scale(.96);
  transition:opacity .28s, transform .28s cubic-bezier(.22,.68,0,1.2);
  min-width:180px; max-width:320px;
}
#invd-toast.open { opacity:1; pointer-events:auto; transform:translateY(0) scale(1); }
@media (max-width:480px) {
  #invd-toast { left:1rem; right:1rem; bottom:1rem; max-width:unset; }
}

/* ── Empty state ── */
.invd-empty { text-align:center; padding:2.5rem 1rem; color:var(--text-muted); }
.invd-empty i { font-size:2.5rem; margin-bottom:.625rem; display:block; opacity:.25; }
.invd-empty p { font-size:.875rem; margin:0; }

/* ── Hide helpers ── */
@media (max-width:1023px) { .invd-hide-lg { display:none !important; } }
@media (max-width:767px)  { .invd-hide-md { display:none !important; } }
@media (max-width:600px)  { .invd-show-mobile { display:block !important; } }
</style>

<div class="invd-page">

<!-- Page Header -->
<div class="invd-page-header">
  <div class="invd-page-header-left">
    <div style="width:3rem;height:3rem;border-radius:.875rem;background:linear-gradient(135deg,rgba(124,58,237,1),rgba(99,102,241,1));display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(124,58,237,.4);flex-shrink:0;">
      <i class="fas fa-boxes" style="color:#fff;font-size:1.25rem;"></i>
    </div>
    <div>
      <h1 style="font-size:1.55rem;font-weight:800;color:var(--text-main);margin:0 0 .15rem;line-height:1.2;">Inventar-Dashboard</h1>
      <p style="color:var(--text-muted);margin:0;font-size:.85rem;">Übersicht aller aktiven Ausleihen und Anfragen</p>
    </div>
  </div>
  <div class="invd-page-header-actions">
    <a href="?export=csv&filter=<?php echo urlencode($filter); ?>" class="invd-csv-btn">
      <i class="fas fa-file-csv"></i>CSV Export
    </a>
    <a href="../inventory/index.php" class="invd-back-btn">
      <i class="fas fa-arrow-left"></i>Inventar
    </a>
  </div>
</div>

<!-- Toast -->
<div id="invd-toast">
  <i id="invd-toast-icon" class="fas fa-check-circle"></i>
  <span id="invd-toast-msg"></span>
</div>

<!-- Stat Cards -->
<div class="invd-stat-grid">
  <div class="invd-stat">
    <div class="invd-stat-icon" style="background:rgba(124,58,237,.12);">
      <i class="fas fa-hand-holding-box" style="color:rgba(109,40,217,1);font-size:1.2rem;"></i>
    </div>
    <div>
      <div class="invd-stat-val"><?php echo count($activeRentals); ?></div>
      <div class="invd-stat-lbl">Aktive Ausleihen</div>
    </div>
  </div>
  <div class="invd-stat">
    <div class="invd-stat-icon" style="background:rgba(59,130,246,.12);">
      <i class="fas fa-cubes" style="color:rgba(37,99,235,1);font-size:1.2rem;"></i>
    </div>
    <div>
      <div class="invd-stat-val"><?php echo $checkedOutStats['total_items_out']; ?></div>
      <div class="invd-stat-lbl">Artikel ausgeliehen</div>
    </div>
  </div>
  <div class="invd-stat">
    <div class="invd-stat-icon" style="background:<?php echo count($pendingRequests) > 0 ? 'rgba(234,179,8,.12)' : 'rgba(156,163,175,.1)'; ?>">
      <i class="fas fa-clock" style="color:<?php echo count($pendingRequests) > 0 ? 'rgba(161,98,7,1)' : 'rgba(156,163,175,1)'; ?>;font-size:1.2rem;"></i>
    </div>
    <div>
      <div class="invd-stat-val" style="color:<?php echo count($pendingRequests) > 0 ? 'rgba(161,98,7,1)' : 'var(--text-main)'; ?>;"><?php echo count($pendingRequests); ?></div>
      <div class="invd-stat-lbl">Ausstehende Anfragen</div>
    </div>
  </div>
  <div class="invd-stat">
    <div class="invd-stat-icon" style="background:<?php echo $checkedOutStats['overdue'] > 0 ? 'rgba(239,68,68,.12)' : 'rgba(156,163,175,.1)'; ?>">
      <i class="fas fa-exclamation-triangle" style="color:<?php echo $checkedOutStats['overdue'] > 0 ? 'rgba(185,28,28,1)' : 'rgba(156,163,175,1)'; ?>;font-size:1.2rem;"></i>
    </div>
    <div>
      <div class="invd-stat-val" style="color:<?php echo $checkedOutStats['overdue'] > 0 ? 'rgba(185,28,28,1)' : 'var(--text-main)'; ?>;"><?php echo $checkedOutStats['overdue']; ?></div>
      <div class="invd-stat-lbl">Überfällig</div>
    </div>
  </div>
</div>

<!-- Filter Bar -->
<div class="invd-filter-bar">
  <i class="fas fa-filter" style="color:rgba(124,58,237,1);flex-shrink:0;"></i>
  <span style="font-size:.875rem;font-weight:600;color:var(--text-muted);">Filter:</span>
  <form method="GET" action="" style="display:flex;align-items:center;gap:.625rem;flex-wrap:wrap;">
    <select id="filter" name="filter" onchange="this.form.submit()" class="invd-filter-select">
      <option value="all"<?php echo $filter === 'all' ? ' selected' : ''; ?>>Alle anzeigen</option>
      <option value="rented"<?php echo $filter === 'rented' ? ' selected' : ''; ?>>Aktiv verliehen</option>
    </select>
    <?php if ($filter !== 'all'): ?>
    <a href="?" style="font-size:.8rem;color:var(--text-muted);text-decoration:none;display:flex;align-items:center;gap:.3rem;">
      <i class="fas fa-times"></i>Filter zurücksetzen
    </a>
    <?php endif; ?>
  </form>
</div>

<!-- Ausstehende Anfragen -->
<div class="invd-section">
  <div class="invd-section-title">
    <div style="width:2.25rem;height:2.25rem;border-radius:.625rem;background:rgba(234,179,8,.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
      <i class="fas fa-clock" style="color:rgba(161,98,7,1);"></i>
    </div>
    <h2>Ausstehende Anfragen
      <?php if (count($pendingRequests) > 0): ?>
      <span id="pending-count-badge"
            style="margin-left:.5rem;padding:.15rem .6rem;border-radius:999px;font-size:.75rem;background:rgba(234,179,8,.15);color:rgba(161,98,7,1);border:1px solid rgba(234,179,8,.35);">
        <?php echo count($pendingRequests); ?>
      </span>
      <?php endif; ?>
    </h2>
  </div>

  <?php if (empty($pendingRequests)): ?>
  <div class="invd-empty">
    <i class="fas fa-check-circle"></i>
    Keine ausstehenden Anfragen
  </div>
  <?php else: ?>
  <div class="invd-table-scroll" id="pending-table-wrap">
    <table class="invd-table" id="pending-requests-table">
      <thead>
        <tr>
          <th>Antragsteller</th>
          <th>Artikel</th>
          <th>Menge</th>
          <th>Zeitraum</th>
          <th class="invd-hide-lg">Zweck</th>
          <th>Status</th>
          <th>Aktionen</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pendingRequests as $req): ?>
        <tr id="pending-row-<?php echo (int)$req['id']; ?>">
          <td data-label="Antragsteller">
            <div style="font-weight:600;font-size:.875rem;">
              <?php echo htmlspecialchars($req['user_name'] ?? $req['user_email'] ?? 'Unbekannt'); ?>
            </div>
            <?php if (!empty($req['user_email']) && $req['user_email'] !== $req['user_name']): ?>
            <div style="font-size:.75rem;color:var(--text-muted);"><?php echo htmlspecialchars($req['user_email']); ?></div>
            <?php endif; ?>
          </td>
          <td data-label="Artikel" style="font-weight:600;"><?php echo htmlspecialchars($req['item_name'] ?? '#' . $req['inventory_object_id']); ?></td>
          <td data-label="Menge"><?php echo (int)$req['quantity']; ?></td>
          <td data-label="Zeitraum" style="white-space:nowrap;color:var(--text-muted);">
            <?php echo date('d.m.Y', strtotime($req['start_date'])); ?> &ndash;
            <?php echo date('d.m.Y', strtotime($req['end_date'])); ?>
          </td>
          <td data-label="Zweck" class="invd-hide-lg" style="color:var(--text-muted);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($req['purpose'] ?? ''); ?>">
            <?php echo htmlspecialchars($req['purpose'] ?? '—'); ?>
          </td>
          <td data-label="Status">
            <span style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;border-radius:999px;font-size:.75rem;font-weight:600;background:rgba(234,179,8,.12);color:rgba(161,98,7,1);border:1px solid rgba(234,179,8,.35);">
              <i class="fas fa-clock" style="font-size:.65rem;"></i>Ausstehend
            </span>
          </td>
          <td data-label="Aktionen">
            <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
              <button onclick="handleRequest(<?php echo (int)$req['id']; ?>, 'approve')" class="invd-btn-ok">
                <i class="fas fa-check"></i>Genehmigen
              </button>
              <button onclick="handleRequest(<?php echo (int)$req['id']; ?>, 'reject')" class="invd-btn-rej">
                <i class="fas fa-times"></i>Ablehnen
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Aktive Ausleihen -->
<div class="invd-section">
  <div class="invd-section-title">
    <div style="width:2.25rem;height:2.25rem;border-radius:.625rem;background:rgba(59,130,246,.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
      <i class="fas fa-clipboard-list" style="color:rgba(37,99,235,1);"></i>
    </div>
    <h2>Aktive Ausleihen</h2>
  </div>

  <?php if (empty($activeRentals)): ?>
  <div class="invd-empty">
    <i class="fas fa-inbox"></i>
    Keine aktiven Ausleihen vorhanden
  </div>
  <?php else: ?>
  <div class="invd-table-scroll">
    <table class="invd-table">
      <thead>
        <tr>
          <th>Benutzer</th>
          <th>Artikel</th>
          <th>Menge</th>
          <th class="invd-hide-lg">Ausgeliehen am</th>
          <th>Rückgabe bis</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($activeRentals as $rental): ?>
        <?php $isOverdue = !empty($rental['expected_return']) && strtotime($rental['expected_return']) < time(); ?>
        <tr style="<?php echo $isOverdue ? 'background:rgba(239,68,68,.04);' : ''; ?>">
          <td>
            <div style="font-weight:600;"><?php echo htmlspecialchars($rental['borrower_name'] ?? $rental['borrower_email'] ?? 'Unbekannt'); ?></div>
            <?php if (!empty($rental['borrower_name']) && $rental['borrower_name'] !== $rental['borrower_email'] && $rental['borrower_name'] !== 'Unbekannt'): ?>
            <div style="font-size:.75rem;color:var(--text-muted);"><?php echo htmlspecialchars($rental['borrower_email']); ?></div>
            <?php endif; ?>
          </td>
          <td>
            <a href="../inventory/view.php?id=<?php echo $rental['item_id']; ?>"
               style="font-weight:600;color:rgba(109,40,217,1);text-decoration:none;">
              <?php echo htmlspecialchars($rental['item_name']); ?>
            </a>
          </td>
          <td style="font-weight:600;"><?php echo $rental['amount']; ?> <?php echo htmlspecialchars($rental['unit']); ?></td>
          <td class="invd-hide-lg" style="color:var(--text-muted);white-space:nowrap;">
            <?php echo date('d.m.Y H:i', strtotime($rental['rented_at'])); ?>
          </td>
          <td>
            <?php if (!empty($rental['expected_return'])): ?>
            <span style="color:<?php echo $isOverdue ? 'rgba(185,28,28,1)' : 'var(--text-main)'; ?>;font-weight:<?php echo $isOverdue ? '700' : '400'; ?>;white-space:nowrap;">
              <?php echo date('d.m.Y', strtotime($rental['expected_return'])); ?>
            </span>
            <?php if ($isOverdue): ?>
            <div style="font-size:.75rem;color:rgba(185,28,28,1);font-weight:600;">Überfällig!</div>
            <?php endif; ?>
            <?php else: ?><span style="color:var(--text-muted);">—</span><?php endif; ?>
          </td>
          <td>
            <?php if ($isOverdue): ?>
            <span style="display:inline-block;padding:.2rem .65rem;border-radius:999px;font-size:.75rem;font-weight:600;background:rgba(239,68,68,.12);color:rgba(185,28,28,1);border:1px solid rgba(239,68,68,.35);">Überfällig</span>
            <?php else: ?>
            <span style="display:inline-block;padding:.2rem .65rem;border-radius:999px;font-size:.75rem;font-weight:600;background:rgba(34,197,94,.12);color:rgba(21,128,61,1);border:1px solid rgba(34,197,94,.35);">Aktiv</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Bestandsliste -->
<div class="invd-section">
  <div class="invd-section-title">
    <div style="width:2.25rem;height:2.25rem;border-radius:.625rem;background:rgba(34,197,94,.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
      <i class="fas fa-warehouse" style="color:rgba(21,128,61,1);"></i>
    </div>
    <h2>Bestandsliste</h2>
  </div>

  <?php if (empty($inventoryItems)): ?>
  <div class="invd-empty">
    <i class="fas fa-inbox"></i>
    Keine Artikel im Inventar vorhanden
  </div>
  <?php else: ?>
  <div class="invd-table-scroll">
    <table class="invd-table">
      <thead>
        <tr>
          <th>Artikel</th>
          <th>Kategorie</th>
          <th>Gesamtbestand</th>
          <th>Verliehen</th>
          <th>Verfügbar</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($inventoryItems as $item): ?>
        <?php $available = (int)$item['available_quantity']; ?>
        <tr>
          <td>
            <a href="../inventory/view.php?id=<?php echo $item['id']; ?>"
               style="font-weight:600;color:rgba(109,40,217,1);text-decoration:none;">
              <?php echo htmlspecialchars($item['name']); ?>
            </a>
          </td>
          <td>
            <?php if (!empty($item['category_name'])): ?>
            <span style="display:inline-block;padding:.2rem .65rem;border-radius:.5rem;font-size:.75rem;font-weight:600;background-color:<?php echo htmlspecialchars($item['category_color'] ?? '#e5e7eb'); ?>20;color:<?php echo htmlspecialchars($item['category_color'] ?? '#374151'); ?>;">
              <?php echo htmlspecialchars($item['category_name']); ?>
            </span>
            <?php else: ?><span style="color:var(--text-muted);">—</span><?php endif; ?>
          </td>
          <td style="font-weight:600;"><?php echo $item['quantity']; ?> <?php echo htmlspecialchars($item['unit']); ?></td>
          <td style="color:var(--text-muted);"><?php echo (int)$item['quantity'] - (int)$item['available_quantity']; ?></td>
          <td style="font-weight:700;color:<?php echo $available <= 0 ? 'rgba(185,28,28,1)' : ($available <= ($item['min_stock'] ?? 0) ? 'rgba(161,98,7,1)' : 'rgba(21,128,61,1)'); ?>;">
            <?php echo $available; ?> <?php echo htmlspecialchars($item['unit']); ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

</div><!-- .invd-page -->

<?php if ($toastSuccess !== ''): ?>
<script>document.addEventListener('DOMContentLoaded', () => showInvdToast(<?php echo json_encode($toastSuccess); ?>, 'success'));</script>
<?php endif; ?>
<?php if ($toastError !== ''): ?>
<script>document.addEventListener('DOMContentLoaded', () => showInvdToast(<?php echo json_encode($toastError); ?>, 'error'));</script>
<?php endif; ?>

<script>
var CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;

function showInvdToast(message, type) {
    const toast     = document.getElementById('invd-toast');
    const toastMsg  = document.getElementById('invd-toast-msg');
    const toastIcon = document.getElementById('invd-toast-icon');
    toastMsg.textContent = message;
    if (type === 'success') {
        toast.style.background = 'rgba(22,163,74,1)';
        toastIcon.className    = 'fas fa-check-circle';
    } else {
        toast.style.background = 'rgba(220,38,38,1)';
        toastIcon.className    = 'fas fa-exclamation-circle';
    }
    toast.classList.add('open');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => toast.classList.remove('open'), 4000);
}

// expose as showToast for URL-triggered calls
function showToast(msg, type) { showInvdToast(msg, type); }

function handleRequest(requestId, action) {
    var label = action === 'approve' ? 'genehmigen' : 'ablehnen';
    if (!confirm('Möchten Sie diese Anfrage wirklich ' + label + '?')) return;

    var row = document.getElementById('pending-row-' + requestId);
    if (row) { row.style.opacity = '.5'; row.style.pointerEvents = 'none'; }

    fetch('/api/rental_request_action.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ action: action, request_id: requestId, csrf_token: CSRF_TOKEN })
    })
    .then(r => r.json())
    .then(function(data) {
        if (data.success) {
            showInvdToast(data.message || (action === 'approve' ? 'Anfrage genehmigt' : 'Anfrage abgelehnt'), 'success');
            if (row) row.remove();
            // Update badge count
            var pendingRows = document.querySelectorAll('#pending-requests-table tbody tr');
            var badge = document.getElementById('pending-count-badge');
            if (badge) {
                if (pendingRows.length === 0) badge.remove();
                else badge.textContent = pendingRows.length;
            }
            if (pendingRows.length === 0) {
                var wrap = document.getElementById('pending-table-wrap');
                if (wrap) {
                    wrap.innerHTML = '<div class="invd-empty"><i class="fas fa-check-circle"></i>Keine ausstehenden Anfragen</div>';
                }
            }
        } else {
            showInvdToast(data.message || 'Fehler bei der Verarbeitung', 'error');
            if (row) { row.style.opacity = '1'; row.style.pointerEvents = ''; }
        }
    })
    .catch(function() {
        showInvdToast('Netzwerkfehler. Bitte erneut versuchen.', 'error');
        if (row) { row.style.opacity = '1'; row.style.pointerEvents = ''; }
    });
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
