<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Payments\Payments;
use App\Services\AuditLogger;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Gateway credentials. Secrets are stored encrypted and never rendered back (empty field = keep). */
class PaymentSettingsController extends Controller
{
    /** key => [label, secret?] */
    public const FIELDS = [
        'zarinpal.merchant_id' => ['مرچنت کد زرین‌پال', true],
        'sep.terminal_id' => ['شماره ترمینال سامان', false],
        'mellat.terminal_id' => ['شماره ترمینال ملت', false],
        'mellat.username' => ['نام کاربری ملت', false],
        'mellat.password' => ['رمز ملت', true],
        'sadad.merchant_id' => ['شماره پذیرنده سداد', false],
        'sadad.terminal_id' => ['شماره ترمینال سداد', false],
        'sadad.key' => ['کلید ترمینال سداد (Base64)', true],
        'usdt.rate_toman' => ['نرخ هر USDT (تومان)', false],
        'usdt.address.trc20' => ['آدرس کیف پول TRC20', false],
        'usdt.address.erc20' => ['آدرس کیف پول ERC20', false],
        'usdt.address.bep20' => ['آدرس کیف پول BEP20', false],
        'usdt.trongrid_key' => ['کلید API ترون‌گرید (اختیاری)', true],
        'usdt.etherscan_key' => ['کلید API اترسکن v2 (برای ERC20 و BEP20)', true],
    ];

    public const FLAGS = ['zarinpal.enabled', 'zarinpal.sandbox', 'sep.enabled', 'mellat.enabled', 'sadad.enabled', 'card.enabled', 'usdt.enabled',
        'usdt.trc20.enabled', 'usdt.erc20.enabled', 'usdt.bep20.enabled'];

    public function __construct(private Settings $settings, private AuditLogger $audit) {}

    public function edit(Payments $payments): View
    {
        $this->authorize('payments.sensitive');
        $values = [];
        foreach (self::FIELDS as $k => [$label, $secret]) {
            $v = $this->settings->get('pay.'.$k);
            $values[$k] = $secret ? ($v ? 'ذخیره شده' : null) : $v;
        }

        return view('payments.settings', [
            'values' => $values,
            'flags' => collect(self::FLAGS)->mapWithKeys(fn ($f) => [$f => $this->settings->get('pay.'.$f) === '1'])->all(),
            'cards' => $payments->cards(),
            'enabled' => $payments->enabledMethods(),
            'callbackExample' => url('/pay/callback/…'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('payments.sensitive');
        $rules = [];
        foreach (self::FIELDS as $k => $_) {
            $rules[str_replace('.', '__', $k)] = ['nullable', 'string', 'max:200'];
        }
        $rules['usdt__rate_toman'] = ['nullable', 'integer', 'between:1000,100000000'];
        $rules['usdt__address__trc20'] = ['nullable', 'regex:/^T[1-9A-HJ-NP-Za-km-z]{33}$/'];
        $rules['usdt__address__erc20'] = ['nullable', 'regex:/^0x[0-9a-fA-F]{40}$/'];
        $rules['usdt__address__bep20'] = ['nullable', 'regex:/^0x[0-9a-fA-F]{40}$/'];
        $rules['cards'] = ['nullable', 'array', 'max:5'];
        $rules['cards.*.number'] = ['nullable', 'regex:/^[0-9۰-۹\s-]{16,23}$/u'];
        $rules['cards.*.holder'] = ['nullable', 'string', 'max:80'];
        $rules['cards.*.bank'] = ['nullable', 'string', 'max:40'];
        $d = $request->validate($rules, ['regex' => 'قالب :attribute درست نیست.'], ['usdt__address__trc20' => 'آدرس TRC20', 'usdt__address__erc20' => 'آدرس ERC20', 'usdt__address__bep20' => 'آدرس BEP20', 'cards.*.number' => 'شماره کارت']);

        $changed = [];
        foreach (self::FIELDS as $k => [$label, $secret]) {
            $v = trim((string) ($d[str_replace('.', '__', $k)] ?? ''));
            if ($secret) {
                if ($v === '') {
                    continue;   // keep
                }
                $this->settings->set('pay.'.$k, $v, encrypt: true);
                $changed[$k] = ['***', '***'];
            } elseif ($v !== (string) $this->settings->get('pay.'.$k)) {
                $changed[$k] = [$this->settings->get('pay.'.$k), $v ?: null];
                $this->settings->set('pay.'.$k, $v === '' ? null : $v);
            }
        }
        foreach ($request->input('clear', []) as $k) {
            if (isset(self::FIELDS[$k]) && self::FIELDS[$k][1]) {
                $this->settings->set('pay.'.$k, null);
                $changed[$k] = ['***', null];
            }
        }
        foreach (self::FLAGS as $f) {
            $v = $request->boolean(str_replace('.', '__', $f)) ? '1' : '0';
            if ($v !== ($this->settings->get('pay.'.$f) ?? '0')) {
                $changed[$f] = [$this->settings->get('pay.'.$f), $v];
                $this->settings->set('pay.'.$f, $v);
            }
        }
        $digits = fn ($s) => preg_replace('/\D/', '', strtr((string) $s, array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY), range(0, 9))));
        $cards = collect($d['cards'] ?? [])->map(fn ($c) => ['number' => $digits($c['number'] ?? ''), 'holder' => trim((string) ($c['holder'] ?? '')), 'bank' => trim((string) ($c['bank'] ?? ''))])
            ->filter(fn ($c) => strlen($c['number']) === 16)->values()->all();
        if (json_encode($cards) !== (string) $this->settings->get('pay.card.cards')) {
            $changed['card.cards'] = [null, count($cards).' کارت'];
            $this->settings->set('pay.card.cards', json_encode($cards, JSON_UNESCAPED_UNICODE));
        }
        if ($changed) {
            $this->audit->log('payments.settings', null, 'تنظیمات درگاه‌های پرداخت', $changed);
        }

        return back()->with('success', 'تنظیمات پرداخت ذخیره شد.');
    }

    public static function methodLabel(string $m): string
    {
        return Payment::METHODS[$m] ?? $m;
    }
}
