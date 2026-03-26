<?php
/**
 * Database Maintenance Tool
 * Admin page for database cleanup and maintenance
 * Only accessible by board members
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';

// Check if user is a board member (board_finance, board_internal, or board_external)
if (!Auth::check() || !Auth::isBoard()) {
    header('Location: ../auth/login.php');
    exit;
}

$message = '';
$error = '';
$actionResult = [];

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['clean_logs'])) {
            // Clean old logs
            $userDb = Database::getUserDB();
            $contentDb = Database::getContentDB();
            
            // Delete user_sessions older than 30 days
            $stmt = $userDb->prepare("DELETE FROM user_sessions WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $stmt->execute();
            $sessionsDeleted = $stmt->rowCount();
            
            // Delete system_logs older than 1 year
            $stmt = $contentDb->prepare("DELETE FROM system_logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL 1 YEAR)");
            $stmt->execute();
            $systemLogsDeleted = $stmt->rowCount();
            
            // Delete inventory_history older than 1 year
            $stmt = $contentDb->prepare("DELETE FROM inventory_history WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)");
            $stmt->execute();
            $inventoryHistoryDeleted = $stmt->rowCount();
            
            // Delete event_history older than 1 year
            $stmt = $contentDb->prepare("DELETE FROM event_history WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR)");
            $stmt->execute();
            $eventHistoryDeleted = $stmt->rowCount();
            
            $actionResult = [
                'type' => 'success',
                'title' => 'Logs bereinigt',
                'details' => [
                    "User Sessions gelöscht: $sessionsDeleted",
                    "System Logs gelöscht: $systemLogsDeleted",
                    "Inventory History gelöscht: $inventoryHistoryDeleted",
                    "Event History gelöscht: $eventHistoryDeleted"
                ]
            ];
            
            // Log the action
            $stmt = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_SESSION['user_id'],
                'cleanup_logs',
                'maintenance',
                null,
                json_encode($actionResult['details']),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
        } elseif (isset($_POST['clear_cache'])) {
            // Clear cache folder
            $cacheDir = __DIR__ . '/../../cache';
            $filesDeleted = 0;
            $spaceFreed = 0;
            
            if (is_dir($cacheDir)) {
                $files = glob($cacheDir . '/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        $spaceFreed += filesize($file);
                        if (unlink($file)) {
                            $filesDeleted++;
                        }
                    }
                }
            }
            
            $actionResult = [
                'type' => 'success',
                'title' => 'Cache geleert',
                'details' => [
                    "Dateien gelöscht: $filesDeleted",
                    "Speicherplatz freigegeben: " . formatBytes($spaceFreed)
                ]
            ];
            
            // Log the action
            $contentDb = Database::getContentDB();
            $stmt = $contentDb->prepare("INSERT INTO system_logs (user_id, action, entity_type, entity_id, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_SESSION['user_id'],
                'clear_cache',
                'maintenance',
                null,
                json_encode($actionResult['details']),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        }
    } catch (Exception $e) {
        $actionResult = [
            'type' => 'error',
            'title' => 'Fehler',
            'details' => [$e->getMessage()]
        ];
    }
}

/**
 * Format bytes to human-readable size
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Get database table sizes
 */
