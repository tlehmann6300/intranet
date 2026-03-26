<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Inventory.php';
require_once __DIR__ . '/../../includes/utils/SecureImageUpload.php';

if (!Auth::check() || !Auth::hasPermission('manager')) {
    header('Location: ../auth/login.php');
    exit;
}

// Check if user is board member (can bypass sync requirement for backward compatibility)
$isAdmin = Auth::isBoard();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token first for security
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    
    // Only allow form submission for admin users
    if (!$isAdmin) {
        $error = 'Neue Artikel müssen zuerst in EasyVerein erstellt und dann synchronisiert werden.';
    } else {
    
    $name = $_POST['name'] ?? '';
    $description = $_POST['description'] ?? '';
    $category_id = $_POST['category_id'] ?? null;
    $location_id = $_POST['location_id'] ?? null;
    $quantity = intval($_POST['current_stock'] ?? 0);
    $min_stock = intval($_POST['min_stock'] ?? 0);
    $unit = $_POST['unit'] ?? 'Stück';
    // Handle German decimal format (comma) by replacing it with dot for float conversion
    $unit_price = floatval(str_replace(',', '.', $_POST['unit_price'] ?? '0'));
    $notes = $_POST['notes'] ?? '';
    
    if (empty($name)) {
        $error = 'Name ist erforderlich';
    } else {
        $imagePath = null;
        
        // Handle image upload using secure upload utility
        if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadResult = SecureImageUpload::uploadImage($_FILES['image']);
            
            if ($uploadResult['success']) {
                $imagePath = $uploadResult['path'];
            } else {
                $error = $uploadResult['error'];
            }
        }
        
        if (empty($error)) {
            $data = [
                'name' => $name,
                'description' => $description,
                'category_id' => $category_id,
                'location_id' => $location_id,
                'quantity' => $quantity,
                'min_stock' => $min_stock,
                'unit' => $unit,
                'unit_price' => $unit_price,
                'image_path' => $imagePath,
                'notes' => $notes
            ];
            
            $itemId = Inventory::create($data, $_SESSION['user_id']);
            
            if ($itemId) {
                header('Location: view.php?id=' . $itemId);
                exit;
            } else {
                $error = 'Fehler beim Erstellen des Artikels';
            }
        }
    }
    }
}

$categories = Inventory::getCategories();
$locations = Inventory::getAllLocations();

$title = 'Neuer Artikel - Inventar';
ob_start();
?>

<div class="mb-6">
    <a href="index.php" class="text-purple-600 hover:text-purple-700 inline-flex items-center mb-4">
        <i class="fas fa-arrow-left mr-2"></i>Zurück zum Inventar
    </a>
</div>

