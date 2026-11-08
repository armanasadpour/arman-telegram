<?php
declare(strict_types=1);

namespace PM\Auth;

use PM\Infra\Database;
use PM\Support\Clock;
use PM\Support\Crypto;
use PM\Support\Ip;
use PM\Support\Random;
use PM\Support\Ulid;
use PM\Support\UserAgent;

final class UserSessions
{
    public const COOKIE = '__Host-sid';

    public function __construct(private readonly Database $db)
    {
    }

    /** @return string the raw session token for the cookie (only its SHA-256 is stored) */
    public function create(int $userId, string $ip, string $userAgent, int $days): string
    {
        $token = Random::token(32);
        $this->db->insert(
            'INSERT INTO user_sessions (public_id, user_id, token_hash, state, ip_prefix, user_agent_summary, created_at, last_used_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [Ulid::generate(), $userId, Crypto::sha256($token), 'device_pending', Ip::prefix($ip), UserAgent::summary($userAgent),
             Clock::db(), Clock::now()->format('Y-m-d H:i:s'), Clock::dbIn('+' . $days . ' days')]
        );
        $this->db->insert('INSERT INTO notifications (user_id, type, created_at) VALUES (?, ?, ?)', [$userId, 'new_login', Clock::db()]);
        return $token;
    }

    /** @return array<string, mixed>|null session + user row when valid */
    public function validate(?string $token): ?array
    {
        if ($token === null || strlen($token) < 20 || strlen($token) > 100) {
            return null;
        }
        $row = $this->db->one(
            'SELECT s.id AS session_id, s.public_id AS session_public_id, s.last_used_at, u.*
               FROM user_sessions s JOIN users u ON u.id = s.user_id
              WHERE s.token_hash = ? AND s.revoked_at IS NULL AND s.expires_at > ? AND u.status = ?',
            [Crypto::sha256($token), Clock::db(), 'active']
        );
        if ($row === null) {
            return null;
        }
        if (strtotime((string)$row['last_used_at'] . ' UTC') < Clock::now()->getTimestamp() - 300) {
            $this->db->run('UPDATE user_sessions SET last_used_at = ? WHERE id = ?', [Clock::now()->format('Y-m-d H:i:s'), $row['session_id']]);
            $this->db->run('UPDATE users SET last_seen_at = ? WHERE id = ?', [Clock::now()->format('Y-m-d H:i:00'), $row['id']]);
        }
        return $row;
    }

    public function listForUser(int $userId): array
    {
        return $this->db->all(
            'SELECT id, public_id, user_agent_summary, ip_prefix, created_at, last_used_at, expires_at
               FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ? ORDER BY last_used_at DESC',
            [$userId, Clock::db()]
        );
    }

    public function revokeByPublicId(int $userId, string $publicId, string $reason): bool
    {
        return $this->db->run(
            'UPDATE user_sessions SET revoked_at = ?, revoked_reason = ? WHERE user_id = ? AND public_id = ? AND revoked_at IS NULL',
            [Clock::db(), $reason, $userId, $publicId]
        )->rowCount() > 0;
    }

    public function revokeById(int $sessionId, string $reason): void
    {
        $this->db->run('UPDATE user_sessions SET revoked_at = ?, revoked_reason = ? WHERE id = ? AND revoked_at IS NULL', [Clock::db(), $reason, $sessionId]);
    }

    public function revokeAll(int $userId, string $reason, ?int $exceptSessionId = null): int
    {
        return $this->db->run(
            'UPDATE user_sessions SET revoked_at = ?, revoked_reason = ? WHERE user_id = ? AND revoked_at IS NULL AND id <> ?',
            [Clock::db(), $reason, $userId, $exceptSessionId ?? 0]
        )->rowCount();
    }
}
