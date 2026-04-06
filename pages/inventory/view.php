<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Inventory.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$itemId = $_GET['id'] ?? null;
if (!$itemId) {
    header('Location: index.php');
    exit;
}

$item = Inventory::getById($itemId);
if (!$item) {
    header('Location: index.php');
    exit;
}

$message = '';
$error = '';

// Handle request return
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_return'])) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    $rentalId = intval($_POST['rental_id'] ?? 0);
    $result = Inventory::requestReturn($rentalId);
    if ($result['success']) {
        $_SESSION['rental_success'] = $result['message'];
    } else {
        $_SESSION['rental_error'] = $result['message'];
    }
    header('Location: view.php?id=' . $itemId);
    exit;
}

// Check for success messages from checkout
if (isset($_SESSION['checkout_success'])) {
    $message = $_SESSION['checkout_success'];
    unset($_SESSION['checkout_success']);
}

// Check for rental messages
if (isset($_SESSION['rental_success'])) {
    $message = $_SESSION['rental_success'];
    unset($_SESSION['rental_success']);
}

if (isset($_SESSION['rental_error'])) {
    $error = $_SESSION['rental_error'];
    unset($_SESSION['rental_error']);
}

// Check for sync results
$syncResult = $_SESSION['sync_result'] ?? null;
unset($_SESSION['sync_result']);


/**
 * Helper function to format history comment/details
 * Handles JSON data smartly - showing changes or summary
 * 
 * @param string $data The comment/details data (plain text or JSON)
 * @return string HTML formatted output
 */
function formatHistoryComment($data) {
    if (empty($data)) {
        return '-';
    }
    
    // Try to decode as JSON
    $json = json_decode($data, true);
    
    // Check if JSON is valid and is an array
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($json)) {
        // Not valid JSON or not an array - return as plain text
        return htmlspecialchars($data);
    }
    
    // Filter out empty values and unwanted fields
    $filtered = array_filter($json, function($value, $key) {
        // Ignore empty values, image paths, and null values
        if (empty($value) && $value !== 0 && $value !== '0') return false;
        if ($key === 'image_path') return false;
        if ($key === 'original_data') return false; // Skip nested import data
        return true;
    }, ARRAY_FILTER_USE_BOTH);
    
    // If no meaningful data after filtering, show generic message
    if (empty($filtered)) {
        return '<span class="text-gray-500 dark:text-gray-400 italic">Details aktualisiert</span>';
    }
    
    // Check if this looks like a full snapshot (many fields typically used in an item)
    $snapshotFields = ['name', 'description', 'category_id', 'location_id', 'quantity', 
                       'min_stock', 'unit', 'unit_price', 'serial_number', 'notes', 'status'];
    $matchCount = count(array_intersect(array_keys($filtered), $snapshotFields));
    
    // If it has many item fields (>= 4), it's likely a full snapshot
    if ($matchCount >= 4) {
        return '<span class="text-gray-500 dark:text-gray-400 italic">Details aktualisiert</span>';
    }
    
    // Format as HTML definition list for changes
    $output = '<dl class="text-xs space-y-1">';
    
    foreach ($filtered as $key => $value) {
        // Format the key for display (convert snake_case to readable format)
        $displayKey = ucfirst(str_replace('_', ' ', $key));
        
        // Handle different value types
        if (is_array($value)) {
            // If array, check if it looks like old/new value pair
            if (isset($value['old']) && isset($value['new'])) {
                $oldValue = htmlspecialchars($value['old']);
                $newValue = htmlspecialchars($value['new']);
                $output .= '<div class="flex gap-2">';
                $output .= '<dt class="font-semibold text-gray-600 dark:text-gray-300 w-28 shrink-0">' . htmlspecialchars($displayKey) . ':</dt>';
                $output .= '<dd class="text-gray-800 dark:text-gray-100">' . $oldValue . ' → ' . $newValue . '</dd>';
                $output .= '</div>';
            } else {
                // Generic array display
                $output .= '<div class="flex gap-2">';
                $output .= '<dt class="font-semibold text-gray-600 dark:text-gray-300 w-28 shrink-0">' . htmlspecialchars($displayKey) . ':</dt>';
                $output .= '<dd class="text-gray-800 dark:text-gray-100">' . htmlspecialchars(json_encode($value)) . '</dd>';
                $output .= '</div>';
            }
        } else {
            // Simple value
            $output .= '<div class="flex gap-2">';
            $output .= '<dt class="font-semibold text-gray-600 dark:text-gray-300 w-28 shrink-0">' . htmlspecialchars($displayKey) . ':</dt>';
            $output .= '<dd class="text-gray-800 dark:text-gray-100">' . htmlspecialchars($value) . '</dd>';
            $output .= '</div>';
        }
    }
    
    $output .= '</dl>';
    return $output;
}

