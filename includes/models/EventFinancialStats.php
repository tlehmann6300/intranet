<?php
// Backward-compatibility shim – use App\Models\EventFinancialStats directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('EventFinancialStats')) {
    class_alias(\App\Models\EventFinancialStats::class, 'EventFinancialStats');
}
