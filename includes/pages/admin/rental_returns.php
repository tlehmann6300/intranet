<?php
/**
 * Admin – Inventarverwaltung: Ausstehende Anfragen & Aktive Ausleihen
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/models/Inventory.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/helpers.php';

if (!Auth::check() || (!Auth::isBoard() && !Auth::hasRole(['alumni_finanz', 'alumni_vorstand']))) {
    header('Location: ../auth/login.php');
    exit;
}

$readOnly = !Auth::isBoard();

function enrichWithUsers(array $rows): array {
    if (empty($rows)) return $rows;
    $userDb = null;
    try { $userDb = Database::getUserDB(); } catch (Exception $e) { error_log('rental_returns: cannot connect to user DB: ' . $e->getMessage()); }

    $easyvereinIds = array_filter(array_unique(array_column($rows, 'easyverein_member_id')), fn($v) => $v !== null && $v !== '');
    $usersByEasyvereinId = [];
    if ($userDb !== null && !empty($easyvereinIds)) {
        try {
            $ph = implode(',', array_fill(0, count($easyvereinIds), '?'));
            $stmt = $userDb->prepare("SELECT easyverein_id, email, first_name, last_name FROM users WHERE easyverein_id IN ({$ph})");
            $stmt->execute(array_values($easyvereinIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $usersByEasyvereinId[$row['easyverein_id']] = $row;
        } catch (Exception $e) { error_log('rental_returns: EasyVerein-based user lookup failed: ' . $e->getMessage()); }
    }

    $userIds = array_unique(array_column($rows, 'user_id'));
    $usersById = [];
    if ($userDb !== null) {
        try {
            $ph = implode(',', array_fill(0, count($userIds), '?'));
            $stmt = $userDb->prepare("SELECT id, email, first_name, last_name FROM users WHERE id IN ({$ph})");
            $stmt->execute($userIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $usersById[(int)$row['id']] = $row;
        } catch (Exception $e) { error_log('rental_returns: user lookup failed: ' . $e->getMessage()); }
    }

    foreach ($rows as &$row) {
        $u = null;
        if (!empty($row['easyverein_member_id'])) $u = $usersByEasyvereinId[$row['easyverein_member_id']] ?? null;
        if ($u === null) $u = $usersById[(int)$row['user_id']] ?? null;
        if ($u) {
            $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
            $row['user_name']  = $name !== '' ? $name : ($u['email'] ?? null);
            $row['user_email'] = $u['email'] ?? null;
        } else {
            $row['user_name'] = null; $row['user_email'] = null;
        }
    }
    unset($row);
    return $rows;
}

function buildItemNameMap(): array {
    $map = [];
    try {
        $items = Inventory::getAll();
        foreach ($items as $item) {
            $eid = (string)($item['easyverein_id'] ?? $item['id'] ?? '');
            if ($eid !== '') $map[$eid] = $item['name'] ?? '';
        }
    } catch (Exception $e) { error_log('rental_returns: EasyVerein item lookup failed: ' . $e->getMessage()); }
    return $map;
}

$pendingRequests = $activeLoans = $pendingReturnLoans = $pendingRentalReturns = [];
$dbError = '';

try {
    $db = Database::getContentDB();
    $stmt = $db->query("SELECT id, inventory_object_id, user_id, start_date, end_date, quantity, created_at, NULL AS easyverein_member_id FROM inventory_requests WHERE status = 'pending' ORDER BY created_at ASC");
    $pendingRequests = enrichWithUsers($stmt->fetchAll(PDO::FETCH_ASSOC));
    $stmt3 = $db->query("SELECT id, inventory_object_id, user_id, start_date, end_date, quantity, created_at, NULL AS easyverein_member_id FROM inventory_requests WHERE status = 'pending_return' ORDER BY created_at ASC");
    $pendingReturnLoans = enrichWithUsers($stmt3->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    $dbError = 'Datenbankfehler (ContentDB): ' . $e->getMessage();
    error_log('rental_returns: ' . $e->getMessage());
}

try {
    $dbInventory = Database::getInventoryDB();
    $stmt2 = $dbInventory->query("SELECT r.id, ii.easyverein_item_id AS inventory_object_id, r.user_id, r.start_date, r.end_date, 1 AS quantity, r.created_at, r.easyverein_member_id FROM inventory_rentals r JOIN inventory_items ii ON ii.id = r.item_id WHERE r.status IN ('active','overdue') ORDER BY r.start_date ASC");
    $activeLoans = enrichWithUsers($stmt2->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    if ($dbError === '') $dbError = 'Datenbankfehler (InventoryDB): ' . $e->getMessage();
    error_log('rental_returns: InventoryDB query failed: ' . $e->getMessage());
}

try {
    $db = Database::getContentDB();
    $stmt4 = $db->query("SELECT id, inventory_object_id, user_id, start_date, end_date, quantity, created_at, NULL AS easyverein_member_id FROM inventory_requests WHERE status = 'approved' ORDER BY start_date ASC");
    $approvedLoans = enrichWithUsers($stmt4->fetchAll(PDO::FETCH_ASSOC));
    $activeLoans   = array_merge($activeLoans, $approvedLoans);
} catch (Exception $e) { /* silent */ }

