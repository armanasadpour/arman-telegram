<?php
declare(strict_types=1);

// The only PHP file inside the web root. Every request is routed here by .htaccess.
// The application itself lives outside the web root.
$candidates = [
    dirname(__DIR__),                              // layout A: docroot = <app>/public
    dirname(__DIR__) . '/private-messenger',       // layout B: public_html next to ~/private-messenger
];
foreach ($candidates as $root) {
    if (is_file($root . '/app/bootstrap.php')) {
        require $root . '/app/bootstrap.php';
        (new PM\Http\Kernel(PM\App::boot($root)))->handle(PM\Http\Request::fromGlobals())->send();
        return;
    }
}
http_response_code(404);
