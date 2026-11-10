<?php
declare(strict_types=1);

namespace PM\Admin;

use PM\Infra\Database;
use PM\Support\Clock;

/**
 * Non-content statistics for the admin panel. Every number here is computed from metadata only
 * (row counts, timestamps, sizes, statuses). Nothing reads, decrypts or samples ciphertext.
 */
final class Stats
{
    public const METRICS = [
        'messages' => 'پیام‌ها و رویدادهای رمزنگاری‌شده',
        'senders' => 'کاربران فرستنده',
        'new_users' => 'حساب‌های جدید',
        'logins' => 'ورود موفق (نشست جدید)',
        'login_failed' => 'ورود ناموفق',
        'otp_sent' => 'کد پیامکی ارسال‌شده',
        'calls' => 'تماس‌ها',
        'call_minutes' => 'دقیقه مکالمه',
        'calls_failed' => 'تماس ناموفق',
        'uploads' => 'فایل‌های آپلودشده',
        'upload_mb' => 'حجم آپلود (مگابایت)',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /** Increments a daily counter (best effort: a stats failure must never break the request). */
    public static function bump(Database $db, string $metric, int $n = 1): void
    {
        try {
            $db->run('INSERT INTO daily_stats (day, metric, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = value + VALUES(value)',
                [Clock::now()->format('Y-m-d'), $metric, $n]);
        } catch (\Throwable) {
            // table missing during an update, etc.
        }
    }

    private function since(string $modify): string
    {
        return Clock::now()->modify($modify)->format('Y-m-d H:i:s');
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $db = $this->db;
        $users = array_map('intval', array_column($db->all('SELECT status, COUNT(*) n FROM users GROUP BY status'), 'n', 'status'));
        $msg = $db->one('SELECT COUNT(*) total, COALESCE(SUM(created_at > ?), 0) d1, COALESCE(SUM(created_at > ?), 0) d7 FROM messages',
            [$this->since('-1 day'), $this->since('-7 days')]);
        $calls = static fn (string $from) => $db->one("SELECT COUNT(*) n, COALESCE(SUM(duration_seconds), 0) secs, COALESCE(SUM(status = 'failed'), 0) failed,
                                                             COALESCE(SUM(status = 'missed'), 0) missed FROM calls WHERE created_at > ?", [$from]);
        return [
            'users' => $users,
            'users_total' => array_sum($users) - ($users['deleted'] ?? 0),
            'online' => (int)$db->scalar('SELECT COUNT(DISTINCT user_id) FROM devices WHERE status = ? AND last_seen_at > ?', ['active', $this->since('-2 minutes')]),
            'online_15' => (int)$db->scalar('SELECT COUNT(DISTINCT user_id) FROM user_sessions WHERE revoked_at IS NULL AND last_used_at > ?', [$this->since('-15 minutes')]),
            'active_7d' => (int)$db->scalar('SELECT COUNT(DISTINCT user_id) FROM user_sessions WHERE last_used_at > ?', [$this->since('-7 days')]),
            'sessions' => (int)$db->scalar('SELECT COUNT(*) FROM user_sessions WHERE revoked_at IS NULL AND expires_at > ?', [Clock::db()]),
            'devices' => (int)$db->scalar("SELECT COUNT(*) FROM devices WHERE status = 'active'"),
            'locked' => (int)$db->scalar('SELECT COUNT(*) FROM users WHERE locked_until > ?', [Clock::db()]),
            'messages' => array_map('intval', $msg),
            'pending_envelopes' => (int)$db->scalar('SELECT COUNT(*) FROM message_envelopes'),
            'groups' => (int)$db->scalar("SELECT COUNT(*) FROM conversations WHERE type = 'group'"),
            'directs' => (int)$db->scalar("SELECT COUNT(*) FROM conversations WHERE type = 'direct'"),
            'calls_1d' => array_map('intval', $calls($this->since('-1 day'))),
            'calls_7d' => array_map('intval', $calls($this->since('-7 days'))),
        ];
    }

    /**
     * One row per day (UTC) for the last $days days, newest first.
     * @return list<array<string, int|string>>
     */
    public function daily(int $days): array
    {
        $days = max(1, min(366, $days));
        $from = Clock::now()->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        $rows = [];
        for ($i = 0; $i < $days; $i++) {
            $d = Clock::now()->modify("-$i days")->format('Y-m-d');
            $rows[$d] = ['day' => $d] + array_fill_keys(array_keys(self::METRICS), 0);
        }
        $put = function (string $sql, array $map) use (&$rows, $from): void {
            foreach ($this->db->all($sql, [$from . ' 00:00:00']) as $r) {
                if (isset($rows[$r['d']])) {
                    foreach ($map as $col => $metric) {
                        $rows[$r['d']][$metric] = (int)$r[$col];
                    }
                }
            }
        };
        $put('SELECT DATE(created_at) d, COUNT(*) n, COUNT(DISTINCT sender_user_id) s FROM messages WHERE created_at >= ? GROUP BY d', ['n' => 'messages', 's' => 'senders']);
        $put('SELECT DATE(created_at) d, COUNT(*) n FROM users WHERE created_at >= ? GROUP BY d', ['n' => 'new_users']);
        $put('SELECT DATE(created_at) d, COUNT(*) n FROM user_sessions WHERE created_at >= ? GROUP BY d', ['n' => 'logins']);
        $put("SELECT DATE(created_at) d, COUNT(*) n FROM otp_requests WHERE created_at >= ? AND user_id IS NOT NULL AND status <> 'expired' GROUP BY d", ['n' => 'otp_sent']);
        $put("SELECT DATE(created_at) d, COUNT(*) n, CEIL(COALESCE(SUM(duration_seconds), 0) / 60) m, SUM(status = 'failed') f FROM calls WHERE created_at >= ? GROUP BY d",
            ['n' => 'calls', 'm' => 'call_minutes', 'f' => 'calls_failed']);
        $put("SELECT DATE(created_at) d, COUNT(*) n, ROUND(COALESCE(SUM(size_bytes), 0) / 1048576) mb FROM attachments WHERE created_at >= ? AND upload_status = 'complete' GROUP BY d",
            ['n' => 'uploads', 'mb' => 'upload_mb']);
        foreach ($this->db->all("SELECT day, value FROM daily_stats WHERE metric = 'login.failed' AND day >= ?", [$from]) as $r) {
            if (isset($rows[$r['day']])) {
                $rows[$r['day']]['login_failed'] = (int)$r['value'];
            }
        }
        return array_values($rows);
    }

    /** Per-user metadata for the user detail page. */
    public function forUser(int $userId): array
    {
        $db = $this->db;
        return [
            'messages_7d' => (int)$db->scalar('SELECT COUNT(*) FROM messages WHERE sender_user_id = ? AND created_at > ?', [$userId, $this->since('-7 days')]),
            'messages' => (int)$db->scalar('SELECT COUNT(*) FROM messages WHERE sender_user_id = ?', [$userId]),
            'files' => array_map('intval', $db->one("SELECT COUNT(*) n, COALESCE(SUM(size_bytes), 0) b FROM attachments WHERE uploader_user_id = ? AND upload_status = 'complete'", [$userId])),
            'calls' => (int)$db->scalar('SELECT COUNT(*) FROM calls WHERE caller_user_id = ? OR callee_user_id = ?', [$userId, $userId]),
            'last_seen' => $db->scalar("SELECT MAX(last_seen_at) FROM devices WHERE user_id = ?", [$userId]),
            'contacts' => (int)$db->scalar('SELECT COUNT(*) FROM user_contacts WHERE user_id = ?', [$userId]),
            'groups' => (int)$db->scalar("SELECT COUNT(*) FROM conversation_members m JOIN conversations c ON c.id = m.conversation_id WHERE m.user_id = ? AND c.type = 'group'", [$userId]),
        ];
    }

    /**
     * Health of the services the messenger depends on.
     * @return list<array{name:string, ok:bool|null, detail:string}>  ok=null means "warning"
     */
    public function health(string $root, int $quota): array
    {
        $out = [];
        try {
            $v = (string)$this->db->scalar('SELECT VERSION()');
            $out[] = ['name' => 'دیتابیس', 'ok' => true, 'detail' => 'متصل — ' . $v];
        } catch (\Throwable $e) {
            $out[] = ['name' => 'دیتابیس', 'ok' => false, 'detail' => 'خطا در اتصال'];
        }
        $cron = $this->db->one("SELECT finished_at, status, trigger_source FROM cron_runs WHERE job = 'maintenance' AND finished_at IS NOT NULL ORDER BY id DESC LIMIT 1");
        $realCron = $this->db->scalar("SELECT MAX(finished_at) FROM cron_runs WHERE job = 'maintenance' AND trigger_source = 'cron'");
        $age = $cron ? Clock::now()->getTimestamp() - strtotime($cron['finished_at'] . ' UTC') : null;
        $out[] = ['name' => 'نگهداری خودکار (Cron)', 'ok' => $realCron !== null && strtotime($realCron . ' UTC') > Clock::now()->getTimestamp() - 3600 ? true : ($age !== null && $age < 86400 ? null : false),
            'detail' => $realCron !== null ? 'آخرین اجرای Cron: ' . \PM\Support\Fa::dateTime($realCron)
                : ($cron ? 'Cron واقعی اجرا نشده؛ فقط اجرای خودکار هنگام بازدید (' . \PM\Support\Fa::dateTime($cron['finished_at']) . ')' : 'هرگز اجرا نشده')];
        $failed = (int)$this->db->scalar("SELECT COUNT(*) FROM cron_runs WHERE status IN ('failed','partial') AND started_at > ?", [$this->since('-7 days')]);
        $out[] = ['name' => 'خطای کارهای زمان‌بندی‌شده (۷ روز)', 'ok' => $failed === 0, 'detail' => $failed === 0 ? 'بدون خطا' : \PM\Support\Fa::digits($failed) . ' اجرای ناموفق/ناقص — صفحه سیستم'];
        $dir = $root . '/storage/attachments';
        $free = @disk_free_space(is_dir($dir) ? $dir : $root);
        $out[] = ['name' => 'فضای دیسک', 'ok' => $free === false ? null : ($free > 1073741824 ? true : ($free > 104857600 ? null : false)),
            'detail' => $free === false ? 'میزبان اجازه خواندن فضای آزاد را نمی‌دهد' : 'فضای آزاد گزارش‌شده توسط سیستم‌عامل: ' . \PM\Support\Fa::bytes((int)$free)
                . ($quota > 0 ? ' (سهمیه هاست وارد‌شده: ' . \PM\Support\Fa::bytes($quota) . ')' : '')];
        foreach (['storage/attachments' => 'پوشه فایل‌ها', 'storage/tmp' => 'پوشه موقت', 'storage/logs' => 'پوشه لاگ'] as $d => $label) {
            $ok = is_dir($root . '/' . $d) && is_writable($root . '/' . $d);
            $out[] = ['name' => $label, 'ok' => $ok, 'detail' => $ok ? 'قابل نوشتن' : 'قابل نوشتن نیست: ' . $d];
        }
        $log = $root . '/storage/logs/php-error.log';
        $recent = $this->recentErrors($log, 86400);
        $out[] = ['name' => 'خطاهای PHP (۲۴ ساعت)', 'ok' => $recent === 0 ? true : null, 'detail' => $recent === 0 ? 'خطایی ثبت نشده' : \PM\Support\Fa::digits($recent) . ' خطا در storage/logs/php-error.log'];
        $out[] = ['name' => 'HTTPS', 'ok' => ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
            'detail' => 'رمزنگاری سرتاسری در مرورگر فقط روی HTTPS کار می‌کند'];
        return $out;
    }

    /** Counts log lines with a timestamp newer than $seconds (reads at most the last 256 KiB). */
    public function recentErrors(string $file, int $seconds): int
    {
        if (!is_file($file) || !is_readable($file)) {
            return 0;
        }
        $size = (int)filesize($file);
        $h = @fopen($file, 'rb');
        if ($h === false) {
            return 0;
        }
        fseek($h, max(0, $size - 262144));
        $data = (string)stream_get_contents($h);
        fclose($h);
        $limit = Clock::now()->getTimestamp() - $seconds;
        $n = 0;
        foreach (explode("\n", $data) as $line) {
            if (preg_match('/^\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2})(?: ([A-Za-z_\/+-]+))?\]/', $line, $m)) {
                $t = strtotime($m[1] . (isset($m[2]) && $m[2] !== '' ? ' ' . $m[2] : ' UTC'));
                if ($t !== false && $t >= $limit) {
                    $n++;
                }
            }
        }
        return $n;
    }
}
