<?php
/**
 * 404 - Seite nicht gefunden
 */

// Set the correct HTTP status code
http_response_code(404);

// Try to load the full layout with authentication
$configFile = __DIR__ . '/../../config/config.php';
$helperFile = __DIR__ . '/../../includes/helpers.php';
$authFile   = __DIR__ . '/../../src/Auth.php';

$useFullLayout = false;
if (file_exists($configFile) && file_exists($helperFile) && file_exists($authFile)) {
    require_once $configFile;
    require_once $helperFile;
    require_once $authFile;

    if (Auth::check()) {
        $useFullLayout = true;
    }
}

$title = '404 – Seite nicht gefunden';

ob_start();
?>
<div class="flex flex-col items-center justify-center min-h-[60vh] text-center px-4">
    <div class="mb-6">
        <span class="text-8xl font-extrabold text-ibc-blue dark:text-ibc-blue-light select-none">404</span>
    </div>
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 dark:text-gray-100 mb-3">
        <i class="fas fa-search mr-2 text-ibc-green" aria-hidden="true"></i>
        Seite nicht gefunden
    </h1>
    <p class="text-gray-600 dark:text-gray-300 text-lg max-w-md mb-8">
        Die gesuchte Seite existiert leider nicht oder wurde verschoben. Bitte überprüfe den Link oder kehre zum Dashboard zurück.
    </p>
    <a href="<?php echo defined('BASE_URL') ? BASE_URL . '/pages/dashboard/index.php' : '/pages/dashboard/index.php'; ?>"
       class="inline-flex items-center gap-2 px-6 py-3 rounded-xl font-semibold text-white shadow-lg transition-all duration-200 hover:scale-105 active:scale-95"
       style="background: var(--ibc-blue, #0066b3);">
        <i class="fas fa-home" aria-hidden="true"></i>
        Zurück zum Dashboard
    </a>
</div>
<?php
$content = ob_get_clean();

if ($useFullLayout) {
    require_once __DIR__ . '/../../includes/templates/main_layout.php';
} else {
    // Standalone fallback for unauthenticated / database-unavailable scenarios
    $baseUrl = defined('BASE_URL') ? BASE_URL : '';
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?></title>
    <link rel="dns-prefetch" href="//fonts.googleapis.com">
    <link rel="dns-prefetch" href="//fonts.gstatic.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; color: #1e293b; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; overflow-x: hidden; }
        .error-box { background: #fff; border-radius: 1.5rem; box-shadow: 0 8px 32px rgba(0,0,0,0.10); padding: 3rem 2.5rem; max-width: 480px; width: 100%; text-align: center; box-sizing: border-box; }
        .error-code { font-size: clamp(2.5rem, 12vw, 5rem); font-weight: 900; color: #0066b3; line-height: 1; }
        h1 { font-size: clamp(1.1rem, 4vw, 1.75rem); font-weight: 700; margin: 1rem 0 0.5rem; }
        p { color: #475569; font-size: 1.05rem; margin-bottom: 2rem; }
        .btn-dashboard { display: inline-flex; align-items: center; gap: 0.5rem; background: #0066b3; color: #fff; font-weight: 600; padding: 0.75rem 1.75rem; border-radius: 0.75rem; text-decoration: none; font-size: 1rem; transition: background 0.2s; min-height: 44px; }
        .btn-dashboard:hover { background: #004f8c; color: #fff; }
        @media (max-width: 480px) { .btn-dashboard { width: 100%; justify-content: center; } .error-box { padding: 1.5rem 1rem; } }
    </style>
</head>
<body>
    <div class="error-box">
        <div class="error-code">404</div>
        <h1><i class="fas fa-search me-2 text-success"></i>Seite nicht gefunden</h1>
        <p>Die gesuchte Seite existiert leider nicht oder wurde verschoben. Bitte überprüfe den Link oder kehre zum Dashboard zurück.</p>
        <a href="<?php echo htmlspecialchars($baseUrl . '/pages/dashboard/index.php'); ?>" class="btn-dashboard">
            <i class="fas fa-home"></i>
            Zurück zum Dashboard
        </a>
    </div>
</body>
</html>
<?php
}
?>
