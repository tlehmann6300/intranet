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
    // UTF-8 BOM for Excel compatibility
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

// Fetch all active rentals
$checkedOutStats = Inventory::getCheckedOutStats();
$activeRentals = array_filter($checkedOutStats['checkouts'], function($r) {
    return $r['status'] === 'rented';
});

// Fetch pending requests (ausstehende Anfragen)
$pendingRequests = Inventory::getPendingRequests();

// Fetch inventory items for Bestandsliste
$inventoryItems = Inventory::getAll();

// Toast messages from redirect
$toastSuccess = isset($_GET['toast_success']) ? htmlspecialchars($_GET['toast_success']) : '';
$toastError   = isset($_GET['toast_error'])   ? htmlspecialchars($_GET['toast_error'])   : '';

$csrfToken = CSRFHandler::getToken();

$title = 'Inventar-Dashboard - IBC Intranet';
ob_start();
?>

<div class="mb-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-boxes text-purple-600 mr-2"></i>
                Inventar-Dashboard
            </h1>
            <p class="text-gray-600">Übersicht aller aktiven Ausleihen</p>
        </div>
        <div class="mt-4 md:mt-0 flex gap-3">
            <a href="?export=csv&filter=<?php echo urlencode($filter); ?>" class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition font-medium">
                <i class="fas fa-file-csv mr-2"></i>
                CSV Export
            </a>
            <a href="../inventory/index.php" class="inline-flex items-center px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition font-medium">
                <i class="fas fa-arrow-left mr-2"></i>
                Zum Inventar
            </a>
        </div>
    </div>
</div>

<!-- Toast Notifications (triggered via URL parameters after redirect) -->
<div id="toast-container" class="fixed top-5 right-5 z-50 flex flex-col gap-3" aria-live="polite"></div>
<?php if ($toastSuccess !== ''): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    showToast(<?php echo json_encode($toastSuccess); ?>, 'success');
});
</script>
<?php endif; ?>
<?php if ($toastError !== ''): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    showToast(<?php echo json_encode($toastError); ?>, 'error');
});
</script>
<?php endif; ?>

<!-- Filter Bar -->
<div class="card p-4 mb-6">
    <form method="GET" action="" class="flex flex-wrap items-center gap-3">
        <label for="filter" class="text-sm font-medium text-gray-700">
            <i class="fas fa-filter text-purple-600 mr-1"></i>Filter:
        </label>
        <select id="filter" name="filter" onchange="this.form.submit()"
                class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-purple-500">
            <option value="all"<?php echo $filter === 'all' ? ' selected' : ''; ?>>Alle anzeigen</option>
            <option value="rented"<?php echo $filter === 'rented' ? ' selected' : ''; ?>>Aktiv verliehen</option>
        </select>
        <?php if ($filter !== 'all'): ?>
            <a href="?" class="text-sm text-gray-500 hover:text-gray-700">
                <i class="fas fa-times mr-1"></i>Filter zurücksetzen
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3 md:gap-6 mb-8">
    <div class="card p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500 mb-1">Aktive Ausleihen</p>
                <p class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100"><?php echo count($activeRentals); ?></p>
            </div>
            <div class="p-3 bg-purple-100 rounded-full">
                <i class="fas fa-hand-holding-box text-purple-600 text-2xl"></i>
            </div>
        </div>
    </div>
    <div class="card p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500 mb-1">Ausgeliehene Artikel gesamt</p>
                <p class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100"><?php echo $checkedOutStats['total_items_out']; ?></p>
            </div>
            <div class="p-3 bg-blue-100 rounded-full">
                <i class="fas fa-cubes text-blue-600 text-2xl"></i>
            </div>
        </div>
    </div>
    <div class="card p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500 mb-1">Ausstehende Anfragen</p>
                <p class="text-2xl sm:text-3xl font-bold <?php echo count($pendingRequests) > 0 ? 'text-yellow-600' : 'text-gray-800 dark:text-gray-100'; ?>">
                    <?php echo count($pendingRequests); ?>
                </p>
            </div>
            <div class="p-3 <?php echo count($pendingRequests) > 0 ? 'bg-yellow-100' : 'bg-gray-100'; ?> rounded-full">
                <i class="fas fa-clock <?php echo count($pendingRequests) > 0 ? 'text-yellow-600' : 'text-gray-400'; ?> text-2xl"></i>
            </div>
        </div>
    </div>
    <div class="card p-6">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-500 mb-1">Überfällig</p>
                <p class="text-2xl sm:text-3xl font-bold <?php echo $checkedOutStats['overdue'] > 0 ? 'text-red-600' : 'text-gray-800 dark:text-gray-100'; ?>">
                    <?php echo $checkedOutStats['overdue']; ?>
                </p>
            </div>
            <div class="p-3 <?php echo $checkedOutStats['overdue'] > 0 ? 'bg-red-100' : 'bg-gray-100'; ?> rounded-full">
                <i class="fas fa-exclamation-triangle <?php echo $checkedOutStats['overdue'] > 0 ? 'text-red-600' : 'text-gray-400'; ?> text-2xl"></i>
            </div>
        </div>
    </div>
