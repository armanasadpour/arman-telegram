<?php
declare(strict_types=1);

namespace PM\Users;

use PM\Infra\Database;
use PM\Support\Clock;
use PM\Support\UserError;

/**
 * Profile photos as opaque ciphertext. The photo is encrypted on the owner's device with a random key that is
 * sent to contacts only inside end-to-end encrypted messages, so the server (and its admins) never see it.
 */
final class AvatarService
{
    public const MAX_BYTES = 600 * 1024;

    public function __construct(private readonly Database $db)
    {
    }

    public function put(int $userId, mixed $b64): int
    {
        $ct = is_string($b64) && strlen($b64) <= self::MAX_BYTES * 2 ? base64_decode($b64, true) : false;
        if ($ct === false || strlen($ct) < 29 || strlen($ct) > self::MAX_BYTES) { // iv(12) + tag(16) + data
            throw new UserError('invalid_avatar');
        }
        return $this->db->transaction(function () use ($userId, $ct): int {
            $v = (int)$this->db->scalar('SELECT version FROM user_avatars WHERE user_id = ? FOR UPDATE', [$userId]);
            $v = max($v, (int)$this->db->scalar("SELECT CAST(setting_value AS UNSIGNED) FROM system_settings WHERE setting_key = ?", ['avatar.v.' . $userId])) + 1;
            $this->db->run('INSERT INTO user_avatars (user_id, version, ciphertext, updated_at) VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE version = VALUES(version), ciphertext = VALUES(ciphertext), updated_at = VALUES(updated_at)', [$userId, $v, $ct, Clock::db()]);
            return $v;
        });
    }

    public function remove(int $userId): void
    {
        $v = (int)$this->db->scalar('SELECT version FROM user_avatars WHERE user_id = ?', [$userId]);
        if ($v > 0) { // remember the version so a later photo never reuses an old number
            $this->db->run('INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                ['avatar.v.' . $userId, (string)$v, Clock::db()]);
        }
        $this->db->run('DELETE FROM user_avatars WHERE user_id = ?', [$userId]);
    }

    /** Only people who share a conversation with the owner, or follow them, may fetch the (encrypted) photo. */
    public function get(int $meId, string $ownerPublicId): ?array
    {
        $owner = $this->db->scalar('SELECT id FROM users WHERE public_id = ?', [$ownerPublicId]);
        if ($owner === null) {
            return null;
        }
        $owner = (int)$owner;
        if ($owner !== $meId
            && $this->db->scalar('SELECT 1 FROM conversation_members a JOIN conversation_members b ON b.conversation_id = a.conversation_id
                                  WHERE a.user_id = ? AND b.user_id = ? AND a.left_at IS NULL AND b.left_at IS NULL LIMIT 1', [$meId, $owner]) === null
            && $this->db->scalar('SELECT 1 FROM user_contacts WHERE user_id = ? AND contact_user_id = ?', [$meId, $owner]) === null) {
            return null;
        }
        $r = $this->db->one('SELECT version, ciphertext FROM user_avatars WHERE user_id = ?', [$owner]);
        return $r === null ? null : ['version' => (int)$r['version'], 'ciphertext' => base64_encode($r['ciphertext'])];
    }
}
