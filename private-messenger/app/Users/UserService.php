<?php
declare(strict_types=1);

namespace PM\Users;

use PM\Audit\AuditLog;
use PM\Auth\UserSessions;
use PM\Infra\Database;
use PM\Support\Clock;
use PM\Support\Passwords;
use PM\Support\Phone;
use PM\Support\Random;
use PM\Support\Ulid;
use PM\Support\UserError;

final class UserService
{
    public const STATUSES = [
        'pending' => 'در انتظار فعال‌سازی',
        'active' => 'فعال',
        'suspended' => 'معلق',
        'blocked' => 'مسدود',
        'deleted' => 'حذف‌شده',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly AuditLog $audit,
        private readonly UserSessions $sessions,
    ) {
    }

    public static function normalizeUsername(string $u): string
    {
        $u = mb_strtolower(trim($u));
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{2,31}$/', $u)) {
            throw new UserError('نام کاربری باید ۳ تا ۳۲ کاراکتر از حروف کوچک انگلیسی، رقم، نقطه، خط تیره یا زیرخط باشد.');
        }
        return $u;
    }

    private static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            throw new UserError('نام نمایشی باید بین ۱ تا ۱۰۰ کاراکتر باشد.');
        }
        return $name;
    }

    private static function cleanPhone(string $phone): ?string
    {
        if (trim($phone) === '') {
            return null;
        }
        $p = Phone::normalize($phone);
        if ($p === null) {
            throw new UserError('شماره موبایل معتبر نیست.');
        }
        return $p;
    }

    /** @return array{0:int, 1:string} new user id and the one-time temporary password */
    public function create(int $adminId, string $username, string $displayName, string $phone, bool $activate, string $ip): array
    {
        $username = self::normalizeUsername($username);
        $displayName = self::cleanName($displayName);
        $phoneE164 = self::cleanPhone($phone);
        $this->assertUnique($username, $phoneE164, null);
        $temp = Random::readablePassword(16);
        $now = Clock::db();
        $id = $this->db->insert(
            'INSERT INTO users (public_id, username, phone_e164, password_hash, must_change_password, display_name, status,
                                created_by_admin_id, created_at, activated_at, updated_at)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)',
            [Ulid::generate(), $username, $phoneE164, Passwords::hash($temp), $displayName, $activate ? 'active' : 'pending',
             $adminId, $now, $activate ? $now : null, $now]
        );
        $this->audit->record('admin', $adminId, 'user.create', 'user', $id,
            ['username' => $username, 'status' => $activate ? 'active' : 'pending', 'has_phone' => $phoneE164 !== null], $ip);
        return [$id, $temp];
    }

    public function update(int $adminId, int $id, string $username, string $displayName, string $phone, string $ip): void
    {
        $u = $this->getOrFail($id);
        $username = self::normalizeUsername($username);
        $displayName = self::cleanName($displayName);
        $phoneE164 = self::cleanPhone($phone);
        $this->assertUnique($username, $phoneE164, $id);
        $this->db->run('UPDATE users SET username = ?, display_name = ?, phone_e164 = ?, updated_at = ? WHERE id = ?',
            [$username, $displayName, $phoneE164, Clock::db(), $id]);
        $changed = [];
        foreach (['username' => $username, 'display_name' => $displayName, 'phone_e164' => $phoneE164] as $k => $v) {
            if ($u[$k] !== $v) {
                $changed[] = $k;
            }
        }
        if ($changed !== []) {
            // Field names only; values (e.g. phone numbers) are not copied into the audit trail.
            $this->audit->record('admin', $adminId, 'user.update', 'user', $id, ['fields' => $changed], $ip);
        }
    }

    public function setStatus(int $adminId, int $id, string $status, string $ip): void
    {
        $u = $this->getOrFail($id);
        if (!isset(self::STATUSES[$status]) || $u['status'] === 'deleted') {
            throw new UserError('تغییر وضعیت نامعتبر است.');
        }
        if ($u['status'] === $status) {
            return;
        }
        $this->db->transaction(function () use ($id, $status, $u): void {
            $now = Clock::db();
            if ($status === 'deleted') {
                // Free the username/phone for reuse; keep the row for referential integrity.
                $this->db->run('UPDATE users SET status = ?, deleted_at = ?, updated_at = ?, username = ?, phone_e164 = NULL, password_hash = NULL WHERE id = ?',
                    ['deleted', $now, $now, 'deleted-' . $id . '-' . bin2hex(random_bytes(3)), $id]);
            } else {
                $this->db->run('UPDATE users SET status = ?, updated_at = ?, activated_at = COALESCE(activated_at, IF(? = \'active\', ?, NULL)) WHERE id = ?',
                    [$status, $now, $status, $now, $id]);
            }
            if ($status !== 'active') {
                $this->sessions->revokeAll($id, 'account_status');
            }
            $this->db->insert('INSERT INTO notifications (user_id, type, created_at) VALUES (?, ?, ?)', [$id, 'account_status', $now]);
        });
        $this->audit->record('admin', $adminId, 'user.status', 'user', $id, ['from' => $u['status'], 'to' => $status], $ip);
    }

    /** @return string one-time temporary password */
    public function resetPassword(int $adminId, int $id, string $ip): string
    {
        $u = $this->getOrFail($id);
        if ($u['status'] === 'deleted') {
            throw new UserError('حساب حذف شده است.');
        }
        $temp = Random::readablePassword(16);
        $this->db->run('UPDATE users SET password_hash = ?, must_change_password = 1, failed_login_count = 0, locked_until = NULL, updated_at = ? WHERE id = ?',
            [Passwords::hash($temp), Clock::db(), $id]);
        $this->sessions->revokeAll($id, 'admin_revoke');
        $this->audit->record('admin', $adminId, 'user.password_reset', 'user', $id, [], $ip);
        return $temp;
    }

    public function unlock(int $adminId, int $id, string $ip): void
    {
        $this->getOrFail($id);
        $this->db->run('UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = ?', [$id]);
        $this->audit->record('admin', $adminId, 'user.unlock', 'user', $id, [], $ip);
    }

    public function changeOwnPassword(array $user, string $current, string $new, string $confirm, int $keepSessionId): void
    {
        $fresh = $this->getOrFail((int)$user['id']);
        if (!Passwords::verify($current, $fresh['password_hash'])) {
            throw new UserError('رمز عبور فعلی نادرست است.');
        }
        if ($new !== $confirm) {
            throw new UserError('تکرار رمز عبور جدید مطابقت ندارد.');
        }
        if (hash_equals($current, $new)) {
            throw new UserError('رمز عبور جدید باید با رمز فعلی متفاوت باشد.');
        }
        $err = Passwords::policyError($new, (string)$fresh['username']);
        if ($err !== null) {
            throw new UserError($err);
        }
        $this->db->run('UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = ?, updated_at = ? WHERE id = ?',
            [Passwords::hash($new), Clock::db(), Clock::db(), $fresh['id']]);
        $this->sessions->revokeAll((int)$fresh['id'], 'user_revoke', $keepSessionId);
    }

    public function setPermanentRetention(int $adminId, int $id, bool $on, string $ip): void
    {
        $u = $this->getOrFail($id);
        if ((bool)$u['permanent_file_retention'] === $on) {
            return;
        }
        $now = Clock::db();
        $this->db->transaction(function () use ($id, $on, $adminId, $now): void {
            $this->db->run('UPDATE users SET permanent_file_retention = ?, retention_changed_by = ?, retention_changed_at = ?, updated_at = ? WHERE id = ?',
                [$on ? 1 : 0, $adminId, $now, $now, $id]);
            if ($on) {
                // Existing, not-yet-expired files become permanent. Already-expired files are not revived.
                $this->db->run("UPDATE attachments SET retention_permanent = 1, expires_at = NULL, retention_set_by = ?, retention_set_at = ?
                                 WHERE uploader_user_id = ? AND upload_status = 'complete' AND (expires_at IS NULL OR expires_at > ?)",
                    [$adminId, $now, $id, $now]);
            } else {
                // Revocation starts a fresh 7-day window from now (see docs/08).
                $this->db->run("UPDATE attachments SET retention_permanent = 0, expires_at = ?, retention_set_by = ?, retention_set_at = ?
                                 WHERE uploader_user_id = ? AND retention_permanent = 1 AND upload_status = 'complete'",
                    [Clock::dbIn('+7 days'), $adminId, $now, $id]);
            }
            $this->db->insert('INSERT INTO notifications (user_id, type, created_at) VALUES (?, ?, ?)', [$id, 'retention_changed', $now]);
        });
        $this->audit->record('admin', $adminId, $on ? 'user.retention.enable' : 'user.retention.disable', 'user', $id, [], $ip);
    }

    public function get(int $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function getOrFail(int $id): array
    {
        $u = $this->get($id);
        if ($u === null) {
            throw new UserError('کاربر یافت نشد.');
        }
        return $u;
    }

    public function findActiveByPhone(string $e164): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE phone_e164 = ? AND status = ?', [$e164, 'active']);
    }

    public function search(string $q, string $status, int $limit = 100): array
    {
        $sql = 'SELECT u.id, u.username, u.display_name, u.phone_e164, u.status, u.last_seen_at, u.created_at, u.locked_until,
                       u.permanent_file_retention,
                       (SELECT COUNT(*) FROM user_sessions s WHERE s.user_id = u.id AND s.revoked_at IS NULL AND s.expires_at > ?) AS sessions
                  FROM users u WHERE 1 = 1';
        $p = [Clock::db()];
        if ($status !== '' && isset(self::STATUSES[$status])) {
            $sql .= ' AND u.status = ?';
            $p[] = $status;
        } else {
            $sql .= " AND u.status <> 'deleted'";
        }
        if ($q !== '') {
            $sql .= ' AND (u.username LIKE ? OR u.display_name LIKE ? OR u.phone_e164 LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($p, $like, $like, $like);
        }
        $sql .= ' ORDER BY u.id DESC LIMIT ' . max(1, min(500, $limit));
        return $this->db->all($sql, $p);
    }

    private function assertUnique(string $username, ?string $phone, ?int $exceptId): void
    {
        if ($this->db->scalar('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $exceptId ?? 0]) !== null) {
            throw new UserError('این نام کاربری قبلاً استفاده شده است.');
        }
        if ($phone !== null && $this->db->scalar('SELECT id FROM users WHERE phone_e164 = ? AND id <> ?', [$phone, $exceptId ?? 0]) !== null) {
            throw new UserError('این شماره موبایل برای کاربر دیگری ثبت شده است.');
        }
    }
}
