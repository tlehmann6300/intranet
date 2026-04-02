<?php
// Backward-compatibility shim – use App\Models\Idea directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('Idea')) {
    class_alias(\App\Models\Idea::class, 'Idea');
}
