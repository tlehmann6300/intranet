<?php
// Backward-compatibility shim – use App\Utils\SecureImageUpload directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('SecureImageUpload')) {
    class_alias(\App\Utils\SecureImageUpload::class, 'SecureImageUpload');
}
