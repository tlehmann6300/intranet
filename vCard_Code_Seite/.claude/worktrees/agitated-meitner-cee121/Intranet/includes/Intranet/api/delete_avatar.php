<?php
/**
 * API: Delete / Remove Profile Picture
 * Removes the manually-uploaded profile picture from disk and clears the
 * image_path in the database so the Entra ID photo (or default) is shown again.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/models/Member.php';
require_once __DIR__ . '/../includes/models/Alumni.php';
require_once __DIR__ . '/../includes/utils/SecureImageUpload.php';
require_once __DIR__ . '/../includes/handlers/CSRFHandler.php';

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Nicht authentifiziert']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'message' => 'Ungültige Anfrage']);
        exit;
    }

    // Read JSON body
    $body = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'message' => 'Ungültiges JSON-Format']);
        exit;
    }

    // CSRF protection
    CSRFHandler::verifyToken($body['csrf_token'] ?? '');

    $user     = Auth::user();
    $userId   = $user['id'];
    $userRole = $user['role'] ?? '';

    // Fetch current profile to get existing image_path
    $existingProfile = Member::getProfileByUserId($userId);

    if (!$existingProfile || empty($existingProfile['image_path'])) {
        // Already in the desired state – no manual upload exists, return success (idempotent).
        echo json_encode(['success' => true]);
        exit;
    }

    $oldImagePath = $existingProfile['image_path'];

    // Clear image_path in the database (reverts to Entra ID photo or default)
    if (isMemberRole($userRole)) {
        $updateSuccess = Member::updateProfile($userId, ['image_path' => null]);
    } else {
        $updateSuccess = Alumni::updateOrCreateProfile($userId, ['image_path' => null]);
    }

    if (!$updateSuccess) {
        echo json_encode(['success' => false, 'message' => 'Datenbankfehler beim Entfernen des Profilbildes']);
        exit;
    }

    // Reset use_custom_avatar and restore avatar_path to entra_photo_path (if present),
    // so the Entra ID photo is shown immediately without waiting for the next login.
    $userDb = Database::getUserDB();
    $avatarStmt = $userDb->prepare("UPDATE users SET avatar_path = entra_photo_path, use_custom_avatar = 0 WHERE id = ?");
    if (!$avatarStmt->execute([$userId])) {
        error_log('delete_avatar.php: Could not reset avatar_path/use_custom_avatar for user ' . $userId);
    }

    // Delete the old file from disk after the DB update succeeded.
    // Log a warning if the file cannot be removed (orphaned file) but still return success
    // because the desired state (no custom DB path) is already achieved.
    if (!SecureImageUpload::deleteImage($oldImagePath)) {
        error_log('delete_avatar.php: Could not delete orphaned file "' . $oldImagePath . '" for user ' . $userId);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log('delete_avatar.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server-Fehler']);
}