$history = Inventory::getHistory($itemId, 20);
$activeCheckouts = Inventory::getItemCheckouts($itemId);

$rentals = Inventory::getRentalsByItem($itemId);
$currentUserId = Auth::getUserId();
$activeRentals  = array_values(array_filter($rentals, fn($r) => in_array($r['status'], ['active', 'pending_return'])));
$returnedRentals = array_values(array_filter($rentals, fn($r) => $r['status'] === 'returned'));

$title = htmlspecialchars($item['name']) . ' - Inventar';
ob_start();
?>

<div class="mb-6">
    <a href="index.php" class="text-purple-600 hover:text-purple-700 dark:text-purple-400 dark:hover:text-purple-300 inline-flex items-center mb-4 text-lg font-semibold group transition-all">
        <i class="fas fa-arrow-left mr-2 group-hover:-translate-x-1 transition-transform"></i>Zurück zum Inventar
    </a>
</div>

<?php if ($message): ?>
<div class="mb-6 p-4 bg-green-50 dark:bg-green-900/20 border border-green-300 dark:border-green-700 text-green-700 dark:text-green-300 rounded-xl flex items-center gap-3">
    <i class="fas fa-check-circle flex-shrink-0"></i><span><?php echo htmlspecialchars($message); ?></span>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300 rounded-xl flex items-center gap-3">
    <i class="fas fa-exclamation-circle flex-shrink-0"></i><span><?php echo htmlspecialchars($error); ?></span>
</div>
<?php endif; ?>

