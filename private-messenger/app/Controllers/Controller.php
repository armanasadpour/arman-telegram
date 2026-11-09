<?php
declare(strict_types=1);

namespace PM\Controllers;

use PM\App;
use PM\Audit\AuditLog;
use PM\Auth\Csrf;
use PM\Auth\RateLimiter;
use PM\Http\Request;
use PM\Http\Response;
use PM\Support\View;

abstract class Controller
{
    protected View $view;

    public function __construct(protected readonly App $app, protected readonly string $base = '')
    {
        $this->view = new View($app->root . '/app/Views');
    }

    abstract public function handle(Request $req): Response;

    /** @param array<string, mixed> $vars */
    protected function page(Request $req, string $template, array $vars = [], int $status = 200): Response
    {
        $vars['csrf'] = Csrf::token($req);
        $vars['base'] = $this->base;
        $vars['asset'] = fn (string $f): string => $this->base . '/assets/' . $f . '?v=' . $this->assetVersion($f);
        return Csrf::attach($req, Response::html($this->view->render($template, $vars), $status));
    }

    protected function redirect(string $subPath): Response
    {
        return Response::redirect($this->base . $subPath);
    }

    /** Serves a static file from app/resources/assets (outside the web root) under the secret path. */
    protected function asset(Request $req, string $name): Response
    {
        if (!preg_match('/^(fonts\/)?[a-z0-9][a-z0-9._-]{0,63}\.(css|js|svg|png|woff2|wasm)$/i', $name) || str_contains($name, '..')) {
            return Response::notFound();
        }
        $file = $this->app->root . '/app/resources/assets/' . $name;
        if (!is_file($file)) {
            return Response::notFound();
        }
        $types = ['css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'svg' => 'image/svg+xml',
                  'png' => 'image/png', 'woff2' => 'font/woff2', 'wasm' => 'application/wasm'];
        $etag = '"' . $this->assetVersion($name) . '"';
        if (($req->header('If-None-Match') ?? '') === $etag) {
            return new Response('', 304, ['ETag' => $etag, 'Cache-Control' => 'private, no-cache']);
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $res = new Response((string)file_get_contents($file), 200, [
            'Content-Type' => $types[$ext],
            // Always revalidate (cheap 304): an updated server must never run with stale client code.
            'Cache-Control' => 'private, no-cache',
            'ETag' => $etag,
        ]);
        if ($ext === 'js') {
            // Policy for the crypto Web Worker (worker scripts carry their own CSP).
            $res->header('Content-Security-Policy', "default-src 'self'; script-src 'self' 'wasm-unsafe-eval'; connect-src 'self'; object-src 'none'");
        }
        return $res;
    }

    private function assetVersion(string $f): string
    {
        $file = $this->app->root . '/app/resources/assets/' . $f;
        return is_file($file) ? substr(hash_file('sha256', $file), 0, 12) : '0';
    }

    protected function audit(): AuditLog
    {
        return new AuditLog($this->app->db());
    }

    protected function limiter(): RateLimiter
    {
        return new RateLimiter($this->app->db());
    }
}
