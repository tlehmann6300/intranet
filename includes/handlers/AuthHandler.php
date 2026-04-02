<?php
// Backward-compatibility shim – use App\Handlers\AuthHandler directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('AuthHandler')) {
    class_alias(\App\Handlers\AuthHandler::class, 'AuthHandler');
}
