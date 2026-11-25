<?php

namespace App\Services\Ibs;

use App\Ibs\IbsConnector;
use App\Ibs\IbsException;
use App\Models\IbsCharge;
use App\Models\IbsGroup;
use App\Models\VpnAccount;
use App\Services\Settings;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pulls data from IBSng into the local mirror. IBSng remains the source of truth;
 * every mirrored row carries last_synced_at so the UI can show freshness.
 */
class AccountSync
{
    public const UNIT_SECONDS = ['Seconds' => 1, 'Hours' => 3600, 'Days' => 86400, 'Months' => 2592000, 'Years' => 31536000];

    public function __construct(private IbsConnector $ibs, private Settings $settings) {}

    // ------------------------------------------------------------------ catalog

    /** @return array{groups:int, charges:int} */
    public function syncCatalog(): array
    {
        $now = now();
        $charges = $this->ibs->listCharges();
        foreach ($charges as $name) {
            $rules = $this->ibs->chargeRules($name);
            $cpk = 0.0;
            $cpm = 0.0;
            foreach ($rules as $r) {
                $cpk = max($cpk, (float) ($r['cpk'] ?? 0));
                $cpm = max($cpm, (float) ($r['cpm'] ?? 0));
            }
            IbsCharge::query()->updateOrCreate(['name' => $name], ['cpk' => $cpk, 'cpm' => $cpm, 'rules' => $rules, 'present' => true, 'synced_at' => $now]);
        }
        IbsCharge::query()->whereNotIn('name', $charges ?: ['__none__'])->update(['present' => false]);

        $groups = $this->ibs->listGroups();
        foreach ($groups as $name) {
            $g = $this->ibs->groupInfo($name);
            $raw = (array) ($g['raw_attrs'] ?? []);
            $attrs = (array) ($g['attrs'] ?? []);
            IbsGroup::query()->updateOrCreate(['name' => $name], [
                'ibs_group_id' => $g['group_id'] ?? null,
                'rel_exp_seconds' => isset($raw['rel_exp_date']) ? (int) $raw['rel_exp_date'] : null,
                'multi_login' => isset($raw['multi_login']) ? (int) $raw['multi_login'] : null,
                'charge_name' => isset($attrs['normal_charge']) ? (string) $attrs['normal_charge'] : null,
                'comment' => mb_substr((string) ($g['comment'] ?? ''), 0, 255),
                'raw_attrs' => $raw,
                'present' => true,
                'synced_at' => $now,
            ]);
        }
        IbsGroup::query()->whereNotIn('name', $groups ?: ['__none__'])->update(['present' => false]);
        $this->settings->set('sync.catalog_at', $now->toIso8601String());

        return ['groups' => count($groups), 'charges' => count($charges)];
    }

    // ------------------------------------------------------------------ accounts

    /**
     * Upsert one account from a getUserInfo entry. Returns null for raw users without a username.
     *
     * @param  array<string,mixed>  $info
     */
    public function apply(array $info, ?string $source = null): ?VpnAccount
    {
        $attrs = (array) ($info['attrs'] ?? []);
        $basic = (array) ($info['basic_info'] ?? []);
        $username = $attrs['normal_username'] ?? null;
        $userId = (int) ($basic['user_id'] ?? $attrs['user_id'] ?? 0);
        if (! $username || ! $userId) {
            return null;
        }

        $rel = null;
        if (isset($attrs['rel_exp_date'])) {
            $rel = (int) round((float) $attrs['rel_exp_date'] * (self::UNIT_SECONDS[$attrs['rel_exp_date_unit'] ?? 'Seconds'] ?? 1));
        }
        // Expiry from IBSng's *relative* remaining time: independent of the IBSng server clock.
        $expiresAt = isset($attrs['time_to_nearest_exp_date']) ? now()->addSeconds((int) round((float) $attrs['time_to_nearest_exp_date'])) : null;

        $values = [
            'username' => (string) $username,
            'group_name' => $basic['group_name'] ?? null,
            'owner_name' => $basic['owner_name'] ?? null,
            'credit' => (float) ($basic['credit'] ?? 0),
            'rel_exp_seconds' => $rel,
            'multi_login' => isset($attrs['multi_login']) ? (int) $attrs['multi_login'] : null,
            'first_login_ibs' => isset($attrs['first_login']) ? (string) $attrs['first_login'] : null,
            'expires_at' => $expiresAt,
            'locked' => array_key_exists('lock', $attrs),
            'lock_reason' => array_key_exists('lock', $attrs) ? mb_substr((string) $attrs['lock'], 0, 255) : null,
            'comment' => isset($attrs['comment']) ? mb_substr((string) $attrs['comment'], 0, 255) : null,
            'ibs_created_at' => $basic['creation_date'] ?? null,
            'last_synced_at' => now(),
            'sync_error' => null,
            'ibs_deleted_at' => null,
        ];
        if (array_key_exists('online_status', $info) && ! $info['online_status']) {
            $values += ['is_online' => false, 'online_sessions' => 0, 'sessions' => null];
        }

        $account = VpnAccount::withTrashed()->where('ibs_user_id', $userId)->first()
            ?? VpnAccount::withTrashed()->where('username', $username)->first()
            ?? new VpnAccount(['source' => $source ?? 'imported']);
        if ($account->trashed()) {
            $account->restore();
        }
        $account->fill(['ibs_user_id' => $userId] + $values);
        // Volume meter baseline for accounts the panel did not create: the largest remaining volume seen.
        $rem = $account->remainingBytes();
        if ($rem !== null && $rem > (int) $account->volume_total_bytes) {
            $account->volume_total_bytes = $rem;
        }
        $account->save();

        return $account;
    }

