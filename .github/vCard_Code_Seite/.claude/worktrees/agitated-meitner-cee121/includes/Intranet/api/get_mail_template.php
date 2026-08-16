<?php
/**
 * AJAX endpoint: Load a mail template JSON from assets/mail_vorlage/
 * Returns JSON with subject and content fields.
 * Requires admin session.
 */

require_once __DIR__ . '/../src/Auth.php';

header('Content-Type: application/json');

if (!Auth::check() || !Auth::canManageUsers()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$template = trim($_GET['template'] ?? '');

if (empty($template)) {
    http_response_code(400);
    echo json_encode(['error' => 'Kein Template angegeben.']);
    exit;
}

// Sanitize: only allow alphanumeric, underscores, hyphens and spaces (no path traversal)
if (!preg_match('/^[a-zA-Z0-9_\- ]+$/', $template)) {
    http_response_code(400);
    echo json_encode(['error' => 'Ungültiger Template-Name.']);
    exit;
}

// Strip any directory components as a second line of defence.
$template = basename($template);

$templateDir = realpath(__DIR__ . '/../assets/mail_vorlage');

if ($templateDir === false) {
    http_response_code(403);
    echo json_encode(['error' => 'Zugriff verweigert.']);
    exit;
}

$filePath = realpath($templateDir . '/' . $template . '.json');

// Ensure the resolved path is strictly inside the allowed directory.
// A path outside the template folder is treated as a forbidden request (HTTP 403).
if ($filePath !== false && strpos($filePath, $templateDir . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Zugriff verweigert.']);
    exit;
}

if ($filePath === false) {
    http_response_code(404);
    echo json_encode(['error' => 'Template nicht gefunden.']);
    exit;
}

$raw = file_get_contents($filePath);
if ($raw === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Template konnte nicht gelesen werden.']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(500);
    echo json_encode(['error' => 'Ungültiges Template-Format.']);
    exit;
}

echo json_encode([
    'subject' => $data['subject'] ?? '',
    'content' => $data['content'] ?? '',
]);
