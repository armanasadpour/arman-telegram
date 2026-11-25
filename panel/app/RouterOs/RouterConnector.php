<?php

namespace App\RouterOs;

use App\Models\Router;
use Throwable;

/**
 * Entry point for talking to one router: picks the transport, records health (last ok / error / latency)
 * on the routers row, and never lets the password into messages.
 */
class RouterConnector
{
    /** @var array<int, Client> */
    private array $clients = [];

    public function client(Router $r): Client
    {
        return $this->clients[$r->id ?? 0] ??= match ($r->transport) {
            'rest' => new RestClient($r->host, $r->port, $r->username, (string) $r->password, (string) $r->cert_pin),
            'api-ssl' => new ApiClient($r->host, $r->port, $r->username, (string) $r->password, true, $r->cert_pin),
            default => new ApiClient($r->host, $r->port, $r->username, (string) $r->password, false, null),
        };
    }

    /** @return list<array<string,string>> */
    public function get(Router $r, string $path, array $query = []): array
    {
        $t = microtime(true);
        try {
            $rows = $this->client($r)->get($path, $query);
        } catch (Throwable $e) {
            $msg = $this->clean($r, $e->getMessage());
            if ($r->exists) {
                $r->forceFill(['last_error_at' => now(), 'last_error' => mb_substr($msg, 0, 500)])->save();
            }
            throw $e instanceof RouterOsException ? new ($e::class)($msg) : new RouterOsException($msg);
        }
        if ($r->exists) {
            $r->forceFill(['last_ok_at' => now(), 'last_latency_ms' => (int) ((microtime(true) - $t) * 1000)])->save();
        }

        return $rows;
    }

    public function first(Router $r, string $path): array
    {
        return $this->get($r, $path)[0] ?? [];
    }

    /** Identity + resources: the "test connection" answer. */
    public function about(Router $r): array
    {
        $res = $this->first($r, 'system/resource');
        $id = $this->first($r, 'system/identity');
        $info = [
            'identity' => $id['name'] ?? null,
            'version' => $res['version'] ?? null,
            'board' => $res['board-name'] ?? null,
            'architecture' => $res['architecture-name'] ?? null,
            'cpu_count' => isset($res['cpu-count']) ? (int) $res['cpu-count'] : null,
            'cpu_load' => isset($res['cpu-load']) ? (int) $res['cpu-load'] : null,
            'free_memory' => isset($res['free-memory']) ? (int) $res['free-memory'] : null,
            'total_memory' => isset($res['total-memory']) ? (int) $res['total-memory'] : null,
            'uptime' => $res['uptime'] ?? null,
            'checked_at' => now()->toIso8601String(),
        ];
        try {
            $lic = $this->first($r, 'system/license');
            $info['license'] = $lic['nlevel'] ?? $lic['level'] ?? null;
        } catch (RouterOsException) {
            $info['license'] = null;   // CHR / older versions may differ: not essential
        }
        if ($r->exists) {
            $r->forceFill(['info' => $info, 'health' => 'up'])->save();
        }

        return $info;
    }

