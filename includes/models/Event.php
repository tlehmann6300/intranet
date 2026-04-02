<?php
// Backward-compatibility shim – use App\Models\Event directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('Event')) {
    class_alias(\App\Models\Event::class, 'Event');
}
