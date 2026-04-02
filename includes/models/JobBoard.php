<?php
// Backward-compatibility shim – use App\Models\JobBoard directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('JobBoard')) {
    class_alias(\App\Models\JobBoard::class, 'JobBoard');
}
