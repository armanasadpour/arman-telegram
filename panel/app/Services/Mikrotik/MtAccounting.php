<?php

namespace App\Services\Mikrotik;

use App\Models\MtAccount;
use App\Models\MtDevice;
use App\Models\Router;
use App\RouterOs\RouterConnector;
use App\RouterOs\RouterOsException;
use App\RouterOs\RouterOsUnreachable;
use App\Services\ConnectionLog;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Usage accounting by polling (runs every minute):
 *   - L2TP: each live session is a dynamic interface "<l2tp-user>" whose rx/tx counters start at 0 per session.
 *     The panel remembers the last counters per session id; delta = now − last (or now, for a new session).
 *   - WireGuard: per-peer rx/tx counters run from the interface start. delta = now − last; when counters go
 *     backwards or the router's uptime dropped (reboot), the current value is the delta.
 * Bytes a session moves between the last poll and its disconnection are not seen (at most one poll interval).
 * Then limits are enforced: first use starts the clock, expired / depleted accounts are disabled on the router,
 * and extra L2TP sessions beyond the multi-login limit are dropped.
 */
class MtAccounting
{
    public function __construct(private RouterConnector $ros, private MtActions $actions) {}

    /** @return array{accounts:int, bytes:int, disabled:int, kicked:int, missing:int} */
    public function syncRouter(Router $r): array
    {
        $stats = ['accounts' => 0, 'bytes' => 0, 'disabled' => 0, 'kicked' => 0, 'missing' => 0];
        $accounts = MtAccount::query()->where('router_id', $r->id)->whereNotIn('status', ['pending', 'failed'])->with('group', 'devices')->get();
        if ($accounts->isEmpty()) {
            return $stats;
        }
        $res = $this->ros->first($r, 'system/resource');
        $uptime = RosValues::seconds($res['uptime'] ?? null);
        $prevUptime = $r->acct_state['uptime'] ?? null;
        $rebooted = $uptime !== null && $prevUptime !== null && $uptime < $prevUptime;

        $l2tp = $accounts->where('protocol', 'l2tp');
        $wg = $accounts->where('protocol', 'wireguard');
        $sessions = $secrets = $peers = [];
        if ($l2tp->isNotEmpty()) {
            $sessions = $this->l2tpSessions($r);
            foreach ($this->ros->get($r, 'ppp/secret') as $s) {
                $secrets[$s['name'] ?? ''] = $s;
            }
        }
        if ($wg->isNotEmpty()) {
            foreach ($this->ros->get($r, 'interface/wireguard/peers') as $p) {
                $peers[$p['public-key'] ?? ''] = $p;
            }
        }

        foreach ($accounts as $a) {
            $stats['accounts']++;
            try {
                $row = $a->isWireguard() ? ($peers[$a->wg_public_key] ?? null) : ($secrets[$a->username] ?? null);
                $d = $a->isWireguard() ? $this->wgDelta($a, $row, $rebooted) : $this->l2tpDelta($a, $sessions[$a->username] ?? []);
                $d['peer_ip'] = $a->isWireguard() ? $d['ip'] : null;
                $devDrift = null;
                $devMissing = false;
                if ($a->isWireguard()) {
                    // devices 2..N add to the same account totals
                    $d['devices'] = [];
                    foreach ($a->devices as $dev) {
                        $drow = $peers[$dev->wg_public_key] ?? null;
                        $dd = $this->wgDelta($dev, $drow, $rebooted);
                        $d['up'] += $dd['up'];
                        $d['down'] += $dd['down'];
                        $d['online'] += $dd['online'];
                        $d['ip'] ??= $dd['ip'];
                        if ($dd['seen'] && (! $d['seen'] || $dd['seen']->gt($d['seen']))) {
                            $d['seen'] = $dd['seen'];
                        }
                        $d['devices'][] = [$dev, $dd, $drow];
                        $devMissing = $devMissing || $drow === null;
                        if ($drow !== null && RosValues::bool($drow['disabled'] ?? 'false') !== $a->router_disabled) {
                            $devDrift = RosValues::bool($drow['disabled'] ?? 'false');
                        }
                    }
                    $devMissing = $devMissing || $a->devices->count() !== $a->deviceCount() - 1;
                }
                $stats['bytes'] += $d['up'] + $d['down'];
                $this->logIps($a, $d, $sessions[$a->username] ?? []);
                $a = $this->record($a, $d, $row);
                if ($row === null) {
                    $stats['missing']++;
                    $this->recreate($a);

                    continue;
                }
                if ($devMissing) {
                    $stats['missing']++;
                    try {
                        app(MtProvisioning::class)->syncDevices($a);
                    } catch (RouterOsException $e) {
                        report($e);
                    }
                }
                $routerDisabled = RosValues::bool($row['disabled'] ?? 'false');
                if ($routerDisabled === $a->router_disabled && $devDrift !== null) {
                    $routerDisabled = $devDrift;
                }
                if ($routerDisabled !== $a->router_disabled) {
                    $a->forceFill(['router_disabled' => $routerDisabled])->save();   // changed by hand on the router: re-apply the panel's view
                }
                $wasDisabled = $a->router_disabled;
                $this->actions->syncState($a);
                $stats['disabled'] += (int) (! $wasDisabled && $a->router_disabled);
                if (! $a->isWireguard() && $a->shouldBeEnabled()) {
                    $limit = $a->multi_login ?? $a->group?->multi_login;
                    if ($limit && count($sessions[$a->username] ?? []) > $limit) {
                        $stats['kicked'] += $this->actions->kick($a, $limit);
                    }
                }
            } catch (RouterOsException $e) {
                throw $e;   // router trouble: stop this run, keep state; next run continues from the stored counters
            } catch (Throwable $e) {
                report($e);
            }
        }
        $r->forceFill(['acct_state' => ['uptime' => $uptime], 'acct_at' => now()])->save();

        return $stats;
    }

