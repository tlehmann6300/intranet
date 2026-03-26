<?php
/**
 * Admin Dashboard - Overview of all administration sections
 * Provides quick access and metrics for all admin features
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';

// Check if user is a board member
if (!Auth::check() || !Auth::isBoard()) {
    header('Location: ../auth/login.php');
    exit;
}

$userDb = Database::getUserDB();
$contentDb = Database::getContentDB();

// Get quick metrics
$metrics = [];

try {
    // Total users count
    $stmt = $userDb->query("SELECT COUNT(*) as count FROM users WHERE deleted_at IS NULL");
    $metrics['total_users'] = $stmt->fetch()['count'] ?? 0;
    
    // Active users (7 days)
    $stmt = $userDb->query("
        SELECT COUNT(*) as count 
        FROM users 
        WHERE last_login > DATE_SUB(NOW(), INTERVAL 7 DAY) 
        AND deleted_at IS NULL
    ");
    $metrics['active_users_7d'] = $stmt->fetch()['count'] ?? 0;
    
    // Recent errors (24h)
    $stmt = $contentDb->query("
        SELECT COUNT(*) as count 
        FROM system_logs 
        WHERE action LIKE '%error%' 
        AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $metrics['recent_errors'] = $stmt->fetch()['count'] ?? 0;
    
    // Failed logins (24h)
    $stmt = $contentDb->query("
        SELECT COUNT(*) as count 
        FROM system_logs 
        WHERE action = 'login_failed' 
        AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $metrics['failed_logins_24h'] = $stmt->fetch()['count'] ?? 0;
    
    // Recent audit logs count
    $stmt = $contentDb->query("
        SELECT COUNT(*) as count 
        FROM system_logs 
        WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $metrics['recent_logs'] = $stmt->fetch()['count'] ?? 0;
    
    // Database size - using parameterized query for safety
    $stmt = $userDb->prepare("
        SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size 
        FROM information_schema.TABLES 
        WHERE table_schema IN (?, ?)
    ");
    $stmt->execute([DB_USER_NAME, DB_CONTENT_NAME]);
    $metrics['db_size_mb'] = $stmt->fetch()['size'] ?? 0;
    
    // Recent system actions
    $stmt = $contentDb->query("
        SELECT action, COUNT(*) as count 
        FROM system_logs 
        WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR) 
        GROUP BY action 
        ORDER BY count DESC 
        LIMIT 5
    ");
    $topActions = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Error fetching admin metrics: " . $e->getMessage());
}

$title = 'Admin Dashboard - IBC Intranet';
ob_start();
?>

<div class="mb-8">
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100 mb-2">
        <i class="fas fa-tachometer-alt text-purple-600 mr-2"></i>
        Admin Dashboard
    </h1>
    <p class="text-gray-600 dark:text-gray-300">Zentrale Übersicht aller Administrationsfunktionen</p>
</div>

<!-- Key Metrics Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <!-- Total Users -->
    <div class="card p-6 hover:shadow-xl transition-shadow duration-200 border-l-4 border-blue-500">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">Gesamtbenutzer</p>
                <p class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100">
                    <?php echo number_format($metrics['total_users'] ?? 0); ?>
                </p>
            </div>
            <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900/30 rounded-xl flex items-center justify-center">
                <i class="fas fa-users text-2xl sm:text-3xl text-blue-600"></i>
            </div>
        </div>
        <div class="mt-4">
            <a href="users.php" class="text-sm text-blue-600 hover:text-blue-700 dark:text-blue-400 font-medium flex items-center">
                Benutzerverwaltung <i class="fas fa-arrow-right ml-2"></i>
            </a>
        </div>
    </div>
    
    <!-- Active Users -->
    <div class="card p-6 hover:shadow-xl transition-shadow duration-200 border-l-4 border-green-500">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">Aktiv (7 Tage)</p>
                <p class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100">
                    <?php echo number_format($metrics['active_users_7d'] ?? 0); ?>
                </p>
            </div>
            <div class="w-16 h-16 bg-green-100 dark:bg-green-900/30 rounded-xl flex items-center justify-center">
                <i class="fas fa-user-check text-2xl sm:text-3xl text-green-600"></i>
            </div>
        </div>
        <div class="mt-4">
            <a href="stats.php" class="text-sm text-green-600 hover:text-green-700 dark:text-green-400 font-medium flex items-center">
                Statistiken ansehen <i class="fas fa-arrow-right ml-2"></i>
            </a>
        </div>
    </div>
    
    <!-- System Errors -->
    <div class="card p-6 hover:shadow-xl transition-shadow duration-200 border-l-4 border-<?php echo $metrics['recent_errors'] > 10 ? 'red' : 'yellow'; ?>-500">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">Fehler (24h)</p>
                <p class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100">
                    <?php echo number_format($metrics['recent_errors'] ?? 0); ?>
                </p>
            </div>
            <div class="w-16 h-16 bg-<?php echo $metrics['recent_errors'] > 10 ? 'red' : 'yellow'; ?>-100 dark:bg-<?php echo $metrics['recent_errors'] > 10 ? 'red' : 'yellow'; ?>-900/30 rounded-xl flex items-center justify-center">
                <i class="fas fa-exclamation-triangle text-2xl sm:text-3xl text-<?php echo $metrics['recent_errors'] > 10 ? 'red' : 'yellow'; ?>-600"></i>
            </div>
        </div>
        <div class="mt-4">
            <a href="audit.php" class="text-sm text-<?php echo $metrics['recent_errors'] > 10 ? 'red' : 'yellow'; ?>-600 hover:text-<?php echo $metrics['recent_errors'] > 10 ? 'red' : 'yellow'; ?>-700 dark:text-<?php echo $metrics['recent_errors'] > 10 ? 'red' : 'yellow'; ?>-400 font-medium flex items-center">
                Audit Logs prüfen <i class="fas fa-arrow-right ml-2"></i>
            </a>
        </div>
    </div>
    
    <!-- Database Size -->
    <div class="card p-6 hover:shadow-xl transition-shadow duration-200 border-l-4 border-purple-500">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">Datenbank-Größe</p>
                <p class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100">
                    <?php echo number_format($metrics['db_size_mb'] ?? 0, 1); ?> MB
                </p>
            </div>
            <div class="w-16 h-16 bg-purple-100 dark:bg-purple-900/30 rounded-xl flex items-center justify-center">
                <i class="fas fa-database text-2xl sm:text-3xl text-purple-600"></i>
            </div>
        </div>
        <div class="mt-4">
            <a href="db_maintenance.php" class="text-sm text-purple-600 hover:text-purple-700 dark:text-purple-400 font-medium flex items-center">
                System Health <i class="fas fa-arrow-right ml-2"></i>
            </a>
        </div>
    </div>
</div>

<!-- Quick Actions and Recent Activity -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <!-- Quick Actions -->
    <div class="card p-6">
        <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
            <i class="fas fa-bolt text-yellow-600 mr-2"></i>
            Schnellaktionen
        </h2>
        <div class="space-y-3">
            <a href="users.php" class="block p-4 bg-gray-50 dark:bg-gray-700 hover:bg-gray-100 dark:hover:bg-gray-600 rounded-lg transition-colors">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                            <i class="fas fa-users text-blue-600"></i>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-800 dark:text-gray-100">Benutzerverwaltung</p>
                            <p class="text-sm text-gray-600 dark:text-gray-400">Benutzer und Rollen verwalten</p>
                        </div>
                    </div>
                    <i class="fas fa-chevron-right text-gray-400"></i>
                </div>
            </a>
            
            <a href="stats.php" class="block p-4 bg-gray-50 dark:bg-gray-700 hover:bg-gray-100 dark:hover:bg-gray-600 rounded-lg transition-colors">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-green-100 dark:bg-green-900/30 rounded-lg flex items-center justify-center">
                            <i class="fas fa-chart-bar text-green-600"></i>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-800 dark:text-gray-100">Statistiken</p>
                            <p class="text-sm text-gray-600 dark:text-gray-400">Systemstatistiken anzeigen</p>
                        </div>
                    </div>
                    <i class="fas fa-chevron-right text-gray-400"></i>
                </div>
            </a>
            
            <a href="audit.php" class="block p-4 bg-gray-50 dark:bg-gray-700 hover:bg-gray-100 dark:hover:bg-gray-600 rounded-lg transition-colors">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-purple-100 dark:bg-purple-900/30 rounded-lg flex items-center justify-center">
                            <i class="fas fa-clipboard-list text-purple-600"></i>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-800 dark:text-gray-100">Audit Logs</p>
                            <p class="text-sm text-gray-600 dark:text-gray-400">Systemaktivitäten überwachen</p>
                        </div>
                    </div>
                    <i class="fas fa-chevron-right text-gray-400"></i>
                </div>
            </a>
            
            <a href="db_maintenance.php" class="block p-4 bg-gray-50 dark:bg-gray-700 hover:bg-gray-100 dark:hover:bg-gray-600 rounded-lg transition-colors">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-indigo-100 dark:bg-indigo-900/30 rounded-lg flex items-center justify-center">
                            <i class="fas fa-heartbeat text-indigo-600"></i>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-800 dark:text-gray-100">System Health</p>
                            <p class="text-sm text-gray-600 dark:text-gray-400">Systemzustand & Wartung</p>
                        </div>
                    </div>
                    <i class="fas fa-chevron-right text-gray-400"></i>
                </div>
            </a>
            
            <a href="settings.php" class="block p-4 bg-gray-50 dark:bg-gray-700 hover:bg-gray-100 dark:hover:bg-gray-600 rounded-lg transition-colors">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-orange-100 dark:bg-orange-900/30 rounded-lg flex items-center justify-center">
                            <i class="fas fa-cog text-orange-600"></i>
                        </div>
                        <div>
                            <p class="font-semibold text-gray-800 dark:text-gray-100">Einstellungen</p>
                            <p class="text-sm text-gray-600 dark:text-gray-400">System konfigurieren</p>
                        </div>
                    </div>
                    <i class="fas fa-chevron-right text-gray-400"></i>
                </div>
            </a>
        </div>
    </div>
    
    <!-- System Activity -->
    <div class="card p-6">
        <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
            <i class="fas fa-chart-line text-green-600 mr-2"></i>
            Top Aktivitäten (24h)
        </h2>
        <?php if (!empty($topActions)): ?>
        <div class="space-y-3">
            <?php foreach ($topActions as $action): ?>
            <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 bg-gradient-to-br from-blue-400 to-indigo-600 rounded-lg flex items-center justify-center">
                        <span class="text-white font-bold text-sm"><?php echo number_format($action['count']); ?></span>
                    </div>
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        <?php echo htmlspecialchars($action['action']); ?>
                    </span>
                </div>
                <span class="text-xs px-2 py-1 bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 rounded-full">
                    <?php echo number_format($action['count']); ?>x
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="text-center py-8 text-gray-500 dark:text-gray-400">
            <i class="fas fa-inbox text-4xl mb-2"></i>
            <p>Keine Aktivitäten in den letzten 24 Stunden</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Security Alert -->
<?php if ($metrics['failed_logins_24h'] > 10 || $metrics['recent_errors'] > 20): ?>
<div class="bg-gradient-to-r from-red-50 to-orange-50 dark:from-red-900/20 dark:to-orange-900/20 border-l-4 border-red-500 rounded-xl p-6 mb-6">
    <div class="flex items-start">
        <div class="flex-shrink-0">
            <div class="w-12 h-12 bg-red-500 rounded-xl flex items-center justify-center">
                <i class="fas fa-exclamation-triangle text-white text-xl"></i>
            </div>
        </div>
        <div class="ml-4 flex-1">
            <h3 class="text-lg font-bold text-red-800 dark:text-red-300 mb-2">
                Sicherheitswarnung
            </h3>
            <p class="text-red-700 dark:text-red-400 mb-3">
                <?php if ($metrics['failed_logins_24h'] > 10): ?>
                Es wurden <strong><?php echo $metrics['failed_logins_24h']; ?> fehlgeschlagene Login-Versuche</strong> in den letzten 24 Stunden festgestellt.
                <?php endif; ?>
                <?php if ($metrics['recent_errors'] > 20): ?>
                <br>Es gibt <strong><?php echo $metrics['recent_errors']; ?> Systemfehler</strong> in den letzten 24 Stunden.
                <?php endif; ?>
            </p>
            <a href="audit.php" class="inline-flex items-center px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors text-sm font-medium">
                <i class="fas fa-search mr-2"></i>
                Logs untersuchen
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Additional Info Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    
    <div class="card p-6">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-gray-600 dark:text-gray-400">Aktivitätslogs (24h)</h3>
            <i class="fas fa-list text-purple-500"></i>
        </div>
        <p class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">
            <?php echo number_format($metrics['recent_logs'] ?? 0); ?>
        </p>
        <a href="audit.php" class="text-sm text-purple-600 hover:text-purple-700 dark:text-purple-400">
            Logs ansehen →
        </a>
    </div>
    
    <div class="card p-6">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-gray-600 dark:text-gray-400">Fehlgeschlagene Logins</h3>
            <i class="fas fa-shield-alt text-red-500"></i>
        </div>
        <p class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-2">
            <?php echo number_format($metrics['failed_logins_24h'] ?? 0); ?>
        </p>
        <a href="audit.php?action=login_failed" class="text-sm text-red-600 hover:text-red-700 dark:text-red-400">
            Details ansehen →
        </a>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
?>
