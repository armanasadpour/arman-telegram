<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Models\IbsGroup;
use App\Models\Package;
use App\Models\PackageCategory;
use App\Services\AuditLogger;
use App\Services\Pricing\PackageRules;
use App\Services\Pricing\PricingEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PackagesController extends Controller
{
    private const AUDITED = ['category_id', 'name', 'type', 'volume_gb', 'duration_days', 'multi_login', 'ibs_group_name', 'price_rial', 'description', 'is_active', 'sort_order'];

    public function __construct(private AuditLogger $audit, private PricingEngine $pricing) {}

    public function index(Request $request): View
    {
        $this->authorize('packages.view');
        $type = $request->query('type');
        $packages = Package::query()->with('category', 'group.charge')
            ->when(in_array($type, array_keys(Package::TYPES), true), fn ($q) => $q->where('type', $type))
            ->orderBy('type')->orderBy('sort_order')->orderBy('price_rial')->get();
        $discounts = Discount::query()->where('is_active', true)->get();

        return view('packages.index', [
            'packages' => $packages,
            'quotes' => $packages->mapWithKeys(fn ($p) => [$p->id => $this->pricing->quote($p, null, $discounts)]),
            'categories' => PackageCategory::query()->withCount('packages')->orderBy('sort_order')->orderBy('name')->get(),
            'type' => $type,
        ]);
    }

    public function create(): View
    {
        $this->authorize('packages.create');

        return view('packages.form', $this->formData(new Package(['type' => 'new', 'is_active' => true])));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('packages.create');
        $data = $this->validated($request);
        $p = Package::query()->create($data);
        $this->audit->log('packages.create', $p, 'ایجاد بسته «'.$p->name.'»', AuditLogger::diff([], $p->only(self::AUDITED)));

        return redirect()->route('packages.index')->with('success', 'بسته «'.$p->name.'» ایجاد شد.');
    }

    public function edit(Package $package): View
    {
        $this->authorize('packages.update');

        return view('packages.form', $this->formData($package));
    }

    public function update(Request $request, Package $package): RedirectResponse
    {
        $this->authorize('packages.update');
        $data = $this->validated($request);
        $before = $package->only(self::AUDITED);
        $package->fill($data)->save();
        $diff = AuditLogger::diff($before, $package->only(self::AUDITED));
        if ($diff) {
            $this->audit->log('packages.update', $package, 'ویرایش بسته «'.$package->name.'»', $diff);
        }

        return redirect()->route('packages.index')->with('success', 'بسته ذخیره شد. سفارش‌ها و Snapshotهای قبلی تغییر نمی‌کنند.');
    }

    public function destroy(Request $request, Package $package): RedirectResponse
    {
        $this->authorize('packages.delete');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']])['reason'];
        $package->delete(); // soft delete: history (snapshots, audit) keeps referring to it
        $this->audit->log('packages.delete', $package, 'حذف بسته «'.$package->name.'»', null, $reason);

        return redirect()->route('packages.index')->with('success', 'بسته حذف شد.');
    }

    // ---------------------------------------------------------------- categories (same page)

    public function storeCategory(Request $request): RedirectResponse
    {
        $this->authorize('packages.create');
        $d = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:package_categories,name'],
            'sort_order' => ['nullable', 'integer', 'between:0,9999'],
        ], [], ['name' => 'نام دسته']);
        $c = PackageCategory::query()->create(['name' => $d['name'], 'sort_order' => $d['sort_order'] ?? 0]);
        $this->audit->log('packages.category_create', $c, 'ایجاد دسته «'.$c->name.'»');

        return back()->with('success', 'دسته «'.$c->name.'» ایجاد شد.');
    }

    public function destroyCategory(PackageCategory $category): RedirectResponse
    {
        $this->authorize('packages.delete');
        if ($category->packages()->exists()) {
            return back()->with('error', 'این دسته بسته دارد؛ ابتدا بسته‌ها را به دسته دیگری منتقل کنید.');
        }
        $category->delete();
        $this->audit->log('packages.category_delete', $category, 'حذف دسته «'.$category->name.'»');

        return back()->with('success', 'دسته حذف شد.');
    }

    // ----------------------------------------------------------------

    /** @return array<string,mixed> */
    private function formData(Package $p): array
    {
        return [
            'p' => $p,
            'categories' => PackageCategory::query()->orderBy('sort_order')->orderBy('name')->get(),
            'groups' => IbsGroup::query()->with('charge')->where('present', true)->orderBy('name')->get(),
        ];
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_keys(Package::TYPES))],
            'category_id' => ['nullable', 'integer', 'exists:package_categories,id'],
            'ibs_group_name' => ['nullable', 'required_if:type,new', 'string', 'max:100'],
            'duration_days' => ['nullable', 'integer', 'between:1,3650'],
            'volume_gb' => ['nullable', 'numeric', 'min:0.1', 'max:100000'],
            'multi_login' => ['nullable', 'integer', 'between:1,255'],
            'price_toman' => ['required', 'integer', 'between:0,1000000000'],
            'description' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'between:0,9999'],
            'is_active' => ['nullable', 'boolean'],
        ], [], ['name' => 'نام بسته', 'type' => 'نوع', 'ibs_group_name' => 'گروه IBSng', 'duration_days' => 'مدت', 'volume_gb' => 'حجم',
            'multi_login' => 'تعداد اتصال', 'price_toman' => 'قیمت', 'description' => 'توضیح']);

        if ($d['type'] !== 'new') {
            $d['ibs_group_name'] = null;
            $d['multi_login'] = null;
        }
        if ($errors = PackageRules::validate($d)) {
            throw ValidationException::withMessages($errors);
        }
        $d['price_rial'] = (int) $d['price_toman'] * 10;
        $d['is_active'] = (bool) ($d['is_active'] ?? false);
        $d['sort_order'] = (int) ($d['sort_order'] ?? 0);
        unset($d['price_toman']);

        return $d;
    }
}
