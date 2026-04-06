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

/*
 * Static CSS class maps for health status indicators.
 * All class strings are defined statically so Tailwind's content scanner
 * can detect them at build time. Do NOT concatenate color names dynamically.
 */
$overallBorderMap = [
    'healthy' => 'border-l-4 border-green-500',
    'warning' => 'border-l-4 border-yellow-500',
    'error'   => 'border-l-4 border-red-500',
];
$overallIconMap = [
    'healthy' => 'text-green-600 dark:text-green-400',
    'warning' => 'text-yellow-600 dark:text-yellow-400',
    'error'   => 'text-red-600 dark:text-red-400',
];
$overallBadgeMap = [
    'healthy' => 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
    'warning' => 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300',
    'error'   => 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300',
];
$overallLabelMap = [
    'healthy' => '✓ Systemgesund',
    'warning' => '⚠ Warnung',
    'error'   => '✗ Fehler',
];

// Card CSS maps for each individual status value
$dbStatusCardMap = [
    'healthy' => [
        'bg'     => 'bg-green-50 dark:bg-green-900/20',
        'border' => 'border border-green-200 dark:border-green-800',
        'icon'   => 'text-green-600 dark:text-green-400',
        'text'   => 'text-green-700 dark:text-green-400',
        'check'  => 'fa-check-circle',
        'label'  => 'Verbunden',
    ],
    'error' => [
        'bg'     => 'bg-red-50 dark:bg-red-900/20',
        'border' => 'border border-red-200 dark:border-red-800',
        'icon'   => 'text-red-600 dark:text-red-400',
        'text'   => 'text-red-700 dark:text-red-400',
        'check'  => 'fa-times-circle',
        'label'  => 'Fehler',
    ],
];
$errorStatusCardMap = [
    'healthy' => [
        'bg'     => 'bg-blue-50 dark:bg-blue-900/20',
        'border' => 'border border-blue-200 dark:border-blue-800',
        'icon'   => 'text-blue-600 dark:text-blue-400',
        'text'   => 'text-blue-700 dark:text-blue-400',
        'check'  => 'fa-check-circle',
        'detail' => 'Alles OK',
    ],
    'warning' => [
        'bg'     => 'bg-yellow-50 dark:bg-yellow-900/20',
        'border' => 'border border-yellow-200 dark:border-yellow-800',
        'icon'   => 'text-yellow-600 dark:text-yellow-400',
        'text'   => 'text-yellow-700 dark:text-yellow-400',
        'check'  => 'fa-exclamation-circle',
        'detail' => 'Erhöhte Fehlerrate',
    ],
];
$securityStatusCardMap = [
    'healthy' => [
        'bg'     => 'bg-purple-50 dark:bg-purple-900/20',
        'border' => 'border border-purple-200 dark:border-purple-800',
        'icon'   => 'text-purple-600 dark:text-purple-400',
        'text'   => 'text-purple-700 dark:text-purple-400',
        'check'  => 'fa-check-circle',
    ],
    'warning' => [
        'bg'     => 'bg-orange-50 dark:bg-orange-900/20',
        'border' => 'border border-orange-200 dark:border-orange-800',
        'icon'   => 'text-orange-600 dark:text-orange-400',
        'text'   => 'text-orange-700 dark:text-orange-400',
        'check'  => 'fa-exclamation-circle',
    ],
];

$overallStatus   = $systemHealth['overall_status'] ?? 'error';
$dbStatus        = $systemHealth['database_status'] ?? 'error';
$errStatus       = $systemHealth['error_status'] ?? 'healthy';
$secStatus       = $systemHealth['security_status'] ?? 'healthy';

$dbCard  = $dbStatusCardMap[$dbStatus]    ?? $dbStatusCardMap['error'];
$errCard = $errorStatusCardMap[$errStatus] ?? $errorStatusCardMap['healthy'];
$secCard = $securityStatusCardMap[$secStatus] ?? $securityStatusCardMap['healthy'];
?>

<div class="max-w-7xl mx-auto">

<!-- Page Header -->
<div class="mb-8 flex items-start gap-3">
    <div class="w-11 h-11 rounded-2xl bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center shadow-sm flex-shrink-0 mt-0.5">
        <i class="fas fa-heartbeat text-blue-600 dark:text-blue-400 text-xl"></i>
    </div>
    <div>
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-gray-50 tracking-tight">System Health & Wartung</h1>
        <p class="text-gray-500 dark:text-gray-400 text-sm mt-0.5">Systemüberwachung, Datenbankverwaltung und Wartungsaktionen</p>
    </div>
