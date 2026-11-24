<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Ibs\ErrorMessages;
use App\Ibs\IbsConnector;
use App\Ibs\IbsException;
use App\Ibs\IbsNotConfiguredException;
use App\Ibs\IbsUnavailableException;
use App\Models\IbsGroup;
use App\Models\Package;
use App\Models\ProvisioningJob;
use App\Models\VpnAccount;
use App\Services\AuditLogger;
use App\Services\Ibs\AccountActions;
use App\Services\Ibs\AccountSync;
use App\Services\Ibs\Provisioning;
use App\Services\Pricing\PackageApplier;
use App\Services\Pricing\PackageRules;
use App\Services\Pricing\PricingEngine;
use App\Services\Settings;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

class AccountsController extends Controller
{
    public function __construct(private AccountSync $sync, private AccountActions $actions, private Settings $settings) {}

    public function index(Request $request): View
    {
        $this->authorize('accounts.view');
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', 'in:online,active,expired,expiring,locked,not_started,deleted'],
            'group' => ['nullable', 'string', 'max:100'],
        ]);
        $q = VpnAccount::query()->with('group.charge');
        if ($f['q'] ?? null) {
            $q->where(fn ($w) => $w->where('username', 'like', '%'.$f['q'].'%')->orWhere('comment', 'like', '%'.$f['q'].'%'));
        }
        if ($f['group'] ?? null) {
            $q->where('group_name', $f['group']);
        }
        match ($f['status'] ?? null) {
            'online' => $q->where('is_online', true),
            'locked' => $q->where('locked', true),
            'expired' => $q->where('expires_at', '<', now()),
            'expiring' => $q->whereBetween('expires_at', [now(), now()->addDays(3)]),
            'not_started' => $q->whereNull('first_login_ibs')->whereNull('expires_at')->where('locked', false),
            'active' => $q->where('locked', false)->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', now())),
            'deleted' => $q->whereNotNull('ibs_deleted_at'),
            default => null,
        };
        if (($f['status'] ?? null) !== 'deleted') {
            $q->whereNull('ibs_deleted_at');
        }

        return view('accounts.index', [
            'accounts' => $q->orderByDesc('is_online')->orderBy('username')->paginate(25)->withQueryString(),
            'filters' => $f,
            'groups' => IbsGroup::query()->where('present', true)->orderBy('name')->pluck('name'),
            'counts' => [
                'total' => VpnAccount::query()->whereNull('ibs_deleted_at')->count(),
                'online' => VpnAccount::query()->where('is_online', true)->count(),
                'expired' => VpnAccount::query()->whereNull('ibs_deleted_at')->where('expires_at', '<', now())->count(),
                'locked' => VpnAccount::query()->whereNull('ibs_deleted_at')->where('locked', true)->count(),
            ],
            'syncedAt' => ['onlines' => $this->settings->get('sync.onlines_at'), 'accounts' => $this->settings->get('sync.accounts_at')],
            'import' => $this->sync->importState(),
            'openJobs' => ProvisioningJob::query()->whereIn('status', ProvisioningJob::OPEN)->count(),
            'configured' => app(IbsConnector::class)->isConfigured(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('accounts.create');

        $pricing = app(PricingEngine::class);

        return view('accounts.create', [
            'packages' => Package::query()->where('type', 'new')->where('is_active', true)->orderBy('sort_order')->orderBy('price_rial')->get()
                ->map(fn ($p) => ['p' => $p, 'q' => $pricing->quote($p)]),
            'groups' => IbsGroup::query()->with('charge')->where('present', true)->orderBy('name')->get(),
            'jobKey' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, Provisioning $provisioning): RedirectResponse
    {
        $this->authorize('accounts.create');
        $data = $request->validate([
            'job_key' => ['required', 'uuid'],
            'username' => ['nullable', 'string', 'min:3', 'max:32', 'regex:/^[a-z0-9][a-z0-9_.-]*$/'],
            'password' => ['nullable', 'string', 'min:6', 'max:32', 'regex:/^[A-Za-z0-9_.@#!-]+$/'],
            'group' => ['required', 'string', 'max:100'],
            'days' => ['nullable', 'integer', 'between:1,3650'],
            'multi_login' => ['nullable', 'integer', 'between:1,255'],
            'volume_mb' => ['nullable', 'integer', 'between:1,10000000'],
            'comment' => ['nullable', 'string', 'max:150'],
        ], [], ['username' => 'نام کاربری', 'password' => 'رمز', 'group' => 'گروه', 'days' => 'مدت (روز)', 'multi_login' => 'تعداد اتصال', 'volume_mb' => 'حجم', 'comment' => 'توضیح']);

        // Generate here so the password can be shown once to the operator; the panel never stores it.
        $data['password'] = ($data['password'] ?? null) ?: Provisioning::generatePassword();
        // Same idempotency key submitted again (double click / back+resubmit): never create twice,
        // and do not show a password that was not the one actually set.
        $resubmitted = ProvisioningJob::query()->where('job_key', $data['job_key'])->exists();
        try {
            $job = $provisioning->create($data['job_key'], $data, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e instanceof IbsException || $e instanceof IbsUnavailableException || $e instanceof IbsNotConfiguredException ? ErrorMessages::for($e) : $e->getMessage());
        }

        return match ($job->status) {
            'completed' => redirect()->route('accounts.show', $job->vpn_account_id)
                ->with('success', 'اکانت «'.$job->username.'» در سرور ساخته شد.')
                ->with('new_password', $resubmitted ? null : $data['password']),
            'failed', 'compensated' => back()->withInput()->with('error', 'ساخت اکانت ناموفق بود: '.$job->last_error),
            default => redirect()->route('accounts.jobs')->with('error', 'ساخت «'.$job->username.'» کامل نشد و در فهرست رسیدگی قرار گرفت: '.$job->last_error),
        };
    }

    /** Create an account from a "new" package (price snapshot stored with the provisioning job). */
    public function storeFromPackage(Request $request, PackageApplier $applier): RedirectResponse
    {
        $this->authorize('accounts.create');
        $d = $request->validate([
            'job_key' => ['required', 'uuid'],
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'username' => ['nullable', 'string', 'min:3', 'max:32', 'regex:/^[a-z0-9][a-z0-9_.-]*$/'],
            'comment' => ['nullable', 'string', 'max:100'],
        ], [], ['package_id' => 'بسته', 'username' => 'نام کاربری', 'comment' => 'توضیح']);
        $package = Package::query()->findOrFail($d['package_id']);
        $d['password'] = Provisioning::generatePassword();
        $resubmitted = ProvisioningJob::query()->where('job_key', $d['job_key'])->exists();
        try {
            $job = $applier->createAccount($package, $d['job_key'], $d, $request->user()->id);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $this->message($e));
        }

        return match ($job->status) {
            'completed' => redirect()->route('accounts.show', $job->vpn_account_id)
                ->with('success', 'اکانت «'.$job->username.'» با بسته «'.$package->name.'» ساخته شد.')
                ->with('new_password', $resubmitted ? null : $d['password']),
            'failed', 'compensated' => back()->withInput()->with('error', 'ساخت اکانت ناموفق بود: '.$job->last_error),
            default => redirect()->route('accounts.jobs')->with('error', 'ساخت «'.$job->username.'» کامل نشد و در فهرست رسیدگی قرار گرفت: '.$job->last_error),
        };
    }

    public function applyPackage(Request $request, VpnAccount $account, PackageApplier $applier): RedirectResponse
    {
        $this->authorize('accounts.update');
        $d = $request->validate(['package_id' => ['required', 'integer', 'exists:packages,id'], 'reason' => ['required', 'string', 'min:3', 'max:200']],
            [], ['package_id' => 'بسته', 'reason' => 'دلیل']);
        $package = Package::query()->findOrFail($d['package_id']);

        return $this->attempt(fn () => $applier->applyToAccount($package, $account, $d['reason']), 'بسته «'.$package->name.'» اعمال شد.');
    }

    public function show(VpnAccount $account): View
    {
        $this->authorize('accounts.view');

        $account->load('group.charge', 'creator');
        $pricing = app(PricingEngine::class);

        return view('accounts.show', [
            'a' => $account,
            'applicable' => Package::query()->where('is_active', true)->whereIn('type', ['renewal', 'topup'])->orderBy('type')->orderBy('sort_order')->get()
                ->filter(fn ($p) => PackageRules::applicability($p, $account) === null)
                ->map(fn ($p) => ['p' => $p, 'q' => $pricing->quote($p)]),
            'renewalMode' => $this->settings->get('accounts.renewal_mode', 'smart'),
            'job' => ProvisioningJob::query()->where('vpn_account_id', $account->id)->latest()->first(),
        ]);
    }

    public function refresh(VpnAccount $account): RedirectResponse
    {
        $this->authorize('accounts.view');

        return $this->attempt(fn () => $this->sync->refresh($account), 'اطلاعات از سرور به‌روز شد.');
    }

    public function lock(Request $request, VpnAccount $account): RedirectResponse
    {
        $this->authorize('accounts.update');
        $r = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']])['reason'];

        return $this->attempt(fn () => $account->locked ? $this->actions->unlock($account, $r) : $this->actions->lock($account, $r),
            $account->locked ? 'اکانت فعال شد.' : 'اکانت غیرفعال (قفل) شد.');
    }

    public function password(Request $request, VpnAccount $account): RedirectResponse
    {
        $this->authorize('accounts.update');
        $pw = $request->validate(['password' => ['nullable', 'string', 'min:6', 'max:32', 'regex:/^[A-Za-z0-9_.@#!-]+$/']])['password']
            ?: Provisioning::generatePassword();

        return $this->attempt(fn () => $this->actions->changePassword($account, $pw), 'رمز اکانت تغییر کرد.', ['new_password' => $pw]);
    }

    public function renew(Request $request, VpnAccount $account): RedirectResponse
    {
        $this->authorize('accounts.update');
        $d = $request->validate(['days' => ['required', 'integer', 'between:1,3650'], 'reason' => ['required', 'string', 'min:3', 'max:200']]);

        return $this->attempt(fn () => $this->actions->renew($account, (int) $d['days'], $d['reason']), 'اکانت '.fa((int) $d['days']).' روز تمدید شد.');
    }

    public function volume(Request $request, VpnAccount $account): RedirectResponse
    {
        $this->authorize('accounts.update');
        $d = $request->validate(['gb' => ['required', 'numeric', 'min:0.1', 'max:10000'], 'reason' => ['required', 'string', 'min:3', 'max:200']]);
        $mb = (int) round(((float) $d['gb']) * 1024);

        return $this->attempt(fn () => $this->actions->addVolume($account, $mb, $d['reason']), 'حجم اکانت '.fa((string) $d['gb']).' گیگابایت افزایش یافت.');
    }

    public function destroy(Request $request, VpnAccount $account): RedirectResponse
    {
        $this->authorize('accounts.delete');
        $r = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']])['reason'];
        $res = $this->attempt(fn () => $this->actions->delete($account, $r), 'اکانت از سرور حذف شد.');

        return session('error') ? $res : redirect()->route('accounts.index')->with('success', 'اکانت «'.$account->username.'» از سرور حذف شد.');
    }

    public function reveal(VpnAccount $account): RedirectResponse
    {
        $this->authorize('accounts.sensitive');
        try {
            $pw = $this->actions->revealPassword($account);
        } catch (RuntimeException $e) {
            return back()->with('error', $this->message($e));
        }

        return back()->with('revealed_password', $pw);
    }

    public function importOne(Request $request): RedirectResponse
    {
        $this->authorize('accounts.create');
        $u = $request->validate(['username' => ['required', 'string', 'max:64']], [], ['username' => 'نام کاربری'])['username'];
        try {
            $a = $this->sync->importByUsername(trim($u));
        } catch (RuntimeException $e) {
            return back()->with('error', $this->message($e));
        }

        return $a
            ? redirect()->route('accounts.show', $a)->with('success', 'اکانت «'.$a->username.'» از سرور وارد شد.')
            : back()->with('error', 'اکانتی با این نام کاربری در سرور پیدا نشد.');
    }

    public function importAll(): RedirectResponse
    {
        $this->authorize('accounts.create');
        if (($this->sync->importState()['running'] ?? false)) {
            return back()->with('error', 'وارد کردن در حال انجام است.');
        }
        $this->sync->startImport();
        app(AuditLogger::class)->log('accounts.import_all', null, 'شروع وارد کردن همه اکانت‌های سرور');

        return back()->with('success', 'وارد کردن همه اکانت‌ها شروع شد و در پس‌زمینه (با Cron) ادامه پیدا می‌کند.');
    }

    // ----------------------------------------------------------------

    private function attempt(Closure $fn, string $ok, array $flash = []): RedirectResponse
    {
        try {
            $fn();
        } catch (RuntimeException $e) {
            return back()->with('error', $this->message($e));
        }
        $r = back()->with('success', $ok);
        foreach ($flash as $k => $v) {
            $r->with($k, $v);
        }

        return $r;
    }

    private function message(RuntimeException $e): string
    {
        return ($e instanceof IbsException || $e instanceof IbsUnavailableException || $e instanceof IbsNotConfiguredException)
            ? ErrorMessages::for($e) : $e->getMessage();
    }
}
