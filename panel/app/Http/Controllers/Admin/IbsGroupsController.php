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
