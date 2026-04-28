<?php
/**
 * API: Create Idea
 *
 * Aktuelle Policy (Stand 2026-04):
 *   Die Ideenbox legt KEINEN Datenbank-Eintrag mehr an, sondern verschickt
 *   ausschließlich eine Benachrichtigungs-E-Mail an die in der .env hinter-
 *   legte Adresse `INVOICE_NOTIFICATION_EMAIL` (Vorstand).
 *
 *   Vorher wurden Ideen auch in der `ideas`-Tabelle persistiert; das ist
 *   ausdrücklich nicht mehr gewünscht. Vorstand-Mailbox = Single Source of
 *   Truth.
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../src/MailService.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../config/config.php';

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

// Empfänger-Adresse aus der .env (Vorstand). Fallback nur als Notnagel.
$recipient = defined('INVOICE_NOTIFICATION_EMAIL') && INVOICE_NOTIFICATION_EMAIL
    ? INVOICE_NOTIFICATION_EMAIL
    : (defined('MAIL_IDEAS') ? MAIL_IDEAS : 'vorstand@business-consulting.de');

try {
    $username  = explode('@', $user['email'])[0];
    $emailBody = '<h2>Neue Idee eingereicht</h2>'
        . '<table class="info-table">'
        . '<tr><td class="info-label">Von:</td><td class="info-value">' . htmlspecialchars($username) . ' (' . htmlspecialchars($user['email']) . ')</td></tr>'
        . '<tr><td class="info-label">Titel:</td><td class="info-value">' . htmlspecialchars($title) . '</td></tr>'
        . '<tr><td class="info-label">Beschreibung:</td><td class="info-value">' . nl2br(htmlspecialchars($description)) . '</td></tr>'
        . '<tr><td class="info-label">Datum:</td><td class="info-value">' . date('d.m.Y H:i') . ' Uhr</td></tr>'
        . '</table>';

    $sent = MailService::send($recipient, 'Neue Idee von ' . $username, $emailBody);

    if (!$sent) {
        // MailService::send liefert false bei Versandfehlern; trotzdem User
        // freundlich informieren und Logs für den Admin schreiben.
        error_log('create_idea.php: MailService::send returned false for recipient ' . $recipient);
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Versand fehlgeschlagen. Bitte später erneut versuchen.']);
        exit;
    }

    recordFormSubmit('last_idea_submit_time');
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log('create_idea.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server-Fehler']);
}
