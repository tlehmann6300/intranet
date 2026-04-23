<?php
/**
 * API: Download a single attachment from a stored newsletter (.eml) file.
 *
 * Parses the stored email on-the-fly using MailMimeParser and streams the
 * requested attachment (identified by its 0-based index in the attachment
 * part list) to the browser.
 *
 * Access rules
 * ────────────
 * 1. User must be authenticated (HTTP 401 otherwise).
 * 2. Newsletter must exist (HTTP 404 otherwise).
 * 3. File must be present on disk (HTTP 404 otherwise).
 * 4. Path-traversal guard: resolved path must be inside the newsletters folder.
 * 5. Attachment index must be valid (HTTP 400 / 404 otherwise).
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../includes/models/Newsletter.php';
require_once __DIR__ . '/../vendor/autoload.php';

use ZBateson\MailMimeParser\Message;

// ── 1. Authentication ────────────────────────────────────────────────────────
if (!Auth::check()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Nicht authentifiziert']);
    exit;
}

// ── 2. Validate request parameters ───────────────────────────────────────────
$newsletterId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($newsletterId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Ungültige Newsletter-ID']);
    exit;
}

if (!isset($_GET['index']) || !ctype_digit((string) $_GET['index'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Ungültiger Anhang-Index']);
    exit;
}
$attachmentIndex = (int) $_GET['index'];
if ($attachmentIndex < 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Ungültiger Anhang-Index']);
    exit;
}

// ── 3. Load newsletter record ─────────────────────────────────────────────────
$newsletter = Newsletter::getById($newsletterId);
if (!$newsletter) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Newsletter nicht gefunden']);
    exit;
}

// ── 4. Resolve and validate the file path ────────────────────────────────────
$newslettersDir = realpath(__DIR__ . '/../uploads/newsletters');

if ($newslettersDir === false) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Server-Konfigurationsfehler']);
    exit;
}

$safeBasename = basename($newsletter['file_path'] ?? '');
if ($safeBasename === '') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Datei nicht gefunden']);
    exit;
}

$fullPath = realpath($newslettersDir . DIRECTORY_SEPARATOR . $safeBasename);

if ($fullPath === false || !str_starts_with($fullPath, $newslettersDir . DIRECTORY_SEPARATOR)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Zugriff verweigert']);
    exit;
}

if (!is_file($fullPath)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Datei nicht gefunden']);
    exit;
}

// ── 5. Parse the email and retrieve the attachment ────────────────────────────
$handle = fopen($fullPath, 'r');
if ($handle === false) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Datei konnte nicht geöffnet werden']);
    exit;
}

try {
    $message = Message::from($handle, true);
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'E-Mail konnte nicht geparst werden']);
    exit;
}

$part = $message->getAttachmentPart($attachmentIndex);

if ($part === null) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Anhang nicht gefunden']);
    exit;
}

// ── 6. Determine filename and MIME type ───────────────────────────────────────
$filename = $part->getFilename();
if ($filename === null || $filename === '') {
    $filename = 'Anhang_' . ($attachmentIndex + 1);
}
// Sanitise: keep only safe characters for a Content-Disposition filename
$filename = preg_replace('/[^\w\s.\-()]/u', '_', $filename);
$filename = trim($filename);
if ($filename === '') {
    $filename = 'Anhang_' . ($attachmentIndex + 1);
}

$rawMime = $part->getHeaderValue('Content-Type') ?? 'application/octet-stream';
// Strip parameters (e.g. "; name=...") from the MIME type
if (($semi = strpos($rawMime, ';')) !== false) {
    $rawMime = trim(substr($rawMime, 0, $semi));
}
// Fallback to safe MIME type if empty
if ($rawMime === '') {
    $rawMime = 'application/octet-stream';
}

// ── 7. Stream the attachment content ─────────────────────────────────────────
$content = $part->getContent();
if ($content === null) {
    $content = '';
}

header('Content-Type: ' . $rawMime);
header('Content-Disposition: attachment; filename="' . str_replace('"', '\\"', $filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Content-Length: ' . strlen($content));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

echo $content;
exit;
