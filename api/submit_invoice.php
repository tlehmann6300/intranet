<?php
/**
 * API: Submit Invoice
 * Handles invoice submission with file upload
 */

require_once __DIR__ . '/../includes/handlers/ApiMiddleware.php';
require_once __DIR__ . '/../includes/models/Invoice.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../src/MailService.php';

// ── 1. Bootstrap: Content-Type + session + auth + method + CSRF ───────────
$user = ApiMiddleware::requireAuth('POST');

// ── 2. Permission check ───────────────────────────────────────────────────
// Group 1 (submit only): alumni, ehrenmitglied, anwaerter, mitglied, ressortleiter
// Group 3 (full access): vorstand_finanzen
// (Group 2 / read-only roles are not permitted to submit invoices)
$hasInvoiceSubmitAccess = in_array($user['role'], [
    'alumni', 'ehrenmitglied', 'anwaerter', 'mitglied', 'ressortleiter',
    'vorstand_finanzen'
], true);
if (!$hasInvoiceSubmitAccess) {
    ApiMiddleware::error(403, 'Keine Berechtigung');
}

// ── 3. Input validation ───────────────────────────────────────────────────
$amount      = $_POST['amount'] ?? null;
$description = $_POST['description'] ?? null;
$date        = $_POST['date'] ?? null;

if (empty($amount) || empty($description) || empty($date)) {
    ApiMiddleware::error(400, 'Alle Felder sind erforderlich');
}

if (!is_numeric($amount) || $amount <= 0) {
    ApiMiddleware::error(400, 'Ungültiger Betrag');
}

// ── 4. File upload validation ─────────────────────────────────────────────
if (!isset($_FILES['file'])) {
    ApiMiddleware::error(400, 'Datei-Upload fehlgeschlagen');
}

if ($_FILES['file']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['file']['error'] === UPLOAD_ERR_FORM_SIZE) {
    ApiMiddleware::error(400, 'Datei ist zu groß. Maximum: 10MB');
}

if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    ApiMiddleware::error(400, 'Datei-Upload fehlgeschlagen (Code: ' . (int)$_FILES['file']['error'] . ')');
}

// ── 5. Create invoice (prepared statements handled inside Invoice model) ──
$result = Invoice::create($user['id'], [
    'description' => $description,
    'amount' => $amount
], $_FILES['file']);

if (!$result['success']) {
    ApiMiddleware::error(500, $result['error'] ?? 'Fehler beim Einreichen der Rechnung');
}

// ── 6. Send email notification (non-blocking) ─────────────────────────────
try {
    $uploaderName = !empty($user['firstname']) && !empty($user['lastname'])
        ? $user['firstname'] . ' ' . $user['lastname']
        : $user['email'];

    $subject = "Neue Rechnung eingereicht von " . $uploaderName;

    $mailBody = MailService::getTemplate(
        'Neue Rechnung eingereicht',
        '<p>Eine neue Rechnung wurde zur Genehmigung eingereicht.</p>' .
        '<p><strong>Eingereicht von:</strong> ' . htmlspecialchars($uploaderName) . '</p>' .
        '<p><strong>Beschreibung:</strong> ' . htmlspecialchars($description) . '</p>' .
        '<p><strong>Betrag:</strong> ' . number_format($amount, 2, ',', '.') . ' €</p>' .
        '<p>Bitte prüfen Sie die Rechnung im System.</p>'
    );

    MailService::sendEmail(MAIL_FINANCE, $subject, $mailBody);
} catch (Exception $e) {
    error_log("Error sending invoice notification email: " . $e->getMessage());
}

// ── 7. Success response ───────────────────────────────────────────────────
http_response_code(200);
echo json_encode(['success' => true, 'message' => 'Rechnung erfolgreich eingereicht']);