<?php if ($syncResult): ?>
<div class="mb-6 p-4 rounded-lg bg-blue-100 border border-blue-400 text-blue-700">
    <div class="flex items-start">
        <i class="fas fa-sync-alt mr-3 mt-1"></i>
        <div class="flex-1">
            <p class="font-semibold">EasyVerein Synchronisierung abgeschlossen</p>
            <ul class="mt-2 text-sm">
                <li>✓ Erstellt: <?php echo htmlspecialchars($syncResult['created']); ?> Artikel</li>
                <li>✓ Aktualisiert: <?php echo htmlspecialchars($syncResult['updated']); ?> Artikel</li>
                <li>✓ Archiviert: <?php echo htmlspecialchars($syncResult['archived']); ?> Artikel</li>
            </ul>
            <?php if (!empty($syncResult['errors'])): ?>
            <details class="mt-2">
                <summary class="cursor-pointer text-sm underline">Fehler anzeigen (<?php echo count($syncResult['errors']); ?>)</summary>
                <ul class="mt-2 list-disc list-inside text-sm">
                    <?php foreach ($syncResult['errors'] as $syncError): ?>
                    <li><?php echo htmlspecialchars($syncError); ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-6">
    <!-- Item Details -->
    <div class="lg:col-span-2">
        <div class="card p-5 sm:p-8 shadow-xl border border-gray-200 dark:border-slate-700">
            <div class="flex flex-wrap items-start justify-between mb-6 gap-3">
                <div>
                    <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-transparent mb-3 break-words hyphens-auto"><?php echo htmlspecialchars($item['name']); ?></h1>
                    <div class="flex flex-wrap gap-2">
                        <?php if ($item['category_name']): ?>
                        <span class="px-4 py-2 text-sm font-semibold rounded-full inline-color-badge shadow-md" style="background-color: <?php echo htmlspecialchars($item['category_color']); ?>20; color: <?php echo htmlspecialchars($item['category_color']); ?>">
                            <i class="fas fa-tag mr-1"></i><?php echo htmlspecialchars($item['category_name']); ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($item['location_name']): ?>
                        <span class="px-4 py-2 text-sm font-semibold bg-gradient-to-r from-gray-100 to-gray-200 dark:from-gray-700 dark:to-gray-600 text-gray-700 dark:text-gray-300 rounded-full shadow-md break-words hyphens-auto">
                            <i class="fas fa-map-marker-alt mr-1"></i><?php echo htmlspecialchars($item['location_name']); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (Auth::hasPermission('manager')): ?>
                <div class="flex flex-col md:flex-row gap-2">
                    <a href="edit.php?id=<?php echo $item['id']; ?>" class="btn-primary w-full sm:w-auto shadow-lg hover:shadow-xl transition-all transform hover:scale-105">
                        <i class="fas fa-edit mr-2"></i>Bearbeiten
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Checkout/Borrow Button for all users -->
            <?php if ($item['available_quantity'] > 0): ?>
            <div class="mb-8 flex flex-wrap items-center gap-3">
                <button onclick="openCheckoutModal()" class="inline-flex items-center px-8 py-4 bg-gradient-to-r from-green-600 to-green-700 text-white rounded-xl hover:from-green-700 hover:to-green-800 transition-all transform hover:scale-105 font-bold shadow-lg text-lg" aria-haspopup="dialog">
                    <i class="fas fa-hand-holding-box mr-3" aria-hidden="true"></i>Entnehmen / Ausleihen
                </button>
            </div>
            <?php else: ?>
            <?php endif; ?>

            <!-- Image -->
            <?php if ($item['image_path']): ?>
            <div class="mb-8 bg-gradient-to-br from-gray-50 to-gray-100 dark:from-slate-800 dark:to-slate-700 rounded-2xl flex items-center justify-center p-4">
                <?php
                // Check if image is from EasyVerein and needs proxy
                $imageSrc = $item['image_path'];
                if (strpos($imageSrc, 'easyverein.com') !== false) {
                    // Use proxy for EasyVerein images
                    $imageSrc = '/api/easyverein_image.php?url=' . urlencode($imageSrc);
                } else {
                    // Local image - ensure leading slash
                    $imageSrc = '/' . ltrim($imageSrc, '/');
                }
                ?>
                <img src="<?php echo htmlspecialchars($imageSrc); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="max-w-full max-h-96 object-contain rounded-2xl shadow-2xl border border-gray-200 dark:border-slate-700">
            </div>
            <?php endif; ?>

            <!-- Description -->
            <?php if ($item['description']): ?>
            <div class="mb-8 p-5 bg-gradient-to-br from-gray-50 to-gray-100 dark:from-slate-800 dark:to-slate-700 rounded-xl border border-gray-200 dark:border-slate-600">
                <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-3 flex items-center">
                    <i class="fas fa-align-left mr-2 text-purple-600"></i>Beschreibung
                </h2>
                <p class="text-gray-700 dark:text-gray-300 leading-relaxed break-words hyphens-auto"><?php echo nl2br(htmlspecialchars($item['description'])); ?></p>
            </div>
            <?php endif; ?>

            <!-- Details Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-5 mb-8">
                <div class="p-5 bg-gradient-to-br from-purple-50 to-purple-100 dark:from-purple-900/30 dark:to-purple-800/30 rounded-xl border border-purple-200 dark:border-purple-700 shadow-md">
                    <p class="text-sm text-purple-600 dark:text-purple-400 mb-2 font-semibold flex items-center">
                        <i class="fas fa-cubes mr-2"></i>Aktueller Bestand
                    </p>
                    <p class="text-2xl sm:text-3xl font-extrabold <?php echo $item['quantity'] <= $item['min_stock'] && $item['min_stock'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-800 dark:text-gray-100'; ?>">
                        <?php echo htmlspecialchars($item['quantity']); ?>
                    </p>
                    <p class="text-sm text-purple-600 dark:text-purple-400 font-medium mt-1"><?php echo htmlspecialchars($item['unit']); ?></p>
                </div>
                <div class="p-5 bg-gradient-to-br from-blue-50 to-blue-100 dark:from-blue-900/30 dark:to-blue-800/30 rounded-xl border border-blue-200 dark:border-blue-700 shadow-md">
                    <p class="text-sm text-blue-600 dark:text-blue-400 mb-2 font-semibold flex items-center">
                        <i class="fas fa-layer-group mr-2"></i>Mindestbestand
                    </p>
                    <p class="text-2xl sm:text-3xl font-extrabold text-gray-800 dark:text-gray-100"><?php echo $item['min_stock']; ?></p>
                    <p class="text-sm text-blue-600 dark:text-blue-400 font-medium mt-1"><?php echo htmlspecialchars($item['unit']); ?></p>
                </div>
                <div class="p-5 bg-gradient-to-br from-green-50 to-green-100 dark:from-green-900/30 dark:to-green-800/30 rounded-xl border border-green-200 dark:border-green-700 shadow-md">
                    <p class="text-sm text-green-600 dark:text-green-400 mb-2 font-semibold flex items-center">
                        <i class="fas fa-euro-sign mr-2"></i>Stückpreis
                    </p>
                    <p class="text-2xl sm:text-3xl font-extrabold text-gray-800 dark:text-gray-100"><?php echo number_format($item['unit_price'], 2); ?> €</p>
                </div>
                <div class="p-5 bg-gradient-to-br from-orange-50 to-orange-100 dark:from-orange-900/30 dark:to-orange-800/30 rounded-xl border border-orange-200 dark:border-orange-700 shadow-md">
                    <p class="text-sm text-orange-600 dark:text-orange-400 mb-2 font-semibold flex items-center">
                        <i class="fas fa-coins mr-2"></i>Gesamtwert
                    </p>
                    <p class="text-2xl sm:text-3xl font-extrabold text-gray-800 dark:text-gray-100"><?php echo number_format($item['quantity'] * $item['unit_price'], 2); ?> €</p>
                </div>
            </div>

            <!-- Notes -->
            <?php if ($item['notes']): ?>
            <div class="bg-gradient-to-r from-yellow-50 to-amber-50 dark:from-yellow-900/20 dark:to-amber-900/20 border-l-4 border-yellow-400 dark:border-yellow-600 p-5 rounded-lg shadow-md">
                <p class="text-sm text-gray-700 dark:text-gray-300 leading-relaxed"><strong class="text-yellow-700 dark:text-yellow-400 flex items-center mb-2"><i class="fas fa-sticky-note mr-2"></i>Notizen:</strong> <?php echo nl2br(htmlspecialchars($item['notes'])); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- EasyVerein Notice -->
    <div class="lg:col-span-1">
        <?php if (Auth::hasPermission('manager')): ?>
        <div class="card p-6 mb-6 shadow-xl border border-blue-200 dark:border-blue-700 bg-gradient-to-br from-white to-blue-50 dark:from-slate-800 dark:to-blue-900/20">
            <h2 class="text-xl sm:text-2xl font-bold text-gray-800 dark:text-gray-100 mb-4">
                <i class="fas fa-sync-alt text-blue-600 mr-2"></i>
                Bestandsverwaltung
            </h2>
            <p class="text-sm text-gray-700 dark:text-gray-300 mb-4">
                Der Bestand wird direkt in <strong>EasyVerein</strong> verwaltet. Änderungen müssen dort vorgenommen werden.
            </p>
            <div class="flex flex-wrap gap-2">
            <a href="https://easyverein.com" target="_blank" rel="noopener noreferrer"
               class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-semibold text-sm">
                <i class="fas fa-external-link-alt mr-2"></i>EasyVerein öffnen
            </a>
            <?php if (Auth::canManageUsers()): ?>
            <a href="sync.php?redirect=<?php echo urlencode('view.php?id=' . (int)$itemId); ?>"
               class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition font-semibold text-sm">
                <i class="fas fa-sync-alt mr-2"></i>Jetzt synchronisieren
            </a>
            <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Info -->
        <div class="card p-6 shadow-xl border border-blue-200 dark:border-blue-700 bg-gradient-to-br from-white to-blue-50 dark:from-slate-800 dark:to-blue-900/20">
            <h3 class="font-bold text-gray-800 dark:text-gray-100 mb-4 text-lg flex items-center">
                <i class="fas fa-info-circle text-blue-600 mr-2"></i>Information
            </h3>
            <div class="space-y-3 text-sm">
                <p class="text-gray-600 dark:text-gray-300 flex items-center p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
                    <i class="fas fa-calendar-alt text-purple-500 mr-3 text-lg"></i>
                    <span><strong>Erstellt:</strong><br><?php echo !empty($item['created_at']) ? date('d.m.Y', strtotime($item['created_at'])) : '-'; ?></span>
                </p>
                <p class="text-gray-600 dark:text-gray-300 flex items-center p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
                    <i class="fas fa-clock text-blue-500 mr-3 text-lg"></i>
                    <span><strong>Aktualisiert:</strong><br><?php echo !empty($item['updated_at']) ? date('d.m.Y', strtotime($item['updated_at'])) : '-'; ?></span>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Aktive Ausleihen -->
