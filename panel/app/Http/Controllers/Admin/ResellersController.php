<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\MtAccount;
use App\Models\Package;
use App\Models\PackageCategory;
use App\Models\Payment;
use App\Models\Reseller;
use App\Models\ResellerCategoryDiscount;
use App\Models\ResellerPrice;
use App\Models\ResellerSale;
use App\Models\Role;
use App\Models\User;
use App\Models\VpnAccount;
use App\Services\AuditLogger;
use App\Services\Finance\ResellerPricing;
use App\Services\Finance\ResellerSales;
use App\Services\Finance\WalletService;
use App\Services\Ibs\AccountActions;
use App\Services\Mikrotik\MtActions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ResellersController extends Controller
{
    public function __construct(private AuditLogger $audit, private WalletService $wallets) {}

    public function index(): View
    {
        $this->authorize('resellers.view');
        $resellers = Reseller::query()->with('wallet', 'user')
            ->withCount(['sales as sales_ok' => fn ($q) => $q->where('status', 'completed')])
            ->withSum(['sales as profit' => fn ($q) => $q->where('status', 'completed')], 'profit_rial')
            ->orderBy('name')->get();

        return view('resellers.index', [
            'resellers' => $resellers,
            'review' => ResellerSale::query()->where('status', 'needs_review')->count(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('resellers.create');

        return view('resellers.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('resellers.create');
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s۰-۹]+$/u'],
            'telegram' => ['nullable', 'string', 'max:64'],
            'username' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-zA-Z0-9_.-]+$/', 'unique:users,username'],
            'password' => ['required', 'confirmed', 'min:4', 'max:200'],
            'global_discount_percent' => ['nullable', 'numeric', 'between:0,100'],
            'account_prefix' => ['required', 'string', 'regex:'.Reseller::PREFIX_RULE, 'unique:resellers,account_prefix'],
        ], ['account_prefix.regex' => 'پیشوند: ۲ تا ۸ کاراکتر، حروف کوچک انگلیسی و عدد، شروع با حرف (مثلاً sam).', 'account_prefix.unique' => 'این پیشوند مال همکار دیگری است.'],
            ['name' => 'نام', 'phone' => 'تلفن', 'username' => 'نام کاربری', 'password' => 'رمز', 'global_discount_percent' => 'تخفیف سراسری', 'account_prefix' => 'پیشوند اکانت‌ها']);

        $reseller = DB::transaction(function () use ($d) {
            $user = User::query()->create(['name' => $d['name'], 'username' => $d['username'], 'password' => $d['password'], 'is_active' => true]);
            $user->forceFill(['password_changed_at' => now()])->save();
            $user->roles()->attach(Role::query()->where('name', config('permissions.reseller_role'))->value('id'));
            $r = Reseller::query()->create([
                'user_id' => $user->id, 'code' => 'tmp-'.Str::random(8), 'name' => $d['name'], 'phone' => $d['phone'] ?? null,
                'telegram' => $d['telegram'] ?? null, 'global_discount_percent' => (float) ($d['global_discount_percent'] ?? 0),
            ]);
            $r->forceFill(['code' => 'R'.(1000 + $r->id), 'account_prefix' => $d['account_prefix']])->save();
            $this->wallets->walletFor('reseller', $r->id);

            return $r;
        });
        $this->audit->log('resellers.create', $reseller, 'ایجاد همکار «'.$reseller->name.'» ('.$reseller->code.') با پیشوند اکانت «'.$reseller->account_prefix.'»', [
            'global_discount_percent' => [null, (float) $reseller->global_discount_percent],
        ]);

        return redirect()->route('resellers.show', $reseller)->with('success', 'همکار «'.$reseller->name.'» با کد '.$reseller->code.' ایجاد شد.');
    }

    public function show(Reseller $reseller, ResellerPricing $pricing, ResellerSales $sales): View
    {
        $this->authorize('resellers.view');
        $sales->syncPending();
        $wallet = $this->wallets->walletFor('reseller', $reseller->id);
        $packages = Package::query()->where('is_active', true)->orderBy('type')->orderBy('sort_order')->get();

        return view('resellers.show', [
            'r' => $reseller->load('user', 'prices.package', 'categoryDiscounts.category'),
            'wallet' => $wallet,
            'recon' => auth()->user()->can('wallets.view') ? $this->wallets->reconcile($wallet) : null,
            'ledger' => auth()->user()->can('wallets.view') ? LedgerEntry::query()->with('actor')->where('wallet_id', $wallet->id)->orderByDesc('id')->paginate(15, ['*'], 'lp') : null,
            'sales' => ResellerSale::query()->with('package', 'account')->where('reseller_id', $reseller->id)->orderByDesc('id')->paginate(15, ['*'], 'sp'),
            'categories' => PackageCategory::query()->orderBy('name')->get(),
            'packages' => $packages,
            'quotes' => $packages->mapWithKeys(fn ($p) => [$p->id => $pricing->quote($reseller, $p)]),
            'report' => $this->report($reseller, $wallet),
            'accounts' => $this->accountRows($reseller),
        ]);
    }

    /** Totals for the reseller's report card. */
    private function report(Reseller $r, $wallet): array
    {
        $ok = ResellerSale::query()->where('reseller_id', $r->id)->where('status', 'completed');
        $since = now()->subDays(30);

        return [
            'sales' => (clone $ok)->count(),
            'sales30' => (clone $ok)->where('created_at', '>=', $since)->count(),
            'cost' => (int) (clone $ok)->sum('cost_rial'),
            'revenue' => (int) (clone $ok)->sum('sale_price_rial'),
            'profit' => (int) (clone $ok)->sum('profit_rial'),
            'profit30' => (int) (clone $ok)->where('created_at', '>=', $since)->sum('profit_rial'),
            'refunded' => ResellerSale::query()->where('reseller_id', $r->id)->where('status', 'refunded')->count(),
            'topups' => (int) Payment::query()->where('wallet_id', $wallet->id)->where('status', 'paid')->sum('amount_rial'),
            'first' => ResellerSale::query()->where('reseller_id', $r->id)->min('created_at'),
            'last' => ResellerSale::query()->where('reseller_id', $r->id)->max('created_at'),
        ];
    }

    /** All the reseller's accounts (OpenVPN + L2TP + WireGuard) as uniform rows. */
    private function accountRows(Reseller $r): Collection
    {
        $rows = collect();
        foreach (VpnAccount::query()->where('reseller_id', $r->id)->whereNull('ibs_deleted_at')->orderBy('username')->get() as $a) {
            $st = $a->status();
            $rows->push(['key' => 'v:'.$a->id, 'proto' => 'openvpn', 'username' => $a->username, 'customer' => $a->displayComment(), 'status' => $st,
                'label' => VpnAccount::STATUS_LABELS[$st] ?? [$st, 'primary'], 'online' => (bool) $a->is_online, 'expires' => $a->expires_at,
                'disabled' => (bool) $a->locked, 'url' => route('accounts.show', $a)]);
        }
        foreach (MtAccount::query()->where('reseller_id', $r->id)->orderBy('username')->get() as $a) {
            $rows->push(['key' => 'm:'.$a->id, 'proto' => $a->protocol, 'username' => $a->username, 'customer' => $a->comment, 'status' => $a->displayStatus(),
                'label' => $a->statusLabel(), 'online' => (bool) $a->is_online, 'expires' => $a->expires_at,
                'disabled' => $a->status === 'disabled', 'url' => route('mt.accounts.show', $a)]);
        }

        return $rows->sortBy('username')->values();
    }

    /** Suspend, re-enable or delete many of a reseller's accounts at once. */
    public function bulkAccounts(Request $request, Reseller $reseller, AccountActions $ibs, MtActions $mt): RedirectResponse
    {
        $this->authorize('resellers.view');
        $d = $request->validate(['action' => ['required', 'in:disable,enable,delete'], 'ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['string', 'regex:/^[vm]:\d+$/']],
            ['ids.required' => 'هیچ اکانتی انتخاب نشده است.']);
        $user = $request->user();
        $reason = 'عملیات گروهی روی اکانت‌های همکار «'.$reseller->name.'»';
        $ok = 0;
        $failed = [];
        foreach ($d['ids'] as $key) {
            [$type, $id] = explode(':', $key);
            try {
                if ($type === 'v') {
                    $a = VpnAccount::query()->where('reseller_id', $reseller->id)->whereNull('ibs_deleted_at')->findOrFail((int) $id);
                    abort_unless($user->can($d['action'] === 'delete' ? 'accounts.delete' : 'accounts.update'), 403);
                    match ($d['action']) {
                        'disable' => $a->locked ? null : $ibs->lock($a, $reason),
                        'enable' => $a->locked ? $ibs->unlock($a, $reason) : null,
                        'delete' => $ibs->delete($a, $reason),
                    };
                } else {
                    $a = MtAccount::query()->where('reseller_id', $reseller->id)->findOrFail((int) $id);
                    abort_unless($user->can($d['action'] === 'delete' ? 'mt_accounts.delete' : 'mt_accounts.update'), 403);
                    match ($d['action']) {
                        'disable' => $a->status === 'disabled' ? null : $mt->disable($a, $reason),
                        'enable' => $a->status === 'disabled' ? $mt->enable($a, $reason) : null,
                        'delete' => $mt->delete($a, $reason),
                    };
                }
                $ok++;
            } catch (HttpException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $failed[] = ($a->username ?? $key).': '.mb_substr($e->getMessage(), 0, 80);
            }
        }
        $verb = ['disable' => 'تعلیق', 'enable' => 'فعال‌سازی', 'delete' => 'حذف'][$d['action']];
        $this->audit->log('resellers.bulk', $reseller, $verb.' گروهی '.count($d['ids']).' اکانت همکار «'.$reseller->name.'» — موفق: '.$ok.($failed ? '، ناموفق: '.count($failed) : ''));
        $msg = $verb.' '.fa($ok).' اکانت انجام شد.';

        return $failed
            ? back()->with('error', $msg.' ناموفق: '.implode(' | ', array_slice($failed, 0, 5)))
            : back()->with('success', $msg);
    }

    public function update(Request $request, Reseller $reseller): RedirectResponse
    {
        $this->authorize('resellers.update');
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s۰-۹]+$/u'],
            'telegram' => ['nullable', 'string', 'max:64'],
            'global_discount_percent' => ['required', 'numeric', 'between:0,100'],
            'status' => ['required', 'in:active,suspended'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['name' => 'نام', 'global_discount_percent' => 'تخفیف سراسری', 'status' => 'وضعیت']);
        if ($reseller->account_prefix === null && $request->filled('account_prefix')) {
            $p = $request->validate(['account_prefix' => ['string', 'regex:'.Reseller::PREFIX_RULE, 'unique:resellers,account_prefix']],
                ['account_prefix.regex' => 'پیشوند: ۲ تا ۸ کاراکتر، حروف کوچک انگلیسی و عدد، شروع با حرف.', 'account_prefix.unique' => 'این پیشوند مال همکار دیگری است.'])['account_prefix'];
            $reseller->forceFill(['account_prefix' => $p])->save();
            $this->audit->log('resellers.prefix', $reseller, 'ثبت پیشوند دائمی «'.$p.'» برای اکانت‌های همکار «'.$reseller->name.'»');
        }
        $before = $reseller->only(array_keys($d));
        $before['global_discount_percent'] = (float) $before['global_discount_percent'];
        $d['global_discount_percent'] = (float) $d['global_discount_percent'];
        $reseller->fill($d)->save();
        // A suspended reseller can still log in and see history, but cannot sell (ResellerSales checks status).
        $reseller->user->forceFill(['name' => $d['name']])->save();
        if ($diff = AuditLogger::diff($before, $d)) {
            $this->audit->log('resellers.update', $reseller, 'ویرایش همکار «'.$reseller->name.'»', $diff);
        }

        return back()->with('success', 'اطلاعات همکار ذخیره شد.');
    }

    public function setCategoryDiscount(Request $request, Reseller $reseller): RedirectResponse
    {
        $this->authorize('resellers.update');
        $d = $request->validate(['category_id' => ['required', 'exists:package_categories,id'], 'percent' => ['required', 'numeric', 'between:0,100']], [], ['percent' => 'درصد']);
        $row = ResellerCategoryDiscount::query()->firstOrNew(['reseller_id' => $reseller->id, 'category_id' => $d['category_id']]);
        $old = $row->exists ? (float) $row->percent : null;
        if ((float) $d['percent'] === 0.0) {
            $row->exists && $row->delete();
        } else {
            $row->percent = $d['percent'];
            $row->save();
        }
        $this->audit->log('resellers.pricing', $reseller, 'تخفیف دسته‌ای همکار «'.$reseller->code.'»', ['category_'.$d['category_id'] => [$old, (float) $d['percent'] ?: null]]);

        return back()->with('success', 'تخفیف دسته‌ای ذخیره شد.');
    }

    public function setFixedPrice(Request $request, Reseller $reseller): RedirectResponse
    {
        $this->authorize('resellers.update');
        $d = $request->validate(['package_id' => ['required', 'exists:packages,id'], 'price_toman' => ['nullable', 'integer', 'between:0,1000000000']], [], ['price_toman' => 'قیمت']);
        $row = ResellerPrice::query()->firstOrNew(['reseller_id' => $reseller->id, 'package_id' => $d['package_id']]);
        $old = $row->exists ? $row->price_rial : null;
        if (($d['price_toman'] ?? null) === null) {
            $row->exists && $row->delete();
            $new = null;
        } else {
            $row->price_rial = (int) $d['price_toman'] * 10;
            $row->save();
            $new = $row->price_rial;
        }
        $this->audit->log('resellers.pricing', $reseller, 'قیمت اختصاصی همکار «'.$reseller->code.'»', ['package_'.$d['package_id'] => [$old, $new]]);

        return back()->with('success', 'قیمت اختصاصی ذخیره شد.');
    }

    /** Sensitive: manual credit / debit with reason (recent password confirmation via route middleware). */
    public function adjust(Request $request, Reseller $reseller): RedirectResponse
    {
        $this->authorize('wallets.sensitive');
        $d = $request->validate([
            'direction' => ['required', 'in:credit,debit'],
            'amount_toman' => ['required', 'integer', 'between:1,10000000000'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
            'op_key' => ['required', 'uuid'],
        ], [], ['amount_toman' => 'مبلغ', 'reason' => 'دلیل']);
        $amount = (int) $d['amount_toman'] * 10 * ($d['direction'] === 'credit' ? 1 : -1);
        try {
            $entry = $this->wallets->post($this->wallets->walletFor('reseller', $reseller->id), $amount,
                $d['direction'] === 'credit' ? 'manual_credit' : 'manual_debit', 'manual:'.$d['op_key'],
                $d['direction'] === 'credit' ? 'شارژ دستی' : 'کسر دستی', $d['reason'], $request->user()->id, $reseller);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        $this->audit->log('wallets.adjust', $reseller, ($amount > 0 ? 'شارژ' : 'کسر').' دستی کیف پول «'.$reseller->code.'» — تراکنش #'.$entry->id,
            ['amount' => [null, $amount], 'balance_after' => [null, $entry->balance_after]], $d['reason']);

        return back()->with('success', 'تراکنش #'.fa((string) $entry->id).' ثبت شد. موجودی جدید: '.fa_money($entry->balance_after));
    }

    public function reverse(Request $request, Reseller $reseller, LedgerEntry $entry): RedirectResponse
    {
        $this->authorize('wallets.sensitive');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']])['reason'];
        abort_unless($entry->wallet->owner_type === 'reseller' && (int) $entry->wallet->owner_id === $reseller->id, 404);
        if (in_array($entry->type, ['purchase', 'refund'], true)) {
            return back()->with('error', 'ردیف‌های خرید و بازگشت وجه از طریق فروش مدیریت می‌شوند و قابل معکوس کردن دستی نیستند.');
        }
        try {
            $rev = $this->wallets->reverse($entry, $reason, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'ردیف #'.fa((string) $entry->id).' با ردیف #'.fa((string) $rev->id).' معکوس شد.');
    }

    public function refundSale(Request $request, Reseller $reseller, ResellerSale $sale, ResellerSales $sales): RedirectResponse
    {
        $this->authorize('wallets.sensitive');
        abort_unless($sale->reseller_id === $reseller->id, 404);
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']])['reason'];
        try {
            $sales->adminRefund($sale, $reason, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'مبلغ فروش #'.fa((string) $sale->id).' به کیف پول همکار برگشت.');
    }
}
