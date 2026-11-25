<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MtGroup;
use App\Models\Package;
use App\Models\Router;
use App\RouterOs\RouterConnector;
use App\RouterOs\RouterOsException;
use App\Services\AuditLogger;
use App\Services\Mikrotik\WgNetwork;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/** Groups of MikroTik accounts: router + protocol + PPP profile / WireGuard interface + defaults. */
class MtGroupsController extends Controller
{
    public function __construct(private RouterConnector $ros, private AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $this->authorize('routers.view');
        $proto = $request->route('proto');
        $groups = MtGroup::query()->with('router')->withCount(['accounts' => fn ($q) => $q->whereNull('deleted_at')])
            ->when($proto, fn ($w) => $w->where('protocol', $proto))->orderBy('router_id')->orderBy('protocol')->orderBy('name')->get();
        // packages ("tariffs") of this protocol: new-account packages of its groups, plus MikroTik renewal / top-up packages
        $packages = Package::query()->with('mtGroup')->where('protocol', 'mikrotik')->orderBy('type')->orderBy('sort_order')->orderBy('price_rial')->get()
            ->filter(fn ($p) => ! $proto || $p->type !== 'new' || $p->mtGroup?->protocol === $proto)->values();

        return view('mt.groups.index', ['groups' => $groups, 'routers' => Router::query()->orderBy('name')->get(), 'proto' => $proto, 'packages' => $packages]);
    }

    public function create(Request $request): View|RedirectResponse
    {
        $this->authorize('routers.update');
        $router = Router::query()->find((int) $request->query('router'));
        if (! $router) {
            return redirect()->route('mt.groups.index')->with('error', 'ابتدا روتر را انتخاب کنید.');
        }
        $protocol = $request->query('protocol') === 'wireguard' ? 'wireguard' : 'l2tp';
        $g = new MtGroup(['router_id' => $router->id, 'protocol' => $protocol]);
        $g->setRelation('router', $router);

        return view('mt.groups.form', ['g' => $g] + $this->routerData($router));
    }

