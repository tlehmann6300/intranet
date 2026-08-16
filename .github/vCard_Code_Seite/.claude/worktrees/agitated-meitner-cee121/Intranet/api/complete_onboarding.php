<?php
/**
 * Complete Onboarding API
 * Saves required profile fields and sets is_onboarded flag to 1 for the authenticated user
 */

// Set JSON response header
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/models/User.php';
require_once __DIR__ . '/../includes/models/Member.php';
require_once __DIR__ . '/../includes/models/Alumni.php';

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Nicht authentifiziert'
    ]);
    exit;
}

try {
    // Check if it's a POST request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode([
            'success' => false,
            'message' => 'Ungültige Anfrage'
        ]);
        exit;
    }

    // Read POST body (supports both JSON and form data)
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $input = $_POST;
    }

    CSRFHandler::verifyToken($input['csrf_token'] ?? '');

    // Get current user ID from session
    if (!isset($_SESSION['user_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Sitzung ungültig'
        ]);
        exit;
    }

    $userId = $_SESSION['user_id'];
    $user = Auth::user();
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Benutzer nicht gefunden']);
        exit;
    }
    $userRole = $user['role'] ?? '';

    // Collect and validate required profile fields
    $firstName   = trim($input['first_name'] ?? '');
    $lastName    = trim($input['last_name'] ?? '');
    $email       = trim($input['email'] ?? $user['email']);
    $mobilePhone = trim($input['mobile_phone'] ?? '');
    $birthday    = trim($input['birthday'] ?? '');

    if (empty($firstName)) {
        echo json_encode(['success' => false, 'message' => 'Vorname ist erforderlich']);
        exit;
    }
    if (empty($lastName)) {
        echo json_encode(['success' => false, 'message' => 'Nachname ist erforderlich']);
        exit;
    }
    if (empty($mobilePhone)) {
        echo json_encode(['success' => false, 'message' => 'Mobiltelefon ist ein Pflichtfeld.']);
        exit;
    }
    if (empty($birthday)) {
        echo json_encode(['success' => false, 'message' => 'Geburtsdatum ist ein Pflichtfeld.']);
        exit;
    }
    if (strtotime($birthday) > strtotime('-16 years', strtotime('today'))) {
        echo json_encode(['success' => false, 'message' => 'Du musst mindestens 16 Jahre alt sein']);
        exit;
    }

    $profileData = [
        'first_name'   => $firstName,
        'last_name'    => $lastName,
        'email'        => $email,
        'mobile_phone' => $mobilePhone,
    ];

    // Save profile via the appropriate model
    $updateSuccess = false;
    if (isMemberRole($userRole)) {
        $updateSuccess = Member::updateProfile($userId, $profileData);
    } elseif (isAlumniRole($userRole)) {
        $updateSuccess = Alumni::updateOrCreateProfile($userId, $profileData);
    } else {
        // For any other role, attempt a generic upsert via Alumni (uses same table)
        $profileData['user_id'] = $userId;
        $updateSuccess = Alumni::updateOrCreateProfile($userId, $profileData);
    }

    if (!$updateSuccess) {
        echo json_encode(['success' => false, 'message' => 'Profil konnte nicht gespeichert werden']);
        exit;
    }

    // Update users table: birthday, is_onboarded, profile_complete, has_seen_onboarding
    $db = Database::getUserDB();
    $birthdayValue = !empty($birthday) ? $birthday : null;
    $stmt = $db->prepare("UPDATE users SET birthday = ?, is_onboarded = 1, profile_complete = 1, has_seen_onboarding = 1 WHERE id = ?");

    if ($stmt->execute([$birthdayValue, $userId])) {
        // Regenerate session ID on privilege elevation (onboarding completion)
        session_regenerate_id(true);

        // Update session so the onboarding middleware no longer triggers
        $_SESSION['is_onboarded'] = true;
        $_SESSION['profile_incomplete'] = false;

        echo json_encode([
            'success' => true,
            'message' => 'Onboarding abgeschlossen'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Fehler beim Aktualisieren'
        ]);
    }
} catch (Exception $e) {
    error_log('Error in complete_onboarding.php: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Server-Fehler'
    ]);
}