</div>

<?php if (!empty($actionResult)): ?>
<div class="mb-6 p-4 rounded-xl border <?php echo $actionResult['type'] === 'success' ? 'bg-green-50 dark:bg-green-900/20 border-green-300 dark:border-green-700 text-green-700 dark:text-green-300' : 'bg-red-50 dark:bg-red-900/20 border-red-300 dark:border-red-700 text-red-700 dark:text-red-300'; ?>">
    <h3 class="font-semibold mb-2 flex items-center gap-2">
        <i class="fas fa-<?php echo $actionResult['type'] === 'success' ? 'check' : 'exclamation'; ?>-circle"></i>
        <?php echo htmlspecialchars($actionResult['title']); ?>
    </h3>
    <ul class="list-disc list-inside ml-4 space-y-0.5 text-sm">
        <?php foreach ($actionResult['details'] as $detail): ?>
        <li><?php echo htmlspecialchars($detail); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- System Health Status -->
<div class="card rounded-xl shadow-sm p-6 mb-6 <?php echo $overallBorderMap[$overallStatus] ?? 'border-l-4 border-red-500'; ?>">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 flex items-center gap-2">
            <i class="fas fa-heartbeat <?php echo $overallIconMap[$overallStatus] ?? 'text-red-600'; ?>"></i>
            System Health Status
        </h2>
        <span class="px-4 py-2 rounded-full text-sm font-semibold <?php echo $overallBadgeMap[$overallStatus] ?? 'bg-red-100 text-red-800'; ?>">
            <?php echo $overallLabelMap[$overallStatus] ?? '✗ Fehler'; ?>
        </span>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        <!-- Database Status -->
        <div class="<?php echo $dbCard['bg']; ?> <?php echo $dbCard['border']; ?> p-4 rounded-xl">
            <div class="flex items-center justify-between mb-3">
                <i class="fas fa-database text-2xl <?php echo $dbCard['icon']; ?>"></i>
                <i class="fas <?php echo $dbCard['check']; ?> <?php echo $dbCard['icon']; ?>"></i>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Datenbank</p>
            <p class="text-lg font-bold <?php echo $dbCard['text']; ?>"><?php echo $dbCard['label']; ?></p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 break-words">
                <?php echo htmlspecialchars($systemHealth['database_message'] ?? ''); ?>
            </p>
        </div>

        <!-- Error Count -->
        <div class="<?php echo $errCard['bg']; ?> <?php echo $errCard['border']; ?> p-4 rounded-xl">
            <div class="flex items-center justify-between mb-3">
                <i class="fas fa-exclamation-triangle text-2xl <?php echo $errCard['icon']; ?>"></i>
                <i class="fas <?php echo $errCard['check']; ?> <?php echo $errCard['icon']; ?>"></i>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Fehler (24h)</p>
            <p class="text-lg font-bold <?php echo $errCard['text']; ?>"><?php echo number_format($systemHealth['error_count_24h'] ?? 0); ?></p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1"><?php echo $errCard['detail']; ?></p>
        </div>

        <!-- Security Status -->
        <div class="<?php echo $secCard['bg']; ?> <?php echo $secCard['border']; ?> p-4 rounded-xl">
            <div class="flex items-center justify-between mb-3">
                <i class="fas fa-shield-alt text-2xl <?php echo $secCard['icon']; ?>"></i>
                <i class="fas <?php echo $secCard['check']; ?> <?php echo $secCard['icon']; ?>"></i>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Sicherheit</p>
            <p class="text-lg font-bold <?php echo $secCard['text']; ?>"><?php echo number_format($systemHealth['failed_logins'] ?? 0); ?> Fehlversuche</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Letzte Stunde</p>
        </div>

        <!-- System Activity -->
        <div class="bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 p-4 rounded-xl">
            <div class="flex items-center justify-between mb-3">
                <i class="fas fa-chart-line text-2xl text-indigo-600 dark:text-indigo-400"></i>
                <i class="fas fa-info-circle text-indigo-600 dark:text-indigo-400"></i>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Aktivität</p>
            <p class="text-lg font-bold text-indigo-700 dark:text-indigo-400"><?php echo number_format($systemHealth['recent_logins'] ?? 0); ?> Logins</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Letzte Stunde</p>
        </div>
    </div>

    <!-- Additional Metrics -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="bg-gray-50 dark:bg-gray-800/60 p-4 rounded-xl">
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Aktive Sessions (24h)</p>
            <p class="text-xl font-bold text-gray-700 dark:text-gray-200 flex items-center gap-2">
                <i class="fas fa-users text-gray-400 text-base"></i><?php echo number_format($systemHealth['active_sessions'] ?? 0); ?>
            </p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-800/60 p-4 rounded-xl">
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Datenbank-Größe</p>
            <p class="text-xl font-bold text-gray-700 dark:text-gray-200 flex items-center gap-2">
                <i class="fas fa-hdd text-gray-400 text-base"></i><?php echo number_format($systemHealth['disk_usage_mb'] ?? 0, 2); ?> MB
            </p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-800/60 p-4 rounded-xl">
            <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Betriebszeit (geschätzt)</p>
            <p class="text-xl font-bold text-gray-700 dark:text-gray-200 flex items-center gap-2">
                <i class="fas fa-clock text-gray-400 text-base"></i><?php echo number_format($systemHealth['uptime_days'] ?? 0); ?> Tage
            </p>
        </div>
    </div>
