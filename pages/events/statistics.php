<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/EventDocumentation.php';
require_once __DIR__ . '/../../src/Database.php';

// Check authentication
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();
$userRole = $_SESSION['user_role'] ?? 'mitglied';

// Check if user has permission to view documentation (board and alumni_vorstand only)
$allowedDocRoles = array_merge(Auth::BOARD_ROLES, ['alumni_vorstand']);
if (!in_array($userRole, $allowedDocRoles)) {
    header('Location: index.php');
    exit;
}

// Get all event documentation with event titles
$allDocs = EventDocumentation::getAllWithEvents();

$title = 'Event-Statistiken - Historie';
ob_start();
?>

<div class="max-w-7xl mx-auto">

    <!-- Page Header -->
    <div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-3 mb-1">
                <div class="w-11 h-11 rounded-2xl bg-purple-100 dark:bg-purple-900/40 flex items-center justify-center shadow-sm flex-shrink-0">
                    <i class="fas fa-chart-bar text-purple-600 dark:text-purple-400 text-xl"></i>
                </div>
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-gray-50 tracking-tight">Event-Statistiken</h1>
            </div>
            <p class="text-gray-500 dark:text-gray-400 text-sm ml-14">Übersicht aller Verkäufer und Statistiken vergangener Events</p>
        </div>
        <a href="index.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600 transition text-sm font-medium flex-shrink-0">
            <i class="fas fa-arrow-left text-xs"></i>
            Zurück zur Übersicht
        </a>
    </div>

    <?php if (empty($allDocs)): ?>
        <div class="card rounded-xl shadow-sm p-16 text-center">
            <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center">
                <i class="fas fa-chart-line text-3xl text-gray-300 dark:text-gray-500"></i>
            </div>
            <h3 class="text-lg font-semibold text-gray-700 dark:text-gray-300 mb-2">Noch keine Statistiken vorhanden</h3>
            <p class="text-gray-500 dark:text-gray-400 text-sm">Erstelle Event-Dokumentationen, um hier Statistiken zu sehen.</p>
        </div>
    <?php else: ?>
        <?php
        $totalEvents = count($allDocs);
        $totalSellers = 0;
        $totalSales = 0;

        foreach ($allDocs as $doc) {
            if (!empty($doc['sellers_data'])) {
                $totalSellers += count($doc['sellers_data']);
            }
            if (!empty($doc['sales_data'])) {
                foreach ($doc['sales_data'] as $sale) {
                    $totalSales += floatval($sale['amount'] ?? 0);
                }
            }
        }
        ?>

        <!-- Statistics Summary -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 md:gap-4 mb-8">
            <div class="card rounded-xl shadow-sm p-5 flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-purple-100 dark:bg-purple-900/40 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-calendar-check text-purple-600 dark:text-purple-400 text-xl"></i>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wide">Events dokumentiert</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-50"><?php echo $totalEvents; ?></p>
                </div>
            </div>

            <div class="card rounded-xl shadow-sm p-5 flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-user-tie text-blue-600 dark:text-blue-400 text-xl"></i>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wide">Verkäufer-Einträge</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-50"><?php echo $totalSellers; ?></p>
                </div>
            </div>

            <div class="card rounded-xl shadow-sm p-5 flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-green-100 dark:bg-green-900/40 flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-euro-sign text-green-600 dark:text-green-400 text-xl"></i>
                </div>
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wide">Gesamtumsatz</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-gray-50"><?php echo number_format($totalSales, 2, ',', '.'); ?>€</p>
                </div>
            </div>
        </div>

        <!-- Events List with Statistics -->
        <div class="space-y-4">
            <?php foreach ($allDocs as $doc): ?>
                <div class="card rounded-xl shadow-sm overflow-hidden">
                    <!-- Event Header -->
                    <div class="flex flex-wrap items-start justify-between gap-3 px-6 py-5 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                        <div class="min-w-0">
                            <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100 break-words">
                                <?php echo htmlspecialchars($doc['event_title']); ?>
                            </h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                                <i class="fas fa-calendar mr-1.5"></i>
                                <?php echo date('d.m.Y', strtotime($doc['start_time'])); ?>
                            </p>
                        </div>
                        <a href="view.php?id=<?php echo $doc['event_id']; ?>"
                           class="inline-flex items-center gap-2 px-4 py-2.5 min-h-[44px] bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-all text-sm font-medium flex-shrink-0">
                            <i class="fas fa-eye"></i>
                            <span class="hidden sm:inline">Event ansehen</span>
                            <span class="sm:hidden">Ansehen</span>
                        </a>
                    </div>

                    <div class="p-6 space-y-6">
                        <!-- Sellers Data -->
                        <?php if (!empty($doc['sellers_data'])): ?>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wide mb-3 flex items-center gap-2">
                                    <i class="fas fa-user-tie text-blue-500"></i>
                                    Verkäufer
                                </h3>
                                <div class="overflow-x-auto -mx-6 px-6">
                                    <table class="w-full text-sm card-table">
                                        <thead class="bg-gray-50 dark:bg-gray-800">
                                            <tr>
                                                <th class="px-4 py-3 text-left font-semibold text-gray-600 dark:text-gray-300 text-xs uppercase tracking-wide">Verkäufer/Stand</th>
                                                <th class="px-4 py-3 text-left font-semibold text-gray-600 dark:text-gray-300 text-xs uppercase tracking-wide">Artikel</th>
                                                <th class="px-4 py-3 text-left font-semibold text-gray-600 dark:text-gray-300 text-xs uppercase tracking-wide">Menge</th>
                                                <th class="px-4 py-3 text-left font-semibold text-gray-600 dark:text-gray-300 text-xs uppercase tracking-wide">Umsatz</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                            <?php foreach ($doc['sellers_data'] as $seller): ?>
                                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                                    <td class="px-4 py-3 font-medium text-gray-800 dark:text-gray-200" data-label="Verkäufer/Stand">
                                                        <?php echo htmlspecialchars($seller['seller_name'] ?? '-'); ?>
                                                    </td>
                                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300" data-label="Artikel">
                                                        <?php echo htmlspecialchars($seller['items'] ?? '-'); ?>
                                                    </td>
                                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300" data-label="Menge">
                                                        <?php echo htmlspecialchars($seller['quantity'] ?? '-'); ?>
                                                    </td>
                                                    <td class="px-4 py-3 text-gray-600 dark:text-gray-300 font-medium" data-label="Umsatz">
                                                        <?php echo htmlspecialchars($seller['revenue'] ?? '-'); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Sales Data -->
                        <?php if (!empty($doc['sales_data'])): ?>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wide mb-3 flex items-center gap-2">
                                    <i class="fas fa-chart-line text-purple-500"></i>
                                    Verkaufsdaten
                                </h3>
                                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                                    <?php foreach ($doc['sales_data'] as $sale): ?>
                                        <div class="p-4 bg-purple-50 dark:bg-purple-900/20 rounded-xl border border-purple-100 dark:border-purple-800">
                                            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1"><?php echo htmlspecialchars($sale['label'] ?? 'Unbenannt'); ?></p>
                                            <p class="text-xl font-bold text-purple-700 dark:text-purple-300">
                                                <?php echo number_format(floatval($sale['amount'] ?? 0), 2, ',', '.'); ?>€
                                            </p>
                                            <?php if (!empty($sale['date'])): ?>
                                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1.5">
                                                    <i class="fas fa-calendar-alt mr-1"></i><?php echo date('d.m.Y', strtotime($sale['date'])); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Calculations Link -->
                        <?php if (!empty($doc['calculation_link'])): ?>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wide mb-3 flex items-center gap-2">
                                    <i class="fas fa-calculator text-green-500"></i>
                                    Kalkulationen
                                </h3>
                                <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700">
                                    <a href="<?php echo htmlspecialchars($doc['calculation_link']); ?>"
                                       target="_blank" rel="noopener noreferrer"
                                       class="inline-flex items-center gap-2 text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 font-medium text-sm break-all">
                                        <i class="fas fa-external-link-alt flex-shrink-0"></i>
                                        <?php echo htmlspecialchars($doc['calculation_link']); ?>
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (empty($doc['sellers_data']) && empty($doc['sales_data']) && empty($doc['calculation_link'])): ?>
                            <p class="text-sm text-gray-400 dark:text-gray-500 italic">Keine Detaildaten vorhanden.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
