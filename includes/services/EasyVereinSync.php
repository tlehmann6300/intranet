<?php
// Backward-compatibility shim – use App\Services\EasyVereinSync directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('EasyVereinSync')) {
    class_alias(\App\Services\EasyVereinSync::class, 'EasyVereinSync');
}
