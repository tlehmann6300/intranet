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
.inv-modal-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    opacity: 0; pointer-events: none;
    transition: opacity .25s;
    display: flex; align-items: center; justify-content: center;
}
.inv-modal-overlay.open { opacity: 1; pointer-events: auto; }
.inv-modal-dialog {
    background-color: var(--bg-card);
    border-radius: 1.25rem;
    box-shadow: 0 20px 60px rgba(0,0,0,0.25);
    width: min(32rem, calc(100vw - 2rem));
    max-height: 90vh; overflow-y: auto;
    transform: translateY(12px) scale(.97);
    transition: transform .25s ease, opacity .25s;
    padding: 1.75rem;
    opacity: 0;
}
.inv-modal-overlay.open .inv-modal-dialog {
    transform: translateY(0) scale(1);
    opacity: 1;
}
@media (max-width: 599px) {
    .inv-modal-overlay { align-items: flex-end; }
    .inv-modal-dialog {
        width: 100%; border-radius: 1.25rem 1.25rem 0 0;
        max-height: 88vh;
    }
}
.inv-modal-close {
    width: 2.25rem; height: 2.25rem; border-radius: 50%;
    border: none; cursor: pointer;
    background-color: rgba(100,116,139,0.12);
    color: var(--text-muted);
    display: flex; align-items: center; justify-content: center;
    transition: background .2s, color .2s;
    flex-shrink: 0;
}
.inv-modal-close:hover { background-color: rgba(220,38,38,0.12); color: #dc2626; }
.inv-form-label {
    display: block; font-size: 0.8125rem; font-weight: 600;
    color: var(--text-main); margin-bottom: 0.4375rem;
}
.inv-form-input {
    width: 100%; padding: 0.625rem 0.875rem;
    border: 1.5px solid var(--border-color);
    border-radius: 0.625rem;
    background-color: var(--bg-body);
    color: var(--text-main);
    font-size: 0.9375rem;
    transition: border-color .2s, box-shadow .2s;
    outline: none;
}
.inv-form-input:focus {
    border-color: #7c3aed;
    box-shadow: 0 0 0 3px rgba(124,58,237,0.15);
}
.inv-modal-cancel {
    flex: 1; padding: 0.75rem; border: none; border-radius: 0.75rem;
    background-color: rgba(100,116,139,0.12);
    color: var(--text-muted); font-weight: 600; cursor: pointer;
    transition: background .2s;
}
.inv-modal-cancel:hover { background-color: rgba(100,116,139,0.22); }
.inv-modal-submit {
    flex: 1; padding: 0.75rem; border: none; border-radius: 0.75rem;
    background: linear-gradient(135deg, #7c3aed, #2563eb);
    color: #fff; font-weight: 700; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 0.5rem;
    transition: opacity .2s;
    box-shadow: 0 3px 10px rgba(124,58,237,0.3);
}
.inv-modal-submit:hover { opacity: .9; }

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

            <!-- Availability Badge -->
            <?php if ($hasStock): ?>
            <span class="inv-avail-badge inv-avail-badge--ok">
                <i class="fas fa-check-circle"></i><?php echo $itemAvailable; ?> verfügbar
            </span>
            <?php else: ?>
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

<!-- ── Lending Modal ── -->
<div id="invModalOverlay" class="inv-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="invModalTitle"
     onclick="if(event.target===this)closeInvModal()">
    <div class="inv-modal-dialog" id="invModalDialog">
        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; margin-bottom:1.25rem;">
            <div>
                <h2 id="invModalTitle" style="font-size:1.125rem; font-weight:800; color:var(--text-main); margin:0 0 0.2rem;">
                    Ausleihen / Entnehmen
                </h2>
                <p id="invModalItemName" style="font-size:0.875rem; color:var(--text-muted); margin:0;"></p>
            </div>
            <button class="inv-modal-close" onclick="closeInvModal()" aria-label="Schließen">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <form id="invLendForm" method="POST" action="">
            <input type="hidden" name="checkout" value="1">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken()); ?>">
            <input type="hidden" name="return_to" value="index">

            <div style="display:flex; flex-direction:column; gap:1rem;">
                <!-- Quantity -->
                <div>
                    <label for="invQty" class="inv-form-label">
                        <i class="fas fa-hashtag" style="color:#7c3aed; margin-right:0.375rem;"></i>Menge <span style="color:#dc2626;">*</span>
                    </label>
                    <input type="number" id="invQty" name="quantity" min="1" value="1" required class="inv-form-input">
                </div>

                <!-- Date Range -->
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem;">
                    <div>
                        <label for="invStartDate" class="inv-form-label">
                            <i class="fas fa-calendar-alt" style="color:#7c3aed; margin-right:0.375rem;"></i>Von <span style="color:#dc2626;">*</span>
                        </label>
                        <input type="date" id="invStartDate" name="start_date" required
                               min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>"
                               class="inv-form-input">
                    </div>
                    <div>
                        <label for="invEndDate" class="inv-form-label">
                            <i class="fas fa-calendar-alt" style="color:#7c3aed; margin-right:0.375rem;"></i>Bis <span style="color:#dc2626;">*</span>
                        </label>
                        <input type="date" id="invEndDate" name="end_date" required
                               min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                               value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                               class="inv-form-input">
                    </div>
                </div>

                <!-- Purpose -->
                <div>
                    <label for="invPurpose" class="inv-form-label">
                        <i class="fas fa-info-circle" style="color:#7c3aed; margin-right:0.375rem;"></i>Verwendungszweck
                    </label>
                    <input type="text" id="invPurpose" name="purpose" maxlength="255"
                           placeholder="z. B. Veranstaltung, Projekt..."
                           class="inv-form-input">
                </div>

                <!-- Destination -->
                <div>
                    <label for="invDest" class="inv-form-label">
                        <i class="fas fa-map-marker-alt" style="color:#7c3aed; margin-right:0.375rem;"></i>Zielort
                        <span style="color:var(--text-muted); font-weight:400;">(optional)</span>
                    </label>
                    <input type="text" id="invDest" name="destination" maxlength="255"
                           placeholder="z. B. Gemeindehaus, Außenlager..."
                           class="inv-form-input">
                </div>
            </div>

            <div style="display:flex; gap:0.75rem; margin-top:1.5rem;">
                <button type="button" class="inv-modal-cancel" onclick="closeInvModal()">Abbrechen</button>
                <button type="submit" class="inv-modal-submit">
                    <i class="fas fa-paper-plane"></i>Anfrage senden
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

        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
        setTimeout(function () { qtyInput.focus(); }, 280);
    };

    window.closeInvModal = function () {
        document.getElementById('invModalOverlay').classList.remove('open');
        document.body.style.overflow = '';
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
