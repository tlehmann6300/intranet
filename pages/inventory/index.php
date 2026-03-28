<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/AuthHandler.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Inventory.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

// Get sync results from session and clear them
$syncResult = $_SESSION['sync_result'] ?? null;
unset($_SESSION['sync_result']);

// Get search / filter parameters
$search = trim($_GET['search'] ?? '');

// Load inventory objects via Inventory model (includes SUM-based rental quantities)
$inventoryObjects = [];
$loadError = null;
try {
    $filters = [];
    if ($search !== '') {
        $filters['search'] = $search;
    }
    $inventoryObjects = Inventory::getAll($filters);
} catch (Exception $e) {
    $loadError = $e->getMessage();
    error_log('Inventory index: fetch failed: ' . $e->getMessage());
}

// Flash messages from checkout redirects
$checkoutSuccess = $_SESSION['checkout_success'] ?? null;
$checkoutError   = $_SESSION['checkout_error']   ?? null;
unset($_SESSION['checkout_success'], $_SESSION['checkout_error']);


$title = 'Inventar - IBC Intranet';
ob_start();
?>

<div id="inventoryContent">
<?php if ($checkoutSuccess): ?>
<div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($checkoutSuccess); ?>
</div>
<?php endif; ?>

<?php if ($checkoutError): ?>
<div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($checkoutError); ?>
</div>
<?php endif; ?>

<!-- Page Header -->
<div class="mb-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-transparent mb-2">
                <i class="fas fa-boxes text-purple-600 mr-3"></i>
                Inventar
            </h1>
            <p class="text-slate-600 dark:text-slate-400 text-lg"><?php echo count($inventoryObjects); ?> Artikel verfügbar</p>
        </div>
        <!-- Action Buttons -->
        <div class="flex gap-3 flex-wrap">
            <a href="my_rentals.php" class="bg-white dark:bg-slate-800 border border-purple-200 dark:border-purple-700 hover:border-purple-400 text-purple-700 dark:text-purple-300 px-5 py-3 rounded-xl flex items-center shadow-sm font-semibold transition-all hover:shadow-md">
                <i class="fas fa-clipboard-list mr-2"></i>Meine Ausleihen
            </a>
            <?php if (AuthHandler::isAdmin()): ?>
            <a href="sync.php" class="bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white px-5 py-3 rounded-xl flex items-center shadow-lg font-semibold transition-all transform hover:scale-105">
                <i class="fas fa-sync-alt mr-2"></i> EasyVerein Sync
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Sync Results -->
<?php if ($syncResult): ?>
<?php
    $syncHasErrors   = !empty($syncResult['errors']);
    $syncTotalFailed = $syncHasErrors
        && ($syncResult['created'] === 0 && $syncResult['updated'] === 0 && $syncResult['archived'] === 0);
    if ($syncTotalFailed) {
        $syncBannerClass = 'bg-red-50 dark:bg-red-900/20 border-red-400 dark:border-red-600';
        $syncTextClass   = 'text-red-700 dark:text-red-300';
        $syncIcon        = 'fa-exclamation-circle';
        $syncTitle       = 'EasyVerein Sync fehlgeschlagen';
    } elseif ($syncHasErrors) {
        $syncBannerClass = 'bg-orange-50 dark:bg-orange-900/20 border-orange-400 dark:border-orange-600';
        $syncTextClass   = 'text-orange-700 dark:text-orange-300';
        $syncIcon        = 'fa-exclamation-triangle';
        $syncTitle       = 'EasyVerein Sync abgeschlossen (mit Fehlern)';
    } else {
        $syncBannerClass = 'bg-green-50 dark:bg-green-900/20 border-green-400 dark:border-green-600';
        $syncTextClass   = 'text-green-700 dark:text-green-300';
        $syncIcon        = 'fa-check-circle';
        $syncTitle       = 'EasyVerein Sync erfolgreich';
    }
