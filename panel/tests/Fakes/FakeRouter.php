<?php

namespace Tests\Fakes;

use App\RouterOs\RestTransport;
use App\RouterOs\RouterOsUnreachable;

/**
 * Stateful RouterOS REST emulator: GET (with ?field=value filters), PUT (add), PATCH (set), DELETE (remove).
 * Tests drive traffic by editing $menus directly (interface counters, peers rx/tx, l2tp sessions).
 */
class FakeRouter implements RestTransport
{
    public array $requests = [];

    public bool $down = false;

    /** @var array<string,string> "PUT ppp/secret" => error detail, consumed once */
    public array $failNext = [];

    /** lose the reply of the next write (router applies it, the panel sees a timeout) */
    public bool $loseNextReply = false;

    private int $seq = 0x100;

    public const SINGLE = ['system/resource', 'system/identity', 'system/license', 'interface/l2tp-server/server'];

    public function __construct(public array $menus) {}

    public function send(string $method, string $url, string $user, string $password, string $pin, ?string $json, int $timeout): array
    {
        $this->requests[] = compact('method', 'url', 'json');
        if ($this->down) {
            throw new RouterOsUnreachable('روتر در زمان مقرر پاسخ نداد (فایروال، IP یا پورت را بررسی کنید).');
        }
        $rel = preg_replace('#^.*/rest/#', '', $url);
        [$path, $qs] = array_pad(explode('?', $rel, 2), 2, '');
        $id = null;
        if (preg_match('#^(.*)/(\*[0-9A-Fa-f]+)$#', $path, $m)) {
            [$path, $id] = [$m[1], $m[2]];
        }
        if ($err = $this->failNext["$method $path"] ?? null) {
            unset($this->failNext["$method $path"]);

            return [400, json_encode(['error' => 400, 'message' => 'Bad Request', 'detail' => $err])];
        }
        $body = $json !== null ? json_decode($json, true) : [];
        $lose = $method !== 'GET' && $this->loseNextReply;

        $out = match ($method) {
            'GET' => $this->get($path, $qs),
            'PUT' => $this->add($path, $body),
            'PATCH' => $this->set($path, $id, $body),
            'DELETE' => $this->remove($path, $id),
        };
        if ($lose) {
            $this->loseNextReply = false;
            throw new RouterOsUnreachable('روتر در زمان مقرر پاسخ نداد.');
        }

        return $out;
    }

    private function get(string $path, string $qs): array
    {
        if (! array_key_exists($path, $this->menus)) {
            return [400, '{"error":400,"message":"no such command"}'];
        }
        parse_str($qs, $q);
        $rows = array_values(array_filter($this->menus[$path], function ($r) use ($q) {
            foreach ($q as $k => $v) {
                if (($r[$k] ?? null) !== $v) {
                    return false;
                }
            }

            return true;
        }));

        return [200, json_encode(in_array($path, self::SINGLE, true) ? ($rows[0] ?? new \stdClass) : $rows)];
    }

    private function add(string $path, array $body): array
    {
        if ($path === 'ppp/secret' && $this->find($path, 'name', $body['name'] ?? '')) {
            return [400, json_encode(['error' => 400, 'message' => 'Bad Request', 'detail' => 'failure: secret with the same name already exists'])];
        }
        $row = ['.id' => '*'.strtoupper(dechex($this->seq++))] + $body + ['disabled' => 'false'];
        $row['disabled'] = in_array($row['disabled'], ['yes', 'true'], true) ? 'true' : 'false';
        if ($path === 'interface/wireguard/peers') {
            $row += ['rx' => '0', 'tx' => '0'];
        }
        $this->menus[$path][] = $row;

        return [201, json_encode($row)];
    }

    private function set(string $path, ?string $id, array $body): array
    {
        foreach ($this->menus[$path] ?? [] as $i => $r) {
            if ($r['.id'] === $id) {
                if (isset($body['disabled'])) {
                    $body['disabled'] = in_array($body['disabled'], ['yes', 'true'], true) ? 'true' : 'false';
                }
                $this->menus[$path][$i] = $body + $r;

                return [200, json_encode($this->menus[$path][$i])];
            }
        }

        return [404, '{"error":404,"message":"Not Found","detail":"no such item"}'];
    }

