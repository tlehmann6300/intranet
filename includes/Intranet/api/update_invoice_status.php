<?php
/**
 * API: Update Invoice Status
 * Allows board members to approve or reject invoices
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../includes/models/Invoice.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';

header('Content-Type: application/json');

// Check authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nicht authentifiziert']);
    exit;
}

$user = Auth::user();
$userRole = $user['role'] ?? '';

// Only vorstand_finanzen can update invoice status
if ($userRole !== 'vorstand_finanzen') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Keine Berechtigung']);
    exit;
}

// Validate POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Methode nicht erlaubt']);
    exit;
}

// CSRF protection
CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

// Get and validate parameters
$invoiceId = isset($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null;
$status = $_POST['status'] ?? null;
$reason = $_POST['reason'] ?? null;

if (empty($invoiceId) || empty($status)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invoice ID und Status sind erforderlich']);
    exit;
}

// Validate status
if (!in_array($status, ['pending', 'approved', 'rejected'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ungültiger Status']);
    exit;
}

// Update invoice status
try {
    $result = Invoice::updateStatus($invoiceId, $status, $reason, $userRole);

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Status erfolgreich aktualisiert']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Fehler beim Aktualisieren des Status']);
    }
} catch (Exception $e) {
    error_log('update_invoice_status.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server-Fehler']);
}