    public function refresh(VpnAccount $account): VpnAccount
    {
        try {
            $info = $this->ibs->usersByIds([$account->ibs_user_id])[(string) $account->ibs_user_id] ?? null;
        } catch (IbsException $e) {
            if ($e->ibsCode === 'USERID_DOESNT_EXISTS') {
                $account->forceFill(['ibs_deleted_at' => now(), 'last_synced_at' => now(), 'is_online' => false])->save();

                return $account;
            }
            throw $e;
        }

        return $info ? ($this->apply($info) ?? $account) : $account;
    }

    public function importByUsername(string $username): ?VpnAccount
    {
        $info = $this->ibs->userByUsername($username);

        return $info ? $this->apply($info, 'imported') : null;
    }

    /** Refresh the stalest accounts in batches of 100 ids per IBSng call. @return int accounts refreshed */
    public function syncAccounts(int $limit = 500): int
    {
        $done = 0;
        VpnAccount::query()->whereNull('ibs_deleted_at')
            ->orderByRaw('last_synced_at is not null')->orderBy('last_synced_at')
            ->limit($limit)->get()
            ->chunk(100)->each(function ($chunk) use (&$done) {
                $done += $this->refreshBatch($chunk->all());
            });
        $this->settings->set('sync.accounts_at', now()->toIso8601String());

        return $done;
    }

    /** Refresh specific accounts (100 per server call). @param iterable<VpnAccount> $accounts */
    public function refreshMany(iterable $accounts): int
    {
        $n = 0;
        foreach (collect($accounts)->chunk(100) as $chunk) {
            $n += $this->refreshBatch($chunk->values()->all());
        }

        return $n;
    }

    /** @param list<VpnAccount> $accounts */
    private function refreshBatch(array $accounts): int
    {
        try {
            $infos = $this->ibs->usersByIds(array_map(fn ($a) => $a->ibs_user_id, $accounts));
        } catch (IbsException $e) {
            if ($e->ibsCode !== 'USERID_DOESNT_EXISTS') {
                throw $e;
            }
            // One id in the batch no longer exists: isolate it with single calls.
            foreach ($accounts as $a) {
                $this->refresh($a);
            }

            return count($accounts);
        }
        foreach ($accounts as $a) {
            if (isset($infos[(string) $a->ibs_user_id])) {
                $this->apply($infos[(string) $a->ibs_user_id]);
            }
        }

        return count($accounts);
    }

