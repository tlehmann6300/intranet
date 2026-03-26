<?php
require_once __DIR__ . '/../../src/Auth.php';

if (!Auth::isBoard()) {
    header('Location: /index.php');
    exit;
}

// Get audit logs from content database
$db = Database::getContentDB();

$limit = 100;
$page = intval($_GET['page'] ?? 1);
$offset = ($page - 1) * $limit;

$filters = [];
$params = [];
$sql = "SELECT * FROM system_logs WHERE 1=1";

if (!empty($_GET['action'])) {
    $sql .= " AND action LIKE ?";
    $params[] = '%' . $_GET['action'] . '%';
}

if (!empty($_GET['user_id'])) {
    $sql .= " AND user_id = ?";
    $params[] = $_GET['user_id'];
}

$sql .= " ORDER BY timestamp DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get total count
$countSql = "SELECT COUNT(*) as total FROM system_logs WHERE 1=1";
$countParams = [];
if (!empty($_GET['action'])) {
    $countSql .= " AND action LIKE ?";
    $countParams[] = '%' . $_GET['action'] . '%';
}
if (!empty($_GET['user_id'])) {
    $countSql .= " AND user_id = ?";
    $countParams[] = $_GET['user_id'];
}
$stmt = $db->prepare($countSql);
$stmt->execute($countParams);
$totalLogs = $stmt->fetch()['total'];
$totalPages = ceil($totalLogs / $limit);

/**
 * Returns static Tailwind CSS classes for styling an audit log entry based on its action type.
 *
 * @param  string $action  The raw action string from the system_logs table.
 * @return array{badge: string, icon: string, dot: string}
 *   - 'badge': CSS classes for the pill/badge element (background + text color)
 *   - 'icon':  Font Awesome icon class(es) representing the action
 *   - 'dot':   CSS class for the small colored dot within the badge
 *
 * NOTE: All returned class strings are static so that Tailwind's content scanner
 *       can detect them at build time. Do NOT concatenate color names dynamically.
 */
