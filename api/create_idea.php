<?php
/**
 * API: Create Idea
 * Handles idea submission and sends notification email
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../includes/models/Idea.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../src/MailService.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nicht authentifiziert']);
    exit;
}

if (!Auth::canAccessPage('ideas')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Keine Berechtigung']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Methode nicht erlaubt']);
    exit;
}

CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

// Per-form rate limiting: prevent idea-submission spam without affecting other actions
$rateLimitWait = checkFormRateLimit('last_idea_submit_time');
if ($rateLimitWait > 0) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Bitte warte noch ' . $rateLimitWait . ' ' . ($rateLimitWait === 1 ? 'Sekunde' : 'Sekunden') . ', bevor du erneut eine Idee einreichst.']);
    exit;
}

$user        = Auth::user();
$title       = strip_tags(trim($_POST['title'] ?? ''));
$description = strip_tags(trim($_POST['description'] ?? ''));

if (empty($title) || empty($description)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Titel und Beschreibung sind erforderlich.']);
    exit;
}

try {
    $result = Idea::create((int) $user['id'], $title, $description);

    if ($result['success']) {
        // Send notification email
        try {
            $username  = explode('@', $user['email'])[0];
            $emailBody = '<h2>Neue Idee eingereicht</h2>'
                . '<table class="info-table">'
                . '<tr><td class="info-label">Von:</td><td class="info-value">' . htmlspecialchars($username) . ' (' . htmlspecialchars($user['email']) . ')</td></tr>'
                . '<tr><td class="info-label">Titel:</td><td class="info-value">' . htmlspecialchars($title) . '</td></tr>'
                . '<tr><td class="info-label">Beschreibung:</td><td class="info-value">' . nl2br(htmlspecialchars($description)) . '</td></tr>'
                . '<tr><td class="info-label">Datum:</td><td class="info-value">' . date('d.m.Y H:i') . ' Uhr</td></tr>'
                . '</table>';
            MailService::send(MAIL_IDEAS, 'Neue Idee von ' . $username, $emailBody);
        } catch (Exception $e) {
            error_log('create_idea.php email error: ' . $e->getMessage());
        }

        recordFormSubmit('last_idea_submit_time');
        echo json_encode(['success' => true, 'id' => $result['id']]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $result['error']]);
    }
} catch (Exception $e) {
    error_log('create_idea.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server-Fehler']);
}
