<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Invoice.php';
require_once __DIR__ . '/../../includes/models/User.php';
require_once __DIR__ . '/../../includes/models/Member.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$user = Auth::user();

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
$canMarkAsPaid     = $canEditInvoices;

$invoices = [];
$stats    = null;
if ($canViewTable) {
    $invoices = Invoice::getAll($userRole, $user['id']);
    $stats    = Invoice::getStats();
}

$userDb      = Database::getUserDB();
$userInfoMap = [];
if (!empty($invoices)) {
    $allUids = array_unique(array_merge(
        array_column($invoices, 'user_id'),
        array_filter(array_column($invoices, 'paid_by_user_id'))
    ));
    if (!empty($allUids)) {
        $ph    = str_repeat('?,', count($allUids) - 1) . '?';
        $uStmt = $userDb->prepare("SELECT id, email FROM users WHERE id IN ($ph)");
        $uStmt->execute($allUids);
        foreach ($uStmt->fetchAll() as $u) {
            $userInfoMap[$u['id']] = $u['email'];
        }
    }
}

$openInvoices = array_values(array_filter($invoices, fn($inv) => in_array($inv['status'], ['pending', 'approved'])));

$overdueThresholdDays = 14;
foreach ($invoices as &$inv) {
    $inv['_display_status'] = ($inv['status'] === 'approved' && (time() - strtotime($inv['created_at'])) / 86400 > $overdueThresholdDays)
        ? 'overdue' : $inv['status'];
}
unset($inv);

$summaryOpenAmount    = 0.0;
$summaryInReviewCount = 0;
$summaryPaidAmount    = 0.0;
$summaryPaidCount     = 0;
foreach ($invoices as $inv) {
    if (in_array($inv['status'], ['pending', 'approved'])) $summaryOpenAmount += (float)$inv['amount'];
    if ($inv['status'] === 'pending') $summaryInReviewCount++;
    if ($inv['status'] === 'paid') { $summaryPaidAmount += (float)$inv['amount']; $summaryPaidCount++; }
}

$title = 'Rechnungsmanagement - IBC Intranet';
ob_start();
?>