function getTableSizes() {
    try {
        $userDb = Database::getUserDB();
        $contentDb = Database::getContentDB();
        
        $tables = [];
        
        // Get user database tables
        $stmt = $userDb->prepare("
            SELECT 
                table_name as 'table',
                ROUND((data_length + index_length) / 1024 / 1024, 2) as 'size_mb',
                table_rows as 'rows'
            FROM information_schema.TABLES 
            WHERE table_schema = ?
            ORDER BY (data_length + index_length) DESC
        ");
        $stmt->execute([DB_USER_NAME]);
        $userTables = $stmt->fetchAll();
        
        // Get content database tables
        $stmt = $contentDb->prepare("
            SELECT 
                table_name as 'table',
                ROUND((data_length + index_length) / 1024 / 1024, 2) as 'size_mb',
                table_rows as 'rows'
            FROM information_schema.TABLES 
            WHERE table_schema = ?
            ORDER BY (data_length + index_length) DESC
        ");
        $stmt->execute([DB_CONTENT_NAME]);
        $contentTables = $stmt->fetchAll();
        
        return [
            'user' => $userTables,
            'content' => $contentTables
        ];
    } catch (Exception $e) {
        return [
            'user' => [],
            'content' => [],
            'error' => $e->getMessage()
        ];
    }
}

$tableSizes = getTableSizes();

// Calculate totals
$userDbTotal = array_sum(array_column($tableSizes['user'], 'size_mb'));
$contentDbTotal = array_sum(array_column($tableSizes['content'], 'size_mb'));
$totalSize = $userDbTotal + $contentDbTotal;

/**
 * Get System Health Metrics
 */
function getSystemHealth() {
    $health = [];
    
    try {
        // Database Connection Status
        $userDb = Database::getUserDB();
        $contentDb = Database::getContentDB();
        
        $health['database_status'] = 'healthy';
        $health['database_message'] = 'Beide Datenbanken sind erreichbar';
        
        // Check for recent errors in logs
        $stmt = $contentDb->query("
            SELECT COUNT(*) as error_count 
            FROM system_logs 
            WHERE action LIKE '%error%' 
            AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $errorCount = $stmt->fetch()['error_count'] ?? 0;
        $health['error_count_24h'] = $errorCount;
        $health['error_status'] = $errorCount > 10 ? 'warning' : 'healthy';
        
        // Check disk usage (database size) - using parameterized query
        $stmt = $userDb->prepare("
            SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size 
            FROM information_schema.TABLES 
            WHERE table_schema IN (?, ?)
        ");
        $stmt->execute([DB_USER_NAME, DB_CONTENT_NAME]);
        $health['disk_usage_mb'] = $stmt->fetch()['size'] ?? 0;
        
        // System uptime (based on oldest active session)
        $stmt = $userDb->query("
            SELECT TIMESTAMPDIFF(HOUR, MIN(created_at), NOW()) as uptime_hours 
            FROM user_sessions 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $uptimeHours = $stmt->fetch()['uptime_hours'] ?? 0;
        $health['uptime_days'] = floor($uptimeHours / 24);
        
        // Active sessions count
        $stmt = $userDb->query("
            SELECT COUNT(*) as active_sessions 
            FROM user_sessions 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
        ");
        $health['active_sessions'] = $stmt->fetch()['active_sessions'] ?? 0;
        
        // Recent login attempts (last hour)
        $stmt = $contentDb->query("
            SELECT COUNT(*) as recent_logins 
            FROM system_logs 
            WHERE action IN ('login', 'login_success') 
            AND timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $health['recent_logins'] = $stmt->fetch()['recent_logins'] ?? 0;
        
        // Failed login attempts (last hour)
        $stmt = $contentDb->query("
            SELECT COUNT(*) as failed_logins 
            FROM system_logs 
            WHERE action = 'login_failed' 
            AND timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $health['failed_logins'] = $stmt->fetch()['failed_logins'] ?? 0;
        $health['security_status'] = $health['failed_logins'] > 20 ? 'warning' : 'healthy';
        
        // Overall system status
        $health['overall_status'] = ($health['database_status'] === 'healthy' && 
                                      $health['error_status'] === 'healthy' && 
                                      $health['security_status'] === 'healthy') 
                                      ? 'healthy' : 'warning';
        
    } catch (Exception $e) {
        $health['database_status'] = 'error';
        $health['database_message'] = 'Datenbankverbindung fehlgeschlagen: ' . $e->getMessage();
        $health['overall_status'] = 'error';
    }
    
    return $health;
}

$systemHealth = getSystemHealth();

$title = 'System Health & Wartung - IBC Intranet';
ob_start();
?>

<div class="mb-8">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100 mb-2">
        <i class="fas fa-heartbeat text-blue-600 mr-2"></i>
        System Health & Wartung
    </h1>
    <p class="text-gray-600 dark:text-gray-300">Systemüberwachung, Datenbankverwaltung und Wartungsaktionen</p>
</div>

<?php if (!empty($actionResult)): ?>
<div class="mb-6 p-4 rounded-lg border <?php echo $actionResult['type'] === 'success' ? 'bg-green-50 border-green-400 text-green-700' : 'bg-red-50 border-red-400 text-red-700'; ?>">
    <h3 class="font-semibold mb-2">
        <i class="fas fa-<?php echo $actionResult['type'] === 'success' ? 'check' : 'exclamation'; ?>-circle mr-2"></i>
        <?php echo htmlspecialchars($actionResult['title']); ?>
    </h3>
    <ul class="list-disc list-inside ml-4">
        <?php foreach ($actionResult['details'] as $detail): ?>
        <li><?php echo htmlspecialchars($detail); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- System Health Status -->
<div class="card p-6 mb-6 <?php 
    $statusColor = $systemHealth['overall_status'] === 'healthy' ? 'border-l-4 border-green-500' : 
                   ($systemHealth['overall_status'] === 'warning' ? 'border-l-4 border-yellow-500' : 'border-l-4 border-red-500'); 
    echo $statusColor;
?>">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <h2 class="text-lg sm:text-xl font-semibold text-gray-800 dark:text-gray-100">
            <i class="fas fa-heartbeat text-<?php echo $systemHealth['overall_status'] === 'healthy' ? 'green' : ($systemHealth['overall_status'] === 'warning' ? 'yellow' : 'red'); ?>-600 mr-2"></i>
            System Health Status
        </h2>
        <div class="flex items-center space-x-2">
            <span class="px-4 py-2 rounded-full text-sm font-semibold <?php 
                echo $systemHealth['overall_status'] === 'healthy' ? 'bg-green-100 text-green-800' : 
                     ($systemHealth['overall_status'] === 'warning' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800');
            ?>">
                <?php 
                    echo $systemHealth['overall_status'] === 'healthy' ? '✓ Systemgesund' : 
                         ($systemHealth['overall_status'] === 'warning' ? '⚠ Warnung' : '✗ Fehler');
                ?>
            </span>
        </div>
    </div>
    
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Database Status -->
        <div class="bg-<?php echo $systemHealth['database_status'] === 'healthy' ? 'green' : 'red'; ?>-50 dark:bg-<?php echo $systemHealth['database_status'] === 'healthy' ? 'green' : 'red'; ?>-900/20 p-4 rounded-lg border border-<?php echo $systemHealth['database_status'] === 'healthy' ? 'green' : 'red'; ?>-200 dark:border-<?php echo $systemHealth['database_status'] === 'healthy' ? 'green' : 'red'; ?>-800">
            <div class="flex items-center justify-between mb-2">
                <i class="fas fa-database text-2xl text-<?php echo $systemHealth['database_status'] === 'healthy' ? 'green' : 'red'; ?>-600"></i>
                <i class="fas fa-<?php echo $systemHealth['database_status'] === 'healthy' ? 'check-circle' : 'times-circle'; ?> text-<?php echo $systemHealth['database_status'] === 'healthy' ? 'green' : 'red'; ?>-600"></i>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-300 mb-1">Datenbank</p>
            <p class="text-lg font-bold text-<?php echo $systemHealth['database_status'] === 'healthy' ? 'green' : 'red'; ?>-700 dark:text-<?php echo $systemHealth['database_status'] === 'healthy' ? 'green' : 'red'; ?>-400">
                <?php echo $systemHealth['database_status'] === 'healthy' ? 'Verbunden' : 'Fehler'; ?>
            </p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                <?php echo htmlspecialchars($systemHealth['database_message']); ?>
            </p>
        </div>
        
        <!-- Error Count -->
        <div class="bg-<?php echo $systemHealth['error_status'] === 'healthy' ? 'blue' : 'yellow'; ?>-50 dark:bg-<?php echo $systemHealth['error_status'] === 'healthy' ? 'blue' : 'yellow'; ?>-900/20 p-4 rounded-lg border border-<?php echo $systemHealth['error_status'] === 'healthy' ? 'blue' : 'yellow'; ?>-200 dark:border-<?php echo $systemHealth['error_status'] === 'healthy' ? 'blue' : 'yellow'; ?>-800">
            <div class="flex items-center justify-between mb-2">
                <i class="fas fa-exclamation-triangle text-2xl text-<?php echo $systemHealth['error_status'] === 'healthy' ? 'blue' : 'yellow'; ?>-600"></i>
                <i class="fas fa-<?php echo $systemHealth['error_status'] === 'healthy' ? 'check-circle' : 'exclamation-circle'; ?> text-<?php echo $systemHealth['error_status'] === 'healthy' ? 'blue' : 'yellow'; ?>-600"></i>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-300 mb-1">Fehler (24h)</p>
            <p class="text-lg font-bold text-<?php echo $systemHealth['error_status'] === 'healthy' ? 'blue' : 'yellow'; ?>-700 dark:text-<?php echo $systemHealth['error_status'] === 'healthy' ? 'blue' : 'yellow'; ?>-400">
                <?php echo number_format($systemHealth['error_count_24h'] ?? 0); ?>
            </p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                <?php echo $systemHealth['error_status'] === 'healthy' ? 'Alles OK' : 'Erhöhte Fehlerrate'; ?>
            </p>
        </div>
        
        <!-- Security Status -->
        <div class="bg-<?php echo $systemHealth['security_status'] === 'healthy' ? 'purple' : 'orange'; ?>-50 dark:bg-<?php echo $systemHealth['security_status'] === 'healthy' ? 'purple' : 'orange'; ?>-900/20 p-4 rounded-lg border border-<?php echo $systemHealth['security_status'] === 'healthy' ? 'purple' : 'orange'; ?>-200 dark:border-<?php echo $systemHealth['security_status'] === 'healthy' ? 'purple' : 'orange'; ?>-800">
            <div class="flex items-center justify-between mb-2">
                <i class="fas fa-shield-alt text-2xl text-<?php echo $systemHealth['security_status'] === 'healthy' ? 'purple' : 'orange'; ?>-600"></i>
                <i class="fas fa-<?php echo $systemHealth['security_status'] === 'healthy' ? 'check-circle' : 'exclamation-circle'; ?> text-<?php echo $systemHealth['security_status'] === 'healthy' ? 'purple' : 'orange'; ?>-600"></i>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-300 mb-1">Sicherheit</p>
            <p class="text-lg font-bold text-<?php echo $systemHealth['security_status'] === 'healthy' ? 'purple' : 'orange'; ?>-700 dark:text-<?php echo $systemHealth['security_status'] === 'healthy' ? 'purple' : 'orange'; ?>-400">
                <?php echo number_format($systemHealth['failed_logins'] ?? 0); ?> Fehlversuche
            </p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Letzte Stunde
            </p>
        </div>
        
        <!-- System Activity -->
        <div class="bg-indigo-50 dark:bg-indigo-900/20 p-4 rounded-lg border border-indigo-200 dark:border-indigo-800">
            <div class="flex items-center justify-between mb-2">
                <i class="fas fa-chart-line text-2xl text-indigo-600"></i>
                <i class="fas fa-info-circle text-indigo-600"></i>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-300 mb-1">Aktivität</p>
            <p class="text-lg font-bold text-indigo-700 dark:text-indigo-400">
                <?php echo number_format($systemHealth['recent_logins'] ?? 0); ?> Logins
            </p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Letzte Stunde
            </p>
        </div>
    </div>
    
    <!-- Additional Metrics -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-4">
        <div class="bg-gray-50 dark:bg-gray-800 p-3 rounded-lg">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Aktive Sessions (24h)</p>
            <p class="text-base sm:text-xl font-semibold text-gray-700 dark:text-gray-200">
                <i class="fas fa-users text-gray-400 mr-2"></i><?php echo number_format($systemHealth['active_sessions'] ?? 0); ?>
            </p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-800 p-3 rounded-lg">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Datenbank-Größe</p>
            <p class="text-base sm:text-xl font-semibold text-gray-700 dark:text-gray-200">
                <i class="fas fa-hdd text-gray-400 mr-2"></i><?php echo number_format($systemHealth['disk_usage_mb'] ?? 0, 2); ?> MB
            </p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-800 p-3 rounded-lg">
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Betriebszeit (geschätzt)</p>
            <p class="text-base sm:text-xl font-semibold text-gray-700 dark:text-gray-200">
                <i class="fas fa-clock text-gray-400 mr-2"></i><?php echo number_format($systemHealth['uptime_days'] ?? 0); ?> Tage
            </p>
        </div>
    </div>
</div>

<!-- Database Overview -->
<div class="card p-6 mb-6">
    <h2 class="text-lg sm:text-xl font-semibold text-gray-800 mb-4">
        <i class="fas fa-chart-pie text-purple-600 mr-2"></i>
        Datenbank-Übersicht
    </h2>
    
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-blue-50 p-4 rounded-lg">
            <p class="text-blue-800 font-semibold text-sm">User Database</p>
            <p class="text-2xl font-bold text-blue-600"><?php echo number_format($userDbTotal, 2); ?> MB</p>
        </div>
        <div class="bg-green-50 p-4 rounded-lg">
            <p class="text-green-800 font-semibold text-sm">Content Database</p>
            <p class="text-2xl font-bold text-green-600"><?php echo number_format($contentDbTotal, 2); ?> MB</p>
        </div>
        <div class="bg-purple-50 p-4 rounded-lg">
            <p class="text-purple-800 font-semibold text-sm">Gesamt</p>
            <p class="text-2xl font-bold text-purple-600"><?php echo number_format($totalSize, 2); ?> MB</p>
        </div>
    </div>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- User Database Tables -->
        <div>
            <h3 class="text-lg font-semibold text-gray-700 mb-3">User Database Tabellen</h3>
            <div class="overflow-x-auto w-full">
                <table class="min-w-full divide-y divide-gray-200 card-table">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Tabelle</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Zeilen</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Größe</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($tableSizes['user'] as $table): ?>
                        <tr>
                            <td class="px-4 py-2 text-sm text-gray-900" data-label="Tabelle"><?php echo htmlspecialchars($table['table']); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-600 text-right" data-label="Zeilen"><?php echo number_format($table['rows']); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-600 text-right" data-label="Größe"><?php echo number_format($table['size_mb'], 2); ?> MB</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Content Database Tables -->
        <div>
            <h3 class="text-lg font-semibold text-gray-700 mb-3">Content Database Tabellen</h3>
            <div class="overflow-x-auto w-full">
                <table class="min-w-full divide-y divide-gray-200 card-table">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Tabelle</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Zeilen</th>
                            <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Größe</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($tableSizes['content'] as $table): ?>
                        <tr>
                            <td class="px-4 py-2 text-sm text-gray-900" data-label="Tabelle"><?php echo htmlspecialchars($table['table']); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-600 text-right" data-label="Zeilen"><?php echo number_format($table['rows']); ?></td>
                            <td class="px-4 py-2 text-sm text-gray-600 text-right" data-label="Größe"><?php echo number_format($table['size_mb'], 2); ?> MB</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Maintenance Actions -->
<div class="card p-6">
    <h2 class="text-lg sm:text-xl font-semibold text-gray-800 mb-4">
        <i class="fas fa-tools text-orange-600 mr-2"></i>
        Wartungsaktionen
    </h2>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Clean Logs -->
        <div class="border border-gray-200 rounded-lg p-4">
            <div class="mb-4">
                <h3 class="text-lg font-semibold text-gray-800 mb-2">
                    <i class="fas fa-broom text-yellow-600 mr-2"></i>
                    Logs bereinigen
                </h3>
                <p class="text-sm text-gray-600 mb-3">
                    Löscht alte Log-Einträge zur Freigabe von Speicherplatz:
                </p>
                <ul class="list-disc list-inside text-sm text-gray-600 space-y-1 ml-4">
                    <li>User Sessions älter als 30 Tage</li>
                    <li>System Logs älter als 1 Jahr</li>
                    <li>Inventory History älter als 1 Jahr</li>
                    <li>Event History älter als 1 Jahr</li>
                </ul>
            </div>
            <form method="POST" onsubmit="return confirm('Möchtest Du wirklich alte Logs löschen? Diese Aktion kann nicht rückgängig gemacht werden.');">
                <button type="submit" name="clean_logs" class="w-full btn-primary flex items-center justify-center">
                    <i class="fas fa-trash-alt mr-2"></i>
                    Logs bereinigen
                </button>
            </form>
        </div>
        
        <!-- Clear Cache -->
        <div class="border border-gray-200 rounded-lg p-4">
            <div class="mb-4">
                <h3 class="text-lg font-semibold text-gray-800 mb-2">
                    <i class="fas fa-sync-alt text-blue-600 mr-2"></i>
                    Cache leeren
                </h3>
                <p class="text-sm text-gray-600 mb-3">
                    Löscht temporäre Cache-Dateien:
                </p>
                <ul class="list-disc list-inside text-sm text-gray-600 space-y-1 ml-4">
                    <li>Alle Dateien im cache/ Ordner</li>
                    <li>Gibt Speicherplatz frei</li>
                    <li>Beeinflusst keine Datenbanken</li>
                </ul>
            </div>
            <form method="POST" onsubmit="return confirm('Möchtest Du wirklich den Cache leeren?');">
                <button type="submit" name="clear_cache" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition flex items-center justify-center">
                    <i class="fas fa-eraser mr-2"></i>
                    Cache leeren
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Warning Notice -->
<div class="mt-6 bg-yellow-400 border-4 border-yellow-600 rounded-lg p-4">
    <p class="text-gray-900 font-bold text-sm">
        <i class="fas fa-exclamation-triangle mr-2"></i>
        <strong>Hinweis:</strong> Wartungsaktionen können nicht rückgängig gemacht werden. Stelle sicher, dass Du vor dem Bereinigen wichtiger Daten ein Backup erstellt hast.
    </p>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
?>
