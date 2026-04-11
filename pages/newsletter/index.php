<?php
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Newsletter.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

$currentUser = Auth::user();
$canManage   = Newsletter::canManage($currentUser['role'] ?? '');
$error       = null;

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload' && $canManage) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

    $nlTitle   = trim($_POST['title'] ?? '');
    $monthYear = trim($_POST['month_year'] ?? '');

    if ($nlTitle === '') {
        $error = 'Bitte geben Sie einen Titel an.';
    } elseif (!isset($_FILES['newsletter_file']) || $_FILES['newsletter_file']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Bitte wählen Sie eine Datei aus.';
    } else {
        $file = $_FILES['newsletter_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = 'Fehler beim Hochladen der Datei (Code ' . $file['error'] . ').';
        } elseif ($file['size'] > 20971520) {
            $error = 'Die Datei überschreitet die maximale Größe von 20 MB.';
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['eml'], true)) {
                $error = 'Nur .eml-Dateien sind erlaubt.';
            } else {
                $uploadDir   = __DIR__ . '/../../uploads/newsletters/';
                $filename    = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $destination = $uploadDir . $filename;

                if (!move_uploaded_file($file['tmp_name'], $destination)) {
                    $error = 'Die Datei konnte nicht gespeichert werden.';
                } else {
                    try {
                        Newsletter::create([
                            'title'       => $nlTitle,
                            'month_year'  => $monthYear !== '' ? $monthYear : null,
                            'file_path'   => $filename,
                            'uploaded_by' => $currentUser['id'],
                        ]);
                        $_SESSION['success_message'] = 'Newsletter erfolgreich hochgeladen.';
                        header('Location: index.php');
                        exit;
                    } catch (Exception $e) {
                        @unlink($destination);
                        $error = 'Fehler beim Speichern in der Datenbank.';
                    }
                }
            }
        }
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && $canManage) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    $deleteId = (int) ($_POST['newsletter_id'] ?? 0);
    if ($deleteId > 0) {
        try {
            Newsletter::delete($deleteId);
            $_SESSION['success_message'] = 'Newsletter erfolgreich gelöscht.';
        } catch (Exception $e) {
            $_SESSION['error_message'] = 'Fehler beim Löschen des Newsletters.';
        }
    }
    header('Location: index.php');
    exit;
}

$newsletters = [];
try {
    $newsletters = Newsletter::getAll();
} catch (Exception $e) {
    $_SESSION['error_message'] = 'Fehler beim Laden der Newsletter.';
}

$title = 'Newsletter - IBC Intranet';
ob_start();

// Group newsletters by year for archive display
$grouped = [];
foreach ($newsletters as $nl) {
    $year = !empty($nl['created_at']) ? date('Y', strtotime($nl['created_at'])) : 'Archiv';
    $grouped[$year][] = $nl;
}
krsort($grouped);
?>
<style>
/* ── Newsletter Page ─────────────────────────────────────── */
.nl-page-header {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 2rem;
}

.nl-header-icon {
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
    font-size: 1.2rem;
}

.nl-page-title {
    font-size: 1.625rem;
    font-weight: 800;
    color: var(--text-main);
    letter-spacing: -0.02em;
    line-height: 1.2;
    margin: 0;
}

.nl-page-subtitle {
    font-size: 0.875rem;
    color: var(--text-muted);
    margin: 0.125rem 0 0;
}

.nl-page-count {
    display: inline-flex;
    align-items: center;
    font-size: 0.7rem;
    font-weight: 700;
    padding: 0.15rem 0.5rem;
    border-radius: 9999px;
    background: rgba(0,102,179,0.1);
    color: var(--ibc-blue);
}

.nl-card {
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 0.875rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1rem 1.125rem;
    transition: border-color 0.2s, box-shadow 0.22s, transform 0.22s cubic-bezier(.22,.68,0,1.2);
    position: relative;
    overflow: hidden;
}

.nl-card:hover {
    border-color: var(--ibc-blue);
    box-shadow: var(--shadow-card-hover);
    transform: translateY(-2px);
}

.nl-icon {
    width: 2.75rem;
    height: 2.75rem;
    border-radius: 0.75rem;
    background: rgba(0,102,179,0.08);
    border: 1.5px solid rgba(0,102,179,0.14);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--ibc-blue);
    font-size: 1rem;
    flex-shrink: 0;
    transition: background 0.2s, transform 0.2s;
}