<style>
/* ── Rechnungen Module ── */
.inv-page-header {
    margin-bottom: 2rem;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.inv-header-content {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    flex: 1;
    min-width: 0;
}

.inv-header-icon {
    width: 3rem;
    height: 3rem;
    border-radius: 0.875rem;
    background: linear-gradient(135deg, var(--ibc-blue), #0088ee);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    box-shadow: 0 4px 14px rgba(0,102,179,0.28);
    color: #fff;
    font-size: 1.1875rem;
}

.inv-page-title {
    font-size: clamp(1.25rem, 4vw, 1.75rem);
    font-weight: 800;
    color: var(--text-main);
    margin: 0;
    line-height: 1.2;
}

.inv-page-subtitle {
    color: var(--text-muted);
    margin: 0.2rem 0 0;
    font-size: 0.8125rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.inv-submit-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 1.25rem;
    border-radius: 0.75rem;
    border: none;
    background: linear-gradient(135deg, var(--ibc-blue), #0088ee);
    color: #fff;
    font-weight: 700;
    font-size: 0.9rem;
    cursor: pointer;
    transition: opacity 0.2s, transform 0.22s cubic-bezier(.22,.68,0,1.2);
    box-shadow: 0 3px 10px rgba(0,102,179,0.3);
    min-height: 44px;
}

.inv-submit-btn:hover {
    opacity: 0.9;
    transform: translateY(-2px);
}

.inv-submit-btn:active {
    transform: translateY(0);
}

/* Flash messages */
.inv-flash {
    margin-bottom: 1.25rem;
    padding: 0.875rem 1.125rem;
    border-radius: 0.875rem;
    border: 1.5px solid;
    display: flex;
    align-items: center;
    gap: 0.625rem;
    font-size: 0.875rem;
    font-weight: 500;
}

.inv-flash--ok {
    background: rgba(0,166,81,0.08);
    border-color: rgba(0,166,81,0.2);
    color: var(--ibc-green);
}

.inv-flash--err {
    background: rgba(239,68,68,0.08);
    border-color: rgba(239,68,68,0.2);
    color: #ef4444;
}

/* ── Stat cards ── */
.inv-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr));
    gap: 0.875rem; margin-bottom: 2rem;
}
.inv-stat-card {
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 1rem; padding: 1.125rem;
    box-shadow: 0 2px 6px rgba(0,0,0,0.05);
}
.inv-stat-label  { font-size: 0.6875rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 0.25rem; }
.inv-stat-val    { font-size: 1.5rem; font-weight: 800; color: var(--text-main); line-height: 1.1; }
.inv-stat-sub    { font-size: 0.75rem; font-weight: 600; margin-top: 0.25rem; }
.inv-stat-sub--red    { color: #dc2626; }
.inv-stat-sub--amber  { color: #b45309; }
.inv-stat-sub--green  { color: #15803d; }
.inv-stat-sub--orange { color: #c2410c; }

/* ── Open invoices banner ── */
.inv-open-banner {
    margin-bottom: 1.5rem; padding: 0.875rem 1.125rem;
    background: rgba(245,158,11,0.08); border: 1px solid rgba(245,158,11,0.3);
    border-radius: 1rem; display: flex; align-items: center;
    justify-content: space-between; gap: 0.75rem;
}
.inv-open-banner-icon {
    width: 2rem; height: 2rem; border-radius: 0.5rem;
    background: #f59e0b; display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; color: #fff; font-weight: 900; font-size: 0.75rem;
}
.inv-open-banner-text { font-size: 0.875rem; font-weight: 600; color: #92400e; flex: 1; }
.inv-open-banner-link {
    font-size: 0.75rem; font-weight: 600; color: #b45309;
    background: none; border: none; cursor: pointer; transition: color .2s;
    white-space: nowrap;
}
.inv-open-banner-link:hover { color: #7c2d12; }

/* ── Filter tabs ── */
.inv-filter-bar {
    margin-bottom: 1.25rem; display: flex; flex-wrap: wrap;
    align-items: center; justify-content: space-between; gap: 0.75rem;
}
.inv-tabs-wrap {
    display: flex; flex-wrap: wrap; gap: 0.25rem;
    background: rgba(100,116,139,0.1); border-radius: 0.875rem; padding: 0.25rem;
}
.inv-tab {
    padding: 0.4375rem 0.875rem; border-radius: 0.625rem; border: none; cursor: pointer;
    font-size: 0.8125rem; font-weight: 500; color: var(--text-muted);
    background: transparent; transition: background .2s, color .2s;
}
.inv-tab:hover { color: var(--text-main); }
.inv-tab--active {
    background-color: var(--bg-card);
    color: var(--text-main) !important;
    font-weight: 700;
    box-shadow: 0 1px 4px rgba(0,0,0,0.1);
}
.inv-export-btn {
    display: inline-flex; align-items: center; gap: 0.375rem;
    padding: 0.5rem 1rem; border-radius: 0.75rem;
    background-color: var(--bg-card); border: 1px solid var(--border-color);
    color: var(--text-muted); font-size: 0.8125rem; font-weight: 600;
    text-decoration: none; transition: background .2s, color .2s;
}
.inv-export-btn:hover { background: rgba(100,116,139,0.1); color: var(--text-main); }

/* ── Table container ── */
.inv-table-wrap {
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 1.125rem;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
}

/* Status badge (PHP-generated inline style version) */
.inv-status-badge {
    display: inline-flex; align-items: center; gap: 0.375rem;
    padding: 0.25rem 0.75rem; border-radius: 999px;
    font-size: 0.7rem; font-weight: 700; border: 1px solid;
    white-space: nowrap;
}
.inv-status-dot {
    width: 0.4375rem; height: 0.4375rem; border-radius: 50%; flex-shrink: 0;
}

/* Mobile cards */
.inv-mob-card {
    padding: 1.125rem;
    border-bottom: 1px solid var(--border-color);
    cursor: pointer; transition: background .15s;
    position: relative; overflow: hidden;
}
.inv-mob-card:last-child { border-bottom: none; }
.inv-mob-card:hover { background: rgba(37,99,235,0.04); }
.inv-mob-avatar {
    width: 2.5rem; height: 2.5rem; border-radius: 50%;
    background: rgba(37,99,235,0.12); color: #2563eb;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 0.875rem; flex-shrink: 0;
}

/* Desktop table */
.inv-table { width: 100%; border-collapse: collapse; min-width: 38rem; }
.inv-table thead { background: rgba(100,116,139,0.06); }
.inv-table th {
    padding: 0.875rem 1.25rem;
    text-align: left; font-size: 0.6875rem; font-weight: 600;
    color: var(--text-muted); text-transform: uppercase; letter-spacing: .06em;
    border-bottom: 1px solid var(--border-color);
    white-space: nowrap;
}
.inv-table td {
    padding: 1rem 1.25rem; font-size: 0.875rem;
    color: var(--text-main); border-bottom: 1px solid rgba(100,116,139,0.1);
}
.inv-table tbody tr { cursor: pointer; transition: background .15s; }
.inv-table tbody tr:hover { background: rgba(37,99,235,0.04); }
.inv-table tbody tr:last-child td { border-bottom: none; }
.inv-table-avatar {
    width: 2.5rem; height: 2.5rem; border-radius: 50%;
    background: rgba(37,99,235,0.12); color: #2563eb;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 0.8125rem; flex-shrink: 0; margin-right: 0.75rem;
}
.inv-file-btn {
    display: inline-flex; align-items: center; gap: 0.25rem;
    padding: 0.3125rem 0.625rem; border-radius: 0.5rem;
    font-size: 0.75rem; font-weight: 600; text-decoration: none;
    transition: background .15s;
}
.inv-file-btn--view { background: #2563eb; color: #fff; }
.inv-file-btn--view:hover { background: #1d4ed8; color: #fff; }
.inv-file-btn--dl   { background: rgba(100,116,139,0.12); color: var(--text-muted); }
.inv-file-btn--dl:hover { background: rgba(100,116,139,0.22); }

/* Action buttons (board) */
.inv-btn-approve {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.4375rem 0.875rem;
    border-radius: 0.625rem;
    border: none;
    background: var(--ibc-green);
    color: #fff;
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: opacity 0.2s, transform 0.18s;
    min-height: 44px;
}

.inv-btn-approve:hover {
    opacity: 0.9;
    transform: translateY(-1px);
}

.inv-btn-reject {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.4375rem 0.875rem;
    border-radius: 0.625rem;
    border: none;
    background: #ef4444;
    color: #fff;
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: opacity 0.2s, transform 0.18s;
    min-height: 44px;
}

.inv-btn-reject:hover {
    opacity: 0.9;
    transform: translateY(-1px);
}

.inv-btn-paid {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.4375rem 0.875rem;
    border-radius: 0.625rem;
    border: none;
    background: var(--ibc-blue);
    color: #fff;
    font-size: 0.75rem;
    font-weight: 600;
    cursor: pointer;
    transition: opacity 0.2s, transform 0.18s;
    min-height: 44px;
}

.inv-btn-paid:hover {
    opacity: 0.9;
    transform: translateY(-1px);
}

/* ── Modals ──
 *
 * Achtung: `position: fixed` referenziert den Viewport NUR dann korrekt, wenn
 * KEIN Vorfahre `transform`, `filter` oder `perspective` setzt (Containing-
 * Block-Trap). Das Modal hängt sich daher per JS direkt an `document.body`
 * (siehe Block weiter unten). Hier wird das Layout viewport-korrekt
 * positioniert mit großzügigem Top-Padding, damit der Topbar das Header
 * des Modals nicht überdeckt. */
.inv-modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,0.55);
    z-index: 1050; opacity: 0; pointer-events: none;
    transition: opacity .25s;
    display: flex; align-items: center; justify-content: center;
    /* Top-Padding clearing die fixe Topbar (~64–80px) + Sicherheitsabstand */
    padding: clamp(72px, 9vh, 110px) 1rem 1.25rem;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
}
.inv-modal-overlay.open { opacity: 1; pointer-events: auto; }
.inv-modal-overlay--z60 { z-index: 1060; }
.inv-modal-box {
    background-color: var(--bg-card);
    border-radius: 1.125rem;
    box-shadow: 0 20px 60px rgba(0,0,0,0.35);
    width: min(42rem, 100%);
    /* Max-height greift jetzt unter Berücksichtigung des Top-Paddings */
    max-height: calc(100vh - clamp(96px, 12vh, 140px));
    display: flex; flex-direction: column; overflow: hidden;
    transform: translateY(10px) scale(.98);
    transition: transform .25s ease, opacity .25s;
    opacity: 0;
    margin: auto; /* zentriert vertikal innerhalb des padding-Bereichs */
}
.inv-modal-overlay.open .inv-modal-box { transform: translateY(0) scale(1); opacity: 1; }
@media (max-width: 599px) {
    .inv-modal-overlay {
        align-items: flex-end;
        padding: clamp(56px, 8vh, 96px) 0 0;
    }
    .inv-modal-box {
        width: 100%;
        border-radius: 1.25rem 1.25rem 0 0;
        max-height: calc(100vh - clamp(56px, 8vh, 96px));
        margin: 0;
    }
}
.inv-modal-header {
    padding: 1.125rem 1.375rem; border-bottom: 1px solid var(--border-color);
    display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
}
.inv-modal-title { font-size: 1rem; font-weight: 700; color: var(--text-main); }
.inv-modal-close {
    width: 2rem; height: 2rem; border-radius: 50%; border: none; cursor: pointer;
    background: rgba(100,116,139,0.12); color: var(--text-muted);
    display: flex; align-items: center; justify-content: center;
    transition: background .2s, color .2s; flex-shrink: 0;
}
.inv-modal-close:hover { background: rgba(220,38,38,0.12); color: #dc2626; }
.inv-modal-body { padding: 1.375rem; overflow-y: auto; flex: 1; }
.inv-modal-footer { padding: 0 1.375rem 1.375rem; flex-shrink: 0; }

/* Detail modal inner sections */
.inv-detail-section {
    background: rgba(100,116,139,0.06); border-radius: 0.75rem;
    padding: 0.875rem 1rem; margin-bottom: 1rem;
}
.inv-detail-section-label { font-size: 0.6875rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 0.375rem; }
.inv-detail-section-val   { font-size: 0.9375rem; color: var(--text-main); }
.inv-detail-section--blue  { background: rgba(37,99,235,0.08); }
.inv-detail-section--green { background: rgba(22,163,74,0.08); }
.inv-detail-section--red   { background: rgba(220,38,38,0.08); }

/* Form inputs in modals */
.inv-form-input {
    width: 100%;
    padding: 0.625rem 0.875rem;
    border: 1.5px solid var(--border-color);
    border-radius: 0.75rem;
    background-color: var(--bg-body);
    color: var(--text-main);
    font-size: 0.9rem;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
    min-height: 44px;
}

.inv-form-input:focus {
    border-color: var(--ibc-blue);
    box-shadow: 0 0 0 3px rgba(0,102,179,0.1);
}

.inv-form-input::placeholder {
    color: var(--text-muted);
}

.inv-form-label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 0.4rem;
}

/* Collapsible Bank-Section innerhalb Submission Modal */
.inv-bank-group summary::-webkit-details-marker { display: none; }
.inv-bank-group[open] .inv-bank-chevron { transform: rotate(180deg); }
.inv-bank-group summary:hover { background: rgba(37,99,235,0.05); border-radius: .75rem .75rem 0 0; }
.inv-bank-group:not([open]) summary { border-radius: .75rem; }

/* Drop zone */
.inv-drop-zone {
    border: 2px dashed var(--border-color);
    border-radius: 0.875rem;
    padding: 1.5rem;
    text-align: center;
    cursor: pointer;
    background: rgba(100,116,139,0.04);
    transition: border-color 0.2s, background 0.2s;
    min-height: 44px;
}

.inv-drop-zone:hover,
.inv-drop-zone--active {
    border-color: var(--ibc-blue);
    background: rgba(0,102,179,0.05);
}

/* Empty state */
.inv-empty { padding: 4rem 2rem; text-align: center; }

/* Print */
.invoice-print-header, .invoice-print-footer { display: none; }
@media print {
    .invoice-print-header, .invoice-print-footer { display: block; }
    .no-print { display: none !important; }
}
</style>

<div class="max-w-7xl mx-auto">

    <!-- Print-only header -->
    <div class="invoice-print-header">
        <img src="<?php echo asset('assets/img/ibc_logo_original.webp'); ?>" alt="IBC Logo" class="img-fluid">
        <div class="invoice-print-header-meta">
            IBC – International Business Club<br>
            Rechnungsübersicht<br>
            Druckdatum: <?php echo date('d.m.Y'); ?>
        </div>
    </div>
    <div class="invoice-print-footer">
        IBC – Rechnungsmanagement &mdash; Seite <span class="print-page-num"></span>
    </div>

    <!-- Flash messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
    <div class="inv-flash inv-flash--ok no-print">
        <i class="fas fa-check-circle flex-shrink-0"></i>
        <span><?php echo htmlspecialchars($_SESSION['success_message']); ?></span>
    </div>
    <?php unset($_SESSION['success_message']); endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
    <div class="inv-flash inv-flash--err no-print">
        <i class="fas fa-exclamation-circle flex-shrink-0"></i>
        <span><?php echo htmlspecialchars($_SESSION['error_message']); ?></span>
    </div>
    <?php unset($_SESSION['error_message']); endif; ?>

    <!-- Page Header -->
    <div class="inv-page-header no-print">
        <div class="inv-header-content">
            <div class="inv-header-icon">
                <i class="fas fa-file-invoice-dollar"></i>
            </div>
            <div>
                <h1 class="inv-page-title">Rechnungen</h1>
                <p class="inv-page-subtitle">Belege einreichen und Erstattungen verfolgen</p>
            </div>
        </div>
        <?php if ($canSubmitInvoice): ?>
        <button id="openSubmissionModal" class="inv-submit-btn">
            <i class="fas fa-plus"></i>Beleg einreichen
        </button>
        <?php endif; ?>
    </div>

    <?php if ($canViewTable): ?>
    <!-- Summary Stats -->
    <div class="inv-stats-grid no-print">
        <div class="inv-stat-card">
            <p class="inv-stat-label">Offen</p>
            <p class="inv-stat-val"><?php echo number_format($summaryOpenAmount, 2, ',', '.'); ?> €</p>
            <p class="inv-stat-sub inv-stat-sub--red">Ausstehend</p>
        </div>
        <div class="inv-stat-card">
            <p class="inv-stat-label">In Prüfung</p>
            <p class="inv-stat-val"><?php echo $summaryInReviewCount; ?></p>
            <p class="inv-stat-sub inv-stat-sub--amber">Warten auf Genehmigung</p>
        </div>
        <div class="inv-stat-card">
            <p class="inv-stat-label">Bezahlt</p>
            <p class="inv-stat-val"><?php echo number_format($summaryPaidAmount, 2, ',', '.'); ?> €</p>
            <p class="inv-stat-sub inv-stat-sub--green"><?php echo $summaryPaidCount; ?> Rechnung<?php echo $summaryPaidCount !== 1 ? 'en' : ''; ?></p>
        </div>
        <?php if ($stats): ?>
        <div class="inv-stat-card">
            <p class="inv-stat-label">Gesamt Offen</p>
            <p class="inv-stat-val"><?php echo number_format($stats['total_pending'], 2, ',', '.'); ?> €</p>
            <p class="inv-stat-sub inv-stat-sub--orange">Alle ausstehend</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Open invoices banner -->
    <?php if (!empty($openInvoices)): ?>
    <div class="inv-open-banner no-print">
        <div class="inv-open-banner-icon"><i class="fas fa-exclamation"></i></div>
        <p class="inv-open-banner-text">
            <?php echo count($openInvoices); ?> offene Rechnung<?php echo count($openInvoices) !== 1 ? 'en' : ''; ?> warten auf Bearbeitung
        </p>
        <button class="inv-open-banner-link" onclick="filterByStatus('pending')">
            Anzeigen <i class="fas fa-arrow-right" style="margin-left:0.25rem;"></i>
        </button>
    </div>
    <?php endif; ?>

    <!-- Filter bar -->
    <?php
    $statusCounts = ['all' => count($invoices), 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'paid' => 0];
    foreach ($invoices as $inv) {
        if (isset($statusCounts[$inv['status']])) $statusCounts[$inv['status']]++;
    }
    ?>
    <div class="inv-filter-bar no-print">
        <div class="inv-tabs-wrap">
            <button class="inv-tab" id="tab-all"      onclick="filterByStatus('all')">Alle <span style="font-size:.75rem; opacity:.7;"><?php echo $statusCounts['all']; ?></span></button>
            <button class="inv-tab" id="tab-pending"  onclick="filterByStatus('pending')">In Prüfung <span style="font-size:.75rem; opacity:.7;"><?php echo $statusCounts['pending']; ?></span></button>
            <button class="inv-tab" id="tab-approved" onclick="filterByStatus('approved')">Offen <span style="font-size:.75rem; opacity:.7;"><?php echo $statusCounts['approved']; ?></span></button>
            <button class="inv-tab" id="tab-rejected" onclick="filterByStatus('rejected')">Abgelehnt <span style="font-size:.75rem; opacity:.7;"><?php echo $statusCounts['rejected']; ?></span></button>
            <button class="inv-tab" id="tab-paid"     onclick="filterByStatus('paid')">Bezahlt <span style="font-size:.75rem; opacity:.7;"><?php echo $statusCounts['paid']; ?></span></button>
        </div>
        <?php if (Auth::isBoard() || Auth::hasRole(['alumni_vorstand', 'alumni_finanz'])): ?>
        <a href="<?php echo asset('api/export_invoices.php'); ?>" class="inv-export-btn no-print">
            <i class="fas fa-download" style="font-size:.75rem;"></i>Exportieren
        </a>
        <?php endif; ?>
    </div>

    <!-- Invoices Table -->
    <div id="invoices-table">
        <?php if (empty($invoices)): ?>
        <div class="inv-table-wrap">
            <div class="inv-empty">
                <div style="width:5rem; height:5rem; border-radius:50%; background:rgba(37,99,235,0.08); display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem;">
                    <i class="fas fa-file-invoice" style="font-size:2rem; color:rgba(37,99,235,0.3);"></i>
                </div>
                <p style="font-size:1rem; font-weight:700; color:var(--text-main); margin:0 0 0.375rem;">Keine Rechnungen vorhanden</p>
                <p style="font-size:0.875rem; color:var(--text-muted); margin:0 0 1.25rem;">Erstelle deine erste Einreichung</p>
                <?php if ($canSubmitInvoice): ?>
                <button onclick="document.getElementById('openSubmissionModal').click()" class="inv-submit-btn">
                    <i class="fas fa-plus"></i>Neue Einreichung
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php else: ?>
        <?php
        // Status style maps – no dark: Tailwind classes, use RGBA
        $statusStyles = [
            'pending'  => ['bg'=>'rgba(245,158,11,0.1)',  'color'=>'#b45309', 'border'=>'rgba(245,158,11,0.3)',  'dot'=>'#f59e0b', 'label'=>'In Prüfung'],
            'approved' => ['bg'=>'rgba(234,179,8,0.1)',   'color'=>'#a16207', 'border'=>'rgba(234,179,8,0.3)',   'dot'=>'#eab308', 'label'=>'Offen'],
            'rejected' => ['bg'=>'rgba(220,38,38,0.1)',   'color'=>'#b91c1c', 'border'=>'rgba(220,38,38,0.3)',   'dot'=>'#dc2626', 'label'=>'Abgelehnt'],
            'paid'     => ['bg'=>'rgba(22,163,74,0.1)',   'color'=>'#15803d', 'border'=>'rgba(22,163,74,0.3)',   'dot'=>'#16a34a', 'label'=>'Bezahlt'],
            'overdue'  => ['bg'=>'rgba(220,38,38,0.1)',   'color'=>'#b91c1c', 'border'=>'rgba(220,38,38,0.3)',   'dot'=>'#dc2626', 'label'=>'Überfällig'],
        ];
        ?>
        <div class="inv-table-wrap">
            <!-- Mobile Card View -->
            <div id="inv-mobile-view">
                <?php foreach ($invoices as $invoice):
                    $submitterEmail = $userInfoMap[$invoice['user_id']] ?? 'Unknown';
                    $submitterName  = explode('@', $submitterEmail)[0];
                    $initials       = strtoupper(substr($submitterName, 0, 2));
                    $displayStatus  = $invoice['_display_status'];
                    $ss             = $statusStyles[$displayStatus] ?? $statusStyles['pending'];
                    $statusLabel    = $ss['label'];

                    $paidAt      = !empty($invoice['paid_at']) ? date('d.m.Y', strtotime($invoice['paid_at'])) : '';
                    $paidByName  = '';
                    if (!empty($invoice['paid_by_user_id']) && isset($userInfoMap[$invoice['paid_by_user_id']])) {
                        $paidByName = explode('@', $userInfoMap[$invoice['paid_by_user_id']])[0];
                    }
                    $fileUrl         = !empty($invoice['file_path']) ? htmlspecialchars(asset('api/download_invoice_file.php?id=' . (int)$invoice['id']), ENT_QUOTES, 'UTF-8') : '';
                    $rejectionReason = !empty($invoice['rejection_reason']) ? htmlspecialchars($invoice['rejection_reason'], ENT_QUOTES) : '';
                ?>
                <div class="inv-mob-card invoice-row" data-status="<?php echo htmlspecialchars($invoice['status']); ?>"
                     onclick="openInvoiceDetail({
                         id:'<?php echo $invoice['id']; ?>',
                         date:'<?php echo date('d.m.Y', strtotime($invoice['created_at'])); ?>',
                         submitter:'<?php echo htmlspecialchars($submitterName, ENT_QUOTES); ?>',
                         initials:'<?php echo htmlspecialchars($initials, ENT_QUOTES); ?>',
                         description:<?php echo json_encode($invoice['description']); ?>,
                         amount:'<?php echo number_format($invoice['amount'], 2, ',', '.'); ?>',
                         status:'<?php echo htmlspecialchars($invoice['status'], ENT_QUOTES); ?>',
                         displayStatus:'<?php echo htmlspecialchars($displayStatus, ENT_QUOTES); ?>',
                         statusLabel:'<?php echo htmlspecialchars($statusLabel, ENT_QUOTES); ?>',
                         filePath:'<?php echo $fileUrl; ?>',
                         paidAt:'<?php echo $paidAt; ?>',
                         paidBy:'<?php echo htmlspecialchars($paidByName, ENT_QUOTES); ?>',
                         rejectionReason:<?php echo json_encode($invoice['rejection_reason'] ?? ''); ?>
                     })">
                    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:0.75rem; margin-bottom:0.75rem;">
                        <div style="display:flex; align-items:center; gap:0.75rem;">
                            <div class="inv-mob-avatar"><?php echo htmlspecialchars($initials); ?></div>
                            <div>
                                <p style="font-weight:600; color:var(--text-main); font-size:0.875rem; margin:0;"><?php echo htmlspecialchars($submitterName); ?></p>
                                <p style="font-size:0.75rem; color:var(--text-muted); margin:0;"><?php echo date('d.m.Y', strtotime($invoice['created_at'])); ?></p>
                            </div>
                        </div>
                        <span class="inv-status-badge"
                              style="background:<?php echo $ss['bg']; ?>; color:<?php echo $ss['color']; ?>; border-color:<?php echo $ss['border']; ?>;">
                            <span class="inv-status-dot" style="background:<?php echo $ss['dot']; ?>;"></span>
                            <?php echo $statusLabel; ?>
                        </span>
                    </div>
                    <p style="font-size:0.875rem; color:var(--text-muted); margin:0 0 0.75rem; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;">
                        <?php echo htmlspecialchars($invoice['description']); ?>
                    </p>
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:0.75rem;">
                        <span style="font-size:1.25rem; font-weight:800; color:var(--text-main);">
                            <?php echo number_format($invoice['amount'], 2, ',', '.'); ?> €
                        </span>
                        <?php if (!empty($invoice['file_path'])): ?>
                        <div style="display:flex; gap:0.375rem;" onclick="event.stopPropagation()">
                            <a href="<?php echo $fileUrl; ?>" target="_blank" class="inv-file-btn inv-file-btn--view">
                                <i class="fas fa-eye"></i>Ansehen
                            </a>
                            <a href="<?php echo $fileUrl; ?>" download class="inv-file-btn inv-file-btn--dl">
                                <i class="fas fa-download"></i>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($canEditInvoices && $invoice['status'] === 'pending'): ?>
                    <div style="display:flex; gap:0.5rem; margin-top:0.875rem;" onclick="event.stopPropagation()">
                        <button onclick="updateInvoiceStatus(<?php echo $invoice['id']; ?>, 'approved')" class="inv-btn-approve" style="flex:1; justify-content:center;">
                            <i class="fas fa-check"></i>Genehmigen
                        </button>
                        <button onclick="updateInvoiceStatus(<?php echo $invoice['id']; ?>, 'rejected')" class="inv-btn-reject" style="flex:1; justify-content:center;">
                            <i class="fas fa-times"></i>Ablehnen
                        </button>
                    </div>
                    <?php elseif ($canEditInvoices && $invoice['status'] === 'approved' && $canMarkAsPaid): ?>
                    <div style="margin-top:0.875rem;" onclick="event.stopPropagation()">
                        <button onclick="markInvoiceAsPaid(<?php echo $invoice['id']; ?>)" class="inv-btn-paid" style="width:100%; justify-content:center;">
                            <i class="fas fa-check-double"></i>Als Bezahlt markieren
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Desktop Table View -->
            <div id="inv-desktop-view" style="overflow-x:auto;">
                <table class="inv-table">
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Einreicher</th>
                            <th>Zweck</th>
                            <th>Betrag</th>
                            <th>Beleg</th>
                            <th>Status</th>
                            <?php if ($canEditInvoices): ?><th>Aktionen</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($invoices as $invoice):
                            $submitterEmail = $userInfoMap[$invoice['user_id']] ?? 'Unknown';
                            $submitterName  = explode('@', $submitterEmail)[0];
                            $initials       = strtoupper(substr($submitterName, 0, 2));
                            $displayStatus  = $invoice['_display_status'];
                            $ss             = $statusStyles[$displayStatus] ?? $statusStyles['pending'];
                            $statusLabel    = $ss['label'];

                            $paidAt      = !empty($invoice['paid_at']) ? date('d.m.Y', strtotime($invoice['paid_at'])) : '';
                            $paidByName  = '';
                            if (!empty($invoice['paid_by_user_id']) && isset($userInfoMap[$invoice['paid_by_user_id']])) {
                                $paidByName = explode('@', $userInfoMap[$invoice['paid_by_user_id']])[0];
                            }
                            $fileUrl = !empty($invoice['file_path']) ? htmlspecialchars(asset('api/download_invoice_file.php?id=' . (int)$invoice['id']), ENT_QUOTES, 'UTF-8') : '';
                        ?>
                        <tr class="invoice-row" data-status="<?php echo htmlspecialchars($invoice['status']); ?>"
                            onclick="openInvoiceDetail({
                                id:'<?php echo $invoice['id']; ?>',
                                date:'<?php echo date('d.m.Y', strtotime($invoice['created_at'])); ?>',
                                submitter:'<?php echo htmlspecialchars($submitterName, ENT_QUOTES); ?>',
                                initials:'<?php echo htmlspecialchars($initials, ENT_QUOTES); ?>',
                                description:<?php echo json_encode($invoice['description']); ?>,
                                amount:'<?php echo number_format($invoice['amount'], 2, ',', '.'); ?>',
                                status:'<?php echo htmlspecialchars($invoice['status'], ENT_QUOTES); ?>',
                                displayStatus:'<?php echo htmlspecialchars($displayStatus, ENT_QUOTES); ?>',
                                statusLabel:'<?php echo htmlspecialchars($statusLabel, ENT_QUOTES); ?>',
                                filePath:'<?php echo $fileUrl; ?>',
                                paidAt:'<?php echo $paidAt; ?>',
                                paidBy:'<?php echo htmlspecialchars($paidByName, ENT_QUOTES); ?>',
                                rejectionReason:<?php echo json_encode($invoice['rejection_reason'] ?? ''); ?>
                            })">
                            <td style="white-space:nowrap;"><?php echo date('d.m.Y', strtotime($invoice['created_at'])); ?></td>
                            <td>
                                <div style="display:flex; align-items:center;">
                                    <div class="inv-table-avatar"><?php echo htmlspecialchars($initials); ?></div>
                                    <span style="font-size:0.875rem; color:var(--text-main);"><?php echo htmlspecialchars($submitterName); ?></span>
                                </div>
                            </td>
                            <td style="max-width:16rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                <?php echo htmlspecialchars($invoice['description']); ?>
                            </td>
                            <td style="white-space:nowrap; font-weight:800; font-size:1rem;">
                                <?php echo number_format($invoice['amount'], 2, ',', '.'); ?> €
                            </td>
                            <td onclick="event.stopPropagation()" style="white-space:nowrap;">
                                <?php if (!empty($invoice['file_path'])): ?>
                                <div style="display:flex; gap:0.375rem;">
                                    <a href="<?php echo $fileUrl; ?>" target="_blank" class="inv-file-btn inv-file-btn--view">
                                        <i class="fas fa-eye"></i>Ansehen
                                    </a>
                                    <a href="<?php echo $fileUrl; ?>" download class="inv-file-btn inv-file-btn--dl">
                                        <i class="fas fa-download"></i>
                                    </a>
                                </div>
                                <?php else: ?>
                                <span style="font-size:0.8125rem; color:var(--text-muted);">Kein Beleg</span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap;">
                                <span class="inv-status-badge"
                                      style="background:<?php echo $ss['bg']; ?>; color:<?php echo $ss['color']; ?>; border-color:<?php echo $ss['border']; ?>;">
                                    <span class="inv-status-dot" style="background:<?php echo $ss['dot']; ?>;"></span>
                                    <?php echo $statusLabel; ?>
                                </span>
                            </td>
                            <?php if ($canEditInvoices): ?>
                            <td onclick="event.stopPropagation()" style="white-space:nowrap;">
                                <?php if ($invoice['status'] === 'pending'): ?>
                                <div style="display:flex; gap:0.375rem;">
                                    <button onclick="updateInvoiceStatus(<?php echo $invoice['id']; ?>, 'approved')" class="inv-btn-approve">
                                        <i class="fas fa-check"></i>Genehmigen
                                    </button>
                                    <button onclick="updateInvoiceStatus(<?php echo $invoice['id']; ?>, 'rejected')" class="inv-btn-reject">
                                        <i class="fas fa-times"></i>Ablehnen
                                    </button>
                                </div>
                                <?php elseif ($invoice['status'] === 'approved' && $canMarkAsPaid): ?>
                                <button onclick="markInvoiceAsPaid(<?php echo $invoice['id']; ?>)" class="inv-btn-paid">
                                    <i class="fas fa-check-circle"></i>Als Bezahlt
                                </button>
                                <?php else: ?>
                                <span style="font-size:0.75rem; color:var(--text-muted);">–</span>
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

<!-- Responsive: toggle mobile/desktop view -->
<style>
@media (max-width: 767px) { #inv-desktop-view { display: none; } }
@media (min-width: 768px) { #inv-mobile-view  { display: none; } }
</style>

<?php if ($canViewTable): ?>
<!-- ── Invoice Detail Modal ── -->
<div id="invoiceDetailModal" class="inv-modal-overlay" role="dialog" aria-modal="true">
    <div class="inv-modal-box" style="width:min(44rem,100%);">
        <div class="inv-modal-header">
            <h2 class="inv-modal-title">
                <i class="fas fa-file-invoice" style="color:#2563eb; margin-right:0.5rem;"></i>
                Rechnungsdetails <span id="detail-id" style="color:var(--text-muted); font-size:0.9rem; font-weight:400;"></span>
            </h2>
            <button id="closeDetailModal" class="inv-modal-close"><i class="fas fa-times"></i></button>
        </div>
        <div class="inv-modal-body">
            <!-- Submitter + date -->
            <div style="display:flex; align-items:center; gap:1rem; margin-bottom:1rem;">
                <div id="detail-avatar" class="inv-mob-avatar" style="width:3rem; height:3rem; font-size:1rem; flex-shrink:0;"></div>
                <div style="flex:1; min-width:0;">
                    <p style="font-weight:700; color:var(--text-main); margin:0;" id="detail-submitter"></p>
                    <p style="font-size:0.8125rem; color:var(--text-muted); margin:0;">
                        <i class="fas fa-calendar-alt" style="margin-right:0.25rem;"></i><span id="detail-date"></span>
                    </p>
                </div>
                <span id="detail-status-badge" class="inv-status-badge"></span>
            </div>

            <!-- Description -->
            <div class="inv-detail-section">
                <p class="inv-detail-section-label">Zweck</p>
                <p class="inv-detail-section-val" id="detail-description"></p>
            </div>

            <!-- Amount -->
            <div class="inv-detail-section inv-detail-section--blue">
                <p class="inv-detail-section-label">Betrag</p>
                <p style="font-size:1.5rem; font-weight:800; color:#2563eb; margin:0;"><span id="detail-amount"></span> €</p>
            </div>

            <!-- Paid info -->
            <div id="detail-paid-row" class="inv-detail-section inv-detail-section--green" style="display:none;">
                <p class="inv-detail-section-label">Bezahlt am</p>
                <p class="inv-detail-section-val" id="detail-paid-info"></p>
            </div>

            <!-- Rejection reason -->
            <div id="detail-rejection-row" class="inv-detail-section inv-detail-section--red" style="display:none;">
                <p class="inv-detail-section-label">Ablehnungsgrund</p>
                <p class="inv-detail-section-val" id="detail-rejection"></p>
            </div>

            <!-- Document -->
            <div>
                <p class="inv-detail-section-label" style="margin-bottom:0.5rem;">Beleg</p>
                <div id="detail-document" style="display:none;">
                    <div id="detail-doc-preview"></div>
                    <a id="detail-doc-link" href="#" target="_blank" class="inv-file-btn inv-file-btn--view" style="margin-top:0.625rem; display:inline-flex;">
                        <i class="fas fa-external-link-alt"></i>In neuem Tab öffnen
                    </a>
                </div>
                <p id="detail-no-document" style="display:none; font-size:0.875rem; color:var(--text-muted);">
                    <i class="fas fa-ban" style="margin-right:0.25rem;"></i>Kein Beleg hochgeladen
                </p>
            </div>
        </div>
        <div class="inv-modal-footer">
            <?php if ($canEditInvoices): ?>
            <div id="detail-actions-pending" style="display:none; gap:0.75rem; margin-bottom:0.75rem; flex-wrap:wrap;">
                <button onclick="updateInvoiceStatusFromDetail('approved')" class="inv-btn-approve" style="flex:1; justify-content:center; padding:.75rem 1rem;">
                    <i class="fas fa-check"></i>Genehmigen
                </button>
                <button onclick="openRejectModal()" class="inv-btn-reject" style="flex:1; justify-content:center; padding:.75rem 1rem;">
                    <i class="fas fa-times"></i>Ablehnen
                </button>
            </div>
            <?php if ($canMarkAsPaid): ?>
            <div id="detail-actions-approved" style="display:none; margin-bottom:0.75rem;">
                <button onclick="markInvoiceAsPaidFromDetail()" class="inv-btn-paid" style="width:100%; justify-content:center; padding:.75rem 1rem;">
                    <i class="fas fa-check-circle"></i>Als Bezahlt markieren
                </button>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <button id="closeDetailModalBtn" style="width:100%; padding:.625rem; border-radius:.75rem; border:none; background:rgba(100,116,139,0.12); color:var(--text-muted); font-weight:600; cursor:pointer; transition:background .2s;"
                    onmouseover="this.style.background='rgba(100,116,139,0.22)'" onmouseout="this.style.background='rgba(100,116,139,0.12)'">
                Schließen
            </button>
        </div>
    </div>
</div>

<!-- ── Rejection Modal ── -->
<div id="rejectModal" class="inv-modal-overlay inv-modal-overlay--z60" role="dialog" aria-modal="true">
    <div class="inv-modal-box" style="width:min(32rem,100%);">
        <div class="inv-modal-header">
            <h3 class="inv-modal-title">
                <i class="fas fa-times-circle" style="color:#dc2626; margin-right:0.5rem;"></i>Rechnung ablehnen
            </h3>
            <button onclick="closeRejectModal()" class="inv-modal-close"><i class="fas fa-times"></i></button>
        </div>
        <div class="inv-modal-body">
            <label class="inv-form-label">Ablehnungsgrund <span style="color:var(--text-muted); font-weight:400; text-transform:none;">(optional)</span></label>
            <textarea id="rejectReasonInput" rows="3" class="inv-form-input" style="resize:none;"
                      placeholder="Grund für die Ablehnung..."></textarea>
        </div>
        <div class="inv-modal-footer" style="display:flex; gap:0.75rem; flex-wrap:wrap;">
            <button onclick="confirmReject()" class="inv-btn-reject" style="flex:1; justify-content:center; padding:.75rem 1rem;">
                <i class="fas fa-times"></i>Ablehnen
            </button>
            <button onclick="closeRejectModal()" style="flex:1; padding:.75rem; border-radius:.75rem; border:none; background:rgba(100,116,139,0.12); color:var(--text-muted); font-weight:600; cursor:pointer;">
                Abbrechen
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($canSubmitInvoice): ?>
<!-- ── Submission Modal (redesigned im Ideenbox-Stil) ── -->
<div id="submissionModal" class="inv-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="submissionModalTitle">
    <div class="inv-modal-box" style="width:min(36rem,100%);">
        <div class="inv-modal-header" style="padding:1rem 1.25rem; gap:.75rem; align-items:flex-start;">
            <div style="display:flex; align-items:center; gap:.75rem; flex:1; min-width:0;">
                <div style="width:2.5rem; height:2.5rem; border-radius:.75rem; background:linear-gradient(135deg, rgba(37,99,235,0.18), rgba(37,99,235,0.08)); display:flex; align-items:center; justify-content:center; flex-shrink:0; box-shadow:0 2px 8px -2px rgba(37,99,235,0.25);">
                    <i class="fas fa-file-invoice-dollar" style="color:#2563eb; font-size:1rem;"></i>
                </div>
                <div style="min-width:0;">
                    <h2 id="submissionModalTitle" class="inv-modal-title" style="margin:0;">Beleg einreichen</h2>
                    <p style="margin:.15rem 0 0; font-size:.75rem; color:var(--text-muted);">Pflichtfelder sind mit <span style="color:#dc2626;">*</span> markiert</p>
                </div>
            </div>
            <button id="closeSubmissionModal" class="inv-modal-close" aria-label="Modal schließen"><i class="fas fa-times"></i></button>
        </div>

        <form id="submissionForm" action="<?php echo asset('api/submit_invoice.php'); ?>" method="POST" enctype="multipart/form-data" style="display:flex; flex-direction:column; flex:1; min-height:0;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken(), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="inv-modal-body" style="display:flex; flex-direction:column; gap:1rem;">

                <!-- Amount + Date -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                    <div>
                        <label for="amount" class="inv-form-label">Betrag (€) <span style="color:#dc2626;">*</span></label>
                        <input type="number" id="amount" name="amount" step="0.01" min="0" required
                               placeholder="0,00" class="inv-form-input">
                    </div>
                    <div>
                        <label for="date" class="inv-form-label">Belegdatum <span style="color:#dc2626;">*</span></label>
                        <input type="date" id="date" name="date" required
                               max="<?php echo date('Y-m-d'); ?>" class="inv-form-input">
                    </div>
                </div>

                <!-- Description -->
                <div>
                    <label for="description" class="inv-form-label">Zweck <span style="color:#dc2626;">*</span></label>
                    <textarea id="description" name="description" rows="3" required
                              placeholder="Wofür wurde der Betrag ausgegeben?"
                              class="inv-form-input" style="resize:none;"></textarea>
                </div>

                <!-- Bankverbindung (optional) -->
                <details class="inv-bank-group" style="border:1px solid var(--border-color); border-radius:.75rem; background:rgba(37,99,235,0.03);">
                    <summary style="list-style:none; cursor:pointer; padding:.75rem 1rem; display:flex; align-items:center; gap:.6rem; font-weight:600; color:var(--text-main); font-size:.875rem; user-select:none;">
                        <i class="fas fa-university" style="color:#2563eb;"></i>
                        Bankverbindung für Rückerstattung
                        <span style="margin-left:auto; font-weight:500; font-size:.7rem; color:var(--text-muted); text-transform:none; letter-spacing:0;">optional</span>
                        <i class="fas fa-chevron-down inv-bank-chevron" style="color:var(--text-muted); font-size:.7rem; transition:transform .2s;"></i>
                    </summary>
                    <div style="padding:.25rem 1rem 1rem; display:flex; flex-direction:column; gap:.75rem;">
                        <div>
                            <label for="account_holder" class="inv-form-label">Kontoinhaber</label>
                            <input type="text" id="account_holder" name="account_holder"
                                   maxlength="120"
                                   placeholder="Vor- und Nachname"
                                   autocomplete="name"
                                   class="inv-form-input">
                        </div>
                        <div style="display:grid; grid-template-columns:2fr 1fr; gap:.75rem;">
                            <div>
                                <label for="iban" class="inv-form-label">IBAN</label>
                                <input type="text" id="iban" name="iban"
                                       maxlength="42"
                                       pattern="[A-Za-z0-9 ]{15,42}"
                                       placeholder="DE00 0000 0000 0000 0000 00"
                                       autocomplete="off"
                                       class="inv-form-input" style="font-family:ui-monospace, SFMono-Regular, Menlo, monospace;">
                            </div>
                            <div>
                                <label for="bic" class="inv-form-label">BIC</label>
                                <input type="text" id="bic" name="bic"
                                       maxlength="11"
                                       pattern="[A-Za-z0-9]{8,11}"
                                       placeholder="XXXXDEXX"
                                       autocomplete="off"
                                       class="inv-form-input" style="font-family:ui-monospace, SFMono-Regular, Menlo, monospace; text-transform:uppercase;">
                            </div>
                        </div>
                        <p style="font-size:.7rem; color:var(--text-muted); margin:0; line-height:1.4;">
                            <i class="fas fa-shield-alt" style="margin-right:.25rem; color:#16a34a;"></i>
                            Nur nötig, wenn Du noch keine Bankverbindung hinterlegt hast. Daten werden verschlüsselt gespeichert.
                        </p>
                    </div>
                </details>

                <!-- File Upload -->
                <div>
                    <label class="inv-form-label">Beleg <span style="color:#dc2626;">*</span></label>
                    <div id="dropZone" class="inv-drop-zone">
                        <input type="file" id="file" name="file" accept=".pdf,.jpg,.jpeg,.png" required style="display:none;">
                        <div id="dropZoneContent">
                            <i class="fas fa-cloud-upload-alt" style="font-size:2rem; color:var(--text-muted); margin-bottom:0.5rem; display:block;"></i>
                            <p style="font-size:0.875rem; color:var(--text-muted); margin:0 0 0.25rem;">
                                <span style="color:#2563eb; font-weight:600;">Klicken</span> oder Datei hierher ziehen
                            </p>
                            <p style="font-size:0.75rem; color:var(--text-muted); margin:0;">PDF, JPG, PNG · max. 10 MB</p>
                        </div>
                        <div id="fileInfo" style="display:none;">
                            <i class="fas fa-file-check" style="font-size:2rem; color:#16a34a; margin-bottom:0.5rem; display:block;"></i>
                            <p id="fileName" style="font-size:0.875rem; font-weight:600; color:var(--text-main); margin:0 0 0.25rem;"></p>
                            <button type="button" id="removeFile" style="font-size:0.75rem; color:#dc2626; background:none; border:none; cursor:pointer;">
                                <i class="fas fa-times" style="margin-right:0.2rem;"></i>Entfernen
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="inv-modal-footer" style="display:flex; gap:0.75rem; flex-wrap:wrap;">
                <button type="submit" class="inv-submit-btn" style="flex:1; justify-content:center; padding:.75rem 1rem;">
                    <i class="fas fa-paper-plane"></i>Einreichen
                </button>
                <button type="button" id="cancelSubmission"
                        style="flex:1; padding:.75rem; border-radius:.75rem; border:none; background:rgba(100,116,139,0.12); color:var(--text-muted); font-weight:600; cursor:pointer; font-size:.875rem; transition:background .2s;"
                        onmouseover="this.style.background='rgba(100,116,139,0.22)'" onmouseout="this.style.background='rgba(100,116,139,0.12)'">
                    Abbrechen
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
const csrfToken = <?php echo json_encode(CSRFHandler::getToken()); ?>;

// ── Modal helpers ────────────────────────────────────────────────────────────
// Hängt das Modal beim Öffnen direkt an document.body, damit `position: fixed`
// gegen den Viewport positioniert wird (kein Containing-Block-Trap durch
// `transform`/`filter` auf einem Vorfahren wie #main-content).
function openModal(id) {
    var el = document.getElementById(id);
    if (!el) return;
    if (el.parentElement !== document.body) {
        el.dataset.invOriginalParent = '1'; // markieren, damit close ihn wieder zurücklegen kann
        document.body.appendChild(el);
    }
    el.classList.add('open');
    document.body.style.overflow = 'hidden';
    document.body.classList.add('rech-modal-open');
}
function closeModal(id) {
    var el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('open');
    document.body.style.overflow = '';
    // Falls noch andere offene Modals existieren, Body-Lock NICHT entfernen
    if (!document.querySelector('.inv-modal-overlay.open')) {
        document.body.classList.remove('rech-modal-open');
    }
}

// Close on overlay click
document.querySelectorAll('.inv-modal-overlay').forEach(function (overlay) {
    overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeModal(overlay.id);
    });
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.inv-modal-overlay.open').forEach(function (el) {
            closeModal(el.id);
        });
    }
});