</div>

<!-- Ausstehende Anfragen -->
<div class="card p-6 mb-8">
    <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4">
        <i class="fas fa-clock text-yellow-500 mr-2"></i>
        Ausstehende Anfragen
        <?php if (count($pendingRequests) > 0): ?>
            <span class="ml-2 px-2 py-0.5 text-sm bg-yellow-100 text-yellow-700 rounded-full font-semibold pending-count-badge"><?php echo count($pendingRequests); ?></span>
        <?php endif; ?>
    </h2>

    <?php if (empty($pendingRequests)): ?>
    <div class="text-center py-10">
        <i class="fas fa-check-circle text-5xl text-green-300 mb-3"></i>
        <p class="text-gray-500">Keine ausstehenden Anfragen</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto w-full">
        <table class="w-full card-table" id="pending-requests-table">
            <thead class="bg-yellow-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Antragsteller</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Artikel</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Menge</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Zeitraum</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden lg:table-cell">Zweck</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aktionen</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($pendingRequests as $req): ?>
                <tr id="pending-row-<?php echo (int)$req['id']; ?>" class="hover:bg-yellow-50">
                    <td class="px-4 py-3 text-sm text-gray-800" data-label="Antragsteller">
                        <i class="fas fa-user text-gray-400 mr-1"></i>
                        <?php echo htmlspecialchars($req['user_name'] ?? $req['user_email'] ?? 'Unbekannt'); ?>
                        <?php if (!empty($req['user_email']) && $req['user_email'] !== $req['user_name']): ?>
                        <span class="block text-xs text-gray-400"><?php echo htmlspecialchars($req['user_email']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-sm font-semibold text-gray-800" data-label="Artikel">
                        <?php echo htmlspecialchars($req['item_name'] ?? '#' . $req['inventory_object_id']); ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600" data-label="Menge">
                        <span class="font-semibold"><?php echo (int)$req['quantity']; ?></span>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600" data-label="Zeitraum">
                        <?php echo htmlspecialchars(date('d.m.Y', strtotime($req['start_date']))); ?>
                        &ndash;
                        <?php echo htmlspecialchars(date('d.m.Y', strtotime($req['end_date']))); ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600 max-w-xs truncate hidden lg:table-cell" data-label="Zweck" title="<?php echo htmlspecialchars($req['purpose'] ?? ''); ?>">
                        <?php echo htmlspecialchars($req['purpose'] ?? '-'); ?>
                    </td>
                    <td class="px-4 py-3 text-sm" data-label="Status">
                        <span class="px-2 py-1 text-xs bg-yellow-100 text-yellow-700 rounded-full font-semibold">
                            <i class="fas fa-clock mr-1"></i>Ausstehend
                        </span>
                    </td>
                    <td class="px-4 py-3 text-sm" data-label="Aktionen">
                        <div class="flex gap-2">
                            <button
                                onclick="handleRequest(<?php echo (int)$req['id']; ?>, 'approve')"
                                class="inline-flex items-center gap-1 px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white text-xs font-semibold rounded-lg transition"
                                title="Anfrage genehmigen">
                                <i class="fas fa-check"></i>Genehmigen
                            </button>
                            <button
                                onclick="handleRequest(<?php echo (int)$req['id']; ?>, 'reject')"
                                class="inline-flex items-center gap-1 px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white text-xs font-semibold rounded-lg transition"
                                title="Anfrage ablehnen">
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

<!-- Active Rentals Table -->
<div class="card p-6">
    <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4">
        <i class="fas fa-clipboard-list text-blue-600 mr-2"></i>
        Aktive Ausleihen
    </h2>

    <?php if (empty($activeRentals)): ?>
    <div class="text-center py-12">
        <i class="fas fa-inbox text-6xl text-gray-300 mb-4"></i>
        <p class="text-gray-500 text-lg">Keine aktiven Ausleihen vorhanden</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto w-full">
        <table class="w-full card-table">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Benutzer</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Artikel</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Menge</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider hidden lg:table-cell">Ausgeliehen am</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rückgabe bis</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($activeRentals as $rental): ?>
                <?php
                $isOverdue = !empty($rental['expected_return']) && strtotime($rental['expected_return']) < time();
                ?>
                <tr class="hover:bg-gray-50 <?php echo $isOverdue ? 'bg-red-50' : ''; ?>">
                    <td class="px-4 py-3 text-sm text-gray-800" data-label="Benutzer">
                        <i class="fas fa-user text-gray-400 mr-1"></i>
                        <?php echo htmlspecialchars($rental['borrower_name'] ?? $rental['borrower_email'] ?? 'Unbekannt'); ?>
                        <?php if (!empty($rental['borrower_name']) && $rental['borrower_name'] !== $rental['borrower_email'] && $rental['borrower_name'] !== 'Unbekannt'): ?>
                        <span class="block text-xs text-gray-400"><?php echo htmlspecialchars($rental['borrower_email']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-sm" data-label="Artikel">
                        <a href="../inventory/view.php?id=<?php echo $rental['item_id']; ?>" class="font-semibold text-purple-600 hover:text-purple-800">
                            <?php echo htmlspecialchars($rental['item_name']); ?>
                        </a>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600" data-label="Menge">
                        <span class="font-semibold"><?php echo $rental['amount']; ?></span> <?php echo htmlspecialchars($rental['unit']); ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600 hidden lg:table-cell" data-label="Ausgeliehen am">
                        <?php echo date('d.m.Y H:i', strtotime($rental['rented_at'])); ?>
                    </td>
                    <td class="px-4 py-3 text-sm" data-label="Rückgabe bis">
                        <?php if (!empty($rental['expected_return'])): ?>
                            <span class="<?php echo $isOverdue ? 'text-red-600 font-semibold' : 'text-gray-600'; ?>">
                                <?php echo date('d.m.Y', strtotime($rental['expected_return'])); ?>
                            </span>
                            <?php if ($isOverdue): ?>
                                <span class="block text-xs text-red-500">Überfällig!</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-sm" data-label="Status">
                        <?php if ($isOverdue): ?>
                            <span class="px-2 py-1 text-xs bg-red-100 text-red-700 rounded-full">Überfällig</span>
                        <?php else: ?>
                            <span class="px-2 py-1 text-xs bg-green-100 text-green-700 rounded-full">Aktiv</span>
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
<div class="card p-6 mt-8">
    <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4">
        <i class="fas fa-warehouse text-green-600 mr-2"></i>
        Bestandsliste
    </h2>
    <?php if (empty($inventoryItems)): ?>
    <div class="text-center py-12">
        <i class="fas fa-inbox text-6xl text-gray-300 mb-4"></i>
        <p class="text-gray-500 text-lg">Keine Artikel im Inventar vorhanden</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto w-full">
        <table class="w-full card-table">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Artikel</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kategorie</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Gesamtbestand</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Verliehen</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Verfügbar</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                <?php foreach ($inventoryItems as $item): ?>
                <?php $available = (int)$item['available_quantity']; ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-sm" data-label="Artikel">
                        <a href="../inventory/view.php?id=<?php echo $item['id']; ?>" class="font-semibold text-purple-600 hover:text-purple-800">
                            <?php echo htmlspecialchars($item['name']); ?>
                        </a>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600" data-label="Kategorie">
                        <?php if (!empty($item['category_name'])): ?>
                            <span class="px-2 py-1 text-xs rounded-full" style="background-color:<?php echo htmlspecialchars($item['category_color'] ?? '#e5e7eb'); ?>20;color:<?php echo htmlspecialchars($item['category_color'] ?? '#374151'); ?>">
                                <?php echo htmlspecialchars($item['category_name']); ?>
                            </span>
                        <?php else: ?>
                            <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-800 font-semibold" data-label="Gesamtbestand">
                        <?php echo $item['quantity']; ?> <?php echo htmlspecialchars($item['unit']); ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600" data-label="Verliehen">
                        <?php echo (int)$item['quantity'] - (int)$item['available_quantity']; ?>
                    </td>
                    <td class="px-4 py-3 text-sm font-semibold <?php echo $available <= 0 ? 'text-red-600' : ($available <= ($item['min_stock'] ?? 0) ? 'text-yellow-600' : 'text-green-700'); ?>" data-label="Verfügbar">
                        <?php echo $available; ?> <?php echo htmlspecialchars($item['unit']); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
var CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;

function handleRequest(requestId, action) {
    var label = action === 'approve' ? 'genehmigen' : 'ablehnen';
    if (!confirm('Möchten Sie diese Anfrage wirklich ' + label + '?')) {
        return;
    }

    var row = document.getElementById('pending-row-' + requestId);
    if (row) {
        row.style.opacity = '0.5';
        row.style.pointerEvents = 'none';
    }

    fetch('/api/rental_request_action.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({
            action:     action,
            request_id: requestId,
            csrf_token: CSRF_TOKEN
        })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (data.success) {
            showToast(data.message || (action === 'approve' ? 'Anfrage genehmigt' : 'Anfrage abgelehnt'), 'success');
            if (row) {
                row.remove();
            }
            // Update pending count badge
            var pendingRows = document.querySelectorAll('#pending-requests-table tbody tr');
            var badge = document.querySelector('.pending-count-badge');
            if (badge) {
                if (pendingRows.length === 0) {
                    badge.remove();
                } else {
                    badge.textContent = pendingRows.length;
                }
            }
            // If no rows left, show the empty state inline
            if (pendingRows.length === 0) {
                var tbody = document.querySelector('#pending-requests-table');
                if (tbody) {
                    var wrapper = tbody.closest('.overflow-x-auto');
                    if (wrapper) {
                        wrapper.innerHTML = '<div class="text-center py-10">'
                            + '<i class="fas fa-check-circle text-5xl text-green-300 mb-3"></i>'
                            + '<p class="text-gray-500">Keine ausstehenden Anfragen</p>'
                            + '</div>';
                    }
                }
            }
        } else {
            showToast(data.message || 'Fehler bei der Verarbeitung', 'error');
            if (row) {
                row.style.opacity = '1';
                row.style.pointerEvents = '';
            }
        }
    })
    .catch(function (err) {
        showToast('Netzwerkfehler. Bitte erneut versuchen.', 'error');
        if (row) {
            row.style.opacity = '1';
            row.style.pointerEvents = '';
        }
    });
}

function showToast(message, type) {
    var container = document.getElementById('toast-container');
    if (!container) return;
    var toast = document.createElement('div');
    var isSuccess = type === 'success';
    toast.className = 'flex items-center gap-3 px-5 py-3 rounded-xl shadow-lg text-white text-sm font-medium transition-opacity duration-500 '
        + (isSuccess ? 'bg-green-600' : 'bg-red-600');
    var icon = document.createElement('i');
    icon.className = 'fas ' + (isSuccess ? 'fa-check-circle' : 'fa-exclamation-circle');
    var text = document.createElement('span');
    text.textContent = message;
    toast.appendChild(icon);
    toast.appendChild(text);
    container.appendChild(toast);
    setTimeout(function () {
        toast.style.opacity = '0';
        setTimeout(function () { toast.remove(); }, 500);
    }, 4000);
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
