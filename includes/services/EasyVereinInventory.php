<?php
// Backward-compatibility shim – use App\Services\EasyVereinInventory directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('EasyVereinInventory')) {
    class_alias(\App\Services\EasyVereinInventory::class, 'EasyVereinInventory');
}
