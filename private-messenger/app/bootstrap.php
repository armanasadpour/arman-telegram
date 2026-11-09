<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80200) { // normally caught earlier by app/preflight.php
    http_response_code(500);
    exit('PHP 8.2+ required');
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (is_dir(dirname(__DIR__) . '/storage/logs') && is_writable(dirname(__DIR__) . '/storage/logs')) {
    ini_set('error_log', dirname(__DIR__) . '/storage/logs/php-error.log');
}

// Fatal errors (parse errors, missing functions, memory) would otherwise produce a blank page.
// Before installation the message is shown to help the installer; afterwards it is only logged.
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if ($e === null || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8', true, 500);
        header('X-Robots-Tag: noindex, nofollow');
    }
    $installed = is_file(dirname(__DIR__) . '/storage/installed.lock');
    $detail = $installed ? '' : '<p dir="ltr"><code>' . htmlspecialchars($e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line'], ENT_QUOTES, 'UTF-8') . '</code></p>'
        . '<p>این متن را برای پشتیبانی بفرستید. (این جزئیات فقط پیش از نصب نمایش داده می‌شود.)</p>';
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="robots" content="noindex"><title>خطا</title>'
        . '<body style="font-family:Tahoma,sans-serif;max-width:720px;margin:40px auto;padding:0 16px;line-height:1.9">'
        . '<h1 style="font-size:20px">خطای داخلی سرور</h1>' . $detail . '</body></html>';
});

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'PM\\', 3) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 3)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

ini_set('expose_php', '0');
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
