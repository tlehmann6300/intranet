<?php
// Backward-compatibility shim – use App\Handlers\CSRFHandler directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('CSRFHandler')) {
    class_alias(\App\Handlers\CSRFHandler::class, 'CSRFHandler');
}
