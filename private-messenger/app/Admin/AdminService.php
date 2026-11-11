<?php
declare(strict_types=1);

namespace PM\Admin;

use PM\Audit\AuditLog;
use PM\Auth\RateLimiter;
use PM\Infra\Database;
use PM\Support\Clock;
use PM\Support\Crypto;
use PM\Support\Ip;
use PM\Support\Passwords;
use PM\Support\Random;
use PM\Support\Totp;
use PM\Support\UserAgent;
use PM\Support\UserError;

final class AdminService
{
    public const COOKIE = '__Host-asid';
    public const SESSION_HOURS = 8;
    public const IDLE_MINUTES = 30;
    private const FAIL_LOCK = 5;
    private const LOCK_MINUTES = 15;

    /**
     * Permission labels. There is deliberately no permission that grants access to message
     * or file content — such a capability does not exist in the system.
     */
    public const PERMISSIONS = [
        'dashboard.view' => 'مشاهده داشبورد',
        'users.view' => 'مشاهده کاربران',
        'users.create' => 'ایجاد کاربر',
        'users.edit' => 'ویرایش کاربر',
        'users.activate' => 'فعال‌سازی کاربر',
        'users.suspend' => 'تعلیق کاربر',
        'users.block' => 'مسدودسازی کاربر',
        'users.delete' => 'حذف کاربر',
        'users.sessions.revoke' => 'خروج اجباری نشست‌ها / بازنشانی رمز',
        'users.devices.revoke' => 'لغو دستگاه‌ها',
        'users.identity.reset' => 'بازنشانی هویت رمزنگاری',
        'users.retention.manage' => 'نگهداری دائمی فایل (فقط مدیرکل)',
        'settings.view' => 'مشاهده تنظیمات',
        'settings.edit' => 'ویرایش تنظیمات',
        'storage.view' => 'مشاهده فضا',
        'storage.cleanup.run' => 'اجرای پاک‌سازی',
        'audit.view' => 'مشاهده گزارش ممیزی',
        'admins.manage' => 'مدیریت مدیران (فقط مدیرکل)',
        'system.capabilities.view' => 'مشاهده قابلیت‌های میزبان',
        'reports.view' => 'مشاهده گزارش‌های آماری (غیرمحتوایی)',
        'backup.download' => 'دریافت نسخه پشتیبان دیتابیس (فقط مدیرکل)',
        'system.paths.manage' => 'تغییر آدرس‌های مخفی (فقط مدیرکل)',
        'conversations.view' => 'مشاهده فهرست گروه‌ها و کانال‌ها (بدون پیام)',
        'conversations.manage' => 'مسدودسازی گروه/کانال و حذف عضو',
        'services.manage' => 'فعال/غیرفعال کردن سرویس‌ها',
        'abuse.handle' => 'بررسی گزارش‌های تخلف',
        'appeals.handle' => 'بررسی درخواست‌های بازبینی',
        'support.manage' => 'تنظیم چت پشتیبانی',
    ];
    /** Permissions that only a super role may ever hold, regardless of role configuration. */
    public const SUPER_ONLY = ['users.retention.manage', 'admins.manage', 'backup.download', 'system.paths.manage'];

    public function __construct(
        private readonly Database $db,
        private readonly AuditLog $audit,
        private readonly RateLimiter $limiter,
        private readonly string $key,
    ) {
    }

