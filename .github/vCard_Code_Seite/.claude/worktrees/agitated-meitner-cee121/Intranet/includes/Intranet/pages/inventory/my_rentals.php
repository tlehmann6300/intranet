<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Inventory.php';
require_once __DIR__ . '/../../includes/database.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$userId = (int)Auth::getUserId();

// Load active rentals from the Inventory DB (dbs15419825) – primary source.
$rentals = [];
try {
    $dbInventory = Database::getInventoryDB();
    $stmt        = $dbInventory->prepare(
        "SELECT r.id,
                ii.easyverein_item_id,
                1            AS quantity,
                r.start_date AS rented_at,
                r.end_date,
                r.status,
                r.created_at
           FROM inventory_rentals r
           JOIN inventory_items ii ON ii.id = r.item_id
          WHERE r.user_id = ?
            AND r.status IN ('pending', 'active', 'overdue')
          ORDER BY r.created_at DESC"
    );
    $stmt->execute([$userId]);
    $rentals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('my_rentals: inventory_rentals (InventoryDB) query failed: ' . $e->getMessage());
}

// Also load pending/active requests from the Content DB (board-approval workflow).
try {
    $dbContent = Database::getContentDB();
    $stmt      = $dbContent->prepare(
        "SELECT id,
                inventory_object_id AS easyverein_item_id,
                quantity,
                start_date          AS rented_at,
                end_date,
                status,
                created_at
           FROM inventory_requests
          WHERE user_id = ?
            AND status IN ('pending', 'approved', 'pending_return')
          ORDER BY created_at DESC"
    );
    $stmt->execute([$userId]);
    $requestRentals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rentals        = array_merge($rentals, $requestRentals);
} catch (Exception $e) {
    // Table may not exist – silently ignore
}

// Batch-resolve item names from EasyVerein
$itemNames = [];
if (!empty($rentals)) {
    try {
        foreach (Inventory::getAll() as $evItem) {
            $eid = (string)($evItem['easyverein_id'] ?? $evItem['id'] ?? '');
            if ($eid !== '') {
                $itemNames[$eid] = $evItem['name'] ?? '';
            }
        }
    } catch (Exception $e) {
        error_log('my_rentals: EasyVerein fetch failed: ' . $e->getMessage());
    }
}

$successMessage = $_SESSION['rental_success'] ?? null;
unset($_SESSION['rental_success']);
$errorMessage = $_SESSION['rental_error'] ?? null;
unset($_SESSION['rental_error']);

$title = 'Meine Ausleihen - IBC Intranet';
ob_start();
?>