$itemNames = buildItemNameMap();

$title = 'Inventarverwaltung - IBC Intranet';
ob_start();
?>

<style>
/* ── rental-returns module ── */
.rr-page { animation: rrPageIn .45s ease both; }
@keyframes rrPageIn {
    from { opacity:0; transform:translateY(14px); }
    to   { opacity:1; transform:translateY(0); }
}

.rr-header-icon {
    width:3rem; height:3rem; border-radius:.875rem; flex-shrink:0;
    background: linear-gradient(135deg, rgba(37,99,235,1), rgba(29,78,216,1));
    box-shadow: 0 4px 14px rgba(37,99,235,.35);
    display:flex; align-items:center; justify-content:center;
}

/* Alert */
.rr-alert {
    padding:.875rem 1.25rem; border-radius:.875rem; margin-bottom:1.25rem;
    display:flex; align-items:center; gap:.65rem; font-size:.875rem; font-weight:500;
    border-width:1px; border-style:solid;
}
.rr-alert-err { background:rgba(239,68,68,.1); color:rgba(185,28,28,1); border-color:rgba(239,68,68,.3); }
.rr-alert-ok  { background:rgba(34,197,94,.1);  color:rgba(21,128,61,1);  border-color:rgba(34,197,94,.3); }

/* AJAX alert */
#rr-ajax-alert { display:none; }
#rr-ajax-alert.show { display:flex; }

/* Tab navigation */
.rr-tabs {
    display:flex; gap:.5rem; flex-wrap:wrap;
    padding:.375rem; border-radius:1rem;
    background:rgba(156,163,175,.1);
    border:1px solid var(--border-color);
    margin-bottom:1.25rem;
}
.rr-tab {
    flex:1; min-width:140px; padding:.6rem 1rem;
    border-radius:.75rem; font-size:.82rem; font-weight:600; border:none; cursor:pointer;
    background:transparent; color:var(--text-muted);
    transition: background .2s, color .2s, box-shadow .2s;
    display:flex; align-items:center; justify-content:center; gap:.4rem;
}
.rr-tab:hover { background:rgba(37,99,235,.07); color:rgba(37,99,235,1); }
.rr-tab.rr-tab--active {
    background: linear-gradient(135deg, rgba(37,99,235,1), rgba(29,78,216,1));
    color:#fff;
    box-shadow: 0 3px 10px rgba(37,99,235,.3);
}

