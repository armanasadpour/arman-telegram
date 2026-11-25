<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Settings;
use App\Services\SystemStatus;
use App\Services\Updater;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class SystemController extends Controller
{
    public function status(SystemStatus $status): View
    {
        $this->authorize('system.view');

        return view('system.status', ['checks' => $status->checks(), 'checkedAt' => now(), 'pending' => SystemStatus::pendingMigrations(),
            'recent' => request()->user()->can('audit.view') ? \App\Models\AuditLog::query()->orderByDesc('id')->limit(10)->get() : collect()]);
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
            // Show the database error to the super admin (paths/credentials are not part of migrate output).
            $detail = mb_substr(preg_replace('/\s+/', ' ', $out), 0, 400);

            return back()->with('error', 'به‌روزرسانی دیتابیس ناموفق بود: '.$detail);
        }
        Artisan::call('permissions:sync');
        Artisan::call('view:clear');
        app(Settings::class)->set('system.version', config('panel.version'));
        $audit->log('system.upgraded', null, 'به‌روزرسانی به نسخه '.config('panel.version').' — '.$before.' Migration اجرا شد');

        return redirect()->route('system.status')->with('success', 'به‌روزرسانی به نسخه '.config('panel.version').' انجام شد.');
    }

    public function updateForm(): View
    {
        abort_unless(request()->user()->isSuperAdmin(), 403, 'فقط مدیرکل می‌تواند به‌روزرسانی را نصب کند.');

        return view('system.update', [
            'version' => config('panel.version'),
            'maxUpload' => min($this->bytes(ini_get('upload_max_filesize')), $this->bytes(ini_get('post_max_size'))),
            'zip' => class_exists(\ZipArchive::class),
        ]);
    }

    /** Upload a release ZIP (small update or full package), install it, then run the database upgrade in the new code. */
    public function updateUpload(Request $request, Updater $updater, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->isSuperAdmin(), 403, 'فقط مدیرکل می‌تواند به‌روزرسانی را نصب کند.');
        if (! $request->hasFile('package')) {
            return back()->with('error', 'فایلی دریافت نشد. اگر فایل بزرگ است (بسته کامل)، حجمش از سقف آپلود هاست بیشتر است؛ از بسته به‌روزرسانی سبک استفاده کنید.');
        }
        $request->validate(['package' => ['required', 'file', 'mimes:zip', 'max:204800']], ['package.mimes' => 'فقط فایل ZIP پنل قابل نصب است.']);
        @set_time_limit(300);
        try {
            $r = $updater->install($request->file('package'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        Cache::forget('system.pending_migrations');
        $audit->log('system.update_installed', null, 'نصب '.($r['type'] === 'full' ? 'بسته کامل' : 'بسته به‌روزرسانی').' '.$r['from'].' ← '.$r['to'].' ('.$r['files'].' فایل)');

        return redirect()->route('system.status')->with('success', 'فایل‌های نسخه '.$r['to'].' نصب شد ('.fa($r['files']).' فایل).')->with('auto_upgrade', true);
    }

    private function bytes(string|false $v): int
    {
        $v = trim((string) $v);
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n,
        } ?: PHP_INT_MAX;
    }
}
