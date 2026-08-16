<?php
/**
 * API: Process Alumni Access Request
 * Approve (and invite to Microsoft Entra ID) or reject an alumni access request.
 * Required permissions: alumni_finanz, alumni_vorstand, vorstand_finanzen,
 *                       vorstand_extern, vorstand_intern
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../includes/models/AlumniAccessRequest.php';
require_once __DIR__ . '/../includes/services/MicrosoftGraphService.php';
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

// --- Auth ---
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht authentifiziert']);
    exit;
}

$allowedRoles = ['alumni_finanz', 'alumni_vorstand', 'vorstand_finanzen', 'vorstand_extern', 'vorstand_intern'];
if (!Auth::hasRole($allowedRoles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Keine Berechtigung']);
    exit;
}

// --- Method ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Ungültige Anfrage']);
    exit;
}

// --- CSRF ---
CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

// --- Input validation ---
$requestId = intval($_POST['request_id'] ?? 0);
$action    = $_POST['action'] ?? '';

if ($requestId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Anfrage-ID']);
    exit;
}

if (!in_array($action, ['approve', 'reject'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Aktion']);
    exit;
}

// --- Load request ---
$request = AlumniAccessRequest::getById($requestId);
if (!$request) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Anfrage nicht gefunden']);
    exit;
}

if ($request['status'] !== 'pending') {
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Diese Anfrage wurde bereits bearbeitet']);
    exit;
}

$processedBy = (int) ($_SESSION['user_id'] ?? 0);

// --- Process ---
if ($action === 'reject') {
    $ok = AlumniAccessRequest::updateStatus($requestId, 'rejected', $processedBy);
    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'Anfrage abgelehnt']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Datenbankfehler beim Ablehnen']);
    }
    exit;
}

// --- Approve: invite to Microsoft Entra ID ---
$fullName     = trim($request['first_name'] . ' ' . $request['last_name']);
$email        = $request['new_email'];
$entraWarning = null;

try {
    $graphService  = new MicrosoftGraphService();
    $redirectUrl   = defined('BASE_URL') ? BASE_URL : '';
    $entraUserId   = $graphService->inviteUser($email, $fullName, $redirectUrl);

    // Assign alumni role in Entra
    $graphService->assignRole($entraUserId, 'alumni');
} catch (Exception $e) {
    // Do not block status update on Entra failure – record warning and proceed
    $entraWarning = $e->getMessage();
    error_log('process_alumni_request: Entra invite/assign failed for request #' . $requestId . ': ' . $e->getMessage());
}

// --- Update DB status ---
$ok = AlumniAccessRequest::updateStatus($requestId, 'approved', $processedBy);
if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Datenbankfehler beim Akzeptieren']);
    exit;
}

$response = ['success' => true, 'message' => 'Anfrage akzeptiert'];
if ($entraWarning !== null) {
    $response['warning'] = 'Entra-Einladung fehlgeschlagen: ' . $entraWarning;
}
echo json_encode($response);
