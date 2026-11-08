<?php
declare(strict_types=1);

namespace PM\Http;

final class Request
{
    /** Attributes set by the kernel (e.g. sub-path below the secret prefix, route params). */
    public array $attributes = [];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $cookies,
        public readonly array $server,
        private readonly ?string $rawBody = null,
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?? '/');
        return new self(
            strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path === '' ? '/' : $path,
            $_GET,
            $_POST,
            $_COOKIE,
            $_SERVER,
        );
    }

    public function input(string $key, string $default = ''): string
    {
        $v = $this->post[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    public function queryString(string $key, string $default = ''): string
    {
        $v = $this->query[$key] ?? $default;
        return is_string($v) ? $v : $default;
    }

    public function cookie(string $name): ?string
    {
        $v = $this->cookies[$name] ?? null;
        return is_string($v) ? $v : null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $v = $this->server[$key] ?? null;
        return is_string($v) ? $v : null;
    }

    public function ip(): string
    {
        return (string)($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return substr((string)($this->server['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }

    public function isHttps(): bool
    {
        $h = $this->server['HTTPS'] ?? '';
        if ($h !== '' && strtolower((string)$h) !== 'off') {
            return true;
        }
        return ($this->server['SERVER_PORT'] ?? '') == 443
            || strtolower((string)($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public function host(): string
    {
        return (string)($this->server['HTTP_HOST'] ?? 'localhost');
    }

    public function body(): string
    {
        return $this->rawBody ?? (string)file_get_contents('php://input');
    }

    /** Same-origin check for state-changing requests (defence in depth on top of CSRF tokens). */
    public function isSameOrigin(): bool
    {
        $origin = $this->header('Origin');
        if ($origin === null || $origin === '' || $origin === 'null') {
            // Some browsers omit Origin on same-origin form POSTs; fall back to Referer when present.
            $ref = $this->header('Referer');
            if ($ref === null || $ref === '') {
                return true; // CSRF token still required
            }
            $origin = $ref;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);
        $h = $host . ($port ? ':' . $port : '');
        return is_string($host) && strcasecmp($h, $this->host()) === 0;
    }

    public function withPath(string $path): self
    {
        $r = new self($this->method, $path, $this->query, $this->post, $this->cookies, $this->server, $this->rawBody);
        $r->attributes = $this->attributes;
        return $r;
    }

    public function param(string $name): string
    {
        return (string)($this->attributes['params'][$name] ?? '');
    }
}