    /** @return array{0:string, 1:?array} 'ok'|'invalid'|'totp'|'rate_limited', admin row */
    public function attemptLogin(string $username, string $password, string $totp, string $ip): array
    {
        if (!$this->limiter->hit('admin-login:ip:' . $ip, 20, 900)) {
            return ['rate_limited', null];
        }
        $username = mb_strtolower(trim($username));
        $a = $username === '' ? null : $this->db->one(
            'SELECT a.*, r.is_super, r.name AS role_name FROM admin_users a JOIN roles r ON r.id = a.role_id WHERE a.username = ?', [$username]);
        if ($a !== null && $a['locked_until'] !== null && strtotime($a['locked_until'] . ' UTC') > Clock::now()->getTimestamp()) {
            Passwords::verify($password, null);
            return ['invalid', null];
        }
        if (!Passwords::verify($password, $a['password_hash'] ?? null) || $a['status'] !== 'active') {
            if ($a !== null) {
                $this->fail($a, $ip, 'password');
            }
            return ['invalid', null];
        }
        if ((int)$a['totp_enabled'] === 1) {
            $secret = Crypto::open($this->key, (string)$a['totp_secret_enc']);
            $step = $secret === null ? null : Totp::verify($secret, $totp, Clock::now()->getTimestamp(),
                $a['totp_last_step'] === null ? null : (int)$a['totp_last_step']);
            if ($step === null) {
                $this->fail($a, $ip, 'totp');
                return [$totp === '' ? 'totp' : 'invalid', null];
            }
            $this->db->run('UPDATE admin_users SET totp_last_step = ? WHERE id = ?', [$step, $a['id']]);
        }
        if (Passwords::needsRehash((string)$a['password_hash'])) {
            $this->db->run('UPDATE admin_users SET password_hash = ? WHERE id = ?', [Passwords::hash($password), $a['id']]);
        }
        $this->db->run('UPDATE admin_users SET failed_attempts = 0, locked_until = NULL, last_login_at = ? WHERE id = ?', [Clock::db(), $a['id']]);
        $this->audit->record('admin', (int)$a['id'], 'admin.login', 'admin', (int)$a['id'], [], $ip);
        return ['ok', $a];
    }

    private function fail(array $a, string $ip, string $stage): void
    {
        $n = (int)$a['failed_attempts'] + 1;
        if ($n >= self::FAIL_LOCK) {
            $this->db->run('UPDATE admin_users SET failed_attempts = 0, locked_until = ? WHERE id = ?', [Clock::dbIn('+' . self::LOCK_MINUTES . ' minutes'), $a['id']]);
            $this->audit->record('system', null, 'admin.login_locked', 'admin', (int)$a['id'], ['stage' => $stage], $ip);
        } else {
            $this->db->run('UPDATE admin_users SET failed_attempts = ? WHERE id = ?', [$n, $a['id']]);
        }
    }

