<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/Auth.php';

// Redirect if already authenticated
if (Auth::check()) {
    header('Location: ../dashboard/index.php');
    exit;
}

// User creation is handled exclusively via Microsoft Entra ID.
// Redirect to Microsoft login.
header('Location: ' . BASE_URL . '/auth/login_start.php');
exit;
