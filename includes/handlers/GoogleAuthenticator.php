<?php
// Backward-compatibility shim – use App\Handlers\PHPGangsta_GoogleAuthenticator directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
// Explicitly load the file because the class name does not match the filename for PSR-4.
require_once dirname(__DIR__, 2) . '/src/Handlers/GoogleAuthenticator.php';
if (!class_exists('PHPGangsta_GoogleAuthenticator')) {
    class_alias(\App\Handlers\PHPGangsta_GoogleAuthenticator::class, 'PHPGangsta_GoogleAuthenticator');
}