    public function edit(MtGroup $group): View
    {
        $this->authorize('routers.update');

        return view('mt.groups.form', ['g' => $group] + $this->routerData($group->router));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('routers.update');
        $router = Router::query()->findOrFail((int) $request->input('router_id'));
        $g = new MtGroup(['router_id' => $router->id, 'protocol' => $request->input('protocol') === 'wireguard' ? 'wireguard' : 'l2tp']);
        try {
            $this->save($request, $g, $router);
        } catch (RuntimeException|RouterOsException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        $this->audit->log('mt_groups.create', $g, 'ساخت گروه میکروتیک «'.$g->name.'» ('.$g->protocolLabel().') روی «'.$router->name.'»');

        return redirect()->route($g->protocol === 'wireguard' ? 'wg.groups' : 'l2tp.groups')->with('success', 'گروه ساخته شد. اکنون می‌توانید برای آن بسته تعریف کنید یا اکانت بسازید.');
    }

    public function update(Request $request, MtGroup $group): RedirectResponse
    {
        $this->authorize('routers.update');
        $before = $group->only(['name', 'ppp_profile', 'wg_interface', 'endpoint_host', 'dns', 'rate_limit', 'multi_login', 'is_active']);
        try {
            $this->save($request, $group, $group->router);
        } catch (RuntimeException|RouterOsException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        $this->audit->log('mt_groups.update', $group, 'ویرایش گروه میکروتیک «'.$group->name.'»', AuditLogger::diff($before, $group->only(array_keys($before))));

        return redirect()->route($group->protocol === 'wireguard' ? 'wg.groups' : 'l2tp.groups')->with('success', 'گروه ذخیره شد. تغییر پروفایل یا سرعت روی اکانت‌های جدید اعمال می‌شود.');
    }

    public function destroy(MtGroup $group): RedirectResponse
    {
        $this->authorize('routers.update');
        if ($group->accounts()->withTrashed()->exists() || Package::query()->where('mt_group_id', $group->id)->exists()) {
            return back()->with('error', 'این گروه اکانت یا بسته دارد؛ به‌جای حذف، آن را غیرفعال کنید.');
        }
        $this->audit->log('mt_groups.delete', $group, 'حذف گروه میکروتیک «'.$group->name.'»');
        $group->delete();

        return back()->with('success', 'گروه حذف شد.');
    }

    private function save(Request $request, MtGroup $g, Router $router): void
    {
        $wg = $g->protocol === 'wireguard';
        $d = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('mt_groups')->where('router_id', $router->id)->where('protocol', $g->protocol)->ignore($g->id)],
            'endpoint_host' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'dns' => ['nullable', 'string', 'max:200', 'regex:/^[0-9a-fA-F.:,\s]+$/'],
            'default_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'default_volume_gb' => ['nullable', 'numeric', 'min:0.1', 'max:100000'],
            'start_on_first_use' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'ppp_profile' => [$wg ? 'nullable' : 'required_without:new_profile', 'nullable', 'string', 'max:100'],
            'new_profile' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9_.\-]+$/'],
            'new_profile_rate' => ['nullable', 'string', 'max:60', 'regex:/^\d+[kKmMgG]?\/\d+[kKmMgG]?$/'],
            'multi_login' => ['nullable', 'integer', 'min:1', $wg ? 'max:10' : 'max:50'],
            'ipsec_psk' => ['nullable', 'string', 'max:200'],
            'wg_interface' => [$wg ? 'required' : 'nullable', 'string', 'max:100'],
            'rate_limit' => ['nullable', 'string', 'max:60', 'regex:/^\d+[kKmMgG]?\/\d+[kKmMgG]?$/'],
            'wg_allowed_ips' => ['nullable', 'string', 'max:200', 'regex:/^[0-9a-fA-F.:\/,\s]+$/'],
            'wg_mtu' => ['nullable', 'integer', 'min:1280', 'max:1500'],
            'wg_keepalive' => ['nullable', 'integer', 'min:0', 'max:300'],
        ], ['new_profile_rate.regex' => 'سرعت را مثل 10M/20M بنویسید (آپلود/دانلود).', 'rate_limit.regex' => 'سرعت را مثل 10M/20M بنویسید (آپلود/دانلود).',
            'ppp_profile.required_without' => 'یک پروفایل PPP انتخاب کنید یا پروفایل جدید بسازید.'],
            ['name' => 'نام', 'ppp_profile' => 'پروفایل PPP', 'wg_interface' => 'اینترفیس WireGuard', 'endpoint_host' => 'آدرس اتصال مشتری']);

        $g->fill([
            'name' => $d['name'], 'endpoint_host' => $d['endpoint_host'] ?? null, 'dns' => isset($d['dns']) ? preg_replace('/\s+/', '', $d['dns']) : null,
            'default_days' => $d['default_days'] ?? null, 'default_volume_gb' => $d['default_volume_gb'] ?? null,
            'start_on_first_use' => (bool) ($d['start_on_first_use'] ?? false), 'is_active' => (bool) ($d['is_active'] ?? false), 'notes' => $d['notes'] ?? null,
        ]);

        if ($wg) {
            $iface = $this->ros->get($router, 'interface/wireguard', ['name' => $d['wg_interface']])[0] ?? null;
            if (! $iface) {
                throw new RuntimeException('اینترفیس WireGuard «'.$d['wg_interface'].'» روی روتر پیدا نشد.');
            }
            $addr = null;
            foreach ($this->ros->get($router, 'ip/address', ['interface' => $d['wg_interface']]) as $row) {
                if (str_contains((string) ($row['address'] ?? ''), '.')) {
                    $addr = $row['address'];
                    break;
                }
            }
            if (! $addr) {
                throw new RuntimeException('اینترفیس «'.$d['wg_interface'].'» آدرس IPv4 ندارد. در Winbox → IP → Addresses یک آدرس مثل 10.66.0.1/24 روی آن بگذارید.');
            }
            $net = WgNetwork::parse($addr);
            $g->fill(['wg_interface' => $d['wg_interface'], 'wg_server_public_key' => $iface['public-key'] ?? null, 'wg_listen_port' => (int) ($iface['listen-port'] ?? 0),
                'wg_network' => $net->cidr(), 'wg_server_ip' => explode('/', $addr)[0], 'rate_limit' => $d['rate_limit'] ?? null,
                'wg_allowed_ips' => isset($d['wg_allowed_ips']) && trim($d['wg_allowed_ips']) !== '' ? trim($d['wg_allowed_ips']) : '0.0.0.0/0, ::/0',
                'wg_mtu' => $d['wg_mtu'] ?? null, 'wg_keepalive' => $d['wg_keepalive'] ?? 25, 'multi_login' => min(10, (int) ($d['multi_login'] ?? 1)) ?: 1]);
        } else {
            $profiles = collect($this->ros->get($router, 'ppp/profile'))->keyBy('name');
            $profile = $d['ppp_profile'] ?? null;
            if (! empty($d['new_profile'])) {
                $base = $profiles[$profile] ?? $profiles->first(fn ($p) => ($p['remote-address'] ?? '') !== '');
                if (! $base || ($base['remote-address'] ?? '') === '') {
                    throw new RuntimeException('برای ساخت پروفایل جدید، یک پروفایل پایه دارای Pool (remote-address) انتخاب کنید.');
                }
                if (isset($profiles[$d['new_profile']])) {
                    throw new RuntimeException('پروفایلی با نام «'.$d['new_profile'].'» روی روتر هست؛ آن را از فهرست انتخاب کنید.');
                }
                $attrs = ['name' => $d['new_profile'], 'local-address' => $base['local-address'] ?? '', 'remote-address' => $base['remote-address'], 'comment' => 'panel'];
                if (! empty($d['new_profile_rate'])) {
                    $attrs['rate-limit'] = $d['new_profile_rate'];
                }
                if ($g->dns ?? null) {
                    $attrs['dns-server'] = $g->dns;
                }
                $this->ros->add($router, 'ppp/profile', array_filter($attrs, fn ($v) => $v !== ''));
                $profile = $d['new_profile'];
            } elseif (! isset($profiles[$profile])) {
                throw new RuntimeException('پروفایل «'.$profile.'» روی روتر پیدا نشد.');
            }
            $psk = $d['ipsec_psk'] ?? null;
            if (($psk === null || $psk === '') && ! $g->ipsec_psk) {
                try {
                    $psk = $this->ros->first($router, 'interface/l2tp-server/server')['ipsec-secret'] ?? null;
                } catch (RouterOsException) {
                }
            }
            $g->fill(['ppp_profile' => $profile, 'multi_login' => $d['multi_login'] ?? null]);
            if ($psk !== null && $psk !== '') {
                $g->ipsec_psk = $psk;
            }
        }
        $g->save();
    }

    /** Live lists from the router for the form (falls back to the last readiness check). */
    private function routerData(Router $r): array
    {
        $error = null;
        try {
            $profiles = $this->ros->get($r, 'ppp/profile');
            $wgs = $this->ros->get($r, 'interface/wireguard');
            $addrs = $this->ros->get($r, 'ip/address');
        } catch (RouterOsException $e) {
            $error = $e->getMessage();
            $profiles = $r->inventory['data']['l2tp']['profiles'] ?? [];
            $wgs = $r->inventory['data']['wireguard']['interfaces'] ?? [];
            $addrs = $r->inventory['data']['wireguard']['addresses'] ?? [];
        }

        return [
            'routerError' => $error,
            'profiles' => array_values(array_map(fn ($p) => array_intersect_key($p, array_flip(['name', 'local-address', 'remote-address', 'rate-limit'])), $profiles)),
            'wgInterfaces' => array_values(array_map(fn ($w) => ['name' => $w['name'] ?? '', 'port' => $w['listen-port'] ?? '',
                'address' => collect($addrs)->firstWhere('interface', $w['name'] ?? '')['address'] ?? null], $wgs)),
        ];
    }
}
