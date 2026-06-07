<?php
/**
 * Intranet-Modul: AWP-Projekte & Bewerbermanagement
 * Zugriff: Vorstand (intern/extern/finanzen) + ERW-Mitglieder.
 *
 * Funktion A: AWP-Projekte anlegen/verwalten (inkl. automatischer Anlage als
 *             internes Projekt in der allgemeinen Projektliste) + Datei-Manager.
 * Funktion B: Bewerbungen je Projekt einsehen, zuweisen und Bestätigungs-Mail
 *             versenden.
 *
 * Hinweis: Die Tabellen (awp_projekte/bewerber/projekt_bewerbungen) werden vom
 * öffentlichen Karriere-Portal befüllt; beide Apps müssen auf dieselbe DB
 * (hier: Content-DB) zeigen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/AwpProject.php';
require_once __DIR__ . '/../../includes/models/Project.php';
require_once __DIR__ . '/../../includes/utils/SecureImageUpload.php';
require_once __DIR__ . '/../../src/MailService.php';

if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Auth::canAccessAdminArea()) {
    http_response_code(403);
    exit('Zugriff verweigert');
}

$currentUser = Auth::user();
$flash = ['ok' => null, 'err' => null];

// Schema-Anlage ist „best effort": Die Tabellen existieren i. d. R. bereits
// (vom Karriere-Portal importiert). Schlägt CREATE fehl (z. B. fehlendes
// CREATE-Recht), wird nur geloggt – die eigentliche DB-Erreichbarkeit zeigt
// sich an den Datenabfragen weiter unten.
try {
    AwpProject::ensureSchema();
} catch (Throwable $e) {
    error_log('AWP ensureSchema (nicht fatal): ' . $e->getMessage());
}

// Intranet-User für die Dropdowns (QM-Person / Projektleiter)
$intranetUsers = [];
try {
    $uStmt = Database::getUserDB()->query(
        "SELECT id, first_name, last_name FROM users WHERE deleted_at IS NULL ORDER BY first_name, last_name"
    );
    $intranetUsers = $uStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('AWP user list: ' . $e->getMessage());
}
$userName = static function ($id) use ($intranetUsers) {
    foreach ($intranetUsers as $u) {
        if ((int) $u['id'] === (int) $id) {
            return trim($u['first_name'] . ' ' . $u['last_name']);
        }
    }
    return $id ? ('User #' . (int) $id) : '—';
};

/* ── POST-Verarbeitung ──────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($flash['err'])) {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';

    // ── Funktion A: AWP-Projekt anlegen ──────────────────────────────────
    if ($action === 'create_awp') {
        $titel        = clean_awp((string) ($_POST['titel'] ?? ''), 255);
        $beschreibung = clean_awp((string) ($_POST['beschreibung'] ?? ''), 20000);
        $teamgroesse  = (int) ($_POST['teamgroesse'] ?? 0);
        $kunde        = clean_awp((string) ($_POST['kunde'] ?? ''), 255);
        $qmId         = ($_POST['qm_person_id'] ?? '') !== '' ? (int) $_POST['qm_person_id'] : null;
        $leiterId     = ($_POST['projektleiter_id'] ?? '') !== '' ? (int) $_POST['projektleiter_id'] : null;

        $errs = [];
        if ($titel === '')        { $errs[] = 'Titel ist erforderlich.'; }
        if ($beschreibung === '') { $errs[] = 'Beschreibung ist erforderlich.'; }
        if ($teamgroesse < 1)     { $errs[] = 'Teamgröße muss mindestens 1 sein.'; }

        // Uploads (optional)
        $bildPath = null;
        $dateiPath = null;
        if (empty($errs) && isset($_FILES['projekt_bild']) && ($_FILES['projekt_bild']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $imgDir = __DIR__ . '/../../uploads/awp';
            $res = SecureImageUpload::uploadImage($_FILES['projekt_bild'], $imgDir, false);
            if (!$res['success']) { $errs[] = 'Projektbild: ' . $res['error']; }
            else { $bildPath = $res['path']; }
        }
        if (empty($errs) && isset($_FILES['projekt_datei']) && ($_FILES['projekt_datei']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $res = Project::handleDocumentationUpload($_FILES['projekt_datei']);
            if (!$res['success']) { $errs[] = 'Projektdatei: ' . $res['error']; }
            else { $dateiPath = $res['path']; }
        }

        if (empty($errs)) {
            try {
                $awpId = AwpProject::createProject([
                    'titel'            => $titel,
                    'beschreibung'     => $beschreibung,
                    'teamgroesse'      => $teamgroesse,
                    'qm_person_id'     => $qmId,
                    'projektleiter_id' => $leiterId,
                    'kunde'            => $kunde,
                    'projekt_bild'     => $bildPath,
                    'projekt_datei'    => $dateiPath,
                ]);

                // Auto-Verknüpfung: zugleich als internes Projekt anlegen.
                // AWP-Projekte: keine Bewerbung (requires_application=0) und
                // Markierung is_awp=1 (Priorität wird als "AWP" angezeigt).
                try {
                    $newProjId = Project::create([
                        'title'           => $titel,
                        'description'     => $beschreibung,
                        'client_name'     => $kunde ?: 'IBC intern',
                        'type'            => 'internal',
                        'status'          => 'open',
                        'max_consultants' => $teamgroesse,
                        'requires_application' => 0,
                        'image_path'      => $bildPath,
                        'created_by'      => $currentUser['id'] ?? null,
                    ]);
                    if ($newProjId) {
                        Database::getContentDB()
                            ->prepare("UPDATE projects SET is_awp = 1 WHERE id = ?")
                            ->execute([(int) $newProjId]);
                    }
                } catch (Throwable $e) {
                    error_log('AWP auto-link Project::create: ' . $e->getMessage());
                }

                $flash['ok'] = 'AWP-Projekt „' . $titel . '" wurde angelegt (auch als internes Projekt verknüpft).';
            } catch (Throwable $e) {
                error_log('AWP createProject: ' . $e->getMessage());
                $flash['err'] = 'Das Projekt konnte nicht gespeichert werden.';
            }
        } else {
            $flash['err'] = implode(' ', $errs);
        }
    }

    // ── Funktion A: AWP-Projekt bearbeiten ───────────────────────────────
    if ($action === 'edit_awp') {
        $pid          = (int) ($_POST['projekt_id'] ?? 0);
        $titel        = clean_awp((string) ($_POST['titel'] ?? ''), 255);
        $beschreibung = clean_awp((string) ($_POST['beschreibung'] ?? ''), 20000);
        $teamgroesse  = (int) ($_POST['teamgroesse'] ?? 0);
        $kunde        = clean_awp((string) ($_POST['kunde'] ?? ''), 255);
        $qmId         = ($_POST['qm_person_id'] ?? '') !== '' ? (int) $_POST['qm_person_id'] : null;
        $leiterId     = ($_POST['projektleiter_id'] ?? '') !== '' ? (int) $_POST['projektleiter_id'] : null;

        $errs = [];
        if ($pid <= 0)            { $errs[] = 'Ungültiges Projekt.'; }
        if ($titel === '')        { $errs[] = 'Titel ist erforderlich.'; }
        if ($beschreibung === '') { $errs[] = 'Beschreibung ist erforderlich.'; }
        if ($teamgroesse < 1)     { $errs[] = 'Teamgröße muss mindestens 1 sein.'; }

        $bildPath = null;
        $dateiPath = null;
        if (empty($errs) && isset($_FILES['projekt_bild']) && ($_FILES['projekt_bild']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $res = SecureImageUpload::uploadImage($_FILES['projekt_bild'], __DIR__ . '/../../uploads/awp', false);
            if (!$res['success']) { $errs[] = 'Projektbild: ' . $res['error']; } else { $bildPath = $res['path']; }
        }
        if (empty($errs) && isset($_FILES['projekt_datei']) && ($_FILES['projekt_datei']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $res = Project::handleDocumentationUpload($_FILES['projekt_datei']);
            if (!$res['success']) { $errs[] = 'Projektdatei: ' . $res['error']; } else { $dateiPath = $res['path']; }
        }

        if (empty($errs)) {
            try {
                AwpProject::updateProject($pid, [
                    'titel' => $titel, 'beschreibung' => $beschreibung, 'teamgroesse' => $teamgroesse,
                    'qm_person_id' => $qmId, 'projektleiter_id' => $leiterId, 'kunde' => $kunde,
                    'projekt_bild' => $bildPath, 'projekt_datei' => $dateiPath,
                ]);
                $flash['ok'] = 'AWP-Projekt „' . $titel . '" wurde aktualisiert.';
            } catch (Throwable $e) {
                error_log('AWP updateProject: ' . $e->getMessage());
                $flash['err'] = 'Das Projekt konnte nicht gespeichert werden.';
            }
        } else {
            $flash['err'] = implode(' ', $errs);
        }
    }

    // ── Projekt-Status umschalten ────────────────────────────────────────
    if ($action === 'toggle_status') {
        $pid = (int) ($_POST['projekt_id'] ?? 0);
        $new = ($_POST['new_status'] ?? '') === 'geschlossen' ? 'geschlossen' : 'offen';
        if ($pid > 0 && AwpProject::setStatus($pid, $new)) {
            $flash['ok'] = 'Projektstatus aktualisiert.';
        }
    }

    // ── Bewerbungsphase global öffnen/schließen ──────────────────────────
    if ($action === 'toggle_applications') {
        $open = ($_POST['open'] ?? '') === '1';
        try {
            AwpProject::setApplicationsOpen($open);
            $flash['ok'] = $open
                ? 'Bewerbungsphase wurde geöffnet – das Karriere-Portal nimmt wieder Bewerbungen an.'
                : 'Bewerbungsphase wurde geschlossen – das Karriere-Portal nimmt keine neuen Bewerbungen mehr an.';
        } catch (Throwable $e) {
            error_log('AWP toggle_applications: ' . $e->getMessage());
            $flash['err'] = 'Die Bewerbungsphase konnte nicht umgeschaltet werden.';
        }
    }

    // ── Funktion B: Bewerber zuweisen + Bestätigungs-Mail ────────────────
    if ($action === 'assign' || $action === 'reject') {
        $bId = (int) ($_POST['bewerbung_id'] ?? 0);
        $app = $bId > 0 ? AwpProject::getApplication($bId) : null;
        if (!$app) {
            $flash['err'] = 'Bewerbung nicht gefunden.';
        } elseif ($action === 'reject') {
            AwpProject::setApplicationStatus($bId, 'abgelehnt');
            $flash['ok'] = 'Bewerbung als abgelehnt markiert.';
        } else {
            AwpProject::setApplicationStatus($bId, 'zugeordnet');
            // Bestätigungs-Mail mit Projektdetails
            $sent = false;
            try {
                $subject = 'Deine Zuteilung zum AWP-Projekt: ' . $app['projekt_titel'];
                $body = '<p>Hallo ' . htmlspecialchars((string) $app['name'], ENT_QUOTES, 'UTF-8') . ',</p>'
                    . '<p>herzlichen Glückwunsch! Du wurdest dem folgenden AWP-Projekt fest zugewiesen:</p>'
                    . '<p><strong>' . htmlspecialchars((string) $app['projekt_titel'], ENT_QUOTES, 'UTF-8') . '</strong>'
                    . (!empty($app['kunde']) ? '<br>Kunde: ' . htmlspecialchars((string) $app['kunde'], ENT_QUOTES, 'UTF-8') : '')
                    . '</p>'
                    . '<p>Der Vorstand des Instituts für Business Consulting e.V. meldet sich mit den nächsten Schritten bei dir.</p>'
                    . '<p>Mit freundlichen Grüßen<br>Dein IBC-Vorstand</p>';
                $sent = MailService::sendEmail((string) $app['email'], $subject, $body);
            } catch (Throwable $e) {
                error_log('AWP assign mail: ' . $e->getMessage());
            }
            $flash['ok'] = $sent
                ? 'Bewerber zugewiesen und Bestätigungs-Mail versendet.'
                : 'Bewerber zugewiesen. (Bestätigungs-Mail konnte nicht versendet werden – siehe Log.)';
        }
    }

    // PRG
    $_SESSION['awp_flash'] = $flash;
    header('Location: index.php' . (isset($_POST['tab']) ? '?tab=' . urlencode((string) $_POST['tab']) : ''));
    exit;
}

if (!empty($_SESSION['awp_flash'])) {
    $flash = $_SESSION['awp_flash'];
    unset($_SESSION['awp_flash']);
}

/* ── Daten fürs Rendering ───────────────────────────────────────────────── */
$projects = [];
$applications = [];
$applicationsOpen = true;
try {
    $applicationsOpen = AwpProject::applicationsOpen();
    $projects = AwpProject::allProjects();
    $applications = AwpProject::applicationsByProject();
} catch (Throwable $e) {
    error_log('AWP load: ' . $e->getMessage());
    if (empty($flash['err'])) {
        $flash['err'] = 'Die Karriere-Datenbank ist derzeit nicht erreichbar. '
            . 'Bitte DB_KARRIERE_* in der .env prüfen.';
    }
}