/* Tab badge */
.rr-tab-badge {
    display:inline-flex; align-items:center; justify-content:center;
    min-width:1.25rem; height:1.25rem; padding:0 .3rem;
    border-radius:9999px; font-size:.65rem; font-weight:700;
    background:rgba(255,255,255,.25); color:inherit;
}
.rr-tab--active .rr-tab-badge { background:rgba(255,255,255,.3); color:#fff; }

/* Tab panels */
.rr-panel { display:none; }
.rr-panel.rr-panel--active { display:block; }

/* Section card */
.rr-card {
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius:1rem;
    overflow:hidden;
    box-shadow: 0 2px 12px rgba(0,0,0,.06);
}
.rr-card-head {
    padding:1.1rem 1.5rem;
    border-bottom:1px solid var(--border-color);
    display:flex; align-items:center; gap:.625rem;
}

/* Table */
.rr-table-wrap { overflow-x:auto; width:100%; }
.rr-table { width:100%; border-collapse:collapse; }
.rr-table thead tr {
    background:rgba(156,163,175,.07);
    border-bottom:1px solid var(--border-color);
}
.rr-table th {
    padding:.7rem 1rem; text-align:left; font-size:.7rem; font-weight:700;
    color:var(--text-muted); text-transform:uppercase; letter-spacing:.06em; white-space:nowrap;
}
.rr-table td {
    padding:.8rem 1rem; font-size:.85rem; color:var(--text-main);
    border-bottom:1px solid var(--border-color);
    vertical-align:middle;
}
.rr-table tbody tr:last-child td { border-bottom:none; }
.rr-table tbody tr { transition:background .15s; }
.rr-table tbody tr:hover { background:rgba(37,99,235,.04); }

/* Action buttons */
.rr-btn-approve {
    display:inline-flex; align-items:center; gap:.35rem;
    padding:.4rem .875rem; border-radius:.625rem; font-size:.8rem; font-weight:600;
    background:rgba(34,197,94,1); color:#fff; border:none; cursor:pointer;
    transition: opacity .2s; white-space:nowrap; min-height:34px;
}
.rr-btn-approve:hover { opacity:.88; }

.rr-btn-reject {
    display:inline-flex; align-items:center; gap:.35rem;
    padding:.4rem .875rem; border-radius:.625rem; font-size:.8rem; font-weight:600;
    background:rgba(239,68,68,1); color:#fff; border:none; cursor:pointer;
    transition: opacity .2s; white-space:nowrap; min-height:34px;
}
.rr-btn-reject:hover { opacity:.88; }

.rr-btn-return {
    display:inline-flex; align-items:center; gap:.35rem;
    padding:.4rem .875rem; border-radius:.625rem; font-size:.8rem; font-weight:600;
    background:rgba(37,99,235,1); color:#fff; border:none; cursor:pointer;
    transition: opacity .2s; white-space:nowrap; min-height:34px;
}
.rr-btn-return:hover { opacity:.88; }

.rr-btn-confirm-return {
    display:inline-flex; align-items:center; gap:.35rem;
    padding:.4rem .875rem; border-radius:.625rem; font-size:.8rem; font-weight:600;
    background:rgba(249,115,22,1); color:#fff; border:none; cursor:pointer;
    transition: opacity .2s; white-space:nowrap; min-height:34px;
}
.rr-btn-confirm-return:hover { opacity:.88; }

/* Back link */
.rr-back {
    display:inline-flex; align-items:center; gap:.5rem;
    padding:.55rem 1.1rem; border-radius:.75rem; font-size:.85rem; font-weight:600;
    background:rgba(156,163,175,.12); color:var(--text-muted);
    border:1.5px solid var(--border-color); text-decoration:none;
    transition: background .2s, color .2s;
}
.rr-back:hover { background:rgba(37,99,235,.08); color:rgba(37,99,235,1); }

/* Empty state */
.rr-empty { padding:3.5rem 1rem; text-align:center; }
.rr-empty-icon {
    width:3.5rem; height:3.5rem; border-radius:50%;
    display:inline-flex; align-items:center; justify-content:center;
    margin-bottom:.875rem; font-size:1.5rem;
}

/* Mobile responsive table */
@media (max-width:640px) {
    .rr-table, .rr-table thead, .rr-table tbody, .rr-table th, .rr-table td, .rr-table tr { display:block; }
    .rr-table thead { display:none; }
    .rr-table td {
        border-bottom:none; padding:.5rem .875rem;
        display:flex; align-items:flex-start; justify-content:space-between; gap:.5rem;
    }
    .rr-table td::before {
        content: attr(data-label);
        font-size:.72rem; font-weight:700; color:var(--text-muted);
        text-transform:uppercase; white-space:nowrap; flex-shrink:0; padding-top:.1rem;
    }
    .rr-table tbody tr {
        border-bottom:1px solid var(--border-color);
        padding:.25rem 0;
    }
    .rr-tabs { flex-direction:column; }
    .rr-tab { min-width:auto; }
}
</style>

<div class="rr-page" style="max-width:72rem;margin:0 auto;">

<!-- Header -->
<div style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.75rem;">
    <div style="display:flex;align-items:center;gap:.875rem;">
        <div class="rr-header-icon">
            <i class="fas fa-boxes" style="color:#fff;font-size:1.1rem;"></i>
        </div>
        <div>
            <h1 style="font-size:1.6rem;font-weight:800;color:var(--text-main);margin:0;line-height:1.2;">Inventarverwaltung</h1>
            <p style="font-size:.85rem;color:var(--text-muted);margin:.2rem 0 0;">Ausstehende Anfragen und aktive Ausleihen</p>
        </div>
    </div>
    <a href="../inventory/index.php" class="rr-back">
        <i class="fas fa-arrow-left"></i>Zum Inventar
    </a>
</div>

<!-- DB error -->
<?php if ($dbError !== ''): ?>
<div class="rr-alert rr-alert-err"><i class="fas fa-exclamation-circle"></i><?php echo htmlspecialchars($dbError); ?></div>
<?php endif; ?>

<!-- AJAX feedback -->
<div id="rr-ajax-alert" class="rr-alert" style="margin-bottom:1.25rem;"></div>

<!-- Tabs -->
<div class="rr-tabs" role="tablist">
    <button class="rr-tab rr-tab--active" data-tab="pending" type="button">
        <i class="fas fa-clock" style="color:inherit;"></i>
        Ausstehende Anfragen
        <span class="rr-tab-badge" id="badge-pending"><?php echo count($pendingRequests); ?></span>
    </button>
    <button class="rr-tab" data-tab="pending-return" type="button">
        <i class="fas fa-undo-alt" style="color:inherit;"></i>
        Rückgaben prüfen
        <span class="rr-tab-badge" id="badge-pending-return"><?php echo count($pendingReturnLoans) + count($pendingRentalReturns); ?></span>
    </button>
    <button class="rr-tab" data-tab="active" type="button">
        <i class="fas fa-sign-out-alt" style="color:inherit;"></i>
        Aktive Ausleihen
        <span class="rr-tab-badge" id="badge-active"><?php echo count($activeLoans); ?></span>
    </button>
</div>

<!-- ── Panel 1: Ausstehende Anfragen ── -->
<div class="rr-panel rr-panel--active" id="panel-pending">
<div class="rr-card">
    <div class="rr-card-head">
        <i class="fas fa-clock" style="color:rgba(234,179,8,1);"></i>
        <span style="font-size:1rem;font-weight:700;color:var(--text-main);">Ausstehende Anfragen</span>
        <span style="margin-left:auto;padding:.15rem .55rem;border-radius:9999px;font-size:.72rem;font-weight:700;background:rgba(234,179,8,.12);color:rgba(161,98,7,1);border:1px solid rgba(234,179,8,.3);"><?php echo count($pendingRequests); ?></span>
    </div>

    <?php if (empty($pendingRequests)): ?>
    <div class="rr-empty">
        <div class="rr-empty-icon" style="background:rgba(34,197,94,.12);color:rgba(21,128,61,1);"><i class="fas fa-check-circle"></i></div>
        <p style="font-size:1rem;font-weight:600;color:var(--text-main);margin:0 0 .3rem;">Keine ausstehenden Anfragen</p>
        <p style="font-size:.82rem;color:var(--text-muted);margin:0;">Alle Anfragen wurden bearbeitet</p>
    </div>
    <?php else: ?>
    <div class="rr-table-wrap">
    <table class="rr-table">
        <thead>
            <tr>
                <th>Artikel</th>
                <th>Menge</th>
                <th>Mitglied</th>
                <th>Zeitraum</th>
                <th>Eingereicht</th>
                <?php if (!$readOnly): ?><th>Aktionen</th><?php endif; ?>
            </tr>
        </thead>
        <tbody id="pending-tbody">
        <?php foreach ($pendingRequests as $req): ?>
        <tr id="pending-row-<?php echo (int)$req['id']; ?>">
            <td data-label="Artikel">
                <?php
                $itemName = $itemNames[(string)$req['inventory_object_id']] ?? '';
                echo $itemName !== ''
                    ? '<strong>'.htmlspecialchars($itemName).'</strong>'
                    : '<span style="color:var(--text-muted);">#'.htmlspecialchars($req['inventory_object_id']).'</span>';
                ?>
            </td>
            <td data-label="Menge"><?php echo (int)$req['quantity']; ?></td>
            <td data-label="Mitglied">
                <div style="display:flex;flex-direction:column;gap:.15rem;">
                    <span style="display:flex;align-items:center;gap:.35rem;">
                        <i class="fas fa-user" style="font-size:.7rem;color:var(--text-muted);"></i>
                        <?php echo htmlspecialchars($req['user_name'] ?? $req['user_email'] ?? 'Unbekannt'); ?>
                    </span>
                    <?php if (!empty($req['user_email']) && $req['user_name'] !== $req['user_email']): ?>
                    <span style="font-size:.72rem;color:var(--text-muted);"><?php echo htmlspecialchars($req['user_email']); ?></span>
                    <?php endif; ?>
                </div>
            </td>
            <td data-label="Zeitraum">
                <?php echo date('d.m.Y', strtotime($req['start_date'])); ?> &ndash; <?php echo date('d.m.Y', strtotime($req['end_date'])); ?>
            </td>
            <td data-label="Eingereicht" style="font-size:.78rem;color:var(--text-muted);">
                <?php echo date('d.m.Y H:i', strtotime($req['created_at'])); ?>
            </td>
            <?php if (!$readOnly): ?>
            <td data-label="Aktionen">
                <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
                    <button class="rr-btn-approve" onclick="handleRequestAction(<?php echo (int)$req['id']; ?>, 'approve')">
                        <i class="fas fa-check"></i>Genehmigen
                    </button>
                    <button class="rr-btn-reject" onclick="handleRequestAction(<?php echo (int)$req['id']; ?>, 'reject')">
                        <i class="fas fa-times"></i>Ablehnen
                    </button>
                </div>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- ── Panel 2: Rückgaben prüfen ── -->
<div class="rr-panel" id="panel-pending-return">
<div class="rr-card">
    <div class="rr-card-head">
        <i class="fas fa-undo-alt" style="color:rgba(249,115,22,1);"></i>
        <span style="font-size:1rem;font-weight:700;color:var(--text-main);">Gemeldete Rückgaben</span>
        <span style="margin-left:auto;padding:.15rem .55rem;border-radius:9999px;font-size:.72rem;font-weight:700;background:rgba(249,115,22,.1);color:rgba(194,65,12,1);border:1px solid rgba(249,115,22,.3);"><?php echo count($pendingReturnLoans) + count($pendingRentalReturns); ?></span>
    </div>
    <div style="padding:.75rem 1.5rem;border-bottom:1px solid var(--border-color);background:rgba(249,115,22,.05);">
        <p style="font-size:.82rem;color:var(--text-muted);margin:0;">
            <i class="fas fa-info-circle" style="margin-right:.35rem;"></i>
            Diese Mitglieder haben ihre Ausleihe vorzeitig beendet. Bitte Artikel prüfen und Rückgabe verifizieren.
        </p>
    </div>

    <?php if (empty($pendingReturnLoans) && empty($pendingRentalReturns)): ?>
    <div class="rr-empty">
        <div class="rr-empty-icon" style="background:rgba(34,197,94,.12);color:rgba(21,128,61,1);"><i class="fas fa-check-circle"></i></div>
        <p style="font-size:1rem;font-weight:600;color:var(--text-main);margin:0 0 .3rem;">Keine ausstehenden Rückgaben</p>
        <p style="font-size:.82rem;color:var(--text-muted);margin:0;">Alle Rückgaben wurden bestätigt</p>
    </div>
    <?php else: ?>
    <div class="rr-table-wrap">
    <table class="rr-table">
        <thead>
            <tr>
                <th>Artikel</th>
                <th>Menge</th>
                <th>Ausgeliehen von</th>
                <th>Zeitraum</th>
                <?php if (!$readOnly): ?><th>Aktion</th><?php endif; ?>
            </tr>
        </thead>
        <tbody id="pending-return-tbody">
        <?php foreach ($pendingReturnLoans as $loan): ?>
        <tr id="pending-return-row-<?php echo (int)$loan['id']; ?>">
            <td data-label="Artikel">
                <?php
                $itemName = $itemNames[(string)$loan['inventory_object_id']] ?? '';
                echo $itemName !== ''
                    ? '<strong>'.htmlspecialchars($itemName).'</strong>'
                    : '<span style="color:var(--text-muted);">#'.htmlspecialchars($loan['inventory_object_id']).'</span>';
                ?>
            </td>
            <td data-label="Menge"><?php echo (int)$loan['quantity']; ?></td>
            <td data-label="Ausgeliehen von">
                <div style="display:flex;flex-direction:column;gap:.15rem;">
                    <span><?php echo htmlspecialchars($loan['user_name'] ?? $loan['user_email'] ?? 'Unbekannt'); ?></span>
                    <?php if (!empty($loan['user_email']) && $loan['user_name'] !== $loan['user_email']): ?>
                    <span style="font-size:.72rem;color:var(--text-muted);"><?php echo htmlspecialchars($loan['user_email']); ?></span>
                    <?php endif; ?>
                </div>
            </td>
            <td data-label="Zeitraum">
                <?php echo date('d.m.Y', strtotime($loan['start_date'])); ?> &ndash; <?php echo date('d.m.Y', strtotime($loan['end_date'])); ?>
                <span style="display:block;font-size:.72rem;color:rgba(249,115,22,1);font-weight:600;margin-top:.2rem;">Vorzeitige Rückgabe gemeldet</span>
            </td>
            <?php if (!$readOnly): ?>
            <td data-label="Aktion">
                <button class="rr-btn-confirm-return" onclick="confirmReturn(<?php echo (int)$loan['id']; ?>, 'pending-return')">
                    <i class="fas fa-check"></i>Bestätigen
                </button>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php foreach ($pendingRentalReturns as $rental): ?>
        <tr id="rental-return-row-<?php echo (int)$rental['id']; ?>">
            <td data-label="Artikel">
                <?php
                $itemName = $itemNames[(string)$rental['inventory_object_id']] ?? '';
                echo $itemName !== ''
                    ? '<strong>'.htmlspecialchars($itemName).'</strong>'
                    : '<span style="color:var(--text-muted);">#'.htmlspecialchars($rental['inventory_object_id']).'</span>';
                ?>
            </td>
            <td data-label="Menge"><?php echo (int)$rental['quantity']; ?></td>
            <td data-label="Ausgeliehen von">
                <span><?php echo htmlspecialchars($rental['user_name'] ?? $rental['user_email'] ?? 'Unbekannt'); ?></span>
            </td>
            <td data-label="Zeitraum">
                <?php echo !empty($rental['end_date']) ? date('d.m.Y', strtotime($rental['end_date'])) : '—'; ?>
                <span style="display:block;font-size:.72rem;color:rgba(249,115,22,1);font-weight:600;margin-top:.2rem;">Rückgabe gemeldet</span>
            </td>
            <?php if (!$readOnly): ?>
            <td data-label="Aktion">
                <button class="rr-btn-confirm-return" onclick="confirmRentalReturn(<?php echo (int)$rental['id']; ?>)">
                    <i class="fas fa-check"></i>Bestätigen
                </button>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- ── Panel 3: Aktive Ausleihen ── -->
<div class="rr-panel" id="panel-active">
<div class="rr-card">
    <div class="rr-card-head">
        <i class="fas fa-sign-out-alt" style="color:rgba(34,197,94,1);"></i>
        <span style="font-size:1rem;font-weight:700;color:var(--text-main);">Aktive Ausleihen</span>
        <span style="margin-left:auto;padding:.15rem .55rem;border-radius:9999px;font-size:.72rem;font-weight:700;background:rgba(34,197,94,.12);color:rgba(21,128,61,1);border:1px solid rgba(34,197,94,.3);"><?php echo count($activeLoans); ?></span>
    </div>

    <?php if (empty($activeLoans)): ?>
    <div class="rr-empty">
        <div class="rr-empty-icon" style="background:rgba(156,163,175,.12);color:rgba(156,163,175,1);"><i class="fas fa-inbox"></i></div>
        <p style="font-size:1rem;font-weight:600;color:var(--text-main);margin:0 0 .3rem;">Keine aktiven Ausleihen</p>
    </div>
    <?php else: ?>
    <div class="rr-table-wrap">
    <table class="rr-table">
        <thead>
            <tr>
                <th>Artikel</th>
                <th>Menge</th>
                <th>Ausgeliehen von</th>
                <th>Zeitraum</th>
                <?php if (!$readOnly): ?><th>Aktion</th><?php endif; ?>
            </tr>
        </thead>
        <tbody id="active-tbody">
        <?php foreach ($activeLoans as $loan): ?>
        <tr id="active-row-<?php echo (int)$loan['id']; ?>">
            <td data-label="Artikel">
                <?php
                $itemName = $itemNames[(string)$loan['inventory_object_id']] ?? '';
                echo $itemName !== ''
                    ? '<strong>'.htmlspecialchars($itemName).'</strong>'
                    : '<span style="color:var(--text-muted);">#'.htmlspecialchars($loan['inventory_object_id']).'</span>';
                ?>
            </td>
            <td data-label="Menge"><?php echo (int)$loan['quantity']; ?></td>
            <td data-label="Ausgeliehen von">
                <div style="display:flex;flex-direction:column;gap:.15rem;">
                    <span><?php echo htmlspecialchars($loan['user_name'] ?? $loan['user_email'] ?? 'Unbekannt'); ?></span>
                    <?php if (!empty($loan['user_email']) && $loan['user_name'] !== $loan['user_email']): ?>
                    <span style="font-size:.72rem;color:var(--text-muted);"><?php echo htmlspecialchars($loan['user_email']); ?></span>
                    <?php endif; ?>
                </div>
            </td>
            <td data-label="Zeitraum">
                <?php echo date('d.m.Y', strtotime($loan['start_date'])); ?> &ndash; <?php echo date('d.m.Y', strtotime($loan['end_date'])); ?>
            </td>
            <?php if (!$readOnly): ?>
            <td data-label="Aktion">
                <button class="rr-btn-return" onclick="confirmReturn(<?php echo (int)$loan['id']; ?>)">
                    <i class="fas fa-undo-alt"></i>Rückgabe
                </button>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
</div>

</div><!-- /rr-page -->

<script>
(function () {
    'use strict';

    // Tab switching
    document.querySelectorAll('.rr-tab').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.rr-tab').forEach(function(b) { b.classList.remove('rr-tab--active'); });
            document.querySelectorAll('.rr-panel').forEach(function(p) { p.classList.remove('rr-panel--active'); });
            this.classList.add('rr-tab--active');
            document.getElementById('panel-' + this.dataset.tab).classList.add('rr-panel--active');
        });
    });

    const csrfToken = <?php echo json_encode(CSRFHandler::getToken()); ?>;

    function showAlert(message, type) {
        const el = document.getElementById('rr-ajax-alert');
        el.className = 'rr-alert show ' + (type === 'success' ? 'rr-alert-ok' : 'rr-alert-err');
        el.innerHTML = '<i class="fas ' + (type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle') + '"></i>' + message;
        window.scrollTo({ top:0, behavior:'smooth' });
        setTimeout(function() { el.classList.remove('show'); el.style.display = 'none'; }, 5000);
    }

    function postAction(payload, onSuccess) {
        payload.csrf_token = csrfToken;
        fetch('<?php echo htmlspecialchars(url('/api/rental_request_action.php'), ENT_QUOTES); ?>', {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify(payload)
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) { showAlert(data.message, 'success'); if (onSuccess) onSuccess(); }
            else showAlert(data.message || 'Fehler bei der Aktion', 'error');
        })
        .catch(function() { showAlert('Netzwerkfehler. Bitte Seite neu laden.', 'error'); });
    }

    function fadeAndRemove(row, badgeId) {
        if (!row) return;
        row.style.transition = 'opacity .4s';
        row.style.opacity    = '0';
        setTimeout(function() {
            row.remove();
            var badge = document.getElementById(badgeId);
            if (badge) {
                var tbody = document.getElementById(badgeId.replace('badge-', '') + '-tbody');
                badge.textContent = tbody ? tbody.querySelectorAll('tr').length : 0;
            }
        }, 400);
    }

    window.handleRequestAction = function(requestId, action) {
        if (!confirm('Anfrage wirklich ' + (action === 'approve' ? 'genehmigen' : 'ablehnen') + '?')) return;
        postAction({ action:action, request_id:requestId }, function() {
            fadeAndRemove(document.getElementById('pending-row-' + requestId), 'badge-pending');
        });
    };

    window.confirmReturn = function(loanId, sourceSection) {
        sourceSection = sourceSection || 'active';
        if (!confirm('Rückgabe bestätigen?')) return;
        postAction({ action:'verify_return', request_id:loanId }, function() {
            var rowId = (sourceSection === 'pending-return' ? 'pending-return-row-' : 'active-row-') + loanId;
            fadeAndRemove(document.getElementById(rowId), 'badge-' + sourceSection);
        });
    };

    window.confirmRentalReturn = function(rentalId) {
        if (!confirm('Rückgabe bestätigen?')) return;
        postAction({ action:'verify_rental_return', rental_id:rentalId }, function() {
            fadeAndRemove(document.getElementById('rental-return-row-' + rentalId), 'badge-pending-return');
        });
    };
}());
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
