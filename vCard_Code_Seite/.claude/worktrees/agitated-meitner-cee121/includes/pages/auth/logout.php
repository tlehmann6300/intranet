<?php
require_once __DIR__ . '/../../src/Auth.php';

Auth::logout();
header('Location: login.php?logout=1');
exit;
