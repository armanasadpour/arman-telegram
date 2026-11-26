<?php

namespace App\Ibs;

use App\Services\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only way the panel talks to IBSng. All method names and arguments are the ones
 * proven in phase 1 against the production server (see docs/phase-1/06-proof-results.md).
 *
 * - Reads are retried on transport errors; writes are NEVER retried blindly
 *   (callers re-check state first — see ProvisioningService).
 * - A circuit breaker opens after repeated transport failures so a dead IBSng
 *   does not make every panel page hang for the full timeout.
 * - The admin password is never logged.
 */
class IbsConnector
{
    private const BREAKER_KEY = 'ibs.breaker';

    private const BREAKER_THRESHOLD = 3;

    private const BREAKER_SECONDS = 60;

    private ?Transport $transport = null;

    public function __construct(private Settings $settings, ?Transport $transport = null)
    {
        $this->transport = $transport;
    }

    public function isConfigured(): bool
    {
        return $this->settings->get('ibs.host') && $this->settings->get('ibs.username') && $this->settings->get('ibs.password') !== null;
    }

    // ------------------------------------------------------------------ reads

    /** @return array<string,mixed> */
    public function adminInfo(): array
    {
        return (array) $this->read('admin.getAdminInfo', ['admin_username' => (string) $this->settings->get('ibs.username')]);
    }

    /** @return list<string> */
    public function listGroups(): array
    {
        return array_values(array_map('strval', (array) $this->read('group.listGroups', [])));
    }

    /** @return array<string,mixed> */
    public function groupInfo(string $name): array
    {
        return (array) $this->read('group.getGroupInfo', ['group_name' => $name]);
    }

    /** @return list<string> */
    public function listCharges(): array
    {
        return array_values(array_map('strval', (array) $this->read('charge.listCharges', [])));
    }

    /** @return list<array<string,mixed>> */
    public function chargeRules(string $name): array
    {
        return array_values((array) $this->read('charge.listChargeRules', ['charge_name' => $name]));
    }

    /** @return list<array<string,mixed>> internet (normal) online sessions */
    public function onlineUsers(): array
    {
        $r = (array) $this->read('report.getOnlineUsers', [
            'normal_sort_by' => 'login_time_epoch', 'normal_desc' => true,
            'voip_sort_by' => 'login_time_epoch', 'voip_desc' => true,
            'conds' => new \stdClass,
        ]);

        return array_values((array) ($r[0] ?? []));
    }

    /** @return array<string,mixed>|null user info or null when the username does not exist */
    public function userByUsername(string $username): ?array
    {
        try {
            $r = (array) $this->read('user.getUserInfo', ['normal_username' => $username]);
        } catch (IbsException $e) {
            if ($e->ibsCode === 'NORMAL_USERNAME_DOESNT_EXISTS') {
                return null;
            }
            throw $e;
        }

        return $r ? array_values($r)[0] : null;
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, array<string,mixed>> keyed by user_id (as string)
     */
    public function usersByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return (array) $this->read('user.getUserInfo', ['user_id' => implode(',', array_map('intval', $ids))]);
    }

    /** @return array{0:int,1:list<int>} [total count, user ids in this page]; page size <= 3000 (IBSng limit) */
    public function searchUserIds(int $from, int $to): array
    {
        $r = (array) $this->read('user.searchUser', [
            'conds' => new \stdClass, 'from' => $from, 'to' => $to, 'order_by' => 'user_id', 'desc' => false,
        ]);

        return [(int) ($r[0] ?? 0), array_map('intval', (array) ($r[1] ?? []))];
    }

    public function usernameAvailable(string $username): bool
    {
        // Returns an empty struct when the name is free; otherwise {"<reason>": [names]}.
        return (array) $this->read('normal_user.checkNormalUsernameForAdd', ['normal_username' => $username, 'current_username' => '']) === [];
    }

    // ------------------------------------------------------------------ writes (never auto-retried)

    /** Step 1 of account creation: a raw user with no username. Returns the new user_id. */
    public function addRawUser(string $group, float $credit, string $creditComment): int
    {
        $ids = (array) $this->write('user.addNewUsers', [
            'count' => 1, 'credit' => $credit, 'owner_name' => (string) $this->settings->get('ibs.username'),
            'group_name' => $group, 'credit_comment' => $creditComment,
        ]);
        if (count($ids) !== 1) {
            throw new IbsUnavailableException('Unexpected addNewUsers result');
        }

        return (int) $ids[0];
    }

    /**
     * @param  array<string,mixed>  $attrs
     * @param  list<string>  $delete
     */
    public function updateAttrs(int $userId, array $attrs, array $delete = []): void
    {
        $this->write('user.updateUserAttrs', ['user_id' => (string) $userId, 'attrs' => $attrs ?: new \stdClass, 'to_del_attrs' => $delete]);
    }

    /** Attributes for setting username + password, exactly as proven in phase 1. */
    public static function credentialAttrs(string $username, string $password): array
    {
        return [
            'normal_username' => $username,
            'normal_password' => $password,
            'normal_generate_password' => 0,
            'normal_generate_password_len' => 0,
            'normal_save_usernames' => '',
        ];
    }

    /** group.addNewGroup (IBSng name rules: letters, digits, "_" and "-"). */
    public function addGroup(string $name, string $comment): void
    {
        $this->write('group.addNewGroup', ['group_name' => $name, 'comment' => $comment]);
    }

