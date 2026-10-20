<?php
declare(strict_types=1);

/**
 * Scheduled tasks run by cron.php from a cPanel Cron Job (no SSH, no long-running process).
 * Each task is short, idempotent and safe to run every few minutes; overlapping runs are prevented
 * with a MySQL named lock, and the last result of every task is kept in cron_runs for the admin panel.
 */

function cron_tasks(): array
{
    return [
        'platform_daily' => ['label'=>'گزارش روزانه مدیران','run'=>fn()=>platform_daily_summary()],
        'admin_push' => ['label'=>'اعلان مرورگر مدیران','run'=>fn()=>admin_push_dispatch()],
        'expire_holds' => ['label' => 'آزادسازی رزروهای موقت منقضی', 'run' => fn() => cron_expire_holds()],
        'reminders' => ['label' => 'یادآوری پیش از نوبت', 'run' => fn() => fa_number(send_visit_reminders()) . ' یادآوری ارسال شد.'],
        'waitlist' => ['label' => 'صف انتظار و ظرفیت آزادشده', 'run' => function () {
            $s = waitlist_process();
            return fa_number($s['notified']) . ' اطلاع‌رسانی، ' . fa_number($s['booked']) . ' رزروشده، ' . fa_number($s['expired']) . ' پایان بازه.';
        }],
        'announcements' => ['label' => 'ارسال اطلاعیه‌های سراسری', 'run' => fn() => fa_number(announcements_process()) . ' اعلان اطلاعیه ثبت شد.'],
        'sms_dispatch' => ['label' => 'ارسال پیامک‌های صف', 'run' => fn() => cron_sms_dispatch()],
        'push_dispatch' => ['label' => 'ارسال اعلان‌های مرورگر', 'run' => function () {
            $s = push_dispatch(200);
            return fa_number($s['sent']) . ' ارسال، ' . fa_number($s['retry']) . ' تلاش مجدد، ' . fa_number($s['failed'] + $s['gone']) . ' ناموفق.';
        }],
        'subscription_warnings' => ['label' => 'هشدار پایان اشتراک پزشکان', 'run' => fn() => cron_subscription_warnings()],
        'subscription_proformas' => ['label' => 'انقضای پیش‌فاکتورهای پرداخت‌نشده', 'run' => fn() => cron_subscription_proformas()],
        'centers' => ['label' => 'اشتراک و دسترسی مراکز درمانی', 'run' => fn() => cron_centers()],
        'payment_stale' => ['label' => 'بستن تراکنش‌های بدون بازگشت از درگاه', 'run' => fn() => cron_payment_stale()],
        'search_cache' => ['label' => 'به‌روزرسانی اولین نوبت آزاد مطب‌ها برای جستجو', 'run' => fn() => fa_number(clinic_free_cron()) . ' مطب به‌روز شد.'],
        'backup' => ['label' => 'پشتیبان خودکار روزانه دیتابیس', 'run' => fn() => backup_auto()],
        'daily_summary' => ['label' => 'پیامک خلاصه پایان روز پزشکان (پرو)', 'run' => fn() => cron_daily_summary()],
        'entitlements' => ['label' => 'بررسی روزانه محدودیت‌های نسخه رایگان و پرو', 'run' => fn() => notice_once('entitlements:' . date('Y-m-d')) ? fa_number(entitlement_sync_all()) . ' پزشک بررسی شد.' : 'امروز انجام شده است.'],
        'cleanup' => ['label' => 'پاک‌سازی داده‌های موقت', 'run' => fn() => cron_cleanup()],
    ];
}

function run_cron(?array $only = null): array
{
    if ((int) db()->query("SELECT GET_LOCK('nobatyar_cron',1)")->fetchColumn() !== 1) {
        return ['skipped' => 'اجرای قبلی هنوز در حال انجام است.'];
    }
    $report = [];
    try {
        foreach (cron_tasks() as $name => $task) {
            if ($only !== null && !in_array($name, $only, true)) {
                continue;
            }
            query(
                "INSERT INTO cron_runs(task,last_started_at,last_status) VALUES(?,NOW(),'running') ON DUPLICATE KEY UPDATE last_started_at=NOW(),last_status='running'",
                [$name],
            );
            try {
                $message = (string) ($task['run'])();
                $status = 'ok';
            } catch (Throwable $e) {
                $message = error_message($e);
                $status = 'error';
                error_log('nobatyar cron ' . $name . ': ' . $e->getMessage());
            }
            query(
                'UPDATE cron_runs SET last_finished_at=NOW(),last_status=?,last_message=?,runs=runs+1 WHERE task=?',
                [$status, mb_substr($message, 0, 500), $name],
            );
            $report[$name] = $status . ': ' . $message;
        }
    } finally {
        db()->query("SELECT RELEASE_LOCK('nobatyar_cron')");
    }
    setting_set('cron_last_run', date('Y-m-d H:i:s'));
    return $report;
}

function cron_sms_dispatch(): string
{
    $stats = sms_dispatch(100);
    return sprintf(
        'ارسال %s، تلاش مجدد %s، ناموفق %s، نامشخص %s',
        fa_number($stats['sent']),
        fa_number($stats['retry']),
        fa_number($stats['failed']),
        fa_number($stats['unknown']),
    );
}

/**
 * A transaction sent to the bank that never came back within 24 hours is closed as failed. Banks reverse
 * unverified payments on their own well within that window, so no money is kept without an order.
 */
