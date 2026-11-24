<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    /** Editable general settings: key => [label, validation rules]. */
    private const FIELDS = [
        'general.site_name' => ['نام سامانه', ['required', 'string', 'max:80']],
        'general.support_contact' => ['اطلاعات تماس پشتیبانی', ['nullable', 'string', 'max:200']],
        'general.display_timezone' => ['منطقه زمانی نمایش', ['required', 'timezone:all']],
        'pricing.display_currency' => ['واحد نمایش قیمت', ['required', 'in:toman,rial']],
        'pricing.rounding_rial' => ['گرد کردن قیمت پس از تخفیف', ['required', 'in:1,10,100,1000,10000']],
    ];

    public function __construct(private Settings $settings, private AuditLogger $audit) {}

    public function edit(): View
    {
        $this->authorize('settings.view');
        $values = [];
        foreach (array_keys(self::FIELDS) as $key) {
            $values[$key] = $this->settings->get($key);
        }

        return view('settings.edit', [
            'values' => $values,
            'fields' => self::FIELDS,
            'timezones' => ['Asia/Tehran', 'UTC', 'Asia/Dubai', 'Europe/Istanbul'],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorize('settings.update');
        $rules = [];
        $attributes = [];
        foreach (self::FIELDS as $key => [$label, $r]) {
            $field = str_replace('.', '__', $key);
            $rules[$field] = $r;
            $attributes[$field] = $label;
        }
        $data = $request->validate($rules, [], $attributes);

        $before = [];
        $after = [];
        foreach (array_keys(self::FIELDS) as $key) {
            $before[$key] = $this->settings->get($key);
            $after[$key] = $data[str_replace('.', '__', $key)] ?? '';
        }
        $diff = AuditLogger::diff($before, $after);
        foreach ($diff as $key => [, $new]) {
            $this->settings->set($key, $new);
        }
        if ($diff) {
            $this->audit->log('settings.update', null, 'تغییر تنظیمات عمومی', $diff);
        }

        return back()->with('success', $diff ? 'تنظیمات ذخیره شد.' : 'تغییری ایجاد نشد.');
    }
}