<div class="card p-5 sm:p-8 mt-8 shadow-xl border border-green-200 dark:border-green-700 bg-gradient-to-br from-white to-green-50 dark:from-slate-800 dark:to-green-900/10">
    <h2 class="text-xl sm:text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6 flex items-center">
        <i class="fas fa-clipboard-list text-green-600 mr-3"></i>
        Aktive Ausleihen
    </h2>
    <?php if (empty($activeRentals)): ?>
    <p class="text-gray-500 dark:text-gray-400 text-center py-8">Keine aktiven Ausleihen vorhanden</p>
    <?php else: ?>
    <div class="table-container has-action-dropdown">
        <table class="w-full ibc-data-table card-table">
            <thead>
                <tr>
                    <th>Ausgeliehen</th>
                    <th>Ausgeliehen von</th>
                    <th>Datum letzte Ausleihe (Start)</th>
                    <th>Datum letzte Rückgabe (Ende)</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($activeRentals as $rental): ?>
                <tr>
                    <td data-label="Ausgeliehen">
                        <span class="font-bold text-lg text-gray-800 dark:text-gray-100"><?php echo (int)$rental['quantity']; ?></span>
                        <span class="text-gray-500 dark:text-gray-400 ml-1"><?php echo htmlspecialchars($item['unit']); ?></span>
                    </td>
                    <td class="font-medium break-all" data-label="Ausgeliehen von">
                        <?php echo htmlspecialchars($rental['user_email'] ?? ('User #' . $rental['user_id'])); ?>
                    </td>
                    <td class="font-medium" data-label="Ausleihe Start">
                        <?php echo !empty($rental['rented_at']) ? date('d.m.Y H:i', strtotime($rental['rented_at'])) : '-'; ?>
                    </td>
                    <td class="font-medium" data-label="Rückgabe Ende">-</td>
                    <td data-label="Aktionen">
                        <?php if ($rental['status'] === 'pending_return'): ?>
                        <span class="px-3 py-1.5 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300">
                            Wird vom Vorstand geprüft
                        </span>
                        <?php elseif ($rental['status'] === 'active' && (int)$rental['user_id'] === (int)$currentUserId): ?>
                        <form method="POST" action="view.php?id=<?php echo (int)$itemId; ?>" class="inline">
                            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
                            <input type="hidden" name="request_return" value="1">
                            <input type="hidden" name="rental_id" value="<?php echo (int)$rental['id']; ?>">
                            <button type="submit" class="inline-flex items-center px-3 py-2 min-h-[44px] bg-orange-500 text-white rounded-lg hover:bg-orange-600 transition text-xs font-semibold">
                                <i class="fas fa-undo mr-1" aria-hidden="true"></i>Rückgabe melden
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Verlauf (Abgeschlossen) -->
<div class="card p-5 sm:p-8 mt-8 shadow-xl border border-gray-200 dark:border-slate-700 bg-gradient-to-br from-white to-gray-50 dark:from-slate-800 dark:to-slate-700/10">
    <h2 class="text-xl sm:text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6 flex items-center">
        <i class="fas fa-history text-gray-600 mr-3"></i>
        Verlauf (Abgeschlossen)
    </h2>
    <?php if (empty($returnedRentals)): ?>
    <p class="text-gray-500 dark:text-gray-400 text-center py-8">Keine abgeschlossenen Ausleihen vorhanden</p>
    <?php else: ?>
    <div class="table-container">
        <table class="w-full ibc-data-table card-table">
            <thead>
                <tr>
                    <th>Ausgeliehen</th>
                    <th>Ausgeliehen von</th>
                    <th>Datum letzte Ausleihe (Start)</th>
                    <th>Datum letzte Rückgabe (Ende)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($returnedRentals as $rental): ?>
                <tr>
                    <td data-label="Ausgeliehen">
                        <span class="font-bold text-lg text-gray-800 dark:text-gray-100"><?php echo (int)$rental['quantity']; ?></span>
                        <span class="text-gray-500 dark:text-gray-400 ml-1"><?php echo htmlspecialchars($item['unit']); ?></span>
                    </td>
                    <td class="font-medium break-all" data-label="Ausgeliehen von">
                        <?php echo htmlspecialchars($rental['user_email'] ?? ('User #' . $rental['user_id'])); ?>
                    </td>
                    <td class="font-medium" data-label="Ausleihe Start">
                        <?php echo !empty($rental['rented_at']) ? date('d.m.Y H:i', strtotime($rental['rented_at'])) : '-'; ?>
                    </td>
                    <td class="font-medium" data-label="Rückgabe Ende">
                        <?php echo !empty($rental['returned_at']) ? date('d.m.Y H:i', strtotime($rental['returned_at'])) : '-'; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- EasyVerein Logbuch -->
