<?php

namespace App\Services\Mikrotik;

use App\Models\MtAccount;
use App\Models\MtGroup;
use App\RouterOs\RouterConnector;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Bring WireGuard peers / L2TP secrets that already exist on the router under panel control.
 * Nothing is changed on the router. Usage counting starts from the current counters (earlier traffic is not charged).
 * WireGuard: the router never stores a peer's private key, so the panel cannot rebuild the customer's file;
 * the customer keeps the file they already have (or the admin pastes the private key, or makes new keys).
 */
class MtImporter
{
    public function __construct(private RouterConnector $ros, private AuditLogger $audit) {}

    /** @return list<array{key:string, name:string, detail:string, disabled:bool, row:array}> rows on the router that the panel does not manage yet */
    public function candidates(MtGroup $g): array
    {
        $out = [];
        if ($g->isWireguard()) {
            $known = MtAccount::withTrashed()->where('router_id', $g->router_id)->whereNotNull('wg_public_key')->pluck('wg_public_key')->flip();
            foreach ($this->ros->get($g->router, 'interface/wireguard/peers') as $p) {
                $pk = (string) ($p['public-key'] ?? '');
                if (($p['interface'] ?? '') !== $g->wg_interface || $pk === '' || isset($known[$pk])) {
                    continue;
                }
                $ip = $this->peerIp($p);
                $out[] = ['key' => (string) $p['.id'], 'name' => $this->suggest($p['name'] ?? $p['comment'] ?? '', $ip ? 'wg-'.str_replace('.', '-', $ip) : 'wg-peer'),
                    'detail' => trim(($ip ?? 'بدون IP').' · '.mb_substr((string) ($p['comment'] ?? ''), 0, 40).' · '.substr($pk, 0, 10).'…', ' ·'),
                    'disabled' => RosValues::bool($p['disabled'] ?? 'false'), 'row' => $p];
            }
        } else {
            $known = MtAccount::query()->where('router_id', $g->router_id)->where('protocol', 'l2tp')->pluck('username')->flip();
            foreach ($this->ros->get($g->router, 'ppp/secret') as $s) {
                $name = (string) ($s['name'] ?? '');
                if ($name === '' || isset($known[$name]) || ! in_array($s['service'] ?? 'any', ['any', 'l2tp'], true)) {
                    continue;
                }
                $out[] = ['key' => (string) $s['.id'], 'name' => $name, 'detail' => 'profile: '.($s['profile'] ?? 'default').(($s['comment'] ?? '') !== '' ? ' · '.mb_substr($s['comment'], 0, 40) : ''),
                    'disabled' => RosValues::bool($s['disabled'] ?? 'false'), 'row' => $s];
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $ids  router .id values chosen by the admin
     * @return array{imported:int, skipped:list<string>}
     */
    public function import(MtGroup $g, array $ids, ?int $days, ?float $volumeGb, ?int $actorId): array
    {
        $byId = collect($this->candidates($g))->keyBy('key');
        $res = ['imported' => 0, 'skipped' => []];
        $importedIds = [];
        foreach ($ids as $id) {
            $c = $byId[$id] ?? null;
            if (! $c) {
                $res['skipped'][] = $id;

                continue;
            }
            $row = $c['row'];
            $attrs = [
                'router_id' => $g->router_id, 'mt_group_id' => $g->id, 'protocol' => $g->protocol, 'create_key' => 'import:'.$g->router_id.':'.$g->protocol.':'.$id,
                'ros_id' => $id, 'status' => $c['disabled'] ? 'disabled' : 'active', 'router_disabled' => $c['disabled'],
                'duration_days' => $days, 'volume_limit_bytes' => $volumeGb ? (int) round($volumeGb * 1073741824) : null,
                'comment' => mb_substr('وارد‌شده از روتر'.(($row['comment'] ?? '') !== '' ? ': '.$row['comment'] : ''), 0, 150), 'created_by' => $actorId, 'last_synced_at' => now(),
            ];
            if ($g->isWireguard()) {
                $ip = $this->peerIp($row);
                $hs = RosValues::seconds($row['last-handshake'] ?? null);
                $attrs += ['username' => $this->unique($g, $c['name']), 'wg_public_key' => $row['public-key'], 'wg_address' => $ip,
                    'wg_preshared_key' => ($row['preshared-key'] ?? '') !== '' && WgKeys::valid((string) $row['preshared-key']) ? $row['preshared-key'] : null,
                    // start counting from what the peer shows now: earlier traffic is not charged
                    'counters' => ['peer' => [RosValues::int($row['rx'] ?? 0), RosValues::int($row['tx'] ?? 0)]],
                    'last_seen_at' => $hs !== null ? now()->subSeconds($hs) : null];
                if ($ip && MtAccount::withTrashed()->where('router_id', $g->router_id)->where('wg_address', $ip)->exists()) {
                    $attrs['wg_address'] = null;
                }
            } else {
                // readable only when the panel's router user has the "sensitive" policy
                $attrs += ['username' => $c['name'], 'password' => ($row['password'] ?? '') !== '' ? (string) $row['password'] : null];
            }
            $importedIds[] = DB::transaction(fn () => MtAccount::query()->create($attrs))->id;
            $res['imported']++;
        }
        if ($res['imported'] && ! $g->isWireguard()) {
            // baseline the live L2TP sessions so their earlier bytes are not charged
            app(MtAccounting::class)->baselineL2tp($g->router, $importedIds);
        }
        $this->audit->log('mt_accounts.import', $g, 'وارد کردن '.fa($res['imported']).' اکانت '.$g->protocolLabel().' از روتر «'.$g->router->name.'»', [
            'days' => [null, $days], 'volume_gb' => [null, $volumeGb],
        ]);

        return $res;
    }

    private function peerIp(array $p): ?string
    {
        foreach (explode(',', (string) ($p['allowed-address'] ?? '')) as $aa) {
            $ip = explode('/', trim($aa))[0];
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }

        return null;
    }

    private function suggest(string $raw, string $fallback): string
    {
        $n = strtolower(preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($raw)) ?? '');
        $n = trim($n, '-._');

        return preg_match(MtProvisioning::USERNAME_RULE, $n) ? substr($n, 0, 32) : $fallback;
    }

    private function unique(MtGroup $g, string $name): string
    {
        $base = substr($name, 0, 28);
        $n = $base;
        for ($i = 2; MtAccount::withTrashed()->where('router_id', $g->router_id)->where('protocol', $g->protocol)->where('username', $n)->exists(); $i++) {
            if ($i > 500) {
                throw new RuntimeException('نام کاربری یکتا پیدا نشد.');
            }
            $n = $base.'-'.$i;
        }

        return $n;
    }
}
