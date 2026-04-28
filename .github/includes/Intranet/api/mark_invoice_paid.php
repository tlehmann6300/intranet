<?php
/**
 * API: Mark Invoice as Paid
 * Allows board members with 'Finanzen und Recht' position to mark invoices as paid
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../includes/models/Invoice.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';

header('Content-Type: application/json');

// Check authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nicht authentifiziert']);
    exit;
}

// Only board_finance members can mark invoices as paid
if (!Auth::canManageInvoices()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Keine Berechtigung - nur Vorstand Finanzen und Recht']);
    exit;
}

$user = Auth::user();

// Validate POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Methode nicht erlaubt']);
    exit;
}

// CSRF protection
CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

// Get invoice ID
$invoiceId = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null;

if (empty($invoiceId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invoice ID erforderlich']);
    exit;
}

// Get invoice and check if it exists and is in approved status
$invoice = Invoice::getById($invoiceId);

if (!$invoice) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Rechnung nicht gefunden']);
    exit;
}

if ($invoice['status'] !== 'approved') {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => 'Nur genehmigte Rechnungen können als bezahlt markiert werden. Aktueller Status: ' . $invoice['status']
    ]);
    exit;
}

// Mark invoice as paid
try {
    $result = Invoice::markAsPaid($invoiceId, $user['id']);

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Rechnung erfolgreich als bezahlt markiert']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Fehler beim Markieren als bezahlt']);
    }
} catch (Exception $e) {
    error_log('mark_invoice_paid.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server-Fehler']);
}
