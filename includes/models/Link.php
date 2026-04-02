<?php
// Backward-compatibility shim – use App\Models\Link directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('Link')) {
    class_alias(\App\Models\Link::class, 'Link');
}