.nl-card:hover .nl-icon {
    background: rgba(0,102,179,0.14);
    transform: scale(1.06);
}

.nl-month-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    padding: 0.18rem 0.5rem;
    border-radius: 9999px;
    background: rgba(0,166,81,0.1);
    color: var(--ibc-green);
    border: 1px solid rgba(0,166,81,0.2);
    margin-bottom: 0.2rem;
}

.nl-year-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin: 1.875rem 0 0.75rem;
}

.nl-year-header:first-child {
    margin-top: 0;
}

.nl-year-label {
    font-size: 0.75rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--text-muted);
    white-space: nowrap;
}

.nl-year-line {
    flex: 1;
    height: 1px;
    background: var(--border-color);
}

.nl-year-count {
    font-size: 0.7rem;
    font-weight: 700;
    color: var(--text-muted);
    background: var(--bg-body);
    border: 1px solid var(--border-color);
    padding: 0.1rem 0.45rem;
    border-radius: 9999px;
    white-space: nowrap;
}

.nl-upload-card {
    background: var(--bg-card);
    border: 1.5px solid var(--border-color);
    border-radius: 1rem;
    padding: 1.375rem 1.5rem;
    margin-bottom: 2rem;
    box-shadow: var(--shadow-card);
}

.nl-form-input {
    width: 100%;
    background: var(--bg-body);
    border: 1.5px solid var(--border-color);
    border-radius: 0.625rem;
    padding: 0.625rem 0.875rem;
    font-size: 0.875rem;
    color: var(--text-main);
    transition: border-color 0.18s, box-shadow 0.18s;
    outline: none;
    -webkit-appearance: none;
    min-height: 44px;
}

.nl-form-input:focus {
    border-color: var(--ibc-blue);
    box-shadow: 0 0 0 3px rgba(0,102,179,0.1);
}

.nl-form-input::placeholder {
    color: var(--text-muted);
    opacity: 0.7;
}

.nl-file-zone {
    width: 100%;
    background: var(--bg-body);
    border: 2px dashed var(--border-color);
    border-radius: 0.75rem;
    padding: 1.125rem 1rem;
    font-size: 0.875rem;
    color: var(--text-muted);
    cursor: pointer;
    transition: border-color 0.18s, background 0.18s;
    display: block;
    min-height: 44px;
}

.nl-file-zone:hover {
    border-color: var(--ibc-blue);
    background: rgba(0,102,179,0.03);
}

.nl-submit-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 1.375rem;
    background: linear-gradient(135deg, var(--ibc-blue), #0088ee);
    color: #fff;
    border: none;
    border-radius: 0.625rem;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    transition: opacity 0.18s, transform 0.22s cubic-bezier(.22,.68,0,1.2);
    box-shadow: 0 2px 10px rgba(0,102,179,0.25);
    -webkit-tap-highlight-color: transparent;
    min-height: 44px;
}

.nl-submit-btn:hover {
    opacity: 0.9;
    transform: translateY(-2px);
}

.nl-submit-btn:active {
    transform: translateY(0);
}

.nl-action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    padding: 0.5rem 0.875rem;
    border-radius: 0.5rem;
    font-size: 0.8125rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: opacity 0.18s, transform 0.18s;
    white-space: nowrap;
    border: none;
    min-height: 44px;
}

.nl-open-btn {
    background: var(--ibc-blue);
    color: #fff;
}

.nl-open-btn:hover {
    opacity: 0.88;
    color: #fff;
}

.nl-delete-btn {
    width: 2.25rem;
    height: 2.25rem;
    padding: 0;
    background: rgba(239,68,68,0.07);
    color: #ef4444;
    border: 1.5px solid rgba(239,68,68,0.18);
}

.nl-delete-btn:hover {
    background: rgba(239,68,68,0.14);
    border-color: rgba(239,68,68,0.35);
}

.nl-flash {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.875rem 1.125rem;
    border-radius: 0.75rem;
    font-size: 0.875rem;
    font-weight: 500;
    margin-bottom: 1.25rem;
    border: 1.5px solid;
}

.nl-flash--success {
    background: rgba(0,166,81,0.08);
    border-color: rgba(0,166,81,0.2);
    color: var(--ibc-green);
}

.nl-flash--error {
    background: rgba(239,68,68,0.08);
    border-color: rgba(239,68,68,0.2);
    color: #ef4444;
}