    /** Mark the live L2TP sessions of just-imported accounts as already counted. @param list<int> $ids */
    public function baselineL2tp(Router $r, array $ids): void
    {
        $bytes = [];
        foreach ($this->ros->get($r, 'interface', ['type' => 'l2tp-in']) as $if) {
            $bytes[$if['name'] ?? ''] = [RosValues::int($if['rx-byte'] ?? 0), RosValues::int($if['tx-byte'] ?? 0)];
        }
        $byUser = [];
        foreach ($this->ros->get($r, 'interface/l2tp-server') as $s) {
            if (RosValues::bool($s['dynamic'] ?? 'false') && ($s['user'] ?? '') !== '') {
                $byUser[$s['user']][($s['.id'] ?? '').'|'.($s['name'] ?? '')] = $bytes[$s['name'] ?? ''] ?? [0, 0];
            }
        }
        foreach (MtAccount::query()->whereKey($ids)->get() as $a) {
            $a->forceFill(['counters' => ['sessions' => $byUser[$a->username] ?? []]])->save();
        }
    }

    /**
     * Live L2TP sessions per user. /ppp/active is the source of truth for "who is connected"; byte counters come
     * from the session's dynamic interface (<l2tp-user> or <l2tp-user-N>), matched through /interface/l2tp-server
     * when it reports the user, otherwise by the interface name.
     *
     * @return array<string, list<array{key:string,rx:int,tx:int,ip:?string,uptime:int,active_id:string}>>
     */
    public function l2tpSessions(Router $r): array
    {
        $bytes = [];
        foreach ($this->ros->get($r, 'interface', ['type' => 'l2tp-in']) as $if) {
            $bytes[$if['name'] ?? ''] = [RosValues::int($if['rx-byte'] ?? 0), RosValues::int($if['tx-byte'] ?? 0)];
        }
        $srvByUser = [];
        $srvId = [];
        try {
            foreach ($this->ros->get($r, 'interface/l2tp-server') as $s) {
                if (RosValues::bool($s['dynamic'] ?? 'false') && ($s['name'] ?? '') !== '') {
                    $srvId[$s['name']] = (string) ($s['.id'] ?? '');
                    if (($s['user'] ?? '') !== '') {
                        $srvByUser[$s['user']][] = $s['name'];
                    }
                }
            }
        } catch (RouterOsException $e) {
            if ($e instanceof RouterOsUnreachable) {
                throw $e;
            }
        }
        $active = [];
        foreach ($this->ros->get($r, 'ppp/active') as $a) {
            if (($a['service'] ?? 'l2tp') === 'l2tp' && ($a['name'] ?? '') !== '') {
                $active[$a['name']][] = $a;
            }
        }
        $out = [];
        foreach ($active as $user => $rows) {
            usort($rows, fn ($x, $y) => (RosValues::seconds($y['uptime'] ?? '') ?? 0) <=> (RosValues::seconds($x['uptime'] ?? '') ?? 0));   // oldest first
            $names = $srvByUser[$user] ?? array_values(array_filter(array_keys($bytes), fn ($n) => preg_match('/^<l2tp-'.preg_quote($user, '/').'(-\d+)?>$/', $n)));
            sort($names, SORT_NATURAL);
            foreach ($rows as $i => $a) {
                $name = $names[$i] ?? null;
                [$rx, $tx] = $name !== null ? ($bytes[$name] ?? [0, 0]) : [0, 0];
                $out[$user][] = ['key' => ($name !== null && isset($srvId[$name]) ? $srvId[$name] : (string) ($a['.id'] ?? '')).'|'.($name ?? ''),
                    'rx' => $rx, 'tx' => $tx, 'ip' => ($a['caller-id'] ?? '') !== '' ? $a['caller-id'] : null,
                    'uptime' => RosValues::seconds($a['uptime'] ?? null) ?? 0, 'active_id' => (string) ($a['.id'] ?? '')];
            }
        }

        return $out;
    }

