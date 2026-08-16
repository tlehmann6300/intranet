<?php
/**
 * Inventory Cart Toggle API
 *
 * Manages the inventory rental cart in $_SESSION['cart'].
 *
 * Supported actions (POST JSON body):
 *   toggle   – Add item if not in cart, remove it if already present (default).
 *   set_qty  – Update the quantity of an existing cart item.
 *   remove   – Remove a specific item from the cart.
 *   clear    – Remove all items from the cart.
 *
 * Required fields per action:
 *   toggle  : item_id, item_name, image_src, pieces, quantity (opt, default 1)
 *   set_qty : item_id, quantity
 *   remove  : item_id
 *   clear   : (none besides csrf_token)
 *
 * Response JSON:
 *   { success: bool, in_cart: bool|null, cart_count: int, cart: array }
 */

require_once __DIR__ . '/../includes/handlers/AuthHandler.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';

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

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültiges JSON-Format']);
    exit;
}

CSRFHandler::verifyToken($input['csrf_token'] ?? '');

$action = $input['action'] ?? 'toggle';
$itemId = trim((string)($input['item_id'] ?? ''));

// Ensure cart is initialised
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$inCart = null;

if ($action === 'toggle') {
    if ($itemId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'item_id fehlt']);
        exit;
    }

    $foundIdx = null;
    foreach ($_SESSION['cart'] as $k => $entry) {
        if ((string)($entry['id'] ?? '') === $itemId) {
            $foundIdx = $k;
            break;
        }
    }

    if ($foundIdx !== null) {
        // Already in cart → remove
        array_splice($_SESSION['cart'], $foundIdx, 1);
        $inCart = false;
    } else {
        // Not in cart → add
        $_SESSION['cart'][] = [
            'id'       => $itemId,
            'name'     => substr(trim((string)($input['item_name'] ?? '')), 0, 200),
            'imageSrc' => trim((string)($input['image_src'] ?? '')),
            'pieces'   => max(0, (int)($input['pieces'] ?? 0)),
            'quantity' => max(1, (int)($input['quantity'] ?? 1)),
        ];
        $inCart = true;
    }

} elseif ($action === 'set_qty') {
    if ($itemId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'item_id fehlt']);
        exit;
    }

    $qty = max(1, (int)($input['quantity'] ?? 1));
    $found = false;
    foreach ($_SESSION['cart'] as &$entry) {
        if ((string)($entry['id'] ?? '') === $itemId) {
            $entry['quantity'] = $qty;
            $found = true;
            break;
        }
    }
    unset($entry);
    $inCart = $found;

} elseif ($action === 'remove') {
    if ($itemId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'item_id fehlt']);
        exit;
    }

    $_SESSION['cart'] = array_values(
        array_filter($_SESSION['cart'], function ($e) use ($itemId) {
            return (string)($e['id'] ?? '') !== $itemId;
        })
    );
    $inCart = false;

} elseif ($action === 'clear') {
    $_SESSION['cart'] = [];
    $inCart = false;

} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige Aktion']);
    exit;
}

echo json_encode([
    'success'    => true,
    'in_cart'    => $inCart,
    'cart_count' => count($_SESSION['cart']),
    'cart'       => $_SESSION['cart'],
]);
