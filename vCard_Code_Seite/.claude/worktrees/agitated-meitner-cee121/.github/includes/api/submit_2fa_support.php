<?php
/**
 * API: Submit Support Request from 2FA Verification Page
 * Handles support requests submitted by users who are in the pending 2FA state.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/handlers/AuthHandler.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../src/MailService.php';
require_once __DIR__ . '/../includes/helpers.php';

AuthHandler::startSession();
header('Content-Type: application/json; charset=utf-8');

// User must have a pending 2FA session
if (!isset($_SESSION['pending_2fa_user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Keine ausstehende 2FA-Verifizierung']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
    exit;
}

// CSRF protection – wrap in try-catch so any failure returns JSON instead of plain text
try {
    CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');
} catch (Exception $csrfEx) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF-Token ungültig. Bitte lade die Seite neu.']);
    exit;
}

$name        = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');

if (empty($name)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Bitte gib deinen Namen an']);
    exit;
}

if (strlen($name) > 200) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name ist zu lang (max. 200 Zeichen)']);
    exit;
}

if (empty($description)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Beschreibung darf nicht leer sein']);
    exit;
}

$userEmail = $_SESSION['pending_2fa_email'] ?? 'Unbekannt';

try {
    $subject = '[IBC Support] 2FA zurücksetzen von ' . $name;
    $body = MailService::getTemplate(
        '2FA Support-Anfrage',
        '<p>Eine Support-Anfrage zur 2FA-Verifizierung wurde eingereicht.</p>' .
        '<p><strong>Name:</strong> ' . htmlspecialchars($name) . '</p>' .
        '<p><strong>E-Mail:</strong> ' . htmlspecialchars($userEmail) . '</p>' .
        '<p><strong>Beschreibung:</strong><br>' . nl2br(htmlspecialchars($description)) . '</p>'
    );

    MailService::sendEmail(MAIL_SUPPORT, $subject, $body);

    echo json_encode(['success' => true, 'message' => 'Deine Anfrage wurde erfolgreich gesendet. Wir melden uns bald bei dir!']);
} catch (Exception $e) {
    error_log('Error in submit_2fa_support.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Fehler beim Senden der Anfrage. Bitte versuche es später erneut.']);
}