?>
<div class="mb-6 p-4 rounded-xl border <?php echo $syncBannerClass; ?> <?php echo $syncTextClass; ?> shadow-sm">
    <div class="flex items-start gap-3">
        <i class="fas <?php echo $syncIcon; ?> mt-0.5 text-xl flex-shrink-0"></i>
        <div class="flex-1 min-w-0">
            <p class="font-bold text-base"><?php echo htmlspecialchars($syncTitle); ?></p>
            <?php if (!$syncTotalFailed): ?>
            <ul class="mt-2 text-sm space-y-0.5">
                <li><i class="fas fa-plus-circle mr-1.5 opacity-70"></i>Erstellt: <strong><?php echo (int)$syncResult['created']; ?></strong> Artikel</li>
                <li><i class="fas fa-pen mr-1.5 opacity-70"></i>Aktualisiert: <strong><?php echo (int)$syncResult['updated']; ?></strong> Artikel</li>
                <li><i class="fas fa-archive mr-1.5 opacity-70"></i>Archiviert: <strong><?php echo (int)$syncResult['archived']; ?></strong> Artikel</li>
            </ul>
            <?php endif; ?>
            <?php if ($syncHasErrors): ?>
            <div class="mt-3">
                <p class="text-sm font-semibold mb-1">
                    <i class="fas fa-bug mr-1.5"></i>
                    <?php echo count($syncResult['errors']); ?> Fehler aufgetreten:
                </p>
                <ul class="text-sm space-y-1 list-none pl-0">
                    <?php foreach ($syncResult['errors'] as $error): ?>
                    <li class="flex items-start gap-1.5">
                        <i class="fas fa-angle-right mt-0.5 flex-shrink-0 opacity-60"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Search Bar -->
<div class="card p-5 mb-8 shadow-lg border border-gray-200 dark:border-slate-700">
    <form method="GET" class="flex gap-3">
        <div class="flex-1">
            <label class="block text-sm font-semibold text-slate-900 dark:text-slate-100 mb-2 flex items-center">
                <i class="fas fa-search mr-2 text-purple-600"></i>Suche
            </label>
            <input
                type="text"
                name="search"
                placeholder="Artikelname oder Beschreibung..."
                value="<?php echo htmlspecialchars($search); ?>"
                class="w-full px-4 py-2.5 border border-gray-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 transition-all"
            >
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="px-5 py-2.5 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white rounded-lg transition-all transform hover:scale-105 shadow-md font-semibold">
                <i class="fas fa-search mr-2"></i>Suchen
            </button>
            <?php if ($search !== ''): ?>
            <a href="index.php" class="px-4 py-2.5 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-all">
                <i class="fas fa-times"></i>
            </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- API Load Error -->
<?php if ($loadError): ?>
<div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
    <i class="fas fa-exclamation-triangle mr-2"></i>
    <strong>Fehler beim Laden der Inventardaten:</strong> <?php echo htmlspecialchars($loadError); ?>
</div>
<?php endif; ?>

<!-- ─── Inventory Grid (full-width) ─── -->
<div>
<div>

