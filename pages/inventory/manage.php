<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Inventory.php';

// Only board and manager can access
if (!Auth::check() || !Auth::hasPermission('manager')) {
    header('Location: ../auth/login.php');
    exit;
}

// Constants
define('DEFAULT_LOW_STOCK_THRESHOLD', 5);

$message = '';
$error = '';

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    
    $itemId = intval($_POST['item_id'] ?? 0);
    
    try {
        Inventory::delete($itemId);
        $message = 'Artikel erfolgreich gelöscht';
    } catch (Exception $e) {
        $error = 'Fehler beim Löschen: ' . $e->getMessage();
    }
}

// Get filters
$filters = [];
if (!empty($_GET['category_id'])) {
    $filters['category_id'] = $_GET['category_id'];
}
if (!empty($_GET['location_id'])) {
    $filters['location_id'] = $_GET['location_id'];
}
if (!empty($_GET['search'])) {
    $filters['search'] = $_GET['search'];
}
if (isset($_GET['filter']) && $_GET['filter'] === 'low_stock') {
    $filters['low_stock'] = true;
}

$items = Inventory::getAll($filters);
$categories = Inventory::getCategories();
$locations = Inventory::getLocations();

// Stats: pending requests count
$pendingRequestsCount = 0;
$activeLoansCount = 0;
try {
    $db = Database::getContentDB();
    $pendingRequestsCount = (int)$db->query("SELECT COUNT(*) FROM inventory_requests WHERE status = 'pending'")->fetchColumn();
    // 'approved' = board has approved the request; the item is currently on loan
    $activeLoansCount = (int)$db->query("SELECT COUNT(*) FROM inventory_requests WHERE status = 'approved'")->fetchColumn();
} catch (Exception $e) {
    // Silently ignore when the inventory_requests table does not yet exist
    // (early deployments before the schema migration has been applied)
    error_log('manage.php stats: ' . $e->getMessage());
}

$lowStockCount = count(array_filter($items, function($i) {
    $threshold = $i['min_stock'] ?? DEFAULT_LOW_STOCK_THRESHOLD;
    return (int)$i['quantity'] <= $threshold;
}));

$title = 'Inventar-Verwaltung - IBC Intranet';
ob_start();
?>

<!-- Page Header -->
<div class="mb-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-transparent mb-2">
                <i class="fas fa-cogs text-purple-600 mr-3"></i>
                Inventar-Verwaltung
            </h1>
            <p class="text-slate-600 dark:text-slate-400 text-lg"><?php echo count($items); ?> Artikel gefunden</p>
        </div>
        <div class="flex gap-4 flex-wrap">
            <a href="sync.php" class="bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white px-5 py-3 rounded-xl flex items-center shadow-lg font-semibold transition-all duration-300 hover:-translate-y-1 hover:shadow-xl">
                <i class="fas fa-sync-alt mr-2"></i> EasyVerein Sync
            </a>
            <a href="../admin/rental_returns.php" class="bg-gradient-to-r from-green-600 to-green-700 hover:from-green-700 hover:to-green-800 text-white px-5 py-3 rounded-xl flex items-center shadow-lg font-semibold transition-all duration-300 hover:-translate-y-1 hover:shadow-xl">
                <i class="fas fa-clipboard-list mr-2"></i> Anfragen
                <?php if ($pendingRequestsCount > 0): ?>
                <span class="ml-1.5 inline-flex items-center justify-center w-5 h-5 text-xs font-bold bg-white text-green-700 rounded-full"><?php echo $pendingRequestsCount; ?></span>
                <?php endif; ?>
            </a>
            <a href="add.php" class="bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white px-5 py-3 rounded-xl flex items-center shadow-lg font-semibold transition-all duration-300 hover:-translate-y-1 hover:shadow-xl">
                <i class="fas fa-plus mr-2"></i> Neuer Artikel
            </a>
        </div>
    </div>
</div>

<?php if ($message): ?>
<div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-xl flex items-center gap-3">
    <i class="fas fa-check-circle text-green-500 text-lg flex-shrink-0"></i>
    <span><?php echo htmlspecialchars($message); ?></span>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-xl flex items-center gap-3">
    <i class="fas fa-exclamation-circle text-red-500 text-lg flex-shrink-0"></i>
    <span><?php echo htmlspecialchars($error); ?></span>
</div>
<?php endif; ?>

