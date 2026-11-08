<?php
declare(strict_types=1);

use PM\App;
use PM\Http\Kernel;
use PM\Http\Request;

function fakeRoot(bool $installed): string {
    $root = sys_get_temp_dir() . '/pmk-' . bin2hex(random_bytes(4));
    foreach (['config', 'storage/logs', 'app'] as $d) { mkdir("$root/$d", 0777, true); }
    // Point at the real code so views/assets exist.
    symlink(dirname(__DIR__, 2) . '/app/Views', "$root/app/Views");
    symlink(dirname(__DIR__, 2) . '/app/resources', "$root/app/resources");
    if ($installed) {
        // Deliberately unreachable database: any DB access would raise -> 500, never 404.
        file_put_contents("$root/config/.env", "APP_KEY=\"" . base64_encode(random_bytes(32)) . "\"\nAPP_PATH=\"SecretApp0123456\"\nADMIN_PATH=\"SecretAdm0123456\"\nDB_HOST=\"203.0.113.1\"\nDB_PORT=\"1\"\n");
        touch("$root/storage/installed.lock");
    }
    return $root;
}

function req(string $path, string $method = 'GET', bool $https = true): Request {
    return new Request($method, $path, [], [], [], ['HTTPS' => $https ? 'on' : '', 'HTTP_HOST' => 'example.test', 'REMOTE_ADDR' => '198.51.100.7', 'REQUEST_URI' => $path]);
}

test('Kernel (installed): every non-secret path is a uniform 404 without touching the database', function () {
    $k = new Kernel(App::boot(fakeRoot(true)));
    $ref = null;
    foreach (['/', '/index.php', '/robots.txt', '/favicon.ico', '/install', '/admin', '/api/v1/me', '/secretapp0123456/', '/SecretApp012345/', '/SecretApp01234567/',
              '/assets/style.css', '/private-messenger/config/.env', '/%2e%2e/config/.env'] as $p) {
        foreach (['GET', 'POST', 'HEAD'] as $m) {
            $r = $k->handle(req($p, $m));
            eq(404, $r->status, "$m $p");
            eq('noindex, nofollow, noarchive, nosnippet, noimageindex', $r->headers['X-Robots-Tag'] ?? null, "$m $p robots");
            eq('no-referrer', $r->headers['Referrer-Policy'] ?? null);
            $ref ??= $r->body;
            eq($ref, $r->body, 'identical body');
            ok(!isset($r->headers['Set-Cookie']) && $r->cookieHeaders() === [], 'no cookies on 404');
        }
    }
});

test('Kernel (installed): secret path without trailing slash redirects; http redirects to https', function () {
    $k = new Kernel(App::boot(fakeRoot(true)));
    $r = $k->handle(req('/SecretApp0123456'));
    eq(301, $r->status);
    eq('/SecretApp0123456/', $r->headers['Location']);
    $r = $k->handle(req('/SecretApp0123456/', 'GET', false));
    eq(301, $r->status);
    eq('https://example.test/SecretApp0123456/', $r->headers['Location']);
});

test('Kernel (installed): secret path reaches the app (DB unreachable -> 500, proving routing)', function () {
    $k = new Kernel(App::boot(fakeRoot(true)));
    $r = $k->handle(req('/SecretApp0123456/'));
    eq(500, $r->status);
    ok(!str_contains($r->body, '203.0.113.1'), 'no internal details leaked');
    // Static assets under the secret path do not need the DB.
    $r = $k->handle(req('/SecretApp0123456/assets/style.css'));
    eq(200, $r->status);
    eq('text/css; charset=utf-8', $r->headers['Content-Type']);
    eq(404, $k->handle(req('/SecretApp0123456/assets/../../config/.env'))->status);
    eq(404, $k->handle(req('/SecretApp0123456/assets/nope.css'))->status);
});

test('Kernel (not installed): only /install answers; everything else 404', function () {
    $root = fakeRoot(false);
    $k = new Kernel(App::boot($root));
    eq(404, $k->handle(req('/'))->status);
    eq(404, $k->handle(req('/SecretApp0123456/'))->status);
    eq(404, $k->handle(req('/install/other'))->status);
    $r = $k->handle(req('/install/'));
    eq(200, $r->status);
    ok(is_file("$root/storage/install-token.txt"), 'install token created');
    ok(str_contains($r->body, 'install-token.txt'));
    eq(301, $k->handle(req('/install/', 'GET', false))->status, 'installer requires https');
});
