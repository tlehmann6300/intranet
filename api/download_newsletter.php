<?php
/**
 * API: Download Newsletter File
 *
 * Serves the uploaded newsletter (.eml) for a given newsletter ID.
 * All authenticated users may download newsletters.
 *
 * Access rules
 * ────────────
 * 1. User must be authenticated (HTTP 401 otherwise).
 * 2. Newsletter must exist (HTTP 404 otherwise).
 * 3. File must be present on disk (HTTP 404 otherwise).
 * 4. Path-traversal guard: resolved path must be inside the newsletters folder.
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../includes/models/Newsletter.php';

// ── 1. Authentication ────────────────────────────────────────────────────────
if (!Auth::check()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Nicht authentifiziert']);
    exit;
}

// ── 2. Validate request parameter ────────────────────────────────────────────
$newsletterId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($newsletterId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Ungültige Newsletter-ID']);
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

// Strip any directory components from the stored filename to prevent path traversal
$safeBasename = basename($newsletter['file_path'] ?? '');
if ($safeBasename === '') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Datei nicht gefunden']);
    exit;
}

$fullPath = realpath($newslettersDir . DIRECTORY_SEPARATOR . $safeBasename);

// Path-traversal guard: block if the path could not be resolved or escapes the newsletters folder
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

// ── 5. Determine extension and MIME type ─────────────────────────────────────
$ext = strtolower(pathinfo($safeBasename, PATHINFO_EXTENSION));

// Only allow the whitelisted extensions even if something unexpected ends up in the folder
if (!in_array($ext, Newsletter::ALLOWED_EXTENSIONS, true)) {
    http_response_code(415);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Ungültiger Dateityp']);
    exit;
}

$mimeMap = [
    'eml' => 'message/rfc822',
];
$mimeType = $mimeMap[$ext] ?? 'application/octet-stream';

// Build a safe download filename from the stored title
$originalFilename = basename($newsletter['title'] ?? ('newsletter_' . $newsletterId)) . '.' . $ext;

// ── 6. Stream the file ────────────────────────────────────────────────────────
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . rawurlencode($originalFilename) . '"');
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

readfile($fullPath);
exit;
