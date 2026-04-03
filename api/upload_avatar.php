<?php
/**
 * API: Upload Avatar / Profile Picture
 * Accepts a base64-encoded JPEG from Cropper.js, saves it to
 * uploads/profile_photos/ and updates the user's image_path in the DB.
 */

require_once __DIR__ . '/../includes/handlers/ApiMiddleware.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/models/Member.php';
require_once __DIR__ . '/../includes/models/Alumni.php';
require_once __DIR__ . '/../includes/utils/SecureImageUpload.php';

// ── 1. Bootstrap: Content-Type + session + auth + method + CSRF ───────────
$user = ApiMiddleware::requireAuth('POST');

// Read JSON body
$body = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    ApiMiddleware::error(400, 'Ungültiges JSON-Format');
}

$base64Data = $body['image'] ?? '';

if (empty($base64Data)) {
    error_log('upload_avatar.php: image data is empty. $_FILES: ' . print_r($_FILES, true));
    ApiMiddleware::error(400, 'Kein Bild übermittelt');
}

// Validate format: data:image/<type>;base64,<data>
if (!preg_match('/^data:image\/(jpeg|png|webp|gif);base64,(.+)$/s', $base64Data, $matches)) {
    ApiMiddleware::error(400, 'Ungültiges Bildformat');
}

$imageData = base64_decode($matches[2]);
if ($imageData === false || strlen($imageData) === 0) {
    ApiMiddleware::error(400, 'Bildverarbeitung fehlgeschlagen');
}

// Enforce 5 MB size limit
if (strlen($imageData) > 5242880) {
    ApiMiddleware::error(400, 'Bild ist zu groß. Maximum: 5MB');
}

// Write to a temp file for MIME validation
$tmpFile = tempnam(sys_get_temp_dir(), 'avatar_');
file_put_contents($tmpFile, $imageData);

$uploadPath = null;
try {
    // Validate actual MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $actualMime = finfo_file($finfo, $tmpFile);
    finfo_close($finfo);

    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($actualMime, $allowedMimes, true)) {
        throw new RuntimeException('Ungültiger Bildtyp');
    }

    // Ensure it is a real image
    if (@getimagesize($tmpFile) === false) {
        throw new RuntimeException('Datei ist kein gültiges Bild');
    }

    // Prepare upload directory
    $uploadDir = __DIR__ . '/../uploads/profile_photos/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    // Ensure PHP execution is disabled in the upload directory
    $htaccess = $uploadDir . '.htaccess';
    if (!file_exists($htaccess)) {
        if (file_put_contents($htaccess, "php_flag engine off\nAddType text/plain .php .php3 .phtml\n") === false) {
            error_log('upload_avatar.php: Failed to write .htaccess to ' . $uploadDir);
            throw new RuntimeException('Upload-Konfiguration konnte nicht geschrieben werden');
        }
    }

    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $ext = $extMap[$actualMime] ?? 'jpg';

    $userId = $user['id'];
    $userRole = $user['role'] ?? '';
    // Generate a filename with the custom_ prefix so the login script knows this is a
    // manually uploaded photo and must never be overwritten by the Entra ID sync.
    // Use cryptographically secure random bytes to keep filenames unguessable.
    $filename = 'custom_' . bin2hex(random_bytes(16)) . '.' . $ext;
    $uploadPath = $uploadDir . $filename;

    if (!copy($tmpFile, $uploadPath)) {
        throw new RuntimeException('Fehler beim Speichern des Profilbildes');
    }
    chmod($uploadPath, 0644);

    // Build relative path for DB storage (relative to project root)
    $projectRoot = realpath(__DIR__ . '/..');
    $realUploadPath = realpath($uploadPath);
    $relativePath = str_replace('\\', '/', substr($realUploadPath, strlen($projectRoot) + 1));

    // Delete old profile photo after the new one is saved successfully,
    // but only if the path differs (avoids removing the just-written file)
    $existingProfile = Member::getProfileByUserId($userId);
    if ($existingProfile && !empty($existingProfile['image_path'])
        && $existingProfile['image_path'] !== $relativePath) {
        SecureImageUpload::deleteImage($existingProfile['image_path']);
    }

    // Update image_path using the model appropriate for the user's role
    if (isMemberRole($userRole)) {
        $updateSuccess = Member::updateProfile($userId, ['image_path' => $relativePath]);
    } else {
        $updateSuccess = Alumni::updateOrCreateProfile($userId, ['image_path' => $relativePath]);
    }
    if (!$updateSuccess) {
        throw new RuntimeException('Datenbankfehler beim Aktualisieren des Profilbildes');
    }

    // Also update users.avatar_path and set use_custom_avatar = 1 so the Entra ID sync
    // recognises that the user prefers their own photo and never overwrites it.
    $userDb = Database::getUserDB();
    $avatarStmt = $userDb->prepare("UPDATE users SET avatar_path = ?, use_custom_avatar = 1 WHERE id = ?");
    if (!$avatarStmt->execute([$relativePath, $userId])) {
        throw new RuntimeException('Datenbankfehler beim Aktualisieren des Avatar-Pfades');
    }

    http_response_code(200);
    echo json_encode(['success' => true, 'image_path' => $relativePath]);

} catch (RuntimeException $e) {
    // Clean up the uploaded file if the DB update failed
    if ($uploadPath !== null) {
        if (file_exists($uploadPath)) {
            $allowedDir = realpath(__DIR__ . '/../uploads/profile_photos');
            $realUploadPath = realpath($uploadPath);
            if ($realUploadPath !== false && $allowedDir !== false && strpos($realUploadPath, $allowedDir . DIRECTORY_SEPARATOR) === 0) {
                @unlink($realUploadPath);
            }
        }
    }
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('upload_avatar.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server-Fehler']);
} finally {
    @unlink($tmpFile);
}

