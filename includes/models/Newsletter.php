<?php
// Backward-compatibility shim – use App\Models\Newsletter directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('Newsletter')) {
    class_alias(\App\Models\Newsletter::class, 'Newsletter');
}
