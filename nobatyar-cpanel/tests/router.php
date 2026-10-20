<?php
// Test-only router for the PHP built-in server: mirrors the friendly-URL rules of .htaccess.
// Requests with the header X-Nobatyar-Pretty: 1 (or a friendly path) run as if mod_rewrite were active.
$root = dirname(__DIR__);
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$rules = [
    '~^/doctor/([0-9]+)(/[^/]*)?/?$~u' => ['/doctor.php', fn($m) => ['id' => $m[1]]],
    '~^/city/([0-9]+)(/[^/]*)?/?$~u' => ['/index.php', fn($m) => ['city' => $m[1]]],
    '~^/specialty/([0-9]+)(/[^/]*)?/?$~u' => ['/index.php', fn($m) => ['specialty_id' => $m[1], 'view' => 'doctors']],
    '~^/article/([^/]+)/?$~u' => ['/article.php', fn($m) => ['slug' => $m[1]]],
    '~^/page/([a-z-]+)/?$~u' => ['/page.php', fn($m) => ['slug' => $m[1]]],
    '~^/robots\.txt$~' => ['/robots.php', fn($m) => []],
    '~^/sitemap\.xml$~' => ['/sitemap.php', fn($m) => []],
];
$script = null;
foreach ($rules as $re => [$target, $args]) {
    if (preg_match($re, $path, $m)) {
        $script = $target;
        $_GET = $args($m) + $_GET;
        $_REQUEST = $_GET + $_POST;
        $_SERVER['NOBATYAR_REWRITE'] = '1';
        break;
    }
}
if (($_SERVER['HTTP_X_NOBATYAR_PRETTY'] ?? '') === '1') {
    $_SERVER['NOBATYAR_REWRITE'] = '1';
}
if ($script === null) {
    if (!isset($_SERVER['NOBATYAR_REWRITE'])) {
        return false;
    }
    $script = $path === '/' ? '/index.php' : $path;
    if (!str_ends_with($script, '.php') || !is_file($root . $script)) {
        return false;
    }
}
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $script;
$_SERVER['SCRIPT_FILENAME'] = $root . $script;
chdir($root);
require $root . $script;
