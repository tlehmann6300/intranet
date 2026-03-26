<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/models/Inventory.php';
require_once __DIR__ . '/../../includes/models/Project.php';

// Check authentication and authorization
if (!Auth::canViewAdminStats()) {
    die('Zugriff verweigert');
}

// Get authenticated user
$user = Auth::user();
if (!$user) {
    die('Zugriff verweigert');
}

// Get databases
$userDb = Database::getUserDB();
$contentDb = Database::getContentDB();

// Metric 1: Active Users (7 Days)
$stmt = $userDb->query("
    SELECT COUNT(*) as active_users 
    FROM users 
    WHERE last_login IS NOT NULL 
    AND last_login > DATE_SUB(NOW(), INTERVAL 7 DAY)
    AND deleted_at IS NULL
");
$activeUsersCount = $stmt->fetch()['active_users'] ?? 0;

// Active Users trend (compare with previous 7 days)
$stmt = $userDb->query("
    SELECT COUNT(*) as active_users_prev 
    FROM users 
    WHERE last_login IS NOT NULL 
    AND DATE(last_login) BETWEEN DATE(DATE_SUB(NOW(), INTERVAL 14 DAY)) AND DATE(DATE_SUB(NOW(), INTERVAL 7 DAY))
    AND deleted_at IS NULL
");
$activeUsersPrev = $stmt->fetch()['active_users_prev'] ?? 0;
$activeUsersTrend = $activeUsersPrev > 0 ? (($activeUsersCount - $activeUsersPrev) / $activeUsersPrev) * 100 : 0;

// Metric 2: Total User Count
$stmt = $userDb->query("SELECT COUNT(*) as total_users FROM users WHERE deleted_at IS NULL");
$totalUsersCount = $stmt->fetch()['total_users'] ?? 0;

// Total Users trend (new users in last 7 days)
$stmt = $userDb->query("
    SELECT COUNT(*) as new_users 
    FROM users 
    WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
    AND deleted_at IS NULL
");
$newUsersCount = $stmt->fetch()['new_users'] ?? 0;
$totalUsersTrend = $newUsersCount;

// Get recent user activity
$recentActivity = [];
try {
    $stmt = $userDb->query("
        SELECT id, email, firstname, lastname, last_login, created_at
        FROM users 
        WHERE last_login IS NOT NULL
        AND deleted_at IS NULL
        ORDER BY last_login DESC
        LIMIT 10
    ");
    $recentActivity = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching recent activity: " . $e->getMessage());
}

// Get inventory stats (for dashboard content that will be moved here)
$stats = Inventory::getDashboardStats();
$inStockStats = Inventory::getInStockStats();
$checkedOutStats = Inventory::getCheckedOutStats();
$writeOffStats = Inventory::getWriteOffStatsThisMonth();

// List 1: 'Wer hat was ausgeliehen?' (Active Checkouts with User Names)
$checkouts = [];
$activeCheckouts = [];

try {
    $stmt = $contentDb->query("
        SELECT 
            ic.id,
            ic.item_id,
            ic.user_id,
            ic.checked_out_at,
            ic.due_date,
            i.name as item_name,
            i.quantity as total_quantity
        FROM inventory_checkouts ic
        JOIN inventory_items i ON ic.item_id = i.id
        WHERE ic.returned_at IS NULL
        ORDER BY ic.checked_out_at DESC
    ");
    $checkouts = $stmt->fetchAll();
    
    // Get all user IDs and fetch user information in bulk
    $userIds = array_unique(array_column($checkouts, 'user_id'));
    $userInfoMap = [];
    
    if (!empty($userIds)) {
        $placeholders = str_repeat('?,', count($userIds) - 1) . '?';
        $userStmt = $userDb->prepare("SELECT id, email, firstname, lastname FROM users WHERE id IN ($placeholders) AND deleted_at IS NULL");
        $userStmt->execute($userIds);
        $users = $userStmt->fetchAll();
        
        foreach ($users as $u) {
            $userInfoMap[$u['id']] = $u;
        }
    }
    
    // Build active checkouts array with user information
    foreach ($checkouts as $checkout) {
        $userInfo = $userInfoMap[$checkout['user_id']] ?? null;
        
        $userName = 'Unbekannt';
        $userEmail = '';
        
        if ($userInfo) {
            $userEmail = $userInfo['email'] ?? '';
            if (!empty($userInfo['firstname']) && !empty($userInfo['lastname'])) {
                $userName = $userInfo['firstname'] . ' ' . $userInfo['lastname'];
            } elseif (!empty($userInfo['firstname'])) {
                $userName = $userInfo['firstname'];
            } elseif (!empty($userEmail)) {
                $userName = explode('@', $userEmail)[0];
            }
        }
        
        $activeCheckouts[] = [
            'checkout_id' => $checkout['id'],
            'item_name' => $checkout['item_name'],
            'user_name' => $userName,
            'user_email' => $userEmail,
            'checked_out_at' => $checkout['checked_out_at'],
            'due_date' => $checkout['due_date'],
            'is_overdue' => !empty($checkout['due_date']) && strtotime($checkout['due_date']) < time()
        ];
    }
} catch (PDOException $e) {
    // Table doesn't exist or other DB error
    error_log("Error fetching inventory checkouts: " . $e->getMessage());
    // activeCheckouts will remain empty array, showing "Keine Daten"
}

// List 2: 'Projekt Bewerbungen' (Count per Project)
$stmt = $contentDb->query("
    SELECT 
        p.id,
        p.title,
        p.type,
        p.status,
        COUNT(pa.id) as application_count
    FROM projects p
    LEFT JOIN project_applications pa ON p.id = pa.project_id
    WHERE p.status != 'draft'
    GROUP BY p.id, p.title, p.type, p.status
    ORDER BY p.created_at DESC
");
$projectApplications = $stmt->fetchAll();

// Database Storage Usage
// Query information_schema to get database sizes
$databaseStats = [];
$databaseQuota = 2048; // 2 GB in MB

try {
    // Get database names from config
    $databases = [
        ['name' => DB_USER_NAME, 'label' => 'User Database', 'color' => 'blue'],
        ['name' => DB_CONTENT_NAME, 'label' => 'Content Database', 'color' => 'purple'],
        ['name' => DB_RECH_NAME, 'label' => 'Invoice Database', 'color' => 'green']
    ];
    
    // Filter out empty database names
    $validDatabases = array_filter($databases, function($db) {
        return !empty($db['name']);
    });
    
    if (!empty($validDatabases)) {
        // Query all databases in a single query using IN clause
        $dbNames = array_column($validDatabases, 'name');
        $placeholders = str_repeat('?,', count($dbNames) - 1) . '?';
        
        $stmt = $userDb->prepare("
            SELECT 
                table_schema as database_name,
                ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb
            FROM information_schema.TABLES
            WHERE table_schema IN ($placeholders)
            GROUP BY table_schema
        ");
        $stmt->execute($dbNames);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create a map of database name to size
        $sizeMap = [];
        foreach ($results as $result) {
            $sizeMap[$result['database_name']] = $result['size_mb'];
        }
        
        // Build stats array maintaining original order
        foreach ($validDatabases as $db) {
            $sizeMb = $sizeMap[$db['name']] ?? 0;
            $percentage = ($sizeMb / $databaseQuota) * 100;
            
            $databaseStats[] = [
                'name' => $db['name'],
                'label' => $db['label'],
                'size_mb' => $sizeMb,
                'percentage' => min($percentage, 100), // Cap at 100%
                'color' => $db['color']
            ];
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching database sizes: " . $e->getMessage());
    // databaseStats will remain empty array
}

$title = 'Statistiken - IBC Intranet';
ob_start();
?>

<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="mb-8">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h1 class="text-2xl sm:text-3xl md:text-4xl font-bold text-gray-800 dark:text-gray-100 mb-2">
                    <i class="fas fa-chart-bar mr-3 text-purple-600 dark:text-purple-400"></i>
                    Statistiken
                </h1>
                <p class="text-gray-600 dark:text-gray-300">Übersicht über wichtige Kennzahlen und Aktivitäten</p>
            </div>
            <button 
                id="exportStats" 
                class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-green-600 to-blue-600 text-white rounded-lg hover:from-green-700 hover:to-blue-700 transition-all shadow-lg hover:shadow-xl font-medium"
            >
                <i class="fas fa-download mr-2"></i>Export Report
            </button>
        </div>
    </div>

    <!-- Metrics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <!-- Active Users (7 Days) -->
        <div class="card p-6 rounded-xl shadow-lg bg-gradient-to-br from-white to-blue-50 dark:from-gray-800 dark:to-blue-900/20 border-l-4 border-blue-500 dark:border-blue-600 hover:shadow-xl transition-shadow duration-300">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h3 class="text-sm font-semibold text-gray-600 dark:text-gray-300 uppercase mb-1">Aktive Nutzer</h3>
                    <p class="text-2xl sm:text-3xl font-bold text-blue-600 dark:text-blue-400"><?php echo number_format($activeUsersCount); ?></p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Letzte 7 Tage</p>
                </div>
                <div class="w-14 h-14 bg-blue-100 dark:bg-blue-900/50 rounded-full flex items-center justify-center">
                    <i class="fas fa-users text-blue-600 dark:text-blue-400 text-2xl"></i>
                </div>
            </div>
            <?php if ($activeUsersTrend != 0): ?>
            <div class="flex items-center text-sm mt-2 pt-2 border-t border-blue-200 dark:border-blue-800">
                <?php if ($activeUsersTrend > 0): ?>
                    <i class="fas fa-arrow-up text-green-600 dark:text-green-400 mr-1"></i>
                    <span class="text-green-600 dark:text-green-400 font-semibold"><?php echo number_format(abs($activeUsersTrend), 1); ?>%</span>
                    <span class="text-gray-500 dark:text-gray-400 ml-1">vs. vorherige Woche</span>
                <?php else: ?>
                    <i class="fas fa-arrow-down text-red-600 dark:text-red-400 mr-1"></i>
                    <span class="text-red-600 dark:text-red-400 font-semibold"><?php echo number_format(abs($activeUsersTrend), 1); ?>%</span>
                    <span class="text-gray-500 dark:text-gray-400 ml-1">vs. vorherige Woche</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Total Users -->
        <div class="card p-6 rounded-xl shadow-lg bg-gradient-to-br from-white to-purple-50 dark:from-gray-800 dark:to-purple-900/20 border-l-4 border-purple-500 dark:border-purple-600 hover:shadow-xl transition-shadow duration-300">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h3 class="text-sm font-semibold text-gray-600 dark:text-gray-300 uppercase mb-1">Gesamtanzahl User</h3>
                    <p class="text-2xl sm:text-3xl font-bold text-purple-600 dark:text-purple-400"><?php echo number_format($totalUsersCount); ?></p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Registriert</p>
                </div>
                <div class="w-14 h-14 bg-purple-100 dark:bg-purple-900/50 rounded-full flex items-center justify-center">
                    <i class="fas fa-user-friends text-purple-600 dark:text-purple-400 text-2xl"></i>
                </div>
            </div>
            <?php if ($totalUsersTrend > 0): ?>
            <div class="flex items-center text-sm mt-2 pt-2 border-t border-purple-200 dark:border-purple-800">
                <i class="fas fa-user-plus text-green-600 dark:text-green-400 mr-1"></i>
                <span class="text-green-600 dark:text-green-400 font-semibold">+<?php echo number_format($totalUsersTrend); ?></span>
                <span class="text-gray-500 dark:text-gray-400 ml-1">neue in 7 Tagen</span>
            </div>
            <?php else: ?>
            <div class="flex items-center text-sm mt-2 pt-2 border-t border-purple-200 dark:border-purple-800">
                <i class="fas fa-minus text-gray-500 dark:text-gray-400 mr-1"></i>
                <span class="text-gray-500 dark:text-gray-400">Keine neuen User</span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Actions and Status Overview Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
        <!-- Quick Actions Card -->
        <div class="card p-6 rounded-xl shadow-lg dark:bg-gray-800">
            <h3 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
                <i class="fas fa-bolt text-yellow-500 mr-2"></i>
                Schnellaktionen
            </h3>
            <div class="space-y-3">
                <a href="../inventory/index.php" class="block p-4 rounded-lg bg-gradient-to-r from-purple-50 to-purple-100 dark:from-purple-900 dark:to-purple-800 hover:from-purple-100 hover:to-purple-200 dark:hover:from-purple-800 dark:hover:to-purple-700 transition-all duration-300 group">
                    <div class="flex items-center">
                        <i class="fas fa-boxes text-purple-600 dark:text-purple-300 mr-3 text-xl group-hover:scale-110 transition-transform"></i>
                        <span class="font-semibold text-gray-800 dark:text-gray-100">Inventar durchsuchen</span>
                    </div>
                </a>
                <a href="../inventory/add.php" class="block p-4 rounded-lg bg-gradient-to-r from-green-50 to-green-100 dark:from-green-900 dark:to-green-800 hover:from-green-100 hover:to-green-200 dark:hover:from-green-800 dark:hover:to-green-700 transition-all duration-300 group">
                    <div class="flex items-center">
                        <i class="fas fa-plus-circle text-green-600 dark:text-green-300 mr-3 text-xl group-hover:scale-110 transition-transform"></i>
                        <span class="font-semibold text-gray-800 dark:text-gray-100">Neuen Artikel hinzufügen</span>
                    </div>
                </a>
                <?php if (Auth::canManageUsers()): // User management restricted to board roles only ?>
                <a href="../admin/users.php" class="block p-4 rounded-lg bg-gradient-to-r from-blue-50 to-blue-100 dark:from-blue-900 dark:to-blue-800 hover:from-blue-100 hover:to-blue-200 dark:hover:from-blue-800 dark:hover:to-blue-700 transition-all duration-300 group">
                    <div class="flex items-center">
                        <i class="fas fa-users-cog text-blue-600 dark:text-blue-300 mr-3 text-xl group-hover:scale-110 transition-transform"></i>
                        <span class="font-semibold text-gray-800 dark:text-gray-100">Benutzerverwaltung</span>
                    </div>
                </a>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Status Overview Card -->
        <div class="card p-6 rounded-xl shadow-lg dark:bg-gray-800">
            <h3 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
                <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                Status-Übersicht
            </h3>
            <div class="space-y-4">
                <div class="flex items-center justify-between p-4 rounded-lg bg-gray-50 dark:bg-gray-700">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle text-yellow-500 mr-3 text-xl"></i>
                        <span class="text-gray-700 dark:text-gray-200 font-medium">Niedriger Bestand</span>
                    </div>
                    <span class="text-2xl font-bold text-yellow-600 dark:text-yellow-400"><?php echo number_format($stats['low_stock']); ?></span>
                </div>
                <div class="flex items-center justify-between p-4 rounded-lg bg-gray-50 dark:bg-gray-700">
                    <div class="flex items-center">
                        <i class="fas fa-warehouse text-green-500 mr-3 text-xl"></i>
                        <span class="text-gray-700 dark:text-gray-200 font-medium">Im Lager</span>
                    </div>
                    <span class="text-2xl font-bold text-green-600 dark:text-green-400"><?php echo number_format($inStockStats['total_in_stock']); ?></span>
                </div>
                <?php if ($user): ?>
                <div class="p-4 rounded-lg bg-gradient-to-r from-purple-50 to-blue-50 dark:from-purple-900 dark:to-blue-900 border border-purple-200 dark:border-purple-700">
                    <p class="text-sm text-gray-800 dark:text-gray-300">
                        <i class="fas fa-user-circle mr-2 text-purple-600 dark:text-purple-400"></i>
                        Angemeldet als <strong><?php echo htmlspecialchars($user['email']); ?></strong>
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Recent User Activity Section -->
    <?php if (!empty($recentActivity)): ?>
    <div class="mb-8">
        <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-4">
            <i class="fas fa-history mr-2 text-orange-600 dark:text-orange-400"></i>
            Letzte Benutzeraktivitäten
        </h2>
        <div class="card overflow-hidden">
            <div class="overflow-x-auto w-full">
                <table class="w-full card-table">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Benutzer</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">E-Mail</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Letzter Login</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Mitglied seit</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($recentActivity as $activity): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                            <td class="px-6 py-4 whitespace-nowrap" data-label="Benutzer">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-10 w-10 bg-purple-100 dark:bg-purple-900/50 rounded-full flex items-center justify-center">
                                        <i class="fas fa-user text-purple-600 dark:text-purple-400"></i>
                                    </div>
                                    <div class="ml-4">
                                        <div class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                            <?php 
                                            if (!empty($activity['firstname']) && !empty($activity['lastname'])) {
                                                echo htmlspecialchars($activity['firstname'] . ' ' . $activity['lastname']);
                                            } elseif (!empty($activity['firstname'])) {
                                                echo htmlspecialchars($activity['firstname']);
                                            } else {
                                                echo 'Unbekannt';
                                            }
                                            ?>
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">ID: <?php echo $activity['id']; ?></div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300" data-label="E-Mail">
                                <?php echo htmlspecialchars($activity['email']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap" data-label="Letzter Login">
                                <?php 
                                $loginTime = strtotime($activity['last_login']);
                                $now = time();
                                $diff = $now - $loginTime;
                                
                                if ($diff < 3600) {
                                    $timeAgo = floor($diff / 60) . ' Min';
                                    $colorClass = 'text-green-600 dark:text-green-400';
                                } elseif ($diff < 86400) {
                                    $timeAgo = floor($diff / 3600) . ' Std';
                                    $colorClass = 'text-blue-600 dark:text-blue-400';
                                } else {
                                    $timeAgo = floor($diff / 86400) . ' Tage';
                                    $colorClass = 'text-gray-600 dark:text-gray-400';
                                }
                                ?>
                                <div class="text-sm <?php echo $colorClass; ?> font-medium">
                                    <i class="fas fa-clock mr-1"></i>vor <?php echo $timeAgo; ?>
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    <?php echo date('d.m.Y H:i', $loginTime); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300" data-label="Mitglied seit">
                                <?php echo date('d.m.Y', strtotime($activity['created_at'])); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Database Storage Usage Section -->
    <?php if (!empty($databaseStats)): ?>
    <div class="mb-8">
        <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100 mb-4">
            <i class="fas fa-database mr-2 text-indigo-600 dark:text-indigo-400"></i>
            Datenbank Speicherverbrauch
        </h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <?php foreach ($databaseStats as $db): ?>
                <?php 
                $colorClasses = [
                    'blue' => [
                        'border' => 'border-blue-500 dark:border-blue-600',
                        'gradient' => 'from-white to-blue-50 dark:from-gray-800 dark:to-blue-900/20',
                        'icon_bg' => 'bg-blue-100 dark:bg-blue-900/50',
                        'icon_text' => 'text-blue-600 dark:text-blue-400',
                        'text' => 'text-blue-600 dark:text-blue-400',
                        'progress_bg' => 'bg-blue-200 dark:bg-blue-800',
                        'progress_bar' => 'bg-blue-600 dark:bg-blue-400'
                    ],
                    'purple' => [
                        'border' => 'border-purple-500 dark:border-purple-600',
                        'gradient' => 'from-white to-purple-50 dark:from-gray-800 dark:to-purple-900/20',
                        'icon_bg' => 'bg-purple-100 dark:bg-purple-900/50',
                        'icon_text' => 'text-purple-600 dark:text-purple-400',
                        'text' => 'text-purple-600 dark:text-purple-400',
                        'progress_bg' => 'bg-purple-200 dark:bg-purple-800',
                        'progress_bar' => 'bg-purple-600 dark:bg-purple-400'
                    ],
                    'green' => [
                        'border' => 'border-green-500 dark:border-green-600',
                        'gradient' => 'from-white to-green-50 dark:from-gray-800 dark:to-green-900/20',
                        'icon_bg' => 'bg-green-100 dark:bg-green-900/50',
                        'icon_text' => 'text-green-600 dark:text-green-400',
                        'text' => 'text-green-600 dark:text-green-400',
                        'progress_bg' => 'bg-green-200 dark:bg-green-800',
                        'progress_bar' => 'bg-green-600 dark:bg-green-400'
                    ]
                ];
                $colors = $colorClasses[$db['color']] ?? $colorClasses['blue'];
                ?>
                <div class="card p-6 rounded-xl shadow-lg bg-gradient-to-br <?php echo $colors['gradient']; ?> border-l-4 <?php echo $colors['border']; ?>">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-600 dark:text-gray-300 uppercase mb-1"><?php echo htmlspecialchars($db['label']); ?></h3>
                            <p class="text-xl sm:text-2xl font-bold <?php echo $colors['text']; ?>"><?php echo number_format($db['size_mb'], 2); ?> MB</p>
                        </div>
                        <div class="w-12 h-12 <?php echo $colors['icon_bg']; ?> rounded-full flex items-center justify-center">
                            <i class="fas fa-hdd <?php echo $colors['icon_text']; ?> text-xl"></i>
                        </div>
                    </div>
                    
                    <!-- Database Name -->
                    <div class="mb-3">
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            <i class="fas fa-tag mr-1"></i><?php echo htmlspecialchars($db['name']); ?>
                        </p>
                    </div>
                    
                    <!-- Progress Bar -->
                    <div class="space-y-2">
                        <div class="flex justify-between text-xs text-gray-600 dark:text-gray-400">
                            <span>Auslastung</span>
                            <span><?php echo number_format($db['percentage'], 1); ?>% von 2 GB</span>
                        </div>
                        <div class="w-full <?php echo $colors['progress_bg']; ?> rounded-full h-2.5">
                            <div class="<?php echo $colors['progress_bar']; ?> h-2.5 rounded-full transition-all duration-300" style="width: <?php echo min($db['percentage'], 100); ?>%"></div>
                        </div>
                        <?php if ($db['percentage'] >= 90): ?>
                        <p class="text-xs text-red-600 dark:text-red-400 mt-1">
                            <i class="fas fa-exclamation-triangle mr-1"></i>Warnung: Hohe Auslastung
                        </p>
                        <?php elseif ($db['percentage'] >= 75): ?>
                        <p class="text-xs text-yellow-600 dark:text-yellow-400 mt-1">
                            <i class="fas fa-info-circle mr-1"></i>Auslastung über 75%
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- In Stock and In Transit Section -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Im Lager (In Stock) -->
        <div class="card p-6 rounded-xl shadow-lg dark:bg-gray-800">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
                <i class="fas fa-warehouse text-green-600 dark:text-green-400 mr-2"></i>
                Im Lager
            </h2>
            <div class="space-y-4">
                <div class="flex items-center justify-between p-4 bg-green-50 dark:bg-green-900/30 rounded-lg">
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">Gesamtbestand</p>
                        <p class="text-2xl font-bold text-green-700 dark:text-green-400"><?php echo number_format($inStockStats['total_in_stock']); ?> Einheiten</p>
                    </div>
                    <div class="w-12 h-12 bg-green-100 dark:bg-green-800 rounded-lg flex items-center justify-center">
                        <i class="fas fa-box-open text-green-600 dark:text-green-400 text-xl"></i>
                    </div>
                </div>
                <div class="flex items-center justify-between p-4 bg-blue-50 dark:bg-blue-900/30 rounded-lg">
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">Verschiedene Artikel</p>
                        <p class="text-2xl font-bold text-blue-700 dark:text-blue-400"><?php echo number_format($inStockStats['unique_items_in_stock']); ?> Artikel</p>
                    </div>
                    <div class="w-12 h-12 bg-blue-100 dark:bg-blue-800 rounded-lg flex items-center justify-center">
                        <i class="fas fa-boxes text-blue-600 dark:text-blue-400 text-xl"></i>
                    </div>
                </div>
                <div class="flex items-center justify-between p-4 bg-purple-50 dark:bg-purple-900/30 rounded-lg">
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">Wert im Lager</p>
                        <p class="text-2xl font-bold text-purple-700 dark:text-purple-400"><?php echo number_format((float)$inStockStats['total_value_in_stock'], 2); ?> €</p>
                    </div>
                    <div class="w-12 h-12 bg-purple-100 dark:bg-purple-800 rounded-lg flex items-center justify-center">
                        <i class="fas fa-euro-sign text-purple-600 dark:text-purple-400 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Unterwegs (In Transit / Checked Out) -->
        <div class="card p-6 rounded-xl shadow-lg dark:bg-gray-800">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 mb-4">
                <i class="fas fa-truck text-orange-600 dark:text-orange-400 mr-2"></i>
                Unterwegs
            </h2>
            <?php if ($checkedOutStats['total_items_out'] > 0): ?>
            <div class="space-y-2 mb-4">
                <div class="flex items-center justify-between p-3 bg-orange-50 dark:bg-orange-900/30 rounded-lg">
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Aktive Ausleihen</p>
                        <p class="text-base sm:text-xl font-bold text-orange-700 dark:text-orange-400"><?php echo count($checkedOutStats['checkouts']); ?> Ausleihen</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Entliehene Menge</p>
                        <p class="text-base sm:text-xl font-bold text-orange-700 dark:text-orange-400"><?php echo $checkedOutStats['total_items_out']; ?> Einheiten</p>
                    </div>
                </div>
            </div>
            <div class="max-h-64 overflow-auto w-full">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-700 sticky top-0">
                        <tr>
                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Artikel</th>
                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Menge</th>
                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Entleiher</th>
                            <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Rückgabe</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($checkedOutStats['checkouts'] as $checkout): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                            <td class="px-2 py-2">
                                <a href="../inventory/view.php?id=<?php echo $checkout['item_id']; ?>" class="text-purple-600 dark:text-purple-400 hover:text-purple-800 dark:hover:text-purple-300 font-medium text-xs">
                                    <?php echo htmlspecialchars($checkout['item_name']); ?>
                                </a>
                            </td>
                            <td class="px-2 py-2 whitespace-nowrap text-xs text-gray-700 dark:text-gray-300">
                                <?php echo $checkout['amount']; ?> <?php echo htmlspecialchars($checkout['unit']); ?>
                            </td>
                            <td class="px-2 py-2 text-xs text-gray-700 dark:text-gray-300">
                                <?php echo htmlspecialchars($checkout['borrower_email']); ?>
                            </td>
                            <td class="px-2 py-2 text-xs text-gray-700 dark:text-gray-300">
                                <?php echo date('d.m.Y', strtotime($checkout['expected_return'] ?? $checkout['rented_at'])); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="text-center py-8">
                <i class="fas fa-check-circle text-4xl mb-3 text-green-400 dark:text-green-500"></i>
                <p class="text-gray-500 dark:text-gray-400">Keine aktiven Ausleihen</p>
                <p class="text-sm text-gray-400 dark:text-gray-500 mt-1">Alle Artikel sind im Lager</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Active Checkouts Section -->
    <div class="card mb-8">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100">
                <i class="fas fa-box-open mr-2 text-orange-600 dark:text-orange-400"></i>
                Wer hat was ausgeliehen?
            </h2>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Aktive Ausleihen mit Benutzernamen</p>
        </div>
        <div class="p-6">
            <?php if (empty($activeCheckouts)): ?>
                <div class="text-center py-8">
                    <i class="fas fa-check-circle text-6xl text-gray-300 dark:text-gray-600 mb-4"></i>
                    <p class="text-gray-600 dark:text-gray-300 text-lg">Keine Daten verfügbar</p>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Keine aktiven Ausleihen oder Tabelle nicht initialisiert</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto w-full">
                    <table class="w-full card-table divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    Artikel
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    Benutzer
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    Ausgeliehen am
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    Fällig am
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    Status
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                            <?php foreach ($activeCheckouts as $checkout): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100" data-label="Artikel">
                                    <?php echo htmlspecialchars($checkout['item_name']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap" data-label="Benutzer">
                                    <div class="text-sm text-gray-900 dark:text-gray-100 font-medium">
                                        <?php echo htmlspecialchars($checkout['user_name']); ?>
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        <?php echo htmlspecialchars($checkout['user_email']); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300" data-label="Ausgeliehen am">
                                    <?php echo date('d.m.Y H:i', strtotime($checkout['checked_out_at'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300" data-label="Fällig am">
                                    <?php 
                                    if (!empty($checkout['due_date'])) {
                                        echo date('d.m.Y', strtotime($checkout['due_date']));
                                    } else {
                                        echo '<span class="text-gray-400 dark:text-gray-500">Kein Datum</span>';
                                    }
                                    ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap" data-label="Status">
                                    <?php if ($checkout['is_overdue']): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 dark:bg-red-900/50 text-red-800 dark:text-red-300">
                                            <i class="fas fa-exclamation-triangle mr-1"></i>
                                            Überfällig
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/50 text-green-800 dark:text-green-300">
                                            <i class="fas fa-check-circle mr-1"></i>
                                            Aktiv
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-4 text-sm text-gray-600 dark:text-gray-300">
                    <strong>Gesamt:</strong> <?php echo count($activeCheckouts); ?> aktive Ausleihen
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Project Applications Section -->
    <div class="card">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100">
                <i class="fas fa-briefcase mr-2 text-purple-600 dark:text-purple-400"></i>
                Projekt Bewerbungen
            </h2>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Anzahl Bewerbungen pro Projekt</p>
        </div>
        <div class="p-6">
            <?php if (empty($projectApplications)): ?>
                <div class="text-center py-8">
                    <i class="fas fa-briefcase text-6xl text-gray-300 dark:text-gray-600 mb-4"></i>
                    <p class="text-gray-600 dark:text-gray-300 text-lg">Keine Projekte vorhanden</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto w-full">
                    <table class="w-full card-table divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    Projekttitel
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    Typ
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    Status
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    Bewerbungen
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                            <?php foreach ($projectApplications as $project): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                                <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-gray-100" data-label="Projekttitel">
                                    <a href="../projects/view.php?id=<?php echo $project['id']; ?>" 
                                       class="text-purple-600 dark:text-purple-400 hover:text-purple-800 dark:hover:text-purple-300 hover:underline">
                                        <?php echo htmlspecialchars($project['title']); ?>
                                    </a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300" data-label="Typ">
                                    <?php 
                                    $typeLabels = [
                                        'internal' => 'Intern',
                                        'external' => 'Extern'
                                    ];
                                    $typeColors = [
                                        'internal' => 'bg-blue-100 dark:bg-blue-900/50 text-blue-800 dark:text-blue-300',
                                        'external' => 'bg-green-100 dark:bg-green-900/50 text-green-800 dark:text-green-300'
                                    ];
                                    $typeLabel = $typeLabels[$project['type']] ?? $project['type'];
                                    $typeColor = $typeColors[$project['type']] ?? 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300';
                                    ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $typeColor; ?>">
                                        <?php echo htmlspecialchars($typeLabel); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300" data-label="Status">
                                    <?php 
                                    $statusLabels = [
                                        'open' => 'Offen',
                                        'assigned' => 'Zugewiesen',
                                        'running' => 'Läuft',
                                        'completed' => 'Abgeschlossen',
                                        'archived' => 'Archiviert'
                                    ];
                                    $statusColors = [
                                        'open' => 'bg-yellow-100 dark:bg-yellow-900/50 text-yellow-800 dark:text-yellow-300',
                                        'assigned' => 'bg-blue-100 dark:bg-blue-900/50 text-blue-800 dark:text-blue-300',
                                        'running' => 'bg-green-100 dark:bg-green-900/50 text-green-800 dark:text-green-300',
                                        'completed' => 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300',
                                        'archived' => 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400'
                                    ];
                                    $statusLabel = $statusLabels[$project['status']] ?? $project['status'];
                                    $statusColor = $statusColors[$project['status']] ?? 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300';
                                    ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $statusColor; ?>">
                                        <?php echo htmlspecialchars($statusLabel); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap" data-label="Bewerbungen">
                                    <div class="flex items-center">
                                        <span class="text-lg font-bold text-purple-600 dark:text-purple-400 mr-2">
                                            <?php echo $project['application_count']; ?>
                                        </span>
                                        <?php if ($project['application_count'] > 0): ?>
                                        <a href="../projects/applications.php?project_id=<?php echo $project['id']; ?>" 
                                           class="text-xs text-purple-600 dark:text-purple-400 hover:text-purple-800 dark:hover:text-purple-300 hover:underline">
                                            Details →
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-4 text-sm text-gray-600 dark:text-gray-300">
                    <strong>Gesamt:</strong> <?php echo count($projectApplications); ?> Projekte | 
                    <?php echo array_sum(array_column($projectApplications, 'application_count')); ?> Bewerbungen insgesamt
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Export Statistics Report
document.addEventListener('DOMContentLoaded', function() {
    const exportBtn = document.getElementById('exportStats');
    
    if (exportBtn) {
        exportBtn.addEventListener('click', function() {
            // Gather data from the page
            const activeUsers = <?php echo $activeUsersCount; ?>;
            const totalUsers = <?php echo $totalUsersCount; ?>;
            
            // Create CSV content
            let csv = 'Statistik Report - IBC Intranet\n';
            csv += 'Generiert am: ' + new Date().toLocaleString('de-DE') + '\n\n';
            
            csv += 'Metriken\n';
            csv += 'Kategorie,Wert\n';
            csv += 'Aktive Nutzer (7 Tage),' + activeUsers + '\n';
            csv += 'Gesamtanzahl User,' + totalUsers + '\n\n';
            
            // Add database stats if available
            <?php if (!empty($databaseStats)): ?>
            csv += 'Datenbank Speicherverbrauch\n';
            csv += 'Datenbank,Größe (MB),Auslastung (%)\n';
            <?php foreach ($databaseStats as $db): ?>
            csv += '<?php echo sanitizeCsvValue(htmlspecialchars($db['label'])); ?>,<?php echo $db['size_mb']; ?>,<?php echo number_format($db['percentage'], 2); ?>\n';
            <?php endforeach; ?>
            csv += '\n';
            <?php endif; ?>
            
            // Add active checkouts
            <?php if (!empty($activeCheckouts)): ?>
            csv += 'Aktive Ausleihen\n';
            csv += 'Artikel,Benutzer,Ausgeliehen am,Fällig am,Überfällig\n';
            <?php foreach ($activeCheckouts as $checkout): ?>
            csv += '"<?php echo str_replace('"', '""', sanitizeCsvValue($checkout['item_name'])); ?>","<?php echo str_replace('"', '""', sanitizeCsvValue($checkout['user_name'])); ?>","<?php echo $checkout['checked_out_at']; ?>","<?php echo $checkout['due_date'] ?? 'N/A'; ?>","<?php echo $checkout['is_overdue'] ? 'Ja' : 'Nein'; ?>"\n';
            <?php endforeach; ?>
            csv += '\n';
            <?php endif; ?>
            
            // Add project applications
            <?php if (!empty($projectApplications)): ?>
            csv += 'Projekt Bewerbungen\n';
            csv += 'Projekt,Typ,Status,Bewerbungen\n';
            <?php foreach ($projectApplications as $project): ?>
            csv += '"<?php echo str_replace('"', '""', sanitizeCsvValue($project['title'])); ?>","<?php echo sanitizeCsvValue($project['type']); ?>","<?php echo sanitizeCsvValue($project['status']); ?>",<?php echo $project['application_count']; ?>\n';
            <?php endforeach; ?>
            <?php endif; ?>
            
            // Create download link
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            const dateStr = new Date().toLocaleDateString('de-DE').replace(/\./g, '-');
            
            link.setAttribute('href', url);
            link.setAttribute('download', 'statistik_report_' + dateStr + '.csv');
            link.style.visibility = 'hidden';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });
    }
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
