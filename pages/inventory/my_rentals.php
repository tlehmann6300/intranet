<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Inventory.php';
require_once __DIR__ . '/../../includes/database.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$userId = (int)Auth::getUserId();

// Load active rentals from the Inventory DB (dbs15419825) – primary source.
// The inventory_rentals table stores direct rentals created via Inventory::createRental().
$rentals = [];
try {
    $dbInventory = Database::getInventoryDB();
    $stmt        = $dbInventory->prepare(
        "SELECT r.id,
                ii.easyverein_item_id,
                1            AS quantity,
                r.start_date AS rented_at,
                r.end_date,
                r.status,
                r.created_at
           FROM inventory_rentals r
           JOIN inventory_items ii ON ii.id = r.item_id
          WHERE r.user_id = ?
            AND r.status IN ('pending', 'active', 'overdue')
          ORDER BY r.created_at DESC"
    );
    $stmt->execute([$userId]);
    $rentals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('my_rentals: inventory_rentals (InventoryDB) query failed: ' . $e->getMessage());
}

// Also load pending/active requests from the Content DB (board-approval workflow).
// These are requests submitted via Inventory::submitRequest() awaiting board sign-off.
try {
    $dbContent = Database::getContentDB();
    $stmt      = $dbContent->prepare(
        "SELECT id,
                inventory_object_id AS easyverein_item_id,
                quantity,
                start_date          AS rented_at,
                end_date,
                status,
                created_at
           FROM inventory_requests
          WHERE user_id = ?
            AND status IN ('pending', 'approved', 'pending_return')
          ORDER BY created_at DESC"
    );
    $stmt->execute([$userId]);
    $requestRentals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rentals        = array_merge($rentals, $requestRentals);
} catch (Exception $e) {
    // Table may not exist in this deployment – silently ignore
}

// Batch-resolve item names from EasyVerein to avoid N+1 API calls.
$itemNames = [];
if (!empty($rentals)) {
    try {
        foreach (Inventory::getAll() as $evItem) {
            $eid = (string)($evItem['easyverein_id'] ?? $evItem['id'] ?? '');
            if ($eid !== '') {
                $itemNames[$eid] = $evItem['name'] ?? '';
            }
        }
    } catch (Exception $e) {
        error_log('my_rentals: EasyVerein fetch failed: ' . $e->getMessage());
    }
}

// Check for success messages
$successMessage = $_SESSION['rental_success'] ?? null;
unset($_SESSION['rental_success']);

$errorMessage = $_SESSION['rental_error'] ?? null;
unset($_SESSION['rental_error']);

$title = 'Meine Ausleihen - IBC Intranet';
ob_start();
?>

<!-- Page Header -->
<div class="mb-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-transparent mb-2">
                <i class="fas fa-clipboard-list text-purple-600 mr-3"></i>
                Meine Ausleihen
            </h1>
            <p class="text-slate-600 dark:text-slate-400 text-lg">
                <?php $cnt = count($rentals); echo $cnt === 0 ? 'Keine aktiven Ausleihen' : ($cnt . ' aktive Ausleihe' . ($cnt !== 1 ? 'n' : '')); ?>
            </p>
        </div>
        <a href="index.php" class="inline-flex items-center gap-2 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white px-5 py-3 rounded-xl font-semibold shadow-lg transition-all transform hover:scale-105">
            <i class="fas fa-plus-circle"></i>
            Artikel ausleihen
        </a>
    </div>
</div>

<?php if ($successMessage): ?>
<div class="mb-6 flex items-center gap-3 p-4 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-700 text-green-800 dark:text-green-300 rounded-2xl shadow-sm">
    <i class="fas fa-check-circle text-green-500 text-lg flex-shrink-0"></i>
    <span><?php echo htmlspecialchars($successMessage); ?></span>
</div>
<?php endif; ?>

<?php if ($errorMessage): ?>
<div class="mb-6 flex items-center gap-3 p-4 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-700 text-red-800 dark:text-red-300 rounded-2xl shadow-sm">
    <i class="fas fa-exclamation-circle text-red-500 text-lg flex-shrink-0"></i>
    <span><?php echo htmlspecialchars($errorMessage); ?></span>
</div>
<?php endif; ?>

