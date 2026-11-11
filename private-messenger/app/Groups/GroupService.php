<?php
declare(strict_types=1);

namespace PM\Groups;

use PM\Infra\Database;
use PM\Support\Clock;
use PM\Support\Ulid;
use PM\Support\UserError;

/**
 * Group conversations. The server only knows WHO is a member and their role (needed for routing and
 * access control). The group's name and the authoritative member list ("roster") travel end-to-end
 * encrypted from a group admin; clients encrypt only for members announced in that roster, so a member
 * silently added by the server would receive nothing. Messages are encrypted pairwise (Olm) for every
 * member device: a removed member is simply no longer encrypted for and cannot read future messages.
 */
final class GroupService
{
    public const MAX_MEMBERS = 64;

    public function __construct(private readonly Database $db)
    {
    }

    private function userId(string $publicId): int
    {
        $id = $this->db->scalar("SELECT id FROM users WHERE public_id = ? AND status = 'active'", [$publicId]);
        if ($id === null) {
            throw new UserError('user_not_found');
        }
        return (int)$id;
    }

    /** @param list<string> $memberPublicIds */
    public function create(int $meId, array $memberPublicIds, bool $channel = false): string
    {
        $ids = [];
        foreach (array_unique(array_filter($memberPublicIds, 'is_string')) as $p) {
            $u = $this->userId($p);
            if ($u !== $meId) {
                $ids[] = $u;
            }
        }
        if ($ids === [] || count($ids) + 1 > self::MAX_MEMBERS) {
            throw new UserError('invalid_members');
        }
        (new \PM\Moderation\PolicyService($this->db))->assertAllowed($channel ? 'create_channel' : 'create_group', $meId);
        return $this->db->transaction(function () use ($meId, $ids, $channel): string {
            $now = Clock::db();
            $pub = Ulid::generate();
            $cid = $this->db->insert('INSERT INTO conversations (public_id, type, is_channel, created_by, created_at, last_activity_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$pub, 'group', $channel ? 1 : 0, $meId, $now, Clock::now()->format('Y-m-d H:i:00')]);
            $this->db->run('INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)', [$cid, $meId, 'owner', $now]);
            foreach ($ids as $u) {
                $this->db->run('INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)', [$cid, $u, 'member', $now]);
                $this->notify($u, $meId);
            }
            return $pub;
        });
    }

    /** @return array{id:int, role:string} */
    private function group(int $meId, string $groupPublicId): array
    {
        $g = $this->db->one("SELECT c.id, cm.role FROM conversations c JOIN conversation_members cm ON cm.conversation_id = c.id
                              WHERE c.public_id = ? AND c.type IN ('group', 'support') AND cm.user_id = ? AND cm.left_at IS NULL", [$groupPublicId, $meId]);
        if ($g === null) {
            throw new UserError('group_not_found');
        }
        return ['id' => (int)$g['id'], 'role' => $g['role']];
    }

    public function members(int $meId, string $groupPublicId): array
    {
        $g = $this->group($meId, $groupPublicId);
        return $this->db->all("SELECT u.public_id AS id, u.display_name, u.username, cm.role FROM conversation_members cm JOIN users u ON u.id = cm.user_id
                                WHERE cm.conversation_id = ? AND cm.left_at IS NULL AND u.status <> 'deleted' ORDER BY FIELD(cm.role, 'owner', 'admin', 'member'), u.display_name", [$g['id']]);
    }

    public function add(int $meId, string $groupPublicId, string $userPublicId): void
    {
        $g = $this->group($meId, $groupPublicId);
        if (!in_array($g['role'], ['owner', 'admin'], true)) {
            throw new UserError('not_group_admin');
        }
        $u = $this->userId($userPublicId);
        if ((int)$this->db->scalar('SELECT COUNT(*) FROM conversation_members WHERE conversation_id = ? AND left_at IS NULL', [$g['id']]) >= self::MAX_MEMBERS) {
            throw new UserError('group_full');
        }
        $this->db->run('INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE left_at = NULL, role = \'member\', joined_at = VALUES(joined_at)', [$g['id'], $u, 'member', Clock::db()]);
        $this->notifyAll($g['id'], $meId);
    }

    public function remove(int $meId, string $groupPublicId, string $userPublicId): void
    {
        $g = $this->group($meId, $groupPublicId);
        $u = $this->userId($userPublicId);
        $target = $this->db->scalar('SELECT role FROM conversation_members WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL', [$g['id'], $u]);
        if ($target === null) {
            throw new UserError('user_not_found');
        }
        $self = $u === $meId;
        if (!$self && (!in_array($g['role'], ['owner', 'admin'], true) || $target === 'owner' || ($target === 'admin' && $g['role'] !== 'owner'))) {
            throw new UserError('not_group_admin');
        }
        $this->db->transaction(function () use ($g, $u, $meId, $target): void {
            $this->db->run('UPDATE conversation_members SET left_at = ? WHERE conversation_id = ? AND user_id = ?', [Clock::db(), $g['id'], $u]);
            if ($target === 'owner') { // owner leaves: oldest admin, else oldest member, takes over
                $next = $this->db->scalar("SELECT user_id FROM conversation_members WHERE conversation_id = ? AND left_at IS NULL
                                            ORDER BY FIELD(role, 'admin', 'member'), joined_at LIMIT 1", [$g['id']]);
                if ($next !== null) {
                    $this->db->run("UPDATE conversation_members SET role = 'owner' WHERE conversation_id = ? AND user_id = ?", [$g['id'], $next]);
                }
            }
            $this->notifyAll($g['id'], $meId);
            $this->notify($u, $meId);
        });
    }

    public function setRole(int $meId, string $groupPublicId, string $userPublicId, string $role): void
    {
        $g = $this->group($meId, $groupPublicId);
        if ($g['role'] !== 'owner' || !in_array($role, ['admin', 'member'], true)) {
            throw new UserError('not_group_owner');
        }
        $u = $this->userId($userPublicId);
        if ($u === $meId) {
            throw new UserError('invalid_role');
        }
        $n = $this->db->run("UPDATE conversation_members SET role = ? WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL AND role <> 'owner'",
            [$role, $g['id'], $u])->rowCount();
        if ($n === 0 && $this->db->scalar('SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL', [$g['id'], $u]) === null) {
            throw new UserError('user_not_found');
        }
        $this->notifyAll($g['id'], $meId);
    }

    private function notifyAll(int $convId, int $exceptId): void
    {
        foreach ($this->db->all('SELECT user_id FROM conversation_members WHERE conversation_id = ? AND left_at IS NULL', [$convId]) as $r) {
            if ((int)$r['user_id'] !== $exceptId) {
                $this->notify((int)$r['user_id'], $exceptId);
            }
        }
    }

    private function notify(int $userId, int $actorId): void
    {
        $this->db->insert('INSERT INTO notifications (user_id, type, subject_user_id, created_at) VALUES (?, ?, ?, ?)', [$userId, 'group_changed', $actorId, Clock::db()]);
    }
}