<?php if ($canViewTable): ?>
// ── Invoice Detail Modal ─────────────────────────────────────────────────────
let currentDetailInvoiceId = null;

const statusStylesJS = {
    pending:  { bg:'rgba(245,158,11,0.1)',  color:'#b45309', border:'rgba(245,158,11,0.3)',  dot:'#f59e0b', label:'In Prüfung' },
    approved: { bg:'rgba(234,179,8,0.1)',   color:'#a16207', border:'rgba(234,179,8,0.3)',   dot:'#eab308', label:'Offen' },
    rejected: { bg:'rgba(220,38,38,0.1)',   color:'#b91c1c', border:'rgba(220,38,38,0.3)',   dot:'#dc2626', label:'Abgelehnt' },
    paid:     { bg:'rgba(22,163,74,0.1)',   color:'#15803d', border:'rgba(22,163,74,0.3)',   dot:'#16a34a', label:'Bezahlt' },
    overdue:  { bg:'rgba(220,38,38,0.1)',   color:'#b91c1c', border:'rgba(220,38,38,0.3)',   dot:'#dc2626', label:'Überfällig' },
};

function openInvoiceDetail(data) {
    currentDetailInvoiceId = data.id;

    document.getElementById('detail-id').textContent          = '#' + data.id;
    document.getElementById('detail-avatar').textContent      = data.initials;
    document.getElementById('detail-submitter').textContent   = data.submitter;
    document.getElementById('detail-date').textContent        = data.date;
    document.getElementById('detail-description').textContent = data.description;
    document.getElementById('detail-amount').textContent      = data.amount;

    // Status badge via inline styles (no dark: Tailwind)
    var ds  = data.displayStatus || data.status;
    var ss  = statusStylesJS[ds] || statusStylesJS['pending'];
    var badge = document.getElementById('detail-status-badge');
    badge.style.background   = ss.bg;
    badge.style.color        = ss.color;
    badge.style.borderColor  = ss.border;
    badge.innerHTML = '<span class="inv-status-dot" style="background:' + ss.dot + ';"></span>' + data.statusLabel;

    // Paid info
    var paidRow  = document.getElementById('detail-paid-row');
    var paidInfo = document.getElementById('detail-paid-info');
    if (data.paidAt) {
        paidInfo.textContent = data.paidAt + (data.paidBy ? ' · von ' + data.paidBy : '');
        paidRow.style.display = 'block';
    } else {
        paidRow.style.display = 'none';
    }

    // Rejection reason
    var rejRow = document.getElementById('detail-rejection-row');
    if (data.rejectionReason) {
        document.getElementById('detail-rejection').textContent = data.rejectionReason;
        rejRow.style.display = 'block';
    } else {
        rejRow.style.display = 'none';
    }

    // Document
    var docContainer = document.getElementById('detail-document');
    var docPreview   = document.getElementById('detail-doc-preview');
    var docLink      = document.getElementById('detail-doc-link');
    var noDoc        = document.getElementById('detail-no-document');
    docPreview.innerHTML = '';
    if (data.filePath) {
        var ext = data.filePath.split('.').pop().toLowerCase().split('?')[0];
        if (['jpg','jpeg','png','heic'].includes(ext)) {
            var img = document.createElement('img');
            img.src = data.filePath; img.alt = 'Beleg';
            img.style.cssText = 'max-width:100%; border-radius:.625rem; border:1px solid var(--border-color);';
            docPreview.appendChild(img);
        } else if (ext === 'pdf') {
            var iframe = document.createElement('iframe');
            iframe.src = data.filePath; iframe.frameBorder = '0';
            iframe.style.cssText = 'width:100%; height:20rem; border-radius:.625rem; border:1px solid var(--border-color);';
            docPreview.appendChild(iframe);
        }
        docLink.href = data.filePath;
        docContainer.style.display = 'block';
        noDoc.style.display        = 'none';
    } else {
        docContainer.style.display = 'none';
        noDoc.style.display        = 'block';
    }

    // Board action buttons
    var pendingEl  = document.getElementById('detail-actions-pending');
    var approvedEl = document.getElementById('detail-actions-approved');
    if (pendingEl)  pendingEl.style.display  = (data.status === 'pending')  ? 'flex' : 'none';
    if (approvedEl) approvedEl.style.display = (data.status === 'approved') ? 'block' : 'none';

    openModal('invoiceDetailModal');
}

