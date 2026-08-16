<?php
/**
 * API: Export Invoices
 * Creates a CSV file containing invoice metadata for board members
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../includes/models/Invoice.php';
require_once __DIR__ . '/../includes/helpers.php';

// Check authentication (Auth::check() calls init_session() which ensures secure session)
if (!Auth::check()) {
    http_response_code(401);
    exit;
}

$user = Auth::user();

// Only board members, alumni_vorstand, and alumni_finanz can export invoices
// Check if user has permission to view invoices
$hasInvoiceAccess = Auth::isBoard() || Auth::hasRole(['alumni_vorstand', 'alumni_finanz']);
if (!$hasInvoiceAccess) {
    header('Location: ../pages/dashboard/index.php');
    exit;
}

$userRole = $user['role'] ?? '';

// Get all invoices
$invoices = Invoice::getAll($userRole, $user['id']);

// Session is available for error messages (initialized by Auth::check())
if (empty($invoices)) {
    $_SESSION['error_message'] = 'Keine Rechnungen zum Exportieren vorhanden';
    header('Location: ' . asset('pages/invoices/index.php'));
    exit;
}

// Output CSV
$csvFileName = 'rechnungen_export_' . date('Y-m-d_H-i-s') . '.csv';
// Sanitize filename to prevent header injection
$safeCsvFileName = str_replace(['"', '\\', "\r", "\n"], '', $csvFileName);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $safeCsvFileName . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: public');

$out = fopen('php://output', 'w');
// UTF-8 BOM so that Excel opens the file with the correct encoding
fputs($out, "\xEF\xBB\xBF");

fputcsv($out, ['ID', 'Benutzer', 'Beschreibung', 'Betrag (€)', 'Status', 'Ablehnungsgrund', 'Erstellt am', 'Bezahlt am'], ';');

foreach ($invoices as $invoice) {
    fputcsv($out, [
        sanitizeCsvValue((string)($invoice['id'] ?? '')),
        sanitizeCsvValue((string)($invoice['user_email'] ?? '')),
        sanitizeCsvValue((string)($invoice['description'] ?? '')),
        sanitizeCsvValue(number_format((float)($invoice['amount'] ?? 0), 2, ',', '.')),
        sanitizeCsvValue((string)($invoice['status'] ?? '')),
        sanitizeCsvValue((string)($invoice['rejection_reason'] ?? '')),
        sanitizeCsvValue((string)($invoice['created_at'] ?? '')),
        sanitizeCsvValue((string)($invoice['paid_at'] ?? '')),
    ], ';');
}

fclose($out);
exit;
