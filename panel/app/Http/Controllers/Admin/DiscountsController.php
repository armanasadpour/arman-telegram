<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Discount;
use App\Models\Package;
use App\Models\PackageCategory;
use App\Services\AuditLogger;
use App\Support\Jalali;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DiscountsController extends Controller
{
    private const AUDITED = ['name', 'scope', 'category_id', 'package_id', 'kind', 'value', 'priority', 'starts_at', 'ends_at', 'is_active'];

    public function __construct(private AuditLogger $audit) {}

    public function index(): View
    {
        $this->authorize('discounts.view');

        return view('discounts.index', [
            'discounts' => Discount::query()->with('category', 'package')->orderByDesc('is_active')->orderBy('scope')->orderByDesc('priority')->get(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('discounts.create');

        return view('discounts.form', $this->formData(new Discount(['scope' => 'all', 'kind' => 'percent', 'is_active' => true, 'priority' => 0])));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('discounts.create');
        $d = Discount::query()->create($this->validated($request));
        $this->audit->log('discounts.create', $d, 'ایجاد تخفیف «'.$d->name.'»', AuditLogger::diff([], $this->auditable($d)));

        return redirect()->route('discounts.index')->with('success', 'تخفیف ایجاد شد.');
    }

    public function edit(Discount $discount): View
    {
        $this->authorize('discounts.update');

        return view('discounts.form', $this->formData($discount));
    }

    public function update(Request $request, Discount $discount): RedirectResponse
    {
        $this->authorize('discounts.update');
        $before = $this->auditable($discount);
        $discount->fill($this->validated($request))->save();
        if ($diff = AuditLogger::diff($before, $this->auditable($discount))) {
            $this->audit->log('discounts.update', $discount, 'ویرایش تخفیف «'.$discount->name.'»', $diff);
        }

        return redirect()->route('discounts.index')->with('success', 'تخفیف ذخیره شد.');
    }

    public function destroy(Request $request, Discount $discount): RedirectResponse
    {
        $this->authorize('discounts.delete');
        $this->audit->log('discounts.delete', $discount, 'حذف تخفیف «'.$discount->name.'»', AuditLogger::diff($this->auditable($discount), []));
        $discount->delete();

        return redirect()->route('discounts.index')->with('success', 'تخفیف حذف شد.');
    }

    /** @return array<string,mixed> */
    private function formData(Discount $d): array
    {
        return [
            'd' => $d,
            'categories' => PackageCategory::query()->orderBy('name')->get(),
            'packages' => Package::query()->orderBy('type')->orderBy('name')->get(),
        ];
    }

    /** @return array<string,mixed> */
    private function auditable(Discount $d): array
    {
        $a = $d->only(self::AUDITED);
        $a['starts_at'] = $d->starts_at?->toIso8601String();
        $a['ends_at'] = $d->ends_at?->toIso8601String();

        return $a;
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'scope' => ['required', Rule::in(array_keys(Discount::SCOPES))],
            'category_id' => ['nullable', 'required_if:scope,category', 'integer', 'exists:package_categories,id'],
            'package_id' => ['nullable', 'required_if:scope,package', 'integer', 'exists:packages,id'],
            'kind' => ['required', 'in:percent,amount'],
            'value' => ['required', 'integer', 'min:1'],
            'priority' => ['nullable', 'integer', 'between:-100,100'],
            'starts_at' => ['nullable', 'string', 'regex:/^[0-9۰-۹]{4}\/[0-9۰-۹]{1,2}\/[0-9۰-۹]{1,2}$/u'],
            'ends_at' => ['nullable', 'string', 'regex:/^[0-9۰-۹]{4}\/[0-9۰-۹]{1,2}\/[0-9۰-۹]{1,2}$/u'],
            'is_active' => ['nullable', 'boolean'],
        ], ['starts_at.regex' => 'تاریخ را به شکل ۱۴۰۵/۰۹/۱۰ وارد کنید.', 'ends_at.regex' => 'تاریخ را به شکل ۱۴۰۵/۰۹/۱۰ وارد کنید.'],
            ['name' => 'نام', 'scope' => 'دامنه', 'category_id' => 'دسته', 'package_id' => 'بسته', 'kind' => 'نوع', 'value' => 'مقدار', 'starts_at' => 'از تاریخ', 'ends_at' => 'تا تاریخ']);

        if ($d['kind'] === 'percent' && $d['value'] > 100) {
            throw ValidationException::withMessages(['value' => 'درصد تخفیف حداکثر ۱۰۰ است.']);
        }
        $out = [
            'name' => $d['name'], 'scope' => $d['scope'], 'kind' => $d['kind'],
            // Amount discounts are entered in toman and stored in rial.
            'value' => $d['kind'] === 'amount' ? (int) $d['value'] * 10 : (int) $d['value'],
            'priority' => (int) ($d['priority'] ?? 0),
            'category_id' => $d['scope'] === 'category' ? (int) $d['category_id'] : null,
            'package_id' => $d['scope'] === 'package' ? (int) $d['package_id'] : null,
            // Dates are Jalali days in the display timezone: start of day (inclusive) .. start of next day after "to" (exclusive).
            'starts_at' => ($d['starts_at'] ?? null) ? self::jalaliDayStart($d['starts_at']) : null,
            'ends_at' => ($d['ends_at'] ?? null) ? self::jalaliDayStart($d['ends_at'])->addDay() : null,
            'is_active' => (bool) ($d['is_active'] ?? false),
        ];
        if ($out['starts_at'] && $out['ends_at'] && $out['ends_at']->lte($out['starts_at'])) {
            throw ValidationException::withMessages(['ends_at' => '«تا تاریخ» باید بعد از «از تاریخ» باشد.']);
        }

        return $out;
    }

    public static function jalaliDayStart(string $jalali): CarbonImmutable
    {
        [$y, $m, $day] = array_map('intval', explode('/', Jalali::toLatinDigits($jalali)));
        if ($m < 1 || $m > 12 || $day < 1 || $day > 31) {
            throw ValidationException::withMessages(['starts_at' => 'تاریخ نامعتبر است.']);
        }
        [$gy, $gm, $gd] = Jalali::toGregorian($y, $m, $day);

        return CarbonImmutable::create($gy, $gm, $gd, 0, 0, 0, config('app.display_timezone', 'Asia/Tehran'))->utc();
    }
}
