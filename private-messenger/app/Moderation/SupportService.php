<?php
declare(strict_types=1);

namespace PM\Moderation;

use PM\Infra\Database;
use PM\Settings\Settings;
use PM\Support\Clock;
use PM\Support\Ulid;
use PM\Support\UserError;

/**
 * «Support» chat pinned at the top of every user's list. Each user gets one conversation of type 'support'
 * whose members are the user and the support agents chosen by the super admin. Messages stay end-to-end
 * encrypted — for the agents' devices (that is who the user is writing to); the server still cannot read them.
 * The user's app shows every agent reply under the support name, not the agent's own name.
 */
final class SupportService
{
    public const MAX_AGENTS = 10;

    public function __construct(private readonly Database $db, private readonly Settings $settings)
    {
    }

    public function enabled(): bool
    {
        return (bool)$this->settings->get('support.enabled') && $this->agents() !== [];
    }

    public function name(): string
    {
        $n = trim((string)$this->settings->get('support.name'));
        return $n !== '' ? $n : 'پشتیبانی ' . (string)$this->settings->get('app.internal_name');
    }

    /** @return list<int> active agent user ids */
    public function agents(): array
    {
        $ids = array_values(array_filter(array_map('intval', (array)($this->settings->get('support.agents') ?: [])), fn ($x) => $x > 0));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        return array_map('intval', array_column($this->db->all("SELECT id FROM users WHERE status = 'active' AND id IN ($in)", $ids), 'id'));
    }

    public function isAgent(int $userId): bool
    {
        return in_array($userId, $this->agents(), true);
    }

    /** Creates (once) the support conversation of a user and keeps its agent list current. */
    public function ensureFor(int $userId): ?int
    {
        if (!$this->enabled() || $this->isAgent($userId)) {
            return null;
        }
        $id = $this->db->scalar("SELECT id FROM conversations WHERE type = 'support' AND created_by = ?", [$userId]);
        if ($id === null) {
            $now = Clock::db();
            $id = $this->db->insert('INSERT INTO conversations (public_id, type, created_by, created_at) VALUES (?, ?, ?, ?)', [Ulid::generate(), 'support', $userId, $now]);
            $this->db->run('INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)', [$id, $userId, 'member', $now]);
        }
        $this->sync((int)$id);
        return (int)$id;
    }

    /** Members = the owner + the current agents (agents removed by the admin leave; new ones join). */
    public function sync(int $convId, ?array $agents = null): void
    {
        $agents ??= $this->agents();
        $owner = (int)$this->db->scalar('SELECT created_by FROM conversations WHERE id = ?', [$convId]);
        $now = Clock::db();
        foreach ($agents as $a) {
            if ($a !== $owner) {
                $this->db->run("INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (?, ?, 'admin', ?)
                                ON DUPLICATE KEY UPDATE left_at = NULL", [$convId, $a, $now]);
            }
        }
        $keep = array_merge([$owner], $agents);
        $in = implode(',', array_fill(0, count($keep), '?'));
        $this->db->run("UPDATE conversation_members SET left_at = ? WHERE conversation_id = ? AND left_at IS NULL AND user_id NOT IN ($in)", [$now, $convId, ...$keep]);
    }

    public function syncAll(): int
    {
        $agents = $this->agents();
        $n = 0;
        foreach ($this->db->all("SELECT id FROM conversations WHERE type = 'support'") as $c) {
            $this->sync((int)$c['id'], $agents);
            $n++;
        }
        return $n;
    }

    /** @param list<string> $usernames */
    public function setAgents(array $usernames): array
    {
        $ids = [];
        foreach (array_unique(array_filter(array_map(fn ($u) => mb_strtolower(trim((string)$u)), $usernames))) as $u) {
            $id = $this->db->scalar("SELECT id FROM users WHERE username = ? AND status = 'active'", [$u]);
            if ($id === null) {
                throw new UserError('کاربر فعال «' . $u . '» پیدا نشد.');
            }
            $ids[] = (int)$id;
        }
        if (count($ids) > self::MAX_AGENTS) {
            throw new UserError('حداکثر ' . self::MAX_AGENTS . ' پشتیبان.');
        }
        $this->settings->set('support.agents', $ids);
        // an agent does not need a support chat of their own
        if ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $this->db->run("UPDATE conversation_members m JOIN conversations c ON c.id = m.conversation_id SET m.left_at = ?
                             WHERE c.type = 'support' AND c.created_by IN ($in) AND m.left_at IS NULL", [Clock::db(), ...$ids]);
        }
        $this->syncAll();
        return $ids;
    }

    /** @return list<array<string,mixed>> agents with whether they can receive (an active device = keys published) */
    public function agentList(): array
    {
        $ids = array_values(array_filter(array_map('intval', (array)($this->settings->get('support.agents') ?: []))));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        return $this->db->all("SELECT u.id, u.username, u.display_name, u.status,
                                      (SELECT COUNT(*) FROM devices d WHERE d.user_id = u.id AND d.status = 'active') AS devices
                                 FROM users u WHERE u.id IN ($in) ORDER BY u.username", $ids);
    }

    public function stats(): array
    {
        return $this->db->one("SELECT COUNT(DISTINCT c.id) AS chats, COUNT(m.id) AS messages, MAX(m.created_at) AS last
                                 FROM conversations c LEFT JOIN messages m ON m.conversation_id = c.id WHERE c.type = 'support'") ?? [];
    }
}