<style>
/* ── Ausleihe (My Rentals) Module ── */
.rent-header-icon {
    width: 3rem; height: 3rem;
    border-radius: 0.875rem;
    background: linear-gradient(135deg, #7c3aed, #2563eb);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 14px rgba(124,58,237,0.35);
    flex-shrink: 0;
}
.rent-back-btn {
    display: inline-flex; align-items: center; gap: 0.5rem;
    padding: 0.75rem 1.25rem; border-radius: 0.75rem;
    background: linear-gradient(135deg, #7c3aed, #2563eb);
    color: #fff; font-weight: 600; font-size: 0.9375rem;
    text-decoration: none;
    box-shadow: 0 3px 12px rgba(124,58,237,0.35);
    transition: opacity .2s, transform .15s;
}
.rent-back-btn:hover { opacity: .9; transform: scale(1.03); color: #fff; }

/* Flash messages */
.rent-flash {
    margin-bottom: 1.5rem; padding: 0.875rem 1.125rem;
    border-radius: 0.875rem; border: 1px solid;
    display: flex; align-items: center; gap: 0.625rem; font-size: 0.9375rem;
}
.rent-flash--ok  { background: rgba(22,163,74,0.08);  border-color: rgba(22,163,74,0.3);  color: #15803d; }
.rent-flash--err { background: rgba(220,38,38,0.08);  border-color: rgba(220,38,38,0.3);  color: #b91c1c; }

/* Cards grid */
.rent-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr));
    gap: 1.25rem;
    margin-bottom: 2rem;
}

.rent-card {
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 1.125rem;
    overflow: hidden;
    display: flex; flex-direction: column;
    box-shadow: 0 2px 10px rgba(0,0,0,0.07);
    transition: box-shadow .25s, transform .2s;
    animation: rentCardIn .45s ease both;
    position: relative;
}
.rent-card:hover {
    box-shadow: 0 8px 28px rgba(124,58,237,0.12);
    transform: translateY(-3px);
}
@keyframes rentCardIn {
    from { opacity: 0; transform: translateY(18px); }
    to   { opacity: 1; transform: translateY(0); }
}
.rent-card:nth-child(1)  { animation-delay: .05s }
.rent-card:nth-child(2)  { animation-delay: .10s }
.rent-card:nth-child(3)  { animation-delay: .15s }
.rent-card:nth-child(4)  { animation-delay: .20s }
.rent-card:nth-child(n+5){ animation-delay: .25s }

/* Status accent bar */
.rent-accent {
    height: 4px;
    width: 100%;
}
.rent-accent--pending       { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
.rent-accent--pending-return{ background: linear-gradient(90deg, #f59e0b, #fbbf24); }
.rent-accent--overdue       { background: linear-gradient(90deg, #dc2626, #ef4444); }
.rent-accent--active        { background: linear-gradient(90deg, #16a34a, #22c55e); }

/* Card sections */
.rent-card-head {
    padding: 1rem 1.125rem 0.75rem;
    display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem;
}
.rent-card-title {
    font-size: 0.9375rem; font-weight: 700;
    color: var(--text-main);
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    flex: 1;
}
.rent-card-body { padding: 0 1.125rem 0.875rem; flex: 1; display: flex; flex-direction: column; gap: 0.625rem; }
.rent-card-footer { padding: 0 1.125rem 1.125rem; }

/* Status badge */
.rent-badge {
    display: inline-flex; align-items: center; gap: 0.3rem;
    padding: 0.25rem 0.625rem; border-radius: 999px;
    font-size: 0.7rem; font-weight: 700; border: 1px solid; flex-shrink: 0;
}
.rent-badge--pending       { background:rgba(59,130,246,0.1);  color:#1d4ed8; border-color:rgba(59,130,246,0.3); }
.rent-badge--pending-return{ background:rgba(245,158,11,0.1);  color:#b45309; border-color:rgba(245,158,11,0.3); }
.rent-badge--overdue       { background:rgba(220,38,38,0.1);   color:#b91c1c; border-color:rgba(220,38,38,0.3); }
.rent-badge--active        { background:rgba(22,163,74,0.1);   color:#15803d; border-color:rgba(22,163,74,0.3); }

/* Info row */
.rent-info-row {
    display: flex; align-items: center; gap: 0.625rem;
    font-size: 0.8125rem; color: var(--text-muted);
}
.rent-info-icon {
    width: 1.75rem; height: 1.75rem; border-radius: 0.4375rem;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; font-size: 0.6875rem;
}
.rent-info-icon--purple { background: rgba(124,58,237,0.1); color: #7c3aed; }
.rent-info-icon--blue   { background: rgba(37,99,235,0.1);  color: #2563eb; }

/* Overdue notice */
.rent-overdue-notice {
    display: flex; align-items: center; gap: 0.5rem;
    padding: 0.5rem 0.75rem; border-radius: 0.625rem;
    background: rgba(220,38,38,0.08); border: 1px solid rgba(220,38,38,0.2);
    color: #b91c1c; font-size: 0.75rem;
}

/* Action buttons */
.rent-action-waiting {
    width: 100%; display: flex; align-items: center; justify-content: center; gap: 0.5rem;
    padding: 0.625rem 1rem; border-radius: 0.75rem;
    font-size: 0.8125rem; font-weight: 600; border: 1px solid;
}
.rent-action-waiting--blue   { background:rgba(59,130,246,0.08); border-color:rgba(59,130,246,0.25); color:#1d4ed8; }
.rent-action-waiting--amber  { background:rgba(245,158,11,0.08); border-color:rgba(245,158,11,0.25); color:#b45309; }
.rent-action-waiting--green  { background:rgba(22,163,74,0.08);  border-color:rgba(22,163,74,0.25);  color:#15803d; }
.rent-return-btn {
    width: 100%; display: flex; align-items: center; justify-content: center; gap: 0.5rem;
    padding: 0.625rem 1rem; border: none; border-radius: 0.75rem;
    font-size: 0.8125rem; font-weight: 700; cursor: pointer;
    transition: opacity .2s; color: #fff;
}
.rent-return-btn--blue  { background: #2563eb; }
.rent-return-btn--red   { background: #dc2626; }
.rent-return-btn--orange{ background: #ea580c; }
.rent-return-btn:hover  { opacity: .88; }

/* Empty state */
.rent-empty {
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 1.125rem;
    padding: 4rem 2rem; text-align: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
}

/* History note */
.rent-history-note {
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius: 1rem;
    padding: 1.125rem 1.375rem;
    display: flex; align-items: flex-start; gap: 0.875rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}
.rent-history-icon {
    width: 2.25rem; height: 2.25rem; border-radius: 0.625rem;
    background: rgba(100,116,139,0.1);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; font-size: 0.9375rem; color: var(--text-muted);
}
</style>

<?php if ($successMessage): ?>
<div class="rent-flash rent-flash--ok">
    <i class="fas fa-check-circle flex-shrink-0"></i>
    <span><?php echo htmlspecialchars($successMessage); ?></span>
</div>
<?php endif; ?>

<?php if ($errorMessage): ?>
<div class="rent-flash rent-flash--err">
    <i class="fas fa-exclamation-circle flex-shrink-0"></i>
    <span><?php echo htmlspecialchars($errorMessage); ?></span>
</div>
<?php endif; ?>

<!-- Page Header -->
<div style="margin-bottom:2rem; display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:1rem;">
    <div style="display:flex; align-items:center; gap:0.875rem;">
        <div class="rent-header-icon">
            <i class="fas fa-clipboard-list" style="color:#fff; font-size:1.125rem;"></i>
        </div>
        <div>
            <h1 style="font-size:1.75rem; font-weight:800; color:var(--text-main); margin:0; line-height:1.2;">Meine Ausleihen</h1>
            <p style="color:var(--text-muted); margin:0.2rem 0 0; font-size:0.9375rem;">
                <?php $cnt = count($rentals); echo $cnt === 0 ? 'Keine aktiven Ausleihen' : ($cnt . ' aktive Ausleihe' . ($cnt !== 1 ? 'n' : '')); ?>
            </p>
        </div>
    </div>
    <a href="index.php" class="rent-back-btn">
        <i class="fas fa-plus-circle"></i>Artikel ausleihen
    </a>
</div>

<?php if (empty($rentals)): ?>
<!-- Empty State -->
<div class="rent-empty">
    <div style="width:5rem; height:5rem; border-radius:50%; background:rgba(124,58,237,0.08); display:flex; align-items:center; justify-content:center; margin:0 auto 1.25rem;">
        <i class="fas fa-inbox" style="font-size:2.25rem; color:rgba(124,58,237,0.3);"></i>
    </div>
    <h2 style="font-size:1.125rem; font-weight:700; color:var(--text-main); margin:0 0 0.5rem;">Keine aktiven Ausleihen</h2>
    <p style="color:var(--text-muted); font-size:0.9rem; margin:0 0 1.5rem;">Du hast aktuell keine laufenden Ausleihvorgänge.</p>
    <a href="index.php" class="rent-back-btn" style="display:inline-flex;">
        <i class="fas fa-search"></i>Artikel entdecken
    </a>
</div>
<?php else: ?>
<!-- Rental Cards -->
<div class="rent-grid">
    <?php foreach ($rentals as $rental):
        $easyvereinId = (string)$rental['easyverein_item_id'];
        $itemName     = $itemNames[$easyvereinId] ?? ('Artikel #' . $easyvereinId);
        $quantity     = (int)$rental['quantity'];
        $rentedAt     = $rental['rented_at'] ? date('d.m.Y', strtotime($rental['rented_at'])) : '-';
        $endDate      = !empty($rental['end_date']) ? date('d.m.Y', strtotime($rental['end_date'])) : null;
        $status       = $rental['status'];

        $isAwaitingApproval = $status === 'pending';
        $isAwaitingReturn   = $status === 'pending_return';
        $isActive           = ($status === 'active' || $status === 'approved' || $status === 'overdue');
        $isOverdue          = ($status === 'overdue')
                              || ($isActive && $status !== 'overdue' && !empty($rental['end_date']) && strtotime($rental['end_date']) < strtotime('today'));
        $isEarlyReturn      = $isActive && !empty($rental['end_date']) && strtotime($rental['end_date']) > strtotime('today');

        if ($isAwaitingApproval) {
            $accentMod = 'rent-accent--pending';
            $badgeMod  = 'rent-badge--pending';
            $label     = 'Ausstehend';
            $icon      = 'fa-hourglass-half';
        } elseif ($isAwaitingReturn) {
            $accentMod = 'rent-accent--pending-return';
            $badgeMod  = 'rent-badge--pending-return';
            $label     = 'Rückgabe in Prüfung';
            $icon      = 'fa-clock';
        } elseif ($isOverdue) {
            $accentMod = 'rent-accent--overdue';
            $badgeMod  = 'rent-badge--overdue';
            $label     = 'Überfällig';
            $icon      = 'fa-exclamation-circle';
        } else {
            $accentMod = 'rent-accent--active';
            $badgeMod  = 'rent-badge--active';
            $label     = 'Aktiv';
            $icon      = 'fa-check-circle';
        }
    ?>
    <div class="rent-card">
        <div class="rent-accent <?php echo $accentMod; ?>"></div>

        <div class="rent-card-head">
            <h3 class="rent-card-title" title="<?php echo htmlspecialchars($itemName); ?>">
                <?php echo htmlspecialchars($itemName); ?>
            </h3>
            <span class="rent-badge <?php echo $badgeMod; ?>">
                <i class="fas <?php echo $icon; ?>"></i><?php echo $label; ?>
            </span>
        </div>

        <div class="rent-card-body">
            <!-- Quantity -->
            <div class="rent-info-row">
                <div class="rent-info-icon rent-info-icon--purple">
                    <i class="fas fa-cubes"></i>
                </div>
                <span>Menge: <strong style="color:var(--text-main);"><?php echo $quantity; ?> Stück</strong></span>
            </div>

            <!-- Date range -->
            <div class="rent-info-row">
                <div class="rent-info-icon rent-info-icon--blue">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <span>
                    Ab <strong style="color:var(--text-main);"><?php echo htmlspecialchars($rentedAt); ?></strong>
                    <?php if ($endDate): ?>
                    <span style="margin:0 0.25rem; color:var(--text-muted);">→</span>
                    <strong style="color:<?php echo $isOverdue ? '#dc2626' : 'var(--text-main)'; ?>;">
                        <?php echo htmlspecialchars($endDate); ?>
                    </strong>
                    <?php endif; ?>
                </span>
            </div>

            <?php if ($isOverdue): ?>
            <div class="rent-overdue-notice">
                <i class="fas fa-exclamation-triangle flex-shrink-0"></i>
                <span>Rückgabe überfällig – bitte zurückgeben.</span>
            </div>
            <?php endif; ?>
        </div>

        <div class="rent-card-footer">
            <?php if ($isAwaitingApproval): ?>
            <div class="rent-action-waiting rent-action-waiting--blue">
                <i class="fas fa-hourglass-half"></i>Wartet auf Genehmigung
            </div>
            <?php elseif ($isAwaitingReturn): ?>
            <div class="rent-action-waiting rent-action-waiting--amber">
                <i class="fas fa-clock"></i>Rückgabe wird bestätigt
            </div>
            <?php elseif ($isActive && $status === 'active'): ?>
            <?php $confirmMsg = $isEarlyReturn
                ? 'Vorzeitige Rückgabe melden? Das Gerät wird sofort wieder freigegeben.'
                : 'Rückgabe für diesen Artikel melden?'; ?>
            <form method="POST" action="rental.php" onsubmit="return confirm('<?php echo htmlspecialchars($confirmMsg, ENT_QUOTES, 'UTF-8'); ?>')">
                <input type="hidden" name="request_return" value="1">
                <input type="hidden" name="rental_id" value="<?php echo (int)$rental['id']; ?>">
                <button type="submit" class="rent-return-btn <?php echo $isEarlyReturn ? 'rent-return-btn--red' : 'rent-return-btn--blue'; ?>">
                    <i class="fas fa-undo-alt"></i><?php echo $isEarlyReturn ? 'Vorzeitig zurückgeben' : 'Zurückgeben'; ?>
                </button>
            </form>
            <?php elseif ($isActive && $status === 'approved'): ?>
            <?php $confirmMsg = $isEarlyReturn
                ? 'Vorzeitige Rückgabe melden? Das Gerät wird sofort wieder freigegeben.'
                : 'Rückgabe jetzt melden? Der Vorstand wird benachrichtigt.'; ?>
            <form method="POST" action="rental.php" onsubmit="return confirm('<?php echo htmlspecialchars($confirmMsg, ENT_QUOTES, 'UTF-8'); ?>')">
                <input type="hidden" name="request_return_approved" value="1">
                <input type="hidden" name="request_id" value="<?php echo (int)$rental['id']; ?>">
                <button type="submit" class="rent-return-btn <?php echo $isEarlyReturn ? 'rent-return-btn--red' : 'rent-return-btn--orange'; ?>">
                    <i class="fas fa-undo-alt"></i><?php echo $isEarlyReturn ? 'Vorzeitig zurückgeben' : 'Rückgabe melden'; ?>
                </button>
            </form>
            <?php else: ?>
            <div class="rent-action-waiting rent-action-waiting--green">
                <i class="fas fa-check-circle"></i>Genehmigt
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- History Note -->
<div class="rent-history-note">
    <div class="rent-history-icon">
        <i class="fas fa-history"></i>
    </div>
    <div>
        <h2 style="font-size:0.9375rem; font-weight:700; color:var(--text-main); margin:0 0 0.25rem;">Verlauf</h2>
        <p style="font-size:0.8125rem; color:var(--text-muted); margin:0; line-height:1.5;">
            Der Verlauf zurückgegebener Artikel wird im EasyVerein-Logbuch der jeweiligen Artikel gespeichert.
        </p>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
