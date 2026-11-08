<?php
declare(strict_types=1);

// Usage (cPanel → Cron Jobs):  */15 * * * * /usr/local/bin/php /home/USER/private-messenger/app/Cli/cron.php maintenance
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';

use PM\App;
use PM\Auth\RateLimiter;
use PM\Support\Clock;

$app = App::boot(dirname(__DIR__, 2));
if (!$app->isInstalled()) {
    fwrite(STDERR, "not installed\n");
    exit(1);
}
$job = $argv[1] ?? '';
$jobs = [
    'maintenance' => static function (App $app): array {
        $db = $app->db();
        $old = Clock::dbIn('-30 days');
        $now = Clock::db();
        return [
            'otp_deleted' => $db->run('DELETE FROM otp_requests WHERE created_at < ?', [Clock::dbIn('-1 day')])->rowCount(),
            'rate_limits_deleted' => (new RateLimiter($db))->purge(),
            'user_sessions_deleted' => $db->run('DELETE FROM user_sessions WHERE (revoked_at IS NOT NULL AND revoked_at < ?) OR expires_at < ?', [$old, $old])->rowCount(),
            'admin_sessions_deleted' => $db->run('DELETE FROM admin_sessions WHERE (revoked_at IS NOT NULL AND revoked_at < ?) OR expires_at < ?', [$old, $old])->rowCount(),
            'download_tokens_deleted' => $db->run('DELETE FROM download_tokens WHERE expires_at < ?', [$now])->rowCount(),
            'cron_runs_deleted' => $db->run('DELETE FROM cron_runs WHERE started_at < ?', [Clock::dbIn('-90 days')])->rowCount(),
        ];
    },
];
if (!isset($jobs[$job])) {
    fwrite(STDERR, 'unknown job; available: ' . implode(', ', array_keys($jobs)) . "\n");
    exit(2);
}

$db = $app->db();
if ((int)$db->scalar('SELECT GET_LOCK(?, 0)', ['pm_cron_' . $job]) !== 1) {
    fwrite(STDERR, "already running\n");
    exit(0);
}
$runId = $db->insert('INSERT INTO cron_runs (job, trigger_source, started_at, status) VALUES (?, ?, ?, ?)', [$job, 'cron', Clock::db(), 'running']);
try {
    $stats = $jobs[$job]($app);
    $db->run('UPDATE cron_runs SET finished_at = ?, status = ?, stats = ? WHERE id = ?', [Clock::db(), 'ok', json_encode($stats), $runId]);
    echo json_encode($stats), "\n";
} catch (Throwable $e) {
    $db->run('UPDATE cron_runs SET finished_at = ?, status = ?, error_message = ? WHERE id = ?', [Clock::db(), 'failed', mb_substr($e->getMessage(), 0, 500), $runId]);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    $db->scalar('SELECT RELEASE_LOCK(?)', ['pm_cron_' . $job]);
}
