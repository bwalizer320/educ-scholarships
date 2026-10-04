<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var array<string, array<int, array{path:string,handler:callable}>> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function dispatch(string $method, string $path): mixed
    {
        foreach ($this->routes[$method] ?? [] as $route) {
            $params = $this->match($route['path'], $path);

            if ($params !== null) {
                return ($route['handler'])($params);
            }
        }

        http_response_code(404);
        return 'Not Found';
    }

    private function add(string $method, string $path, callable $handler): void
    {
        $this->routes[$method][] = [
            'path' => $path,
            'handler' => $handler,
        ];
    }

    /** @return array<string,string>|null */
    private function match(string $pattern, string $path): ?array
    {
        if ($pattern === $path) {
            return [];
        }

        $names = [];
        $regex = preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            static function (array $matches) use (&$names): string {
                $names[] = $matches[1];
                return '([^/]+)';
            },
            $pattern
        );

        if ($regex === null) {
            return null;
        }

        if (preg_match('#^' . $regex . '$#', $path, $matches) !== 1) {
            return null;
        }

        array_shift($matches);
        $params = [];

        foreach ($names as $index => $name) {
            $params[$name] = urldecode($matches[$index] ?? '');
        }

        return $params;
    }
}