    private function logIps(MtAccount $a, array $d, array $l2tpSessions): void
    {
        $log = app(ConnectionLog::class);
        $key = 'm:'.$a->id;
        if (! $a->isWireguard()) {
            foreach ($l2tpSessions as $s) {
                $log->seen('l2tp', $key, $s['ip'] ?? null);
            }

            return;
        }
        if ($d['peer_ip'] ?? null) {
            $log->seen('wireguard', $key, $d['peer_ip'], 1);
        }
        foreach ($d['devices'] ?? [] as [$dev, $dd]) {
            if ($dd['ip'] ?? null) {
                $log->seen('wireguard', $key, $dd['ip'], $dev->idx);
            }
        }
    }

    /** @param list<array{key:string,rx:int,tx:int,ip:?string,uptime:int}> $sessions */
    private function l2tpDelta(MtAccount $a, array $sessions): array
    {
        $prev = $a->counters['sessions'] ?? [];
        $now = [];
        $up = $down = 0;
        foreach ($sessions as $s) {
            $p = $prev[$s['key']] ?? [0, 0];
            $up += $s['rx'] >= $p[0] ? $s['rx'] - $p[0] : $s['rx'];
            $down += $s['tx'] >= $p[1] ? $s['tx'] - $p[1] : $s['tx'];
            $now[$s['key']] = [$s['rx'], $s['tx']];
        }
        $last = $sessions ? collect($sessions)->sortBy('uptime')->first() : null;

        return ['up' => $up, 'down' => $down, 'counters' => ['sessions' => $now], 'online' => count($sessions),
            'ip' => $last['ip'] ?? null, 'seen' => $sessions ? now() : null];
    }

