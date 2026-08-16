<?php
/**
 * Impressum - Legal Notice
 * Access: public (linked from email footers)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/helpers.php';

$title = 'Impressum - IBC Intranet';
ob_start();
?>

<div class="max-w-4xl mx-auto">
    <!-- Header -->
    <div class="mb-8">
        <h1 class="text-2xl sm:text-3xl md:text-4xl font-bold text-gray-800 dark:text-gray-100 mb-2">
            <i class="fas fa-gavel mr-3 text-ibc-green"></i>
            Impressum
        </h1>
    </div>

    <div class="card p-8">
        <p class="text-gray-600 dark:text-gray-300">
            Die Impressumspflicht wird über die Hauptwebseite des IBC erfüllt.
        </p>
    </div>
</div>

<?php
$content = ob_get_clean();

// Use auth_layout as it does not require authentication (same as register page)
require_once __DIR__ . '/../includes/templates/auth_layout.php';
?>
