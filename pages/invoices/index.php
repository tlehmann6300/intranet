<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Invoice.php';
require_once __DIR__ . '/../../includes/models/User.php';
require_once __DIR__ . '/../../includes/models/Member.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';

// Access Control: Allow 'board', 'alumni_vorstand', 'ressortleiter', 'alumni' (read-only)
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();

// Check if user has permission to access invoices page
$hasInvoiceAccess = Auth::canAccessPage('invoices');
if (!$hasInvoiceAccess) {
    header('Location: ../dashboard/index.php');
    exit;
}

$userRole = $user['role'] ?? '';

// Role-based visibility groups:
// Group 1 (submit only):  alumni, ehrenmitglied, anwaerter, mitglied, ressortleiter
// Group 2 (read only):    vorstand_intern, vorstand_extern, alumni_finanz, alumni_vorstand
// Group 3 (full access):  vorstand_finanzen
$canViewTable      = in_array($userRole, ['vorstand_intern', 'vorstand_extern', 'alumni_finanz', 'alumni_vorstand', 'vorstand_finanzen']);
$canEditInvoices   = ($userRole === 'vorstand_finanzen');
$canSubmitInvoice  = in_array($userRole, ['alumni', 'ehrenmitglied', 'anwaerter', 'mitglied', 'ressortleiter', 'vorstand_finanzen']);

// Only vorstand_finanzen can mark invoices as paid
$canMarkAsPaid = $canEditInvoices;

// Get invoices and stats only for roles that can view the table
$invoices = [];
$stats = null;
if ($canViewTable) {
    $invoices = Invoice::getAll($userRole, $user['id']);
    $stats = Invoice::getStats();
}

// Get user database for fetching submitter info
$userDb = Database::getUserDB();

// Pre-fetch all user info once (avoids N+1 in template)
$userInfoMap = [];
if (!empty($invoices)) {
    $allUids = array_unique(array_merge(
        array_column($invoices, 'user_id'),
        array_filter(array_column($invoices, 'paid_by_user_id'))
    ));
    if (!empty($allUids)) {
        $ph = str_repeat('?,', count($allUids) - 1) . '?';
        $uStmt = $userDb->prepare("SELECT id, email FROM users WHERE id IN ($ph)");
        $uStmt->execute($allUids);
        foreach ($uStmt->fetchAll() as $u) {
            $userInfoMap[$u['id']] = $u['email'];
        }
    }
}

// Collect open invoices for the dedicated banner
$openInvoices = array_values(array_filter($invoices, fn($inv) => in_array($inv['status'], ['pending', 'approved'])));

// Compute display status: approved invoices older than 14 days are shown as 'overdue'
$overdueThresholdDays = 14;
foreach ($invoices as &$inv) {
    $inv['_display_status'] = ($inv['status'] === 'approved' && (time() - strtotime($inv['created_at'])) / 86400 > $overdueThresholdDays)
        ? 'overdue' : $inv['status'];
}
unset($inv);

// Compute summary stats from the fetched invoices (visible to all users)
$summaryOpenAmount = 0.0;
$summaryInReviewCount = 0;
$summaryPaidAmount = 0.0;
$summaryPaidCount = 0;
foreach ($invoices as $inv) {
    if (in_array($inv['status'], ['pending', 'approved'])) $summaryOpenAmount += (float)$inv['amount'];
    if ($inv['status'] === 'pending') $summaryInReviewCount++;
    if ($inv['status'] === 'paid') { $summaryPaidAmount += (float)$inv['amount']; $summaryPaidCount++; }
}

$title = 'Rechnungsmanagement - IBC Intranet';
ob_start();
?>