    /**
     * group.updateGroupAttrs — same attribute names as users (rel_exp_date+unit, multi_login, normal_charge).
     *
     * @param  array<string,mixed>  $attrs
     * @param  list<string>  $delete
     */
    public function updateGroupAttrs(string $name, array $attrs, array $delete = []): void
    {
        $this->write('group.updateGroupAttrs', ['group_name' => $name, 'attrs' => $attrs ?: new \stdClass, 'to_del_attrs' => $delete]);
    }

    /** Adds (or with a negative value, subtracts) credit. */
    public function changeCredit(int $userId, float $credit, string $comment): void
    {
        $this->write('user.changeCredit', ['user_id' => (string) $userId, 'credit' => $credit, 'credit_comment' => $comment]);
    }

    /** Disconnect one online session (user.killUser; kill=true removes it on the RAS). */
    public function killUser(int $userId, string $rasIp, string $uniqueIdVal): void
    {
        $this->write('user.killUser', ['user_id' => (string) $userId, 'ras_ip' => $rasIp, 'unique_id_val' => $uniqueIdVal, 'kill' => true]);
    }

    public function deleteUser(int $userId, string $comment): void
    {
        $this->write('user.delUser', [
            'user_id' => (string) $userId, 'delete_comment' => $comment,
            'del_connection_logs' => false, 'del_audit_logs' => false,
        ]);
    }

    // ------------------------------------------------------------------ plumbing

    /** @return array{ok_at:?string, error_at:?string, error:?string, latency_ms:?int, breaker_open:bool} */
    public function health(): array
    {
        $b = Cache::get(self::BREAKER_KEY, []);

        return [
            'ok_at' => Cache::get('ibs.last_ok_at'),
            'error_at' => Cache::get('ibs.last_error_at'),
            'error' => Cache::get('ibs.last_error'),
            'latency_ms' => Cache::get('ibs.last_latency_ms'),
            'breaker_open' => isset($b['open_until']) && $b['open_until'] > time(),
        ];
    }

    public function resetBreaker(): void
    {
        Cache::forget(self::BREAKER_KEY);
    }

    private function read(string $method, array $args): mixed
    {
        $delays = [0, 300_000, 1_000_000]; // up to 3 attempts for transport errors only
        foreach ($delays as $i => $us) {
            if ($us) {
                usleep($us);
            }
            try {
                return $this->send($method, $args);
            } catch (IbsUnavailableException $e) {
                if ($i === count($delays) - 1 || $this->breakerOpen()) {
                    throw $e;
                }
            }
        }

        throw new IbsUnavailableException('unreachable'); // @codeCoverageIgnore
    }

    private function write(string $method, array $args): mixed
    {
        return $this->send($method, $args);
    }

    private function send(string $method, array $args): mixed
    {
        if ($this->breakerOpen()) {
            throw new IbsUnavailableException('ارتباط با سرور موقتاً قطع است (چند خطای پیاپی). یک دقیقه دیگر دوباره تلاش می‌شود.');
        }
        $params = [
            'auth_name' => (string) $this->settings->get('ibs.username'),
            'auth_pass' => (string) $this->settings->get('ibs.password'),
            'auth_type' => 'ADMIN',
        ] + $args;

        $transport = $this->transport(); // throws IbsNotConfiguredException: a setup problem, not an outage
        $t = microtime(true);
        try {
            $result = $transport->call($method, $params);
        } catch (IbsException $e) {
            $this->recordOk($t); // IBSng is up; it just refused the request

            throw $e;
        } catch (IbsUnavailableException $e) {
            $this->recordFailure($method, $e->getMessage());

            throw $e;
        } catch (Throwable $e) {
            $this->recordFailure($method, class_basename($e));

            throw new IbsUnavailableException('IBSng call failed: '.class_basename($e), 0, $e);
        }
        $this->recordOk($t);

        return $result;
    }

    private function transport(): Transport
    {
        if ($this->transport) {
            return $this->transport;
        }
        if (! $this->isConfigured()) {
            throw new IbsNotConfiguredException('اتصال به سرور هنوز تنظیم نشده است (تنظیمات ← اتصال به سرور IBSng).');
        }

        return $this->transport = new HttpTransport(
            (string) $this->settings->get('ibs.host'),
            (int) $this->settings->get('ibs.port', '1235'),
            (float) $this->settings->get('ibs.timeout', '15'),
        );
    }

    private function breakerOpen(): bool
    {
        $b = Cache::get(self::BREAKER_KEY, []);

        return isset($b['open_until']) && $b['open_until'] > time();
    }

    private function recordOk(float $start): void
    {
        Cache::forget(self::BREAKER_KEY);
        Cache::forever('ibs.last_ok_at', now()->toIso8601String());
        Cache::forever('ibs.last_latency_ms', (int) round((microtime(true) - $start) * 1000));
    }

    private function recordFailure(string $method, string $error): void
    {
        $b = Cache::get(self::BREAKER_KEY, ['fails' => 0]);
        $b['fails'] = ($b['fails'] ?? 0) + 1;
        if ($b['fails'] >= self::BREAKER_THRESHOLD) {
            $b['open_until'] = time() + self::BREAKER_SECONDS;
        }
        Cache::put(self::BREAKER_KEY, $b, 600);
        Cache::forever('ibs.last_error_at', now()->toIso8601String());
        Cache::forever('ibs.last_error', mb_substr("$method: $error", 0, 250));
        Log::warning('IBSng call failed', ['method' => $method, 'error' => $error]);
    }
}
