<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\MtAccount;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Reseller;
use App\Models\ResellerSale;
use App\Models\VpnAccount;
use App\Payments\Payments;
use App\Services\Finance\ResellerPricing;
use App\Services\Finance\ResellerSales;
use App\Services\Finance\WalletService;
use App\Services\Mikrotik\MtActions;
use App\Services\Pricing\PackageRules;
use App\Services\Reports\CsvExport;
use App\Services\Reports\Reports;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reseller portal. Every query is scoped to the logged-in reseller; another reseller's
 * accounts or sales are 404 (not 403) so their existence is not revealed.
 */
class PortalController extends Controller
{
    public function __construct(private WalletService $wallets, private ResellerPricing $pricing, private ResellerSales $sales) {}

    private function me(Request $r): Reseller
    {
        return $r->user()->reseller;
    }

    public function dashboard(Request $request): View
    {
        $me = $this->me($request);
        $this->sales->syncPending();
        $monthStart = now(config('app.display_timezone'))->startOfMonth()->utc();
        $ok = ResellerSale::query()->where('reseller_id', $me->id)->where('status', 'completed');

        return view('portal.dashboard', [
            'me' => $me,
            'wallet' => $this->wallets->walletFor('reseller', $me->id),
            'stats' => [
                'accounts' => VpnAccount::query()->where('reseller_id', $me->id)->whereNull('ibs_deleted_at')->count() + MtAccount::query()->where('reseller_id', $me->id)->count(),
                'online' => VpnAccount::query()->where('reseller_id', $me->id)->where('is_online', true)->count() + MtAccount::query()->where('reseller_id', $me->id)->where('is_online', true)->count(),
                'month_sales' => (clone $ok)->where('created_at', '>=', $monthStart)->count(),
                'month_profit' => (int) (clone $ok)->where('created_at', '>=', $monthStart)->sum('profit_rial'),
                'month_spent' => (int) (clone $ok)->where('created_at', '>=', $monthStart)->sum('cost_rial'),
            ],
            'protocols' => $this->protocolCards($me),
            'recent' => ResellerSale::query()->with('package')->where('reseller_id', $me->id)->orderByDesc('id')->limit(5)->get(),
            'daily' => app(Reports::class)->daily(...array_merge(app(Reports::class)->lastDays(30), [$me->id])),
        ]);
    }

    /** Own accounts per protocol (only protocols the reseller has accounts in or can sell). */
    private function protocolCards($me): array
    {
        $newPkgs = Package::query()->with('mtGroup')->where('is_active', true)->where('type', 'new')->get();
        $canSell = $me->isActive();
        $cards = [];
        $v = VpnAccount::query()->where('reseller_id', $me->id)->whereNull('ibs_deleted_at');
        $total = (clone $v)->count();
        if ($total > 0 || $newPkgs->contains(fn ($p) => ! $p->isMikrotik())) {
            $cards['openvpn'] = [
                'title' => 'OpenVPN', 'url' => route('portal.accounts'), 'create' => $canSell ? route('portal.sell') : null,
                'active' => (clone $v)->where('locked', false)->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', now()))->count(),
                'online' => (clone $v)->where('is_online', true)->count(),
                'expired' => (clone $v)->where('expires_at', '<', now())->count(),
            ];
        }
        foreach (['l2tp' => 'L2TP', 'wireguard' => 'WireGuard'] as $p => $title) {
            $q = MtAccount::query()->where('reseller_id', $me->id)->where('protocol', $p);
            if ((clone $q)->count() > 0 || $newPkgs->contains(fn ($x) => $x->isMikrotik() && $x->mtGroup?->protocol === $p)) {
                $cards[$p] = [
                    'title' => $title, 'url' => route('portal.accounts'), 'create' => $canSell ? route('portal.sell') : null,
                    'active' => (clone $q)->where('status', 'active')->count(),
                    'online' => (clone $q)->where('is_online', true)->count(),
                    'expired' => (clone $q)->whereIn('status', ['expired', 'depleted'])->count(),
                ];
            }
        }

        return $cards;
    }