<div class="max-w-7xl mx-auto">
    <!-- Print-only header (hidden on screen, shown when printing) -->
    <div class="invoice-print-header hidden">
        <img src="<?php echo asset('assets/img/ibc_logo_original.webp'); ?>" alt="IBC Logo" class="img-fluid">
        <div class="invoice-print-header-meta">
            IBC – International Business Club<br>
            Rechnungsübersicht<br>
            Druckdatum: <?php echo date('d.m.Y'); ?>
        </div>
    </div>
    <div class="invoice-print-footer hidden">
        IBC – Rechnungsmanagement &mdash; Seite <span class="print-page-num"></span>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="mb-5 p-3.5 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800/50 text-green-700 dark:text-green-300 rounded-xl text-sm font-medium flex items-center gap-2">
        <i class="fas fa-check-circle text-green-500 flex-shrink-0"></i><?php echo htmlspecialchars($_SESSION['success_message']); ?>
    </div>
    <?php 
        unset($_SESSION['success_message']); 
    endif; 
    ?>
    
    <?php if (isset($_SESSION['error_message'])): ?>
    <div class="mb-5 p-3.5 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800/50 text-red-700 dark:text-red-300 rounded-xl text-sm font-medium flex items-center gap-2">
        <i class="fas fa-exclamation-circle text-red-500 flex-shrink-0"></i><?php echo htmlspecialchars($_SESSION['error_message']); ?>
    </div>
    <?php 
        unset($_SESSION['error_message']); 
    endif; 
    ?>

    <!-- Header -->
    <div class="mb-8 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 no-print">
        <div>
            <div class="flex items-center gap-3 mb-1">
                <div class="w-10 h-10 rounded-2xl bg-blue-50 dark:bg-blue-900/40 flex items-center justify-center shadow-sm">
                    <i class="fas fa-file-invoice-dollar text-blue-600 dark:text-blue-400 text-lg"></i>
                </div>
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-gray-50 tracking-tight">Rechnungen</h1>
            </div>
            <p class="text-gray-500 dark:text-gray-400 text-sm ml-0.5">Belege einreichen und Erstattungen verfolgen</p>
        </div>
        <?php if ($canSubmitInvoice): ?>
        <button
            id="openSubmissionModal"
            class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-sm hover:shadow-md transition-all text-sm flex-shrink-0"
        >
            <i class="fas fa-plus"></i>
            Beleg einreichen
        </button>
        <?php endif; ?>
    </div>

    <?php if ($canViewTable): ?>
    <!-- Summary Stats -->
    <div class="grid grid-cols-1 sm:grid-cols-3 <?php echo $stats ? 'lg:grid-cols-4' : ''; ?> gap-3 mb-8 no-print">
        <!-- Offen -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 p-5 shadow-sm">
            <p class="text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wide mb-1">Offen</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-gray-50"><?php echo number_format($summaryOpenAmount, 2, ',', '.'); ?> €</p>
            <p class="text-xs text-red-500 dark:text-red-400 mt-1 font-medium">Ausstehend</p>
        </div>

        <!-- In Prüfung -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 p-5 shadow-sm">
            <p class="text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wide mb-1">In Prüfung</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-gray-50"><?php echo $summaryInReviewCount; ?></p>
            <p class="text-xs text-amber-500 dark:text-amber-400 mt-1 font-medium">Warten auf Genehmigung</p>
        </div>

        <!-- Bezahlt -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 p-5 shadow-sm">
            <p class="text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wide mb-1">Bezahlt</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-gray-50"><?php echo number_format($summaryPaidAmount, 2, ',', '.'); ?> €</p>
            <p class="text-xs text-green-500 dark:text-green-400 mt-1 font-medium"><?php echo $summaryPaidCount; ?> Rechnung<?php echo $summaryPaidCount !== 1 ? 'en' : ''; ?></p>
        </div>

        <?php if ($stats): ?>
        <!-- Gesamt Ausstehend (Board) -->
        <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 p-5 shadow-sm">
            <p class="text-xs font-medium text-gray-400 dark:text-gray-500 uppercase tracking-wide mb-1">Gesamt Offen</p>
            <p class="text-2xl font-bold text-gray-900 dark:text-gray-50"><?php echo number_format($stats['total_pending'], 2, ',', '.'); ?> €</p>
            <p class="text-xs text-orange-500 dark:text-orange-400 mt-1 font-medium">Alle ausstehend</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Offene Rechnungen Banner -->
    <?php if (!empty($openInvoices)): ?>
    <div class="mb-6 bg-amber-50 dark:bg-amber-950/20 border border-amber-200 dark:border-amber-800/40 rounded-2xl p-4 flex items-center justify-between gap-3 no-print">
        <div class="flex items-center gap-2.5">
            <div class="w-8 h-8 rounded-xl bg-amber-400 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-exclamation text-white text-xs font-bold"></i>
            </div>
            <p class="text-sm font-semibold text-amber-800 dark:text-amber-200">
                <?php echo count($openInvoices); ?> offene Rechnung<?php echo count($openInvoices) !== 1 ? 'en' : ''; ?> warten auf Bearbeitung
            </p>
        </div>
        <button onclick="filterByStatus('pending')" class="text-xs font-semibold text-amber-700 dark:text-amber-300 hover:text-amber-900 dark:hover:text-amber-100 transition-colors flex-shrink-0">
            Anzeigen <i class="fas fa-arrow-right ml-1"></i>
        </button>
    </div>
    <?php endif; ?>

    <!-- Filter Tabs + Export -->
    <?php
    $statusCounts = ['all' => count($invoices), 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'paid' => 0];
    foreach ($invoices as $inv) {
        if (isset($statusCounts[$inv['status']])) $statusCounts[$inv['status']]++;
    }
    ?>
    <div class="mb-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 no-print">
        <!-- Status filter tabs -->
        <div class="flex flex-wrap gap-1.5 bg-gray-100 dark:bg-gray-800 rounded-xl p-1">
            <button onclick="filterByStatus('all')" id="tab-all"
                class="filter-tab px-3.5 py-1.5 rounded-lg text-sm font-medium transition-all text-gray-600 dark:text-gray-400">
                Alle <span class="ml-1 text-xs"><?php echo $statusCounts['all']; ?></span>
            </button>
            <button onclick="filterByStatus('pending')" id="tab-pending"
                class="filter-tab px-3.5 py-1.5 rounded-lg text-sm font-medium transition-all text-gray-600 dark:text-gray-400">
                In Prüfung <span class="ml-1 text-xs"><?php echo $statusCounts['pending']; ?></span>
            </button>
            <button onclick="filterByStatus('approved')" id="tab-approved"
                class="filter-tab px-3.5 py-1.5 rounded-lg text-sm font-medium transition-all text-gray-600 dark:text-gray-400">
                Offen <span class="ml-1 text-xs"><?php echo $statusCounts['approved']; ?></span>
            </button>
            <button onclick="filterByStatus('rejected')" id="tab-rejected"
                class="filter-tab px-3.5 py-1.5 rounded-lg text-sm font-medium transition-all text-gray-600 dark:text-gray-400">
                Abgelehnt <span class="ml-1 text-xs"><?php echo $statusCounts['rejected']; ?></span>
            </button>
            <button onclick="filterByStatus('paid')" id="tab-paid"
                class="filter-tab px-3.5 py-1.5 rounded-lg text-sm font-medium transition-all text-gray-600 dark:text-gray-400">
                Bezahlt <span class="ml-1 text-xs"><?php echo $statusCounts['paid']; ?></span>
            </button>
        </div>
        <?php if (Auth::isBoard() || Auth::hasRole(['alumni_vorstand', 'alumni_finanz'])): ?>
        <a
            href="<?php echo asset('api/export_invoices.php'); ?>"
            class="inline-flex items-center gap-1.5 px-4 py-2 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-700 transition-all no-underline flex-shrink-0"
        >
            <i class="fas fa-download text-xs"></i>
            Exportieren
        </a>
        <?php endif; ?>
    </div>

    <!-- Invoices Table -->
    <div id="invoices-table">
        <?php if (empty($invoices)): ?>
            <div class="p-16 text-center">
                <div class="w-20 h-20 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-5">
                    <i class="fas fa-file-invoice text-4xl text-gray-400 dark:text-gray-500"></i>
                </div>
                <p class="text-base sm:text-xl font-semibold text-gray-600 dark:text-gray-300 mb-2">Keine Rechnungen vorhanden</p>
                <p class="text-gray-500 dark:text-gray-400 mb-5">Erstelle Deine erste Einreichung</p>
                <?php if ($canSubmitInvoice): ?>
                <button onclick="document.getElementById('openSubmissionModal').click()"
                    class="inline-flex items-center px-5 py-2.5 bg-blue-600 text-white rounded-xl font-semibold hover:bg-blue-700 transition-all shadow-md">
                    <i class="fas fa-plus mr-2"></i>Neue Einreichung
                </button>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-card overflow-hidden">
                <?php
                // Status configuration (dots + colors + labels)
                $statusColors = [
                    'pending'  => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200 ring-1 ring-inset ring-amber-500/40 dark:ring-amber-400/30',
                    'approved' => 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-800 dark:text-yellow-200 ring-1 ring-inset ring-yellow-500/40 dark:ring-yellow-400/30',
                    'rejected' => 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200 ring-1 ring-inset ring-red-500/40 dark:ring-red-400/30',
                    'paid'     => 'bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-200 ring-1 ring-inset ring-green-500/40 dark:ring-green-400/30',
                    'overdue'  => 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200 ring-1 ring-inset ring-red-500/40 dark:ring-red-400/30',
                ];
                $statusDots = [
                    'pending'  => 'bg-amber-500 dark:bg-amber-400',
                    'approved' => 'bg-yellow-500 dark:bg-yellow-400',
                    'rejected' => 'bg-red-500 dark:bg-red-400',
                    'paid'     => 'bg-green-500 dark:bg-green-400',
                    'overdue'  => 'bg-red-500 dark:bg-red-400',
                ];
                $statusLabels = [
                    'pending'  => 'In Prüfung',
                    'approved' => 'Offen',
                    'rejected' => 'Abgelehnt',
                    'paid'     => 'Bezahlt',
                    'overdue'  => 'Überfällig',
                ];
                ?>

                <!-- Mobile Card View (visible on small screens only) -->
                <div class="md:hidden flex flex-col gap-4 p-4">
                    <?php foreach ($invoices as $invoice): ?>
                        <?php
                        $submitterEmail = $userInfoMap[$invoice['user_id']] ?? 'Unknown';
                        $submitterName  = explode('@', $submitterEmail)[0];
                        $initials       = strtoupper(substr($submitterName, 0, 2));
                        $displayStatus  = $invoice['_display_status'];
                        $statusClass    = $statusColors[$displayStatus] ?? 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300 ring-1 ring-inset ring-gray-500/10';
                        $statusDot      = $statusDots[$displayStatus] ?? 'bg-gray-400';
                        $statusLabel    = $statusLabels[$displayStatus] ?? ucfirst($displayStatus);

                        $paidAt      = !empty($invoice['paid_at']) ? date('d.m.Y', strtotime($invoice['paid_at'])) : '';
                        $paidByName  = '';
                        if (!empty($invoice['paid_by_user_id']) && isset($userInfoMap[$invoice['paid_by_user_id']])) {
                            $paidByName = explode('@', $userInfoMap[$invoice['paid_by_user_id']])[0];
                        }
                        $fileUrl         = !empty($invoice['file_path']) ? htmlspecialchars(asset('api/download_invoice_file.php?id=' . (int)$invoice['id']), ENT_QUOTES, 'UTF-8') : '';
                        $rejectionReason = !empty($invoice['rejection_reason']) ? htmlspecialchars($invoice['rejection_reason'], ENT_QUOTES) : '';
                        ?>
                        <div class="p-5 bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-sm hover:shadow-md transition-all duration-200 cursor-pointer invoice-row relative overflow-hidden" data-status="<?php echo htmlspecialchars($invoice['status']); ?>"
                             onclick="openInvoiceDetail({
                                 id: '<?php echo $invoice['id']; ?>',
                                 date: '<?php echo date('d.m.Y', strtotime($invoice['created_at'])); ?>',
                                 submitter: '<?php echo htmlspecialchars($submitterName, ENT_QUOTES); ?>',
                                 initials: '<?php echo htmlspecialchars($initials, ENT_QUOTES); ?>',
                                 description: <?php echo json_encode($invoice['description']); ?>,
                                 amount: '<?php echo number_format($invoice['amount'], 2, ',', '.'); ?>',
                                 status: '<?php echo htmlspecialchars($invoice['status'], ENT_QUOTES); ?>',
                                 displayStatus: '<?php echo htmlspecialchars($displayStatus, ENT_QUOTES); ?>',
                                 statusLabel: '<?php echo htmlspecialchars($statusLabel, ENT_QUOTES); ?>',
                                 filePath: '<?php echo $fileUrl; ?>',
                                 paidAt: '<?php echo $paidAt; ?>',
                                 paidBy: '<?php echo htmlspecialchars($paidByName, ENT_QUOTES); ?>',
                                 rejectionReason: <?php echo json_encode($invoice['rejection_reason'] ?? ''); ?>
                             })">
                            <div class="flex items-start justify-between mb-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center text-blue-600 dark:text-blue-300 font-semibold text-sm flex-shrink-0">
                                        <?php echo htmlspecialchars($initials); ?>
                                    </div>
                                    <div>
                                        <p class="font-semibold text-gray-900 dark:text-gray-100 text-sm"><?php echo htmlspecialchars($submitterName); ?></p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo date('d.m.Y', strtotime($invoice['created_at'])); ?></p>
                                    </div>
                                </div>
                                <span class="inline-flex items-center gap-x-1.5 px-2.5 py-1 text-xs font-medium rounded-full <?php echo $statusClass; ?>">
                                    <span class="w-1.5 h-1.5 rounded-full flex-shrink-0 <?php echo $statusDot; ?>"></span>
                                    <?php echo htmlspecialchars($statusLabel); ?>
                                </span>
                            </div>
                            <p class="text-sm text-gray-700 dark:text-gray-300 mb-3 line-clamp-2"><?php echo htmlspecialchars($invoice['description']); ?></p>
                            <div class="flex items-center justify-between">
                                <span class="text-xl font-extrabold text-gray-900 dark:text-white tracking-tight">
                                    <?php echo number_format($invoice['amount'], 2, ',', '.'); ?> €
                                </span>
                                <?php if (!empty($invoice['file_path'])): ?>
                                    <div class="flex items-center gap-1" onclick="event.stopPropagation()">
                                        <a href="<?php echo $fileUrl; ?>"
                                           target="_blank"
                                           title="Ansehen"
                                           class="inline-flex items-center px-2 py-1 bg-blue-600 text-white rounded-xl text-xs font-medium hover:bg-blue-700 transition-colors no-underline shadow-sm hover:shadow">
                                            <i class="fas fa-eye mr-1"></i>Ansehen
                                        </a>
                                        <a href="<?php echo $fileUrl; ?>"
                                           download
                                           title="Herunterladen"
                                           class="inline-flex items-center px-2 py-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-xl text-xs font-medium hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors no-underline">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if ($canEditInvoices && $invoice['status'] === 'pending'): ?>
                            <div class="flex gap-4 mt-3" onclick="event.stopPropagation()">
                                <button onclick="updateInvoiceStatus(<?php echo $invoice['id']; ?>, 'approved')"
                                    class="flex-1 px-3 py-2 min-h-[44px] bg-green-600 text-white rounded-xl text-xs font-medium hover:bg-green-700 shadow-sm hover:shadow-md hover:-translate-y-0.5 transform transition-all duration-200">
                                    <i class="fas fa-check mr-1"></i>Genehmigen
                                </button>
                                <button onclick="updateInvoiceStatus(<?php echo $invoice['id']; ?>, 'rejected')"
                                    class="flex-1 px-3 py-2 min-h-[44px] bg-red-600 text-white rounded-xl text-xs font-medium hover:bg-red-700 shadow-sm hover:shadow-md hover:-translate-y-0.5 transform transition-all duration-200">
                                    <i class="fas fa-times mr-1"></i>Ablehnen
                                </button>
                            </div>
                            <?php elseif ($canEditInvoices && $invoice['status'] === 'approved' && $canMarkAsPaid): ?>
                            <div class="mt-3" onclick="event.stopPropagation()">
                                <button onclick="markInvoiceAsPaid(<?php echo $invoice['id']; ?>)"
                                    class="w-full px-3 py-2 min-h-[44px] bg-blue-600 text-white rounded-xl text-xs font-medium hover:bg-blue-700 shadow-sm hover:shadow-md hover:-translate-y-0.5 transform transition-all duration-200">
                                    <i class="fas fa-check-double mr-1"></i>Als Bezahlt markieren
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Desktop Table View (hidden on small screens) -->
                <div class="hidden md:block overflow-x-auto w-full has-action-dropdown">
                    <table class="min-w-full">
                    <thead class="bg-gray-50 dark:bg-gray-800/80 backdrop-blur-sm border-b border-gray-200 dark:border-gray-700">
                        <tr>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Datum</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Einreicher</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Zweck</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Betrag</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Beleg</th>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                            <?php if ($canEditInvoices): ?>
                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider invoice-actions-col">Aktionen</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-100 dark:divide-gray-800">
                        <?php foreach ($invoices as $invoice): ?>
                            <?php
                            $submitterEmail = $userInfoMap[$invoice['user_id']] ?? 'Unknown';
                            $submitterName  = explode('@', $submitterEmail)[0];
                            $initials       = strtoupper(substr($submitterName, 0, 2));
                            $displayStatus  = $invoice['_display_status'];
                            $statusClass    = $statusColors[$displayStatus] ?? 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300 ring-1 ring-inset ring-gray-500/10';
                            $statusDot      = $statusDots[$displayStatus] ?? 'bg-gray-400';
                            $statusLabel    = $statusLabels[$displayStatus] ?? ucfirst($displayStatus);

                            $paidAt     = !empty($invoice['paid_at']) ? date('d.m.Y', strtotime($invoice['paid_at'])) : '';
                            $paidByName = '';
                            if (!empty($invoice['paid_by_user_id']) && isset($userInfoMap[$invoice['paid_by_user_id']])) {
                                $paidByName = explode('@', $userInfoMap[$invoice['paid_by_user_id']])[0];
                            }
                            $fileUrl = !empty($invoice['file_path']) ? htmlspecialchars(asset('api/download_invoice_file.php?id=' . (int)$invoice['id']), ENT_QUOTES, 'UTF-8') : '';
                            ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/60 transition-colors duration-150 cursor-pointer invoice-row" data-status="<?php echo htmlspecialchars($invoice['status']); ?>"
                                onclick="openInvoiceDetail({
                                    id: '<?php echo $invoice['id']; ?>',
                                    date: '<?php echo date('d.m.Y', strtotime($invoice['created_at'])); ?>',
                                    submitter: '<?php echo htmlspecialchars($submitterName, ENT_QUOTES); ?>',
                                    initials: '<?php echo htmlspecialchars($initials, ENT_QUOTES); ?>',
                                    description: <?php echo json_encode($invoice['description']); ?>,
                                    amount: '<?php echo number_format($invoice['amount'], 2, ',', '.'); ?>',
                                    status: '<?php echo htmlspecialchars($invoice['status'], ENT_QUOTES); ?>',
                                    displayStatus: '<?php echo htmlspecialchars($displayStatus, ENT_QUOTES); ?>',
                                    statusLabel: '<?php echo htmlspecialchars($statusLabel, ENT_QUOTES); ?>',
                                    filePath: '<?php echo $fileUrl; ?>',
                                    paidAt: '<?php echo $paidAt; ?>',
                                    paidBy: '<?php echo htmlspecialchars($paidByName, ENT_QUOTES); ?>',
                                    rejectionReason: <?php echo json_encode($invoice['rejection_reason'] ?? ''); ?>
                                })">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                    <?php echo date('d.m.Y', strtotime($invoice['created_at'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center text-blue-600 dark:text-blue-300 font-semibold mr-3">
                                            <?php echo htmlspecialchars($initials); ?>
                                        </div>
                                        <div class="text-sm text-gray-900 dark:text-gray-100">
                                            <?php echo htmlspecialchars($submitterName); ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-100 max-w-xs truncate">
                                    <?php echo htmlspecialchars($invoice['description']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-base font-extrabold text-gray-900 dark:text-white">
                                    <?php echo number_format($invoice['amount'], 2, ',', '.'); ?> €
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm" onclick="event.stopPropagation()">
                                    <?php if (!empty($invoice['file_path'])): ?>
                                        <div class="flex items-center gap-2">
                                            <a href="<?php echo $fileUrl; ?>"
                                               target="_blank"
                                               title="Ansehen"
                                               class="inline-flex items-center px-2.5 py-1.5 bg-blue-600 dark:bg-blue-700 text-white rounded-xl text-xs font-semibold hover:bg-blue-700 dark:hover:bg-blue-600 transition-all shadow-sm hover:shadow hover:-translate-y-0.5 transform no-underline">
                                                <i class="fas fa-eye mr-1"></i>Ansehen
                                            </a>
                                            <a href="<?php echo $fileUrl; ?>"
                                               download
                                               title="Herunterladen"
                                               class="inline-flex items-center px-2.5 py-1.5 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 rounded-xl text-xs font-semibold hover:bg-gray-200 dark:hover:bg-gray-600 transition-all shadow-sm hover:shadow hover:-translate-y-0.5 transform no-underline">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-gray-400 dark:text-gray-500">Kein Beleg</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center gap-x-1.5 px-2.5 py-1 text-xs font-medium rounded-full <?php echo $statusClass; ?>">
                                        <span class="w-1.5 h-1.5 rounded-full flex-shrink-0 <?php echo $statusDot; ?>"></span>
                                        <?php echo htmlspecialchars($statusLabel); ?>
                                    </span>
                                </td>
                                <?php if ($canEditInvoices): ?>
                                <td class="px-6 py-4 whitespace-nowrap text-sm invoice-actions-col" onclick="event.stopPropagation()">
                                    <?php if ($invoice['status'] === 'pending'): ?>
                                        <div class="flex gap-4">
                                            <button
                                                onclick="updateInvoiceStatus(<?php echo $invoice['id']; ?>, 'approved')"
                                                class="inline-flex items-center px-3 py-2 min-h-[44px] bg-green-600 dark:bg-green-700 text-white rounded-xl text-xs font-semibold hover:bg-green-700 dark:hover:bg-green-600 shadow-sm hover:shadow-md hover:-translate-y-0.5 transform transition-all duration-200">
                                                <i class="fas fa-check mr-1"></i>Genehmigen
                                            </button>
                                            <button
                                                onclick="updateInvoiceStatus(<?php echo $invoice['id']; ?>, 'rejected')"
                                                class="inline-flex items-center px-3 py-2 min-h-[44px] bg-red-600 dark:bg-red-700 text-white rounded-xl text-xs font-semibold hover:bg-red-700 dark:hover:bg-red-600 shadow-sm hover:shadow-md hover:-translate-y-0.5 transform transition-all duration-200">
                                                <i class="fas fa-times mr-1"></i>Ablehnen
                                            </button>
                                        </div>
                                    <?php elseif ($invoice['status'] === 'approved' && $canMarkAsPaid): ?>
                                        <button
                                            onclick="markInvoiceAsPaid(<?php echo $invoice['id']; ?>)"
                                            class="inline-flex items-center px-3 py-2 min-h-[44px] bg-blue-600 dark:bg-blue-700 text-white rounded-xl text-xs font-semibold hover:bg-blue-700 dark:hover:bg-blue-600 shadow-sm hover:shadow-md hover:-translate-y-0.5 transform transition-all duration-200">
                                            <i class="fas fa-check-circle mr-1"></i>Als Bezahlt markieren
                                        </button>
                                    <?php else: ?>
                                        <span class="text-gray-400 dark:text-gray-500 text-xs">-</span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($canViewTable): ?>
<!-- Invoice Detail Modal -->
<div id="invoiceDetailModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-2xl w-full max-h-[85vh] flex flex-col overflow-hidden">
        <!-- Header -->
        <div class="p-5 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800 dark:text-gray-100">
                <i class="fas fa-file-invoice mr-2 text-blue-600 dark:text-blue-400"></i>
                Rechnungsdetails <span id="detail-id" class="text-gray-400 dark:text-gray-500 text-base font-normal"></span>
            </h2>
            <button id="closeDetailModal" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-300 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <!-- Body -->
        <div class="p-5 space-y-4 overflow-y-auto flex-1">
            <!-- Submitter + Date row -->
            <div class="flex items-center gap-4">
                <div id="detail-avatar" class="w-12 h-12 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center text-blue-600 dark:text-blue-300 font-bold text-lg flex-shrink-0"></div>
                <div>
                    <p class="font-semibold text-gray-900 dark:text-gray-100" id="detail-submitter"></p>
                    <p class="text-sm text-gray-500 dark:text-gray-400"><i class="fas fa-calendar-alt mr-1"></i><span id="detail-date"></span></p>
                </div>
                <div class="ml-auto">
                    <span id="detail-status-badge" class="inline-flex items-center px-3 py-1 text-sm font-semibold rounded-full border"></span>
                </div>
            </div>

            <!-- Description -->
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4">
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Zweck</p>
                <p class="text-gray-800 dark:text-gray-200" id="detail-description"></p>
            </div>

            <!-- Amount -->
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Betrag</p>
                <p class="text-2xl font-bold text-blue-700 dark:text-blue-300"><span id="detail-amount"></span> €</p>
            </div>

            <!-- Paid Info (conditionally shown) -->
            <div id="detail-paid-row" class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4 hidden">
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Bezahlt am</p>
                <p class="text-gray-800 dark:text-gray-200" id="detail-paid-info"></p>
            </div>

            <!-- Rejection Reason (conditionally shown) -->
            <div id="detail-rejection-row" class="bg-red-50 dark:bg-red-900/20 rounded-lg p-4 hidden">
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Ablehnungsgrund</p>
                <p class="text-gray-800 dark:text-gray-200" id="detail-rejection"></p>
            </div>

            <!-- Document -->
            <div>
                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">Beleg</p>
                <div id="detail-document" class="hidden">
                    <div id="detail-doc-preview"></div>
                    <a id="detail-doc-link" href="#" target="_blank"
                       class="inline-flex items-center mt-2 px-3 py-1.5 bg-blue-600 dark:bg-blue-700 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 dark:hover:bg-blue-600 transition-all shadow-sm no-underline">
                        <i class="fas fa-external-link-alt mr-1"></i>In neuem Tab öffnen
                    </a>
                </div>
                <p id="detail-no-document" class="text-gray-400 dark:text-gray-500 text-sm hidden">
                    <i class="fas fa-ban mr-1"></i>Kein Beleg hochgeladen
                </p>
            </div>
        </div>
        <!-- Footer -->
        <div class="px-5 pb-5 space-y-3">
            <?php if ($canEditInvoices): ?>
            <!-- Board action buttons (shown/hidden dynamically by JS) -->
            <div id="detail-actions-pending" class="hidden flex flex-col md:flex-row gap-4">
                <button onclick="updateInvoiceStatusFromDetail('approved')"
                    class="flex-1 inline-flex items-center justify-center px-4 py-3 bg-green-600 dark:bg-green-700 text-white rounded-lg font-semibold hover:bg-green-700 dark:hover:bg-green-600 transition-all shadow-sm">
                    <i class="fas fa-check mr-2"></i>Genehmigen
                </button>
                <button onclick="openRejectModal()"
                    class="flex-1 inline-flex items-center justify-center px-4 py-3 bg-red-600 dark:bg-red-700 text-white rounded-lg font-semibold hover:bg-red-700 dark:hover:bg-red-600 transition-all shadow-sm">
                    <i class="fas fa-times mr-2"></i>Ablehnen
                </button>
            </div>
            <?php if ($canMarkAsPaid): ?>
            <div id="detail-actions-approved" class="hidden">
                <button onclick="markInvoiceAsPaidFromDetail()"
                    class="w-full inline-flex items-center justify-center px-4 py-3 bg-blue-600 dark:bg-blue-700 text-white rounded-lg font-semibold hover:bg-blue-700 dark:hover:bg-blue-600 transition-all shadow-sm">
                    <i class="fas fa-check-circle mr-2"></i>Als Bezahlt markieren
                </button>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <button id="closeDetailModalBtn"
                class="w-full px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg font-semibold hover:bg-gray-300 dark:hover:bg-gray-600 transition-all">
                Schließen
            </button>
        </div>
    </div>
</div>

<!-- Rejection Reason Modal -->
<div id="rejectModal" class="fixed inset-0 bg-black bg-opacity-50 z-[60] hidden flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-lg max-h-[85vh] flex flex-col overflow-hidden">
        <div class="p-5 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-bold text-gray-800 dark:text-gray-100">
                <i class="fas fa-times-circle mr-2 text-red-500"></i>Rechnung ablehnen
            </h3>
        </div>
        <div class="p-5 overflow-y-auto flex-1">
            <label class="block w-full text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Ablehnungsgrund <span class="text-gray-400 dark:text-gray-500 font-normal">(optional)</span></label>
            <textarea id="rejectReasonInput" rows="3"
                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent resize-none"
                placeholder="Grund für die Ablehnung (optional)..."></textarea>
        </div>
        <div class="px-5 pb-5 flex flex-col md:flex-row gap-3">
            <button onclick="confirmReject()"
                class="flex-1 px-4 py-3 bg-red-600 dark:bg-red-700 text-white rounded-lg font-semibold hover:bg-red-700 dark:hover:bg-red-600 transition-all">
                <i class="fas fa-times mr-2"></i>Ablehnen
            </button>
            <button onclick="document.getElementById('rejectModal').classList.add('hidden')"
                class="flex-1 px-4 py-3 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg font-semibold hover:bg-gray-300 dark:hover:bg-gray-600 transition-all">
                Abbrechen
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canSubmitInvoice): ?>
<!-- Submission Modal -->
<div id="submissionModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl max-w-lg w-full max-h-[90vh] flex flex-col overflow-hidden">
        <!-- Header -->
        <div class="px-6 py-5 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between flex-shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-blue-50 dark:bg-blue-900/40 flex items-center justify-center">
                    <i class="fas fa-file-invoice-dollar text-blue-600 dark:text-blue-400"></i>
                </div>
                <h2 class="text-lg font-bold text-gray-900 dark:text-gray-50">Beleg einreichen</h2>
            </div>
            <button id="closeSubmissionModal" class="min-w-[44px] min-h-[44px] rounded-lg flex items-center justify-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="submissionForm" action="<?php echo asset('api/submit_invoice.php'); ?>" method="POST" enctype="multipart/form-data" class="flex flex-col flex-1 min-h-0">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="p-6 overflow-y-auto flex-1 space-y-4">

                <!-- Amount + Date row -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label for="amount" class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1.5">
                            Betrag (€) <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="number"
                            id="amount"
                            name="amount"
                            step="0.01"
                            min="0"
                            required
                            placeholder="0,00"
                            class="w-full px-3.5 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500 transition-colors text-sm"
                        >
                    </div>
                    <div>
                        <label for="date" class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1.5">
                            Belegdatum <span class="text-red-500">*</span>
                        </label>
                        <input
                            type="date"
                            id="date"
                            name="date"
                            required
                            max="<?php echo date('Y-m-d'); ?>"
                            class="w-full px-3.5 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500 transition-colors text-sm"
                        >
                    </div>
                </div>

                <!-- Description -->
                <div>
                    <label for="description" class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1.5">
                        Zweck <span class="text-red-500">*</span>
                    </label>
                    <textarea
                        id="description"
                        name="description"
                        rows="3"
                        required
                        placeholder="Wofür wurde der Betrag ausgegeben?"
                        class="w-full px-3.5 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-gray-900 dark:text-gray-100 placeholder-gray-400 dark:placeholder-gray-500 focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500 transition-colors text-sm resize-none"
                    ></textarea>
                </div>

                <!-- File Upload -->
                <div>
                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1.5">
                        Beleg <span class="text-red-500">*</span>
                    </label>
                    <div
                        id="dropZone"
                        class="border-2 border-dashed border-gray-200 dark:border-gray-700 rounded-xl p-6 text-center hover:border-blue-400 dark:hover:border-blue-500 transition-colors cursor-pointer bg-gray-50 dark:bg-gray-800/50"
                    >
                        <input
                            type="file"
                            id="file"
                            name="file"
                            accept=".pdf,.jpg,.jpeg,.png"
                            required
                            class="hidden"
                        >
                        <div id="dropZoneContent">
                            <i class="fas fa-cloud-upload-alt text-3xl text-gray-300 dark:text-gray-600 mb-2"></i>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">
                                <span class="text-blue-600 dark:text-blue-400 font-semibold">Klicken</span> oder Datei hierher ziehen
                            </p>
                            <p class="text-xs text-gray-400 dark:text-gray-500">PDF, JPG, PNG · max. 10 MB</p>
                        </div>
                        <div id="fileInfo" class="hidden">
                            <i class="fas fa-file-check text-3xl text-green-500 dark:text-green-400 mb-2"></i>
                            <p id="fileName" class="text-sm text-gray-700 dark:text-gray-300 font-medium mb-1"></p>
                            <button type="button" id="removeFile" class="text-xs text-red-500 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300">
                                <i class="fas fa-times mr-0.5"></i>Entfernen
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="flex flex-col md:flex-row gap-3 px-6 pb-6 pt-2 flex-shrink-0">
                <button
                    type="submit"
                    class="flex-1 flex items-center justify-center gap-2 px-5 py-3 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl shadow-sm hover:shadow-md transition-all text-sm"
                >
                    <i class="fas fa-paper-plane"></i>
                    Einreichen
                </button>
                <button
                    type="button"
                    id="cancelSubmission"
                    class="px-5 py-3 bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-semibold rounded-xl hover:bg-gray-200 dark:hover:bg-gray-700 transition-all text-sm"
                >
                    Abbrechen
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
<?php if ($canViewTable): ?>
// ── Invoice Detail Modal ────────────────────────────────────────────────────
const detailModal = document.getElementById('invoiceDetailModal');
let currentDetailInvoiceId = null;

