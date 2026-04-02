<?php
// Backward-compatibility shim – use App\Models\NewAlumniRequest directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('NewAlumniRequest')) {
    class_alias(\App\Models\NewAlumniRequest::class, 'NewAlumniRequest');
}