    public function prices(Request $request): View
    {
        $me = $this->me($request);
        $packages = Package::query()->with('category')->where('is_active', true)->orderBy('type')->orderBy('sort_order')->orderBy('price_rial')->get();

        return view('portal.prices', ['me' => $me, 'rows' => $packages->map(fn ($p) => ['p' => $p, 'q' => $this->pricing->quote($me, $p)])]);
    }

    public function sellForm(Request $request): View
    {
        $me = $this->me($request);
        $target = $request->query('account') ? $this->ownAccount($me, (int) $request->query('account'))
            : ($request->query('mt') ? $this->ownMt($me, (int) $request->query('mt')) : null);
        $packages = Package::query()->with('mtGroup.router')->where('is_active', true)->where('type', $target ? '!=' : '=', 'new')->orderBy('sort_order')->orderBy('price_rial')->get()
            ->filter(fn ($p) => $target ? ($target instanceof MtAccount ? MtActions::applicability($p, $target) : PackageRules::applicability($p, $target)) === null
                : (! $p->isMikrotik() || ($p->mtGroup?->is_active && $p->mtGroup->router?->is_active)));

        return view('portal.sell', [
            'me' => $me,
            'target' => $target,
            'rows' => $packages->map(fn ($p) => ['p' => $p, 'q' => $this->pricing->quote($me, $p)]),
            'wallet' => $this->wallets->walletFor('reseller', $me->id),
            'saleKey' => (string) Str::uuid(),
        ]);
    }

    public function sell(Request $request): RedirectResponse
    {
        $me = $this->me($request);
        $d = $request->validate([
            'sale_key' => ['required', 'uuid'],
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'account_id' => ['nullable', 'integer'],
            'mt_account_id' => ['nullable', 'integer'],
            'sale_price_toman' => ['required', 'integer', 'between:0,1000000000'],
            'customer_name' => [$request->filled('account_id') || $request->filled('mt_account_id') ? 'nullable' : 'required', 'string', 'max:100'],
            'customer_phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s۰-۹]+$/u'],
            'username' => ['nullable', 'string', 'min:3', 'max:32', 'regex:/^[a-z0-9][a-z0-9_.-]*$/'],
            'password' => ['nullable', 'string', 'max:64', 'regex:/^[^\x00-\x1F\x7F]+$/'],
        ], [], ['password' => 'رمز', 'package_id' => 'بسته', 'sale_price_toman' => 'قیمت فروش به مشتری', 'customer_name' => 'نام مشتری', 'customer_phone' => 'تلفن مشتری', 'username' => 'نام کاربری']);