document.getElementById('closeDetailModal').addEventListener('click', function () { closeModal('invoiceDetailModal'); });
document.getElementById('closeDetailModalBtn').addEventListener('click', function () { closeModal('invoiceDetailModal'); });

function updateInvoiceStatusFromDetail(status) {
    if (status === 'rejected') { openRejectModal(); return; }
    updateInvoiceStatus(currentDetailInvoiceId, status);
}
function markInvoiceAsPaidFromDetail() {
    markInvoiceAsPaid(currentDetailInvoiceId);
}

// ── Rejection modal ──────────────────────────────────────────────────────────
function openRejectModal() {
    document.getElementById('rejectReasonInput').value = '';
    openModal('rejectModal');
}
function closeRejectModal() { closeModal('rejectModal'); }
function confirmReject() {
    var reason    = document.getElementById('rejectReasonInput').value.trim();
    var invoiceId = currentDetailInvoiceId !== null ? currentDetailInvoiceId : pendingRejectInvoiceId;
    closeRejectModal();
    _doUpdateStatus(invoiceId, 'rejected', reason);
}
<?php endif; ?>

<?php if ($canSubmitInvoice): ?>
// ── Submission modal ─────────────────────────────────────────────────────────
document.getElementById('openSubmissionModal').addEventListener('click', function () { openModal('submissionModal'); });
document.getElementById('closeSubmissionModal').addEventListener('click', function () { closeModal('submissionModal'); });
document.getElementById('cancelSubmission').addEventListener('click', function () { closeModal('submissionModal'); });

