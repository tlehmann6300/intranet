<?php
/**
 * API: Save Event Documentation
 * Only accessible to board and alumni_board members
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../includes/models/EventDocumentation.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';

header('Content-Type: application/json');

// Check authentication
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

$user = Auth::user();
$userRole = $user['role'] ?? '';

// Check if user has permission (board roles or alumni_vorstand only)
// This includes all board role variants: vorstand_finanzen, vorstand_intern, vorstand_extern
$allowedRoles = array_merge(Auth::BOARD_ROLES, ['alumni_vorstand']);
if (!in_array($userRole, $allowedRoles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Keine Berechtigung']);
    exit;
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültiges JSON-Format']);
    exit;
}

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Daten']);
    exit;
}

// CSRF protection
CSRFHandler::verifyToken($data['csrf_token'] ?? '');

$eventId = isset($data['event_id']) ? (int)$data['event_id'] : null;
$calculationLink = $data['calculation_link'] ?? null;
$salesData = $data['sales_data'] ?? [];
$sellersData = $data['sellers_data'] ?? [];

if (!$eventId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Event-ID fehlt']);
    exit;
}

// If only saving calculation_link
if (isset($data['calculation_link']) && !isset($data['sales_data'])) {
    try {
        $success = EventDocumentation::saveCalculationLink($eventId, $calculationLink, $user['id']);
        if ($success) {
            echo json_encode(['success' => true, 'message' => 'Kalkulationslink erfolgreich gespeichert']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Fehler beim Speichern']);
        }
    } catch (Exception $e) {
        error_log("Error saving calculation link: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Serverfehler: ' . $e->getMessage()]);
    }
    exit;
}

// If only saving total_costs
if (array_key_exists('total_costs', $data) && !isset($data['sales_data'])) {
    $totalCosts = $data['total_costs'];
    if ($totalCosts !== null && $totalCosts !== '' && (!is_numeric($totalCosts) || $totalCosts < 0)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ungültige Kosten (muss >= 0 sein)']);
        exit;
    }
    try {
        $amount = ($totalCosts === null || $totalCosts === '') ? null : floatval($totalCosts);
        $success = EventDocumentation::saveTotalCosts($eventId, $amount, $user['id']);
        if ($success) {
            echo json_encode(['success' => true, 'message' => 'Kosten erfolgreich gespeichert']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Fehler beim Speichern']);
        }
    } catch (Exception $e) {
        error_log("Error saving total costs: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Serverfehler: ' . $e->getMessage()]);
    }
    exit;
}

// Validate sales data structure
if (!is_array($salesData)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Verkaufsdaten']);
    exit;
}

// Validate sellers data structure
if (!is_array($sellersData)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Verkäuferdaten']);
    exit;
}

// Save documentation
try {
    $success = EventDocumentation::save($eventId, $salesData, $sellersData, $user['id']);
    
    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => 'Dokumentation erfolgreich gespeichert'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Fehler beim Speichern'
        ]);
    }
} catch (Exception $e) {
    error_log("Error saving event documentation: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Serverfehler: ' . $e->getMessage()
    ]);
}
