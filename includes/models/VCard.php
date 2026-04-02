<?php
// Backward-compatibility shim – use App\Models\VCard directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('VCard')) {
    class_alias(\App\Models\VCard::class, 'VCard');
}
