<?php
/**
 * API: Create VCard (Admin)
 *
 * Creates a new vCard record in the external vCard database.
 * Required fields: vorname, nachname
 * Optional fields: rolle, funktion, telefon, email, linkedin, profilbild
 *
 * Required permissions: Vorstand (vorstand_finanzen, vorstand_extern, vorstand_intern)
 *                       or Resortleiter (ressortleiter)
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../../includes/models/VCard.php';
require_once __DIR__ . '/../../includes/utils/SecureImageUpload.php';
require_once __DIR__ . '/../../config/config.php';

header('Content-Type: application/json');

// ── Authentication ─────────────────────────────────────────────────────────────
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht authentifiziert']);
    exit;
}

// ── Role check ─────────────────────────────────────────────────────────────────
$allowedRoles = [
    Auth::ROLE_BOARD_FINANCE,
    Auth::ROLE_BOARD_INTERNAL,
    Auth::ROLE_BOARD_EXTERNAL,
    Auth::ROLE_HEAD,
];
if (!Auth::hasRole($allowedRoles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Keine Berechtigung']);
    exit;
}

// ── HTTP method ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Methode nicht erlaubt']);
    exit;
}

// ── CSRF verification ──────────────────────────────────────────────────────────
CSRFHandler::verifyToken($_POST['csrf_token'] ?? '');

// ── Input validation ───────────────────────────────────────────────────────────
$vorname = trim(strip_tags($_POST['vorname'] ?? ''));
$nachname = trim(strip_tags($_POST['nachname'] ?? ''));

if ($vorname === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Vorname ist erforderlich']);
    exit;
}

if ($nachname === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Nachname ist erforderlich']);
    exit;
}

$data = [
    'vorname'  => $vorname,
    'nachname' => $nachname,
];

if (isset($_POST['rolle'])) {
    $data['rolle'] = trim(strip_tags($_POST['rolle']));

    // Uniqueness: jede Rolle darf nur einmal vergeben werden
    if ($data['rolle'] !== '' && VCard::isRolleTaken($data['rolle'])) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Diese Rolle ist bereits einer anderen vCard zugewiesen. Bitte eine andere Rolle wählen oder die bestehende vCard bearbeiten.',
            'field'   => 'rolle',
        ]);
        exit;
    }
}

if (isset($_POST['funktion'])) {
    $data['funktion'] = trim(strip_tags($_POST['funktion']));
}

if (isset($_POST['telefon'])) {
    $data['telefon'] = trim(strip_tags($_POST['telefon']));
}

if (isset($_POST['email'])) {
    $email = trim($_POST['email']);
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ungültige E-Mail-Adresse']);
        exit;
    }
    $data['email'] = $email;
}

if (isset($_POST['linkedin'])) {
    $linkedin = trim($_POST['linkedin']);
    if ($linkedin !== '') {
        $filtered = filter_var($linkedin, FILTER_VALIDATE_URL);
        if ($filtered === false) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ungültige LinkedIn-URL']);
            exit;
        }
        $scheme = strtolower(parse_url($linkedin, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ungültige LinkedIn-URL']);
            exit;
        }
        $data['linkedin'] = $linkedin;
    } else {
        $data['linkedin'] = '';
    }
}

// ── Lebenslauf URL ─────────────────────────────────────────────────────────────
if (isset($_POST['lebenslauf'])) {
    $lebenslauf = trim($_POST['lebenslauf']);
    if ($lebenslauf !== '') {
        if (filter_var($lebenslauf, FILTER_VALIDATE_URL) === false) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ungültige Lebenslauf-URL']);
            exit;
        }
        $scheme = strtolower(parse_url($lebenslauf, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Lebenslauf-URL muss mit http:// oder https:// beginnen']);
            exit;
        }
        $data['lebenslauf'] = $lebenslauf;
    } else {
        $data['lebenslauf'] = '';
    }
}

if (isset($_FILES['profilbild']) && $_FILES['profilbild']['error'] !== UPLOAD_ERR_NO_FILE) {
    $uploadDir = __DIR__ . '/../../uploads/vcards/';
    $result    = SecureImageUpload::uploadImage($_FILES['profilbild'], $uploadDir);
    if (!$result['success']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $result['error']]);
        exit;
    }
    $data['profilbild'] = $result['path']; // relative path, e.g. "uploads/vcards/item_abc123.jpg"
}

// ── Create vCard ───────────────────────────────────────────────────────────────
try {
    $newId = VCard::create($data);
    echo json_encode(['success' => true, 'message' => 'VCard erfolgreich erstellt', 'id' => $newId]);
} catch (Exception $e) {
    error_log('create_vcard(admin): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Datenbankfehler beim Erstellen der VCard']);
}