function openInvoiceDetail(data) {
    currentDetailInvoiceId = data.id;

    // Populate header
    document.getElementById('detail-id').textContent = '#' + data.id;
    document.getElementById('detail-avatar').textContent  = data.initials;
    document.getElementById('detail-submitter').textContent = data.submitter;
    document.getElementById('detail-date').textContent = data.date;
    document.getElementById('detail-description').textContent = data.description;
    document.getElementById('detail-amount').textContent = data.amount;

    // Status badge
    const badge = document.getElementById('detail-status-badge');
    const statusClasses = {
        pending:  'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200 ring-1 ring-inset ring-amber-500/40 dark:ring-amber-400/30',
        approved: 'bg-yellow-100 dark:bg-yellow-900/40 text-yellow-800 dark:text-yellow-200 ring-1 ring-inset ring-yellow-500/40 dark:ring-yellow-400/30',
        rejected: 'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200 ring-1 ring-inset ring-red-500/40 dark:ring-red-400/30',
        paid:     'bg-green-100 dark:bg-green-900/40 text-green-800 dark:text-green-200 ring-1 ring-inset ring-green-500/40 dark:ring-green-400/30',
        overdue:  'bg-red-100 dark:bg-red-900/40 text-red-800 dark:text-red-200 ring-1 ring-inset ring-red-500/40 dark:ring-red-400/30',
    };
    const statusDotColors = {
        pending:  'bg-amber-500',
        approved: 'bg-yellow-500',
        rejected: 'bg-red-500',
        paid:     'bg-green-500',
        overdue:  'bg-red-500',
    };
    const ds = data.displayStatus || data.status;
    badge.className = 'inline-flex items-center gap-x-1.5 px-3 py-1 text-sm font-medium rounded-full ' +
        (statusClasses[ds] || 'bg-gray-100 text-gray-600 ring-1 ring-inset ring-gray-500/10');
    const dotColor = statusDotColors[ds] || 'bg-gray-400';
    badge.innerHTML = `<span class="w-1.5 h-1.5 rounded-full flex-shrink-0 ${dotColor}"></span>${data.statusLabel}`;

    // Paid info
    const paidRow = document.getElementById('detail-paid-row');
    const paidInfo = document.getElementById('detail-paid-info');
    if (data.paidAt) {
        paidInfo.textContent = data.paidAt + (data.paidBy ? ' · von ' + data.paidBy : '');
        paidRow.classList.remove('hidden');
    } else {
        paidRow.classList.add('hidden');
    }

    // Rejection reason
    const rejRow = document.getElementById('detail-rejection-row');
    if (data.rejectionReason) {
        document.getElementById('detail-rejection').textContent = data.rejectionReason;
        rejRow.classList.remove('hidden');
    } else {
        rejRow.classList.add('hidden');
    }

    // Document
    const docContainer  = document.getElementById('detail-document');
    const docPreview    = document.getElementById('detail-doc-preview');
    const docLink       = document.getElementById('detail-doc-link');
    const noDoc         = document.getElementById('detail-no-document');
    docPreview.innerHTML = '';
    if (data.filePath) {
        const ext = data.filePath.split('.').pop().toLowerCase();
        if (['jpg', 'jpeg', 'png', 'heic'].includes(ext)) {
            const img = document.createElement('img');
            img.src = data.filePath;
            img.alt = 'Beleg';
            img.className = 'max-w-full rounded-lg border border-gray-200 dark:border-gray-700';
            docPreview.appendChild(img);
        } else if (ext === 'pdf') {
            const iframe = document.createElement('iframe');
            iframe.src = data.filePath;
            iframe.className = 'w-full rounded-lg border border-gray-200 dark:border-gray-700';
            iframe.style.height = '320px';
            iframe.setAttribute('frameborder', '0');
            docPreview.appendChild(iframe);
        }
        docLink.href = data.filePath;
        docContainer.classList.remove('hidden');
        noDoc.classList.add('hidden');
    } else {
        docContainer.classList.add('hidden');
        noDoc.classList.remove('hidden');
    }

    // Board action buttons
    const pendingActions  = document.getElementById('detail-actions-pending');
    const approvedActions = document.getElementById('detail-actions-approved');
    if (pendingActions)  pendingActions.classList.toggle('hidden',  data.status !== 'pending');
    if (approvedActions) approvedActions.classList.toggle('hidden', data.status !== 'approved');

    detailModal.classList.remove('hidden');
}

