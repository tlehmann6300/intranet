<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Handlers\RateLimiter;

/**
 * RateLimitMiddleware
 *
 * Wraps the RateLimiter handler as a MiddlewareInterface so it can be added
 * to any route in routes/web.php.
 *
 * Example – protect the login form (10 attempts per 10 minutes per IP):
 *   use App\Middleware\RateLimitMiddleware;
 *   $r->addRoute(['GET','POST'], '/login', [
 *       'App\Controllers\AuthController@login',
 *       [new RateLimitMiddleware('login', 10, 600)],   // ← inline instance
 *   ]);
 *
 * Because MiddlewarePipeline instantiates middleware via the DI container,
 * you can also register named instances in container.php and reference them
 * by class name – or pass a pre-configured instance directly.
 *
 * On success (within limits) the middleware increments the counter and calls
 * $next.  On failure it returns 429 Too Many Requests.
 *
 * NOTE: The hit counter is incremented BEFORE the request is handled.
 * Call RateLimiter::clear() inside the controller on successful actions
 * (e.g. after a successful login) to reset the counter.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private RateLimiter $limiter;

    /**
     * @param string $namespace     Logical name for the rate-limited action (e.g. 'login')
     * @param int    $maxAttempts   Maximum allowed attempts in the time window
     * @param int    $windowSeconds Length of the sliding window in seconds
     */
    public function __construct(
        string $namespace    = 'default',
        int    $maxAttempts  = 10,
        int    $windowSeconds = 600
    ) {
        $this->limiter = new RateLimiter($namespace, $maxAttempts, $windowSeconds);
    }

    public function handle(string $method, string $uri, callable $next): void
    {
        if ($this->limiter->tooManyAttempts()) {
            $retryAfter = $this->limiter->availableIn();
            http_response_code(429);
            header('Retry-After: ' . $retryAfter);

            $acceptsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
            if ($acceptsJson) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success'     => false,
                    'error'       => 'Zu viele Anfragen. Bitte warte ' . $retryAfter . ' Sekunden.',
                    'retry_after' => $retryAfter,
                ]);
            } else {
                echo 'Zu viele Anfragen. Bitte warte ' . $retryAfter . ' Sekunden, bevor du es erneut versuchst.';
            }

            exit;
        }

        $this->limiter->hit();

        $next($method, $uri);
    }

    /**
     * Expose the underlying limiter so the controller can call clear() on
     * a successful action (e.g. after a good login).
     */
    public function getLimiter(): RateLimiter
    {
        return $this->limiter;
    }
}
