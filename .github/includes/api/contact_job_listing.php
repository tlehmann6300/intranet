<?php
/**
 * API: Contact Job Listing Author
 * Allows authenticated users to send a message to the owner of a job listing.
 * The sender must provide a contact e-mail address; the message is forwarded
 * to the listing owner via e-mail.
 */

require_once __DIR__ . '/../includes/handlers/AuthHandler.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/MailService.php';
require_once __DIR__ . '/../includes/models/JobBoard.php';
require_once __DIR__ . '/../includes/helpers.php';

AuthHandler::startSession();
header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht authentifiziert']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
    exit;
}

// CSRF protection
CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

// Per-form rate limiting
$rateLimitWait = checkFormRateLimit('last_job_contact_time');
if ($rateLimitWait > 0) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Bitte warte noch ' . $rateLimitWait . ' ' . ($rateLimitWait === 1 ? 'Sekunde' : 'Sekunden') . ', bevor du erneut eine Nachricht sendest.',
    ]);
    exit;
}

$listingId    = isset($_POST['listing_id']) ? (int)$_POST['listing_id'] : 0;
$contactEmail = trim($_POST['contact_email'] ?? '');
$message      = trim($_POST['message'] ?? '');

// Validate listing ID
if ($listingId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Anzeigen-ID']);
    exit;
}

// Validate contact e-mail
if (empty($contactEmail) || !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Bitte gib eine gültige Kontakt-E-Mail-Adresse an.']);
    exit;
}

// Validate message
if (empty($message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Die Nachricht darf nicht leer sein.']);
    exit;
}

if (mb_strlen($message) > 2000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Die Nachricht ist zu lang (maximal 2000 Zeichen).']);
    exit;
}

// Load listing
$listing = JobBoard::getById($listingId);
if (!$listing) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Die Anzeige wurde nicht gefunden.']);
    exit;
}

$sender = Auth::user();
$senderId = (int)($sender['id'] ?? 0);

// Prevent messaging your own listing
if ((int)$listing['user_id'] === $senderId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Du kannst dir selbst keine Nachricht senden.']);
    exit;
}

// Fetch recipient (listing owner) details
$userDb = Database::getUserDB();
$stmt = $userDb->prepare("SELECT id, first_name, last_name, email FROM users WHERE id = ? AND deleted_at IS NULL LIMIT 1");
$stmt->execute([(int)$listing['user_id']]);
$recipient = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$recipient || empty($recipient['email'])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Der Empfänger konnte nicht gefunden werden.']);
    exit;
}

$recipientName = trim(($recipient['first_name'] ?? '') . ' ' . ($recipient['last_name'] ?? ''));
$senderName    = trim((($sender['first_name'] ?? '') . ' ' . ($sender['last_name'] ?? '')) ?: ($sender['email'] ?? 'Unbekannt'));
$recipientDisplay = $recipientName ?: ($recipient['email']);

$sent = MailService::sendJobListingContact(
    $recipient['email'],
    $recipientDisplay,
    $senderName,
    $contactEmail,
    $listing['title'],
    $message
);

if (!$sent) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Die Nachricht konnte nicht gesendet werden. Bitte versuche es später erneut.']);
    exit;
}

recordFormSubmit('last_job_contact_time');

echo json_encode(['success' => true, 'message' => 'Deine Nachricht wurde erfolgreich gesendet!']);