<?php if ($error): ?>
<div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<?php if (!$isAdmin): ?>
<div class="mb-6 p-6 bg-yellow-50 border-l-4 border-yellow-400 rounded-lg">
    <div class="flex items-start">
        <div class="flex-shrink-0">
            <i class="fas fa-exclamation-triangle text-yellow-600 text-2xl"></i>
        </div>
        <div class="ml-4">
            <h3 class="text-lg font-semibold text-yellow-800 mb-2">
                <i class="fas fa-sync-alt mr-2"></i>EasyVerein Synchronisation erforderlich
            </h3>
            <p class="text-yellow-700 mb-3">
                <strong>Neue Artikel müssen zuerst in EasyVerein erstellt und dann synchronisiert werden.</strong>
            </p>
            <p class="text-yellow-700 text-sm mb-3">
                Das Hinzufügen von Artikeln über dieses Formular ist deaktiviert. Bitte erstellen Sie neue Artikel in EasyVerein und verwenden Sie dann die Synchronisationsfunktion, um sie in dieses System zu importieren.
            </p>
            <div class="mt-4">
                <a href="sync.php" class="inline-flex items-center px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition">
                    <i class="fas fa-sync-alt mr-2"></i>Zur Synchronisation
                </a>
                <a href="index.php" class="ml-3 inline-flex items-center px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                    <i class="fas fa-arrow-left mr-2"></i>Zurück zum Inventar
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card p-8">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100 mb-2">
        <i class="fas fa-plus text-purple-600 mr-2"></i>
        Neuer Artikel
    </h1>
    <p class="text-gray-600 mb-6">
        <?php if ($isAdmin): ?>
            Erstellen Sie einen neuen Artikel im Inventar
            <span class="text-yellow-600 font-semibold">(Vorstand-Bypass aktiv)</span>
        <?php else: ?>
            Formular ist deaktiviert - Neue Artikel müssen über EasyVerein synchronisiert werden
        <?php endif; ?>
    </p>

    <form method="POST" enctype="multipart/form-data" class="space-y-8" <?php if (!$isAdmin): ?>onsubmit="return false;"<?php endif; ?>>
        <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
        
        <?php $disabledAttr = !$isAdmin ? 'disabled' : ''; ?>
        <?php $readonlyClass = !$isAdmin ? 'bg-gray-100 cursor-not-allowed' : ''; ?>
        
        <!-- Basisdaten Section -->
        <div class="bg-gray-50 rounded-lg p-6 <?php echo $readonlyClass; ?>">
            <h2 class="text-lg sm:text-xl font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                Basisdaten
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Name <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="name" 
                        required 
                        value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 <?php echo $readonlyClass; ?>"
                        placeholder="Artikelname"
                        <?php echo $disabledAttr; ?>
                    >
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Beschreibung</label>
                    <textarea 
                        name="description" 
                        rows="3"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 <?php echo $readonlyClass; ?>"
                        placeholder="Beschreibung des Artikels..."
                        <?php echo $disabledAttr; ?>
                    ><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Kategorie</label>
                    <select 
                        name="category_id" 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 <?php echo $readonlyClass; ?>"
                        <?php echo $disabledAttr; ?>
                    >
                        <option value="">Keine Kategorie</option>
                        <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category['id']; ?>" <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $category['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($category['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Einheit</label>
                    <input 
                        type="text" 
                        name="unit" 
                        value="<?php echo htmlspecialchars($_POST['unit'] ?? 'Stück'); ?>"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 <?php echo $readonlyClass; ?>"
                        placeholder="z.B. Stück, Karton, Liter"
                        <?php echo $disabledAttr; ?>
                    >
                </div>
            </div>
        </div>

        <!-- Bestandsinformationen Section -->
        <div class="bg-gray-50 rounded-lg p-6">
            <h2 class="text-lg sm:text-xl font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-boxes text-green-600 mr-2"></i>
                Bestandsinformationen
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Aktueller Bestand</label>
                    <input 
                        type="number" 
                        name="current_stock" 
                        min="0"
                        value="<?php echo htmlspecialchars($_POST['current_stock'] ?? '0'); ?>"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 <?php echo $readonlyClass; ?>"
                        <?php echo $disabledAttr; ?>
                    >
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Mindestbestand</label>
                    <input 
                        type="number" 
                        name="min_stock" 
                        min="0"
                        value="<?php echo htmlspecialchars($_POST['min_stock'] ?? '0'); ?>"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 <?php echo $readonlyClass; ?>"
                        <?php echo $disabledAttr; ?>
                    >
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Stückpreis (€)</label>
                    <input 
                        type="number" 
                        name="unit_price" 
                        min="0" 
                        step="0.01"
                        value="<?php echo htmlspecialchars($_POST['unit_price'] ?? '0'); ?>"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 <?php echo $readonlyClass; ?>"
                        <?php echo $disabledAttr; ?>
                    >
                </div>
            </div>
        </div>

        <!-- Lagerort Section -->
        <div class="bg-gray-50 rounded-lg p-6">
            <h2 class="text-lg sm:text-xl font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-map-marker-alt text-red-600 mr-2"></i>
                Lagerort
            </h2>
            <div class="grid grid-cols-1 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Standort</label>
                    <select 
                        name="location_id" 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 <?php echo $readonlyClass; ?>"
                        <?php echo $disabledAttr; ?>
                    >
                        <option value="">Kein Standort</option>
                        <?php foreach ($locations as $location): ?>
                        <option value="<?php echo $location['id']; ?>" <?php echo (isset($_POST['location_id']) && $_POST['location_id'] == $location['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($location['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Notizen zum Lagerort</label>
                    <textarea 
                        name="notes" 
                        rows="2"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 <?php echo $readonlyClass; ?>"
                        placeholder="Zusätzliche Notizen zum Lagerort..."
                        <?php echo $disabledAttr; ?>
                    ><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                </div>
            </div>
        </div>

        <!-- Bild Section -->
        <div class="bg-gray-50 rounded-lg p-6">
            <h2 class="text-lg sm:text-xl font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-image text-purple-600 mr-2"></i>
                Bild
            </h2>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Artikelbild hochladen</label>
                <input 
                    type="file" 
                    name="image" 
                    accept="image/jpeg,image/png,image/gif,image/webp"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-purple-50 file:text-purple-700 hover:file:bg-purple-100 <?php echo $readonlyClass; ?>"
                    <?php echo $disabledAttr; ?>
                >
                <p class="text-sm text-gray-500 mt-2">
                    <i class="fas fa-info-circle mr-1"></i>
                    Erlaubt: JPG, PNG, GIF, WebP. Maximum: 5MB
                </p>
            </div>
        </div>

        <!-- Actions -->
        <div class="flex flex-col sm:flex-row justify-end gap-3 pt-4 border-t">
            <a href="index.php" class="px-6 py-3 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition text-center">
                <i class="fas fa-times mr-2"></i>Abbrechen
            </a>
            <button type="submit" class="btn-primary px-6 py-3 <?php echo !$isAdmin ? 'opacity-50 cursor-not-allowed' : ''; ?>" <?php echo $disabledAttr; ?>>
                <i class="fas fa-save mr-2"></i>Artikel erstellen
            </button>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
