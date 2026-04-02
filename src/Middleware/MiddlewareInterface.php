<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * MiddlewareInterface
 *
 * Every middleware receives the request context (method + URI) and a
 * $next callable that represents the remaining middleware chain / handler.
 * It may either call $next to continue processing, or short-circuit (e.g.
 * by redirecting) to stop the chain.
 */
interface MiddlewareInterface
{
    /**
     * @param  string   $method HTTP method (GET, POST, …)
     * @param  string   $uri    Decoded request URI path
     * @param  callable $next   The next middleware or the actual route handler
     * @return void
     */
    public function handle(string $method, string $uri, callable $next): void;
}
