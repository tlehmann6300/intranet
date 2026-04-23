<?php
/**
 * API: Save Event Financial Stats
 * Only accessible to board and alumni_board members
 */

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../includes/models/EventFinancialStats.php';
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

// Validation: event_id is always required
if (!$eventId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Event-ID fehlt']);
    exit;
}

// ── Donations-total branch ────────────────────────────────────────────────────
if (array_key_exists('donations_total', $data)) {
    $donationsTotal = $data['donations_total'];

    if ($donationsTotal === null || $donationsTotal === '') {
        $donationsTotal = 0;
    }

    if (!is_numeric($donationsTotal) || $donationsTotal < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ungültiger Spendenbetrag (muss >= 0 sein)']);
        exit;
    }

    try {
        $success = EventFinancialStats::saveDonationsTotal(
            $eventId,
            floatval($donationsTotal),
            $user['id']
        );

        if ($success) {
            echo json_encode(['success' => true, 'message' => 'Spendenbetrag erfolgreich gespeichert']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Fehler beim Speichern']);
        }
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Validierungsfehler: ' . $e->getMessage()]);
    } catch (Exception $e) {
        error_log("Error saving donations total: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Serverfehler: ' . $e->getMessage()]);
    }
    exit;
}

// ── Item-based financial-stats branch ────────────────────────────────────────
$category = $data['category'] ?? null;
$itemName = $data['item_name'] ?? null;
$quantity = $data['quantity'] ?? null;
$revenue = $data['revenue'] ?? null;
$recordYear = $data['record_year'] ?? date('Y');

// Validation
if (!$category || !in_array($category, ['Verkauf', 'Kalkulation'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Kategorie']);
    exit;
}

if (!$itemName || trim($itemName) === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Artikelname fehlt']);
    exit;
}

if ($quantity === null || !is_numeric($quantity) || $quantity < 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Menge (muss >= 0 sein)']);
    exit;
}

if ($revenue !== null && (!is_numeric($revenue) || $revenue < 0)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültiger Umsatz (muss >= 0 sein)']);
    exit;
}

// Convert empty string to null for revenue
if ($revenue === '') {
    $revenue = null;
}

// Save financial stat
try {
    $success = EventFinancialStats::create(
        $eventId,
        $category,
        trim($itemName),
        intval($quantity),
        $revenue !== null ? floatval($revenue) : null,
        intval($recordYear),
        $user['id']
    );
    
    if ($success) {
        echo json_encode([
            'success' => true,
            'message' => 'Eintrag erfolgreich gespeichert'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Fehler beim Speichern'
        ]);
    }
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Validierungsfehler: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Error saving event financial stats: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Serverfehler: ' . $e->getMessage()
    ]);
}
