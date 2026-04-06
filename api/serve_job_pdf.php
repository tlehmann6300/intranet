<?php
/**
 * API: Serve Job Listing PDF
 * Serves a job listing's PDF file (CV/Lebenslauf) to authenticated users.
 */

require_once __DIR__ . '/../includes/handlers/AuthHandler.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../includes/models/JobBoard.php';

AuthHandler::startSession();

if (!Auth::check()) {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit('Ungültige Anfrage.');
}

$listing = JobBoard::getById($id);
if (!$listing || empty($listing['pdf_path'])) {
    http_response_code(404);
    exit('Datei nicht gefunden.');
}

$allowedDir = realpath(__DIR__ . '/../uploads/jobs');
$filePath   = realpath(__DIR__ . '/../' . $listing['pdf_path']);

if (
    $filePath === false ||
    $allowedDir === false ||
    strpos($filePath, $allowedDir . DIRECTORY_SEPARATOR) !== 0 ||
    !is_file($filePath)
) {
    http_response_code(404);
    exit('Datei nicht gefunden.');
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="lebenslauf.pdf"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, no-store');
readfile($filePath);
exit;