document.getElementById('closeDetailModal').addEventListener('click', () => {
    detailModal.classList.add('hidden');
});
document.getElementById('closeDetailModalBtn').addEventListener('click', () => {
    detailModal.classList.add('hidden');
});
detailModal.addEventListener('click', (e) => {
    if (e.target === detailModal) detailModal.classList.add('hidden');
});

// Actions from detail modal
function updateInvoiceStatusFromDetail(status) {
    if (status === 'rejected') { openRejectModal(); return; }
    updateInvoiceStatus(currentDetailInvoiceId, status);
}

function markInvoiceAsPaidFromDetail() {
    markInvoiceAsPaid(currentDetailInvoiceId);
}

// ── Rejection reason modal ──────────────────────────────────────────────────
function openRejectModal() {
    document.getElementById('rejectReasonInput').value = '';
    document.getElementById('rejectModal').classList.remove('hidden');
}

function confirmReject() {
    const reason = document.getElementById('rejectReasonInput').value.trim();
    document.getElementById('rejectModal').classList.add('hidden');
    const invoiceId = currentDetailInvoiceId !== null ? currentDetailInvoiceId : pendingRejectInvoiceId;
    _doUpdateStatus(invoiceId, 'rejected', reason);
}

<?php endif; ?>
<?php if ($canSubmitInvoice): ?>
// ── Submission Modal ────────────────────────────────────────────────────────
const modal = document.getElementById('submissionModal');
const openBtn = document.getElementById('openSubmissionModal');
const closeBtn = document.getElementById('closeSubmissionModal');
const cancelBtn = document.getElementById('cancelSubmission');

