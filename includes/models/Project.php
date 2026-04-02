<?php
// Backward-compatibility shim – use App\Models\Project directly.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!class_exists('Project')) {
    class_alias(\App\Models\Project::class, 'Project');
}