function getActionStyle(string $action): array {
    $action = strtolower($action);
    if (str_contains($action, 'delete'))     return ['badge' => 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300',     'icon' => 'fas fa-trash-alt',         'dot' => 'bg-red-500'];
    if (str_contains($action, 'login_fail')) return ['badge' => 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300',     'icon' => 'fas fa-times-circle',      'dot' => 'bg-red-400'];
    if (str_contains($action, 'create'))     return ['badge' => 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300', 'icon' => 'fas fa-plus-circle',    'dot' => 'bg-green-500'];
    if (str_contains($action, 'login'))      return ['badge' => 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300', 'icon' => 'fas fa-sign-in-alt',    'dot' => 'bg-green-400'];
    if (str_contains($action, 'logout'))     return ['badge' => 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300',        'icon' => 'fas fa-sign-out-alt',   'dot' => 'bg-gray-400'];
    if (str_contains($action, 'update'))     return ['badge' => 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300', 'icon' => 'fas fa-pencil-alt',     'dot' => 'bg-amber-400'];
    if (str_contains($action, 'invitation')) return ['badge' => 'bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300', 'icon' => 'fas fa-envelope',   'dot' => 'bg-purple-500'];
    return                                          ['badge' => 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300',        'icon' => 'fas fa-circle',         'dot' => 'bg-gray-400'];
}

$title = 'Audit-Logs - IBC Intranet';
ob_start();
?>

<div class="max-w-7xl mx-auto">

<div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="flex items-center gap-3 mb-1">
            <div class="w-11 h-11 rounded-2xl bg-purple-100 dark:bg-purple-900/40 flex items-center justify-center shadow-sm">
                <i class="fas fa-clipboard-list text-purple-600 dark:text-purple-400 text-xl"></i>
            </div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-gray-50 tracking-tight">Audit-Logs</h1>
        </div>
        <p class="text-gray-500 dark:text-gray-400 text-sm ml-14"><?php echo number_format($totalLogs); ?> Einträge insgesamt</p>
    </div>
</div>

<!-- Filters -->
<div class="card p-5 mb-6 rounded-xl shadow-sm">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Aktion</label>
            <input
                type="text"
                name="action"
                placeholder="z.B. login, create, update..."
                value="<?php echo htmlspecialchars($_GET['action'] ?? ''); ?>"
                class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-2 focus:ring-purple-400/50 focus:border-purple-400 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white transition-colors"
            >
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Benutzer-ID</label>
            <input
                type="number"
                name="user_id"
                placeholder="Benutzer-ID"
                value="<?php echo htmlspecialchars($_GET['user_id'] ?? ''); ?>"
                class="w-full px-4 py-2 bg-white border border-gray-300 text-gray-900 rounded-lg focus:ring-2 focus:ring-purple-400/50 focus:border-purple-400 dark:bg-gray-800 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white transition-colors"
            >
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="flex-1 btn-primary min-h-[44px]">
                <i class="fas fa-search mr-2"></i>Filtern
            </button>
            <a href="audit.php" class="px-4 min-h-[44px] flex items-center justify-center bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition" title="Filter zurücksetzen">
                <i class="fas fa-times"></i>
            </a>
        </div>
    </form>
</div>

<!-- Timeline Log List -->
<div class="card rounded-xl shadow-sm overflow-hidden">
    <?php if (empty($logs)): ?>
    <div class="p-16 text-center">
        <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
            <i class="fas fa-clipboard text-3xl text-gray-300 dark:text-gray-500"></i>
        </div>
        <p class="text-gray-500 dark:text-gray-400 text-lg font-medium">Keine Logs gefunden</p>
        <p class="text-gray-400 dark:text-gray-500 text-sm mt-1">Versuche andere Filterkriterien</p>
    </div>
    <?php else: ?>

    <!-- Legend -->
    <div class="px-6 pt-5 pb-3 border-b border-gray-100 dark:border-gray-700 flex flex-wrap gap-3">
        <span class="inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400"><span class="w-2 h-2 rounded-full bg-green-500 flex-shrink-0"></span>Erstellt / Login</span>
        <span class="inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400"><span class="w-2 h-2 rounded-full bg-amber-400 flex-shrink-0"></span>Aktualisiert</span>
        <span class="inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400"><span class="w-2 h-2 rounded-full bg-red-500 flex-shrink-0"></span>Gelöscht / Fehlgeschlagen</span>
        <span class="inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400"><span class="w-2 h-2 rounded-full bg-purple-500 flex-shrink-0"></span>Einladung</span>
        <span class="inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400"><span class="w-2 h-2 rounded-full bg-gray-400 flex-shrink-0"></span>Sonstige</span>
    </div>

    <div class="divide-y divide-gray-50 dark:divide-gray-700/50">
        <?php foreach ($logs as $log):
            $style = getActionStyle($log['action']);
        ?>
        <div class="flex items-start gap-4 px-6 py-4 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">

            <!-- Timeline icon -->
            <div class="flex-shrink-0 mt-0.5">
                <div class="w-8 h-8 rounded-full <?php echo $style['badge']; ?> flex items-center justify-center">
                    <i class="<?php echo $style['icon']; ?> text-xs"></i>
                </div>
            </div>

            <!-- Content -->
            <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2 mb-1">
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold max-w-full break-all <?php echo $style['badge']; ?>">
                        <span class="w-1.5 h-1.5 rounded-full <?php echo $style['dot']; ?> flex-shrink-0"></span>
                        <?php echo htmlspecialchars($log['action']); ?>
                    </span>
                    <?php if ($log['entity_type']): ?>
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        <?php echo htmlspecialchars($log['entity_type']); ?>
                        <?php if ($log['entity_id']): ?><span class="text-gray-400 dark:text-gray-500">#<?php echo (int) $log['entity_id']; ?></span><?php endif; ?>
                    </span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($log['details']) && $log['details'] !== '-'): ?>
                <p class="text-sm text-gray-600 dark:text-gray-300 break-all"><?php echo htmlspecialchars($log['details']); ?></p>
                <?php endif; ?>

                <div class="flex flex-wrap items-center gap-3 mt-1.5 text-xs text-gray-400 dark:text-gray-500">
                    <span class="inline-flex items-center gap-1">
                        <i class="fas fa-clock"></i>
                        <?php echo date('d.m.Y H:i:s', strtotime($log['timestamp'])); ?>
                    </span>
                    <?php if ($log['user_id']): ?>
                    <span class="inline-flex items-center gap-1">
                        <i class="fas fa-user"></i>
                        ID: <?php echo (int) $log['user_id']; ?>
                    </span>
                    <?php else: ?>
                    <span class="inline-flex items-center gap-1 italic">
                        <i class="fas fa-robot"></i>System
                    </span>
                    <?php endif; ?>
                    <?php if (!empty($log['ip_address'])): ?>
                    <span class="inline-flex items-center gap-1">
                        <i class="fas fa-network-wired"></i>
                        <?php echo htmlspecialchars($log['ip_address']); ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="px-6 py-4 bg-gray-50 dark:bg-gray-800/50 border-t border-gray-100 dark:border-gray-700 flex items-center justify-between">
        <div class="text-sm text-gray-500 dark:text-gray-400">
            Seite <?php echo $page; ?> von <?php echo $totalPages; ?>
        </div>
        <div class="flex gap-4">
            <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page - 1; ?><?php echo !empty($_GET['action']) ? '&action=' . urlencode($_GET['action']) : ''; ?><?php echo !empty($_GET['user_id']) ? '&user_id=' . urlencode($_GET['user_id']) : ''; ?>" class="inline-flex items-center gap-1 px-4 py-3 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-600 transition text-sm text-gray-700 dark:text-gray-300">
                <i class="fas fa-chevron-left text-xs"></i> Zurück
            </a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
            <a href="?page=<?php echo $page + 1; ?><?php echo !empty($_GET['action']) ? '&action=' . urlencode($_GET['action']) : ''; ?><?php echo !empty($_GET['user_id']) ? '&user_id=' . urlencode($_GET['user_id']) : ''; ?>" class="inline-flex items-center gap-1 px-4 py-3 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-600 transition text-sm text-gray-700 dark:text-gray-300">
                Weiter <i class="fas fa-chevron-right text-xs"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
