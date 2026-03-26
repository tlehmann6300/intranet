<?php
/**
 * Admin – Inventarverwaltung: Ausstehende Anfragen & Aktive Ausleihen
 *
 * Zugriff nur für Vorstandsmitglieder (board_finance, board_internal, board_external).
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

// Read-only mode for alumni_auditor and alumni_board: they may view but not take actions
$readOnly = !Auth::isBoard();

// ── Helper: enrich rows with user name/email ──────────────────────────────────
// Primary source: EasyVerein (match inventory_rentals.easyverein_member_id against users.easyverein_id)
// Fallback source: User DB JOIN on user_id
function enrichWithUsers(array $rows): array {
    if (empty($rows)) {
        return $rows;
    }

    $userDb = null;
    try {
        $userDb = Database::getUserDB();
    } catch (Exception $e) {
        error_log('rental_returns: cannot connect to user DB: ' . $e->getMessage());
    }

    // Primary: look up by easyverein_member_id matching users.easyverein_id
    $easyvereinIds      = array_filter(
        array_unique(array_column($rows, 'easyverein_member_id')),
        fn($v) => $v !== null && $v !== ''
    );
    $usersByEasyvereinId = [];
    if ($userDb !== null && !empty($easyvereinIds)) {
        try {
            $placeholders = implode(',', array_fill(0, count($easyvereinIds), '?'));
            $stmt         = $userDb->prepare(
                "SELECT easyverein_id, email, first_name, last_name
                   FROM users WHERE easyverein_id IN ({$placeholders})"
            );
            $stmt->execute(array_values($easyvereinIds));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $usersByEasyvereinId[$row['easyverein_id']] = $row;
            }
        } catch (Exception $e) {
            error_log('rental_returns: EasyVerein-based user lookup failed: ' . $e->getMessage());
        }
    }

    // Fallback: look up by local user_id
    $userIds   = array_unique(array_column($rows, 'user_id'));
    $usersById = [];
    if ($userDb !== null) {
        try {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $stmt         = $userDb->prepare(
                "SELECT id, email, first_name, last_name FROM users WHERE id IN ({$placeholders})"
            );
            $stmt->execute($userIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $usersById[(int)$row['id']] = $row;
            }
        } catch (Exception $e) {
            error_log('rental_returns: user lookup failed: ' . $e->getMessage());
        }
    }

    foreach ($rows as &$row) {
        $u = null;
        // Primary: EasyVerein member ID
        if (!empty($row['easyverein_member_id'])) {
            $u = $usersByEasyvereinId[$row['easyverein_member_id']] ?? null;
        }
        // Fallback: local user_id
        if ($u === null) {
            $u = $usersById[(int)$row['user_id']] ?? null;
        }
        if ($u) {
            $name              = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
            $row['user_name']  = $name !== '' ? $name : ($u['email'] ?? null);
            $row['user_email'] = $u['email'] ?? null;
        } else {
            $row['user_name']  = null;
            $row['user_email'] = null;
        }
    }
    unset($row);
    return $rows;
}

// ── Helper: build item-name map from EasyVerein ───────────────────────────────
function buildItemNameMap(): array {
    $map = [];
    try {
        $items = Inventory::getAll();
        foreach ($items as $item) {
            $eid = (string)($item['easyverein_id'] ?? $item['id'] ?? '');
            if ($eid !== '') {
                $map[$eid] = $item['name'] ?? '';
            }
        }
    } catch (Exception $e) {
        error_log('rental_returns: EasyVerein item lookup failed: ' . $e->getMessage());
    }
    return $map;
}

// ── Fetch data ────────────────────────────────────────────────────────────────
$pendingRequests      = [];
$activeLoans          = [];
$pendingReturnLoans   = [];
$pendingRentalReturns = [];
$dbError              = '';

// ── Pending requests: still stored in ContentDB (board-approval workflow) ──────
try {
    $db   = Database::getContentDB();
    $stmt = $db->query(
        "SELECT id, inventory_object_id, user_id, start_date, end_date, quantity, created_at,
                NULL AS easyverein_member_id
           FROM inventory_requests WHERE status = 'pending' ORDER BY created_at ASC"
    );
    $pendingRequests = enrichWithUsers($stmt->fetchAll(PDO::FETCH_ASSOC));

    // Pending return loans from the approval workflow (ContentDB)
    $stmt3 = $db->query(
        "SELECT id, inventory_object_id, user_id, start_date, end_date, quantity, created_at,
                NULL AS easyverein_member_id
           FROM inventory_requests WHERE status = 'pending_return' ORDER BY created_at ASC"
    );
    $pendingReturnLoans = enrichWithUsers($stmt3->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    $dbError = 'Datenbankfehler (ContentDB): ' . $e->getMessage();
    error_log('rental_returns: ' . $e->getMessage());
}

// ── Active loans: primary source is the Inventory DB (dbs15419825) ─────────────
try {
    $dbInventory = Database::getInventoryDB();

    $stmt2 = $dbInventory->query(
        "SELECT r.id,
                ii.easyverein_item_id AS inventory_object_id,
                r.user_id,
                r.start_date,
                r.end_date,
                1                    AS quantity,
                r.created_at,
                r.easyverein_member_id
           FROM inventory_rentals r
           JOIN inventory_items ii ON ii.id = r.item_id
          WHERE r.status IN ('active', 'overdue')
          ORDER BY r.start_date ASC"
    );
    $activeLoans = enrichWithUsers($stmt2->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    if ($dbError === '') {
        $dbError = 'Datenbankfehler (InventoryDB): ' . $e->getMessage();
    }
    error_log('rental_returns: InventoryDB query failed: ' . $e->getMessage());
}

// ── Approved active loans from ContentDB approval workflow (secondary) ──────────
try {
    $db    = Database::getContentDB();
    $stmt4   = $db->query(
        "SELECT id, inventory_object_id, user_id, start_date, end_date, quantity, created_at,
                NULL AS easyverein_member_id
           FROM inventory_requests WHERE status = 'approved' ORDER BY start_date ASC"
    );
    $approvedLoans = enrichWithUsers($stmt4->fetchAll(PDO::FETCH_ASSOC));
    $activeLoans   = array_merge($activeLoans, $approvedLoans);
} catch (Exception $e) {
    // Silently ignore if ContentDB approval table is unavailable
}

$itemNames = buildItemNameMap();

$title = 'Inventarverwaltung - IBC Intranet';
ob_start();
?>

<div class="mb-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-boxes text-blue-600 mr-2"></i>
                Inventarverwaltung
            </h1>
            <p class="text-gray-600">Ausstehende Anfragen und aktive Ausleihen</p>
        </div>
        <div class="mt-4 md:mt-0">
            <a href="../inventory/index.php" class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition font-medium">
                <i class="fas fa-arrow-left mr-2"></i>
                Zum Inventar
            </a>
        </div>
    </div>
</div>

<?php if ($dbError !== ''): ?>
<div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($dbError); ?>
</div>
<?php endif; ?>

<!-- Alert placeholder for AJAX feedback -->
<div id="ajax-alert" class="mb-6 hidden"></div>

<!-- ── Tab navigation ────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-6" id="inventoryTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="pending-tab" data-bs-toggle="tab" data-bs-target="#pending" type="button" role="tab" aria-controls="pending" aria-selected="true">
            <i class="fas fa-clock text-yellow-600 mr-1"></i>
            Ausstehende Anfragen
            <span class="badge bg-warning text-white ms-1"><?php echo count($pendingRequests); ?></span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="pending-return-tab" data-bs-toggle="tab" data-bs-target="#pending-return" type="button" role="tab" aria-controls="pending-return" aria-selected="false">
            <i class="fas fa-undo-alt text-orange-600 mr-1"></i>
            Rückgaben prüfen
            <span class="badge bg-warning text-white ms-1"><?php echo count($pendingReturnLoans) + count($pendingRentalReturns); ?></span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="active-tab" data-bs-toggle="tab" data-bs-target="#active" type="button" role="tab" aria-controls="active" aria-selected="false">
            <i class="fas fa-sign-out-alt text-green-600 mr-1"></i>
            Aktive Ausleihen &amp; Rücknahme
            <span class="badge bg-success ms-1"><?php echo count($activeLoans); ?></span>
        </button>
    </li>
</ul>

<div class="tab-content" id="inventoryTabContent">

    <!-- ── Section 1: Ausstehende Anfragen ────────────────────────────────── -->
    <div class="tab-pane fade show active" id="pending" role="tabpanel" aria-labelledby="pending-tab">
        <div class="card p-6">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-clock text-yellow-600 mr-2"></i>
                Ausstehende Anfragen (<?php echo count($pendingRequests); ?>)
            </h2>

            <?php if (empty($pendingRequests)): ?>
            <div class="text-center py-12">
                <i class="fas fa-check-circle text-6xl text-green-300 mb-4"></i>
                <p class="text-gray-500 text-lg">Keine ausstehenden Anfragen</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto w-full has-action-dropdown">
                <table class="w-full card-table">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Artikel</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Menge</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Mitglied</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Zeitraum</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Eingereicht</th>
                            <?php if (!$readOnly): ?><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aktionen</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200" id="pending-tbody">
                        <?php foreach ($pendingRequests as $req): ?>
                        <tr id="pending-row-<?php echo (int)$req['id']; ?>" class="hover:bg-yellow-50 dark:hover:bg-yellow-900/20">
                            <td class="px-4 py-3 text-sm font-semibold text-gray-800" data-label="Artikel">
                                <?php
                                $itemName = $itemNames[(string)$req['inventory_object_id']] ?? '';
                                echo $itemName !== ''
                                    ? htmlspecialchars($itemName)
                                    : '<span class="text-gray-400">#' . htmlspecialchars($req['inventory_object_id']) . '</span>';
                                ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700" data-label="Menge"><?php echo (int)$req['quantity']; ?></td>
                            <td class="px-4 py-3 text-sm text-gray-700" data-label="Mitglied">
                                <i class="fas fa-user text-gray-400 mr-1"></i>
                                <?php echo htmlspecialchars($req['user_name'] ?? $req['user_email'] ?? 'Unbekannt'); ?>
                                <?php if (!empty($req['user_email']) && $req['user_name'] !== $req['user_email']): ?>
                                    <span class="block text-xs text-gray-400"><?php echo htmlspecialchars($req['user_email']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600" data-label="Zeitraum">
                                <?php echo htmlspecialchars(date('d.m.Y', strtotime($req['start_date']))); ?>
                                &ndash;
                                <?php echo htmlspecialchars(date('d.m.Y', strtotime($req['end_date']))); ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600" data-label="Eingereicht">
                                <?php echo htmlspecialchars(date('d.m.Y H:i', strtotime($req['created_at']))); ?>
                            </td>
                            <?php if (!$readOnly): ?>
                            <td class="px-4 py-3 text-sm" data-label="Aktionen">
                                <div class="flex flex-wrap items-center gap-2">
                                    <button
                                        onclick="handleRequestAction(<?php echo (int)$req['id']; ?>, 'approve')"
                                        class="btn btn-sm d-inline-flex align-items-center gap-1"
                                        style="background-color: #198754 !important; border-color: #198754 !important; color: #ffffff !important;">
                                        <i class="fas fa-check mr-1"></i>Genehmigen
                                    </button>
                                    <button
                                        onclick="handleRequestAction(<?php echo (int)$req['id']; ?>, 'reject')"
                                        class="btn btn-sm d-inline-flex align-items-center gap-1"
                                        style="background-color: #dc3545 !important; border-color: #dc3545 !important; color: #ffffff !important;">
                                        <i class="fas fa-times mr-1"></i>Ablehnen
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

    <!-- ── Section 2: Gemeldete Rückgaben ────────────────────────────────── -->
    <div class="tab-pane fade" id="pending-return" role="tabpanel" aria-labelledby="pending-return-tab">
        <div class="card p-6">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-undo-alt text-orange-600 mr-2"></i>
                Gemeldete Rückgaben (<?php echo count($pendingReturnLoans) + count($pendingRentalReturns); ?>)
            </h2>
            <p class="text-sm text-gray-500 mb-4">
                <i class="fas fa-info-circle mr-1"></i>
                Diese Mitglieder haben ihre Ausleihe vorzeitig beendet und die Rückgabe gemeldet. Bitte Artikel prüfen und Rückgabe verifizieren.
            </p>

            <?php if (empty($pendingReturnLoans) && empty($pendingRentalReturns)): ?>
            <div class="text-center py-12">
                <i class="fas fa-check-circle text-6xl text-green-300 mb-4"></i>
                <p class="text-gray-500 text-lg">Keine ausstehenden Rückgaben</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto w-full has-action-dropdown">
                <table class="w-full card-table">
                    <thead class="bg-orange-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Artikel</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Menge</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ausgeliehen von</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Zeitraum</th>
                            <?php if (!$readOnly): ?><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aktion</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200" id="pending-return-tbody">
                        <?php foreach ($pendingReturnLoans as $loan): ?>
                        <tr id="pending-return-row-<?php echo (int)$loan['id']; ?>" class="hover:bg-orange-50 dark:hover:bg-orange-900/20">
                            <td class="px-4 py-3 text-sm font-semibold text-gray-800" data-label="Artikel">
                                <?php
                                $itemName = $itemNames[(string)$loan['inventory_object_id']] ?? '';
                                echo $itemName !== ''
                                    ? htmlspecialchars($itemName)
                                    : '<span class="text-gray-400">#' . htmlspecialchars($loan['inventory_object_id']) . '</span>';
                                ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700" data-label="Menge"><?php echo (int)$loan['quantity']; ?></td>
                            <td class="px-4 py-3 text-sm text-gray-700" data-label="Ausgeliehen von">
                                <i class="fas fa-user text-gray-400 mr-1"></i>
                                <?php echo htmlspecialchars($loan['user_name'] ?? $loan['user_email'] ?? 'Unbekannt'); ?>
                                <?php if (!empty($loan['user_email']) && $loan['user_name'] !== $loan['user_email']): ?>
                                    <span class="block text-xs text-gray-400"><?php echo htmlspecialchars($loan['user_email']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600" data-label="Zeitraum">
                                <?php echo htmlspecialchars(date('d.m.Y', strtotime($loan['start_date']))); ?>
                                &ndash;
                                <?php echo htmlspecialchars(date('d.m.Y', strtotime($loan['end_date']))); ?>
                                <span class="block text-xs text-orange-600 font-medium mt-0.5">Vorzeitige Rückgabe gemeldet</span>
                            </td>
                            <?php if (!$readOnly): ?>
                            <td class="px-4 py-3 text-sm" data-label="Aktion">
                                <button
                                    onclick="confirmReturn(<?php echo (int)$loan['id']; ?>, 'pending-return')"
                                    class="inline-flex items-center px-3 py-1.5 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition font-medium text-sm">
                                    <i class="fas fa-check mr-1"></i>Rückgabe bestätigen
                                </button>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php foreach ($pendingRentalReturns as $rental): ?>
                        <tr id="rental-return-row-<?php echo (int)$rental['id']; ?>" class="hover:bg-orange-50 dark:hover:bg-orange-900/20">
                            <td class="px-4 py-3 text-sm font-semibold text-gray-800" data-label="Artikel">
                                <?php
                                $itemName = $itemNames[(string)$rental['inventory_object_id']] ?? '';
                                echo $itemName !== ''
                                    ? htmlspecialchars($itemName)
                                    : '<span class="text-gray-400">#' . htmlspecialchars($rental['inventory_object_id']) . '</span>';
                                ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700" data-label="Menge"><?php echo (int)$rental['quantity']; ?></td>
                            <td class="px-4 py-3 text-sm text-gray-700" data-label="Ausgeliehen von">
                                <i class="fas fa-user text-gray-400 mr-1"></i>
                                <?php echo htmlspecialchars($rental['user_name'] ?? $rental['user_email'] ?? 'Unbekannt'); ?>
                                <?php if (!empty($rental['user_email']) && $rental['user_name'] !== $rental['user_email']): ?>
                                    <span class="block text-xs text-gray-400"><?php echo htmlspecialchars($rental['user_email']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600" data-label="Zeitraum">
                                <?php if (!empty($rental['end_date'])): ?>
                                    <?php echo htmlspecialchars(date('d.m.Y', strtotime($rental['end_date']))); ?>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                                <span class="block text-xs text-orange-600 font-medium mt-0.5">Rückgabe gemeldet</span>
                            </td>
                            <?php if (!$readOnly): ?>
                            <td class="px-4 py-3 text-sm" data-label="Aktion">
                                <button
                                    onclick="confirmRentalReturn(<?php echo (int)$rental['id']; ?>)"
                                    class="inline-flex items-center px-3 py-1.5 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition font-medium text-sm">
                                    <i class="fas fa-check mr-1"></i>Rückgabe bestätigen
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

    <!-- ── Section 3: Aktive Ausleihen ────────────────────────────────────── -->
    <div class="tab-pane fade" id="active" role="tabpanel" aria-labelledby="active-tab">
        <div class="card p-6">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-sign-out-alt text-green-600 mr-2"></i>
                Aktive Ausleihen (<?php echo count($activeLoans); ?>)
            </h2>

            <?php if (empty($activeLoans)): ?>
            <div class="text-center py-12">
                <i class="fas fa-inbox text-6xl text-gray-300 mb-4"></i>
                <p class="text-gray-500 text-lg">Keine aktiven Ausleihen</p>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto w-full has-action-dropdown">
                <table class="w-full card-table">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Artikel</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Menge</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ausgeliehen von</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Zeitraum</th>
                            <?php if (!$readOnly): ?><th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aktion</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200" id="active-tbody">
                        <?php foreach ($activeLoans as $loan): ?>
                        <tr id="active-row-<?php echo (int)$loan['id']; ?>" class="hover:bg-green-50">
                            <td class="px-4 py-3 text-sm font-semibold text-gray-800" data-label="Artikel">
                                <?php
                                $itemName = $itemNames[(string)$loan['inventory_object_id']] ?? '';
                                echo $itemName !== ''
                                    ? htmlspecialchars($itemName)
                                    : '<span class="text-gray-400">#' . htmlspecialchars($loan['inventory_object_id']) . '</span>';
                                ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700" data-label="Menge"><?php echo (int)$loan['quantity']; ?></td>
                            <td class="px-4 py-3 text-sm text-gray-700" data-label="Ausgeliehen von">
                                <i class="fas fa-user text-gray-400 mr-1"></i>
                                <?php echo htmlspecialchars($loan['user_name'] ?? $loan['user_email'] ?? 'Unbekannt'); ?>
                                <?php if (!empty($loan['user_email']) && $loan['user_name'] !== $loan['user_email']): ?>
                                    <span class="block text-xs text-gray-400"><?php echo htmlspecialchars($loan['user_email']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600" data-label="Zeitraum">
                                <?php echo htmlspecialchars(date('d.m.Y', strtotime($loan['start_date']))); ?>
                                &ndash;
                                <?php echo htmlspecialchars(date('d.m.Y', strtotime($loan['end_date']))); ?>
                            </td>
                            <?php if (!$readOnly): ?>
                            <td class="px-4 py-3 text-sm" data-label="Aktion">
                                <button
                                    onclick="confirmReturn(<?php echo (int)$loan['id']; ?>)"
                                    class="inline-flex items-center px-3 py-1.5 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-medium text-sm">
                                    <i class="fas fa-undo-alt mr-1"></i>Rückgabe bestätigen
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

</div><!-- /tab-content -->

<script>
(function () {
    'use strict';

    const csrfToken = <?php echo json_encode(CSRFHandler::getToken()); ?>;

    // ── Show Bootstrap alert ──────────────────────────────────────────────────
    function showAlert(message, type) {
        const alertEl = document.getElementById('ajax-alert');
        const icon    = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        alertEl.className = 'mb-6 p-4 rounded-lg border ' +
            (type === 'success'
                ? 'bg-green-100 border-green-400 text-green-700'
                : 'bg-red-100 border-red-400 text-red-700');
        alertEl.innerHTML = '<i class="fas ' + icon + ' mr-2"></i>' + message;
        alertEl.classList.remove('hidden');
        window.scrollTo({ top: 0, behavior: 'smooth' });
        setTimeout(function () { alertEl.classList.add('hidden'); }, 5000);
    }

    // ── AJAX helper ───────────────────────────────────────────────────────────
    function postAction(payload, onSuccess, onError) {
        payload.csrf_token = csrfToken;
        fetch('<?php echo htmlspecialchars(url('/api/rental_request_action.php'), ENT_QUOTES); ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.success) {
                showAlert(data.message, 'success');
                if (typeof onSuccess === 'function') onSuccess();
            } else {
                showAlert(data.message || 'Fehler bei der Aktion', 'error');
                if (typeof onError === 'function') onError();
            }
        })
        .catch(function () {
            showAlert('Netzwerkfehler. Bitte Seite neu laden.', 'error');
            if (typeof onError === 'function') onError();
        });
    }

    // ── Approve / Reject ──────────────────────────────────────────────────────
    window.handleRequestAction = function (requestId, action) {
        const label = action === 'approve' ? 'genehmigen' : 'ablehnen';
        if (!confirm('Anfrage wirklich ' + label + '?')) return;

        postAction({ action: action, request_id: requestId }, function () {
            const row = document.getElementById('pending-row-' + requestId);
            if (row) {
                row.style.transition = 'opacity 0.4s';
                row.style.opacity    = '0';
                setTimeout(function () { row.remove(); updateBadge('pending'); }, 400);
            }
        });
    };

    // ── Confirm return ────────────────────────────────────────────────────────
    window.confirmReturn = function (loanId, sourceSection) {
        sourceSection = sourceSection || 'active';
        if (!confirm('Rückgabe bestätigen?')) return;

        postAction({ action: 'verify_return', request_id: loanId }, function () {
            const rowId = (sourceSection === 'pending-return' ? 'pending-return-row-' : 'active-row-') + loanId;
            const row   = document.getElementById(rowId);
            const tbody = sourceSection === 'pending-return' ? 'pending-return' : 'active';
            if (row) {
                row.style.transition = 'opacity 0.4s';
                row.style.opacity    = '0';
                setTimeout(function () { row.remove(); updateBadge(tbody); }, 400);
            }
        });
    };

    // ── Confirm return for legacy inventory_rentals rows ──────────────────────
    window.confirmRentalReturn = function (rentalId) {
        if (!confirm('Rückgabe bestätigen?')) return;

        postAction({ action: 'verify_rental_return', rental_id: rentalId }, function () {
            const row = document.getElementById('rental-return-row-' + rentalId);
            if (row) {
                row.style.transition = 'opacity 0.4s';
                row.style.opacity    = '0';
                setTimeout(function () { row.remove(); updateBadge('pending-return'); }, 400);
            }
        });
    };

    // ── Update badge counts ───────────────────────────────────────────────────
    function updateBadge(section) {
        const tbody  = document.getElementById(section + '-tbody');
        const tab    = document.getElementById(section + '-tab');
        if (!tbody || !tab) return;
        const count  = tbody.querySelectorAll('tr').length;
        const badge  = tab.querySelector('.badge');
        if (badge) badge.textContent = count;
    }
}());
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';

