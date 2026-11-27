<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Ibs\ErrorMessages;
use App\Ibs\IbsConnector;
use App\Ibs\IbsException;
use App\Ibs\IbsNotConfiguredException;
use App\Ibs\IbsUnavailableException;
use App\Models\IbsCharge;
use App\Models\IbsGroup;
use App\Models\Package;
use App\Models\VpnAccount;
use App\Services\AuditLogger;
use App\Services\Ibs\AccountSync;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class IbsGroupsController extends Controller
{
    public function __construct(private IbsConnector $ibs, private AccountSync $sync, private AuditLogger $audit) {}

    public function index(Settings $settings): View
    {
        $this->authorize('ibs_groups.view');

        return view('ibs.groups', [
            'groups' => IbsGroup::query()->with('charge')->orderByDesc('present')->orderBy('name')->get(),
            'charges' => IbsCharge::query()->orderByDesc('present')->orderBy('name')->get(),
            'activeCharges' => IbsCharge::query()->where('present', true)->orderBy('name')->pluck('name'),
            'accountCounts' => VpnAccount::query()->whereNull('ibs_deleted_at')->selectRaw('group_name, count(*) c')->groupBy('group_name')->pluck('c', 'group_name'),
            'syncedAt' => $settings->get('sync.catalog_at'),
        ]);
    }

    /** Create a group on the server (group.addNewGroup), then set its attributes (group.updateGroupAttrs). */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('ibs_groups.update');
        $d = $this->validated($request, true);
        try {
            $this->ibs->addGroup($d['name'], (string) ($d['comment'] ?? ''));
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', 'گروه ساخته نشد: '.$this->message($e));
        }
        $this->audit->log('ibs_groups.create', null, 'ساخت گروه «'.$d['name'].'» در سرور', $this->diffFor(null, $d));
        try {
            $this->ibs->updateGroupAttrs($d['name'], $this->attrs($d));
        } catch (RuntimeException $e) {
            $this->resync();

            return redirect()->route('ibs.groups.edit', $d['name'])
                ->with('error', 'گروه در سرور ساخته شد ولی تنظیماتش ثبت نشد: '.$this->message($e).' — تنظیمات را دوباره ذخیره کنید.');
        }
        $this->resync();

        return redirect()->route('ibs.groups')->with('success', 'گروه «'.$d['name'].'» در سرور ساخته شد.');
    }

    public function edit(string $name): View
    {
        $this->authorize('ibs_groups.update');
        $group = IbsGroup::query()->where('name', $name)->firstOrFail();

        return view('ibs.group-edit', [
            'g' => $group,
            'activeCharges' => IbsCharge::query()->where('present', true)->orderBy('name')->pluck('name'),
            'accounts' => VpnAccount::query()->whereNull('ibs_deleted_at')->where('group_name', $name)->count(),
        ]);
    }

    public function update(Request $request, string $name): RedirectResponse
    {
        $this->authorize('ibs_groups.update');
        $group = IbsGroup::query()->where('name', $name)->firstOrFail();
        $d = $this->validated($request, false) + ['name' => $name];
        $before = ['days' => $group->rel_exp_seconds !== null ? intdiv($group->rel_exp_seconds, 86400) : null, 'multi_login' => $group->multi_login, 'charge' => $group->charge_name];
        try {
            $this->ibs->updateGroupAttrs($name, $this->attrs($d));
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', 'ذخیره نشد: '.$this->message($e));
        }
        $this->audit->log('ibs_groups.update', null, 'ویرایش گروه «'.$name.'» در سرور', $this->diffFor($before, $d));
        $this->resync();

        return redirect()->route('ibs.groups')->with('success', 'تنظیمات گروه «'.$name.'» در سرور ذخیره شد.');
    }

    /** Delete a group on the server (group.delGroup). Refused while accounts or packages use it. */
    public function destroy(string $name): RedirectResponse
    {
        $this->authorize('ibs_groups.update');
        $group = IbsGroup::query()->where('name', $name)->firstOrFail();
        $accounts = VpnAccount::query()->whereNull('ibs_deleted_at')->where('group_name', $name)->count();
        if ($accounts > 0) {
            return back()->with('error', 'گروه «'.$name.'» '.fa($accounts).' اکانت دارد؛ اول اکانت‌ها را به گروه دیگری ببرید یا حذف کنید.');
        }
        $packages = Package::query()->where('ibs_group_name', $name)->pluck('name');
        if ($packages->isNotEmpty()) {
            return back()->with('error', 'بسته‌های «'.$packages->implode('»، «').'» از این گروه استفاده می‌کنند؛ اول گروه آن بسته‌ها را عوض کنید یا حذفشان کنید.');
        }
        if ($group->present) {
            try {
                $this->ibs->delGroup($name);
            } catch (RuntimeException $e) {
                if (! ($e instanceof IbsException && $e->ibsCode === 'GROUP_NAME_INVALID')) {   // already gone on the server
                    return back()->with('error', 'گروه حذف نشد: '.$this->message($e));
                }
            }
        }
        $group->delete();
        $this->audit->log('ibs_groups.delete', null, 'حذف گروه «'.$name.'» از سرور');

        return redirect()->route('ibs.groups')->with('success', 'گروه «'.$name.'» حذف شد.');
    }

    /** Create a charge on the server with one all-day rule: volume (credit per KB) or unlimited. */
    public function storeCharge(Request $request): RedirectResponse
    {
        $this->authorize('ibs_groups.update');
        $d = $request->validate([
            'charge_name' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_-]+$/'],
            'charge_type' => ['required', 'in:volume,unlimited'],
        ], ['charge_name.regex' => 'نام تعرفه فقط حروف انگلیسی، عدد، «_» و «-».'], ['charge_name' => 'نام تعرفه']);
        $name = $d['charge_name'];
        $cpk = $d['charge_type'] === 'volume' ? 1.0 : 0.0;   // volume: 1 credit per KB; the panel converts GB to credit
        try {
            try {
                $this->ibs->addCharge($name, 'ساخته‌شده از پنل');
            } catch (IbsException $e) {
                // retry after a half-finished attempt: the charge exists but has no rule yet
                if ($e->ibsCode !== 'CHARGE_NAME_EXISTS' || $this->ibs->chargeRules($name) !== []) {
                    throw $e;
                }
            }
            $this->ibs->addAllDayInternetRule($name, $cpk);
        } catch (RuntimeException $e) {
            $this->resync();

            return back()->withInput()->with('error', 'تعرفه ساخته نشد: '.$this->message($e));
        }
        $this->audit->log('ibs_charges.create', null, 'ساخت تعرفه '.($cpk > 0 ? 'حجمی' : 'بدون محدودیت حجم').' «'.$name.'» در سرور');
        $this->resync();

        return redirect()->route('ibs.groups')->with('success', 'تعرفه «'.$name.'» ساخته شد. حالا می‌توانید گروهی با این تعرفه بسازید.');
    }

    public function destroyCharge(string $name): RedirectResponse
    {
        $this->authorize('ibs_groups.update');
        $charge = IbsCharge::query()->where('name', $name)->firstOrFail();
        $groups = IbsGroup::query()->where('charge_name', $name)->where('present', true)->pluck('name');
        if ($groups->isNotEmpty()) {
            return back()->with('error', 'گروه‌های «'.$groups->implode('»، «').'» از این تعرفه استفاده می‌کنند؛ اول تعرفه آن گروه‌ها را عوض کنید.');
        }
        if ($charge->present) {
            try {
                $this->ibs->delCharge($name);
            } catch (RuntimeException $e) {
                return back()->with('error', 'تعرفه حذف نشد: '.$this->message($e));
            }
        }
        $charge->delete();
        $this->audit->log('ibs_charges.delete', null, 'حذف تعرفه «'.$name.'» از سرور');

        return redirect()->route('ibs.groups')->with('success', 'تعرفه «'.$name.'» حذف شد.');
    }

    // ----------------------------------------------------------------

    /** @return array<string,mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $rules = [
            'days' => ['required', 'integer', 'between:1,3650'],
            'multi_login' => ['required', 'integer', 'between:1,255'],
            'charge' => ['required', 'string', 'exists:ibs_charges,name'],
        ];
        if ($creating) {
            $rules['name'] = ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:ibs_groups,name'];
            $rules['comment'] = ['nullable', 'string', 'max:200'];
        }

        return $request->validate($rules, ['name.regex' => 'نام گروه فقط می‌تواند حروف انگلیسی، عدد، «_» و «-» داشته باشد.', 'name.unique' => 'گروهی با این نام وجود دارد.'],
            ['name' => 'نام گروه', 'days' => 'مدت اعتبار', 'multi_login' => 'اتصال همزمان', 'charge' => 'تعرفه']);
    }

    /** @return array<string,mixed> */
    private function attrs(array $d): array
    {
        return [
            'rel_exp_date' => (int) $d['days'], 'rel_exp_date_unit' => 'Days',
            'multi_login' => (int) $d['multi_login'],
            'normal_charge' => (string) $d['charge'],
        ];
    }

    private function diffFor(?array $before, array $d): array
    {
        $after = ['days' => (int) $d['days'], 'multi_login' => (int) $d['multi_login'], 'charge' => $d['charge']];

        return AuditLogger::diff($before ?? array_fill_keys(array_keys($after), null), $after);
    }

    private function resync(): void
    {
        try {
            $this->sync->syncCatalog();
        } catch (RuntimeException) {
            // The hourly sync will pick it up.
        }
    }

    private function message(RuntimeException $e): string
    {
        return ($e instanceof IbsException || $e instanceof IbsUnavailableException || $e instanceof IbsNotConfiguredException)
            ? ErrorMessages::for($e) : $e->getMessage();
    }
}
