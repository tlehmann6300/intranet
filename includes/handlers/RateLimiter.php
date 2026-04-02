<?php
// Backward-compatibility shim – use App\Handlers\RateLimiter directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('RateLimiter')) {
    class_alias(\App\Handlers\RateLimiter::class, 'RateLimiter');
}
