<?php
// Backward-compatibility shim – use App\Models\BlogPost directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('BlogPost')) {
    class_alias(\App\Models\BlogPost::class, 'BlogPost');
}
