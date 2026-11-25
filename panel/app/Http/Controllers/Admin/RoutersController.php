<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\RouterOs\Pin;
use App\RouterOs\RouterConnector;
use App\RouterOs\RouterOsException;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RoutersController extends Controller
{
    public function __construct(private RouterConnector $ros, private AuditLogger $audit) {}

    public function index(): View
    {
        $this->authorize('routers.view');

        return view('routers.index', ['routers' => Router::query()->orderBy('name')->get()]);
    }

    public function create(): View
    {
        $this->authorize('routers.sensitive');

        return view('routers.form', ['r' => new Router, 'panelIp' => $this->panelIp()]);
    }

    public function edit(Router $router): View
    {
        $this->authorize('routers.sensitive');

        return view('routers.form', ['r' => $router, 'panelIp' => $this->panelIp()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('routers.sensitive');
        $d = $this->validated($request, null);
        $r = new Router;
        $this->fill($r, $d);
        $r->save();
        $this->audit->log('routers.create', $r, 'افزودن روتر «'.$r->name.'» ('.$r->host.')', ['host' => [null, $r->host], 'transport' => [null, $r->transport], 'password' => [null, '***']]);

        return redirect()->route('routers.show', $r)->with('success', 'روتر ذخیره شد.'.($r->needsPin() && ! $r->cert_pin ? ' اکنون «دریافت اثر انگشت گواهی» را بزنید.' : ' اکنون «تست اتصال» را بزنید.'));
    }

    public function update(Request $request, Router $router): RedirectResponse
    {
        $this->authorize('routers.sensitive');
        $d = $this->validated($request, $router);
        $before = $router->only(['name', 'host', 'transport', 'port', 'username', 'is_active']);
        $hostChanged = $router->host !== $d['host'] || (int) $router->port !== (int) $d['port'] || $router->transport !== $d['transport'];
        $this->fill($router, $d);
        if ($hostChanged) {
            $router->cert_pin = null;   // a new endpoint must be trusted again
        }
        $router->save();
        $diff = AuditLogger::diff($before, $router->only(array_keys($before)));
        if (($d['password'] ?? '') !== '') {
            $diff['password'] = ['***', '***'];
        }
        $this->audit->log('routers.update', $router, 'ویرایش روتر «'.$router->name.'»', $diff);

        return redirect()->route('routers.show', $router)->with('success', 'تنظیمات روتر ذخیره شد.'.($hostChanged && $router->needsPin() ? ' چون آدرس یا روش اتصال عوض شد، اثر انگشت گواهی را دوباره دریافت کنید.' : ''));
    }

    public function show(Router $router): View
    {
        $this->authorize('routers.view');

        return view('routers.show', ['r' => $router, 'pinCandidate' => session('pin_candidate'), 'panelIp' => $this->panelIp()]);
    }

    /** Connect over TLS without credentials and show the certificate so the admin can compare it on the router. */
    public function fetchPin(Router $router): RedirectResponse
    {
        $this->authorize('routers.sensitive');
        try {
            $c = Pin::fetch($router->host, $router->port);
        } catch (RouterOsException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('pin_candidate', $c);
    }

    public function trustPin(Request $request, Router $router): RedirectResponse
    {
        $this->authorize('routers.sensitive');
        $pin = $request->validate(['pin' => ['required', 'regex:#^sha256//[A-Za-z0-9+/=]{43,44}$#']])['pin'];
        $old = $router->cert_pin;
        $router->forceFill(['cert_pin' => $pin])->save();
        $this->audit->log('routers.pin', $router, 'ثبت اثر انگشت گواهی روتر «'.$router->name.'»', ['cert_pin' => [$old, $pin]]);

        return back()->with('success', 'اثر انگشت گواهی ثبت شد. اکنون «تست اتصال» را بزنید.');
    }

    public function test(Router $router): RedirectResponse
    {
        $this->authorize('routers.update');
        try {
            $this->ros->about($router);
            $inv = $this->ros->inventory($router);
        } catch (RouterOsException $e) {
            return back()->with('error', $e->getMessage());
        }
        $router->forceFill(['l2tp_enabled' => $router->l2tp_enabled && $inv['l2tp_ready'], 'wireguard_enabled' => $router->wireguard_enabled && $inv['wg_ready']])->save();

        return back()->with('success', 'اتصال برقرار است: '.($router->info['identity'] ?? '').' — RouterOS '.($router->info['version'] ?? '?').'. نتیجه بررسی آمادگی پایین صفحه است.');
    }

    public function toggleProtocol(Request $request, Router $router): RedirectResponse
    {
        $this->authorize('routers.update');
        $d = $request->validate(['protocol' => ['required', 'in:l2tp,wireguard'], 'enabled' => ['required', 'boolean']]);
        $field = $d['protocol'] === 'l2tp' ? 'l2tp_enabled' : 'wireguard_enabled';
        $ready = $router->inventory[$d['protocol'] === 'l2tp' ? 'l2tp_ready' : 'wg_ready'] ?? false;
        if ($d['enabled'] && ! $ready) {
            return back()->with('error', 'ابتدا «تست اتصال» را بزنید و موارد قرمز بررسی آمادگی را برطرف کنید.');
        }
        $router->forceFill([$field => (bool) $d['enabled']])->save();
        $this->audit->log('routers.protocol', $router, ($d['enabled'] ? 'فعال‌سازی ' : 'غیرفعال‌سازی ').strtoupper($d['protocol']).' روی «'.$router->name.'»');

        return back()->with('success', 'ذخیره شد.');
    }

    public function destroy(Request $request, Router $router): RedirectResponse
    {
        $this->authorize('routers.sensitive');
        $this->audit->log('routers.delete', $router, 'حذف روتر «'.$router->name.'» از پنل (روی خود روتر چیزی تغییر نکرد)');
        $router->delete();

        return redirect()->route('routers.index')->with('success', 'روتر از پنل حذف شد.');
    }

    // ----------------------------------------------------------------

    private function validated(Request $request, ?Router $r): array
    {
        // Forgive the common paste "https://host:port/..." or "host:port": keep the bare host and move the port to its field.
        $host = trim((string) $request->input('host'));
        $host = (string) preg_replace('#^[a-z]+://#i', '', $host);
        $host = (string) preg_replace('#[/?].*$#', '', $host);
        if (preg_match('/^([^:\[\]]+):(\d{1,5})$/', $host, $m)) {
            $host = $m[1];
            $request->merge(['port' => (int) $m[2]]);
        }
        $request->merge(['host' => $host]);

        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'host' => ['required', 'string', 'max:255', 'regex:/^([A-Za-z0-9.\-]+|\[[0-9A-Fa-f:.]+\])$/'],
            'transport' => ['required', Rule::in(array_keys(Router::TRANSPORTS))],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:100'],
            'password' => [$r ? 'nullable' : 'required', 'string', 'max:200'],
            'is_active' => ['nullable', 'boolean'],
        ], ['host.regex' => 'آدرس فقط IP یا نام دامنه باشد (بدون http://، مسیر یا :پورت — پورت را در فیلد پورت بنویسید).'], ['name' => 'نام', 'host' => 'آدرس', 'port' => 'پورت', 'username' => 'نام کاربری', 'password' => 'رمز']);
    }

    private function fill(Router $r, array $d): void
    {
        $r->fill(['name' => $d['name'], 'host' => $d['host'], 'transport' => $d['transport'], 'port' => (int) $d['port'], 'username' => $d['username'], 'is_active' => (bool) ($d['is_active'] ?? false)]);
        if (($d['password'] ?? '') !== '') {
            $r->password = $d['password'];
        }
    }

    /** Best guess of the panel host's IP, for the "allow only this IP" lines of the setup script. */
    private function panelIp(): string
    {
        $ip = (string) request()->server('SERVER_ADDR');

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? $ip : '';
    }
}
