<?php
/**
 * API: Download Invoice File
 *
 * Serves the uploaded invoice document (PDF or image) for a given invoice ID.
 * IDOR protection: only the invoice owner or a privileged role may access the file.
 *
 * Access rules
 * ────────────
 * 1. User must be authenticated (HTTP 401 otherwise).
 * 2. Invoice must exist (HTTP 404 otherwise).
 * 3a. The invoice's user_id matches the current session user  → allowed.
 * 3b. The current user holds a privileged role                → allowed.
 * 3c. Neither condition is met                               → HTTP 403.
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../includes/models/Invoice.php';

// ── 1. Authentication ────────────────────────────────────────────────────────
if (!Auth::check()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Nicht authentifiziert']);
    exit;
}

$currentUser   = Auth::user();
$currentUserId = (int) ($currentUser['id'] ?? 0);
$currentRole   = $currentUser['role'] ?? '';

// ── 2. Validate request parameter ────────────────────────────────────────────
$invoiceId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($invoiceId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Ungültige Rechnungs-ID']);
    exit;
}

// ── 3. Load invoice record ────────────────────────────────────────────────────
$invoice = Invoice::getById($invoiceId);
if (!$invoice) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Rechnung nicht gefunden']);
    exit;
}

// ── 4. IDOR / authorisation check ────────────────────────────────────────────
// Roles that may access any invoice (not only their own):
// board roles + alumni auditors/board (they see the full invoice table).
$privilegedRoles = array_merge(Auth::BOARD_ROLES, ['alumni_vorstand', 'alumni_finanz']);

$isOwner      = ((int) $invoice['user_id'] === $currentUserId);
$isPrivileged = in_array($currentRole, $privilegedRoles, true);

if (!$isOwner && !$isPrivileged) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Zugriff verweigert']);
    exit;
}

// ── 5. Resolve and validate the file path ────────────────────────────────────
$relativePath = $invoice['file_path'] ?? '';
if (empty($relativePath)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Keine Datei für diese Rechnung vorhanden']);
    exit;
}

// Path-traversal guard: the resolved path must be strictly inside the dedicated
// invoices upload folder.  A path outside that folder (e.g. reached via symlinks
// or a tampered DB value) is treated as an authorisation failure (HTTP 403), not a
// missing file (HTTP 404), so that the response cannot be used to probe paths.
$invoicesDir = realpath(__DIR__ . '/../uploads/invoices');

if ($invoicesDir === false) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Server-Konfigurationsfehler']);
    exit;
}

// Strip any directory components from the stored filename so that a tampered
// database value cannot escape the invoices folder via path traversal.
$safeBasename = basename($relativePath);
$fullPath = realpath($invoicesDir . DIRECTORY_SEPARATOR . $safeBasename);

if ($fullPath !== false && !str_starts_with($fullPath, $invoicesDir . DIRECTORY_SEPARATOR)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Zugriff verweigert']);
    exit;
}

if ($fullPath === false || !is_file($fullPath)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Datei nicht gefunden']);
    exit;
}

// ── 6. Detect MIME type and stream the file ───────────────────────────────────
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $fullPath);
finfo_close($finfo);

// Allowed MIME types match the upload whitelist defined in Invoice::ALLOWED_MIME_TYPES
$allowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mimeType, $allowedMimeTypes, true)) {
    http_response_code(415);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Ungültiger Dateityp']);
    exit;
}

// Build a safe download filename: invoice_{id}.{ext}
$extensionMap = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
];
$ext          = $extensionMap[$mimeType] ?? 'bin';
$safeFilename = 'Rechnung_' . $invoiceId . '.' . $ext;

header('Content-Type: ' . $mimeType);
header('Content-Disposition: inline; filename="' . $safeFilename . '"');
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

readfile($fullPath);
exit;
