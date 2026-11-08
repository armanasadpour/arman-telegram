<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80200) {
    http_response_code(500);
    exit('PHP 8.2+ required');
}

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'PM\\', 3) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 3)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('expose_php', '0');
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
