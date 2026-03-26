<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Inventory.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';

if (!Auth::check() || !Auth::hasPermission('manager')) {
    header('Location: ../dashboard/index.php');
    exit;
}

$message = '';
$error = '';

// Handle category creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_category'])) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $color = trim($_POST['color'] ?? '#3B82F6');
    
    if (empty($name)) {
        $error = 'Name ist erforderlich';
    } else {
        try {
            Inventory::createCategory($name, $description, $color);
            $message = 'Kategorie erfolgreich erstellt';
        } catch (Exception $e) {
            $error = 'Fehler beim Erstellen der Kategorie: ' . $e->getMessage();
        }
    }
}

$categories = Inventory::getCategories();

$title = 'Kategorien verwalten - IBC Intranet';
ob_start();
?>

<div class="mb-8">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                <i class="fas fa-tags text-purple-600 mr-2"></i>
                Kategorien verwalten
            </h1>
            <p class="text-gray-600"><?php echo count($categories); ?> Kategorien</p>
        </div>
        <div class="mt-4 md:mt-0">
            <a href="../inventory/index.php" class="btn-primary">
                <i class="fas fa-arrow-left mr-2"></i>
                Zurück zum Inventar
            </a>
        </div>
    </div>
</div>

<?php if ($message): ?>
<div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg">
    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($message); ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg">
    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Create Category Form -->
    <div class="lg:col-span-1">
        <div class="card p-6">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-plus text-green-600 mr-2"></i>
                Neue Kategorie
            </h2>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="create_category" value="1">
                <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Name <span class="text-red-500">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="name" 
                        required 
                        class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                        placeholder="z.B. Elektronik"
                    >
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Beschreibung
                    </label>
                    <textarea 
                        name="description" 
                        rows="3"
                        class="w-full px-4 py-2 bg-white border-gray-300 text-gray-900 rounded-lg focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500"
                        placeholder="Optionale Beschreibung..."
                    ></textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Farbe
                    </label>
                    <div class="flex items-center space-x-3">
                        <input 
                            type="color" 
                            name="color" 
                            value="#3B82F6"
                            class="h-10 w-20 bg-white border-gray-300 rounded cursor-pointer dark:bg-gray-800 dark:border-gray-600"
                        >
                        <span class="text-sm text-gray-500">Wähle eine Farbe für die Kategorie</span>
                    </div>
                </div>

                <button type="submit" class="w-full btn-primary">
                    <i class="fas fa-plus mr-2"></i>Kategorie erstellen
                </button>
            </form>
        </div>
    </div>

    <!-- Categories List -->
    <div class="lg:col-span-2">
        <div class="card p-6">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-list text-blue-600 mr-2"></i>
                Bestehende Kategorien
            </h2>
            
            <?php if (empty($categories)): ?>
            <div class="text-center py-8">
                <i class="fas fa-inbox text-6xl text-gray-300 mb-4"></i>
                <p class="text-gray-500">Keine Kategorien vorhanden</p>
            </div>
            <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach ($categories as $category): ?>
                <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition">
                    <div class="flex items-start justify-between mb-2">
                        <div class="flex items-center space-x-3">
                            <div 
                                class="w-4 h-4 rounded-full inline-color-badge" 
                                style="background-color: <?php echo htmlspecialchars($category['color']); ?>"
                            ></div>
                            <h3 class="font-bold text-gray-800"><?php echo htmlspecialchars($category['name']); ?></h3>
                        </div>
                    </div>
                    <?php if ($category['description']): ?>
                    <p class="text-sm text-gray-600 mb-2"><?php echo htmlspecialchars($category['description']); ?></p>
                    <?php endif; ?>
                    <div class="flex items-center justify-between text-xs text-gray-500 mt-3 pt-3 border-t border-gray-200">
                        <span>ID: <?php echo $category['id']; ?></span>
                        <span><?php echo date('d.m.Y', strtotime($category['created_at'])); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
