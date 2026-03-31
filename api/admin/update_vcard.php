<?php
/**
 * API: Update VCard (Admin)
 *
 * Updates an existing vCard record in the external vCard database.
 * Accepted fields: vorname, nachname, telefon, email, linkedin.
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
$id = intval($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ungültige oder fehlende ID']);
    exit;
}

$data = [];

if (isset($_POST['vorname'])) {
    $data['vorname'] = trim(strip_tags($_POST['vorname']));
}

if (isset($_POST['nachname'])) {
    $data['nachname'] = trim(strip_tags($_POST['nachname']));
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

// ── Profile image upload ───────────────────────────────────────────────────────
if (isset($_FILES['profilbild']) && $_FILES['profilbild']['error'] !== UPLOAD_ERR_NO_FILE) {
    $uploadDir = __DIR__ . '/../../uploads/vcards/';
    $result    = SecureImageUpload::uploadImage($_FILES['profilbild'], $uploadDir);
    if (!$result['success']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $result['error']]);
        exit;
    }
    // Delete the previous profile image to avoid accumulating orphaned files
    $existing = VCard::getById($id);
    if ($existing && !empty($existing['profilbild'])) {
        SecureImageUpload::deleteImage($existing['profilbild']);
    }
    $data['profilbild'] = $result['path']; // relative path, e.g. "uploads/vcards/item_abc123.jpg"
}

if (empty($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Keine Felder zum Aktualisieren angegeben']);
    exit;
}

// ── Update vCard ───────────────────────────────────────────────────────────────
try {
    $updated = VCard::update($id, $data);
    if ($updated) {
        echo json_encode(['success' => true, 'message' => 'VCard erfolgreich aktualisiert']);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Keine Änderungen vorgenommen']);
    }
} catch (Exception $e) {
    error_log('update_vcard(admin): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Datenbankfehler beim Aktualisieren der VCard']);
}
