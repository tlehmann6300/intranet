<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';

if (!Auth::canAccessSystemSettings()) {
    header('Location: /index.php');
    exit;
}

$message = '';
$error = '';

// Get current settings from database or config
$db = Database::getContentDB();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    try {
        // Ensure system_settings table exists (one-time check)
        try {
            $db->query("SELECT 1 FROM system_settings LIMIT 1");
        } catch (Exception $e) {
            // Table doesn't exist, create it
            $db->exec("
                CREATE TABLE IF NOT EXISTS system_settings (
                    setting_key VARCHAR(100) PRIMARY KEY,
                    setting_value TEXT,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    updated_by INT
                )
            ");
        }
        
        if (isset($_POST['update_system_settings'])) {
            // For now, we'll store settings in a simple key-value table
            // In a production system, you'd want a more robust configuration system
            
            $siteName = $_POST['site_name'] ?? 'IBC Intranet';
            $siteDescription = $_POST['site_description'] ?? '';
            $maintenanceMode = isset($_POST['maintenance_mode']) ? 1 : 0;
            $allowRegistration = isset($_POST['allow_registration']) ? 1 : 0;
            
            // Update or insert settings
            $stmt = $db->prepare("
                INSERT INTO system_settings (setting_key, setting_value, updated_by) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)
            ");
            
            $stmt->execute(['site_name', $siteName, $_SESSION['user_id']]);
            $stmt->execute(['site_description', $siteDescription, $_SESSION['user_id']]);
            $stmt->execute(['maintenance_mode', $maintenanceMode, $_SESSION['user_id']]);
            $stmt->execute(['allow_registration', $allowRegistration, $_SESSION['user_id']]);
            
            // Log the action
            $stmt = $db->prepare("INSERT INTO system_logs (user_id, action, entity_type, details, ip_address) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $_SESSION['user_id'],
                'update_system_settings',
                'settings',
                'System-Einstellungen aktualisiert',
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);
            
            $message = 'Einstellungen erfolgreich gespeichert';
        }
    } catch (Exception $e) {
        $error = 'Fehler beim Speichern: ' . $e->getMessage();
    }
}

// Load current settings
function getSetting($db, $key, $default = '') {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        return $result ? $result['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

$siteName = getSetting($db, 'site_name', 'IBC Intranet');
$siteDescription = getSetting($db, 'site_description', '');
$maintenanceMode = getSetting($db, 'maintenance_mode', '0');
$allowRegistration = getSetting($db, 'allow_registration', '1');

$title = 'Systemeinstellungen - IBC Intranet';
ob_start();
?>

<div class="mb-8">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100 mb-2">
        <i class="fas fa-cog text-purple-600 mr-2"></i>
        Systemeinstellungen
    </h1>
    <p class="text-gray-600 dark:text-gray-300">Konfiguriere allgemeine Systemeinstellungen und Parameter</p>
</div>

<!-- Success/Error Messages -->
<?php if ($message): ?>
    <div class="mb-6 p-4 bg-green-100 border border-green-400 text-green-700 rounded-lg flex items-start">
        <i class="fas fa-check-circle mt-0.5 mr-3"></i>
        <span><?php echo htmlspecialchars($message); ?></span>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="mb-6 p-4 bg-red-100 border border-red-400 text-red-700 rounded-lg flex items-start">
        <i class="fas fa-exclamation-circle mt-0.5 mr-3"></i>
        <span><?php echo htmlspecialchars($error); ?></span>
    </div>
<?php endif; ?>

<!-- General Settings -->
<div class="card p-6 mb-6">
    <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
        <i class="fas fa-sliders-h text-blue-600 mr-2"></i>
        Allgemeine Einstellungen
    </h2>
    
    <form method="POST" class="space-y-4">
        <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Website-Name
            </label>
            <input 
                type="text" 
                name="site_name" 
                value="<?php echo htmlspecialchars($siteName); ?>"
                class="w-full px-4 py-2 bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg focus:ring-blue-500 focus:border-blue-500"
                required
            >
        </div>
        
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Website-Beschreibung
            </label>
            <textarea 
                name="site_description" 
                rows="3"
                class="w-full px-4 py-2 bg-white dark:bg-gray-800 border-gray-300 dark:border-gray-600 text-gray-900 dark:text-white rounded-lg focus:ring-blue-500 focus:border-blue-500"
            ><?php echo htmlspecialchars($siteDescription); ?></textarea>
        </div>
        
        <div class="flex items-center space-x-4">
            <label class="flex items-center space-x-2 cursor-pointer min-h-[44px]">
                <input 
                    type="checkbox" 
                    name="maintenance_mode" 
                    <?php echo $maintenanceMode == '1' ? 'checked' : ''; ?>
                    class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                >
                <span class="text-sm text-gray-700 dark:text-gray-300">Wartungsmodus aktivieren</span>
            </label>
        </div>
        
        <div class="flex items-center space-x-4">
            <label class="flex items-center space-x-2 cursor-pointer min-h-[44px]">
                <input 
                    type="checkbox" 
                    name="allow_registration" 
                    <?php echo $allowRegistration == '1' ? 'checked' : ''; ?>
                    class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                >
                <span class="text-sm text-gray-700 dark:text-gray-300">Registrierung erlauben</span>
            </label>
        </div>
        
        <div class="pt-4">
            <button type="submit" name="update_system_settings" class="btn-primary w-full sm:w-auto">
                <i class="fas fa-save mr-2"></i>
                Einstellungen speichern
            </button>
        </div>
    </form>
</div>

<!-- Information Box -->
<div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
    <div class="flex items-start">
        <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 text-xl mr-3 mt-1"></i>
        <div>
            <h3 class="text-sm font-semibold text-blue-900 dark:text-blue-100 mb-1">
                Hinweis zu Systemeinstellungen
            </h3>
            <p class="text-sm text-blue-800 dark:text-blue-200">
                Einige Einstellungen erfordern möglicherweise einen Server-Neustart oder eine Cache-Löschung, um wirksam zu werden.
                Sicherheitseinstellungen (Passwörter, MFA, Zugriffsrichtlinien) werden über Microsoft Entra verwaltet.
            </p>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
