<?php
declare(strict_types=1);

// Usage (cPanel → Cron Jobs):  */15 * * * * /usr/local/bin/php /home/USER/private-messenger/app/Cli/cron.php maintenance
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';

use PM\App;

$app = App::boot(dirname(__DIR__, 2));
if (!$app->isInstalled()) {
    fwrite(STDERR, "not installed\n");
    exit(1);
}
$job = $argv[1] ?? '';
if ($job !== 'maintenance') {
    fwrite(STDERR, "unknown job; available: maintenance\n");
    exit(2);
}
try {
    $stats = PM\Maintenance::run($app, 'cron');
    echo $stats === null ? "already running\n" : json_encode($stats) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