@keyframes nlCardIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: none;
    }
}

.nl-card {
    animation: nlCardIn 0.28s ease both;
}

.nl-card:nth-child(2) {
    animation-delay: 0.04s;
}

.nl-card:nth-child(3) {
    animation-delay: 0.08s;
}

.nl-card:nth-child(4) {
    animation-delay: 0.12s;
}

.nl-card:nth-child(5) {
    animation-delay: 0.16s;
}

.nl-card:nth-child(6) {
    animation-delay: 0.20s;
}

.nl-card:nth-child(n+7) {
    animation-delay: 0.24s;
}

@media (max-width: 600px) {
    .nl-upload-grid {
        grid-template-columns: 1fr !important;
    }

    .nl-card {
        gap: 0.75rem;
        padding: 0.875rem;
    }

    .nl-card-actions {
        flex-direction: column;
        gap: 0.375rem;
        align-items: stretch !important;
    }

    .nl-open-btn {
        justify-content: center;
    }

    .nl-delete-btn {
        width: 100%;
        height: 2.375rem;
    }
}
</style>

<!-- ── Page Header ─────────────────────────────────────────── -->
<div class="nl-page-header">
    <div class="nl-header-icon">
        <i class="fas fa-envelope-open-text" aria-hidden="true"></i>
    </div>
    <div>
        <h1 class="nl-page-title">Newsletter</h1>
        <p class="nl-page-subtitle">
            Archiv aller versendeten IBC-Newsletter
            <?php if (!empty($newsletters)): ?>
            <span class="nl-page-count"><?php echo count($newsletters); ?></span>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php /* Flash messages */ ?>
<?php if (isset($_SESSION['success_message'])): ?>
<div class="nl-flash nl-flash--success"><i class="fas fa-check-circle" style="flex-shrink:0;"></i><span><?php echo htmlspecialchars($_SESSION['success_message']); ?></span></div>
<?php unset($_SESSION['success_message']); endif; ?>
<?php if (isset($_SESSION['error_message'])): ?>
<div class="nl-flash nl-flash--error"><i class="fas fa-exclamation-circle" style="flex-shrink:0;"></i><span><?php echo htmlspecialchars($_SESSION['error_message']); ?></span></div>
<?php unset($_SESSION['error_message']); endif; ?>
<?php if ($error): ?>
<div class="nl-flash nl-flash--error"><i class="fas fa-exclamation-circle" style="flex-shrink:0;"></i><span><?php echo htmlspecialchars($error); ?></span></div>
<?php endif; ?>

<?php if ($canManage): ?>
<!-- ── Upload Form ─────────────────────────────────────────── -->
<div class="nl-upload-card">
    <h2 style="font-size:1rem;font-weight:700;color:var(--text-main);display:flex;align-items:center;gap:0.5rem;margin:0 0 1.25rem;">
        <i class="fas fa-cloud-upload-alt" style="color:var(--ibc-blue);font-size:0.9375rem;" aria-hidden="true"></i>
        Newsletter hochladen
    </h2>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
        <input type="hidden" name="action" value="upload">
        <div class="nl-upload-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label for="nl_title" style="display:block;font-size:0.8125rem;font-weight:600;color:var(--text-main);margin-bottom:0.375rem;">
                    Titel <span style="color:#ef4444;">*</span>
                </label>
                <input type="text" id="nl_title" name="title" required
                       value="<?php echo htmlspecialchars($_POST['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                       placeholder="z. B. IBC Newsletter März 2025"
                       class="nl-form-input">
            </div>
            <div>
                <label for="nl_month_year" style="display:block;font-size:0.8125rem;font-weight:600;color:var(--text-main);margin-bottom:0.375rem;">
                    Monat / Jahr&nbsp;<span style="color:var(--text-muted);font-weight:400;">(optional)</span>
                </label>
                <input type="text" id="nl_month_year" name="month_year"
                       value="<?php echo htmlspecialchars($_POST['month_year'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                       placeholder="z. B. März 2025"
                       class="nl-form-input">
            </div>
            <div style="grid-column:1/-1;">
                <label for="nl_file" style="display:block;font-size:0.8125rem;font-weight:600;color:var(--text-main);margin-bottom:0.375rem;">
                    Datei <span style="color:#ef4444;">*</span>
                </label>
                <input type="file" id="nl_file" name="newsletter_file" required accept=".eml" class="nl-file-zone">
                <p style="margin-top:0.375rem;font-size:0.75rem;color:var(--text-muted);">
                    <i class="fas fa-info-circle" aria-hidden="true"></i>
                    Erlaubt: <strong>.eml</strong> &ndash; Max. 20 MB
                </p>
            </div>
            <div style="grid-column:1/-1;padding-top:0.25rem;">
                <button type="submit" class="nl-submit-btn">
                    <i class="fas fa-upload" aria-hidden="true"></i>
                    Newsletter hochladen
                </button>
            </div>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if (empty($newsletters)): ?>
