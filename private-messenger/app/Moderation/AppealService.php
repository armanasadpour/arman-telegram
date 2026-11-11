<?php
declare(strict_types=1);

namespace PM\Moderation;

use PM\Audit\AuditLog;
use PM\Infra\Database;
use PM\Support\Clock;
use PM\Support\UserError;
use PM\Users\UserService;

/** «Request a review» from a suspended / banned account, and the admin's answer. */
final class AppealService
{
    public function __construct(private readonly Database $db, private readonly AuditLog $audit)
    {
    }

    /** Short-lived proof that the person knew the account's password (no session is opened for an inactive account). */
    public static function token(string $appKey, int $userId): string
    {
        $exp = time() + 1800;
        return $userId . '.' . $exp . '.' . hash_hmac('sha256', "appeal|$userId|$exp", $appKey);
    }

    public static function verifyToken(string $appKey, string $token): ?int
    {
        if (!preg_match('/^(\d+)\.(\d+)\.([a-f0-9]{64})$/', $token, $m) || (int)$m[2] < time()) {
            return null;
        }
        return hash_equals(hash_hmac('sha256', "appeal|{$m[1]}|{$m[2]}", $appKey), $m[3]) ? (int)$m[1] : null;
    }

    public function latest(int $userId): ?array
    {
        return $this->db->one('SELECT * FROM user_appeals WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId]);
    }

    public function create(int $userId, string $message): void
    {
        $message = trim($message);
        if (mb_strlen($message) < 10 || mb_strlen($message) > 2000) {
            throw new UserError('متن درخواست باید بین ۱۰ تا ۲۰۰۰ کاراکتر باشد.');
        }
        if ($this->db->scalar("SELECT 1 FROM user_appeals WHERE user_id = ? AND status = 'open'", [$userId]) !== null) {
            throw new UserError('یک درخواست بازبینی در حال بررسی دارید.');
        }
        if ((int)$this->db->scalar('SELECT COUNT(*) FROM user_appeals WHERE user_id = ? AND created_at > ?', [$userId, Clock::now()->modify('-1 day')->format('Y-m-d H:i:s')]) >= 3) {
            throw new UserError('در هر روز حداکثر ۳ درخواست بازبینی می‌توانید بفرستید.');
        }
        $this->db->insert('INSERT INTO user_appeals (user_id, message, created_at) VALUES (?, ?, ?)', [$userId, $message, Clock::db()]);
        $this->audit->record('user', $userId, 'appeal.create', 'user', $userId, [], null);
    }

    public function list(string $status = 'open'): array
    {
        return $this->db->all("SELECT a.*, u.username, u.display_name, u.status AS user_status, u.status_until, u.status_reason, ad.username AS admin
                                 FROM user_appeals a JOIN users u ON u.id = a.user_id LEFT JOIN admin_users ad ON ad.id = a.handled_by
                                WHERE (? = 'all' OR a.status = ?) ORDER BY a.status = 'open' DESC, a.id DESC LIMIT 200", [$status, $status]);
    }

    public function handle(int $adminId, int $id, bool $accept, string $reply, UserService $users, string $ip): void
    {
        $a = $this->db->one("SELECT * FROM user_appeals WHERE id = ? AND status = 'open'", [$id]);
        if ($a === null) {
            throw new UserError('درخواست پیدا نشد یا قبلاً بررسی شده است.');
        }
        $this->db->run('UPDATE user_appeals SET status = ?, admin_reply = ?, handled_by = ?, handled_at = ? WHERE id = ?',
            [$accept ? 'accepted' : 'rejected', mb_substr(trim($reply), 0, 1000) ?: null, $adminId, Clock::db(), $id]);
        if ($accept) {
            $users->setStatus($adminId, (int)$a['user_id'], 'active', $ip);
        }
        $this->audit->record('admin', $adminId, $accept ? 'appeal.accept' : 'appeal.reject', 'user', (int)$a['user_id'], [], $ip);
    }
}
