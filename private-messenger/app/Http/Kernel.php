<?php
declare(strict_types=1);

namespace PM\Http;

use PM\App;
use PM\Controllers\AdminController;
use PM\Controllers\AppController;
use PM\Controllers\InstallController;

/**
 * Stealth front controller:
 *  - Only two secret path prefixes (APP_PATH, ADMIN_PATH) exist. Everything else, including "/",
 *    gets the same Apache-style 404 before any database access.
 *  - Every response carries X-Robots-Tag noindex and Referrer-Policy no-referrer.
 */
final class Kernel
{
    private bool $servedApp = false;

    public function __construct(private readonly App $app)
    {
    }

    /** Runs after the response was sent: pseudo-cron (only for requests that reached the app). */
    public function terminate(): void
    {
        if (!$this->servedApp) {
            return; // stealth 404 paths never touch the database
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        \PM\Maintenance::runIfDue($this->app);
    }

    public function handle(Request $req): Response
    {
        try {
            $res = $this->route($req);
        } catch (\Throwable $e) {
            error_log('[pm] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $res = Response::error('خطای داخلی رخ داد. لطفاً دوباره تلاش کنید.', 500);
        }
        return $this->secureHeaders($res, $req);
    }

    private function route(Request $req): Response
    {
        $trimmed = ltrim($req->path, '/');
        $slash = strpos($trimmed, '/');
        $first = $slash === false ? $trimmed : substr($trimmed, 0, $slash);
        $rest = $slash === false ? null : substr($trimmed, $slash);

        if (!$this->app->isInstalled()) {
            if ($first === 'install') {
                return $this->requireHttps($req) ?? (new InstallController($this->app, '/install'))->handle($req->withPath($rest ?? '/'));
            }
            return Response::notFound();
        }

        $appPath = (string)$this->app->env('APP_PATH');
        $adminPath = (string)$this->app->env('ADMIN_PATH');
        foreach ([[$appPath, AppController::class], [$adminPath, AdminController::class]] as [$secret, $controller]) {
            if (strlen($secret) >= 12 && hash_equals($secret, $first)) {
                if ($rest === null) {
                    return Response::redirect('/' . $secret . '/', 301);
                }
                if (($redirect = $this->requireHttps($req)) !== null) {
                    return $redirect;
                }
                $this->servedApp = true;
                return (new $controller($this->app, '/' . $secret))->handle($req->withPath($rest));
            }
        }
        return Response::notFound();
    }

    private function requireHttps(Request $req): ?Response
    {
        if ($req->isHttps()) {
            return null;
        }
        $uri = (string)($req->server['REQUEST_URI'] ?? '/');
        return Response::redirect('https://' . $req->host() . $uri, 301);
    }

    private function secureHeaders(Response $res, Request $req): Response
    {
        $res->header('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet, noimageindex');
        $res->header('Referrer-Policy', 'no-referrer');
        $res->header('X-Content-Type-Options', 'nosniff');
        if ($req->isHttps()) {
            $res->header('Strict-Transport-Security', 'max-age=31536000');
        }
        if ($res->status === 404) {
            return $res;
        }
        $res->header('X-Frame-Options', 'DENY');
        $res->header('Cross-Origin-Opener-Policy', 'same-origin');
        $res->header('Permissions-Policy', 'camera=(self), microphone=(self), geolocation=(), payment=(), usb=()');
        if (!isset($res->headers['Cache-Control'])) {
            $res->header('Cache-Control', 'no-store');
        }
        if (str_starts_with($res->headers['Content-Type'] ?? '', 'text/html')) {
            $res->header('Content-Security-Policy', "default-src 'self'; script-src 'self' 'wasm-unsafe-eval'; worker-src 'self'; style-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; "
                . "font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
        }
        return $res;
    }
}