    /**
     * Read-only readiness check for L2TP and WireGuard. Every item: [status ok|warn|fail|info, title, detail].
     *
     * @return array{checks: list<array{0:string,1:string,2:string}>, l2tp_ready: bool, wg_ready: bool, data: array}
     */
    public function inventory(Router $r): array
    {
        $checks = [];
        $data = [];
        $safe = function (string $path) use ($r) {
            try {
                return Paths::redact($this->get($r, $path));
            } catch (RouterOsUnreachable $e) {
                throw $e;
            } catch (RouterOsException $e) {
                return null;
            }
        };

        // management services
        $svc = $safe('ip/service') ?? [];
        $byName = collect($svc)->keyBy('name');
        $mine = ['rest' => 'www-ssl', 'api-ssl' => 'api-ssl', 'api' => 'api'][$r->transport] ?? 'www-ssl';
        $s = $byName[$mine] ?? null;
        if ($s) {
            $restricted = trim((string) ($s['address'] ?? '')) !== '';
            $checks[] = [$restricted ? 'ok' : 'warn', 'سرویس '.$mine, $restricted ? 'فقط از آدرس‌های '.$s['address'].' قابل دسترسی است.' : 'بدون محدودیت IP باز است؛ در اسکریپت راه‌اندازی، فقط IP هاست پنل را مجاز کنید.'];
        }
        foreach (['telnet', 'ftp', 'www'] as $weak) {
            if (isset($byName[$weak]) && ($byName[$weak]['disabled'] ?? 'true') === 'false' && trim((string) ($byName[$weak]['address'] ?? '')) === '') {
                $checks[] = ['warn', 'سرویس ناامن '.$weak, 'فعال و بدون محدودیت IP است؛ پیشنهاد: غیرفعال یا محدود شود.'];
            }
        }

        // L2TP
        $l2 = ($safe('interface/l2tp-server/server') ?? [[]])[0] ?? [];
        $profiles = $safe('ppp/profile') ?? [];
        $pools = $safe('ip/pool') ?? [];
        $secrets = $safe('ppp/secret');
        $data['l2tp'] = ['server' => $l2, 'profiles' => array_map(fn ($p) => array_intersect_key($p, array_flip(['name', 'local-address', 'remote-address', 'rate-limit', 'only-one', 'dns-server'])), $profiles),
            'pools' => array_map(fn ($p) => array_intersect_key($p, array_flip(['name', 'ranges'])), $pools), 'secret_count' => is_array($secrets) ? count($secrets) : null];
        $l2On = ($l2['enabled'] ?? 'false') === 'true';
        $checks[] = [$l2On ? 'ok' : 'fail', 'سرور L2TP', $l2On ? 'فعال است'.(isset($l2['default-profile']) ? ' — پروفایل پیش‌فرض: '.$l2['default-profile'] : '') : 'غیرفعال است (Interface → L2TP Server → Enabled).'];
        $ipsec = in_array($l2['use-ipsec'] ?? 'no', ['yes', 'required'], true);
        $checks[] = [$ipsec ? 'ok' : 'warn', 'IPsec برای L2TP', $ipsec ? 'use-ipsec='.$l2['use-ipsec'].' (کلید مشترک تنظیم شده)' : 'IPsec خاموش است؛ گوشی‌ها (iOS/Android) معمولاً L2TP بدون IPsec را پشتیبانی نمی‌کنند.'];
        $poolProfiles = array_filter($profiles, fn ($p) => ($p['remote-address'] ?? '') !== '' && ($p['name'] ?? '') !== 'default-encryption');
        $checks[] = [$poolProfiles ? 'ok' : 'fail', 'پروفایل PPP با Pool آدرس', $poolProfiles ? fa(count($poolProfiles)).' پروفایل دارای remote-address: '.implode('، ', array_column($poolProfiles, 'name')) : 'هیچ پروفایلی remote-address (Pool) ندارد؛ کاربران L2TP آدرس نمی‌گیرند.'];
        if (is_array($secrets)) {
            $checks[] = ['info', 'کاربران PPP فعلی روی روتر', fa(count($secrets)).' کاربر (ساخته‌شده به‌صورت دستی یا قبلی)'];
        }

        // WireGuard
        $wg = $safe('interface/wireguard') ?? [];
        $peers = $safe('interface/wireguard/peers');
        $addrs = $safe('ip/address') ?? [];
        $data['wireguard'] = ['interfaces' => array_map(fn ($w) => array_intersect_key($w, array_flip(['name', 'listen-port', 'public-key', 'running', 'disabled', 'mtu'])), $wg),
            'peer_count' => is_array($peers) ? count($peers) : null,
            'addresses' => array_values(array_map(fn ($a) => array_intersect_key($a, array_flip(['address', 'interface'])), array_filter($addrs, fn ($a) => in_array($a['interface'] ?? '', array_column($wg, 'name'), true))))];
        $checks[] = [$wg ? 'ok' : 'fail', 'اینترفیس WireGuard', $wg ? implode('، ', array_map(fn ($w) => ($w['name'] ?? '?').' (پورت '.fa($w['listen-port'] ?? '?').')', $wg)) : 'هیچ اینترفیس WireGuard ساخته نشده است.'];
        $wgAddr = $data['wireguard']['addresses'];
        if ($wg) {
            $checks[] = [$wgAddr ? 'ok' : 'fail', 'آدرس IP روی اینترفیس WireGuard', $wgAddr ? implode('، ', array_map(fn ($a) => $a['address'].' روی '.$a['interface'], $wgAddr)) : 'اینترفیس WireGuard آدرس IP ندارد؛ پنل از این شبکه به مشتری‌ها IP می‌دهد.'];
            $checks[] = ['info', 'Peerهای فعلی WireGuard', (is_array($peers) ? fa(count($peers)) : '?').' Peer'];
        }

        $result = [
            'checks' => $checks,
            'l2tp_ready' => $l2On && (bool) $poolProfiles,
            'wg_ready' => (bool) $wg && (bool) $wgAddr,
            'data' => $data,
            'checked_at' => now()->toIso8601String(),
        ];
        if ($r->exists) {
            $r->forceFill(['inventory' => $result])->save();
        }

        return $result;
    }

    private function clean(Router $r, string $m): string
    {
        $pw = (string) $r->password;

        return $pw !== '' ? str_replace($pw, '***', $m) : $m;
    }
}