openBtn.addEventListener('click', () => {
    modal.classList.remove('hidden');
});

closeBtn.addEventListener('click', () => {
    modal.classList.add('hidden');
});

cancelBtn.addEventListener('click', () => {
    modal.classList.add('hidden');
});

// Close modal when clicking outside
modal.addEventListener('click', (e) => {
    if (e.target === modal) {
        modal.classList.add('hidden');
    }
});

// File upload handling
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('file');
const dropZoneContent = document.getElementById('dropZoneContent');
const fileInfo = document.getElementById('fileInfo');
const fileName = document.getElementById('fileName');
const removeFileBtn = document.getElementById('removeFile');

dropZone.addEventListener('click', () => {
    fileInput.click();
});

dropZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropZone.classList.add('border-blue-500', 'dark:border-blue-400', 'bg-blue-50', 'dark:bg-blue-900/20');
});

dropZone.addEventListener('dragleave', () => {
    dropZone.classList.remove('border-blue-500', 'dark:border-blue-400', 'bg-blue-50', 'dark:bg-blue-900/20');
});

dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('border-blue-500', 'dark:border-blue-400', 'bg-blue-50', 'dark:bg-blue-900/20');
    
    if (e.dataTransfer.files.length > 0) {
        fileInput.files = e.dataTransfer.files;
        updateFileInfo();
    }
});