<?php
$logbookNote = $item['notes'] ?? '';
if (!empty($logbookNote)):
    $logLines = array_values(array_filter(
        array_map('trim', explode("\n", $logbookNote)),
        fn($l) => $l !== ''
    ));
?>
<div class="card p-5 sm:p-8 mt-8 shadow-xl border border-blue-200 dark:border-blue-700 bg-gradient-to-br from-white to-blue-50 dark:from-slate-800 dark:to-blue-900/10">
    <h2 class="text-xl sm:text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6 flex items-center">
        <i class="fas fa-book text-blue-600 mr-3"></i>
        Logbuch / Historie
    </h2>
    <div class="space-y-2">
        <?php foreach ($logLines as $line):
            $isReturn   = mb_strpos($line, 'ZURÜCKGEGEBEN') !== false;
            $isCheckout = mb_strpos($line, 'AUSGELIEHEN') !== false;
            if ($isReturn) {
                $bgClass = 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-700';
            } elseif ($isCheckout) {
                $bgClass = 'bg-orange-50 dark:bg-orange-900/20 border-orange-200 dark:border-orange-700';
            } else {
                $bgClass = 'bg-gray-50 dark:bg-gray-800/50 border-gray-200 dark:border-gray-700';
            }
        ?>
        <div class="flex items-start p-3 rounded-lg border <?php echo $bgClass; ?>">
            <span class="text-sm text-gray-700 dark:text-gray-300 font-mono whitespace-pre-wrap"><?php echo htmlspecialchars($line); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- History -->
