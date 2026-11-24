<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Ibs\ErrorMessages;
use App\Ibs\IbsConnector;
use App\Services\AuditLogger;
use App\Services\Ibs\AccountSync;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class IbsSettingsController extends Controller
{
    public function __construct(private Settings $settings, private AuditLogger $audit, private IbsConnector $ibs) {}

    public function edit(): View
    {
        $this->authorize('settings.view');

        return view('ibs.settings', [
            'host' => $this->settings->get('ibs.host'),
            'port' => $this->settings->get('ibs.port', '1235'),
            'username' => $this->settings->get('ibs.username'),
            'hasPassword' => $this->settings->get('ibs.password') !== null,
            'timeout' => $this->settings->get('ibs.timeout', '15'),
            'renewalMode' => $this->settings->get('accounts.renewal_mode', 'smart'),
            'staleMinutes' => $this->settings->get('sync.stale_minutes', '30'),
            'prefix' => $this->settings->get('accounts.username_prefix', 'u'),
            'health' => $this->ibs->health(),
            'test' => session('ibs_test'),
        ]);
    }

    /** Connection settings include the IBSng admin password: a sensitive operation. */
    public function update(Request $request): RedirectResponse
    {
        $this->authorize('settings.sensitive');
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:60'],
            'password' => ['nullable', 'string', 'max:200'],
            'timeout' => ['required', 'integer', 'between:3,60'],
            'renewal_mode' => ['required', 'in:smart,from_expiry'],
            'stale_minutes' => ['required', 'integer', 'between:5,1440'],
            'prefix' => ['required', 'string', 'max:10', 'regex:/^[a-z][a-z0-9_]*$/'],
        ], [], ['host' => 'میزبان', 'port' => 'پورت', 'username' => 'نام کاربری مدیر', 'password' => 'رمز مدیر', 'timeout' => 'مهلت پاسخ',
            'stale_minutes' => 'آستانه قدیمی بودن داده', 'prefix' => 'پیشوند نام کاربری']);

        $map = ['ibs.host' => $data['host'], 'ibs.port' => (string) $data['port'], 'ibs.username' => $data['username'], 'ibs.timeout' => (string) $data['timeout'],
            'accounts.renewal_mode' => $data['renewal_mode'], 'sync.stale_minutes' => (string) $data['stale_minutes'], 'accounts.username_prefix' => $data['prefix']];
        $before = [];
        foreach ($map as $k => $v) {
            $before[$k] = $this->settings->get($k);
        }
        $diff = AuditLogger::diff($before, $map);
        foreach ($diff as $k => [, $new]) {
            $this->settings->set($k, $new);
        }
        if (($data['password'] ?? '') !== '') {
            $this->settings->set('ibs.password', $data['password'], encrypt: true);
            $diff['ibs.password'] = ['***', '***'];
        }
        $this->ibs->resetBreaker();
        if ($diff) {
            $this->audit->log('settings.ibs_update', null, 'تغییر تنظیمات اتصال IBSng', $diff);
        }

        return back()->with('success', $diff ? 'تنظیمات ذخیره شد. برای اطمینان «تست اتصال» را بزنید.' : 'تغییری ایجاد نشد.');
    }

    public function test(): RedirectResponse
    {
        $this->authorize('settings.update');
        $this->ibs->resetBreaker();
        $t = microtime(true);
        try {
            $admin = $this->ibs->adminInfo();
            $groups = $this->ibs->listGroups();
            $result = ['ok' => true, 'ms' => (int) round((microtime(true) - $t) * 1000), 'admin' => $admin['name'] ?? $admin['username'] ?? '', 'groups' => count($groups)];
        } catch (Throwable $e) {
            $result = ['ok' => false, 'error' => ErrorMessages::for($e)];
        }
        $this->audit->log('settings.ibs_test', null, $result['ok'] ? 'تست اتصال IBSng موفق' : 'تست اتصال IBSng ناموفق: '.$result['error']);

        return back()->with('ibs_test', $result);
    }

    public function syncCatalog(AccountSync $sync): RedirectResponse
    {
        $this->authorize('ibs_groups.update');
        try {
            $r = $sync->syncCatalog();

            return back()->with('success', 'همگام‌سازی انجام شد: '.fa($r['groups']).' گروه و '.fa($r['charges']).' تعرفه.');
        } catch (Throwable $e) {
            return back()->with('error', ErrorMessages::for($e));
        }
    }
}
