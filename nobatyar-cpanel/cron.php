<?php
declare(strict_types=1);

/**
 * Entry point for cPanel Cron Jobs. Two equivalent ways (pick one, every 5 minutes):
 *   php -q /home/USER/public_html/cron.php
 *   wget -q -O /dev/null "https://example.com/cron.php?token=CRON_TOKEN"
 * CRON_TOKEN is the cron_token value in config.php (also shown in the admin "زمان‌بندی" page).
 */

define('NOBATYAR_NO_SESSION', true);
require __DIR__ . '/core.php';
require __DIR__ . '/lib/jobs.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    $token = (string) ($_GET['token'] ?? '');
    $expected = (string) (config()['cron_token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        exit("forbidden\n");
    }
}
if (!schema_is_current()) {
    http_response_code(503);
    exit("upgrade required\n");
}
@set_time_limit(120);
foreach (run_cron() as $task => $line) {
    echo $task . ' => ' . $line . "\n";
}
