<?php
// Backward-compatibility shim – use App\Services\MicrosoftGraphService directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('MicrosoftGraphService')) {
    class_alias(\App\Services\MicrosoftGraphService::class, 'MicrosoftGraphService');
}