// Drop zone / file upload
var dropZone        = document.getElementById('dropZone');
var fileInput       = document.getElementById('file');
var dropZoneContent = document.getElementById('dropZoneContent');
var fileInfoEl      = document.getElementById('fileInfo');
var fileNameEl      = document.getElementById('fileName');

dropZone.addEventListener('click', function () { fileInput.click(); });
dropZone.addEventListener('dragover', function (e) { e.preventDefault(); dropZone.classList.add('inv-drop-zone--active'); });
dropZone.addEventListener('dragleave', function () { dropZone.classList.remove('inv-drop-zone--active'); });
dropZone.addEventListener('drop', function (e) {
    e.preventDefault(); dropZone.classList.remove('inv-drop-zone--active');
    if (e.dataTransfer.files.length > 0) { fileInput.files = e.dataTransfer.files; updateFileInfo(); }
});
fileInput.addEventListener('change', updateFileInfo);
document.getElementById('removeFile').addEventListener('click', function (e) {
    e.stopPropagation(); fileInput.value = '';
    dropZoneContent.style.display = 'block'; fileInfoEl.style.display = 'none';
});
function updateFileInfo() {
    if (fileInput.files.length > 0) {
        fileNameEl.textContent = fileInput.files[0].name;
        dropZoneContent.style.display = 'none'; fileInfoEl.style.display = 'block';
    }
}
<?php endif; ?>