</div>

<!-- Database Overview -->
<div class="card rounded-xl shadow-sm p-6 mb-6">
    <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-5 flex items-center gap-2">
        <i class="fas fa-chart-pie text-purple-500 dark:text-purple-400"></i>
        Datenbank-Übersicht
    </h2>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-100 dark:border-blue-800 p-4 rounded-xl">
            <p class="text-xs font-semibold text-blue-700 dark:text-blue-300 uppercase tracking-wide mb-1">User Database</p>
            <p class="text-2xl font-bold text-blue-600 dark:text-blue-400"><?php echo number_format($userDbTotal, 2); ?> MB</p>
        </div>
        <div class="bg-green-50 dark:bg-green-900/20 border border-green-100 dark:border-green-800 p-4 rounded-xl">
            <p class="text-xs font-semibold text-green-700 dark:text-green-300 uppercase tracking-wide mb-1">Content Database</p>
            <p class="text-2xl font-bold text-green-600 dark:text-green-400"><?php echo number_format($contentDbTotal, 2); ?> MB</p>
        </div>
        <div class="bg-purple-50 dark:bg-purple-900/20 border border-purple-100 dark:border-purple-800 p-4 rounded-xl">
            <p class="text-xs font-semibold text-purple-700 dark:text-purple-300 uppercase tracking-wide mb-1">Gesamt</p>
            <p class="text-2xl font-bold text-purple-600 dark:text-purple-400"><?php echo number_format($totalSize, 2); ?> MB</p>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <!-- User Database Tables -->
        <div>
            <h3 class="text-sm font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wide mb-3">User Database Tabellen</h3>
            <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-gray-700">
                <table class="w-full divide-y divide-gray-100 dark:divide-gray-700 card-table text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Tabelle</th>
                            <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Zeilen</th>
                            <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Größe</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50 dark:divide-gray-700/50">
                        <?php foreach ($tableSizes['user'] as $table): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                            <td class="px-4 py-2.5 text-gray-800 dark:text-gray-200 font-medium" data-label="Tabelle"><?php echo htmlspecialchars($table['table']); ?></td>
                            <td class="px-4 py-2.5 text-gray-600 dark:text-gray-400 text-right tabular-nums" data-label="Zeilen"><?php echo number_format($table['rows']); ?></td>
                            <td class="px-4 py-2.5 text-gray-600 dark:text-gray-400 text-right tabular-nums" data-label="Größe"><?php echo number_format($table['size_mb'], 2); ?> MB</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Content Database Tables -->
        <div>
            <h3 class="text-sm font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wide mb-3">Content Database Tabellen</h3>
            <div class="overflow-x-auto rounded-xl border border-gray-100 dark:border-gray-700">
                <table class="w-full divide-y divide-gray-100 dark:divide-gray-700 card-table text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-4 py-2.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Tabelle</th>
                            <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Zeilen</th>
                            <th class="px-4 py-2.5 text-right text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Größe</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50 dark:divide-gray-700/50">
                        <?php foreach ($tableSizes['content'] as $table): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                            <td class="px-4 py-2.5 text-gray-800 dark:text-gray-200 font-medium" data-label="Tabelle"><?php echo htmlspecialchars($table['table']); ?></td>
                            <td class="px-4 py-2.5 text-gray-600 dark:text-gray-400 text-right tabular-nums" data-label="Zeilen"><?php echo number_format($table['rows']); ?></td>
                            <td class="px-4 py-2.5 text-gray-600 dark:text-gray-400 text-right tabular-nums" data-label="Größe"><?php echo number_format($table['size_mb'], 2); ?> MB</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Maintenance Actions -->
