<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/AuthHandler.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Inventory.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

// Get sync results from session and clear them
$syncResult = $_SESSION['sync_result'] ?? null;
unset($_SESSION['sync_result']);

// Get search / filter parameters
$search = trim($_GET['search'] ?? '');

// Load inventory objects via Inventory model (includes SUM-based rental quantities)
$inventoryObjects = [];
$loadError = null;
try {
    $filters = [];
    if ($search !== '') {
        $filters['search'] = $search;
    }
    $inventoryObjects = Inventory::getAll($filters);
} catch (Exception $e) {
    $loadError = $e->getMessage();
    error_log('Inventory index: fetch failed: ' . $e->getMessage());
}

// Flash messages from checkout redirects
$checkoutSuccess = $_SESSION['checkout_success'] ?? null;
$checkoutError   = $_SESSION['checkout_error']   ?? null;
unset($_SESSION['checkout_success'], $_SESSION['checkout_error']);

$title = 'Inventar - IBC Intranet';
ob_start();
?>

<style>
/* ── Inventar Module ── */
.inv-header-icon {
    width: 3rem; height: 3rem;
    border-radius: 0.875rem;
    background: linear-gradient(135deg, #7c3aed, #2563eb);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 14px rgba(124,58,237,0.35);
    flex-shrink: 0;
}

/* Search card */
.inv-search-card {
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 1rem;
    padding: 1.25rem 1.5rem;
    margin-bottom: 2rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}
.inv-search-input {
    width: 100%;
    padding: 0.625rem 1rem;
    border: 1.5px solid var(--border-color);
    border-radius: 0.625rem;
    background-color: var(--bg-body);
    color: var(--text-main);
    font-size: 0.9375rem;
    transition: border-color .2s, box-shadow .2s;
    outline: none;
}
.inv-search-input:focus {
    border-color: #7c3aed;
    box-shadow: 0 0 0 3px rgba(124,58,237,0.15);
}
.inv-search-input::placeholder { color: var(--text-muted); }
.inv-search-btn {
    padding: 0.625rem 1.25rem;
    background: linear-gradient(135deg, #7c3aed, #2563eb);
    color: #fff;
    border: none;
    border-radius: 0.625rem;
    font-weight: 700;
    font-size: 0.875rem;
    cursor: pointer;
    transition: opacity .2s, transform .15s;
    white-space: nowrap;
}
.inv-search-btn:hover { opacity: .9; transform: scale(1.03); }
.inv-clear-btn {
    padding: 0.625rem 0.875rem;
    background-color: rgba(100,116,139,0.12);
    color: var(--text-muted);
    border: none;
    border-radius: 0.625rem;
    cursor: pointer;
    transition: background .2s;
}
.inv-clear-btn:hover { background-color: rgba(100,116,139,0.22); }

/* ── Item Cards ── */
.inv-card {
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 1.125rem;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 2px 10px rgba(0,0,0,0.07);
    transition: box-shadow .25s, transform .2s;
    animation: invCardIn .45s ease both;
}
.inv-card:hover {
    box-shadow: 0 8px 28px rgba(124,58,237,0.14);
    transform: translateY(-3px);
}
@keyframes invCardIn {
    from { opacity: 0; transform: translateY(18px); }
    to   { opacity: 1; transform: translateY(0); }
}
.inv-card:nth-child(1)  { animation-delay: .05s }
.inv-card:nth-child(2)  { animation-delay: .10s }
.inv-card:nth-child(3)  { animation-delay: .15s }
.inv-card:nth-child(4)  { animation-delay: .20s }
.inv-card:nth-child(5)  { animation-delay: .25s }
.inv-card:nth-child(6)  { animation-delay: .30s }
.inv-card:nth-child(n+7) { animation-delay: .35s }

/* Image zone */
.inv-img-wrap {
    height: 11rem;
    background: linear-gradient(135deg, rgba(124,58,237,0.08), rgba(37,99,235,0.08));
    display: flex; align-items: center; justify-content: center;
    overflow: hidden; position: relative;
}
.inv-img-wrap img {
    width: 100%; height: 100%; object-fit: contain;
    transition: transform .5s ease;
}
.inv-card:hover .inv-img-wrap img { transform: scale(1.06); }
.inv-img-placeholder { color: rgba(124,58,237,0.25); font-size: 3.5rem; }

/* Availability badge */
.inv-avail-badge {
    position: absolute; top: 0.75rem; right: 0.75rem;
    display: inline-flex; align-items: center; gap: 0.375rem;
    padding: 0.3125rem 0.75rem;
    border-radius: 999px;
    font-size: 0.72rem; font-weight: 700;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
}
.inv-avail-badge--ok   { background: #16a34a; color: #fff; }
.inv-avail-badge--none { background: #dc2626; color: #fff; }

/* Card body */
.inv-card-body { padding: 1.125rem; display: flex; flex-direction: column; flex: 1; }
.inv-card-title {
    font-size: 1rem; font-weight: 700;
    color: var(--text-main);
    line-clamp: 2;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    margin-bottom: 0.375rem;
    transition: color .2s;
}
.inv-card:hover .inv-card-title { color: #7c3aed; }
.inv-card-desc {
    font-size: 0.8125rem;
    color: var(--text-muted);
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    flex: 1; margin-bottom: 1rem;
}

/* Stock row */
.inv-stock-row {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 0.5rem; margin-bottom: 1rem;
}
.inv-stock-cell {
    text-align: center; padding: 0.5rem 0.25rem;
    border-radius: 0.625rem;
    background-color: rgba(100,116,139,0.07);
}
.inv-stock-cell--loan  { background-color: rgba(249,115,22,0.1); }
.inv-stock-cell--ok    { background-color: rgba(22,163,74,0.1); }
.inv-stock-cell--empty { background-color: rgba(220,38,38,0.1); }
.inv-stock-label { font-size: 0.6875rem; color: var(--text-muted); margin-bottom: 0.2rem; display: block; }
.inv-stock-val   { font-size: 0.875rem; font-weight: 700; color: var(--text-main); }
.inv-stock-cell--loan  .inv-stock-label { color: rgba(249,115,22,0.9); }
.inv-stock-cell--ok    .inv-stock-label { color: rgba(22,163,74,0.9); }
.inv-stock-cell--empty .inv-stock-label { color: rgba(220,38,38,0.9); }
.inv-stock-cell--loan  .inv-stock-val   { color: #ea580c; }
.inv-stock-cell--ok    .inv-stock-val   { color: #16a34a; }
.inv-stock-cell--empty .inv-stock-val   { color: #dc2626; }

/* Action buttons */
.inv-action-row { display: flex; gap: 0.5rem; }
.inv-view-btn {
    display: flex; align-items: center; justify-content: center;
    width: 2.5rem; height: 2.5rem; border-radius: 0.625rem;
    background-color: rgba(100,116,139,0.1);
    color: var(--text-muted);
    text-decoration: none;
    transition: background .2s, color .2s;
    flex-shrink: 0;
}
.inv-view-btn:hover { background-color: rgba(124,58,237,0.15); color: #7c3aed; }
.inv-lend-btn {
    flex: 1; display: flex; align-items: center; justify-content: center; gap: 0.5rem;
    padding: 0.625rem 1rem; border: none; border-radius: 0.625rem;
    background: linear-gradient(135deg, #7c3aed, #2563eb);
    color: #fff; font-weight: 700; font-size: 0.8125rem; cursor: pointer;
    transition: opacity .2s, transform .15s, box-shadow .2s;
    box-shadow: 0 3px 10px rgba(124,58,237,0.3);
}
.inv-lend-btn:hover { opacity: .9; transform: scale(1.02); box-shadow: 0 5px 18px rgba(124,58,237,0.4); }
.inv-disabled-btn {
    flex: 1; display: flex; align-items: center; justify-content: center; gap: 0.5rem;
    padding: 0.625rem 1rem; border: none; border-radius: 0.625rem;
    background-color: rgba(100,116,139,0.12); color: var(--text-muted);
    font-weight: 700; font-size: 0.8125rem; cursor: not-allowed;
}

/* My-rentals action button */
.inv-rentals-btn {
    display: inline-flex; align-items: center; gap: 0.5rem;
    padding: 0.75rem 1.25rem; border-radius: 0.75rem;
    background-color: var(--bg-card);
    border: 1.5px solid rgba(124,58,237,0.35);
    color: #7c3aed; font-weight: 600; font-size: 0.9375rem;
    text-decoration: none;
    transition: border-color .2s, box-shadow .2s;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}
.inv-rentals-btn:hover { border-color: #7c3aed; box-shadow: 0 4px 14px rgba(124,58,237,0.2); color: #7c3aed; }
.inv-sync-btn {
    display: inline-flex; align-items: center; gap: 0.5rem;
    padding: 0.75rem 1.25rem; border-radius: 0.75rem;
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff; font-weight: 600; font-size: 0.9375rem;
    text-decoration: none;
    transition: opacity .2s, transform .15s;
    box-shadow: 0 3px 12px rgba(37,99,235,0.35);
}
.inv-sync-btn:hover { opacity: .9; transform: scale(1.03); color: #fff; }

/* ── Lending Modal ── */
/* ═══ Ausleihen / Entnehmen – Modal (Premium Redesign) ══════
   Accent colour: purple/indigo  prefix: inv-modal-
══════════════════════════════════════════════════════════ */
.inv-modal-overlay {
    position: fixed; inset: 0; z-index: 1080;
    background: rgba(15,23,42,.55);
    backdrop-filter: blur(6px);
    -webkit-backdrop-filter: blur(6px);
    display: flex; align-items: center; justify-content: center;
    padding: 1.25rem;
    opacity: 0; pointer-events: none;
    transition: opacity .22s ease;
}
.inv-modal-overlay.open { opacity: 1; pointer-events: auto; }

.inv-modal-dialog {
    background: #fff;
    border-radius: 1.375rem;
    width: min(30rem, calc(100vw - 2rem));
    max-height: 92dvh;
    overflow: hidden;
    display: flex; flex-direction: column;
    box-shadow:
        0 0 0 1px rgba(0,0,0,.07),
        0 4px 6px rgba(0,0,0,.04),
        0 16px 48px rgba(0,0,0,.16);
    transform: translateY(20px) scale(.97);
    transition: transform .32s cubic-bezier(.22,.68,0,1.2), opacity .22s;
    opacity: 0;
}
.inv-modal-overlay.open .inv-modal-dialog {
    transform: translateY(0) scale(1);
    opacity: 1;
}
/* Top accent stripe */
.inv-modal-dialog::before {
    content: ''; display: block; height: 4px; flex-shrink: 0;
    background: linear-gradient(90deg, #7c3aed, #2563eb);
    border-radius: 1.375rem 1.375rem 0 0;
}

/* Header */
.inv-modal-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 1.125rem 1.5rem 1rem;
    border-bottom: 1px solid #f1f5f9;
    gap: .75rem; flex-shrink: 0;
}
.inv-modal-head-left { display: flex; align-items: center; gap: .75rem; min-width: 0; }
.inv-modal-head-icon {
    width: 2.375rem; height: 2.375rem; border-radius: .75rem; flex-shrink: 0;
    background: linear-gradient(135deg, #7c3aed, #2563eb);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 12px rgba(124,58,237,.35);
}
.inv-modal-head-title {
    font-size: 1.0625rem; font-weight: 800;
    color: #0f172a !important; margin: 0; line-height: 1.25;
    letter-spacing: -.01em;
}
.inv-modal-head-sub {
    font-size: .8rem; color: #64748b !important;
    margin: .125rem 0 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.inv-modal-close {
    width: 2.25rem; height: 2.25rem; border-radius: .625rem;
    background: transparent; border: 1.5px solid #e2e8f0;
    color: #94a3b8; cursor: pointer; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s, color .15s, border-color .15s;
    font-size: .9rem;
}
.inv-modal-close:hover { background: #fef2f2; border-color: #fca5a5; color: #ef4444; }

/* Body */
.inv-modal-body {
    padding: 1.25rem 1.5rem;
    overflow-y: auto; flex: 1;
    display: flex; flex-direction: column; gap: 1rem;
    scrollbar-width: thin;
}

/* Form label */
.inv-form-label {
    display: block; font-size: .75rem; font-weight: 700;
    color: #475569 !important;
    text-transform: uppercase; letter-spacing: .055em;
    margin-bottom: .3rem; line-height: 1.3;
}
/* Form input */
.inv-form-input {
    width: 100%; padding: .6875rem .9375rem;
    border: 1.5px solid #e2e8f0; border-radius: .625rem;
    background: #fff; color: #0f172a;
    font-size: .9rem; outline: none; box-sizing: border-box;
    transition: border-color .18s, box-shadow .18s;
    -webkit-appearance: none; line-height: 1.45;
}
.inv-form-input::placeholder { color: #94a3b8; }
.inv-form-input:focus { border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124,58,237,.14); }
.inv-form-input:hover:not(:focus) { border-color: #cbd5e1; }

/* Date row */
.inv-date-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
@media (max-width: 420px) { .inv-date-grid { grid-template-columns: 1fr; } }

/* Footer */
.inv-modal-foot {
    padding: .875rem 1.5rem 1.25rem;
    border-top: 1px solid #f1f5f9;
    display: flex; gap: .625rem; flex-shrink: 0;
}
.inv-modal-cancel {
    flex: 1; padding: .6875rem 1rem; border-radius: .75rem;
    border: 1.5px solid #e2e8f0; background: #fff;
    color: #475569 !important; font-weight: 600; cursor: pointer;
    font-size: .875rem; transition: background .15s, border-color .15s;
    text-align: center;
}
.inv-modal-cancel:hover { background: #f8fafc; border-color: #cbd5e1; }
.inv-modal-submit {
    flex: 2; padding: .6875rem 1rem; border-radius: .75rem;
    background: linear-gradient(135deg, #7c3aed, #2563eb);
    color: #fff !important; font-weight: 700; cursor: pointer;
    font-size: .875rem; border: none;
    display: flex; align-items: center; justify-content: center; gap: .425rem;
    box-shadow: 0 2px 12px rgba(124,58,237,.35);
    transition: opacity .18s, transform .15s, box-shadow .18s;
}
.inv-modal-submit:hover { opacity: .93; transform: translateY(-1px); box-shadow: 0 4px 18px rgba(124,58,237,.5); }
.inv-modal-submit:active { transform: none; opacity: 1; }

/* Responsive: bottom sheet */
@media (max-width: 599px) {
    .inv-modal-overlay { align-items: flex-end; padding: 0; }
    .inv-modal-dialog { width: 100%; border-radius: 1.375rem 1.375rem 0 0; max-height: 92dvh; }
    .inv-modal-dialog::before { border-radius: 1.375rem 1.375rem 0 0; }
}

/* Dark mode */
.dark-mode .inv-modal-dialog {
    background: var(--bg-card) !important;
    box-shadow: 0 0 0 1px rgba(255,255,255,.06), 0 24px 64px rgba(0,0,0,.7) !important;
}
.dark-mode .inv-modal-head { border-bottom-color: rgba(255,255,255,.07) !important; }
.dark-mode .inv-modal-head-title { color: var(--text-main) !important; }
.dark-mode .inv-modal-head-sub   { color: var(--text-muted) !important; }
.dark-mode .inv-modal-close { border-color: rgba(255,255,255,.1) !important; color: var(--text-muted) !important; }
.dark-mode .inv-modal-close:hover { background: rgba(239,68,68,.15) !important; border-color: rgba(239,68,68,.4) !important; color: #f87171 !important; }
.dark-mode .inv-form-label  { color: #94a3b8 !important; }
.dark-mode .inv-form-input  { background: rgba(255,255,255,.06) !important; border-color: rgba(255,255,255,.12) !important; color: var(--text-main) !important; }
.dark-mode .inv-form-input:focus { border-color: #7c3aed !important; box-shadow: 0 0 0 3px rgba(124,58,237,.22) !important; }
.dark-mode .inv-form-input::placeholder { color: #475569 !important; }
.dark-mode .inv-modal-foot { border-top-color: rgba(255,255,255,.07) !important; }
.dark-mode .inv-modal-cancel { background: rgba(255,255,255,.05) !important; border-color: rgba(255,255,255,.1) !important; color: var(--text-muted) !important; }
.dark-mode .inv-modal-cancel:hover { background: rgba(255,255,255,.09) !important; }

/* Sync banner */
.inv-sync-banner {
    margin-bottom: 1.5rem; padding: 1rem 1.25rem;
    border-radius: 0.875rem; border: 1px solid;
    display: flex; align-items: flex-start; gap: 0.75rem;
}
.inv-sync-banner--ok   { background: rgba(22,163,74,0.08);  border-color: rgba(22,163,74,0.3);  color: #15803d; }
.inv-sync-banner--warn { background: rgba(234,88,12,0.08);  border-color: rgba(234,88,12,0.3);  color: #c2410c; }
.inv-sync-banner--err  { background: rgba(220,38,38,0.08);  border-color: rgba(220,38,38,0.3);  color: #b91c1c; }

/* Flash message */
.inv-flash {
    margin-bottom: 1.5rem; padding: 0.875rem 1.125rem;
    border-radius: 0.875rem; border: 1px solid;
    display: flex; align-items: center; gap: 0.625rem;
    font-size: 0.9375rem;
}
.inv-flash--ok   { background: rgba(22,163,74,0.08);  border-color: rgba(22,163,74,0.3);  color: #15803d; }
.inv-flash--err  { background: rgba(220,38,38,0.08);  border-color: rgba(220,38,38,0.3);  color: #b91c1c; }
</style>

<div id="inventoryContent">

<?php if ($checkoutSuccess): ?>
<div class="inv-flash inv-flash--ok">
    <i class="fas fa-check-circle flex-shrink-0"></i>
    <span><?php echo htmlspecialchars($checkoutSuccess); ?></span>
</div>
<?php endif; ?>

<?php if ($checkoutError): ?>
<div class="inv-flash inv-flash--err">
    <i class="fas fa-exclamation-circle flex-shrink-0"></i>
    <span><?php echo htmlspecialchars($checkoutError); ?></span>
</div>
<?php endif; ?>

<!-- Page Header -->
<div style="margin-bottom:2rem; display:flex; flex-direction:column; gap:1rem;">
    <div style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:1rem;">
        <div style="display:flex; align-items:center; gap:0.875rem;">
            <div class="inv-header-icon">
                <i class="fas fa-boxes" style="color:#fff; font-size:1.25rem;"></i>
            </div>
            <div>
                <h1 style="font-size:1.75rem; font-weight:800; color:var(--text-main); margin:0; line-height:1.2;">Inventar</h1>
                <p style="color:var(--text-muted); margin:0.2rem 0 0; font-size:0.9375rem;">
                    <?php echo count($inventoryObjects); ?> Artikel verfügbar
                </p>
            </div>
        </div>
        <div style="display:flex; gap:0.625rem; flex-wrap:wrap;">
            <a href="my_rentals.php" class="inv-rentals-btn">
                <i class="fas fa-clipboard-list"></i>Meine Ausleihen
            </a>
            <?php if (AuthHandler::isAdmin()): ?>
            <a href="sync.php" class="inv-sync-btn">
                <i class="fas fa-sync-alt"></i>EasyVerein Sync
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Sync Results -->
<?php if ($syncResult): ?>
<?php
    $syncHasErrors   = !empty($syncResult['errors']);
    $syncTotalFailed = $syncHasErrors
        && ($syncResult['created'] === 0 && $syncResult['updated'] === 0 && $syncResult['archived'] === 0);
    if ($syncTotalFailed) {
        $syncBannerMod = 'inv-sync-banner--err';
        $syncIcon      = 'fa-exclamation-circle';
        $syncTitle     = 'EasyVerein Sync fehlgeschlagen';
    } elseif ($syncHasErrors) {
        $syncBannerMod = 'inv-sync-banner--warn';
        $syncIcon      = 'fa-exclamation-triangle';
        $syncTitle     = 'EasyVerein Sync abgeschlossen (mit Fehlern)';
    } else {
        $syncBannerMod = 'inv-sync-banner--ok';
        $syncIcon      = 'fa-check-circle';
        $syncTitle     = 'EasyVerein Sync erfolgreich';
    }
?>
<div class="inv-sync-banner <?php echo $syncBannerMod; ?>">
    <i class="fas <?php echo $syncIcon; ?> flex-shrink-0" style="font-size:1.25rem; margin-top:0.1rem;"></i>
    <div style="flex:1; min-width:0;">
        <p style="font-weight:700; margin:0 0 0.375rem;"><?php echo htmlspecialchars($syncTitle); ?></p>
        <?php if (!$syncTotalFailed): ?>
        <ul style="margin:0; padding-left:1rem; font-size:0.875rem; line-height:1.7;">
            <li>Erstellt: <strong><?php echo (int)$syncResult['created']; ?></strong> Artikel</li>
            <li>Aktualisiert: <strong><?php echo (int)$syncResult['updated']; ?></strong> Artikel</li>
            <li>Archiviert: <strong><?php echo (int)$syncResult['archived']; ?></strong> Artikel</li>
        </ul>
        <?php endif; ?>
        <?php if ($syncHasErrors): ?>
        <div style="margin-top:0.625rem; font-size:0.875rem;">
            <strong><?php echo count($syncResult['errors']); ?> Fehler:</strong>
            <ul style="margin:0.25rem 0 0; padding-left:1rem; line-height:1.7;">
                <?php foreach ($syncResult['errors'] as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Search Bar -->
<div class="inv-search-card">
    <form method="GET" style="display:flex; gap:0.75rem; align-items:flex-end; flex-wrap:wrap;">
        <div style="flex:1; min-width:12rem;">
            <label style="display:block; font-size:0.8125rem; font-weight:600; color:var(--text-main); margin-bottom:0.4rem;">
                <i class="fas fa-search" style="color:#7c3aed; margin-right:0.375rem;"></i>Suche
            </label>
            <input
                type="text"
                name="search"
                class="inv-search-input"
                placeholder="Artikelname oder Beschreibung..."
                value="<?php echo htmlspecialchars($search); ?>"
            >
        </div>
        <div style="display:flex; gap:0.5rem;">
            <button type="submit" class="inv-search-btn">
                <i class="fas fa-search" style="margin-right:0.375rem;"></i>Suchen
            </button>
            <?php if ($search !== ''): ?>
            <a href="index.php" class="inv-clear-btn" style="text-decoration:none; display:flex; align-items:center; justify-content:center;">
                <i class="fas fa-times"></i>
            </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- API Load Error -->
<?php if ($loadError): ?>
<div class="inv-flash inv-flash--err" style="margin-bottom:1.5rem;">
    <i class="fas fa-exclamation-triangle flex-shrink-0"></i>
    <span><strong>Fehler beim Laden:</strong> <?php echo htmlspecialchars($loadError); ?></span>
</div>
<?php endif; ?>

<!-- Inventory Grid -->
<?php if (empty($inventoryObjects) && !$loadError): ?>
<div style="background-color:var(--bg-card); border:1px solid var(--border-color); border-radius:1.125rem; padding:4rem 2rem; text-align:center; box-shadow:0 2px 8px rgba(0,0,0,0.06);">
    <i class="fas fa-inbox" style="font-size:4rem; color:rgba(124,58,237,0.2); margin-bottom:1rem; display:block;"></i>
    <p style="font-size:1.0625rem; font-weight:600; color:var(--text-main); margin:0 0 0.5rem;">Keine Artikel gefunden</p>
    <?php if ($search !== ''): ?>
    <a href="index.php" style="color:#7c3aed; font-size:0.9rem;">Alle Artikel anzeigen</a>
    <?php elseif (AuthHandler::isAdmin()): ?>
    <a href="sync.php" class="inv-sync-btn" style="margin-top:1rem; display:inline-flex;">
        <i class="fas fa-sync-alt"></i>EasyVerein Sync
    </a>
    <?php endif; ?>
</div>
<?php else: ?>
<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(17rem, 1fr)); gap:1.25rem;">
    <?php foreach ($inventoryObjects as $item):
        $itemId        = $item['id'] ?? '';
        $itemName      = $item['name'] ?? '';
        $itemDesc      = $item['description'] ?? '';
        $itemPieces    = (int)($item['quantity'] ?? 0);
        $itemLoaned    = $itemPieces - (int)$item['available_quantity'];
        $itemAvailable = (int)$item['available_quantity'];
        $rawImage      = $item['image_path'] ?? null;
        if ($rawImage && strpos($rawImage, 'easyverein.com') !== false) {
            $imageSrc = '/api/easyverein_image.php?url=' . urlencode($rawImage);
        } elseif ($rawImage) {
            $imageSrc = '/' . ltrim($rawImage, '/');
        } else {
            $imageSrc = null;
        }
        $hasStock = $itemAvailable > 0;
    ?>
    <div class="inv-card">
        <!-- Image -->
        <div class="inv-img-wrap">
            <?php if ($imageSrc): ?>
            <img src="<?php echo htmlspecialchars($imageSrc); ?>" alt="<?php echo htmlspecialchars($itemName); ?>" loading="lazy">
            <?php else: ?>
            <i class="fas fa-box-open inv-img-placeholder" aria-label="Kein Bild"></i>
            <?php endif; ?>

            <!-- Availability Badge (only show when out of stock) -->
            <?php if (!$hasStock): ?>
            <span class="inv-avail-badge inv-avail-badge--none">
                <i class="fas fa-times-circle"></i>Vergriffen
            </span>
            <?php endif; ?>
        </div>

        <!-- Body -->
        <div class="inv-card-body">
            <h3 class="inv-card-title" title="<?php echo htmlspecialchars($itemName); ?>">
                <?php echo htmlspecialchars($itemName); ?>
            </h3>
            <?php if ($itemDesc !== ''): ?>
            <p class="inv-card-desc" title="<?php echo htmlspecialchars($itemDesc); ?>">
                <?php echo htmlspecialchars($itemDesc); ?>
            </p>
            <?php else: ?>
            <div style="flex:1;"></div>
            <?php endif; ?>

            <!-- Stock Info -->
            <div class="inv-stock-row">
                <div class="inv-stock-cell">
                    <span class="inv-stock-label">Gesamt</span>
                    <span class="inv-stock-val"><?php echo $itemPieces; ?></span>
                </div>
                <div class="inv-stock-cell inv-stock-cell--loan">
                    <span class="inv-stock-label">Ausgeliehen</span>
                    <span class="inv-stock-val"><?php echo $itemLoaned; ?></span>
                </div>
                <div class="inv-stock-cell <?php echo $hasStock ? 'inv-stock-cell--ok' : 'inv-stock-cell--empty'; ?>">
                    <span class="inv-stock-label">Verfügbar</span>
                    <span class="inv-stock-val"><?php echo $itemAvailable; ?></span>
                </div>
            </div>

            <!-- Actions -->
            <div class="inv-action-row">
                <a href="view.php?id=<?php echo htmlspecialchars($itemId); ?>" class="inv-view-btn" title="Details">
                    <i class="fas fa-eye"></i>
                </a>
                <?php if ($hasStock): ?>
                <button
                    type="button"
                    class="inv-lend-btn"
                    onclick="openInvModal(<?php echo htmlspecialchars(json_encode([
                        'id'     => (string)$itemId,
                        'name'   => $itemName,
                        'pieces' => $itemAvailable,
                    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>)"
                >
                    <i class="fas fa-hand-holding"></i>Ausleihen
                </button>
                <?php else: ?>
                <button type="button" class="inv-disabled-btn" disabled>
                    <i class="fas fa-ban"></i>Vergriffen
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

</div><!-- /#inventoryContent -->

<!-- ── Lending Modal ─────────────────────────────────────────── -->
<div id="invModalOverlay" class="inv-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="invModalTitle"
     onclick="if(event.target===this)closeInvModal()">
    <div class="inv-modal-dialog" id="invModalDialog">

        <!-- Header -->
        <div class="inv-modal-head">
            <div class="inv-modal-head-left">
                <div class="inv-modal-head-icon">
                    <i class="fas fa-hand-holding" style="color:#fff;font-size:.9rem;" aria-hidden="true"></i>
                </div>
                <div style="min-width:0;">
                    <p class="inv-modal-head-title" id="invModalTitle">Ausleihen / Entnehmen</p>
                    <p class="inv-modal-head-sub" id="invModalItemName"></p>
                </div>
            </div>
            <button class="inv-modal-close" onclick="closeInvModal()" aria-label="Schließen">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <!-- Body -->
        <form id="invLendForm" method="POST" action="" style="display:contents;">
            <div class="inv-modal-body">
                <input type="hidden" name="checkout" value="1">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken()); ?>">
                <input type="hidden" name="return_to" value="index">

                <!-- Quantity -->
                <div>
                    <label for="invQty" class="inv-form-label">
                        Menge <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="number" id="invQty" name="quantity" min="1" value="1" required
                           class="inv-form-input" placeholder="1">
                </div>

                <!-- Date Range -->
                <div class="inv-date-grid">
                    <div>
                        <label for="invStartDate" class="inv-form-label">Von <span style="color:#ef4444;">*</span></label>
                        <input type="date" id="invStartDate" name="start_date" required
                               min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>"
                               class="inv-form-input">
                    </div>
                    <div>
                        <label for="invEndDate" class="inv-form-label">Bis <span style="color:#ef4444;">*</span></label>
                        <input type="date" id="invEndDate" name="end_date" required
                               min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                               value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                               class="inv-form-input">
                    </div>
                </div>

                <!-- Purpose -->
                <div>
                    <label for="invPurpose" class="inv-form-label">Verwendungszweck</label>
                    <input type="text" id="invPurpose" name="purpose" maxlength="255"
                           placeholder="z. B. Veranstaltung, Projekt …"
                           class="inv-form-input">
                </div>

                <!-- Destination -->
                <div>
                    <label for="invDest" class="inv-form-label">
                        Zielort <span style="font-size:.7rem;font-weight:400;text-transform:none;color:#94a3b8;">(optional)</span>
                    </label>
                    <input type="text" id="invDest" name="destination" maxlength="255"
                           placeholder="z. B. Gemeindehaus, Außenlager …"
                           class="inv-form-input">
                </div>
            </div>

            <!-- Footer -->
            <div class="inv-modal-foot">
                <button type="button" class="inv-modal-cancel" onclick="closeInvModal()">Abbrechen</button>
                <button type="submit" class="inv-modal-submit">
                    <i class="fas fa-paper-plane" aria-hidden="true"></i>Anfrage senden
                </button>
            </div>
        </form>

    </div>
</div>

<script>
(function () {
    'use strict';
    function fmtDate(d) {
        return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    }

    var _scrollY = 0;

    window.openInvModal = function (item) {
        var overlay  = document.getElementById('invModalOverlay');
        var nameEl   = document.getElementById('invModalItemName');
        var qtyInput = document.getElementById('invQty');
        var form     = document.getElementById('invLendForm');

        form.action = 'checkout.php?id=' + encodeURIComponent(item.id);
        if (nameEl) nameEl.textContent = item.name;

        qtyInput.max   = item.pieces;
        qtyInput.value = 1;

        var today    = new Date();
        var tomorrow = new Date(today); tomorrow.setDate(tomorrow.getDate() + 1);
        var startEl  = document.getElementById('invStartDate');
        var endEl    = document.getElementById('invEndDate');
        if (startEl) { startEl.value = fmtDate(today);    startEl.min = fmtDate(today); }
        if (endEl)   { endEl.value   = fmtDate(tomorrow); endEl.min   = fmtDate(tomorrow); }

        var purposeEl = document.getElementById('invPurpose');
        var destEl    = document.getElementById('invDest');
        if (purposeEl) purposeEl.value = '';
        if (destEl)    destEl.value    = '';

        /* Lock scroll WITHOUT jumping to top */
        _scrollY = window.scrollY;
        document.body.style.position = 'fixed';
        document.body.style.top      = '-' + _scrollY + 'px';
        document.body.style.left     = '0';
        document.body.style.right    = '0';
        document.body.style.overflow = 'hidden';

        overlay.classList.add('open');
        setTimeout(function () { qtyInput.focus(); }, 280);
    };

    window.closeInvModal = function () {
        document.getElementById('invModalOverlay').classList.remove('open');
        /* Restore scroll position exactly where user was */
        document.body.style.position = '';
        document.body.style.top      = '';
        document.body.style.left     = '';
        document.body.style.right    = '';
        document.body.style.overflow = '';
        window.scrollTo(0, _scrollY);
    };

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeInvModal();
    });

    var startEl = document.getElementById('invStartDate');
    if (startEl) {
        startEl.addEventListener('change', function () {
            var endEl = document.getElementById('invEndDate');
            if (!endEl) return;
            var next = new Date(this.value);
            next.setDate(next.getDate() + 1);
            var minDate = fmtDate(next);
            endEl.min = minDate;
            if (endEl.value < minDate) endEl.value = minDate;
        });
    }
}());
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
