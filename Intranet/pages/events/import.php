<?php
/**
 * Events Mass-Import
 * ──────────────────────────────────────────────────────────────────────
 * Flow: 1) JSON-Upload → alle Items werden als Drafts angelegt.
 *       2) Redirect auf ?step=images → pro angelegtem Event kann
 *          optional ein Bild hochgeladen werden.
 *
 * Erlaubte Rollen: Vorstand Intern, Vorstand Extern, Vorstand Finanzen und Recht
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/Event.php';
require_once __DIR__ . '/../../includes/utils/SecureImageUpload.php';

// ── Zugriffskontrolle: nur die drei Vorstandsrollen ─────────────────────
if (!Auth::check() || !Auth::hasRole(['vorstand_intern', 'vorstand_extern', 'vorstand_finanzen'])) {
    header('Location: ../auth/login.php');
    exit;
}

$user    = Auth::user();
$step    = $_GET['step'] ?? 'upload';
$message = '';
$errors  = [];
$summary = null;

// ── Helper: robustes Datum-Parsing ──────────────────────────────────────
function ibc_parse_datetime(?string $value): ?string {
    if ($value === null || $value === '') return null;
    $value = trim($value);
    // Akzeptiere "YYYY-MM-DD HH:MM", "YYYY-MM-DD HH:MM:SS", "YYYY-MM-DDTHH:MM"
    $value = str_replace('T', ' ', $value);
    $ts = strtotime($value);
    if ($ts === false) return null;
    return date('Y-m-d H:i:s', $ts);
}
function ibc_parse_date(?string $value): ?string {
    if ($value === null || $value === '') return null;
    $ts = strtotime(trim($value));
    if ($ts === false) return null;
    return date('Y-m-d', $ts);
}
function ibc_bool($v): int {
    if (is_bool($v))   return $v ? 1 : 0;
    if (is_numeric($v)) return ((int)$v) ? 1 : 0;
    if (is_string($v))  return in_array(strtolower($v), ['1','true','yes','ja','y','on']) ? 1 : 0;
    return 0;
}

// ── POST: Schritt 1 – JSON hochladen & validieren ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'upload') {
    try { CSRFHandler::verifyToken($_POST['csrf_token'] ?? ''); }
    catch (Exception $e) { $errors[] = $e->getMessage(); }

    if (empty($errors)) {
        if (!isset($_FILES['json_file']) || $_FILES['json_file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Bitte eine JSON-Datei auswählen.';
        } elseif ($_FILES['json_file']['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Die JSON-Datei darf maximal 2 MB groß sein.';
        } else {
            $raw  = file_get_contents($_FILES['json_file']['tmp_name']);
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $errors[] = 'JSON konnte nicht geparst werden: ' . json_last_error_msg();
            } elseif (($data['type'] ?? '') !== 'events') {
                $errors[] = 'Das Feld "type" muss "events" sein.';
            } elseif (empty($data['items']) || !is_array($data['items'])) {
                $errors[] = 'Das Feld "items" fehlt oder ist leer.';
            } elseif (count($data['items']) > 200) {
                $errors[] = 'Maximal 200 Events pro Import erlaubt.';
            }
        }
    }

    // Pro Item Pflichtfelder prüfen
    if (empty($errors)) {
        foreach ($data['items'] as $i => $item) {
            $pos = '#' . ($i + 1);
            if (empty($item['title']))      $errors[] = "$pos: title fehlt";
            if (empty($item['start_time'])) $errors[] = "$pos: start_time fehlt";
            if (empty($item['end_time']))   $errors[] = "$pos: end_time fehlt";
            if (!empty($item['start_time']) && !ibc_parse_datetime($item['start_time']))
                $errors[] = "$pos: start_time konnte nicht geparst werden ('{$item['start_time']}')";
            if (!empty($item['end_time']) && !ibc_parse_datetime($item['end_time']))
                $errors[] = "$pos: end_time konnte nicht geparst werden ('{$item['end_time']}')";
            if (!empty($item['status']) && !in_array($item['status'], ['draft','planned','open','closed','running','past'])) {
                $errors[] = "$pos: status '{$item['status']}' ist ungültig";
            }
        }
    }

    // Alle gültig → insert
    if (empty($errors)) {
        $createdIds   = [];
        $createdItems = [];
        foreach ($data['items'] as $i => $item) {
            $payload = [
                'title'                => trim($item['title']),
                'description'          => $item['description']         ?? null,
                'location'             => $item['location']            ?? null,
                'maps_link'            => $item['maps_link']           ?? null,
                'start_time'           => ibc_parse_datetime($item['start_time']),
                'end_time'             => ibc_parse_datetime($item['end_time']),
                'registration_start'   => ibc_parse_datetime($item['registration_start'] ?? null),
                'registration_end'     => ibc_parse_datetime($item['registration_end']   ?? null),
                'status'               => 'draft', // Import-Items starten immer als Draft
                'is_external'          => ibc_bool($item['is_external']          ?? false),
                'external_link'        => $item['external_link']                 ?? null,
                'registration_link'    => $item['registration_link']             ?? null,
                'needs_helpers'        => ibc_bool($item['needs_helpers']        ?? false),
                'is_internal_project'  => ibc_bool($item['is_internal_project']  ?? false),
                'requires_application' => ibc_bool($item['requires_application'] ?? false),
            ];
            try {
                $eventId = Event::create($payload, $user['id'], []);
                $createdIds[]   = (int)$eventId;
                $createdItems[] = ['id' => (int)$eventId, 'title' => $payload['title'], 'image_key' => $item['image_key'] ?? null];
            } catch (Throwable $t) {
                $errors[] = '#' . ($i + 1) . ': Speichern fehlgeschlagen: ' . $t->getMessage();
            }
        }
        if (empty($errors)) {
            $_SESSION['event_import_batch'] = [
                'ids'       => $createdIds,
                'items'     => $createdItems,
                'created_at'=> time(),
            ];
            header('Location: import.php?step=images');
            exit;
        }
    }
}

// ── POST: Schritt 2 – Bilder hochladen ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'images') {
    try { CSRFHandler::verifyToken($_POST['csrf_token'] ?? ''); }
    catch (Exception $e) { $errors[] = $e->getMessage(); }

    $batch = $_SESSION['event_import_batch'] ?? null;
    if (!$batch || empty($batch['ids'])) {
        $errors[] = 'Keine Import-Session aktiv. Bitte erneut eine JSON-Datei hochladen.';
    }

    if (empty($errors) && !empty($_FILES['images']['name']) && is_array($_FILES['images']['name'])) {
        $allowedIds = array_flip($batch['ids']);
        $uploaded   = 0;
        $failed     = 0;
        $db         = Database::getContentDB();
        foreach ($_FILES['images']['name'] as $eventIdRaw => $name) {
            $eventId = (int)$eventIdRaw;
            if (!isset($allowedIds[$eventId])) continue;
            if ($_FILES['images']['error'][$eventIdRaw] !== UPLOAD_ERR_OK) continue;

            $tmpFile = [
                'name'     => $_FILES['images']['name'][$eventIdRaw],
                'type'     => $_FILES['images']['type'][$eventIdRaw],
                'tmp_name' => $_FILES['images']['tmp_name'][$eventIdRaw],
                'error'    => $_FILES['images']['error'][$eventIdRaw],
                'size'     => $_FILES['images']['size'][$eventIdRaw],
            ];
            $up = SecureImageUpload::uploadImage($tmpFile);
            if ($up['success']) {
                $stmt = $db->prepare('UPDATE events SET image_path = ? WHERE id = ?');
                $stmt->execute([$up['path'], $eventId]);
                $uploaded++;
            } else {
                $failed++;
            }
        }
        $summary = compact('uploaded', 'failed');
    }
}

// ── JSON Beispiel-Datei zum Download ────────────────────────────────────
if (isset($_GET['download']) && $_GET['download'] === 'example') {
    $example = [
        'version' => 1,
        'type'    => 'events',
        'items'   => [
            [
                'title'                => 'IBC Business Night 2026',
                'description'          => 'Jahresevent mit Gastrednern, Networking und Dinner.',
                'location'             => 'München, BMW Welt',
                'maps_link'            => 'https://maps.app.goo.gl/beispiel',
                'start_time'           => '2026-06-15 18:00',
                'end_time'             => '2026-06-15 23:00',
                'registration_start'   => '2026-05-01 00:00',
                'registration_end'     => '2026-06-10 23:59',
                'is_external'          => false,
                'needs_helpers'        => true,
                'requires_application' => false,
                'image_key'            => 'business_night',
            ],
            [
                'title'       => 'Workshop: Design Thinking',
                'description' => 'Praxis-Workshop für Mitglieder.',
                'location'    => 'Online',
                'start_time'  => '2026-05-20 18:30',
                'end_time'    => '2026-05-20 20:30',
                'is_external' => false,
                'image_key'   => 'design_thinking',
            ],
        ],
    ];
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="events_import_example.json"');
    echo json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Rendering ───────────────────────────────────────────────────────────
$title = 'Events importieren - IBC Intranet';
$batch = $_SESSION['event_import_batch'] ?? null;
ob_start();
?>
<style>
.imp-shell  { max-width: 1000px; margin: 0 auto; }
.imp-card   { background: var(--bg-card); border: 1.5px solid var(--border-color); border-radius: 1rem; padding: 1.75rem; margin-bottom: 1.5rem; box-shadow: 0 1px 2px rgba(15,23,42,0.04), 0 2px 8px rgba(15,23,42,0.04); }
.imp-h1     { font-size: clamp(1.4rem, 3.2vw, 1.75rem); font-weight: 800; color: var(--text-main); letter-spacing: -0.02em; margin: 0 0 0.25rem; }
.imp-sub    { color: var(--text-muted); font-size: 0.95rem; margin: 0 0 1.25rem; }
.imp-steps  { display: flex; gap: 0.75rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
.imp-step   { flex: 1 1 220px; display: flex; align-items: center; gap: 0.75rem; padding: 0.85rem 1rem; border: 1.5px solid var(--border-color); border-radius: 0.75rem; background: var(--bg-card); min-width: 0; }
.imp-step--active { border-color: var(--ibc-blue); box-shadow: 0 0 0 3px rgba(0,102,179,0.15); }
.imp-step-idx { width: 2rem; height: 2rem; border-radius: 999px; background: #e2e8f0; color: #0f172a; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.875rem; flex-shrink: 0; }
.imp-step--active .imp-step-idx { background: var(--ibc-blue); color: #fff; }
.imp-step-title { font-weight: 700; color: var(--text-main); font-size: 0.9rem; }
.imp-step-desc { color: var(--text-muted); font-size: 0.8rem; }
.imp-drop   { border: 2px dashed #cbd5e1; border-radius: 0.9rem; padding: 2rem 1.25rem; text-align: center; transition: border-color 0.18s, background 0.18s; }
.imp-drop:hover { border-color: var(--ibc-blue); background: rgba(0,102,179,0.04); }
.imp-btn    { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.7rem 1.25rem; border-radius: 0.7rem; font-weight: 700; font-size: 0.9rem; cursor: pointer; border: 1.5px solid transparent; text-decoration: none; transition: transform 0.15s, box-shadow 0.15s, opacity 0.15s; }
.imp-btn--primary   { background: linear-gradient(135deg, var(--ibc-blue), #0044aa); color: #fff; box-shadow: 0 3px 12px rgba(0,102,179,0.3); }
.imp-btn--primary:hover { transform: translateY(-1px); opacity: 0.95; }
.imp-btn--secondary { background: var(--bg-card); border-color: #cbd5e1; color: var(--text-main); }
.imp-btn--secondary:hover { border-color: var(--ibc-blue); color: var(--ibc-blue); }
.imp-alert  { padding: 0.85rem 1rem; border-radius: 0.75rem; margin-bottom: 1rem; font-size: 0.9rem; }
.imp-alert--ok  { background: rgba(16,185,129,0.1); color: #047857; border: 1.5px solid rgba(16,185,129,0.3); }
.imp-alert--err { background: rgba(239,68,68,0.1); color: #b91c1c; border: 1.5px solid rgba(239,68,68,0.3); }
.imp-table  { width: 100%; border-collapse: collapse; margin-top: 0.5rem; }
.imp-table th, .imp-table td { padding: 0.85rem 0.75rem; border-bottom: 1px solid var(--border-color); font-size: 0.9rem; text-align: left; vertical-align: middle; }
.imp-table th { color: var(--text-muted); font-weight: 700; font-size: 0.75rem; letter-spacing: 0.05em; text-transform: uppercase; background: rgba(15,23,42,0.02); }
.imp-imgkey { font-family: ui-monospace, monospace; color: var(--text-muted); font-size: 0.8rem; }
.imp-file   { display: block; }
.imp-file input[type=file] { font-size: 0.85rem; }
.imp-docs   { font-size: 0.875rem; color: var(--text-main); line-height: 1.6; }
.imp-docs code { background: rgba(15,23,42,0.07); padding: 0.1em 0.45em; border-radius: 0.3em; font-size: 0.85em; }
.imp-docs pre { background: #0f172a; color: #e2e8f0; padding: 1rem; border-radius: 0.6rem; overflow: auto; font-size: 0.78rem; }
.dark-mode .imp-docs pre { background: #020617; }
.dark-mode .imp-table th { background: rgba(255,255,255,0.03); }
.imp-chip { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.7rem; padding: 0.18rem 0.55rem; border-radius: 999px; font-weight: 700; letter-spacing: 0.03em; background: rgba(0,102,179,0.12); color: var(--ibc-blue); }
</style>

<div class="imp-shell">
  <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1rem;">
    <a href="index.php" class="imp-btn imp-btn--secondary" style="padding:0.5rem 0.85rem;font-size:0.8rem;">
      <i class="fas fa-arrow-left" aria-hidden="true"></i> Zurück
    </a>
    <span class="imp-chip"><i class="fas fa-file-import" aria-hidden="true"></i> Massenimport</span>
  </div>

  <h1 class="imp-h1">Events importieren</h1>
  <p class="imp-sub">Lade eine JSON-Datei hoch, um mehrere Events in einem Rutsch als Entwurf anzulegen. Bilder kannst Du anschließend pro Event ergänzen.</p>

  <div class="imp-steps" role="list">
    <div class="imp-step <?php echo $step === 'upload' ? 'imp-step--active' : ''; ?>" role="listitem">
      <div class="imp-step-idx">1</div>
      <div style="min-width:0;">
        <div class="imp-step-title">JSON hochladen</div>
        <div class="imp-step-desc">Validiert & legt Entwürfe an</div>
      </div>
    </div>
    <div class="imp-step <?php echo $step === 'images' ? 'imp-step--active' : ''; ?>" role="listitem">
      <div class="imp-step-idx">2</div>
      <div style="min-width:0;">
        <div class="imp-step-title">Bilder zuordnen</div>
        <div class="imp-step-desc">Pro Event optional 1 Bild</div>
      </div>
    </div>
    <div class="imp-step" role="listitem">
      <div class="imp-step-idx">3</div>
      <div style="min-width:0;">
        <div class="imp-step-title">Feinschliff &amp; Publish</div>
        <div class="imp-step-desc">In "Events verwalten" veröffentlichen</div>
      </div>
    </div>
  </div>

  <?php foreach ($errors as $err): ?>
    <div class="imp-alert imp-alert--err"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> <?php echo htmlspecialchars($err); ?></div>
  <?php endforeach; ?>

  <?php if ($summary !== null): ?>
    <div class="imp-alert imp-alert--ok">
      <i class="fas fa-check-circle" aria-hidden="true"></i>
      <?php echo (int)$summary['uploaded']; ?> Bild(er) hochgeladen<?php if ((int)$summary['failed'] > 0) echo ', ' . (int)$summary['failed'] . ' fehlgeschlagen'; ?>.
    </div>
  <?php endif; ?>

<?php if ($step === 'upload'): ?>
  <div class="imp-card">
    <h2 style="margin:0 0 0.5rem;font-size:1.05rem;font-weight:800;color:var(--text-main);">Schritt 1 – JSON hochladen</h2>
    <p style="margin:0 0 1rem;font-size:0.875rem;color:var(--text-muted);">
      Felder: <code>title</code>, <code>start_time</code>, <code>end_time</code> sind Pflicht. Alle Items werden zunächst als <strong>Entwurf</strong> angelegt.
    </p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken()); ?>">
      <div class="imp-drop">
        <i class="fas fa-file-code" style="font-size:2.25rem;color:var(--ibc-blue);margin-bottom:0.5rem;" aria-hidden="true"></i>
        <div style="margin-bottom:0.75rem;color:var(--text-main);font-weight:600;">JSON-Datei auswählen</div>
        <input type="file" name="json_file" accept="application/json,.json" required>
      </div>
      <div style="display:flex;gap:0.6rem;flex-wrap:wrap;justify-content:flex-end;margin-top:1rem;">
        <a class="imp-btn imp-btn--secondary" href="import.php?download=example">
          <i class="fas fa-download" aria-hidden="true"></i> Beispiel-JSON herunterladen
        </a>
        <button type="submit" class="imp-btn imp-btn--primary">
          <i class="fas fa-file-upload" aria-hidden="true"></i> Importieren
        </button>
      </div>
    </form>
  </div>

  <div class="imp-card imp-docs">
    <h2 style="margin:0 0 0.5rem;font-size:1.05rem;font-weight:800;color:var(--text-main);">JSON-Format</h2>
    <p>Pro Event wird ein Objekt im Array <code>items</code> erwartet. <code>start_time</code>/<code>end_time</code> akzeptieren <code>YYYY-MM-DD HH:MM</code> oder ISO-8601.</p>
    <pre>{
  "version": 1,
  "type": "events",
  "items": [
    {
      "title": "IBC Business Night 2026",
      "description": "Jahresevent …",
      "location": "München",
      "maps_link": "https://maps.app.goo.gl/…",
      "start_time": "2026-06-15 18:00",
      "end_time":   "2026-06-15 23:00",
      "registration_start": "2026-05-01 00:00",
      "registration_end":   "2026-06-10 23:59",
      "is_external": false,
      "needs_helpers": true,
      "requires_application": false,
      "image_key": "business_night"
    }
  ]
}</pre>
    <p style="margin-top:0.75rem;">
      <strong>image_key</strong> ist optional und hilft Dir in Schritt 2, die richtigen Bilder zuzuordnen. Alle Items werden als <code>status: "draft"</code> gespeichert.
    </p>
  </div>

<?php elseif ($step === 'images'): ?>
  <?php if (!$batch || empty($batch['ids'])): ?>
    <div class="imp-card">
      <p style="margin:0;color:var(--text-main);">Keine aktive Import-Sitzung gefunden. <a href="import.php" style="color:var(--ibc-blue);font-weight:700;">Erneut starten →</a></p>
    </div>
  <?php else: ?>
    <div class="imp-card">
      <h2 style="margin:0 0 0.5rem;font-size:1.05rem;font-weight:800;color:var(--text-main);">
        Schritt 2 – Bilder zu den <?php echo count($batch['ids']); ?> importierten Events zuordnen
      </h2>
      <p style="margin:0 0 1rem;font-size:0.875rem;color:var(--text-muted);">
        Optional: Du kannst pro Event ein Bild hochladen. Fertig ist erst, wenn Du auf <em>Abschließen</em> klickst.
      </p>

      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(CSRFHandler::getToken()); ?>">

        <div style="overflow-x:auto;">
          <table class="imp-table">
            <thead>
              <tr>
                <th style="width:70px;">#</th>
                <th>Titel</th>
                <th>image_key</th>
                <th style="min-width:240px;">Bild hochladen</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($batch['items'] as $it): ?>
                <?php
                  // aktuellen Stand aus DB holen für Preview-Status
                  $preview = null;
                  try {
                      $dbp = Database::getContentDB();
                      $s = $dbp->prepare('SELECT image_path FROM events WHERE id = ?');
                      $s->execute([$it['id']]);
                      $preview = $s->fetch(PDO::FETCH_ASSOC);
                  } catch (Throwable $t) {}
                ?>
                <tr>
                  <td style="font-family:ui-monospace,monospace;color:var(--text-muted);">#<?php echo (int)$it['id']; ?></td>
                  <td>
                    <div style="font-weight:700;color:var(--text-main);"><?php echo htmlspecialchars($it['title']); ?></div>
                    <a href="edit.php?id=<?php echo (int)$it['id']; ?>" style="font-size:0.78rem;color:var(--ibc-blue);text-decoration:none;">Details öffnen →</a>
                  </td>
                  <td class="imp-imgkey"><?php echo $it['image_key'] ? htmlspecialchars($it['image_key']) : '—'; ?></td>
                  <td>
                    <label class="imp-file">
                      <input type="file" name="images[<?php echo (int)$it['id']; ?>]" accept="image/jpeg,image/png,image/webp,image/gif">
                    </label>
                    <?php if (!empty($preview['image_path'])): ?>
                      <div style="margin-top:0.3rem;font-size:0.75rem;color:#16a34a;"><i class="fas fa-check" aria-hidden="true"></i> Bild gesetzt</div>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div style="display:flex;gap:0.6rem;flex-wrap:wrap;justify-content:space-between;margin-top:1.25rem;">
          <a href="manage.php" class="imp-btn imp-btn--secondary">
            <i class="fas fa-check" aria-hidden="true"></i> Abschließen & zu "Events verwalten"
          </a>
          <button type="submit" class="imp-btn imp-btn--primary">
            <i class="fas fa-images" aria-hidden="true"></i> Bilder speichern
          </button>
        </div>
      </form>
    </div>
  <?php endif; ?>
<?php endif; ?>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../../includes/templates/main_layout.php';