fileInput.addEventListener('change', updateFileInfo);

removeFileBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    fileInput.value = '';
    dropZoneContent.classList.remove('hidden');
    fileInfo.classList.add('hidden');
});

function updateFileInfo() {
    if (fileInput.files.length > 0) {
        const file = fileInput.files[0];
        fileName.textContent = file.name;
        dropZoneContent.classList.add('hidden');
        fileInfo.classList.remove('hidden');
    }
}

<?php endif; ?>
// ── Status filter tabs ──────────────────────────────────────────────────────
function filterByStatus(status) {
    document.querySelectorAll('.filter-tab').forEach(btn => {
        btn.classList.remove('bg-white', 'dark:bg-gray-900', 'text-gray-900', 'dark:text-gray-50', 'shadow-sm', 'font-semibold');
        btn.classList.add('text-gray-600', 'dark:text-gray-400');
    });
    const activeTab = document.getElementById('tab-' + status);
    if (activeTab) {
        activeTab.classList.add('bg-white', 'dark:bg-gray-900', 'text-gray-900', 'dark:text-gray-50', 'shadow-sm', 'font-semibold');
        activeTab.classList.remove('text-gray-600', 'dark:text-gray-400');
    }
    document.querySelectorAll('.invoice-row').forEach(row => {
        if (status === 'all' || row.dataset.status === status) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// Initialise tab styling on page load
filterByStatus('all');

// ── Update invoice status ───────────────────────────────────────────────────
let pendingRejectInvoiceId = null;
const csrfToken = <?php echo json_encode(CSRFHandler::getToken()); ?>;

function updateInvoiceStatus(invoiceId, status) {
    if (status === 'rejected') {
        pendingRejectInvoiceId = invoiceId;
        openRejectModal();
        return;
    }
    _doUpdateStatus(invoiceId, status, null);
}

function _doUpdateStatus(invoiceId, status, reason) {
    const formData = new FormData();
    formData.append('invoice_id', invoiceId);
    formData.append('status', status);
    if (reason) formData.append('reason', reason);
    formData.append('csrf_token', csrfToken);
    
    fetch('<?php echo asset('api/update_invoice_status.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert('Fehler: ' + (data.error || 'Unbekannter Fehler'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Fehler beim Aktualisieren des Status');
    });
}

// Mark invoice as paid function
function markInvoiceAsPaid(invoiceId) {
    if (!confirm('Möchtest du diese Rechnung wirklich als bezahlt markieren?')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('invoice_id', invoiceId);
    formData.append('csrf_token', csrfToken);
    
    fetch('<?php echo asset('api/mark_invoice_paid.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert('Fehler: ' + (data.error || 'Unbekannter Fehler'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Fehler beim Markieren als bezahlt');
    });
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
