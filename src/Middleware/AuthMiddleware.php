<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * AuthMiddleware
 *
 * Ensures that the current user has an active session before the request
 * reaches the controller.  If the user is not authenticated the middleware
 * redirects them to the login page.
 *
 * Usage in routes/web.php (via the dispatcher helper) or in index.php before
 * dispatching authenticated route groups.
 */
class AuthMiddleware implements MiddlewareInterface
{
    public function handle(string $method, string $uri, callable $next): void
    {
        if (!\Auth::check()) {
            header('Location: ' . \BASE_URL . '/login', true, 302);
            exit;
        }

        $next($method, $uri);
    }
}
