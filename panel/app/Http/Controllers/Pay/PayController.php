<?php

namespace App\Http\Controllers\Pay;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Payments\PaymentException;
use App\Payments\Payments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use RuntimeException;

/**
 * Public payment pages, addressed by the payment's random UUID (sent to the payer in the bot or portal).
 * They never change money by themselves: the bank callback is verified server to server.
 */
class PayController extends Controller
{
    public function __construct(private Payments $payments) {}

    public function show(string $uuid): Response
    {
        $p = $this->find($uuid);
        $res = response()->view('pay.show', [
            'p' => $p,
            'cards' => $p->method === 'card' ? $this->payments->cards() : [],
            'address' => $p->method === 'usdt' ? ($this->payments->usdtNetworks()[$p->usdt_network] ?? null) : null,
        ]);
        // The "go to bank" form posts here, then is redirected to the bank: CSP form-action covers the whole chain.
        if ($p->isOnline() && ! $p->isFinal()) {
            $res->header('X-Panel-Form-Action', $this->payments->gateway($p->method)->origin());
        }

        return $res;
    }

    public function go(string $uuid): RedirectResponse|Response
    {
        $p = $this->find($uuid);
        if ($p->status === 'paid' || $p->isFinal()) {
            return redirect()->route('pay.show', $p->uuid);
        }
        try {
            $r = $this->payments->start($p);
        } catch (PaymentException|RuntimeException $e) {
            return redirect()->route('pay.show', $p->uuid)->with('error', $e->getMessage());
        }
        $origin = parse_url($r->url, PHP_URL_SCHEME).'://'.parse_url($r->url, PHP_URL_HOST).(parse_url($r->url, PHP_URL_PORT) ? ':'.parse_url($r->url, PHP_URL_PORT) : '');
        if ($r->method === 'GET') {
            return redirect()->away($r->url);
        }

        return response()->view('pay.redirect', ['p' => $p, 'r' => $r])->header('X-Panel-Form-Action', $origin);
    }

    /** Bank return (GET or POST, no CSRF: the bank posts here). */
    public function callback(Request $request, string $uuid): View
    {
        $p = $this->find($uuid);
        $p = $this->payments->handleCallback($p, $request->except(['_token']));

        return view('pay.show', ['p' => $p->fresh(), 'cards' => [], 'address' => null, 'returned' => true]);
    }

    public function card(Request $request, string $uuid): RedirectResponse
    {
        $p = $this->find($uuid);
        $d = $request->validate([
            'tracking' => ['required', 'string', 'max:40'],
            'last4' => ['required', 'regex:/^[0-9۰-۹]{4}$/u'],
            'paid_at' => ['nullable', 'string', 'max:40'],
            'receipt' => ['nullable', 'file', 'image', 'max:3072'],
        ], [], ['tracking' => 'شماره پیگیری', 'last4' => '۴ رقم آخر کارت', 'receipt' => 'تصویر رسید']);
        $path = $request->file('receipt')?->store('receipts', 'local');
        try {
            $this->payments->reportCard($p, 'پیگیری: '.$d['tracking'].' — کارت: ****'.$this->digits($d['last4']).(($d['paid_at'] ?? '') !== '' ? ' — زمان: '.$d['paid_at'] : ''), $path);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('pay.show', $p->uuid)->with('success', 'اطلاعات واریز ثبت شد. پس از بررسی مدیر، کیف پول شارژ می‌شود.');
    }

    public function usdt(Request $request, string $uuid): RedirectResponse
    {
        $p = $this->find($uuid);
        $hash = (string) $request->validate(['tx_hash' => ['required', 'string', 'max:100']], [], ['tx_hash' => 'هش تراکنش'])['tx_hash'];
        try {
            $p = $this->payments->submitUsdtHash($p, $hash);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('pay.show', $p->uuid)->with($p->status === 'paid' ? 'success' : 'warning',
            $p->status === 'paid' ? 'تراکنش تأیید و کیف پول شارژ شد.' : 'هش ثبت شد: '.($p->error ?? 'در انتظار تأیید شبکه').' — خودکار دوباره بررسی می‌شود.');
    }

    private function find(string $uuid): Payment
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/', $uuid), 404);

        return Payment::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function digits(string $s): string
    {
        return strtr($s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
    }
}
