<?php
// Backward-compatibility shim – use App\Models\EventDocumentation directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('EventDocumentation')) {
    class_alias(\App\Models\EventDocumentation::class, 'EventDocumentation');
}
