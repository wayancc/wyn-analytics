<?php

declare(strict_types=1);

namespace WynAnalytics\Http;

/**
 * Tiny router: add('GET', '/sites/{id}', $handler). Params are passed to the handler by name.
 */
final class Router
{
    /** @var list<array{0: string, 1: string, 2: callable}> */
    private array $routes = [];

    public function add(string $methods, string $pattern, callable $handler): void
    {
        $parts = preg_split('#(\{\w+\})#', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '#^' . implode('', array_map(
            fn (string $p) => preg_match('#^\{(\w+)\}$#', $p, $m) ? '(?P<' . $m[1] . '>[^/]+)' : preg_quote($p, '#'),
            $parts,
        )) . '$#';
        foreach (explode('|', $methods) as $method) {
            $this->routes[] = [$method, $regex, $handler];
        }
    }

    public function dispatch(Request $request): ?Response
    {
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        $allowed = false;

        foreach ($this->routes as [$routeMethod, $regex, $handler]) {
            if (!preg_match($regex, $request->path, $m)) {
                continue;
            }
            $allowed = true;
            if ($routeMethod !== $method) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

            return $handler($request, ...$params);
        }

        return $allowed ? Response::text('Method not allowed', 405) : null;
    }
}
