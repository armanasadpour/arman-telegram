<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Reseller;
use App\Payments\Payments;
use App\Services\AuditLogger;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Accounting: reseller wallet top-up requests (card-to-card + receipt) and the bank cards shown to resellers. */
class AccountingController extends Controller
{
    public function __construct(private Payments $payments, private Settings $settings, private AuditLogger $audit) {}

    public function topups(Request $request): View
    {
        $this->authorize('payments.view');
        $status = in_array($request->query('status'), ['review', 'paid', 'rejected', 'all'], true) ? $request->query('status') : 'review';
        $base = Payment::query()->where('method', 'card')->where('purpose', 'topup')
            ->whereHas('wallet', fn ($w) => $w->where('owner_type', 'reseller'));
        $rows = (clone $base)->with('wallet', 'reviewer')->when($status !== 'all', fn ($q) => $q->where('status', $status))->orderByDesc('id')->paginate(20)->withQueryString();
        $resellers = Reseller::query()->whereIn('id', $rows->getCollection()->pluck('wallet.owner_id')->filter()->unique())->get()->keyBy('id');

        return view('accounting.topups', [
            'rows' => $rows, 'status' => $status, 'resellers' => $resellers,
            'counts' => ['review' => (clone $base)->where('status', 'review')->count(), 'paid' => (clone $base)->where('status', 'paid')->count(), 'rejected' => (clone $base)->where('status', 'rejected')->count()],
            'paidMonth' => (int) (clone $base)->where('status', 'paid')->where('paid_at', '>=', now()->subDays(30))->sum('amount_rial'),
        ]);
    }

    public function cards(): View
    {
        $this->authorize('payments.sensitive');

        return view('accounting.cards', ['cards' => $this->payments->topupCards()]);
    }

    public function saveCards(Request $request): RedirectResponse
    {
        $this->authorize('payments.sensitive');
        $d = $request->validate([
            'cards' => ['nullable', 'array', 'max:5'],
            'cards.*.number' => ['nullable', 'string', 'max:30'],
            'cards.*.sheba' => ['nullable', 'string', 'max:40'],
            'cards.*.holder' => ['nullable', 'string', 'max:80'],
            'cards.*.bank' => ['nullable', 'string', 'max:40'],
        ]);
        $cards = [];
        foreach ($d['cards'] ?? [] as $i => $c) {
            $num = self::digits($c['number'] ?? '');
            $sheba = self::digits($c['sheba'] ?? '');
            if ($num === '' && $sheba === '') {
                continue;
            }
            if ($num !== '' && strlen($num) !== 16) {
                return back()->withInput()->with('error', 'شماره کارت '.fa($i + 1).' باید ۱۶ رقم باشد.');
            }
            if ($sheba !== '' && strlen($sheba) !== 24) {
                return back()->withInput()->with('error', 'شماره شبا '.fa($i + 1).' باید ۲۴ رقم (بدون IR) باشد.');
            }
            $cards[] = ['number' => $num, 'sheba' => $sheba, 'holder' => trim((string) ($c['holder'] ?? '')), 'bank' => trim((string) ($c['bank'] ?? ''))];
        }
        $this->settings->set('topup.cards', json_encode($cards, JSON_UNESCAPED_UNICODE));
        $this->audit->log('accounting.cards', null, 'ویرایش کارت‌های بانکی شارژ کیف پول ('.count($cards).' کارت)');

        return back()->with('success', 'کارت‌های بانکی ذخیره شد.');
    }

    /** English digits only (Persian / Arabic digits converted; IR, spaces and dashes dropped). */
    public static function digits(?string $s): string
    {
        return preg_replace('/\D/', '', strtr((string) $s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']));
    }
}