<!-- ── Empty State ─────────────────────────────────────────── -->
<div style="background:var(--bg-card);border:1.5px solid var(--border-color);border-radius:1rem;padding:4rem 2rem;text-align:center;">
    <div style="width:4.5rem;height:4.5rem;margin:0 auto 1.25rem;border-radius:50%;background:rgba(0,102,179,0.07);border:1.5px solid rgba(0,102,179,0.12);display:flex;align-items:center;justify-content:center;">
        <i class="fas fa-envelope-open" style="font-size:1.75rem;color:var(--text-muted);" aria-hidden="true"></i>
    </div>
    <p style="font-weight:700;color:var(--text-main);font-size:1.0625rem;margin:0 0 0.375rem;">Noch keine Newsletter vorhanden</p>
    <p style="font-size:0.875rem;color:var(--text-muted);margin:0;">Hochgeladene Newsletter erscheinen hier im Archiv.</p>
</div>

<?php else: ?>
<!-- ── Newsletter Archive ──────────────────────────────────── -->
<?php foreach ($grouped as $year => $items): ?>
<div class="nl-year-header">
    <span class="nl-year-label"><?php echo htmlspecialchars($year); ?></span>
    <div class="nl-year-line"></div>
    <span class="nl-year-count"><?php echo count($items); ?> Eintr<?php echo count($items) === 1 ? 'ag' : 'äge'; ?></span>
</div>
<div style="display:flex;flex-direction:column;gap:0.5rem;margin-bottom:0.5rem;">
    <?php foreach ($items as $nl):
        $nlId      = (int)($nl['id'] ?? 0);
        $monthYear = $nl['month_year'] ?? null;
        $createdAt = isset($nl['created_at']) ? date('d.m.Y', strtotime($nl['created_at'])) : '';
    ?>
    <div class="nl-card">
        <div class="nl-icon">
            <i class="fas fa-envelope-open-text" aria-hidden="true"></i>
        </div>
        <div style="flex:1;min-width:0;">
            <?php if ($monthYear): ?>
            <div class="nl-month-pill">
                <i class="fas fa-calendar-alt" style="font-size:0.6rem;" aria-hidden="true"></i>
                <?php echo htmlspecialchars($monthYear, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php endif; ?>
            <h3 style="font-size:0.9375rem;font-weight:700;color:var(--text-main);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin:0;">
                <?php echo htmlspecialchars($nl['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
            </h3>
            <p style="font-size:0.725rem;color:var(--text-muted);margin:0.15rem 0 0;">
                <i class="fas fa-clock" style="font-size:0.625rem;" aria-hidden="true"></i>
                Hochgeladen am <?php echo $createdAt; ?>
            </p>
        </div>
        <div class="nl-card-actions" style="display:flex;align-items:center;gap:0.5rem;flex-shrink:0;">
            <a href="view.php?id=<?php echo $nlId; ?>" class="nl-action-btn nl-open-btn">
                <i class="fas fa-eye" aria-hidden="true"></i>
                Öffnen
            </a>
            <?php if ($canManage): ?>
            <form method="POST" action="index.php"
                  data-confirm="Newsletter „<?php echo htmlspecialchars($nl['title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" wirklich löschen?"
                  class="delete-form" style="display:contents;">
                <input type="hidden" name="csrf_token" value="<?php echo CSRFHandler::getToken(); ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="newsletter_id" value="<?php echo $nlId; ?>">
                <button type="submit" class="nl-action-btn nl-delete-btn" aria-label="Löschen">
                    <i class="fas fa-trash" aria-hidden="true"></i>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<script>
document.querySelectorAll('.delete-form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        var msg = this.dataset.confirm || 'Wirklich löschen?';
        if (!confirm(msg)) { e.preventDefault(); }
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../../includes/templates/main_layout.php';
