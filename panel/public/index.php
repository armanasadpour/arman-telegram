<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Installed inside public_html (or a subfolder of it): the root .htaccess rewrites every
// request into /public. Make the app see its real base path (e.g. "/panel" not "/panel/public").
if (isset($_SERVER['SCRIPT_NAME'], $_SERVER['REQUEST_URI'])) {
    $publicDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    $uriPath = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (str_ends_with($publicDir, '/public') && ! str_starts_with($uriPath, $publicDir.'/') && $uriPath !== $publicDir) {
        $_SERVER['SCRIPT_NAME'] = substr($publicDir, 0, -7).'/index.php';
        $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
    }
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