<div class="card p-5 sm:p-8 mt-8 shadow-xl border border-gray-200 dark:border-slate-700">
    <h2 class="text-xl sm:text-2xl font-bold text-gray-800 dark:text-gray-100 mb-6 flex items-center">
        <i class="fas fa-history text-blue-600 mr-3"></i>
        Verlauf
    </h2>
    <?php if (empty($history)): ?>
    <p class="text-gray-500 dark:text-gray-400 text-center py-8">Keine Verlaufsdaten vorhanden</p>
    <?php else: ?>
    <div class="table-container">
        <table class="w-full ibc-data-table card-table">
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Typ</th>
                    <th>Änderung</th>
                    <th>Grund</th>
                    <th>Kommentar</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $entry): ?>
                <tr>
                    <td class="font-medium" data-label="Datum">
                        <?php echo date('d.m.Y H:i', strtotime($entry['created_at'])); ?>
                    </td>
                    <td data-label="Typ">
                        <?php
                        $typeClasses = [
                            'adjustment' => 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300',
                            'create' => 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
                            'update' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300',
                            'delete' => 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300',
                            'checkout' => 'bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300',
                            'checkin' => 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
                            'writeoff' => 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300'
                        ];
                        $badgeClass = $typeClasses[$entry['change_type']] ?? 'bg-gray-100 text-gray-700 dark:bg-gray-900 dark:text-gray-300';
                        $typeLabels = [
                            'adjustment' => 'Anpassung',
                            'create' => 'Erstellt',
                            'update' => 'Aktualisiert',
                            'delete' => 'Gelöscht',
                            'checkout' => 'Ausgeliehen',
                            'checkin' => 'Zurückgegeben',
                            'writeoff' => 'Ausschuss'
                        ];
                        $label = $typeLabels[$entry['change_type']] ?? $entry['change_type'];
                        ?>
                        <span class="px-3 py-1.5 text-xs font-semibold <?php echo $badgeClass; ?> rounded-full">
                            <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </td>
                    <td data-label="Änderung">
                        <?php if ($entry['change_type'] === 'adjustment'): ?>
                        <span class="font-bold text-lg <?php echo $entry['change_amount'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'; ?>">
                            <?php echo ($entry['change_amount'] >= 0 ? '+' : '') . $entry['change_amount']; ?>
                        </span>
                        <span class="text-gray-500 dark:text-gray-400 text-xs ml-2">
                            (<?php echo $entry['old_stock']; ?> → <?php echo $entry['new_stock']; ?>)
                        </span>
                        <?php else: ?>
                        <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="font-medium" data-label="Grund">
                        <?php echo htmlspecialchars($entry['reason'] ?? '-'); ?>
                    </td>
                    <td data-label="Kommentar">
                        <?php 
                        // Use helper function to format history comment/details
                        $details = $entry['details'] ?? $entry['comment'] ?? '';
                        echo formatHistoryComment($details);
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Combined Checkout/Rental Modal -->
<div id="checkoutModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="checkout-modal-title">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden">
        <form id="combinedCheckoutForm" method="POST" action="checkout.php?id=<?php echo htmlspecialchars($item['id']); ?>" class="flex flex-col flex-1 min-h-0">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
            <input type="hidden" name="checkout" value="1">

            <div class="p-6 overflow-y-auto flex-1">
                <div class="flex justify-between items-center mb-4">
                    <h2 id="checkout-modal-title" class="text-xl sm:text-2xl font-bold text-gray-800 dark:text-gray-100">
                        <i class="fas fa-hand-holding-box text-green-600 mr-2" aria-hidden="true"></i>
                        Entnehmen / Ausleihen
                    </h2>
                    <button type="button" onclick="closeCheckoutModal()" class="inline-flex items-center justify-center w-9 h-9 rounded-full bg-gray-100 hover:bg-red-100 dark:bg-gray-700 dark:hover:bg-red-900/40 text-gray-500 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400 transition-all duration-200 shadow-sm flex-shrink-0" aria-label="Dialog schließen">
                        <i class="fas fa-times text-base" aria-hidden="true"></i>
                    </button>
                </div>

                <div class="bg-gray-50 dark:bg-gray-700 p-3 rounded-lg mb-4">
                    <p class="font-semibold text-gray-800 dark:text-gray-100"><?php echo htmlspecialchars($item['name']); ?></p>
                    <p class="text-sm text-gray-600 dark:text-gray-300">Verfügbar: <?php echo htmlspecialchars(max(0, $item['available_quantity'])); ?> <?php echo htmlspecialchars($item['unit']); ?></p>
                </div>

                <div class="space-y-4">
                    <div>
                        <label for="checkout-start-date" class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Startdatum der Ausleihe <span class="text-red-500" aria-hidden="true">*</span>
                        </label>
                        <input
                            type="date"
                            id="checkout-start-date"
                            name="start_date"
                            required
                            aria-required="true"
                            value="<?php echo date('Y-m-d'); ?>"
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-gray-100"
                        >
                    </div>

                    <div>
                        <label for="checkoutQuantity" class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Menge <span class="text-red-500" aria-hidden="true">*</span>
                        </label>
                        <input
                            type="number"
                            id="checkoutQuantity"
                            name="quantity"
                            required
                            aria-required="true"
                            min="1"
                            max="<?php echo htmlspecialchars(max(0, $item['available_quantity'])); ?>"
                            value="1"
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-gray-100"
                            placeholder="Anzahl eingeben"
                        >
                    </div>

                    <div>
                        <label for="checkout-purpose" class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Zweck / Notiz
                        </label>
                        <textarea
                            id="checkout-purpose"
                            name="purpose"
                            rows="3"
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-gray-100"
                            placeholder="Optionale Angabe zum Verwendungszweck"
                        ></textarea>
                    </div>

                    <div>
                        <label for="checkoutReturnDate" class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Voraussichtliches Rückgabedatum
                        </label>
                        <input
                            type="date"
                            id="checkoutReturnDate"
                            name="expected_return_at"
                            min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-gray-100"
                        >
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Leer lassen, falls es sich um eine dauerhafte Entnahme handelt.</p>
                    </div>
                </div>
            </div>

            <div class="flex flex-col md:flex-row gap-3 px-6 pb-6 pt-2">
                <button type="button" onclick="closeCheckoutModal()" class="flex-1 px-4 py-3 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition">
                    Abbrechen
                </button>
                <button type="submit" class="flex-1 px-4 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                    <i class="fas fa-check mr-2" aria-hidden="true"></i>Bestätigen
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openCheckoutModal() {
    const modal = document.getElementById('checkoutModal');
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeCheckoutModal() {
    const modal = document.getElementById('checkoutModal');
    modal.classList.add('hidden');
    modal.style.display = '';
    document.body.style.overflow = 'auto';
}

// Close modal when clicking outside
document.getElementById('checkoutModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeCheckoutModal();
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeCheckoutModal();
    }
});


</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
