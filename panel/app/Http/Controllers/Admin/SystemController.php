<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Settings;
use App\Services\SystemStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class SystemController extends Controller
{
    public function status(SystemStatus $status): View
    {
        $this->authorize('system.view');

        return view('system.status', ['checks' => $status->checks(), 'checkedAt' => now(), 'pending' => SystemStatus::pendingMigrations()]);
    }

    /**
     * Web upgrade (no SSH): after extracting a new release over the old files, the super admin
     * applies pending migrations and permission changes here. Backup the database first.
     */
    public function upgrade(AuditLogger $audit): RedirectResponse
    {
        abort_unless(request()->user()->isSuperAdmin(), 403, 'فقط مدیرکل می‌تواند به‌روزرسانی را اجرا کند.');
        @set_time_limit(300);
        $before = SystemStatus::pendingMigrations();
        $code = Artisan::call('migrate', ['--force' => true]);
        $out = trim(Artisan::output());
        Cache::forget('system.pending_migrations');
        if ($code !== 0) {
            $audit->log('system.upgrade_failed', null, 'اجرای به‌روزرسانی دیتابیس ناموفق بود', null, mb_substr($out, 0, 450));

            return back()->with('error', 'به‌روزرسانی دیتابیس ناموفق بود. جزئیات در storage/logs ثبت شد.');
        }
        Artisan::call('permissions:sync');
        Artisan::call('view:clear');
        app(Settings::class)->set('system.version', config('panel.version'));
        $audit->log('system.upgraded', null, 'به‌روزرسانی به نسخه '.config('panel.version').' — '.$before.' Migration اجرا شد');

        return back()->with('success', 'به‌روزرسانی به نسخه '.config('panel.version').' انجام شد.');
    }
}
