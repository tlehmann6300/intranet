<?php
// Backward-compatibility shim – use App\Models\AlumniAccessRequest directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('AlumniAccessRequest')) {
    class_alias(\App\Models\AlumniAccessRequest::class, 'AlumniAccessRequest');
}
