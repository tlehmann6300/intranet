<?php
/**
 * API: Submit Invoice
 * Handles invoice submission with file upload
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../includes/models/Invoice.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../src/MailService.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';

// Check authentication
if (!Auth::check()) {
    $_SESSION['error_message'] = 'Nicht authentifiziert';
    header('Location: ' . asset('pages/auth/login.php'));
    exit;
}

$user = Auth::user();

// Check if user has permission to submit invoices
// Group 1 (submit only): alumni, ehrenmitglied, anwaerter, mitglied, resortleiter
// Group 3 (full access): vorstand_finanzen
// (Group 2 / read-only roles are not permitted to submit invoices)
$hasInvoiceSubmitAccess = in_array($user['role'], [
    'alumni', 'ehrenmitglied', 'anwaerter', 'mitglied', 'ressortleiter',
    'vorstand_finanzen'
]);
if (!$hasInvoiceSubmitAccess) {
    $_SESSION['error_message'] = 'Keine Berechtigung';
    header('Location: ' . asset('pages/invoices/index.php'));
    exit;
}

$userRole = $user['role'] ?? '';

// Validate POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = 'Methode nicht erlaubt';
    header('Location: ' . asset('pages/invoices/index.php'));
    exit;
}

// CSRF protection
CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

// Validate required fields
$amount = $_POST['amount'] ?? null;
$description = $_POST['description'] ?? null;
$date = $_POST['date'] ?? null;

if (empty($amount) || empty($description) || empty($date)) {
    $_SESSION['error_message'] = 'Alle Felder sind erforderlich';
    header('Location: ' . asset('pages/invoices/index.php'));
    exit;
}

// Validate amount
if (!is_numeric($amount) || $amount <= 0) {
    $_SESSION['error_message'] = 'Ungültiger Betrag';
    header('Location: ' . asset('pages/invoices/index.php'));
    exit;
}

// Validate file upload
if (!isset($_FILES['file'])) {
    $_SESSION['error_message'] = 'Datei-Upload fehlgeschlagen';
    header('Location: ' . asset('pages/invoices/index.php'));
    exit;
}

if ($_FILES['file']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['file']['error'] === UPLOAD_ERR_FORM_SIZE) {
    $_SESSION['error_message'] = 'Datei ist zu groß. Maximum: 10MB';
    header('Location: ' . asset('pages/invoices/index.php'));
    exit;
}

if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['error_message'] = 'Datei-Upload fehlgeschlagen (Code: ' . (int)$_FILES['file']['error'] . ')';
    header('Location: ' . asset('pages/invoices/index.php'));
    exit;
}

// Create invoice using Invoice model
$result = Invoice::create($user['id'], [
    'description' => $description,
    'amount' => $amount
], $_FILES['file']);

if ($result['success']) {
    // Send email notification to finance inbox
    try {
        $uploaderName = !empty($user['firstname']) && !empty($user['lastname'])
            ? $user['firstname'] . ' ' . $user['lastname']
            : $user['email'];

        $subject = "Neue Rechnung eingereicht von " . $uploaderName;

        $body = MailService::getTemplate(
            'Neue Rechnung eingereicht',
            '<p>Eine neue Rechnung wurde zur Genehmigung eingereicht.</p>' .
            '<p><strong>Eingereicht von:</strong> ' . htmlspecialchars($uploaderName) . '</p>' .
            '<p><strong>Beschreibung:</strong> ' . htmlspecialchars($description) . '</p>' .
            '<p><strong>Betrag:</strong> ' . number_format($amount, 2, ',', '.') . ' €</p>' .
            '<p>Bitte prüfen Sie die Rechnung im System.</p>'
        );

        MailService::sendEmail(MAIL_FINANCE, $subject, $body);
    } catch (Exception $e) {
        error_log("Error sending invoice notification email: " . $e->getMessage());
    }
    
    $_SESSION['success_message'] = 'Rechnung erfolgreich eingereicht';
} else {
    $_SESSION['error_message'] = $result['error'] ?? 'Fehler beim Einreichen der Rechnung';
}

header('Location: ' . asset('pages/invoices/index.php'));
exit;
