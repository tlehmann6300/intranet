<?php
// Backward-compatibility shim – use App\Models\User directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('User')) {
    class_alias(\App\Models\User::class, 'User');
}