    private function wgDelta(MtAccount|MtDevice $a, ?array $peer, bool $rebooted): array
    {
        if ($peer === null) {
            return ['up' => 0, 'down' => 0, 'counters' => $a->counters ?? [], 'online' => 0, 'ip' => null, 'seen' => null];
        }
        $rx = RosValues::int($peer['rx'] ?? 0);
        $tx = RosValues::int($peer['tx'] ?? 0);
        $p = $a->counters['peer'] ?? null;
        if ($p === null) {
            // First look at this peer: a brand-new peer starts at 0, so whatever it shows was used by this account.
            [$up, $down] = [$rx, $tx];
        } elseif ($rebooted || $rx < $p[0] || $tx < $p[1]) {
            [$up, $down] = [$rx, $tx];
        } else {
            [$up, $down] = [$rx - $p[0], $tx - $p[1]];
        }
        $hs = RosValues::seconds($peer['last-handshake'] ?? null);
        $online = ! RosValues::bool($peer['disabled'] ?? 'false') && (($hs !== null && $hs <= 180) || ($p !== null && $up + $down > 0));

        return ['up' => $up, 'down' => $down, 'counters' => ['peer' => [$rx, $tx]], 'online' => $online ? 1 : 0,
            'ip' => $online ? ($peer['current-endpoint-address'] ?? null) : null, 'seen' => $hs !== null ? now()->subSeconds($hs) : null];
    }

    private function record(MtAccount $a, array $d, ?array $row): MtAccount
    {
        return DB::transaction(function () use ($a, $d, $row) {
            /** @var MtAccount $a */
            $a = MtAccount::query()->lockForUpdate()->with('group')->findOrFail($a->id);
            $bytes = $d['up'] + $d['down'];
            if ($bytes > 0) {
                $a->used_bytes += $bytes;
                $a->upload_bytes += $d['up'];
                $a->download_bytes += $d['down'];
                $day = now()->setTimezone('Asia/Tehran')->toDateString();
                $u = DB::table('mt_usage_daily')->where('mt_account_id', $a->id)->where('day', $day)->first();
                $u ? DB::table('mt_usage_daily')->where('id', $u->id)->update(['upload_bytes' => $u->upload_bytes + $d['up'], 'download_bytes' => $u->download_bytes + $d['down']])
                    : DB::table('mt_usage_daily')->insert(['mt_account_id' => $a->id, 'day' => $day, 'upload_bytes' => $d['up'], 'download_bytes' => $d['down']]);
            }
            $active = $bytes > 0 || $d['online'] > 0;
            if ($active && $a->first_used_at === null) {
                $a->first_used_at = now();
                if ($a->duration_days && $a->expires_at === null) {
                    $a->expires_at = now()->addDays($a->duration_days);
                }
            }
            if ($d['seen'] && ($a->last_seen_at === null || $d['seen']->gt($a->last_seen_at))) {
                $a->last_seen_at = $d['seen'];
            }
            if ($row !== null && isset($row['.id']) && $row['.id'] !== $a->ros_id) {
                $a->ros_id = $row['.id'];
            }
            $a->forceFill(['counters' => $d['counters'], 'is_online' => $d['online'] > 0, 'online_count' => $d['online'], 'online_ip' => $d['ip'],
                'last_synced_at' => now(), 'error' => $row === null ? 'روی روتر پیدا نشد؛ دوباره ساخته می‌شود.' : $a->error]);
            $this->actions->recomputeStatus($a);
            $a->save();
            foreach ($d['devices'] ?? [] as [$dev, $dd, $drow]) {
                $dev->forceFill(['counters' => $dd['counters'], 'is_online' => $dd['online'] > 0, 'online_ip' => $dd['ip'],
                    'last_seen_at' => $dd['seen'] && (! $dev->last_seen_at || $dd['seen']->gt($dev->last_seen_at)) ? $dd['seen'] : $dev->last_seen_at,
                    'ros_id' => $drow === null ? null : ($drow['.id'] ?? $dev->ros_id)])->save();
            }

            return $a;
        });
    }

    /** The secret / peer disappeared from the router (reset, manual delete): create it again with the same credentials. */
    private function recreate(MtAccount $a): void
    {
        $prev = $a->status;
        $a->forceFill(['status' => 'pending', 'ros_id' => null, 'router_disabled' => false, 'counters' => null])->save();
        $a = app(MtProvisioning::class)->push($a);
        if ($a->status === 'active') {
            $a->status = $prev;
            $this->actions->recomputeStatus($a);
            $a->save();
            $this->actions->syncState($a);
        }
    }
}
