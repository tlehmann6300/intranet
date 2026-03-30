<?php
/**
 * Newsletter Render Endpoint
 *
 * Parses and renders the content of a stored .eml newsletter file
 * directly in the browser. Inline (CID-referenced) images are converted to
 * base64 data-URIs so the preview is self-contained and no external requests
 * are needed.
 *
 * Security
 * ────────
 * • Authentication required (same rule as every other newsletter page).
 * • Path-traversal guard: resolved file path must stay inside the newsletters
 *   upload folder.
 * • Output is wrapped in a full HTML5 document so the browser enforces a
 *   well-defined content model.
 * • X-Frame-Options is set to SAMEORIGIN so the document can only be embedded
 *   as an iframe on the same origin (the view.php page).
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/Database.php';
require_once __DIR__ . '/../../includes/models/Newsletter.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use ZBateson\MailMimeParser\Message;

// ── 1. Authentication ────────────────────────────────────────────────────────
if (!Auth::check()) {
    http_response_code(401);
    exit('Nicht authentifiziert.');
}

// ── 2. Validate request parameter ────────────────────────────────────────────
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    exit('Ungültige Newsletter-ID.');
}

// ── 3. Load newsletter record via model ───────────────────────────────────────
$newsletter = Newsletter::getById($id);

if (!$newsletter) {
    http_response_code(404);
    exit('Newsletter nicht gefunden.');
}

// ── 4. Resolve and validate the file path ────────────────────────────────────
$newslettersDir = realpath(__DIR__ . '/../../uploads/newsletters');

if ($newslettersDir === false) {
    http_response_code(500);
    exit('Server-Konfigurationsfehler.');
}

$safeBasename = basename($newsletter['file_path'] ?? '');
if ($safeBasename === '') {
    http_response_code(404);
    exit('Datei nicht gefunden.');
}

$fullPath = realpath($newslettersDir . DIRECTORY_SEPARATOR . $safeBasename);

if ($fullPath === false || !str_starts_with($fullPath, $newslettersDir . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('Datei nicht gefunden.');
}

// ── 5. Parse the EML file ─────────────────────────────────────────────────────
$handle = fopen($fullPath, 'r');
if ($handle === false) {
    http_response_code(500);
    exit('Datei konnte nicht geöffnet werden.');
}
$message = Message::from($handle, true);

$htmlContent = $message->getHtmlContent();

// ── 6. Replace CID image references with inline base64 data-URIs ─────────────
if ($htmlContent !== null) {
    $attachmentCount = $message->getAttachmentCount();
    for ($i = 0; $i < $attachmentCount; $i++) {
        $part      = $message->getAttachmentPart($i);
        $contentId = $part->getHeaderValue('Content-ID');
        if ($contentId === null || $contentId === '') {
            continue;
        }
        // Content-IDs are often wrapped in angle brackets: <id@domain>
        $contentId = trim($contentId, '<>');
        if ($contentId === '') {
            continue;
        }

        $rawMime = $part->getHeaderValue('Content-Type') ?? 'application/octet-stream';
        // Strip parameters (e.g. "; name=...") from the MIME type
        if (($semi = strpos($rawMime, ';')) !== false) {
            $rawMime = trim(substr($rawMime, 0, $semi));
        }

        $content = $part->getContent();
        if ($content === null || $content === '') {
            continue;
        }

        $dataUri     = 'data:' . $rawMime . ';base64,' . base64_encode($content);
        $htmlContent = str_replace('cid:' . $contentId, $dataUri, $htmlContent);
    }
}

// ── 7. Send response ──────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// Allow this document to be embedded as an iframe on the same origin only.
// Explicit headers ensure this page can always be framed same-origin,
// independent of future changes to the global security_headers.php defaults.
header('X-Frame-Options: SAMEORIGIN');
header('Content-Security-Policy: default-src \'none\'; img-src data: blob:; style-src \'unsafe-inline\'; frame-ancestors \'self\'');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
  body { margin: 0; padding: 12px; }
  img  { max-width: 100%; height: auto; }
  pre  { font-family: sans-serif; white-space: pre-wrap; word-wrap: break-word; padding: 16px; }
</style>
</head>
<body>
<?php
if ($htmlContent !== null) {
    echo $htmlContent;
} else {
    $textContent = $message->getTextContent() ?? '';
    echo '<pre>';
    echo htmlspecialchars($textContent, ENT_QUOTES, 'UTF-8');
    echo '</pre>';
}
?>
</body>
</html>
