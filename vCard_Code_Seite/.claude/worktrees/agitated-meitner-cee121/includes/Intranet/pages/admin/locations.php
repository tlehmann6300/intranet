<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/models/Inventory.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';

if (!Auth::check() || !Auth::hasPermission('manager')) {
    header('Location: ../dashboard/index.php');
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_location'])) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $address     = trim($_POST['address'] ?? '');

    if (empty($name)) {
        $error = 'Name ist erforderlich';
    } else {
        try {
            Inventory::createLocation($name, $description, $address);
            $message = 'Standort erfolgreich erstellt';
        } catch (Exception $e) {
            $error = 'Fehler beim Erstellen des Standorts: ' . $e->getMessage();
        }
    }
}

$locations = Inventory::getLocations();

$title = 'Standorte verwalten - IBC Intranet';
ob_start();
?>

<style>
/* ── locations module ── */
.loc-page { animation: locPageIn .45s ease both; }
@keyframes locPageIn {
    from { opacity:0; transform:translateY(14px); }
    to   { opacity:1; transform:translateY(0); }
}

.loc-header-icon {
    width:3rem; height:3rem; border-radius:.875rem; flex-shrink:0;
    background: linear-gradient(135deg, rgba(124,58,237,1), rgba(99,102,241,1));
    box-shadow: 0 4px 14px rgba(124,58,237,.35);
    display:flex; align-items:center; justify-content:justify-content;
    align-items:center; justify-content:center;
}

.loc-alert {
    padding:.875rem 1.25rem; border-radius:.875rem; margin-bottom:1.25rem;
    display:flex; align-items:center; gap:.65rem; font-size:.875rem; font-weight:500;
    border-width:1px; border-style:solid;
}
.loc-alert-ok  { background:rgba(34,197,94,.1);  color:rgba(21,128,61,1);   border-color:rgba(34,197,94,.3); }
.loc-alert-err { background:rgba(239,68,68,.1);  color:rgba(185,28,28,1);  border-color:rgba(239,68,68,.3); }

.loc-card {
    background-color: var(--bg-card);
    border: 1px solid var(--border-color);
    border-radius:1rem;
    padding:1.5rem;
    box-shadow: 0 2px 12px rgba(0,0,0,.06);
}

.loc-label {
    display:block; font-size:.8rem; font-weight:600; color:var(--text-muted);
    margin-bottom:.4rem;
}
.loc-input, .loc-textarea {
    width:100%; padding:.6rem 1rem; border-radius:.625rem; font-size:.875rem;
    background-color: var(--bg-body);
    border: 1.5px solid var(--border-color);
    color: var(--text-main);
    transition: border-color .2s, box-shadow .2s;
    outline: none; resize: vertical;
    box-sizing: border-box;
}
.loc-input:focus, .loc-textarea:focus {
    border-color: rgba(124,58,237,.6);
    box-shadow: 0 0 0 3px rgba(124,58,237,.12);
}
.loc-input::placeholder, .loc-textarea::placeholder { color:var(--text-muted); }

.loc-submit {
    width:100%; padding:.7rem 1.25rem; border-radius:.75rem; font-size:.875rem; font-weight:700;
    background: linear-gradient(135deg, rgba(124,58,237,1), rgba(99,102,241,1));
    color:#fff; border:none; cursor:pointer;
    box-shadow: 0 3px 10px rgba(124,58,237,.3);
    transition: opacity .2s, box-shadow .2s;
    display:flex; align-items:center; justify-content:center; gap:.5rem;
}
.loc-submit:hover { opacity:.92; box-shadow: 0 5px 18px rgba(124,58,237,.42); }

.loc-back {
    display:inline-flex; align-items:center; gap:.5rem;
    padding:.55rem 1.1rem; border-radius:.75rem; font-size:.85rem; font-weight:600;
    background:rgba(156,163,175,.12); color:var(--text-muted);
    border:1.5px solid var(--border-color); text-decoration:none;
    transition: background .2s, color .2s;
}
.loc-back:hover { background:rgba(124,58,237,.08); color:rgba(124,58,237,1); }

/* Location items */
.loc-item {
    border: 1.5px solid var(--border-color);
    border-radius:.875rem;
    padding:1rem 1.1rem;
    background-color: var(--bg-card);
    transition: box-shadow .2s, border-color .2s;
    animation: locItemIn .35s ease both;
}
.loc-item:hover { box-shadow:0 4px 16px rgba(0,0,0,.1); border-color:rgba(124,58,237,.3); }

@keyframes locItemIn {
    from { opacity:0; transform:translateY(10px); }
    to   { opacity:1; transform:translateY(0); }
}
.loc-item:nth-child(1) { animation-delay:.05s; }
.loc-item:nth-child(2) { animation-delay:.09s; }
.loc-item:nth-child(3) { animation-delay:.13s; }
.loc-item:nth-child(4) { animation-delay:.17s; }
.loc-item:nth-child(5) { animation-delay:.21s; }

.loc-footer {
    display:flex; align-items:center; justify-content:space-between;
    margin-top:.75rem; padding-top:.75rem;
    border-top:1px solid var(--border-color);
    font-size:.72rem; color:var(--text-muted);
}