// ── Filter tabs ──────────────────────────────────────────────────────────────
function filterByStatus(status) {
    document.querySelectorAll('.inv-tab').forEach(function (btn) {
        btn.classList.remove('inv-tab--active');
    });
    var activeTab = document.getElementById('tab-' + status);
    if (activeTab) activeTab.classList.add('inv-tab--active');

    document.querySelectorAll('.invoice-row').forEach(function (row) {
        row.style.display = (status === 'all' || row.dataset.status === status) ? '' : 'none';
    });
}
filterByStatus('all');

// ── Status update helpers ────────────────────────────────────────────────────
var pendingRejectInvoiceId = null;

function updateInvoiceStatus(invoiceId, status) {
    if (status === 'rejected') { pendingRejectInvoiceId = invoiceId; openRejectModal(); return; }
    _doUpdateStatus(invoiceId, status, null);
}

function _doUpdateStatus(invoiceId, status, reason) {
    var fd = new FormData();
    fd.append('invoice_id', invoiceId); fd.append('status', status);
    if (reason) fd.append('reason', reason);
    fd.append('csrf_token', csrfToken);
    fetch('<?php echo asset('api/update_invoice_status.php'); ?>', { method:'POST', body:fd })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.success) window.location.reload(); else alert('Fehler: ' + (d.error || 'Unbekannter Fehler')); })
        .catch(function () { alert('Fehler beim Aktualisieren des Status'); });
}

function markInvoiceAsPaid(invoiceId) {
    if (!confirm('Möchtest du diese Rechnung wirklich als bezahlt markieren?')) return;
    var fd = new FormData();
    fd.append('invoice_id', invoiceId); fd.append('csrf_token', csrfToken);
    fetch('<?php echo asset('api/mark_invoice_paid.php'); ?>', { method:'POST', body:fd })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.success) window.location.reload(); else alert('Fehler: ' + (d.error || 'Unbekannter Fehler')); })
        .catch(function () { alert('Fehler beim Markieren als bezahlt'); });
}
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
?>
