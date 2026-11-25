<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\Package;
use App\Models\Reseller;
use App\Models\ResellerSale;
use App\Models\VpnAccount;
use App\Services\Finance\ResellerPricing;
use App\Services\Finance\ResellerSales;
use App\Services\Finance\WalletService;
use App\Services\Pricing\PackageRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

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
                'accounts' => VpnAccount::query()->where('reseller_id', $me->id)->whereNull('ibs_deleted_at')->count(),
                'online' => VpnAccount::query()->where('reseller_id', $me->id)->where('is_online', true)->count(),
                'month_sales' => (clone $ok)->where('created_at', '>=', $monthStart)->count(),
                'month_profit' => (int) (clone $ok)->where('created_at', '>=', $monthStart)->sum('profit_rial'),
                'month_spent' => (int) (clone $ok)->where('created_at', '>=', $monthStart)->sum('cost_rial'),
            ],
            'recent' => ResellerSale::query()->with('package')->where('reseller_id', $me->id)->orderByDesc('id')->limit(5)->get(),
        ]);
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
        $target = $request->query('account') ? $this->ownAccount($me, (int) $request->query('account')) : null;
        $packages = Package::query()->where('is_active', true)->where('type', $target ? '!=' : '=', 'new')->orderBy('sort_order')->orderBy('price_rial')->get()
            ->filter(fn ($p) => ! $target || PackageRules::applicability($p, $target) === null);

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
            'sale_price_toman' => ['required', 'integer', 'between:0,1000000000'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s۰-۹]+$/u'],
            'username' => ['nullable', 'string', 'min:3', 'max:32', 'regex:/^[a-z0-9][a-z0-9_.-]*$/'],
        ], [], ['package_id' => 'بسته', 'sale_price_toman' => 'قیمت فروش به مشتری', 'customer_name' => 'نام مشتری', 'customer_phone' => 'تلفن مشتری', 'username' => 'نام کاربری']);

        $target = ! empty($d['account_id']) ? $this->ownAccount($me, (int) $d['account_id']) : null;
        try {
            $r = $this->sales->sell($me, Package::query()->findOrFail($d['package_id']), $d['sale_key'], (int) $d['sale_price_toman'] * 10, $d, $target, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        $sale = $r['sale'];

        return match ($sale->status) {
            'completed' => redirect()->route('portal.sales.show', $sale)->with('success', 'فروش انجام شد.')->with('new_password', $r['password']),
            'refunded', 'failed' => redirect()->route('portal.sales.show', $sale)->with('error', 'انجام نشد و مبلغ به کیف پول شما برگشت: '.$sale->error),
            default => redirect()->route('portal.sales.show', $sale)->with('error', 'انجام کار در سرور نامشخص ماند و برای بررسی ثبت شد. مبلغ پس از روشن شدن وضعیت یا تکمیل می‌شود یا برمی‌گردد.'),
        };
    }

    public function sales(Request $request): View
    {
        $me = $this->me($request);

        return view('portal.sales', ['sales' => ResellerSale::query()->with('package', 'account')->where('reseller_id', $me->id)->orderByDesc('id')->paginate(20)]);
    }

    public function sale(Request $request, int $sale): View
    {
        $me = $this->me($request);
        $s = ResellerSale::query()->with('package', 'account')->where('reseller_id', $me->id)->findOrFail($sale);

        return view('portal.sale', ['s' => $this->sales->syncOne($s)]);
    }

    public function accounts(Request $request): View
    {
        $me = $this->me($request);
        $q = trim((string) $request->query('q', ''));

        return view('portal.accounts', [
            'q' => $q,
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

    private function ownAccount(Reseller $me, int $id): VpnAccount
    {
        return VpnAccount::query()->where('reseller_id', $me->id)->whereNull('ibs_deleted_at')->findOrFail($id);
    }
}
