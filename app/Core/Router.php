<?php
declare(strict_types=1);

namespace App\Core;

use App\Http\Request;
use App\Http\Response;

use function App\Support\pipeline;

/**
 * Declarative router. Routes are data:
 * [method, path, [Controller::class, 'action'], [middleware names]].
 */
final class Router
{
    /**
     * @param list<array{0:string,1:string,2:array{0:class-string,1:string},3:list<string>}> $routes
     * @param array<string, callable(Request, callable): Response> $middleware
     */
    public function __construct(
        private readonly array $routes,
        private readonly array $middleware,
        private readonly Container $container,
    ) {
    }

    public function dispatch(Request $request): Response
    {
        $pathMatches = array_filter($this->routes, static fn (array $r): bool => $r[1] === $request->path);

        if ($pathMatches === []) {
            return Response::html('Page not found.', 404);
        }

        $route = array_values(array_filter($pathMatches, static fn (array $r): bool => $r[0] === $request->method))[0] ?? null;
        if ($route === null) {
            return Response::html('Method not allowed.', 405)
                ->withHeader('Allow', implode(', ', array_unique(array_column($pathMatches, 0))));
        }

        [, , [$controller, $action], $names] = $route;
        $handler = [$this->container->get($controller), $action];
        $stack = array_map(fn (string $name): callable => $this->middleware[$name], $names);

        return pipeline($stack, $handler)($request);
    }
}