    private function remove(string $path, ?string $id): array
    {
        foreach ($this->menus[$path] ?? [] as $i => $r) {
            if ($r['.id'] === $id) {
                array_splice($this->menus[$path], $i, 1);
                if ($path === 'ppp/active') {   // the dynamic interface goes away with the session
                    $names = array_column(array_filter($this->menus['interface/l2tp-server'] ?? [], fn ($s) => ($s['session'] ?? null) === $id), 'name');
                    $this->menus['interface/l2tp-server'] = array_values(array_filter($this->menus['interface/l2tp-server'] ?? [], fn ($s) => ($s['session'] ?? null) !== $id));
                    $this->menus['interface'] = array_values(array_filter($this->menus['interface'] ?? [], fn ($i) => ! in_array($i['name'], $names, true)));
                }

                return [204, ''];
            }
        }

        return [404, '{"error":404,"message":"Not Found","detail":"no such item"}'];
    }

    public function find(string $path, string $field, string $value): ?array
    {
        foreach ($this->menus[$path] ?? [] as $r) {
            if (($r[$field] ?? null) === $value) {
                return $r;
            }
        }

        return null;
    }

    // ------------------------------------------------------------ traffic helpers

    /** Start an L2TP session for $user; returns the ppp/active id. */
    public function connectL2tp(string $user, string $ip = '5.5.5.5'): string
    {
        $sid = '*'.strtoupper(dechex($this->seq++));
        $n = count(array_filter($this->menus['ppp/active'] ?? [], fn ($a) => $a['name'] === $user));
        $if = '<l2tp-'.$user.($n ? '-'.$n : '').'>';
        $this->menus['ppp/active'][] = ['.id' => $sid, 'name' => $user, 'service' => 'l2tp', 'caller-id' => $ip, 'address' => '10.10.0.'.(10 + $this->seq % 200), 'uptime' => (10 * ($this->seq % 50)).'s'];
        $iid = '*'.strtoupper(dechex($this->seq++));
        $this->menus['interface/l2tp-server'][] = ['.id' => $iid, 'name' => $if, 'user' => $user, 'dynamic' => 'true', 'client-address' => $ip, 'uptime' => '1m', 'session' => $sid];
        $this->menus['interface'][] = ['.id' => $iid, 'name' => $if, 'type' => 'l2tp-in', 'rx-byte' => '0', 'tx-byte' => '0'];

        return $sid;
    }

    /** Add traffic to every live session of $user (rx = upload, tx = download). */
    public function trafficL2tp(string $user, int $up, int $down): void
    {
        $names = array_column(array_filter($this->menus['interface/l2tp-server'] ?? [], fn ($s) => $s['user'] === $user), 'name');
        foreach ($this->menus['interface'] ?? [] as $i => $if) {
            if (in_array($if['name'], $names, true)) {
                $this->menus['interface'][$i]['rx-byte'] = (string) ((int) $if['rx-byte'] + $up);
                $this->menus['interface'][$i]['tx-byte'] = (string) ((int) $if['tx-byte'] + $down);
            }
        }
    }

    public function disconnectL2tp(string $user): void
    {
        $names = array_column(array_filter($this->menus['interface/l2tp-server'] ?? [], fn ($s) => $s['user'] === $user), 'name');
        $this->menus['ppp/active'] = array_values(array_filter($this->menus['ppp/active'] ?? [], fn ($a) => $a['name'] !== $user));
        $this->menus['interface/l2tp-server'] = array_values(array_filter($this->menus['interface/l2tp-server'] ?? [], fn ($s) => $s['user'] !== $user));
        $this->menus['interface'] = array_values(array_filter($this->menus['interface'] ?? [], fn ($i) => ! in_array($i['name'], $names, true)));
    }

    public function trafficWg(string $publicKey, int $up, int $down, string $handshake = '20s'): void
    {
        foreach ($this->menus['interface/wireguard/peers'] as $i => $p) {
            if ($p['public-key'] === $publicKey) {
                $this->menus['interface/wireguard/peers'][$i]['rx'] = (string) ((int) $p['rx'] + $up);
                $this->menus['interface/wireguard/peers'][$i]['tx'] = (string) ((int) $p['tx'] + $down);
                $this->menus['interface/wireguard/peers'][$i]['last-handshake'] = $handshake;
                $this->menus['interface/wireguard/peers'][$i]['current-endpoint-address'] = '7.7.7.7';
            }
        }
    }

    public function reboot(): void
    {
        $this->menus['system/resource'][0]['uptime'] = '30s';
        foreach ($this->menus['interface/wireguard/peers'] as $i => $p) {
            $this->menus['interface/wireguard/peers'][$i]['rx'] = '0';
            $this->menus['interface/wireguard/peers'][$i]['tx'] = '0';
        }
        $this->menus['ppp/active'] = [];
        $this->menus['interface/l2tp-server'] = [];
        $this->menus['interface'] = [];
    }
}