$activeTab = ($_GET['tab'] ?? 'projekte') === 'bewerbungen' ? 'bewerbungen' : 'projekte';

// Bearbeiten-Modus: Projekt zum Vorbefüllen laden
$editProject = null;
if (isset($_GET['edit'])) {
    try {
        $editProject = AwpProject::getProject((int) $_GET['edit']);
    } catch (Throwable $e) {
        error_log('AWP edit load: ' . $e->getMessage());
    }
}
$isEdit = $editProject !== null;

/** Kleiner Säuberungs-Helfer (Steuerzeichen entfernen + Länge begrenzen). */
function clean_awp(string $v, int $max): string
{
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    $v = trim($v);
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}

$title = 'AWP-Projekte & Bewerbungen - IBC Intranet';
ob_start();
?>
<style>
.awp-wrap { max-width: 70rem; }
.awp-tabs { display:flex; gap:.5rem; margin-bottom:1.5rem; flex-wrap:wrap; }
.awp-tab { padding:.6rem 1.1rem; border-radius:.75rem; font-weight:700; font-size:.875rem; text-decoration:none;
           border:1.5px solid var(--border-color); color:var(--text-muted); background:var(--bg-card); }
.awp-tab--active { background:linear-gradient(135deg,var(--ibc-blue),var(--ibc-green)); color:#fff; border-color:transparent; }
.awp-card { background:var(--bg-card); border:1.5px solid var(--border-color); border-radius:1rem; padding:1.5rem; margin-bottom:1.5rem; }
.awp-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:1rem; }
.awp-field { display:flex; flex-direction:column; gap:.35rem; margin-bottom:.5rem; }
.awp-field label { font-size:.8rem; font-weight:600; color:var(--text-main); }
.awp-field input, .awp-field select, .awp-field textarea {
    width:100%; padding:.6rem .75rem; border:1px solid var(--border-color); border-radius:.6rem;
    background:var(--bg-input); color:var(--text-main); font-size:16px; }