    public function createSession(int $adminId, string $ip, string $ua): string
    {
        $token = Random::token(32);
        $this->db->insert('INSERT INTO admin_sessions (admin_id, token_hash, ip, user_agent_summary, created_at, last_used_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$adminId, Crypto::sha256($token), Ip::toBinary($ip), UserAgent::summary($ua), Clock::db(), Clock::now()->format('Y-m-d H:i:s'),
             Clock::dbIn('+' . self::SESSION_HOURS . ' hours')]);
        return $token;
    }

    /** @return array<string, mixed>|null admin row + session_id + permissions */
    public function validateSession(?string $token): ?array
    {
        if ($token === null || strlen($token) < 20 || strlen($token) > 100) {
            return null;
        }
        $a = $this->db->one(
            'SELECT s.id AS session_id, s.last_used_at, a.*, r.is_super, r.name AS role_name
               FROM admin_sessions s JOIN admin_users a ON a.id = s.admin_id JOIN roles r ON r.id = a.role_id
              WHERE s.token_hash = ? AND s.revoked_at IS NULL AND s.expires_at > ? AND a.status = ?',
            [Crypto::sha256($token), Clock::db(), 'active']);
        if ($a === null) {
            return null;
        }
        $now = Clock::now()->getTimestamp();
        if (strtotime($a['last_used_at'] . ' UTC') < $now - self::IDLE_MINUTES * 60) {
            $this->revokeSession((int)$a['session_id']);
            return null;
        }
        if (strtotime($a['last_used_at'] . ' UTC') < $now - 60) {
            $this->db->run('UPDATE admin_sessions SET last_used_at = ? WHERE id = ?', [Clock::now()->format('Y-m-d H:i:s'), $a['session_id']]);
        }
        $a['permissions'] = $this->permissionsFor($a);
        return $a;
    }

    public function revokeSession(int $sessionId): void
    {
        $this->db->run('UPDATE admin_sessions SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [Clock::db(), $sessionId]);
    }

    /** @return list<string> */
    public function permissionsFor(array $admin): array
    {
        if ((int)$admin['is_super'] === 1) {
            return array_keys(self::PERMISSIONS);
        }
        $codes = array_column($this->db->all(
            'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?', [$admin['role_id']]), 'code');
        return array_values(array_diff($codes, self::SUPER_ONLY));
    }

    public static function can(array $admin, string $perm): bool
    {
        return in_array($perm, $admin['permissions'] ?? [], true);
    }

    public function roles(): array
    {
        return $this->db->all('SELECT id, name, is_super FROM roles ORDER BY is_super DESC, id');
    }

    /** @return list<array{id:int,name:string,is_super:int,admins:int,permissions:list<string>}> */
    public function rolesDetailed(): array
    {
        $roles = $this->db->all('SELECT r.id, r.name, r.is_super, (SELECT COUNT(*) FROM admin_users a WHERE a.role_id = r.id) AS admins FROM roles r ORDER BY r.is_super DESC, r.id');
        $perms = [];
        foreach ($this->db->all('SELECT rp.role_id, p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id') as $r) {
            $perms[(int)$r['role_id']][] = $r['code'];
        }
        foreach ($roles as &$r) {
            $r['permissions'] = (int)$r['is_super'] === 1 ? array_keys(self::PERMISSIONS) : array_values(array_diff($perms[(int)$r['id']] ?? [], self::SUPER_ONLY));
        }
        return $roles;
    }

    /** Creates ($roleId = null) or updates a non-super role. Super-only permissions can never be granted. */
    public function saveRole(int $actorId, ?int $roleId, string $name, array $codes, string $ip): int
    {
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 50) {
            throw new UserError('نام نقش باید بین ۲ تا ۵۰ کاراکتر باشد.');
        }
        $codes = array_values(array_intersect(array_unique(array_map('strval', $codes)), array_diff(array_keys(self::PERMISSIONS), self::SUPER_ONLY)));
        return $this->db->transaction(function () use ($actorId, $roleId, $name, $codes, $ip): int {
            if ($roleId !== null) {
                $role = $this->db->one('SELECT id, is_super FROM roles WHERE id = ? FOR UPDATE', [$roleId]);
                if ($role === null) {
                    throw new UserError('نقش پیدا نشد.');
                }
                if ((int)$role['is_super'] === 1) {
                    throw new UserError('نقش مدیرکل همه مجوزها را دارد و قابل ویرایش نیست.');
                }
            }
            $dup = $this->db->scalar('SELECT id FROM roles WHERE name = ? AND id <> ?', [$name, $roleId ?? 0]);
            if ($dup !== null) {
                throw new UserError('نقشی با این نام وجود دارد.');
            }
            if ($roleId === null) {
                $roleId = $this->db->insert('INSERT INTO roles (name, is_super) VALUES (?, 0)', [$name]);
            } else {
                $this->db->run('UPDATE roles SET name = ? WHERE id = ?', [$name, $roleId]);
                $this->db->run('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
            }
            foreach ($codes as $c) {
                $this->db->run('INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE code = ?', [$roleId, $c]);
            }
            $this->audit->record('admin', $actorId, 'role.save', 'role', $roleId, ['name' => $name, 'permissions' => $codes], $ip);
            return $roleId;
        });
    }

    public function deleteRole(int $actorId, int $roleId, string $ip): void
    {
        $role = $this->db->one('SELECT id, name, is_super FROM roles WHERE id = ?', [$roleId]);
        if ($role === null || (int)$role['is_super'] === 1) {
            throw new UserError('این نقش قابل حذف نیست.');
        }
        if ((int)$this->db->scalar('SELECT COUNT(*) FROM admin_users WHERE role_id = ?', [$roleId]) > 0) {
            throw new UserError('ابتدا نقش مدیرانی را که این نقش را دارند تغییر دهید.');
        }
        $this->db->run('DELETE FROM roles WHERE id = ?', [$roleId]);
        $this->audit->record('admin', $actorId, 'role.delete', 'role', $roleId, ['name' => $role['name']], $ip);
    }

    /** Changes another admin's role. The last active super admin can never lose the super role. */
    public function setAdminRole(int $actorId, int $id, int $roleId, string $ip): void
    {
        if ($id === $actorId) {
            throw new UserError('نقش حساب خودتان را نمی‌توانید تغییر دهید.');
        }
        $this->db->transaction(function () use ($actorId, $id, $roleId, $ip): void {
            $a = $this->db->one('SELECT a.id, a.role_id, r.is_super FROM admin_users a JOIN roles r ON r.id = a.role_id WHERE a.id = ? FOR UPDATE', [$id]);
            $role = $this->db->one('SELECT id, is_super FROM roles WHERE id = ?', [$roleId]);
            if ($a === null || $role === null) {
                throw new UserError('مدیر یا نقش پیدا نشد.');
            }
            if ((int)$a['is_super'] === 1 && (int)$role['is_super'] === 0
                && (int)$this->db->scalar("SELECT COUNT(*) FROM admin_users a JOIN roles r ON r.id = a.role_id WHERE r.is_super = 1 AND a.status = 'active' AND a.id <> ?", [$id]) === 0) {
                throw new UserError('حداقل یک مدیرکل فعال باید باقی بماند.');
            }
            $this->db->run('UPDATE admin_users SET role_id = ? WHERE id = ?', [$roleId, $id]);
            $this->db->run('UPDATE admin_sessions SET revoked_at = ? WHERE admin_id = ? AND revoked_at IS NULL', [Clock::db(), $id]);
            $this->audit->record('admin', $actorId, 'admin.role', 'admin', $id, ['from' => (int)$a['role_id'], 'to' => $roleId], $ip);
        });
    }

    public function listAdmins(): array
    {
        return $this->db->all('SELECT a.id, a.username, a.display_name, a.status, a.totp_enabled, a.last_login_at, a.role_id, r.name AS role_name, r.is_super
                                 FROM admin_users a JOIN roles r ON r.id = a.role_id ORDER BY a.id');
    }

    public function createAdmin(int $actorId, string $username, string $displayName, string $password, int $roleId, string $ip): int
    {
        $username = mb_strtolower(trim($username));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,31}$/', $username)) {
            throw new UserError('نام کاربری مدیر نامعتبر است (۳ تا ۳۲ کاراکتر لاتین).');
        }
        $err = Passwords::policyError($password, $username);
        if ($err !== null) {
            throw new UserError($err);
        }
        if ($this->db->scalar('SELECT id FROM roles WHERE id = ?', [$roleId]) === null) {
            throw new UserError('نقش نامعتبر است.');
        }
        if ($this->db->scalar('SELECT id FROM admin_users WHERE username = ?', [$username]) !== null) {
            throw new UserError('این نام کاربری قبلاً استفاده شده است.');
        }
        $id = $this->db->insert('INSERT INTO admin_users (username, display_name, password_hash, role_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$username, mb_substr(trim($displayName), 0, 100), Passwords::hash($password), $roleId, 'active', Clock::db()]);
        $this->audit->record($actorId === 0 ? 'installer' : 'admin', $actorId ?: null, 'admin.create', 'admin', $id, ['username' => $username, 'role_id' => $roleId], $ip);
        return $id;
    }

    public function setAdminStatus(int $actorId, int $id, string $status, string $ip): void
    {
        if ($actorId === $id) {
            throw new UserError('نمی‌توانید وضعیت حساب خودتان را تغییر دهید.');
        }
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new UserError('وضعیت نامعتبر است.');
        }
        $target = $this->db->one('SELECT a.id, r.is_super FROM admin_users a JOIN roles r ON r.id = a.role_id WHERE a.id = ?', [$id]);
        if ($target === null) {
            throw new UserError('مدیر یافت نشد.');
        }
        if ($status === 'disabled' && (int)$target['is_super'] === 1) {
            $others = (int)$this->db->scalar("SELECT COUNT(*) FROM admin_users a JOIN roles r ON r.id = a.role_id WHERE r.is_super = 1 AND a.status = 'active' AND a.id <> ?", [$id]);
            if ($others === 0) {
                throw new UserError('حداقل یک مدیرکل فعال باید باقی بماند.');
            }
        }
        $this->db->run('UPDATE admin_users SET status = ? WHERE id = ?', [$status, $id]);
        if ($status === 'disabled') {
            $this->db->run('UPDATE admin_sessions SET revoked_at = ? WHERE admin_id = ? AND revoked_at IS NULL', [Clock::db(), $id]);
        }
        $this->audit->record('admin', $actorId, 'admin.status', 'admin', $id, ['to' => $status], $ip);
    }

    /** Re-authentication for sensitive actions (backup download, changing secret paths). */
    public function confirmPassword(array $admin, string $password, string $ip): void
    {
        if (!$this->limiter->hit('admin:reauth:' . $admin['id'], 10, 900)) {
            throw new UserError('تلاش‌های زیادی انجام شده است. چند دقیقه بعد دوباره تلاش کنید.');
        }
        $hash = $this->db->scalar('SELECT password_hash FROM admin_users WHERE id = ?', [$admin['id']]);
        if (!Passwords::verify($password, is_string($hash) ? $hash : null)) {
            $this->audit->record('admin', (int)$admin['id'], 'admin.reauth_failed', 'admin', (int)$admin['id'], [], $ip);
            throw new UserError('رمز عبور نادرست است.');
        }
    }

    public function changePassword(array $admin, string $current, string $new, string $confirm, string $ip): void
    {
        $row = $this->db->one('SELECT password_hash, username FROM admin_users WHERE id = ?', [$admin['id']]);
        if ($row === null || !Passwords::verify($current, $row['password_hash'])) {
            throw new UserError('رمز عبور فعلی نادرست است.');
        }
        if ($new !== $confirm) {
            throw new UserError('تکرار رمز عبور جدید مطابقت ندارد.');
        }
        $err = Passwords::policyError($new, (string)$row['username']);
        if ($err !== null) {
            throw new UserError($err);
        }
        $this->db->run('UPDATE admin_users SET password_hash = ? WHERE id = ?', [Passwords::hash($new), $admin['id']]);
        $this->db->run('UPDATE admin_sessions SET revoked_at = ? WHERE admin_id = ? AND id <> ? AND revoked_at IS NULL',
            [Clock::db(), $admin['id'], $admin['session_id']]);
        $this->audit->record('admin', (int)$admin['id'], 'admin.password_change', 'admin', (int)$admin['id'], [], $ip);
    }

    /** Starts TOTP enrolment: stores a sealed secret (not yet enabled) and returns it for display. */
    public function startTotp(array $admin): string
    {
        $secret = Totp::newSecret();
        $this->db->run('UPDATE admin_users SET totp_secret_enc = ?, totp_enabled = 0, totp_last_step = NULL WHERE id = ?',
            [Crypto::seal($this->key, $secret), $admin['id']]);
        return $secret;
    }

    public function pendingTotpSecret(array $admin): ?string
    {
        $enc = $this->db->scalar('SELECT totp_secret_enc FROM admin_users WHERE id = ? AND totp_enabled = 0', [$admin['id']]);
        return $enc === null ? null : Crypto::open($this->key, (string)$enc);
    }

    public function confirmTotp(array $admin, string $code, string $ip): void
    {
        $secret = $this->pendingTotpSecret($admin);
        $step = $secret === null ? null : Totp::verify($secret, $code, Clock::now()->getTimestamp());
        if ($step === null) {
            throw new UserError('کد وارد‌شده درست نیست. ساعت دستگاه را بررسی و دوباره تلاش کنید.');
        }
        $this->db->run('UPDATE admin_users SET totp_enabled = 1, totp_last_step = ? WHERE id = ?', [$step, $admin['id']]);
        $this->audit->record('admin', (int)$admin['id'], 'admin.totp.enable', 'admin', (int)$admin['id'], [], $ip);
    }

    public function disableTotp(array $admin, string $password, string $ip): void
    {
        $row = $this->db->one('SELECT password_hash FROM admin_users WHERE id = ?', [$admin['id']]);
        if ($row === null || !Passwords::verify($password, $row['password_hash'])) {
            throw new UserError('رمز عبور نادرست است.');
        }
        $this->db->run('UPDATE admin_users SET totp_enabled = 0, totp_secret_enc = NULL, totp_last_step = NULL WHERE id = ?', [$admin['id']]);
        $this->audit->record('admin', (int)$admin['id'], 'admin.totp.disable', 'admin', (int)$admin['id'], [], $ip);
    }
}
