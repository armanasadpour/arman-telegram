<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MtAccount;
use App\Models\MtGroup;
use App\Models\Package;
use App\Models\Router;
use App\Models\User;
use App\RouterOs\RouterOsException;
use App\Services\Mikrotik\MtAccounting;
use App\Services\Mikrotik\MtActions;
use App\Services\Mikrotik\MtImporter;
use App\Services\Mikrotik\MtProvisioning;
use App\Services\Pricing\PricingEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

/** L2TP / WireGuard accounts on MikroTik routers. */
class MtAccountsController extends Controller
{
    public function __construct(private MtProvisioning $prov, private MtActions $actions, private PricingEngine $pricing) {}

    public function index(Request $request): View
    {
        $this->authorize('mt_accounts.view');
        $f = $request->only(['q', 'status', 'protocol', 'router', 'group', 'creator']);
        $proto = $request->route('proto');   // /l2tp/accounts, /wireguard/accounts
        if ($proto) {
            $f['protocol'] = $proto;
        }
        $q = MtAccount::query()->with(['group', 'router', 'reseller', 'creator']);
        if (ctype_digit((string) ($f['creator'] ?? ''))) {
            $q->where('created_by', (int) $f['creator']);
        }
        if (! empty($f['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($f['q'])).'%';
            $q->where(fn ($w) => $w->where('username', 'like', $term)->orWhere('comment', 'like', $term)->orWhere('wg_address', 'like', $term));
        }
        match ($f['status'] ?? '') {
            'online' => $q->where('is_online', true),
            'not_started' => $q->where('status', 'active')->whereNull('expires_at')->whereNotNull('duration_days'),
            'expiring' => $q->where('status', 'active')->whereBetween('expires_at', [now(), now()->addDays(3)]),
            'deleted' => $q->onlyTrashed(),
            '' => null,
            default => $q->where('status', $f['status']),
        };
        foreach (['protocol' => 'protocol', 'router' => 'router_id', 'group' => 'mt_group_id'] as $k => $col) {
            if (! empty($f[$k])) {
                $q->where($col, $f[$k]);
            }
        }
        $counts = MtAccount::query()->when($proto, fn ($w) => $w->where('protocol', $proto))->selectRaw("count(*) as total, sum(case when is_online = 1 then 1 else 0 end) as online,
            sum(case when status = 'expired' then 1 else 0 end) as expired, sum(case when status = 'depleted' then 1 else 0 end) as depleted,
            sum(case when status in ('pending','failed') then 1 else 0 end) as problems")->first();

        return view('mt.accounts.index', [
            'accounts' => $q->orderByDesc('id')->paginate(30)->withQueryString(),
            'filters' => $proto ? array_diff_key($f, ['protocol' => 1]) : $f, 'counts' => $counts, 'proto' => $proto,
            'routers' => Router::query()->orderBy('name')->get(['id', 'name']),
            'groups' => MtGroup::query()->with('router:id,name')->when($proto, fn ($w) => $w->where('protocol', $proto))->orderBy('name')->get(),
            'creators' => User::query()->whereIn('id', MtAccount::withTrashed()->whereNotNull('created_by')->distinct()->select('created_by'))->orderBy('name')->get(['id', 'name', 'username']),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('mt_accounts.create');
        $proto = in_array($request->query('protocol'), ['l2tp', 'wireguard'], true) ? $request->query('protocol') : null;
        $groups = MtGroup::query()->with('router')->where('is_active', true)->when($proto, fn ($w) => $w->where('protocol', $proto))->orderBy('protocol')->orderBy('name')->get()
            ->filter(fn (MtGroup $g) => $g->router?->is_active && ($g->isWireguard() ? $g->router->wireguard_enabled : $g->router->l2tp_enabled))->values();
        $packages = Package::query()->with('mtGroup.router')->where('is_active', true)->where('type', 'new')->where('protocol', 'mikrotik')
            ->orderBy('sort_order')->orderBy('price_rial')->get()->filter(fn ($p) => $p->mtGroup && $groups->contains('id', $p->mt_group_id))->values();

        return view('mt.accounts.create', ['groups' => $groups, 'proto' => $proto, 'jobKey' => (string) Str::uuid(),
            'packages' => $packages->map(fn ($p) => ['p' => $p, 'q' => $this->pricing->quote($p)])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('mt_accounts.create');
        $d = $request->validate([
            'job_key' => ['required', 'uuid'],
            'mt_group_id' => ['required', 'integer', 'exists:mt_groups,id'],
            'username' => ['nullable', 'string', 'max:32'],
            'password' => ['nullable', 'string', 'max:64'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'volume_gb' => ['nullable', 'numeric', 'min:0.1', 'max:100000'],
            'multi_login' => ['nullable', 'integer', 'min:1', 'max:50'],
            'comment' => ['required', 'string', 'max:100'],
        ], [], ['mt_group_id' => 'گروه', 'days' => 'مدت', 'volume_gb' => 'حجم', 'comment' => 'نام کاربر']);
        $group = MtGroup::query()->findOrFail($d['mt_group_id']);

        return $this->created(fn () => $this->prov->create($group, $d['job_key'], $d, $request->user()->id));
    }

    public function storeFromPackage(Request $request): RedirectResponse
    {
        $this->authorize('mt_accounts.create');
        $d = $request->validate([
            'job_key' => ['required', 'uuid'],
            'package_id' => ['required', 'integer'],
            'username' => ['nullable', 'string', 'max:32'],
            'password' => ['nullable', 'string', 'max:64'],
            'comment' => ['required', 'string', 'max:100'],
        ], [], ['comment' => 'نام کاربر']);
        $p = Package::query()->where('protocol', 'mikrotik')->where('type', 'new')->where('is_active', true)->findOrFail($d['package_id']);
        if (! $p->mtGroup) {
            return back()->withInput()->with('error', 'گروه این بسته وجود ندارد.');
        }

        return $this->created(fn () => $this->prov->create($p->mtGroup, $d['job_key'], [
            'username' => $d['username'] ?? null, 'password' => $d['password'] ?? null, 'days' => $p->duration_days, 'volume_gb' => $p->volume_gb,
            'multi_login' => $p->multi_login, 'comment' => trim((string) $d['comment']), 'package_id' => $p->id,
        ], $request->user()->id));
    }

    private function created(\Closure $make): RedirectResponse
    {
        try {
            $a = $make();
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        $r = redirect()->route('mt.accounts.show', $a);

        return match ($a->status) {
            'failed' => $r->with('error', 'روتر ساخت اکانت را نپذیرفت: '.$a->error),
            'pending' => $r->with('warning', 'روتر در دسترس نبود؛ اکانت ثبت شد و به‌محض برقراری اتصال (حداکثر چند دقیقه) روی روتر ساخته می‌شود.')->with('mt_reveal', $a->id),
            default => $r->with('success', 'اکانت ساخته شد. اطلاعات اتصال را برای مشتری بفرستید.')->with('mt_reveal', $a->id),
        };
    }

    public function show(MtAccount $account): View
    {
        $this->authorize('mt_accounts.view');

        return $this->showView($account, session('mt_reveal') === $account->id);
    }

    /** Connection details (password / private key / QR): sensitive, needs a recent password confirmation. */
    public function connect(MtAccount $account): View
    {
        $this->authorize('mt_accounts.sensitive');

        return $this->showView($account, true);
    }

    private function showView(MtAccount $a, bool $reveal): View
    {
        $a->load(['group', 'router', 'reseller', 'package', 'creator']);
        $usage = DB::table('mt_usage_daily')->where('mt_account_id', $a->id)->where('day', '>=', now()->setTimezone('Asia/Tehran')->subDays(29)->toDateString())->orderBy('day')->get();
        $renewals = Package::query()->where('protocol', 'mikrotik')->where('is_active', true)->whereIn('type', ['renewal', 'topup'])->orderBy('sort_order')->get()
            ->filter(fn ($p) => MtActions::applicability($p, $a) === null)->values();

        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $days[now()->setTimezone('Asia/Tehran')->subDays($i)->toDateString()] = 0;
        }
        foreach ($usage as $u) {
            $days[substr((string) $u->day, 0, 10)] = (int) round(($u->upload_bytes + $u->download_bytes) / 1048576);
        }

        return view('mt.accounts.show', ['a' => $a, 'reveal' => $reveal && ! $a->trashed() && $a->status !== 'failed', 'usage' => $usage,
            'usageDays' => $days, 'renewals' => $renewals]);
    }

    // ---------------------------------------------------------------- actions

    public function renew(Request $request, MtAccount $account): RedirectResponse
    {
        $this->authorize('mt_accounts.update');
        $d = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:3650'], 'reason' => ['nullable', 'string', 'max:200']]);

        return $this->run(fn () => $this->actions->renew($account, (int) $d['days'], $d['reason'] ?? 'تمدید از پنل'), 'تمدید شد.');
    }

    public function volume(Request $request, MtAccount $account): RedirectResponse
    {
        $this->authorize('mt_accounts.update');
        $d = $request->validate(['gb' => ['required', 'numeric', 'min:0.1', 'max:100000'], 'reason' => ['nullable', 'string', 'max:200']]);

        return $this->run(fn () => $this->actions->addVolume($account, (float) $d['gb'], $d['reason'] ?? 'افزایش حجم از پنل'), 'حجم اضافه شد.');
    }

    public function limits(Request $request, MtAccount $account): RedirectResponse
    {
        $this->authorize('mt_accounts.update');
        $d = $request->validate(['days_left' => ['nullable', 'integer', 'min:0', 'max:3650'], 'volume_gb' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'multi_login' => ['nullable', 'integer', 'min:1', 'max:50'], 'reason' => ['required', 'string', 'min:3', 'max:200']], [], ['reason' => 'دلیل']);

        return $this->run(fn () => $this->actions->setLimits($account, isset($d['days_left']) ? (int) $d['days_left'] : null,
            isset($d['volume_gb']) ? (float) $d['volume_gb'] : null, $d['multi_login'] ?? null, $d['reason']), 'محدودیت‌ها ذخیره شد.');
    }

    public function applyPackage(Request $request, MtAccount $account): RedirectResponse
    {
        $this->authorize('mt_accounts.update');
        $p = Package::query()->findOrFail((int) $request->validate(['package_id' => ['required', 'integer']])['package_id']);

        return $this->run(fn () => $this->actions->applyPackage($p, $account, 'از پنل توسط '.$request->user()->username), 'بسته «'.$p->name.'» اعمال شد.');
    }

    public function resetUsage(Request $request, MtAccount $account): RedirectResponse
    {
        $this->authorize('mt_accounts.update');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']], [], ['reason' => 'دلیل'])['reason'];

        return $this->run(fn () => $this->actions->resetUsage($account, $reason), 'مصرف صفر شد.');
    }

    public function resetFirstUse(Request $request, MtAccount $account): RedirectResponse
    {
        $this->authorize('mt_accounts.update');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']], [], ['reason' => 'دلیل'])['reason'];

        return $this->run(fn () => $this->actions->resetToFirstUse($account, $reason),
            'بازنشانی شد: مصرف صفر شد'.($account->duration_days ? ' و '.fa($account->duration_days).' روز از اولین اتصال بعدی شروع می‌شود' : '').'.');
    }

    public function updateInfo(Request $request, MtAccount $account): RedirectResponse
    {
        $this->authorize('mt_accounts.update');
        $d = $request->validate(['comment' => ['required', 'string', 'max:100'], 'mt_group_id' => ['nullable', 'integer', 'exists:mt_groups,id']], [], ['comment' => 'نام کاربر', 'mt_group_id' => 'گروه']);

        return $this->run(function () use ($account, $d) {
            $this->actions->setCustomerName($account, $d['comment']);
            if (! empty($d['mt_group_id']) && (int) $d['mt_group_id'] !== $account->mt_group_id) {
                $this->actions->setGroup($account->fresh(), MtGroup::query()->findOrFail($d['mt_group_id']));
            }
        }, 'اطلاعات حساب ذخیره شد.');
    }

    public function kick(MtAccount $account): RedirectResponse
    {
        $this->authorize('mt_accounts.update');

        return $this->run(fn () => $this->actions->kickOne($account), 'نشست‌های آنلاین قطع شد.');
    }

    public function toggle(Request $request, MtAccount $account): RedirectResponse
    {
        $this->authorize('mt_accounts.update');
        $reason = (string) $request->input('reason', 'از پنل');

        return $account->status === 'disabled'
            ? $this->run(fn () => $this->actions->enable($account, $reason), 'اکانت فعال شد.')
            : $this->run(fn () => $this->actions->disable($account, $reason), 'اکانت غیرفعال شد و اتصال‌های فعلی قطع شد.');
    }

    public function password(Request $request, MtAccount $account): RedirectResponse
    {
        $this->authorize('mt_accounts.sensitive');
        $pw = $request->validate(['password' => ['nullable', 'string', 'max:64']])['password'] ?? null;

        try {
            $new = $this->actions->changePassword($account, $pw, 'از پنل');
        } catch (RuntimeException|RouterOsException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'رمز عوض شد.')->with('changed_password', $new);
    }

    public function keys(Request $request, MtAccount $account): RedirectResponse
    {
        $this->authorize('mt_accounts.sensitive');
        $device = (int) $request->validate(['device' => ['nullable', 'integer', 'min:1', 'max:10']])['device'] ?: 1;

        return $this->run(fn () => $this->actions->regenerateKeys($account, 'از پنل', $device), 'کلید جدید ساخته شد؛ فایل/QR جدید را برای مشتری بفرستید (فایل قبلی دیگر کار نمی‌کند).', reveal: $account->id);
    }

    public function privateKey(Request $request, MtAccount $account): RedirectResponse
    {
        $this->authorize('mt_accounts.sensitive');
        $k = $request->validate(['private_key' => ['required', 'string', 'max:60']])['private_key'];

        return $this->run(fn () => $this->actions->setPrivateKey($account, $k), 'کلید ثبت شد؛ فایل و QR حالا قابل نمایش است.', reveal: $account->id);
    }

    public function importForm(MtGroup $group, MtImporter $imp): View|RedirectResponse
    {
        $this->authorize('mt_accounts.create');
        try {
            $rows = $imp->candidates($group);
        } catch (RouterOsException $e) {
            return redirect()->route($group->isWireguard() ? 'wg.groups' : 'l2tp.groups')->with('error', $e->getMessage());
        }

        return view('mt.accounts.import', ['g' => $group->load('router'), 'rows' => $rows]);
    }

    public function import(Request $request, MtGroup $group, MtImporter $imp): RedirectResponse
    {
        $this->authorize('mt_accounts.create');
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:2000'], 'ids.*' => ['string', 'regex:/^\*[0-9A-Fa-f]{1,16}$/'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'], 'volume_gb' => ['nullable', 'numeric', 'min:0.1', 'max:100000']],
            ['ids.required' => 'دست‌کم یک مورد را انتخاب کنید.']);
        try {
            $r = $imp->import($group, $d['ids'], isset($d['days']) ? (int) $d['days'] : null, isset($d['volume_gb']) ? (float) $d['volume_gb'] : null, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route($group->isWireguard() ? 'wg.accounts' : 'l2tp.accounts', ['group' => $group->id])->with('success', fa($r['imported']).' اکانت وارد پنل شد. مصرف از همین لحظه شمرده می‌شود.');
    }

    public function retry(MtAccount $account): RedirectResponse
    {
        $this->authorize('mt_accounts.create');
        try {
            $a = $this->actions->retryCreate($account);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($a->status === 'active' ? 'success' : 'error', $a->status === 'active' ? 'اکانت روی روتر ساخته شد.' : 'ساخت انجام نشد: '.$a->error);
    }

    /** Poll the router now (usage + state of every account on it). */
    public function sync(MtAccount $account, MtAccounting $acct): RedirectResponse
    {
        $this->authorize('mt_accounts.view');
        try {
            $acct->syncRouter($account->router);
        } catch (RouterOsException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'اطلاعات از روتر به‌روز شد.');
    }

    public function destroy(Request $request, MtAccount $account): RedirectResponse
    {
        $this->authorize('mt_accounts.delete');
        $d = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200'], 'panel_only' => ['nullable', 'boolean']], [], ['reason' => 'دلیل']);
        try {
            $this->actions->delete($account, $d['reason'], (bool) ($d['panel_only'] ?? false) || ($account->status === 'failed' && ! $account->ros_id));
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage().' اگر روتر برای همیشه از دسترس خارج شده، گزینه «فقط از پنل» را بزنید.');
        }

        return redirect()->to($account->listUrl())->with('success', 'اکانت حذف شد.');
    }

    private function run(\Closure $fn, string $ok, ?int $reveal = null): RedirectResponse
    {
        try {
            $fn();
        } catch (RuntimeException|RouterOsException $e) {
            return back()->with('error', $e->getMessage());
        }
        $r = back()->with('success', $ok);

        return $reveal ? $r->with('mt_reveal', $reveal) : $r;
    }
}
