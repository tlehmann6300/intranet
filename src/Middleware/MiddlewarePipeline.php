<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * MiddlewarePipeline
 *
 * Wraps a list of middleware objects and a final handler into a single
 * callable.  The middleware are executed in the order they are provided;
 * each one receives $next which points to the subsequent middleware (or the
 * terminal handler when there are no more middleware left).
 *
 * Example
 * -------
 * $pipeline = new MiddlewarePipeline(
 *     [new AuthMiddleware(), new AdminMiddleware()],
 *     fn($method, $uri) => $controller->action($vars)
 * );
 * $pipeline->run($method, $uri);
 */
class MiddlewarePipeline
{
    /** @param MiddlewareInterface[] $middlewares */
    public function __construct(
        private readonly array    $middlewares,
        /** @var callable */
        private readonly mixed    $handler
    ) {}

    public function run(string $method, string $uri): void
    {
        $pipeline = array_reduce(
            array_reverse($this->middlewares),
            static function (callable $carry, MiddlewareInterface $middleware): callable {
                return static function (string $m, string $u) use ($carry, $middleware): void {
                    $middleware->handle($m, $u, $carry);
                };
            },
            $this->handler
        );

        ($pipeline)($method, $uri);
    }
}