<?php if (empty($rentals)): ?>
<!-- Empty State -->
<div class="card p-16 text-center">
    <div class="w-24 h-24 bg-purple-50 dark:bg-purple-900/30 rounded-full flex items-center justify-center mx-auto mb-6">
        <i class="fas fa-inbox text-4xl text-purple-300 dark:text-purple-600"></i>
    </div>
    <h2 class="text-xl font-bold text-slate-700 dark:text-slate-300 mb-2">Keine aktiven Ausleihen</h2>
    <p class="text-slate-500 dark:text-slate-400 mb-6">Du hast aktuell keine laufenden Ausleihvorgänge.</p>
    <a href="index.php" class="inline-flex items-center gap-2 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white px-6 py-3 rounded-xl font-semibold shadow-lg transition-all transform hover:scale-105">
        <i class="fas fa-search"></i>Artikel entdecken
    </a>
</div>
<?php else: ?>
<!-- Rental Cards Grid -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 mb-8">
    <?php foreach ($rentals as $rental):
        $easyvereinId = (string)$rental['easyverein_item_id'];
        $itemName     = $itemNames[$easyvereinId] ?? ('Artikel #' . $easyvereinId);
        $quantity     = (int)$rental['quantity'];
        $rentedAt     = $rental['rented_at']
            ? date('d.m.Y', strtotime($rental['rented_at']))
            : '-';
        $endDate      = !empty($rental['end_date'])
            ? date('d.m.Y', strtotime($rental['end_date']))
            : null;
        $status             = $rental['status'];
        $isAwaitingApproval = $status === 'pending';
        $isAwaitingReturn   = $status === 'pending_return';
        // InventoryDB uses 'active' and 'overdue'; ContentDB requests use 'approved'; legacy ContentDB uses 'active'
        $isActive           = ($status === 'active' || $status === 'approved' || $status === 'overdue');
        $isOverdue          = ($status === 'overdue')
                              || ($isActive && $status !== 'overdue' && !empty($rental['end_date']) && strtotime($rental['end_date']) < strtotime('today'));
        $isEarlyReturn      = $isActive && !empty($rental['end_date']) && strtotime($rental['end_date']) > strtotime('today');

        if ($isAwaitingApproval) {
            $statusLabel = 'Ausstehend';
            $statusIcon  = 'fa-hourglass-half';
            $statusClass = 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-700';
            $cardAccent  = 'border-t-blue-400';
        } elseif ($isAwaitingReturn) {
            $statusLabel = 'Rückgabe in Prüfung';
            $statusIcon  = 'fa-clock';
            $statusClass = 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-700';
            $cardAccent  = 'border-t-amber-400';
        } elseif ($isOverdue) {
            $statusLabel = 'Überfällig';
            $statusIcon  = 'fa-exclamation-circle';
            $statusClass = 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-300 border-red-200 dark:border-red-700';
            $cardAccent  = 'border-t-red-400';
        } else {
            $statusLabel = 'Aktiv';
            $statusIcon  = 'fa-check-circle';
            $statusClass = 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-300 border-green-200 dark:border-green-700';
            $cardAccent  = 'border-t-green-400';
        }
    ?>
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-md border border-gray-100 dark:border-slate-700 border-t-4 <?php echo $cardAccent; ?> flex flex-col overflow-hidden transition-shadow hover:shadow-lg">

        <!-- Card Header -->
        <div class="px-5 pt-5 pb-4 flex items-start justify-between gap-3">
            <div class="flex-1 min-w-0">
                <h3 class="font-bold text-slate-900 dark:text-white text-base leading-snug line-clamp-2" title="<?php echo htmlspecialchars($itemName); ?>">
                    <?php echo htmlspecialchars($itemName); ?>
                </h3>
            </div>
            <span class="flex-shrink-0 inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-semibold rounded-full border <?php echo $statusClass; ?>">
                <i class="fas <?php echo $statusIcon; ?>"></i>
                <?php echo $statusLabel; ?>
            </span>
        </div>

        <!-- Card Body -->
        <div class="px-5 pb-4 flex-1 space-y-3">
            <!-- Quantity -->
            <div class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                <div class="w-7 h-7 rounded-lg bg-purple-50 dark:bg-purple-900/30 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-cubes text-purple-500 text-xs"></i>
                </div>
                <span>Menge: <strong class="text-slate-900 dark:text-white"><?php echo $quantity; ?> Stück</strong></span>
            </div>

            <!-- Date range -->
            <div class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                <div class="w-7 h-7 rounded-lg bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-calendar-alt text-blue-500 text-xs"></i>
                </div>
                <span>
                    Ab <strong class="text-slate-900 dark:text-white"><?php echo htmlspecialchars($rentedAt); ?></strong>
                    <?php if ($endDate): ?>
                    <span class="mx-1 text-slate-400">→</span>
                    <strong class="<?php echo $isOverdue ? 'text-red-600 dark:text-red-400' : 'text-slate-900 dark:text-white'; ?>">
                        <?php echo htmlspecialchars($endDate); ?>
                    </strong>
                    <?php endif; ?>
                </span>
            </div>

            <?php if ($isOverdue): ?>
            <div class="flex items-center gap-2 text-xs text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 rounded-xl px-3 py-2 border border-red-100 dark:border-red-800">
                <i class="fas fa-exclamation-triangle flex-shrink-0"></i>
                <span>Rückgabe überfällig – bitte zurückgeben.</span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Card Footer: Action -->
        <div class="px-5 pb-5">
            <?php if ($isAwaitingApproval): ?>
            <div class="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded-xl text-sm font-medium border border-blue-100 dark:border-blue-800">
                <i class="fas fa-hourglass-half"></i>Wartet auf Genehmigung
            </div>
            <?php elseif ($isAwaitingReturn): ?>
            <div class="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 rounded-xl text-sm font-medium border border-amber-100 dark:border-amber-800">
                <i class="fas fa-clock"></i>Rückgabe wird bestätigt
            </div>
            <?php elseif ($isActive && $status === 'active'): ?>
            <?php $confirmMsg = $isEarlyReturn ? 'Vorzeitige Rückgabe melden? Das Gerät wird sofort wieder freigegeben.' : 'Rückgabe für diesen Artikel melden?'; ?>
            <form method="POST" action="rental.php" onsubmit="return confirm('<?php echo htmlspecialchars($confirmMsg, ENT_QUOTES, 'UTF-8'); ?>')">
                <input type="hidden" name="request_return" value="1">
                <input type="hidden" name="rental_id" value="<?php echo (int)$rental['id']; ?>">
                <button type="submit"
                        class="w-full flex items-center justify-center gap-2 px-4 py-2.5 <?php echo $isEarlyReturn ? 'bg-red-600 hover:bg-red-700' : 'bg-blue-600 hover:bg-blue-700'; ?> text-white rounded-xl font-semibold text-sm transition-all shadow hover:shadow-md">
                    <i class="fas fa-undo-alt"></i><?php echo $isEarlyReturn ? 'Vorzeitig zurückgeben' : 'Zurückgeben'; ?>
                </button>
            </form>
            <?php elseif ($isActive && $status === 'approved'): ?>
            <?php $confirmMsg = $isEarlyReturn ? 'Vorzeitige Rückgabe melden? Das Gerät wird sofort wieder freigegeben.' : 'Rückgabe jetzt melden? Der Vorstand wird benachrichtigt.'; ?>
            <form method="POST" action="rental.php" onsubmit="return confirm('<?php echo htmlspecialchars($confirmMsg, ENT_QUOTES, 'UTF-8'); ?>')">
                <input type="hidden" name="request_return_approved" value="1">
                <input type="hidden" name="request_id" value="<?php echo (int)$rental['id']; ?>">
                <button type="submit"
                        class="w-full flex items-center justify-center gap-2 px-4 py-2.5 <?php echo $isEarlyReturn ? 'bg-red-600 hover:bg-red-700' : 'bg-orange-500 hover:bg-orange-600'; ?> text-white rounded-xl font-semibold text-sm transition-all shadow hover:shadow-md">
                    <i class="fas fa-undo-alt"></i><?php echo $isEarlyReturn ? 'Vorzeitig zurückgeben' : 'Rückgabe melden'; ?>
                </button>
            </form>
            <?php else: ?>
            <div class="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-green-50 dark:bg-green-900/30 text-green-700 dark:text-green-300 rounded-xl text-sm font-medium border border-green-100 dark:border-green-800">
                <i class="fas fa-check-circle"></i>Genehmigt
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- History note -->
<div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-gray-100 dark:border-slate-700 p-5">
    <div class="flex items-start gap-3">
        <div class="w-9 h-9 bg-gray-100 dark:bg-slate-700 rounded-xl flex items-center justify-center flex-shrink-0">
            <i class="fas fa-history text-gray-500 dark:text-gray-400"></i>
        </div>
        <div>
            <h2 class="font-semibold text-slate-700 dark:text-slate-300 mb-1">Verlauf</h2>
            <p class="text-slate-500 dark:text-slate-400 text-sm">
                Der Verlauf zurückgegebener Artikel wird im EasyVerein-Logbuch der jeweiligen Artikel gespeichert.
            </p>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
