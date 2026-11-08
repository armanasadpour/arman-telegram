<?php
declare(strict_types=1);

namespace PM\Http;

final class Router
{
    /** @var list<array{string, string, callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $h): void
    {
        $this->routes[] = ['GET', $pattern, $h];
    }

    public function post(string $pattern, callable $h): void
    {
        $this->routes[] = ['POST', $pattern, $h];
    }

    public function dispatch(Request $req): Response
    {
        foreach ($this->routes as [$method, $pattern, $handler]) {
            $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[A-Za-z0-9_.-]+)', $pattern) . '$#';
            if (!preg_match($regex, $req->path, $m)) {
                continue;
            }
            if ($method !== $req->method && !($method === 'GET' && $req->method === 'HEAD')) {
                continue;
            }
            $req->attributes['params'] = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return $handler($req);
        }
        // Unknown route or wrong method inside the secret area: the same uniform 404.
        return Response::notFound();
    }
}
