<?php
/**
 * EasyVerein Synchronization Page
 * Handles synchronization of inventory items from EasyVerein
 */

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../includes/handlers/AuthHandler.php';
require_once __DIR__ . '/../../includes/services/EasyVereinSync.php';

// Check authentication and permissions
if (!Auth::check()) {
    header('Location: ../auth/login.php');
    exit;
}

// Only admin and board members can perform synchronization
// Note: AuthHandler::isAdmin() returns true for both 'admin' and 'board' roles
if (!AuthHandler::isAdmin()) {
    $_SESSION['error'] = 'Du hast keine Berechtigung, diese Aktion auszuführen.';
    header('Location: index.php');
    exit;
}

// Get user ID from session, fallback to 0 if not set
$userId = $_SESSION['user_id'] ?? 0;

// Perform synchronization
$sync = new EasyVereinSync();
$result = $sync->sync($userId);

// Store results in session for display
$_SESSION['sync_result'] = $result;

// Redirect back – allow returning to item view if a safe relative redirect was requested
$redirect = 'index.php';
if (!empty($_GET['redirect'])) {
    $raw = $_GET['redirect'];
    // Only allow relative paths (no scheme, no host) to prevent open redirects
    if (!preg_match('#^[a-zA-Z0-9_./?=&-]+$#', $raw) || preg_match('#^//#', $raw) || strpos($raw, '://') !== false || strpos($raw, '..') !== false) {
        $redirect = 'index.php';
    } else {
        $redirect = $raw;
    }
}
header('Location: ' . $redirect);
exit;
