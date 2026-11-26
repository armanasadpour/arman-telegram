<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Records the IPs accounts connect from. One row per (account, IP): first and last time seen and how many
 * separate connections (a gap of more than 10 minutes counts as a new connection).
 */
class ConnectionLog
{
    public static function key(string $type, int $id): string
    {
        return $type.':'.$id;
    }

    public function seen(string $protocol, string $accountKey, ?string $ip, ?int $device = null): void
    {
        $ip = trim((string) $ip);
        if (! filter_var($ip, FILTER_VALIDATE_IP) && preg_match('/^\[?([^\]]+?)\]?:\d+$/', $ip, $m)) {
            $ip = $m[1];   // "1.2.3.4:51820" / "[2001:db8::1]:51820" -> address only
        }
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return;
        }
        try {
            $row = DB::table('connection_logs')->where('account_key', $accountKey)->where('ip', $ip)->first();
            if ($row) {
                $new = strtotime((string) $row->last_seen_at) < now()->subMinutes(10)->getTimestamp();
                DB::table('connection_logs')->where('id', $row->id)->update(['last_seen_at' => now(), 'connections' => $row->connections + ($new ? 1 : 0)]);
            } else {
                DB::table('connection_logs')->insertOrIgnore(['protocol' => $protocol, 'account_key' => $accountKey, 'ip' => mb_substr($ip, 0, 64),
                    'device' => $device, 'connections' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]);
            }
        } catch (Throwable $e) {
            report($e);   // logging never breaks the sync
        }
    }

    /** @return Collection<int, object> */
    public function forAccount(string $accountKey, int $limit = 100)
    {
        return Schema::hasTable('connection_logs')
            ? DB::table('connection_logs')->where('account_key', $accountKey)->orderByDesc('last_seen_at')->limit($limit)->get()
            : collect();
    }

    public function prune(int $days = 180): int
    {
        return Schema::hasTable('connection_logs') ? DB::table('connection_logs')->where('last_seen_at', '<', now()->subDays($days))->delete() : 0;
    }

    /** The customer's own address in an IBSng online record (field names differ between setups). */
    public static function ibsClientIp(array $s): ?string
    {
        $flat = $s + (is_array($s['attrs'] ?? null) ? $s['attrs'] : []);
        foreach (['station_ip', 'calling_station_id', 'caller_id', 'client_ip', 'remote_addr'] as $k) {
            if (! empty($flat[$k]) && is_scalar($flat[$k]) && filter_var(preg_replace('/:\d+$/', '', (string) $flat[$k]), FILTER_VALIDATE_IP)) {
                return preg_replace('/:\d+$/', '', (string) $flat[$k]);
            }
        }
        foreach ($flat as $k => $v) {
            if (is_string($k) && is_scalar($v) && preg_match('/station|caller|calling|client/i', $k) && filter_var((string) $v, FILTER_VALIDATE_IP)) {
                return (string) $v;
            }
        }

        return null;
    }
}