<div class="card rounded-xl shadow-sm p-6 mb-6">
    <h2 class="text-lg font-bold text-gray-800 dark:text-gray-100 mb-5 flex items-center gap-2">
        <i class="fas fa-tools text-orange-500 dark:text-orange-400"></i>
        Wartungsaktionen
    </h2>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <!-- Clean Logs -->
        <div class="border border-gray-200 dark:border-gray-700 rounded-xl p-5 bg-gray-50 dark:bg-gray-800/40">
            <div class="mb-5">
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100 mb-1 flex items-center gap-2">
                    <i class="fas fa-broom text-yellow-500"></i>
                    Logs bereinigen
                </h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                    Löscht alte Log-Einträge zur Freigabe von Speicherplatz:
                </p>
                <ul class="space-y-1 text-sm text-gray-600 dark:text-gray-400">
                    <li class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-gray-300 dark:bg-gray-600 flex-shrink-0"></span>User Sessions älter als 30 Tage</li>
                    <li class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-gray-300 dark:bg-gray-600 flex-shrink-0"></span>System Logs älter als 1 Jahr</li>
                    <li class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-gray-300 dark:bg-gray-600 flex-shrink-0"></span>Inventory History älter als 1 Jahr</li>
                    <li class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-gray-300 dark:bg-gray-600 flex-shrink-0"></span>Event History älter als 1 Jahr</li>
                </ul>
            </div>
            <form method="POST" onsubmit="return confirm('Möchtest Du wirklich alte Logs löschen? Diese Aktion kann nicht rückgängig gemacht werden.');">
                <button type="submit" name="clean_logs" class="w-full btn-primary min-h-[44px] flex items-center justify-center gap-2">
                    <i class="fas fa-trash-alt"></i>
                    Logs bereinigen
                </button>
            </form>
        </div>

        <!-- Clear Cache -->
        <div class="border border-gray-200 dark:border-gray-700 rounded-xl p-5 bg-gray-50 dark:bg-gray-800/40">
            <div class="mb-5">
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100 mb-1 flex items-center gap-2">
                    <i class="fas fa-sync-alt text-blue-500"></i>
                    Cache leeren
                </h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                    Löscht temporäre Cache-Dateien:
                </p>
                <ul class="space-y-1 text-sm text-gray-600 dark:text-gray-400">
                    <li class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-gray-300 dark:bg-gray-600 flex-shrink-0"></span>Alle Dateien im cache/ Ordner</li>
                    <li class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-gray-300 dark:bg-gray-600 flex-shrink-0"></span>Gibt Speicherplatz frei</li>
                    <li class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full bg-gray-300 dark:bg-gray-600 flex-shrink-0"></span>Beeinflusst keine Datenbanken</li>
                </ul>
            </div>
            <form method="POST" onsubmit="return confirm('Möchtest Du wirklich den Cache leeren?');">
                <button type="submit" name="clear_cache" class="w-full min-h-[44px] bg-blue-600 hover:bg-blue-700 dark:bg-blue-700 dark:hover:bg-blue-600 text-white font-semibold rounded-xl transition flex items-center justify-center gap-2">
                    <i class="fas fa-eraser"></i>
                    Cache leeren
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Warning Notice -->
<div class="rounded-xl p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-300 dark:border-amber-700">
    <p class="text-amber-800 dark:text-amber-300 font-medium text-sm flex items-start gap-2">
        <i class="fas fa-exclamation-triangle mt-0.5 flex-shrink-0"></i>
        <span><strong>Hinweis:</strong> Wartungsaktionen können nicht rückgängig gemacht werden. Stelle sicher, dass Du vor dem Bereinigen wichtiger Daten ein Backup erstellt hast.</span>
    </p>
</div>

</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
?>