<!-- Stats Section -->
<div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 mb-8">
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-md p-5 border border-gray-100 dark:border-slate-700">
        <div class="flex items-center gap-3 mb-2">
            <div class="w-10 h-10 bg-purple-100 dark:bg-purple-900/40 rounded-xl flex items-center justify-center flex-shrink-0">
                <i class="fas fa-boxes text-purple-600 text-lg"></i>
            </div>
            <span class="text-2xl font-extrabold text-slate-900 dark:text-white"><?php echo count($items); ?></span>
        </div>
        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Artikel gesamt</p>
    </div>
    <a href="../admin/rental_returns.php" class="manage-stat-card bg-white dark:bg-slate-800 rounded-2xl shadow-md p-5 border border-gray-100 dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors">
        <div class="flex items-center gap-3 mb-2">
            <div class="w-10 h-10 bg-yellow-100 dark:bg-yellow-900/40 rounded-xl flex items-center justify-center flex-shrink-0">
                <i class="fas fa-clock text-yellow-600 text-lg"></i>
            </div>
            <span class="text-2xl font-extrabold text-slate-900 dark:text-white"><?php echo $pendingRequestsCount; ?></span>
        </div>
        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Ausstehende Anfragen</p>
    </a>
    <a href="../admin/rental_returns.php#active" class="manage-stat-card bg-white dark:bg-slate-800 rounded-2xl shadow-md p-5 border border-gray-100 dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors">
        <div class="flex items-center gap-3 mb-2">
            <div class="w-10 h-10 bg-green-100 dark:bg-green-900/40 rounded-xl flex items-center justify-center flex-shrink-0">
                <i class="fas fa-sign-out-alt text-green-600 text-lg"></i>
            </div>
            <span class="text-2xl font-extrabold text-slate-900 dark:text-white"><?php echo $activeLoansCount; ?></span>
        </div>
        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Aktive Ausleihen</p>
    </a>
    <a href="manage.php?filter=low_stock" class="manage-stat-card bg-white dark:bg-slate-800 rounded-2xl shadow-md p-5 border border-gray-100 dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors">
        <div class="flex items-center gap-3 mb-2">
            <div class="w-10 h-10 bg-red-100 dark:bg-red-900/40 rounded-xl flex items-center justify-center flex-shrink-0">
                <i class="fas fa-exclamation-triangle text-red-600 text-lg"></i>
            </div>
            <span class="text-2xl font-extrabold text-slate-900 dark:text-white"><?php echo $lowStockCount; ?></span>
        </div>
        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Niedriger Bestand</p>
    </a>
</div>

<!-- Quick Links Section -->
<div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 mb-8">
    <a href="add.php" class="group manage-quick-link bg-white dark:bg-slate-800 rounded-2xl shadow-md p-5 border border-gray-100 dark:border-slate-700 text-center hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors">
        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/40 rounded-xl flex items-center justify-center mx-auto mb-3 group-hover:bg-purple-200 dark:group-hover:bg-purple-900/60 transition-colors">
            <i class="fas fa-plus-circle text-purple-600 text-xl"></i>
        </div>
        <h3 class="font-bold text-slate-800 dark:text-slate-100 text-sm">Artikel hinzufügen</h3>
        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Neuen Artikel erstellen</p>
    </a>
    <a href="../admin/categories.php" class="group manage-quick-link bg-white dark:bg-slate-800 rounded-2xl shadow-md p-5 border border-gray-100 dark:border-slate-700 text-center hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors">
        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/40 rounded-xl flex items-center justify-center mx-auto mb-3 group-hover:bg-blue-200 dark:group-hover:bg-blue-900/60 transition-colors">
            <i class="fas fa-tags text-blue-600 text-xl"></i>
        </div>
        <h3 class="font-bold text-slate-800 dark:text-slate-100 text-sm">Kategorien</h3>
        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Kategorien verwalten</p>
    </a>
    <a href="../admin/locations.php" class="group manage-quick-link bg-white dark:bg-slate-800 rounded-2xl shadow-md p-5 border border-gray-100 dark:border-slate-700 text-center hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors">
        <div class="w-12 h-12 bg-green-100 dark:bg-green-900/40 rounded-xl flex items-center justify-center mx-auto mb-3 group-hover:bg-green-200 dark:group-hover:bg-green-900/60 transition-colors">
            <i class="fas fa-map-marker-alt text-green-600 text-xl"></i>
        </div>
        <h3 class="font-bold text-slate-800 dark:text-slate-100 text-sm">Standorte</h3>
        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Standorte verwalten</p>
    </a>
    <a href="index.php" class="group manage-quick-link bg-white dark:bg-slate-800 rounded-2xl shadow-md p-5 border border-gray-100 dark:border-slate-700 text-center hover:bg-gray-50 dark:hover:bg-slate-700 transition-colors">
        <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900/40 rounded-xl flex items-center justify-center mx-auto mb-3 group-hover:bg-orange-200 dark:group-hover:bg-orange-900/60 transition-colors">
            <i class="fas fa-boxes text-orange-600 text-xl"></i>
        </div>
        <h3 class="font-bold text-slate-800 dark:text-slate-100 text-sm">Inventar Übersicht</h3>
        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Alle Artikel anzeigen</p>
    </a>