.loc-empty { padding:3rem 1rem; text-align:center; }
.loc-empty-icon {
    width:3rem; height:3rem; border-radius:50%;
    background:rgba(156,163,175,.12);
    display:inline-flex; align-items:center; justify-content:center;
    margin-bottom:.875rem; font-size:1.25rem; color:rgba(156,163,175,1);
}

@media (max-width:768px) {
    .loc-layout { grid-template-columns:1fr !important; }
}
</style>

<div class="loc-page" style="max-width:72rem;margin:0 auto;">

<!-- Header -->
<div style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:1rem;margin-bottom:1.75rem;">
    <div style="display:flex;align-items:center;gap:.875rem;">
        <div class="loc-header-icon">
            <i class="fas fa-map-marker-alt" style="color:#fff;font-size:1.1rem;"></i>
        </div>
        <div>
            <h1 style="font-size:1.6rem;font-weight:800;color:var(--text-main);margin:0;line-height:1.2;">Standorte verwalten</h1>
            <p style="font-size:.85rem;color:var(--text-muted);margin:.2rem 0 0;"><?php echo count($locations); ?> Standorte vorhanden</p>
        </div>
    </div>
    <a href="../inventory/index.php" class="loc-back">
        <i class="fas fa-arrow-left"></i>Zurück zum Inventar
    </a>
</div>

<?php if ($message): ?>
<div class="loc-alert loc-alert-ok"><i class="fas fa-check-circle"></i><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="loc-alert loc-alert-err"><i class="fas fa-exclamation-circle"></i><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:320px 1fr;gap:1.25rem;align-items:start;" class="loc-layout">

    <!-- Create form -->
    <div class="loc-card">
        <h2 style="font-size:1rem;font-weight:700;color:var(--text-main);margin:0 0 1.1rem;display:flex;align-items:center;gap:.5rem;">
            <span style="width:1.6rem;height:1.6rem;border-radius:.4rem;background:rgba(34,197,94,.15);display:inline-flex;align-items:center;justify-content:center;">
                <i class="fas fa-plus" style="font-size:.7rem;color:rgba(21,128,61,1);"></i>
            </span>
            Neuer Standort
        </h2>
        <form method="POST" style="display:flex;flex-direction:column;gap:.9rem;">
            <input type="hidden" name="create_location" value="1">
            <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">

            <div>
                <label class="loc-label">Name <span style="color:rgba(239,68,68,1);">*</span></label>
                <input type="text" name="name" required class="loc-input" placeholder="z.B. Lager, Konferenzraum A">
            </div>
            <div>
                <label class="loc-label">Beschreibung</label>
                <textarea name="description" rows="3" class="loc-textarea" placeholder="Optionale Beschreibung…"></textarea>
            </div>
            <div>
                <label class="loc-label">Adresse</label>
                <textarea name="address" rows="2" class="loc-textarea" placeholder="Optionale Adresse…"></textarea>
            </div>

            <button type="submit" class="loc-submit">
                <i class="fas fa-plus"></i>Standort erstellen
            </button>
        </form>
    </div>

    <!-- Locations list -->
    <div class="loc-card">
        <h2 style="font-size:1rem;font-weight:700;color:var(--text-main);margin:0 0 1.1rem;display:flex;align-items:center;gap:.5rem;">
            <span style="width:1.6rem;height:1.6rem;border-radius:.4rem;background:rgba(59,130,246,.12);display:inline-flex;align-items:center;justify-content:center;">
                <i class="fas fa-list" style="font-size:.7rem;color:rgba(37,99,235,1);"></i>
            </span>
            Bestehende Standorte
        </h2>

        <?php if (empty($locations)): ?>
        <div class="loc-empty">
            <div class="loc-empty-icon"><i class="fas fa-inbox"></i></div>
            <p style="font-size:.9rem;color:var(--text-muted);margin:0;">Keine Standorte vorhanden</p>
        </div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:.75rem;">
            <?php foreach ($locations as $loc): ?>
            <div class="loc-item">
                <div style="display:flex;align-items:center;gap:.625rem;margin-bottom:.25rem;">
                    <i class="fas fa-map-marker-alt" style="color:rgba(124,58,237,1);font-size:.85rem;width:1rem;text-align:center;"></i>
                    <span style="font-weight:700;font-size:.9rem;color:var(--text-main);"><?php echo htmlspecialchars($loc['name']); ?></span>
                </div>
                <?php if ($loc['description']): ?>
                <p style="font-size:.8rem;color:var(--text-muted);margin:.3rem 0 0 1.625rem;line-height:1.4;"><?php echo htmlspecialchars($loc['description']); ?></p>
                <?php endif; ?>
                <?php if ($loc['address']): ?>
                <p style="font-size:.78rem;color:var(--text-muted);margin:.25rem 0 0 1.625rem;">
                    <i class="fas fa-home" style="margin-right:.3rem;opacity:.6;"></i><?php echo nl2br(htmlspecialchars($loc['address'])); ?>
                </p>
                <?php endif; ?>
                <div class="loc-footer">
                    <span>ID: <?php echo $loc['id']; ?></span>
                    <span><?php echo date('d.m.Y', strtotime($loc['created_at'])); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
