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
        // Re-authentication happens in the same form (no redirect round-trip).
        request()->validate(['password' => ['required', 'current_password']], [], ['password' => 'رمز عبور']);
        @set_time_limit(300);
        $before = SystemStatus::pendingMigrations();
        $code = Artisan::call('migrate', ['--force' => true]);
        $out = trim(Artisan::output());
        Cache::forget('system.pending_migrations');
        if ($code !== 0) {
            $audit->log('system.upgrade_failed', null, 'اجرای به‌روزرسانی دیتابیس ناموفق بود', null, mb_substr($out, 0, 450));
            // Show the database error to the super admin (paths/credentials are not part of migrate output).
            $detail = mb_substr(preg_replace('/\s+/', ' ', $out), 0, 400);

            return back()->with('error', 'به‌روزرسانی دیتابیس ناموفق بود: '.$detail);
        }
        Artisan::call('permissions:sync');
        Artisan::call('view:clear');
        app(Settings::class)->set('system.version', config('panel.version'));
        $audit->log('system.upgraded', null, 'به‌روزرسانی به نسخه '.config('panel.version').' — '.$before.' Migration اجرا شد');

        return back()->with('success', 'به‌روزرسانی به نسخه '.config('panel.version').' انجام شد.');
    }
}
