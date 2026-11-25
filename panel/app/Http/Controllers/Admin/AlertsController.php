<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BotOutbox;
use App\Models\BotUser;
use App\Services\Alerts\Alerts;
use App\Services\AuditLogger;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AlertsController extends Controller
{
    private const FLAGS = ['expiry_enabled', 'expired_enabled', 'volume_enabled', 'reseller_digest_enabled', 'admin_report_enabled', 'server_enabled'];

    public function __construct(private Alerts $alerts, private Settings $settings, private AuditLogger $audit) {}

    public function edit(Request $request): View
    {
        $this->authorize('settings.view');

        return view('alerts.settings', [
            'v' => collect(array_keys(Alerts::DEFAULTS))->mapWithKeys(fn ($k) => [substr($k, 7) => $this->alerts->get($k)])->all(),
            'linked' => BotUser::query()->where('user_id', $request->user()->id)->exists(),
            'customers' => BotUser::query()->whereHas('accounts')->count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('settings.update');
        $d = $request->validate([
            'expiry_days' => ['required', 'regex:/^\s*\d{1,2}(\s*,\s*\d{1,2})*\s*$/'],
            'volume_pct' => ['required', 'integer', 'between:1,90'],
            'volume_mb' => ['required', 'integer', 'between:0,1000000'],
            'reseller_low_balance_toman' => ['required', 'integer', 'between:0,1000000000'],
            'report_hour' => ['required', 'integer', 'between:0,23'],
        ], ['expiry_days.regex' => 'روزها را با کاما جدا کنید، مثلاً 3,1'], ['expiry_days' => 'روزهای یادآوری', 'volume_pct' => 'درصد حجم', 'volume_mb' => 'حجم', 'report_hour' => 'ساعت گزارش']);
        $changes = [];
        $values = $d + collect(self::FLAGS)->mapWithKeys(fn ($f) => [$f => $request->boolean($f) ? '1' : '0'])->all();
        foreach ($values as $k => $v) {
            $v = preg_replace('/\s+/', '', (string) $v);
            if ($v !== $this->alerts->get('alerts.'.$k)) {
                $changes['alerts.'.$k] = [$this->alerts->get('alerts.'.$k), $v];
                $this->settings->set('alerts.'.$k, $v);
            }
        }
        if ($changes) {
            $this->audit->log('settings.alerts', null, 'تنظیمات اعلان‌ها', $changes);
        }

        return back()->with('success', 'تنظیمات اعلان‌ها ذخیره شد.');
    }

    public function testReport(Request $request): RedirectResponse
    {
        $this->authorize('settings.view');
        $n = $this->alerts->dailyAdminReport(force: true, onlyUserId: $request->user()->id);

        return back()->with($n ? 'success' : 'error', $n ? 'گزارش آزمایشی در صف ارسال قرار گرفت (تا یک دقیقه دیگر می‌رسد).' : 'ابتدا از «اتصال به ربات» حساب خود را به یک ربات وصل کنید.');
    }

    public function outbox(Request $request): View
    {
        $this->authorize('bots.view');
        $q = BotOutbox::query()->with('bot')->orderByDesc('id');
        if ($s = $request->query('status')) {
            $q->where('status', $s);
        }

        return view('alerts.outbox', ['rows' => $q->paginate(50)->withQueryString()]);
    }
}
