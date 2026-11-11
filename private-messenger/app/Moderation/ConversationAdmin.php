<?php
declare(strict_types=1);

namespace PM\Moderation;

use PM\Audit\AuditLog;
use PM\Infra\Database;
use PM\Support\Clock;
use PM\Support\UserError;

/**
 * Groups and channels as the admin panel sees them: metadata only. Names, messages, photos and videos are
 * end-to-end encrypted on the members' devices — the server has no way to show them, and no page tries to.
 */
final class ConversationAdmin
{
    public function __construct(private readonly Database $db, private readonly AuditLog $audit)
    {
    }

    public function list(string $kind = 'all', string $member = '', string $status = 'all'): array
    {
        $w = ["c.type = 'group'"];
        $p = [];
        if ($kind === 'group' || $kind === 'channel') {
            $w[] = 'c.is_channel = ?';
            $p[] = $kind === 'channel' ? 1 : 0;
        }
        if ($status === 'banned') {
            $w[] = "c.status = 'banned'";
        }
        if ($member !== '') {
            $w[] = 'EXISTS (SELECT 1 FROM conversation_members m2 JOIN users u2 ON u2.id = m2.user_id WHERE m2.conversation_id = c.id AND m2.left_at IS NULL AND u2.username = ?)';
            $p[] = mb_strtolower($member);
        }
        return $this->db->all('SELECT c.id, c.public_id, c.is_channel, c.status, c.banned_until, c.created_at, c.last_activity_at, cu.username AS creator,
                                      (SELECT COUNT(*) FROM conversation_members m WHERE m.conversation_id = c.id AND m.left_at IS NULL) AS members,
                                      (SELECT COUNT(*) FROM messages x WHERE x.conversation_id = c.id) AS messages,
                                      (SELECT COUNT(*) FROM abuse_reports r WHERE r.target_type = \'conversation\' AND r.target_id = c.id AND r.status = \'open\') AS reports
                                 FROM conversations c LEFT JOIN users cu ON cu.id = c.created_by
                                WHERE ' . implode(' AND ', $w) . ' ORDER BY c.last_activity_at IS NULL, c.last_activity_at DESC, c.id DESC LIMIT 300', $p);
    }

    public function get(int $id): ?array
    {
        $c = $this->db->one("SELECT c.*, cu.username AS creator FROM conversations c LEFT JOIN users cu ON cu.id = c.created_by WHERE c.id = ? AND c.type = 'group'", [$id]);
        if ($c === null) {
            return null;
        }
        $c['members'] = $this->db->all('SELECT u.id, u.username, u.display_name, u.status, m.role, m.joined_at FROM conversation_members m JOIN users u ON u.id = m.user_id
                                         WHERE m.conversation_id = ? AND m.left_at IS NULL ORDER BY FIELD(m.role, \'owner\', \'admin\', \'member\'), u.username', [$id]);
        $c['stats'] = $this->db->one('SELECT COUNT(*) AS messages, MAX(created_at) AS last_message, COUNT(DISTINCT sender_user_id) AS senders FROM messages WHERE conversation_id = ?', [$id]);
        $c['files'] = $this->db->one("SELECT COUNT(*) AS n, COALESCE(SUM(a.size_bytes), 0) AS bytes FROM attachment_grants g JOIN attachments a ON a.id = g.attachment_id
                                       WHERE g.conversation_id = ? AND a.upload_status = 'complete'", [$id]);
        return $c;
    }

    public function ban(int $adminId, int $id, ?string $until, ?string $reason, string $ip): void
    {
        $this->db->run("UPDATE conversations SET status = 'banned', banned_until = ?, ban_reason = ? WHERE id = ? AND type = 'group'",
            [$until, $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null, $id]);
        $this->notifyMembers($id);
        $this->audit->record('admin', $adminId, 'conversation.ban', 'conversation', $id, ['until' => $until, 'reason' => $reason], $ip);
    }

    public function unban(int $adminId, int $id, string $ip): void
    {
        $this->db->run("UPDATE conversations SET status = 'active', banned_until = NULL, ban_reason = NULL WHERE id = ?", [$id]);
        $this->notifyMembers($id);
        $this->audit->record('admin', $adminId, 'conversation.unban', 'conversation', $id, [], $ip);
    }

    /** Removes a member on the server. Clients send only to roster ∩ server members, so they stop reaching this person. */
    public function removeMember(int $adminId, int $id, int $userId, string $ip): void
    {
        $role = $this->db->scalar('SELECT role FROM conversation_members WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL', [$id, $userId]);
        if ($role === null) {
            throw new UserError('عضو پیدا نشد.');
        }
        $this->db->run('UPDATE conversation_members SET left_at = ? WHERE conversation_id = ? AND user_id = ?', [Clock::db(), $id, $userId]);
        if ($role === 'owner') { // hand over, like a member leaving
            $next = $this->db->scalar("SELECT user_id FROM conversation_members WHERE conversation_id = ? AND left_at IS NULL ORDER BY role = 'admin' DESC, joined_at LIMIT 1", [$id]);
            if ($next !== null) {
                $this->db->run("UPDATE conversation_members SET role = 'owner' WHERE conversation_id = ? AND user_id = ?", [$id, $next]);
            }
        }
        $this->notifyMembers($id);
        $this->audit->record('admin', $adminId, 'conversation.remove_member', 'conversation', $id, ['user' => $userId], $ip);
    }

    private function notifyMembers(int $id): void
    {
        foreach ($this->db->all('SELECT user_id FROM conversation_members WHERE conversation_id = ?', [$id]) as $m) {
            $this->db->insert('INSERT INTO notifications (user_id, type, created_at) VALUES (?, ?, ?)', [$m['user_id'], 'group_changed', Clock::db()]);
        }
    }

    /** Lifts bans and account suspensions whose time is over (cron). */
    public static function expire(Database $db): int
    {
        $now = Clock::db();
        $n = $db->run("UPDATE conversations SET status = 'active', banned_until = NULL, ban_reason = NULL WHERE status = 'banned' AND banned_until IS NOT NULL AND banned_until <= ?", [$now])->rowCount();
        $n += $db->run("UPDATE users SET status = 'active', status_until = NULL, status_reason = NULL WHERE status IN ('suspended','blocked') AND status_until IS NOT NULL AND status_until <= ?", [$now])->rowCount();
        $db->run('DELETE FROM service_policies WHERE until_at IS NOT NULL AND until_at <= ?', [$now]);
        return $n;
    }
}