function cron_payment_stale(): string
{
    $n = 0;
    foreach (
        query(
            "SELECT * FROM payment_transactions WHERE status IN ('initiated','redirected') AND created_at < NOW() - INTERVAL 24 HOUR LIMIT 200",
        )->fetchAll()
        as $tx
    ) {
        $q = query(
            "UPDATE payment_transactions SET status='failed',error='بازگشتی از درگاه دریافت نشد.' WHERE id=? AND status IN ('initiated','redirected')",
            [$tx['id']],
        );
        if ($q->rowCount()) {
            payment_release($tx);
            $n++;
        }
    }
    return fa_number($n) . ' تراکنش رهاشده بسته شد.';
}

/** Center proformas, expiry warnings, and daily re-sync of center staff access with subscriptions. */
function cron_centers(): string
{
    $expired = center_expire_proformas();
    $warned = center_subscription_warnings();
    $synced = center_sync_all();
    return fa_number($expired) . ' پیش‌فاکتور منقضی، ' . fa_number($warned) . ' هشدار، ' . fa_number($synced) . ' مرکز همگام شد.';
}

function cron_expire_holds(): string
{
    $before = (int) query("SELECT COUNT(*) FROM bookings WHERE status='held' AND expires_at<=NOW()")->fetchColumn();
    expire_holds();
    return fa_number($before) . ' رزرو موقت بررسی شد.';
}

/**
 * Warnings at each configured threshold (e.g. 7, 3 and 1 days before the end). If cron missed a day,
 * the closest threshold that has not been sent yet is used, so a doctor never gets two for one window.
 */
function cron_subscription_warnings(): string
{
    if (!subscriptions_on()) {
        return 'فروش اشتراک غیرفعال است؛ هشداری ارسال نشد.';
    }
    $thresholds = array_values(array_filter(array_map('intval', explode(',', setting('subscription_warning_thresholds', '7,3,1')))));
    if (!$thresholds) {
        return 'هشداری تنظیم نشده است.';
    }
    $max = max($thresholds);
    $rows = query(
        "SELECT id,mobile,subscription_until,DATEDIFF(subscription_until,CURDATE())+1 days_left FROM doctors WHERE review_status='approved' AND subscription_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL ? DAY)",
        [$max - 1],
    )->fetchAll();
    $queued = 0;
    foreach ($rows as $d) {
        $left = (int) $d['days_left'];
        $applicable = array_filter($thresholds, fn($t) => $t >= $left);
        if (!$applicable) {
            continue;
        }
        $threshold = min($applicable);
        $key = 'subscription_expiring:' . $d['id'] . ':' . $d['subscription_until'] . ':' . $threshold;
        if (!notice_once($key)) {
            continue;
        }
        if ($d['mobile']) {
            sms_enqueue(
                'subscription_expiring',
                $d['mobile'],
                ['token' => (string) $left, 'token2' => digits(jdate($d['subscription_until']))],
                $key,
            );
        }
        notify_doctor((int) $d['id'], 'اشتراک نزدیک به پایان است', 'اشتراک شما ' . fa_number($left) . ' روز دیگر (' . jdate($d['subscription_until']) . ') به پایان می‌رسد. برای تمدید وارد بخش اشتراک شوید.');
        $queued++;
    }
    if ($queued) {
        sms_dispatch(50);
    }
    return fa_number($queued) . ' هشدار جدید ثبت شد.';
}

/** Records a one-time notice; returns false when it was already recorded (dedupe across runs). */

function cron_subscription_proformas(): string
{
    return fa_number(subscription_expire_proformas()) . ' پیش‌فاکتور منقضی شد.';
}

function cron_cleanup(): string
{
    $otp = query('DELETE FROM otp_challenges WHERE created_at<?', [time() - 7 * 86400])->rowCount();
    $limits = query('DELETE FROM auth_rate_limits WHERE window_start<?', [time() - 2 * 86400])->rowCount();
    $limits += query('DELETE FROM request_limits WHERE window_start<?', [time() - 2 * 86400])->rowCount();
    query('DELETE FROM notice_markers WHERE created_at<DATE_SUB(NOW(),INTERVAL 180 DAY)');
    query("DELETE FROM push_messages WHERE status IN ('sent','failed','cancelled') AND created_at<DATE_SUB(NOW(),INTERVAL 30 DAY)");
    // Log files older than twelve months.
    foreach (glob(log_dir() . '/*-*.log') ?: [] as $file) {
        if (filemtime($file) < time() - 366 * 86400) {
            @unlink($file);
        }
    }
    $sessions = session_files_cleanup();
    return 'کد ورود قدیمی: ' . fa_number($otp) . '، محدودیت‌های منقضی: ' . fa_number($limits) . '، نشست قدیمی: ' . fa_number($sessions);
}

function cron_status(): array
{
    $rows = query('SELECT * FROM cron_runs')->fetchAll();
    $byTask = [];
    foreach ($rows as $r) {
        $byTask[$r['task']] = $r;
    }
    return $byTask;
}

/**
 * 1.0.0-rc.18: sessions live in private/sessions; page requests never clean that folder (no random
 * clean-up in the middle of someone's work). Files untouched for 13 hours — beyond the 12-hour sign-in
 * limit — are removed here.
 */
function session_files_cleanup(): int
{
    $dir = dirname(__DIR__) . '/private/sessions';
    $n = 0;
    foreach (is_dir($dir) ? (glob($dir . '/sess_*') ?: []) : [] as $f) {
        if (@filemtime($f) < time() - 46800 && @unlink($f)) {
            $n++;
        }
    }
    return $n;
}
