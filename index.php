<?php

/**
 * Front-Controller (index.php)
 *
 * All HTTP requests that do not match an existing file or directory on disk
 * are forwarded here via .htaccess (Apache) / try_files (Nginx).
 *
 * Responsibilities:
 *  1. Bootstrap configuration and autoloading
 *  2. Dispatch clean-URL routes defined in config/routes.php (via FastRoute)
 *  3. Fall back to the legacy redirect behaviour for the bare "/" request
 *     when no route matches
 */

ob_start();

try {
    // -----------------------------------------------------------------------
    // 1. Bootstrap
    // -----------------------------------------------------------------------
    $configFile = __DIR__ . '/config/config.php';
    if (!file_exists($configFile)) {
        throw new Exception("Kritisch: config.php nicht gefunden in $configFile");
    }
    require_once $configFile;

    $helperFile = __DIR__ . '/includes/helpers.php';
    if (!file_exists($helperFile)) {
        throw new Exception("Kritisch: helpers.php nicht gefunden in $helperFile");
    }
    require_once $helperFile;

    // Composer autoloader (loads FastRoute, Twig, Eloquent, etc.)
    $autoloadFile = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoloadFile)) {
        throw new Exception("Kritisch: vendor/autoload.php nicht gefunden. Bitte 'composer install' ausführen.");
    }
    require_once $autoloadFile;

    // Legacy classes still loaded via require_once until fully migrated
    require_once __DIR__ . '/src/Auth.php';
    require_once __DIR__ . '/includes/handlers/CSRFHandler.php';

    // -----------------------------------------------------------------------
    // 2. BASE_URL fallback
    // -----------------------------------------------------------------------
    if (!defined('BASE_URL')) {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $baseUrl  = $protocol . '://' . $_SERVER['HTTP_HOST'];
        $path     = dirname($_SERVER['PHP_SELF']);
        if ($path !== '/' && $path !== '\\') {
            $baseUrl .= $path;
        }
        define('BASE_URL', rtrim($baseUrl, '/'));
    }

    // -----------------------------------------------------------------------
    // 3. FastRoute dispatcher
    // -----------------------------------------------------------------------
    $routesFile = __DIR__ . '/config/routes.php';
    if (!file_exists($routesFile)) {
        throw new Exception("Kritisch: config/routes.php nicht gefunden.");
    }

    $routeDefinitions = require $routesFile;

    $dispatcher = FastRoute\simpleDispatcher($routeDefinitions);

    $httpMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $uri        = $_SERVER['REQUEST_URI']    ?? '/';

    // Strip query string
    if (($pos = strpos($uri, '?')) !== false) {
        $uri = substr($uri, 0, $pos);
    }
    $uri = rawurldecode($uri);

    // Strip base path prefix (when deployed in a sub-directory)
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    if ($basePath !== '' && str_starts_with($uri, $basePath)) {
        $uri = substr($uri, strlen($basePath));
    }
    if ($uri === '' || $uri === false) {
        $uri = '/';
    }

    $routeInfo = $dispatcher->dispatch($httpMethod, $uri);

    switch ($routeInfo[0]) {

        // ------------------------------------------------------------------
        // Route matched
        // ------------------------------------------------------------------
        case FastRoute\Dispatcher::FOUND:
            [$handler, $vars] = [$routeInfo[1], $routeInfo[2]];

            [$controllerClass, $method] = $handler;

            // Build DI container and resolve the controller
            $container  = require __DIR__ . '/config/container.php';
            $controller = $container->get($controllerClass);

            ob_end_clean();
            $controller->$method(...array_values($vars));
            break;

        // ------------------------------------------------------------------
        // Method not allowed
        // ------------------------------------------------------------------
        case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
            ob_end_clean();
            header('Allow: ' . implode(', ', $routeInfo[1]));
            http_response_code(405);
            echo '<h1>405 Method Not Allowed</h1>';
            break;

        // ------------------------------------------------------------------
        // No route matched → legacy fallback (direct-file era)
        // ------------------------------------------------------------------
        default:
            ob_end_clean();

            // For the bare "/" root only, replicate old redirect behaviour
            if ($uri === '/') {
                $target = Auth::check()
                    ? BASE_URL . '/pages/dashboard/index.php'
                    : BASE_URL . '/pages/auth/login.php';

                if (!headers_sent()) {
                    header('Location: ' . $target);
                    exit;
                }
            }

            // Any other unmatched path → 404
            http_response_code(404);
            $errorPage = __DIR__ . '/pages/errors/404.php';
            if (file_exists($errorPage)) {
                require $errorPage;
            } else {
                echo '<h1>404 Not Found</h1>';
            }
            break;
    }

} catch (Exception $e) {
    ob_end_clean();

    $isProduction = !defined('ENVIRONMENT') || ENVIRONMENT === 'production';

    if ($isProduction) {
        $logFile   = __DIR__ . '/logs/error.log';
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents(
            $logFile,
            "[$timestamp] " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
        http_response_code(500);
        echo '<p>Ein interner Fehler ist aufgetreten. Bitte versuche es später erneut.</p>';
    } else {
        echo "<div style='font-family:sans-serif; padding:20px; background:#ffebee; border:1px solid #c62828; color:#b71c1c;'>";
        echo "<h2>⚠️ System Fehler</h2>";
        echo "<p><strong>Fehler:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><strong>Datei:</strong> " . $e->getFile() . " (Zeile " . $e->getLine() . ")</p>";
        echo "</div>";
        if (defined('BASE_URL')) {
            echo "<p><a href='" . BASE_URL . "/pages/auth/login.php'>Versuche direkten Login-Link</a></p>";
        }
    }
    exit;
}