.awp-flash { padding:.85rem 1.1rem; border-radius:.75rem; margin-bottom:1.25rem; font-size:.9rem; }
.awp-flash--ok { background:rgba(0,166,81,.12); border:1px solid rgba(0,166,81,.35); color:#10b981; }
.awp-flash--err { background:rgba(239,68,68,.12); border:1px solid rgba(239,68,68,.4); color:#fca5a5; }
.awp-btn { display:inline-flex; align-items:center; gap:.4rem; padding:.6rem 1.1rem; border:0; border-radius:.6rem;
           font-weight:700; font-size:.85rem; cursor:pointer; color:#fff; min-height:44px;
           background:linear-gradient(135deg,var(--ibc-blue),var(--ibc-green)); }
.awp-btn--soft { background:transparent; color:var(--text-muted); border:1.5px solid var(--border-color); }
.awp-btn--danger { background:transparent; color:#ef4444; border:1.5px solid rgba(239,68,68,.4); }
.awp-proj { border:1px solid var(--border-color); border-radius:.75rem; padding:1rem; margin-bottom:.85rem; background:var(--bg-input); }
.awp-badge { display:inline-block; padding:.15rem .6rem; border-radius:999px; font-size:.7rem; font-weight:700; }
.awp-badge--open { background:rgba(0,166,81,.15); color:#10b981; }
.awp-badge--closed { background:rgba(107,114,128,.18); color:var(--text-muted); }
.awp-app { border:1px solid var(--border-color); border-radius:.65rem; padding:.85rem 1rem; margin-bottom:.6rem; background:var(--bg-card); }
.awp-app-meta { font-size:.78rem; color:var(--text-muted); display:flex; flex-wrap:wrap; gap:.75rem; margin:.25rem 0; }
.awp-file-link { font-size:.8rem; color:var(--ibc-blue); font-weight:600; text-decoration:none; }
@media (max-width:640px){ .awp-grid{ grid-template-columns:1fr; } }
</style>

<div class="awp-wrap">
    <h1 style="font-size:clamp(1.4rem,1.1rem+1.5vw,1.9rem);font-weight:800;margin:0 0 .35rem;">AWP-Projekte &amp; Bewerbungen</h1>
    <p style="color:var(--text-muted);margin:0 0 1.5rem;">Anwärter-Projekte anlegen, verwalten und Bewerber zuweisen.</p>

    <?php if (!empty($flash['ok'])): ?><div class="awp-flash awp-flash--ok"><?= e($flash['ok']) ?></div><?php endif; ?>
    <?php if (!empty($flash['err'])): ?><div class="awp-flash awp-flash--err"><?= e($flash['err']) ?></div><?php endif; ?>

    <!-- Globale Bewerbungsphase -->
    <div class="awp-card" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;
         border-color:<?= $applicationsOpen ? 'rgba(0,166,81,.4)' : 'rgba(239,68,68,.4)' ?>;">
        <div>
            <div style="font-weight:700;">
                Bewerbungsphase:
                <?php if ($applicationsOpen): ?>
                    <span class="awp-badge awp-badge--open">OFFEN</span>
                <?php else: ?>
                    <span class="awp-badge" style="background:rgba(239,68,68,.18);color:#fca5a5;">GESCHLOSSEN</span>
                <?php endif; ?>
            </div>
            <div style="font-size:.82rem;color:var(--text-muted);margin-top:.2rem;">
                <?= $applicationsOpen
                    ? 'Das öffentliche Karriere-Portal nimmt aktuell Bewerbungen an.'
                    : 'Das öffentliche Karriere-Portal nimmt aktuell KEINE Bewerbungen an.' ?>
            </div>
        </div>
        <form method="POST" style="margin:0;flex-shrink:0;"
              onsubmit="return confirm('<?= $applicationsOpen ? 'Bewerbungsphase wirklich schließen? Das Portal nimmt dann keine Bewerbungen mehr an.' : 'Bewerbungsphase wieder öffnen?' ?>');">
            <input type="hidden" name="csrf_token" value="<?= e(CSRFHandler::getToken()) ?>">
            <input type="hidden" name="action" value="toggle_applications">
            <input type="hidden" name="open" value="<?= $applicationsOpen ? '0' : '1' ?>">
            <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
            <button type="submit" class="awp-btn <?= $applicationsOpen ? 'awp-btn--danger' : '' ?>">
                <i class="fas <?= $applicationsOpen ? 'fa-lock' : 'fa-lock-open' ?>"></i>
                <?= $applicationsOpen ? 'Bewerbungsphase schließen' : 'Bewerbungsphase öffnen' ?>
            </button>
        </form>
    </div>

    <div class="awp-tabs">
        <a class="awp-tab <?= $activeTab === 'projekte' ? 'awp-tab--active' : '' ?>" href="?tab=projekte">Projekte verwalten</a>
        <a class="awp-tab <?= $activeTab === 'bewerbungen' ? 'awp-tab--active' : '' ?>" href="?tab=bewerbungen">Bewerbungen
            <?php $appCount = array_sum(array_map(fn($g) => count($g['bewerbungen']), $applications)); ?>
            <?php if ($appCount): ?>(<?= (int) $appCount ?>)<?php endif; ?>
        </a>
    </div>

    <?php if ($activeTab === 'projekte'): ?>
    <!-- ── Funktion A: Projekt anlegen ─────────────────────────────────── -->
    <div class="awp-card" id="awp-form">
        <h2 style="font-size:1.05rem;font-weight:700;margin:0 0 1rem;">
            <?= $isEdit ? 'AWP-Projekt bearbeiten' : 'Neues AWP-Projekt anlegen' ?>
        </h2>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e(CSRFHandler::getToken()) ?>">
            <input type="hidden" name="action" value="<?= $isEdit ? 'edit_awp' : 'create_awp' ?>">
            <input type="hidden" name="tab" value="projekte">
            <?php if ($isEdit): ?><input type="hidden" name="projekt_id" value="<?= (int) $editProject['id'] ?>"><?php endif; ?>
            <div class="awp-grid">
                <div class="awp-field">
                    <label>Titel *</label>
                    <input type="text" name="titel" required maxlength="255" value="<?= $isEdit ? e($editProject['titel']) : '' ?>">
                </div>
                <div class="awp-field">
                    <label>Kunde</label>
                    <input type="text" name="kunde" maxlength="255" value="<?= $isEdit ? e($editProject['kunde'] ?? '') : '' ?>">
                </div>
                <div class="awp-field">
                    <label>Teamgröße *</label>
                    <input type="number" name="teamgroesse" min="1" max="99" value="<?= $isEdit ? (int) $editProject['teamgroesse'] : 3 ?>" required>
                </div>
                <div class="awp-field">
                    <label>QM-Person</label>
                    <select name="qm_person_id">
                        <option value="">– keine –</option>
                        <?php foreach ($intranetUsers as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= ($isEdit && (int) $editProject['qm_person_id'] === (int) $u['id']) ? 'selected' : '' ?>><?= e(trim($u['first_name'] . ' ' . $u['last_name'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="awp-field">
                    <label>Projektleiter</label>
                    <select name="projektleiter_id">
                        <option value="">– keiner –</option>
                        <?php foreach ($intranetUsers as $u): ?>
                        <option value="<?= (int) $u['id'] ?>" <?= ($isEdit && (int) $editProject['projektleiter_id'] === (int) $u['id']) ? 'selected' : '' ?>><?= e(trim($u['first_name'] . ' ' . $u['last_name'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="awp-field">
                    <label>Projekt-Vorschaubild (jpg/png)<?= $isEdit && !empty($editProject['projekt_bild']) ? ' – vorhanden, leer lassen zum Behalten' : '' ?></label>
                    <input type="file" name="projekt_bild" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                </div>
                <div class="awp-field">
                    <label>Projektdatei / Briefing (PDF)<?= $isEdit && !empty($editProject['projekt_datei']) ? ' – vorhanden, leer lassen zum Behalten' : '' ?></label>
                    <input type="file" name="projekt_datei" accept=".pdf,application/pdf">
                </div>
            </div>
            <div class="awp-field">
                <label>Beschreibung *</label>
                <textarea name="beschreibung" rows="5" required><?= $isEdit ? e($editProject['beschreibung']) : '' ?></textarea>
            </div>
            <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                <button type="submit" class="awp-btn"><?= $isEdit ? 'Änderungen speichern' : 'Projekt anlegen' ?></button>
                <?php if ($isEdit): ?><a href="index.php?tab=projekte" class="awp-btn awp-btn--soft">Abbrechen</a><?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Bestehende Projekte + Datei-Manager -->
    <div class="awp-card">
        <h2 style="font-size:1.05rem;font-weight:700;margin:0 0 1rem;">Bestehende AWP-Projekte (<?= count($projects) ?>)</h2>
        <?php if (empty($projects)): ?>
            <p style="color:var(--text-muted);">Noch keine AWP-Projekte angelegt.</p>
        <?php else: foreach ($projects as $p): ?>
            <div class="awp-proj">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                    <div style="min-width:0;">
                        <strong style="font-size:1rem;"><?= e($p['titel']) ?></strong>
                        <span class="awp-badge <?= $p['status'] === 'offen' ? 'awp-badge--open' : 'awp-badge--closed' ?>"><?= e($p['status']) ?></span>
                        <div class="awp-app-meta">
                            <span>Team: <?= (int) $p['teamgroesse'] ?></span>
                            <?php if (!empty($p['kunde'])): ?><span>Kunde: <?= e($p['kunde']) ?></span><?php endif; ?>
                            <span>QM: <?= e($userName($p['qm_person_id'])) ?></span>
                            <span>Leitung: <?= e($userName($p['projektleiter_id'])) ?></span>
                        </div>
                        <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-top:.35rem;">
                            <?php if (!empty($p['projekt_bild'])): ?>
                                <a class="awp-file-link" href="<?= e(asset($p['projekt_bild'])) ?>" target="_blank" rel="noopener"><i class="fas fa-image"></i> Vorschaubild</a>
                            <?php endif; ?>
                            <?php if (!empty($p['projekt_datei'])): ?>
                                <a class="awp-file-link" href="<?= e(asset($p['projekt_datei'])) ?>" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> Briefing/Datei</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="display:flex;gap:.4rem;flex-shrink:0;flex-wrap:wrap;">
                        <a href="index.php?tab=projekte&edit=<?= (int) $p['id'] ?>#awp-form" class="awp-btn awp-btn--soft">Bearbeiten</a>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="csrf_token" value="<?= e(CSRFHandler::getToken()) ?>">
                            <input type="hidden" name="action" value="toggle_status">
                            <input type="hidden" name="tab" value="projekte">
                            <input type="hidden" name="projekt_id" value="<?= (int) $p['id'] ?>">
                            <input type="hidden" name="new_status" value="<?= $p['status'] === 'offen' ? 'geschlossen' : 'offen' ?>">
                            <button type="submit" class="awp-btn awp-btn--soft"><?= $p['status'] === 'offen' ? 'Schließen' : 'Öffnen' ?></button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <?php else: ?>
    <!-- ── Funktion B: Bewerbermanagement ──────────────────────────────── -->
    <div class="awp-card">
        <h2 style="font-size:1.05rem;font-weight:700;margin:0 0 1rem;">Eingegangene Bewerbungen, gruppiert nach Projekt</h2>
        <?php if (empty($applications)): ?>
            <p style="color:var(--text-muted);">Noch keine Bewerbungen eingegangen.</p>
        <?php else: foreach ($applications as $pid => $group): ?>
            <h3 style="font-size:.95rem;font-weight:800;margin:1.25rem 0 .6rem;border-left:4px solid var(--ibc-green);padding-left:.5rem;">
                <?= e($group['titel']) ?> (<?= count($group['bewerbungen']) ?>)
            </h3>
            <?php foreach ($group['bewerbungen'] as $b): ?>
                <div class="awp-app">
                    <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start;">
                        <div style="min-width:0;flex:1;">
                            <strong><?= e($b['name']) ?></strong>
                            <?php if (!empty($b['prioritaet'])): ?>
                                <span class="awp-badge" style="background:linear-gradient(135deg,var(--ibc-blue),var(--ibc-green));color:#fff;" title="Vom Bewerber gewählte Priorität für dieses Projekt">Prio <?= (int) $b['prioritaet'] ?></span>
                            <?php endif; ?>
                            <span class="awp-badge <?= $b['bewerbung_status'] === 'zugeordnet' ? 'awp-badge--open' : 'awp-badge--closed' ?>"><?= e($b['bewerbung_status']) ?></span>
                            <div class="awp-app-meta">
                                <span><i class="fas fa-envelope"></i> <?= e($b['email']) ?></span>
                                <?php if (!empty($b['telefon'])): ?><span><i class="fas fa-phone"></i> <?= e($b['telefon']) ?></span><?php endif; ?>
                                <?php if (!empty($b['studiengang'])): ?><span><?= e($b['studiengang']) ?>, <?= (int) $b['semester'] ?>. Sem.</span><?php endif; ?>
                                <?php if (!empty($b['alter_jahre'])): ?><span><?= (int) $b['alter_jahre'] ?> Jahre</span><?php endif; ?>
                            </div>
                            <div style="display:flex;gap:1rem;flex-wrap:wrap;margin:.3rem 0;">
                                <?php if (!empty($b['profilbild_pfad'])): ?>
                                    <a class="awp-file-link" target="_blank" rel="noopener" href="<?= e(asset('api/awp_download.php')) ?>?f=<?= urlencode($b['profilbild_pfad']) ?>"><i class="fas fa-id-badge"></i> Profilbild</a>
                                <?php endif; ?>
                                <?php if (!empty($b['lebenslauf_pdf_pfad'])): ?>
                                    <a class="awp-file-link" target="_blank" rel="noopener" href="<?= e(asset('api/awp_download.php')) ?>?f=<?= urlencode($b['lebenslauf_pdf_pfad']) ?>"><i class="fas fa-file-pdf"></i> Lebenslauf (PDF)</a>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($b['lebenslauf_text'])): ?>
                                <details style="margin:.3rem 0;"><summary style="cursor:pointer;font-size:.8rem;color:var(--ibc-blue);font-weight:600;">Lebenslauf (Text)</summary>
                                    <div style="white-space:pre-wrap;font-size:.82rem;color:var(--text-main);margin-top:.35rem;"><?= e($b['lebenslauf_text']) ?></div>
                                </details>
                            <?php endif; ?>
                            <details style="margin:.3rem 0;"><summary style="cursor:pointer;font-size:.8rem;color:var(--ibc-blue);font-weight:600;">Motivationsschreiben</summary>
                                <div style="white-space:pre-wrap;font-size:.82rem;color:var(--text-main);margin-top:.35rem;"><?= e($b['motivationsschreiben']) ?></div>
                            </details>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:.4rem;flex-shrink:0;">
                            <?php if ($b['bewerbung_status'] !== 'zugeordnet'): ?>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('Bewerber fest zuweisen und Bestätigungs-Mail senden?');">
                                <input type="hidden" name="csrf_token" value="<?= e(CSRFHandler::getToken()) ?>">
                                <input type="hidden" name="action" value="assign">
                                <input type="hidden" name="tab" value="bewerbungen">
                                <input type="hidden" name="bewerbung_id" value="<?= (int) $b['bewerbung_id'] ?>">
                                <button type="submit" class="awp-btn"><i class="fas fa-user-check"></i> Zuweisen</button>
                            </form>
                            <?php endif; ?>
                            <?php if ($b['bewerbung_status'] !== 'abgelehnt'): ?>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('Bewerbung ablehnen?');">
                                <input type="hidden" name="csrf_token" value="<?= e(CSRFHandler::getToken()) ?>">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="tab" value="bewerbungen">
                                <input type="hidden" name="bewerbung_id" value="<?= (int) $b['bewerbung_id'] ?>">
                                <button type="submit" class="awp-btn awp-btn--danger">Ablehnen</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endforeach; endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
