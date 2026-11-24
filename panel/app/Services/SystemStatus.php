<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Real, live health checks for the status page and dashboard. Each check returns
 * ['label', 'status' => ok|warn|fail, 'detail'].
 */
class SystemStatus
{
    public function __construct(private Settings $settings) {}

    /** @return list<array{key:string,label:string,status:string,detail:string}> */
    public function checks(): array
    {
        return [
            $this->php(),
            $this->database(),
            $this->migrations(),
            $this->cron(),
            $this->queue(),
            $this->storage(),
            $this->environment(),
            $this->https(),
        ];
    }

    private function php(): array
    {
        $ok = version_compare(PHP_VERSION, '8.3.0', '>=');

        return $this->row('php', 'نسخه PHP', $ok ? 'ok' : 'fail', PHP_VERSION.' | memory_limit='.ini_get('memory_limit').' | max_execution_time='.ini_get('max_execution_time'));
    }

    private function database(): array
    {
        try {
            $t = microtime(true);
            $version = DB::connection()->getDriverName() === 'sqlite'
                ? 'SQLite '.DB::selectOne('select sqlite_version() as v')->v
                : DB::selectOne('select version() as v')->v;
            $ms = (int) round((microtime(true) - $t) * 1000);

            return $this->row('db', 'دیتابیس', 'ok', "$version ({$ms}ms)");
        } catch (Throwable $e) {
            return $this->row('db', 'دیتابیس', 'fail', 'اتصال برقرار نشد');
        }
    }

    private function migrations(): array
    {
        try {
            $files = collect(glob(database_path('migrations/*.php')))->map(fn ($f) => basename($f, '.php'));
            $ran = DB::table('migrations')->pluck('migration');
            $pending = $files->diff($ran)->count();

            return $this->row('migrations', 'Migrationها', $pending ? 'warn' : 'ok',
                $pending ? fa((string) $pending).' Migration اجرا نشده — نصب‌کننده/به‌روزرسانی را اجرا کنید' : 'همه اجرا شده‌اند ('.fa($ran->count()).')');
        } catch (Throwable) {
            return $this->row('migrations', 'Migrationها', 'fail', 'جدول migrations در دسترس نیست');
        }
    }

    private function cron(): array
    {
        $last = $this->settings->get('system.cron_last_run');
        if (! $last) {
            return $this->row('cron', 'Cron (زمان‌بندی)', 'fail', 'هنوز هیچ اجرایی ثبت نشده — Cron Job را در cPanel تنظیم کنید');
        }
        $at = CarbonImmutable::parse($last);
        $stale = $at->lt(now()->subMinutes(5));

        return $this->row('cron', 'Cron (زمان‌بندی)', $stale ? 'warn' : 'ok', 'آخرین اجرا: '.jdate($at).' ('.fa_ago($at).')');
    }

    private function queue(): array
    {
        try {
            $pending = DB::table('jobs')->count();
            $failed = DB::table('failed_jobs')->count();

            return $this->row('queue', 'صف کارها', $failed ? 'warn' : 'ok', 'در انتظار: '.fa($pending).' | ناموفق: '.fa($failed));
        } catch (Throwable) {
            return $this->row('queue', 'صف کارها', 'fail', 'جدول صف در دسترس نیست');
        }
    }

    private function storage(): array
    {
        $dirs = [storage_path('framework/sessions'), storage_path('framework/cache'), storage_path('logs'), base_path('bootstrap/cache')];
        $bad = array_filter($dirs, fn ($d) => ! is_writable($d));

        return $this->row('storage', 'دسترسی نوشتن پوشه‌ها', $bad ? 'fail' : 'ok',
            $bad ? 'غیرقابل نوشتن: '.implode('، ', array_map(fn ($d) => str_replace(base_path().'/', '', $d), $bad)) : 'storage و bootstrap/cache قابل نوشتن‌اند');
    }

    private function environment(): array
    {
        $env = app()->environment();
        $debug = (bool) config('app.debug');
        $status = ($env === 'production' && $debug) ? 'fail' : ($env === 'production' ? 'ok' : 'warn');

        return $this->row('env', 'محیط اجرا', $status, "APP_ENV=$env | APP_DEBUG=".($debug ? 'true' : 'false')
            .($env === 'production' && $debug ? ' — حالت Debug در محیط عملیاتی باید خاموش باشد' : ''));
    }

    private function https(): array
    {
        $secure = request()->isSecure();

        return $this->row('https', 'HTTPS', $secure ? 'ok' : 'warn', $secure ? 'اتصال رمزنگاری‌شده' : 'پنل روی HTTP باز شده؛ SSL (AutoSSL) را فعال کنید');
    }

    /** @return array{key:string,label:string,status:string,detail:string} */
    private function row(string $key, string $label, string $status, string $detail): array
    {
        return compact('key', 'label', 'status', 'detail');
    }
}
