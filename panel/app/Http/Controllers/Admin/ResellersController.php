<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\Package;
use App\Models\PackageCategory;
use App\Models\Reseller;
use App\Models\ResellerCategoryDiscount;
use App\Models\ResellerPrice;
use App\Models\ResellerSale;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\ResellerPricing;
use App\Services\Finance\ResellerSales;
use App\Services\Finance\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

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
        ], [], ['name' => 'نام', 'phone' => 'تلفن', 'username' => 'نام کاربری', 'password' => 'رمز', 'global_discount_percent' => 'تخفیف سراسری']);

        $reseller = DB::transaction(function () use ($d) {
            $user = User::query()->create(['name' => $d['name'], 'username' => $d['username'], 'password' => $d['password'], 'is_active' => true]);
            $user->forceFill(['password_changed_at' => now()])->save();
            $user->roles()->attach(Role::query()->where('name', config('permissions.reseller_role'))->value('id'));
            $r = Reseller::query()->create([
                'user_id' => $user->id, 'code' => 'tmp-'.Str::random(8), 'name' => $d['name'], 'phone' => $d['phone'] ?? null,
                'telegram' => $d['telegram'] ?? null, 'global_discount_percent' => (float) ($d['global_discount_percent'] ?? 0),
            ]);
            $r->forceFill(['code' => 'R'.(1000 + $r->id)])->save();
            $this->wallets->walletFor('reseller', $r->id);

            return $r;
        });
        $this->audit->log('resellers.create', $reseller, 'ایجاد همکار «'.$reseller->name.'» ('.$reseller->code.')', [
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
        ]);
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
