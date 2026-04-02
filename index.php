<?php
/**
 * index.php – Front-Controller (Haupt-Einstiegspunkt)
 *
 * All HTTP requests that do not match a real file or directory are routed here
 * by the `.htaccess` RewriteRule.  This controller loads the application
 * bootstrap, resolves the requested URI via FastRoute and delegates to the
 * matching handler defined in `routes/web.php`.
 */

declare(strict_types=1);

// 1. Output buffering – prevents "Headers already sent" errors
ob_start();

try {
    // 2. Bootstrap: loads config, helpers, auth, autoloader and returns DI container
    $container = require __DIR__ . '/bootstrap/app.php';

    /** @var \Twig\Environment $twig */
    $twig = $container->get(\Twig\Environment::class);

    $isProduction = !defined('ENVIRONMENT') || ENVIRONMENT === 'production';

    // 3. Error handling setup
    if (!$isProduction) {
        // Development: use Whoops for rich, interactive error pages
        $whoops = new \Whoops\Run();
        $whoops->pushHandler(new \Whoops\Handler\PrettyPageHandler());
        $whoops->register();
    }

    // 4. BASE_URL fallback
    if (!defined('BASE_URL')) {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $baseUrl  = $protocol . '://' . $_SERVER['HTTP_HOST'];
        $path     = dirname($_SERVER['PHP_SELF']);
        if ($path !== '/' && $path !== '\\') {
            $baseUrl .= $path;
        }
        define('BASE_URL', rtrim($baseUrl, '/'));
    }

    // 5. FastRoute – dispatch the current request
    $dispatcher = FastRoute\simpleDispatcher(static function (FastRoute\RouteCollector $r): void {
        $redirect = static function (string $url): never {
            header('Location: ' . $url, true, 302);
            exit;
        };
        require __DIR__ . '/routes/web.php';
    });

    // Strip query string and decode URI for matching
    $uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $uri    = rawurldecode((string) $uri);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Remove BASE_URL path prefix if the app is installed in a subdirectory
    $basePath = rtrim(parse_url(BASE_URL, PHP_URL_PATH) ?? '', '/');
    if ($basePath !== '' && strpos($uri, $basePath) === 0) {
        $uri = substr($uri, strlen($basePath));
    }
    if ($uri === '' || $uri === false) {
        $uri = '/';
    }

    $routeInfo = $dispatcher->dispatch($method, $uri);

    ob_end_clean();

    switch ($routeInfo[0]) {
        case FastRoute\Dispatcher::FOUND:
            $handler  = $routeInfo[1];
            $vars     = $routeInfo[2];

            // Handlers may be:
            //   (a) a plain string 'ControllerClass@method'
            //   (b) an array  ['ControllerClass@method', [MiddlewareClass::class, ...]]
            //   (c) a closure (legacy)
            $middlewareClasses = [];
            if (is_array($handler)) {
                [$handler, $middlewareClasses] = $handler;
            }

            // Build the terminal callable that invokes the actual controller/closure
            $terminal = static function () use ($handler, $vars, $container): void {
                if (is_string($handler)) {
                    [$class, $method_name] = explode('@', $handler, 2);
                    if (!str_contains($class, '\\')) {
                        $class = 'App\\Controllers\\' . $class;
                    }
                    $controller = $container->get($class);
                    $controller->$method_name($vars);
                } else {
                    $handler($vars);
                }
            };

            // Resolve and instantiate middleware classes, then run the pipeline
            $middlewareInstances = array_map(
                static fn(string $cls): \App\Middleware\MiddlewareInterface => $container->get($cls),
                $middlewareClasses
            );

            $pipeline = new \App\Middleware\MiddlewarePipeline(
                $middlewareInstances,
                static function () use ($terminal): void { $terminal(); }
            );
            $pipeline->run($method, $uri);
            break;

        case FastRoute\Dispatcher::NOT_FOUND:
            http_response_code(404);
            echo '<h1>404 – Seite nicht gefunden</h1>';
            break;

        case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
            http_response_code(405);
            header('Allow: ' . implode(', ', $routeInfo[1]));
            echo '<h1>405 – Methode nicht erlaubt</h1>';
            break;
    }

} catch (Throwable $e) {
    // Discard any partial output
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    $isProduction = !defined('ENVIRONMENT') || ENVIRONMENT === 'production';

    if ($isProduction) {
        // Production: log structured error via Monolog
        try {
            if (isset($container)) {
                /** @var \Psr\Log\LoggerInterface $logger */
                $logger = $container->get(\Psr\Log\LoggerInterface::class);
                $logger->error($e->getMessage(), [
                    'exception' => get_class($e),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                    'trace'     => $e->getTraceAsString(),
                ]);
            }
        } catch (Throwable $logError) {
            // Fallback: write to error.log if container is unavailable
            $logFile   = __DIR__ . '/logs/error.log';
            $timestamp = date('Y-m-d H:i:s');
            @file_put_contents(
                $logFile,
                "[$timestamp] " . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine() . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        }
        http_response_code(500);
        echo '<p>Ein interner Fehler ist aufgetreten. Bitte versuche es später erneut.</p>';
    } else {
        // Development: Whoops handles it if registered, otherwise fall back to plain output
        if (class_exists(\Whoops\Run::class) && isset($whoops)) {
            throw $e;
        }
        echo "<div style='font-family:sans-serif;padding:20px;background:#ffebee;border:1px solid #c62828;color:#b71c1c;'>";
        echo '<h2>⚠️ System Fehler</h2>';
        echo '<p><strong>Fehler:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '<p><strong>Datei:</strong> ' . htmlspecialchars($e->getFile()) . ' (Zeile ' . $e->getLine() . ')</p>';
        echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        echo '</div>';

        if (defined('BASE_URL')) {
            echo "<p><a href='" . BASE_URL . "/login'>Zum Login</a></p>";
        }
    }
    exit;
}
