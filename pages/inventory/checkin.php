<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Inventory.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$checkoutId = $_GET['id'] ?? null;
if (!$checkoutId) {
    header('Location: my_checkouts.php');
    exit;
}

$checkout = Inventory::getCheckoutById($checkoutId);
if (!$checkout || $checkout['actual_return'] !== null) {
    header('Location: my_checkouts.php');
    exit;
}

$message = '';
$error = '';

// Handle checkin submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkin'])) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    
    $returnedQuantity = intval($_POST['returned_quantity'] ?? 0);
    $isDefective = isset($_POST['is_defective']) && $_POST['is_defective'] === 'yes';
    $defectiveQuantity = $isDefective ? intval($_POST['defective_quantity'] ?? 0) : 0;
    $defectiveReason = $isDefective ? trim($_POST['defective_reason'] ?? '') : null;
    
    if ($returnedQuantity <= 0 || $returnedQuantity > $checkout['amount']) {
        $error = 'Bitte geben Sie eine gültige Rückgabemenge ein';
    } elseif ($isDefective && $defectiveQuantity <= 0) {
        $error = 'Bitte geben Sie die defekte Menge ein';
    } elseif ($isDefective && empty($defectiveReason)) {
        $error = 'Bitte geben Sie einen Grund für den Defekt an';
    } else {
        $result = Inventory::checkinItem($checkoutId, $returnedQuantity, $isDefective, $defectiveQuantity, $defectiveReason);
        
        if ($result['success']) {
            $_SESSION['checkin_success'] = $result['message'];
            header('Location: my_checkouts.php');
            exit;
        } else {
            $error = $result['message'];
        }
    }
}

$title = 'Artikel zurückgeben - ' . htmlspecialchars($checkout['item_name']);
ob_start();
?>

<div class="mb-6">
    <a href="my_checkouts.php" class="text-purple-600 hover:text-purple-700 inline-flex items-center mb-4">
        <i class="fas fa-arrow-left mr-2"></i>Zurück zu meinen Ausleihen
    </a>
</div>

<?php if ($error): ?>
<div class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-300 dark:border-red-700 text-red-700 dark:text-red-300 rounded-xl flex items-center gap-3">
    <i class="fas fa-exclamation-circle flex-shrink-0"></i><span><?php echo htmlspecialchars($error); ?></span>
</div>
<?php endif; ?>