<!-- Inventory Grid -->
<?php if (empty($inventoryObjects) && !$loadError): ?>
<div class="card p-12 text-center">
    <i class="fas fa-inbox text-6xl text-gray-300 dark:text-gray-600 mb-4"></i>
    <p class="text-slate-900 dark:text-slate-100 text-lg">Keine Artikel gefunden</p>
    <?php if ($search !== ''): ?>
    <a href="index.php" class="mt-4 inline-block text-purple-600 hover:underline">Alle Artikel anzeigen</a>
    <?php elseif (AuthHandler::isAdmin()): ?>
    <a href="sync.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg inline-flex items-center mt-4">
        <i class="fas fa-sync-alt mr-2"></i> EasyVerein Sync
    </a>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-6">
    <?php foreach ($inventoryObjects as $item):
        $itemId        = $item['id'] ?? '';
        $itemName      = $item['name'] ?? '';
        $itemDesc      = $item['description'] ?? '';
        $itemPieces    = (int)($item['quantity'] ?? 0);
        $itemLoaned    = $itemPieces - (int)$item['available_quantity'];
        $itemAvailable = (int)$item['available_quantity'];
        $rawImage      = $item['image_path'] ?? null;
        if ($rawImage && strpos($rawImage, 'easyverein.com') !== false) {
            $imageSrc = '/api/easyverein_image.php?url=' . urlencode($rawImage);
        } elseif ($rawImage) {
            $imageSrc = '/' . ltrim($rawImage, '/');
        } else {
            $imageSrc = null;
        }
        $hasStock = $itemAvailable > 0;
    ?>
    <div class="group inventory-item-card bg-white dark:bg-slate-800 rounded-2xl shadow-md overflow-hidden border border-gray-100 dark:border-slate-700 flex flex-col">

        <!-- Image Area -->
        <div class="relative h-48 bg-gradient-to-br from-purple-50 via-blue-50 to-indigo-50 dark:from-purple-900/30 dark:via-blue-900/30 dark:to-indigo-900/30 flex items-center justify-center overflow-hidden">
            <?php if ($imageSrc): ?>
            <img src="<?php echo htmlspecialchars($imageSrc); ?>" alt="<?php echo htmlspecialchars($itemName); ?>" class="w-full h-full object-contain group-hover:scale-105 transition-transform duration-500" loading="lazy">
            <?php else: ?>
            <div class="relative">
                <div class="absolute inset-0 bg-gradient-to-br from-purple-200/20 to-blue-200/20 dark:from-purple-800/20 dark:to-blue-800/20 rounded-full blur-2xl"></div>
                <i class="fas fa-box-open text-gray-300 dark:text-gray-600 text-6xl relative z-10" aria-label="Kein Bild verfügbar"></i>
            </div>
            <?php endif; ?>

            <!-- Availability Badge (top-right) -->
            <div class="absolute top-3 right-3">
                <?php if ($hasStock): ?>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold rounded-full bg-green-500 text-white shadow-lg">
                    <i class="fas fa-check-circle"></i><?php echo $itemAvailable; ?> verfügbar
                </span>
                <?php else: ?>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold rounded-full bg-red-500 text-white shadow-lg">
                    <i class="fas fa-times-circle"></i>Vergriffen
                </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Card Content -->
        <div class="p-5 flex flex-col flex-1">
            <h3 class="font-bold text-slate-900 dark:text-white text-lg mb-1 line-clamp-2 group-hover:text-purple-600 dark:group-hover:text-purple-400 transition-colors" title="<?php echo htmlspecialchars($itemName); ?>">
                <?php echo htmlspecialchars($itemName); ?>
            </h3>

            <?php if (!empty($item['category_name'])): ?>
            <span class="inline-block self-start px-2 py-0.5 text-xs rounded-full mb-3 font-semibold" style="background-color: <?php echo htmlspecialchars($item['category_color'] ?? '#8b5cf6'); ?>20; color: <?php echo htmlspecialchars($item['category_color'] ?? '#8b5cf6'); ?>">
                <?php echo htmlspecialchars($item['category_name']); ?>
            </span>
            <?php endif; ?>

            <?php if ($itemDesc !== ''): ?>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-4 line-clamp-2 flex-1" title="<?php echo htmlspecialchars($itemDesc); ?>">
                <?php echo htmlspecialchars($itemDesc); ?>
            </p>
            <?php else: ?>
            <div class="flex-1"></div>
            <?php endif; ?>

            <!-- Stock Info -->
            <div class="grid grid-cols-3 grid-no-stack gap-2 mb-4">
                <div class="text-center px-2 py-2 rounded-xl bg-slate-50 dark:bg-slate-700/50">
                    <p class="text-xs text-slate-400 dark:text-slate-500 mb-0.5">Gesamt</p>
                    <p class="font-bold text-slate-700 dark:text-slate-200 text-sm"><?php echo $itemPieces; ?></p>
                </div>
                <div class="text-center px-2 py-2 rounded-xl bg-orange-50 dark:bg-orange-900/20">
                    <p class="text-xs text-orange-400 dark:text-orange-500 mb-0.5">Ausgeliehen</p>
                    <p class="font-bold text-orange-600 dark:text-orange-400 text-sm"><?php echo $itemLoaned; ?></p>
                </div>
                <div class="text-center px-2 py-2 rounded-xl <?php echo $hasStock ? 'bg-green-50 dark:bg-green-900/20' : 'bg-red-50 dark:bg-red-900/20'; ?>">
                    <p class="text-xs <?php echo $hasStock ? 'text-green-500' : 'text-red-400'; ?> mb-0.5">Verfügbar</p>
                    <p class="font-bold <?php echo $hasStock ? 'text-green-700 dark:text-green-400' : 'text-red-600 dark:text-red-400'; ?> text-sm"><?php echo $itemAvailable; ?></p>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex gap-2">
                <a href="view.php?id=<?php echo htmlspecialchars($itemId); ?>"
                   class="flex items-center justify-center gap-1.5 px-3 py-2.5 bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 text-slate-600 dark:text-slate-300 rounded-xl text-xs font-semibold transition-all"
                   title="Details anzeigen">
                    <i class="fas fa-eye"></i>
                </a>
                <?php if ($hasStock): ?>
                <button
                    type="button"
                    onclick="openLendModal(<?php echo htmlspecialchars(json_encode([
                        'id'       => (string)$itemId,
                        'name'     => $itemName,
                        'pieces'   => $itemAvailable,
                    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>)"
                    class="flex-1 py-2.5 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white rounded-xl font-bold text-sm transition-all transform hover:scale-[1.02] shadow-md hover:shadow-lg flex items-center justify-center gap-2"
                >
                    <i class="fas fa-hand-holding"></i>Ausleihen / Entnehmen
                </button>
                <?php else: ?>
                <button
                    type="button"
                    disabled
                    class="flex-1 py-2.5 bg-gray-200 dark:bg-slate-700 text-gray-400 dark:text-slate-500 rounded-xl font-bold text-sm cursor-not-allowed flex items-center justify-center gap-2"
                >
                    <i class="fas fa-ban"></i>Vergriffen
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</div><!-- /.inventory -->
</div><!-- /.main layout -->
</div><!-- /#inventoryContent -->