</div>

<!-- Filter Section -->
<div class="card p-5 mb-8 shadow-lg border border-gray-200 dark:border-slate-700">
    <h2 class="text-base font-bold text-slate-800 dark:text-slate-100 mb-4 flex items-center">
        <i class="fas fa-filter text-purple-600 mr-2"></i>Filter &amp; Suche
    </h2>
    <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div>
            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wide mb-1.5">
                <i class="fas fa-search mr-1 text-purple-500"></i>Suche
            </label>
            <input type="text" name="search" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" placeholder="Name oder Beschreibung" class="w-full px-4 py-2.5 border border-gray-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 transition-all text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wide mb-1.5">
                <i class="fas fa-tag mr-1 text-blue-500"></i>Kategorie
            </label>
            <select name="category_id" class="w-full px-4 py-2.5 border border-gray-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 transition-all text-sm">
                <option value="">Alle Kategorien</option>
                <?php foreach ($categories as $category): ?>
                <option value="<?php echo $category['id']; ?>" <?php echo (isset($_GET['category_id']) && $_GET['category_id'] == $category['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($category['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wide mb-1.5">
                <i class="fas fa-map-marker-alt mr-1 text-green-500"></i>Standort
            </label>
            <select name="location_id" class="w-full px-4 py-2.5 border border-gray-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 transition-all text-sm">
                <option value="">Alle Standorte</option>
                <?php foreach ($locations as $location): ?>
                <option value="<?php echo $location['id']; ?>" <?php echo (isset($_GET['location_id']) && $_GET['location_id'] == $location['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($location['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wide mb-1.5">
                <i class="fas fa-cubes mr-1 text-orange-500"></i>Bestand
            </label>
            <select name="filter" class="w-full px-4 py-2.5 border border-gray-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 transition-all text-sm">
                <option value="">Alle</option>
                <option value="low_stock" <?php echo (isset($_GET['filter']) && $_GET['filter'] === 'low_stock') ? 'selected' : ''; ?>>Niedriger Bestand</option>
            </select>
        </div>
        <div class="md:col-span-2 lg:col-span-4 flex justify-end gap-4">
            <a href="manage.php" class="px-5 py-2.5 min-h-[44px] inline-flex items-center bg-gray-200 dark:bg-slate-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-slate-600 transition font-semibold text-sm">
                <i class="fas fa-times mr-1.5"></i>Zurücksetzen
            </a>
            <button type="submit" class="px-5 py-2.5 min-h-[44px] bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white rounded-lg transition-all duration-300 hover:-translate-y-0.5 shadow-md font-semibold text-sm">
                <i class="fas fa-search mr-1.5"></i>Filtern
            </button>
        </div>
    </form>
</div>

<!-- Items Grid -->
<?php if (empty($items)): ?>
<div class="card p-12 text-center shadow-lg border border-gray-200 dark:border-slate-700">
    <i class="fas fa-box-open text-6xl text-gray-300 dark:text-gray-600 mb-4"></i>
    <h3 class="text-base sm:text-xl font-semibold text-slate-600 dark:text-slate-300 mb-2">Keine Artikel gefunden</h3>
    <p class="text-slate-500 dark:text-slate-400 mb-6">Es wurden keine Artikel mit den ausgewählten Filtern gefunden.</p>
    <a href="add.php" class="bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white px-5 py-3 rounded-xl font-bold shadow-md transition-all duration-300 hover:-translate-y-1 hover:shadow-xl inline-flex items-center gap-2">
        <i class="fas fa-plus"></i>Ersten Artikel erstellen
    </a>
</div>
<?php else: ?>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
    <?php foreach ($items as $item):
        $loanedQty     = (int)$item['quantity'] - (int)$item['available_quantity'];
        $hasStock      = (int)$item['available_quantity'] > 0;
        $lowStockThreshold = $item['min_stock'] ?? DEFAULT_LOW_STOCK_THRESHOLD;
        $isLowStock    = (int)$item['quantity'] <= $lowStockThreshold;
        $rawImage      = $item['image_path'] ?? null;
        if ($rawImage && strpos($rawImage, 'easyverein.com') !== false) {
            $imageSrc = '/api/easyverein_image.php?url=' . urlencode($rawImage);
        } elseif ($rawImage) {
            $imageSrc = '/' . ltrim($rawImage, '/');
        } else {
            $imageSrc = null;
        }
    ?>
    <div class="group inventory-item-card bg-white dark:bg-slate-800 rounded-2xl shadow-md overflow-hidden border border-gray-100 dark:border-slate-700 flex flex-col">

        <!-- Image Area -->
        <div class="relative h-44 bg-gradient-to-br from-purple-50 via-blue-50 to-indigo-50 dark:from-purple-900/30 dark:via-blue-900/30 dark:to-indigo-900/30 flex items-center justify-center overflow-hidden">
            <?php if ($imageSrc): ?>
            <img src="<?php echo htmlspecialchars($imageSrc); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="w-full h-full object-contain group-hover:scale-105 transition-transform duration-500" loading="lazy">
            <?php else: ?>
            <i class="fas fa-box-open text-gray-300 dark:text-gray-600 text-5xl" aria-label="Kein Bild verfügbar"></i>
            <?php endif; ?>

            <!-- Status Badge -->
            <div class="absolute top-3 right-3">
                <?php if ($isLowStock): ?>
                <span class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-bold rounded-full bg-amber-500 text-white shadow-md">
                    <i class="fas fa-exclamation-triangle"></i>Tief
                </span>
                <?php elseif ($hasStock): ?>
                <span class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-bold rounded-full bg-green-500 text-white shadow-md">
                    <i class="fas fa-check-circle"></i>Verfügbar
                </span>
                <?php else: ?>
                <span class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-bold rounded-full bg-red-500 text-white shadow-md">
                    <i class="fas fa-times-circle"></i>Vergriffen
                </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Card Content -->
        <div class="p-4 flex flex-col flex-1">
            <h3 class="font-bold text-slate-900 dark:text-white text-base mb-2 line-clamp-2 group-hover:text-purple-600 dark:group-hover:text-purple-400 transition-colors" title="<?php echo htmlspecialchars($item['name']); ?>">
                <?php echo htmlspecialchars($item['name']); ?>
            </h3>

            <!-- Meta Info -->
            <div class="space-y-1 mb-3 flex-1">
                <?php if (!empty($item['category_name'])): ?>
                <div class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                    <i class="fas fa-tag text-purple-400 w-3.5"></i>
                    <span class="truncate"><?php echo htmlspecialchars($item['category_name']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($item['location_name'])): ?>
                <div class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                    <i class="fas fa-map-marker-alt text-green-400 w-3.5"></i>
                    <span class="truncate"><?php echo htmlspecialchars($item['location_name']); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Stock Info -->
            <div class="flex items-center justify-between mb-4 p-2.5 rounded-xl <?php echo $hasStock ? 'bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800' : 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800'; ?>">
                <span class="text-xs font-semibold <?php echo $hasStock ? 'text-green-700 dark:text-green-300' : 'text-red-700 dark:text-red-300'; ?> flex items-center gap-1.5">
                    <i class="fas fa-cubes"></i>
                    <?php echo (int)$item['quantity']; ?> | <i class="fas fa-arrow-up text-red-400"></i><?php echo $loanedQty; ?> | <i class="fas fa-check text-green-400"></i><?php echo (int)$item['available_quantity']; ?>
                </span>
                <span class="text-xs text-slate-400 dark:text-slate-500"><?php echo htmlspecialchars($item['unit'] ?? 'Stk'); ?></span>
            </div>

            <!-- Actions -->
            <div class="flex gap-4">
                <a href="view.php?id=<?php echo $item['id']; ?>" class="flex-1 py-2 min-h-[44px] inline-flex items-center justify-center bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-900/50 rounded-lg transition text-xs font-semibold border border-blue-200 dark:border-blue-800">
                    <i class="fas fa-eye mr-1"></i>Ansehen
                </a>
                <a href="edit.php?id=<?php echo $item['id']; ?>" class="flex-1 py-2 min-h-[44px] inline-flex items-center justify-center bg-purple-50 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400 hover:bg-purple-100 dark:hover:bg-purple-900/50 rounded-lg transition text-xs font-semibold border border-purple-200 dark:border-purple-800">
                    <i class="fas fa-edit mr-1"></i>Bearbeiten
                </a>
                <button
                    class="delete-item-btn py-2 px-3 min-h-[44px] min-w-[44px] bg-red-50 dark:bg-red-900/30 text-red-500 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/50 rounded-lg transition text-xs border border-red-200 dark:border-red-800"
                    data-item-id="<?php echo $item['id']; ?>"
                    data-item-name="<?php echo htmlspecialchars($item['name']); ?>"
                    title="Löschen"
                >
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4" style="background: rgba(15,23,42,0.70); backdrop-filter: blur(4px);" role="dialog" aria-modal="true" aria-labelledby="deleteModalTitle">
    <div class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden animate-modal-in">
        <div class="bg-gradient-to-r from-red-500 to-red-600 px-6 py-5 flex items-center gap-3">
            <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center">
                <i class="fas fa-trash text-white text-lg"></i>
            </div>
            <div>
                <h3 id="deleteModalTitle" class="text-lg sm:text-xl font-bold text-white">Artikel löschen</h3>
                <p class="text-red-100 text-xs mt-0.5">Diese Aktion kann nicht rückgängig gemacht werden</p>
            </div>
        </div>
        <div class="px-6 py-5 overflow-y-auto flex-1">
            <p class="text-slate-600 dark:text-slate-300">
                Möchtest Du den Artikel "<span id="deleteItemName" class="font-bold text-slate-800 dark:text-slate-100"></span>" wirklich löschen?
            </p>
        </div>
        <form method="POST" id="deleteForm" class="px-6 pb-5">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
            <input type="hidden" name="item_id" id="deleteItemId" value="">
            <input type="hidden" name="delete_item" value="1">
            <div class="flex gap-3">
                <button type="button" id="closeDeleteModalBtn" class="flex-1 px-4 py-2.5 bg-gray-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-xl hover:bg-gray-200 dark:hover:bg-slate-700 transition font-semibold text-sm border border-gray-200 dark:border-slate-700">
                    <i class="fas fa-times mr-1.5"></i>Abbrechen
                </button>
                <button type="submit" class="flex-1 px-4 py-2.5 bg-red-600 hover:bg-red-700 text-white rounded-xl transition font-semibold text-sm shadow-md">
                    <i class="fas fa-trash mr-1.5"></i>Löschen
                </button>
            </div>
        </form>
    </div>
</div>

<style>
@keyframes modal-in {
    from { opacity: 0; transform: scale(0.95) translateY(8px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}
.animate-modal-in { animation: modal-in 0.2s ease-out; }
.line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
@media (prefers-reduced-motion: reduce) {
    .animate-modal-in { animation: none; }
}

/* Quick-link and stat card tiles: keep heading & description text readable on hover in every mode.
   The tile is an <a> anchor; theme.css forces "color: white !important" on dark-mode links, which
   would make the h3/p labels invisible on the light hover background. We fix this by:
   1. Pinning a readable color on the tile container itself in each mode
   2. Using "color: inherit" (without !important) on child headings/paragraphs so they always follow
      the container, regardless of any global link-color override.
*/
body:not(.dark-mode) .manage-quick-link,
body:not(.dark-mode) .manage-stat-card {
    color: #1e293b !important; /* slate-800 – dark text on white/light-gray backgrounds */
}
body.dark-mode .manage-quick-link,
body.dark-mode .manage-stat-card {
    color: #f1f5f9 !important; /* slate-100 – light text on dark backgrounds */
}
.manage-quick-link h3,
.manage-quick-link p,
.manage-stat-card h3,
.manage-stat-card p,
.manage-stat-card span {
    color: inherit;
}
</style>

<script>
// Delete button event listeners using data attributes
document.querySelectorAll('.delete-item-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        confirmDelete(this.dataset.itemId, this.dataset.itemName);
    });
});

function confirmDelete(itemId, itemName) {
    document.getElementById('deleteItemId').value          = itemId;
    document.getElementById('deleteItemName').textContent  = itemName;
    const modal = document.getElementById('deleteModal');
    modal.classList.remove('hidden');
    modal.style.display          = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeDeleteModal() {
    const modal = document.getElementById('deleteModal');
    modal.classList.add('hidden');
    modal.style.display          = '';
    document.body.style.overflow = 'auto';
}

document.getElementById('closeDeleteModalBtn')?.addEventListener('click', closeDeleteModal);

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDeleteModal(); });

document.getElementById('deleteModal')?.addEventListener('click', e => { if (e.target === e.currentTarget) closeDeleteModal(); });
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
