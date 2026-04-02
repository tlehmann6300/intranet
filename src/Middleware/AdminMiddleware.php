<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * AdminMiddleware
 *
 * Ensures that the authenticated user holds a board-level role ("Vorstand")
 * before the request reaches the controller.  It depends on AuthMiddleware
 * having already run (i.e. the user is logged in).
 *
 * If the user is authenticated but not a board member they are redirected
 * to the dashboard instead of seeing an error page.
 */
class AdminMiddleware implements MiddlewareInterface
{
    public function handle(string $method, string $uri, callable $next): void
    {
        if (!\Auth::check()) {
            header('Location: ' . \BASE_URL . '/login', true, 302);
            exit;
        }

        if (!\Auth::isBoard()) {
            header('Location: ' . \BASE_URL . '/dashboard', true, 302);
            exit;
        }

        $next($method, $uri);
    }
}
