<?php
// Backward-compatibility shim – use App\Models\Member directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('Member')) {
    class_alias(\App\Models\Member::class, 'Member');
}