        $target = ! empty($d['account_id']) ? $this->ownAccount($me, (int) $d['account_id'])
            : (! empty($d['mt_account_id']) ? $this->ownMt($me, (int) $d['mt_account_id']) : null);
        try {
            $r = $this->sales->sell($me, Package::query()->findOrFail($d['package_id']), $d['sale_key'], (int) $d['sale_price_toman'] * 10, $d, $target, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        $sale = $r['sale'];

        return match ($sale->status) {
            'completed' => redirect()->route('portal.sales.show', $sale)->with('success', 'فروش انجام شد.')->with('new_password', $r['password'])->with('mt_reveal', $sale->mt_account_id),
            'refunded', 'failed' => redirect()->route('portal.sales.show', $sale)->with('error', 'انجام نشد و مبلغ به کیف پول شما برگشت: '.$sale->error),
            default => redirect()->route('portal.sales.show', $sale)->with('error', 'انجام کار در سرور نامشخص ماند و برای بررسی ثبت شد. مبلغ پس از روشن شدن وضعیت یا تکمیل می‌شود یا برمی‌گردد.'),
        };
    }

    public function sales(Request $request): View
    {
        $me = $this->me($request);

        return view('portal.sales', ['sales' => ResellerSale::query()->with('package', 'account', 'mtAccount')->where('reseller_id', $me->id)->orderByDesc('id')->paginate(20)]);
    }

    public function sale(Request $request, int $sale): View
    {
        $me = $this->me($request);
        $s = ResellerSale::query()->with('package', 'account', 'mtAccount.group')->where('reseller_id', $me->id)->findOrFail($sale);

        return view('portal.sale', ['s' => $this->sales->syncOne($s)]);
    }

    public function accounts(Request $request): View
    {
        $me = $this->me($request);
        $q = trim((string) $request->query('q', ''));

        return view('portal.accounts', [
            'q' => $q,
            'mt' => MtAccount::query()->with('group')->where('reseller_id', $me->id)
                ->when($q !== '', fn ($w) => $w->where('username', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%'))
                ->orderByDesc('is_online')->orderBy('username')->limit(200)->get(),
            'accounts' => VpnAccount::query()->with('group.charge')->where('reseller_id', $me->id)->whereNull('ibs_deleted_at')
                ->when($q !== '', fn ($w) => $w->where('username', 'like', "%$q%"))
                ->orderByDesc('is_online')->orderBy('username')->paginate(25)->withQueryString(),
        ]);
    }

    public function account(Request $request, int $account): View
    {
        $me = $this->me($request);

        return view('portal.account', [
            'a' => $this->ownAccount($me, $account)->load('group.charge'),
            'sales' => ResellerSale::query()->with('package')->where('reseller_id', $me->id)->where('vpn_account_id', $account)->orderByDesc('id')->get(),
        ]);
    }

    public function ledger(Request $request): View
    {
        $me = $this->me($request);
        $wallet = $this->wallets->walletFor('reseller', $me->id);

        return view('portal.ledger', ['wallet' => $wallet, 'entries' => LedgerEntry::query()->where('wallet_id', $wallet->id)->orderByDesc('id')->paginate(25)]);
    }

    /** Own data only: sales / ledger / accounts as Excel CSV (last N days, max 366). */
    public function export(Request $request, string $type, CsvExport $csv): StreamedResponse
    {
        $me = $this->me($request);
        abort_unless(in_array($type, ['sales', 'ledger', 'accounts'], true), 404);
        $r = app(Reports::class);
        [$f, $t] = $r->range(...$r->lastDays(min(366, max(1, (int) $request->query('days', 90)))));

        return $csv->download($type, $f, $t, 'my-'.$type.'.csv', $me->id);
    }

    public function topupForm(Request $request, Payments $payments): View
    {
        $me = $this->me($request);

        return view('portal.topup', [
            'wallet' => $this->wallets->walletFor('reseller', $me->id),
            'methods' => $payments->enabledMethods(),
            'networks' => array_intersect_key(Payment::NETWORKS, $payments->usdtNetworks()),
            'recent' => Payment::query()->where('wallet_id', $this->wallets->walletFor('reseller', $me->id)->id)->orderByDesc('id')->limit(10)->get(),
        ]);
    }

    public function topup(Request $request, Payments $payments): RedirectResponse
    {
        $me = $this->me($request);
        $d = $request->validate([
            'amount_toman' => ['required', 'integer', 'between:1000,200000000'],
            'method' => ['required', 'string', 'in:'.implode(',', array_keys($payments->enabledMethods()) ?: ['none'])],
            'network' => ['required_if:method,usdt', 'nullable', 'in:trc20,erc20,bep20'],
        ], [], ['amount_toman' => 'مبلغ', 'method' => 'روش پرداخت', 'network' => 'شبکه']);
        try {
            $p = $payments->create($d['method'], $this->wallets->walletFor('reseller', $me->id), (int) $d['amount_toman'] * 10,
                ['created_by' => $request->user()->id, 'usdt_network' => $d['method'] === 'usdt' ? $d['network'] : null]);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('pay.show', $p->uuid);
    }

    /** A reseller's own L2TP / WireGuard account, with its connection details (the reseller delivers them). */
    public function mtAccount(Request $request, int $account): View
    {
        $me = $this->me($request);
        $a = $this->ownMt($me, $account)->load('group', 'router');

        return view('portal.mt-account', [
            'a' => $a,
            'sales' => ResellerSale::query()->with('package')->where('reseller_id', $me->id)->where('mt_account_id', $account)->orderByDesc('id')->get(),
        ]);
    }

    private function ownMt(Reseller $me, int $id): MtAccount
    {
        return MtAccount::query()->where('reseller_id', $me->id)->findOrFail($id);
    }

    private function ownAccount(Reseller $me, int $id): VpnAccount
    {
        return VpnAccount::query()->where('reseller_id', $me->id)->whereNull('ibs_deleted_at')->findOrFail($id);
    }
}