<div class="max-w-2xl mx-auto">
    <div class="card p-6">
        <h1 class="text-xl sm:text-2xl font-bold text-gray-800 mb-6">
            <i class="fas fa-undo text-green-600 mr-2"></i>
            Artikel zurückgeben
        </h1>

        <!-- Checkout Info -->
        <div class="bg-gray-50 dark:bg-gray-800/50 p-4 rounded-xl mb-6 border border-gray-200 dark:border-gray-700">
            <h2 class="font-bold text-lg text-gray-800 dark:text-gray-100 mb-3"><?php echo htmlspecialchars($checkout['item_name']); ?></h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                <div>
                    <p class="text-gray-500">Ausgeliehen am:</p>
                    <p class="font-semibold"><?php echo date('d.m.Y H:i', strtotime($checkout['rented_at'])); ?></p>
                </div>
                <div>
                    <p class="text-gray-500">Menge:</p>
                    <p class="font-semibold"><?php echo $checkout['amount']; ?> <?php echo htmlspecialchars($checkout['unit']); ?></p>
                </div>
                <?php if ($checkout['expected_return']): ?>
                <div class="col-span-2">
                    <p class="text-gray-500">Erwartete Rückgabe:</p>
                    <p class="font-semibold"><?php echo date('d.m.Y', strtotime($checkout['expected_return'])); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Checkin Form -->
        <form method="POST" class="space-y-6" id="checkinForm">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
            <input type="hidden" name="checkin" value="1">

            <div>
                <label class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Rückgabemenge <span class="text-red-500">*</span>
                </label>
                <input 
                    type="number" 
                    name="returned_quantity" 
                    id="returned_quantity"
                    min="1" 
                    max="<?php echo $checkout['amount']; ?>"
                    value="<?php echo $checkout['amount']; ?>"
                    required 
                    class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                >
                <p class="text-xs text-gray-500 mt-1">
                    Ausgeliehene Menge: <?php echo $checkout['amount']; ?> <?php echo htmlspecialchars($checkout['unit']); ?>
                </p>
            </div>

            <div>
                <label class="block w-full text-sm font-medium text-gray-700 mb-3">
                    Ist alles in Ordnung? <span class="text-red-500">*</span>
                </label>
                <div class="space-y-3">
                    <label class="flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-xl cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                        <input 
                            type="radio" 
                            name="is_defective" 
                            value="no" 
                            checked 
                            class="mr-3 w-4 h-4 text-green-600"
                            onchange="toggleDefectiveFields()"
                        >
                        <div>
                            <p class="font-semibold text-gray-800 dark:text-gray-100">Ja, alles in Ordnung</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Alle Artikel sind unbeschädigt</p>
                        </div>
                    </label>
                    
                    <label class="flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-xl cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                        <input 
                            type="radio" 
                            name="is_defective" 
                            value="yes" 
                            class="mr-3 w-4 h-4 text-red-600"
                            onchange="toggleDefectiveFields()"
                        >
                        <div>
                            <p class="font-semibold text-gray-800 dark:text-gray-100">Nein, es gibt Probleme</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Einige Artikel sind beschädigt oder verloren</p>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Defective Items Section (hidden by default) -->
            <div id="defectiveSection" class="hidden space-y-4 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl">
                <h3 class="font-semibold text-red-800 dark:text-red-300">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    Details zu beschädigten/verlorenen Artikeln
                </h3>
                
                <div>
                    <label class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Anzahl beschädigt/verloren <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="number" 
                        name="defective_quantity" 
                        id="defective_quantity"
                        min="1" 
                        max="<?php echo $checkout['amount']; ?>"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500"
                    >
                </div>

                <div>
                    <label class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Grund / Beschreibung <span class="text-red-500">*</span>
                    </label>
                    <textarea 
                        name="defective_reason" 
                        id="defective_reason"
                        rows="4"
                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500"
                        placeholder="Bitte beschreiben Sie, was mit den Artikeln passiert ist..."
                    ></textarea>
                </div>

                <div class="bg-white dark:bg-gray-800 p-3 rounded-lg border border-red-300 dark:border-red-700">
                    <p class="text-sm text-red-800 dark:text-red-300">
                        <i class="fas fa-info-circle mr-1"></i>
                        <strong>Hinweis:</strong> Beschädigte oder verlorene Artikel werden vom Bestand abgezogen und als "Ausschuss" dokumentiert.
                    </p>
                </div>
            </div>

            <div class="bg-blue-50 dark:bg-blue-900/20 border-l-4 border-blue-400 dark:border-blue-600 p-4 rounded-r-lg">
                <p class="text-sm text-blue-700 dark:text-blue-300">
                    <i class="fas fa-info-circle mr-2"></i>
                    Nach der Rückgabe wird der Bestand im Lager entsprechend erhöht.
                </p>
            </div>

            <div class="flex flex-col sm:flex-row gap-3">
                <a href="my_checkouts.php" class="flex-1 px-6 py-3 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-xl hover:bg-gray-300 dark:hover:bg-gray-600 transition text-center font-semibold">
                    Abbrechen
                </a>
                <button type="submit" class="flex-1 btn-primary bg-green-600 hover:bg-green-700">
                    <i class="fas fa-check mr-2"></i>Rückgabe bestätigen
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleDefectiveFields() {
    const isDefective = document.querySelector('input[name="is_defective"]:checked').value === 'yes';
    const defectiveSection = document.getElementById('defectiveSection');
    const defectiveQuantity = document.getElementById('defective_quantity');
    const defectiveReason = document.getElementById('defective_reason');
    
    if (isDefective) {
        defectiveSection.classList.remove('hidden');
        defectiveQuantity.required = true;
        defectiveReason.required = true;
    } else {
        defectiveSection.classList.add('hidden');
        defectiveQuantity.required = false;
        defectiveReason.required = false;
        defectiveQuantity.value = '';
        defectiveReason.value = '';
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
