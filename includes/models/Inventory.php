<?php
// Backward-compatibility shim – use App\Models\Inventory directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('Inventory')) {
    class_alias(\App\Models\Inventory::class, 'Inventory');
}
