<?php
// Backward-compatibility shim – use App\Models\Alumni directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('Alumni')) {
    class_alias(\App\Models\Alumni::class, 'Alumni');
}