    /** One call for all online sessions; updates is_online/sessions; imports unknown online users. @return int online users */
    public function syncOnlines(int $importLimit = 20): int
    {
        $byUser = [];
        foreach ($this->ibs->onlineUsers() as $s) {
            $u = (string) ($s['normal_username'] ?? '');
            if ($u === '') {
                continue;
            }
            $byUser[$u][] = [
                'ras_ip' => $s['ras_ip'] ?? null,
                'service' => $s['service'] ?? null,
                'login_epoch' => $s['login_time_epoch'] ?? null,
                'duration_secs' => $s['duration_secs'] ?? null,
                'in_bytes' => $s['in_bytes'] ?? null,
                'out_bytes' => $s['out_bytes'] ?? null,
                'unique_id_val' => $s['unique_id_val'] ?? null,
            ];
        }

        DB::transaction(function () use ($byUser) {
            VpnAccount::query()->where('is_online', true)->whereNotIn('username', array_keys($byUser) ?: ['__none__'])
                ->update(['is_online' => false, 'online_sessions' => 0, 'sessions' => null]);
            foreach ($byUser as $username => $sessions) {
                VpnAccount::query()->where('username', $username)->update([
                    'is_online' => true, 'online_sessions' => count($sessions), 'sessions' => json_encode($sessions),
                ]);
            }
        });

        $known = VpnAccount::query()->whereIn('username', array_keys($byUser) ?: ['__none__'])->pluck('username')->all();
        foreach (array_slice(array_diff(array_keys($byUser), $known), 0, $importLimit) as $username) {
            if ($acc = $this->importByUsername($username)) {
                $acc->forceFill(['is_online' => true, 'online_sessions' => count($byUser[$username]), 'sessions' => $byUser[$username]])->save();
            }
        }
        $this->settings->set('sync.onlines_at', now()->toIso8601String());
        $this->settings->set('sync.onlines_count', (string) count($byUser));

        return count($byUser);
    }

    // ------------------------------------------------------------------ bulk import

    public function startImport(): void
    {
        $this->settings->set('import.state', json_encode(['running' => true, 'cursor' => 0, 'total' => null, 'imported' => 0, 'skipped' => 0, 'started_at' => now()->toIso8601String()]));
    }

    /** @return array<string,mixed>|null */
    public function importState(): ?array
    {
        $s = $this->settings->get('import.state');

        return $s ? json_decode($s, true) : null;
    }

    /**
     * Continue the bulk import. One searchUser call fetches up to $page ids (IBSng allows 3000); they are
     * then read 100 at a time and the cursor is saved after every chunk, so a run stopped by $deadline
     * (microtime) resumes exactly where it left off without re-reading anything.
     */
    public function importStep(int $page = 3000, ?float $deadline = null): ?array
    {
        $st = $this->importState();
        if (! $st || empty($st['running'])) {
            return $st;
        }
        $st += ['no_username' => 0, 'missing' => 0, 'existing' => 0, 'errors' => 0];
        [$total, $ids] = $this->ibs->searchUserIds((int) $st['cursor'], (int) $st['cursor'] + $page);
        $st['total'] = $total;
        foreach (array_chunk($ids, 100) as $chunk) {
            $existing = VpnAccount::withTrashed()->whereIn('ibs_user_id', $chunk)->pluck('ibs_user_id')->all();
            $new = array_values(array_diff($chunk, $existing));
            $st['existing'] += count($existing);
            $returned = 0;
            foreach ($new === [] ? [] : $this->ibs->usersByIds($new) as $info) {
                $returned++;
                try {
                    if ($this->apply((array) $info, 'imported')) {
                        $st['imported']++;
                    } else {
                        $st['no_username']++;
                    }
                } catch (QueryException $e) {
                    // One unusual record must never stop the whole import: count it, log it, move on.
                    $st['errors'] = ($st['errors'] ?? 0) + 1;
                    Log::warning('import: account skipped', ['user_id' => $info['basic_info']['user_id'] ?? null, 'error' => mb_substr($e->getMessage(), 0, 300)]);
                }
            }
            $st['missing'] += max(0, count($new) - $returned);
            $st['skipped'] = $st['existing'] + $st['no_username'] + $st['missing'] + $st['errors'];
            $st['cursor'] += count($chunk);
            $st['updated_at'] = now()->toIso8601String();
            $this->settings->set('import.state', json_encode($st));
            if ($deadline !== null && microtime(true) >= $deadline) {
                return $st;
            }
        }
        if ($ids === [] || $st['cursor'] >= $total) {
            $st['running'] = false;
            $st['finished_at'] = now()->toIso8601String();
            $this->settings->set('import.state', json_encode($st));
        }

        return $st;
    }

    /** Ids checked per minute since the import started, for the progress banner. */
    public function importRate(array $st): ?float
    {
        $mins = isset($st['started_at']) ? max(1, now()->diffInMinutes(Carbon::parse($st['started_at']), true)) : null;

        return $mins ? ((int) $st['cursor']) / $mins : null;
    }
}
