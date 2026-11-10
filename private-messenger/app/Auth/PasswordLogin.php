<?php
declare(strict_types=1);

namespace PM\Auth;

use PM\Audit\AuditLog;
use PM\Infra\Database;
use PM\Settings\Settings;
use PM\Support\Clock;
use PM\Support\Passwords;

final class PasswordLogin
{
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const INACTIVE = 'inactive';
    public const RATE_LIMITED = 'rate_limited';

    public function __construct(
        private readonly Database $db,
        private readonly Settings $settings,
        private readonly RateLimiter $limiter,
        private readonly AuditLog $audit,
    ) {
    }

    /** @return array{0:string, 1:?array} result code and user row on success/inactive */
    public function attempt(string $username, string $password, string $ip): array
    {
        if (!$this->limiter->hit('login:ip:' . $ip, $this->settings->int('login.per_ip_15min'), 900)) {
            return [self::RATE_LIMITED, null];
        }
        $username = mb_strtolower(trim($username));
        $user = ($username === '' || mb_strlen($username) > 64) ? null
            : $this->db->one('SELECT * FROM users WHERE username = ? AND status <> ?', [$username, 'deleted']);

        if ($user !== null && $user['locked_until'] !== null && strtotime($user['locked_until'] . ' UTC') > Clock::now()->getTimestamp()) {
            Passwords::verify($password, null); // keep timing uniform
            \PM\Admin\Stats::bump($this->db, 'login.failed');
            return [self::INVALID, null];
        }
        if (!Passwords::verify($password, $user['password_hash'] ?? null)) {
            if ($user !== null) {
                $this->registerFailure($user, $ip);
            }
            \PM\Admin\Stats::bump($this->db, 'login.failed');
            return [self::INVALID, null];
        }
        $this->db->run('UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = ?', [$user['id']]);
        if (Passwords::needsRehash((string)$user['password_hash'])) {
            $this->db->run('UPDATE users SET password_hash = ? WHERE id = ?', [Passwords::hash($password), $user['id']]);
        }
        if ($user['status'] !== 'active') {
            return [self::INACTIVE, $user];
        }
        return [self::OK, $user];
    }

    private function registerFailure(array $user, string $ip): void
    {
        $count = (int)$user['failed_login_count'] + 1;
        if ($count >= $this->settings->int('login.user_fail_lock')) {
            $until = Clock::dbIn('+' . $this->settings->int('login.lock_minutes') . ' minutes');
            $this->db->run('UPDATE users SET failed_login_count = 0, locked_until = ? WHERE id = ?', [$until, $user['id']]);
            $this->audit->record('system', null, 'user.login_locked', 'user', (int)$user['id'], ['until' => $until], $ip);
        } else {
            $this->db->run('UPDATE users SET failed_login_count = ? WHERE id = ?', [$count, $user['id']]);
        }
    }
}
