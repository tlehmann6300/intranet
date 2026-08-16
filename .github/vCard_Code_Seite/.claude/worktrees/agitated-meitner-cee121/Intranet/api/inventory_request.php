<?php
/**
 * Inventory Request API
 *
 * action: check_availability  – Returns the available quantity for an inventory object and date range.
 * action: submit_request      – Saves a new rental request with status 'pending' in the local DB.
 */

require_once __DIR__ . '/../includes/handlers/AuthHandler.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/services/EasyVereinInventory.php';
require_once __DIR__ . '/../src/MailService.php';

AuthHandler::startSession();
header('Content-Type: application/json; charset=utf-8');

if (!AuthHandler::isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht authentifiziert']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Nur POST-Anfragen erlaubt']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültiges JSON-Format']);
    exit;
}
$action = $input['action'] ?? '';

// CSRF protection
$csrfToken = $input['csrf_token'] ?? '';
CSRFHandler::verifyToken($csrfToken);

try {
    $evi = new EasyVereinInventory();

    if ($action === 'check_availability') {
        $inventoryObjectId = $input['inventory_object_id'] ?? '';
        $startDate         = $input['start_date'] ?? '';
        $endDate           = $input['end_date']   ?? '';

        if (empty($inventoryObjectId) || empty($startDate) || empty($endDate)) {
            throw new Exception('Pflichtfelder fehlen: inventory_object_id, start_date, end_date');
        }

        // Validate date format (YYYY-MM-DD)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            throw new Exception('Ungültiges Datumsformat. Erwartet: YYYY-MM-DD');
        }

        if ($startDate > $endDate) {
            throw new Exception('Startdatum muss vor dem Enddatum liegen');
        }

        $available = $evi->getAvailableQuantity($inventoryObjectId, $startDate, $endDate);
        $total     = $evi->getTotalPieces($inventoryObjectId);

        echo json_encode([
            'success'   => true,
            'available' => $available,
            'total'     => $total,
        ]);
        exit;
    }

    if ($action === 'submit_request') {
        $inventoryObjectId = trim($input['inventory_object_id'] ?? '');
        $startDate         = trim($input['start_date'] ?? '');
        $endDate           = trim($input['end_date']   ?? '');
        $quantity          = (int)($input['quantity']  ?? 0);
        $purpose           = trim($input['purpose']    ?? '');
        $userId = (int)($_SESSION['user_id'] ?? 0);

        if (empty($inventoryObjectId) || empty($startDate) || empty($endDate) || $quantity < 1) {
            throw new Exception('Pflichtfelder fehlen oder ungültig: inventory_object_id, start_date, end_date, quantity');
        }

        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            throw new Exception('Ungültiges Datumsformat. Erwartet: YYYY-MM-DD');
        }

        if ($startDate > $endDate) {
            throw new Exception('Startdatum muss vor dem Enddatum liegen');
        }

        // Check availability
        $available = $evi->getAvailableQuantity($inventoryObjectId, $startDate, $endDate);
        if ($available < $quantity) {
            throw new Exception('Nicht genügend Bestand verfügbar. Verfügbar: ' . $available);
        }

        // Persist the request with status 'pending'
        $db   = Database::getContentDB();
        $stmt = $db->prepare(
            "INSERT INTO inventory_requests
                (inventory_object_id, user_id, start_date, end_date, quantity, purpose, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())"
        );
        $stmt->execute([$inventoryObjectId, $userId, $startDate, $endDate, $quantity, $purpose ?: null]);
        $requestId = $db->lastInsertId();

        // Send email notification to inventory inbox
        try {
            $requesterEmail = $_SESSION['user_email'] ?? '';
            $subject = 'Neue Inventar-Anfrage von ' . $requesterEmail;
            $body = MailService::getTemplate(
                'Neue Inventar-Anfrage',
                '<p>Eine neue Inventar-Anfrage wurde eingereicht.</p>' .
                '<table class="info-table">' .
                '<tr><td><strong>Anfragesteller</strong></td><td>' . htmlspecialchars($requesterEmail) . '</td></tr>' .
                '<tr><td><strong>Inventar-ID</strong></td><td>' . htmlspecialchars($inventoryObjectId) . '</td></tr>' .
                '<tr><td><strong>Zeitraum</strong></td><td>' . htmlspecialchars($startDate) . ' bis ' . htmlspecialchars($endDate) . '</td></tr>' .
                '<tr><td><strong>Menge</strong></td><td>' . (int)$quantity . '</td></tr>' .
                ($purpose !== '' ? '<tr><td><strong>Verwendungszweck</strong></td><td>' . htmlspecialchars($purpose) . '</td></tr>' : '') .
                '</table>' .
                '<p>Bitte prüfen Sie die Anfrage im System.</p>'
            );
            MailService::sendEmail(MAIL_INVENTORY, $subject, $body);
        } catch (Exception $mailEx) {
            error_log('inventory_request: Fehler beim Senden der Benachrichtigungs-E-Mail: ' . $mailEx->getMessage());
        }

        // Send confirmation email to the requester
        try {
            $requesterEmail = $_SESSION['user_email'] ?? '';
            if ($requesterEmail !== '') {
                $confirmBody = MailService::getTemplate(
                    'Anfrage eingegangen',
                    '<p class="email-text">Deine Anfrage ist beim Vorstand eingegangen und wird geprüft.</p>' .
                    '<table class="info-table">' .
                    '<tr><td><strong>Inventar-ID</strong></td><td>' . htmlspecialchars($inventoryObjectId) . '</td></tr>' .
                    '<tr><td><strong>Zeitraum</strong></td><td>' . htmlspecialchars($startDate) . ' bis ' . htmlspecialchars($endDate) . '</td></tr>' .
                    '<tr><td><strong>Menge</strong></td><td>' . (int)$quantity . '</td></tr>' .
                    ($purpose !== '' ? '<tr><td><strong>Verwendungszweck</strong></td><td>' . htmlspecialchars($purpose) . '</td></tr>' : '') .
                    '</table>' .
                    '<p class="email-text">Du wirst benachrichtigt, sobald deine Anfrage bearbeitet wurde.</p>'
                );
                MailService::sendEmail($requesterEmail, 'Deine Inventar-Anfrage ist eingegangen', $confirmBody);
            }
        } catch (Exception $mailEx) {
            error_log('inventory_request: Fehler beim Senden der Bestätigungs-E-Mail an Anfragesteller: ' . $mailEx->getMessage());
        }

        echo json_encode([
            'success'    => true,
            'message'    => 'Anfrage erfolgreich eingereicht. Sie werden benachrichtigt, sobald Ihre Anfrage bearbeitet wurde.',
            'request_id' => (int)$requestId,
        ]);
        exit;
    }

    throw new Exception('Ungültige Aktion');

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