<!-- ─── Lending Modal ─── -->
<div id="lendModal"
     class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center p-4"
     role="dialog" aria-modal="true" aria-labelledby="lendModalTitle"
     onclick="if(event.target===this)closeLendModal()">
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto">
        <div class="p-6">
            <!-- Header -->
            <div class="flex items-start justify-between mb-5">
                <div>
                    <h2 id="lendModalTitle" class="text-xl font-extrabold text-slate-900 dark:text-white">Ausleihen / Entnehmen</h2>
                    <p id="lendModalItemName" class="text-sm text-slate-500 dark:text-slate-400 mt-0.5"></p>
                </div>
                <button type="button" onclick="closeLendModal()" class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-slate-100 hover:bg-red-100 dark:bg-slate-700 dark:hover:bg-red-900/40 text-slate-500 hover:text-red-600 dark:text-slate-400 dark:hover:text-red-400 transition-all duration-200 ml-4 flex-shrink-0 shadow-sm" aria-label="Schließen">
                    <i class="fas fa-times text-base"></i>
                </button>
            </div>

            <!-- Form -->
            <form id="lendForm" method="POST" action="">
                <input type="hidden" name="checkout" value="1">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken()); ?>">
                <input type="hidden" name="return_to" value="index">

                <div class="space-y-4">
                    <!-- Quantity -->
                    <div>
                        <label for="lendQuantity" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                            <i class="fas fa-hashtag text-purple-500 mr-1.5"></i>Menge <span class="text-red-500">*</span>
                        </label>
                        <input type="number" id="lendQuantity" name="quantity" min="1" value="1" required
                               class="w-full px-4 py-2.5 border border-gray-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 transition-all">
                    </div>

                    <!-- Date Range -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label for="lendStartDate" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                                <i class="fas fa-calendar-alt text-purple-500 mr-1.5"></i>Von <span class="text-red-500">*</span>
                            </label>
                            <input type="date" id="lendStartDate" name="start_date" required
                                   min="<?php echo date('Y-m-d'); ?>"
                                   value="<?php echo date('Y-m-d'); ?>"
                                   class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 transition-all">
                        </div>
                        <div>
                            <label for="lendEndDate" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                                <i class="fas fa-calendar-alt text-purple-500 mr-1.5"></i>Bis <span class="text-red-500">*</span>
                            </label>
                            <input type="date" id="lendEndDate" name="end_date" required
                                   min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                                   value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                                   class="w-full px-3 py-2.5 border border-gray-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 transition-all">
                        </div>
                    </div>

                    <!-- Purpose -->
                    <div>
                        <label for="lendPurpose" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                            <i class="fas fa-info-circle text-purple-500 mr-1.5"></i>Verwendungszweck
                        </label>
                        <input type="text" id="lendPurpose" name="purpose" maxlength="255"
                               placeholder="z. B. Veranstaltung, Projekt..."
                               class="w-full px-4 py-2.5 border border-gray-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 transition-all">
                    </div>

                    <!-- Destination -->
                    <div>
                        <label for="lendDestination" class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                            <i class="fas fa-map-marker-alt text-purple-500 mr-1.5"></i>Zielort <span class="text-slate-400 dark:text-slate-500 font-normal">(optional)</span>
                        </label>
                        <input type="text" id="lendDestination" name="destination" maxlength="255"
                               placeholder="z. B. Gemeindehaus, Außenlager..."
                               class="w-full px-4 py-2.5 border border-gray-300 dark:border-slate-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 transition-all">
                    </div>
                </div>

                <!-- Submit -->
                <div class="mt-6 flex gap-3">
                    <button type="button" onclick="closeLendModal()"
                            class="flex-1 py-2.5 bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-300 rounded-xl font-semibold text-sm transition-all">
                        Abbrechen
                    </button>
                    <button type="submit"
                            class="flex-1 py-2.5 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white rounded-xl font-bold text-sm transition-all shadow-md hover:shadow-lg flex items-center justify-center gap-2">
                        <i class="fas fa-paper-plane"></i>Anfrage senden
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.line-clamp-3 { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
</style>

<script>
(function () {
    'use strict';

    // Date formatter: returns a YYYY-MM-DD string from a Date object
    function fmtDate(d) {
        return d.getFullYear() + '-'
            + String(d.getMonth() + 1).padStart(2, '0') + '-'
            + String(d.getDate()).padStart(2, '0');
    }

    window.openLendModal = function (item) {
        var modal    = document.getElementById('lendModal');
        var form     = document.getElementById('lendForm');
        var nameEl   = document.getElementById('lendModalItemName');
        var qtyInput = document.getElementById('lendQuantity');

        // Set form action to single-item checkout with this item's id
        form.action = 'checkout.php?id=' + encodeURIComponent(item.id);

        // Update heading
        if (nameEl) { nameEl.textContent = item.name; }

        // Reset quantity and cap at available stock
        qtyInput.max   = item.pieces;
        qtyInput.value = 1;

        // Reset date fields to today / tomorrow
        var today    = new Date();
        var tomorrow = new Date(today);
        tomorrow.setDate(tomorrow.getDate() + 1);
        var startInput = document.getElementById('lendStartDate');
        var endInput   = document.getElementById('lendEndDate');
        if (startInput) { startInput.value = fmtDate(today);    startInput.min = fmtDate(today); }
        if (endInput)   { endInput.value   = fmtDate(tomorrow); endInput.min   = fmtDate(tomorrow); }

        // Reset other fields
        var purposeEl = document.getElementById('lendPurpose');
        var destEl    = document.getElementById('lendDestination');
        if (purposeEl) { purposeEl.value = ''; }
        if (destEl)    { destEl.value    = ''; }

        // Show modal
        modal.classList.remove('hidden');
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        qtyInput.focus();
    };

    window.closeLendModal = function () {
        var modal = document.getElementById('lendModal');
        modal.style.display = '';
        modal.classList.add('hidden');
        document.body.style.overflow = '';
    };

    // Close on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeLendModal(); }
    });

    // Keep end-date min in sync with start-date
    var startInput = document.getElementById('lendStartDate');
    if (startInput) {
        startInput.addEventListener('change', function () {
            var endInput = document.getElementById('lendEndDate');
            if (!endInput) { return; }
            var next = new Date(this.value);
            next.setDate(next.getDate() + 1);
            var minDate = fmtDate(next);
            endInput.min = minDate;
            if (endInput.value < minDate) { endInput.value = minDate; }
        });
    }
}());
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
