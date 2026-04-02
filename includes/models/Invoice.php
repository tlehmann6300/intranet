<?php
// Backward-compatibility shim – use App\Models\Invoice directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('Invoice')) {
    class_alias(\App\Models\Invoice::class, 'Invoice');
}
