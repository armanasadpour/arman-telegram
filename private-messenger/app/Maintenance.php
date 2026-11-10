<?php
declare(strict_types=1);

namespace PM;

use PM\Auth\RateLimiter;
use PM\Support\Clock;

/**
 * Periodic housekeeping. Runs from a cPanel cron job if configured, and otherwise
 * automatically after normal requests at most once every 15 minutes ("pseudo-cron"),
 * so the system works even when the host offers no cron.
 */
final class Maintenance
{
    public const INTERVAL_SECONDS = 900;

    /** @return array<string, int>|null stats, or null when another run holds the lock */
    public static function run(App $app, string $trigger): ?array
    {
        $db = $app->db();
        if ((int)$db->scalar('SELECT GET_LOCK(?, 0)', ['pm_job_maintenance']) !== 1) {
            return null;
        }
        $runId = $db->insert('INSERT INTO cron_runs (job, trigger_source, started_at, status) VALUES (?, ?, ?, ?)',
            ['maintenance', $trigger, Clock::db(), 'running']);
        try {
            $old = Clock::dbIn('-30 days');
            $stats = [
                'otp_deleted' => $db->run('DELETE FROM otp_requests WHERE created_at < ?', [Clock::dbIn('-1 day')])->rowCount(),
                'rate_limits_deleted' => (new RateLimiter($db))->purge(),
                'user_sessions_deleted' => $db->run('DELETE FROM user_sessions WHERE (revoked_at IS NOT NULL AND revoked_at < ?) OR expires_at < ?', [$old, $old])->rowCount(),
                'admin_sessions_deleted' => $db->run('DELETE FROM admin_sessions WHERE (revoked_at IS NOT NULL AND revoked_at < ?) OR expires_at < ?', [$old, $old])->rowCount(),
                'download_tokens_deleted' => $db->run('DELETE FROM download_tokens WHERE expires_at < ?', [Clock::db()])->rowCount(),
                'ephemeral_deleted' => $db->run('DELETE FROM ephemeral_envelopes WHERE expires_at < ?', [Clock::db()])->rowCount(),
                'envelopes_expired' => $db->run('DELETE FROM message_envelopes WHERE expires_at < ?', [Clock::db()])->rowCount(),
                'files' => (new Files\AttachmentService($db, $app->settings(), $app->root . '/storage/attachments'))->cleanup(),
                'cron_runs_deleted' => $db->run('DELETE FROM cron_runs WHERE started_at < ?', [Clock::dbIn('-90 days')])->rowCount(),
            ];
            $db->run('UPDATE cron_runs SET finished_at = ?, status = ?, stats = ? WHERE id = ?', [Clock::db(), 'ok', json_encode($stats), $runId]);
            return $stats;
        } catch (\Throwable $e) {
            $db->run('UPDATE cron_runs SET finished_at = ?, status = ?, error_message = ? WHERE id = ?',
                [Clock::db(), 'failed', mb_substr($e->getMessage(), 0, 500), $runId]);
            throw $e;
        } finally {
            $db->scalar('SELECT RELEASE_LOCK(?)', ['pm_job_maintenance']);
        }
    }

    /** Called after the response has been sent. Cheap when nothing is due (one indexed query). */
    public static function runIfDue(App $app): void
    {
        try {
            $last = $app->db()->scalar("SELECT MAX(started_at) FROM cron_runs WHERE job = 'maintenance'");
            if ($last === null || strtotime($last . ' UTC') < Clock::now()->getTimestamp() - self::INTERVAL_SECONDS) {
                self::run($app, 'pseudo');
            }
        } catch (\Throwable $e) {
            error_log('[pm] pseudo-cron: ' . $e->getMessage());
        }
    }
}
