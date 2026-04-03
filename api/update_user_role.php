<?php
/**
 * API: Update User Role
 * Updates the local role of a user and syncs the change to Microsoft Entra ID.
 * Required permissions: canManageUsers (board or higher)
 */

require_once __DIR__ . '/../includes/handlers/ApiMiddleware.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../includes/services/MicrosoftGraphService.php';

// ── 1. Bootstrap: Content-Type + session + auth + method + CSRF ───────────
$user = ApiMiddleware::requireAuth('POST');

// ── 2. Permission check ───────────────────────────────────────────────────
if (!Auth::canManageUsers()) {
    ApiMiddleware::error(403, 'Keine Berechtigung');
}

// ── 3. Input validation ───────────────────────────────────────────────────
$userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$newRole = trim(filter_input(INPUT_POST, 'new_role', FILTER_DEFAULT) ?? '');

if ($userId === false || $userId === null) {
    ApiMiddleware::error(400, 'Ungültige Benutzer-ID');
}

if (!in_array($newRole, Auth::VALID_ROLES, true)) {
    ApiMiddleware::error(400, 'Ungültige Rolle');
}

if ($userId === (int) ($_SESSION['user_id'] ?? 0)) {
    ApiMiddleware::error(403, 'Du kannst Deine eigene Rolle nicht ändern');
}

// ── 4. Entra ID sync (non-blocking) ──────────────────────────────────────
$entraWarning = null;
try {
    $db = Database::getUserDB();
    $stmt = $db->prepare('SELECT azure_oid, role FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        ApiMiddleware::error(400, 'Benutzer nicht gefunden');
    }

    $azureOid = $row['azure_oid'] ?? null;

    if ($azureOid !== null && $azureOid !== '') {
        $graphService = new MicrosoftGraphService();
        $graphService->updateUserRole($azureOid, $newRole);
    } else {
        // User has no azure_oid yet – local DB will be updated but Entra is not synced.
        // The change will be overwritten by Entra on the user's next Microsoft login.
        error_log('[update_user_role] User ' . $userId . ' has no azure_oid – role change is local only.');
    }
} catch (Exception $e) {
    // Log the error but do not block the local role update –
    // an admin should still be able to manage roles even if Entra is temporarily unavailable.
    error_log('[update_user_role] Entra sync failed for user ' . $userId . ': ' . $e->getMessage());
    $entraWarning = 'Entra-Synchronisierung fehlgeschlagen: ' . $e->getMessage();
}

// ── 5. Persist the role change (prepared statement) ───────────────────────
try {
    $db = Database::getUserDB();
    $stmt = $db->prepare('UPDATE users SET role = :role WHERE id = :id');
    $stmt->execute([':role' => $newRole, ':id' => $userId]);

    // rowCount() may be 0 when the role is already set to the requested value –
    // that is still a valid outcome (user exists, confirmed in step 4).
    if ($stmt->rowCount() < 1 && $newRole !== ($row['role'] ?? null)) {
        ApiMiddleware::error(500, 'Fehler beim Ändern der Rolle');
    }

    $response = ['success' => true, 'message' => 'Rolle erfolgreich geändert'];
    if ($entraWarning !== null) {
        $response['warning'] = $entraWarning;
    }
    http_response_code(200);
    echo json_encode($response);
} catch (Exception $e) {
    error_log('[update_user_role] DB update failed: ' . $e->getMessage());
    ApiMiddleware::error(500, 'Server-Fehler');
}

