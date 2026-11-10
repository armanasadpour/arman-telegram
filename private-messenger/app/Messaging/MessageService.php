<?php
declare(strict_types=1);

namespace PM\Messaging;

use PM\Infra\Database;
use PM\Support\B64;
use PM\Support\Clock;
use PM\Support\Ulid;
use PM\Support\UserError;

/**
 * Delivery service for end-to-end encrypted messages. The server only routes opaque ciphertext:
 * one envelope per recipient device. It cannot tell a text from a reaction or an edit, and it never
 * receives plaintext. Envelopes are deleted as soon as the recipient device acknowledges them.
 */
final class MessageService
{
    public const PROTOCOL_VERSION = 1;
    public const MAX_ENVELOPE_BYTES = 65536;
    public const UNDELIVERED_TTL_DAYS = 30;

    public function __construct(private readonly Database $db)
    {
    }

    private const USER_COLS = "u.public_id AS id, u.display_name, u.username,
        EXISTS(SELECT 1 FROM user_master_keys k WHERE k.user_id = u.id AND k.superseded_at IS NULL) AS has_identity";

    /**
     * Finds users by at least 3 characters of their username (prefix) or display name. There is deliberately
     * no "list everyone" endpoint: members are found by searching, then followed (added to contacts).
     * @return list<array<string, mixed>>
     */
    public function searchUsers(int $meId, string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 3 || mb_strlen($q) > 64) {
            throw new UserError('query_too_short');
        }
        $like = addcslashes($q, '%_\\');
        return $this->db->all('SELECT ' . self::USER_COLS . ', EXISTS(SELECT 1 FROM user_contacts c WHERE c.user_id = ? AND c.contact_user_id = u.id) AS is_contact
                                 FROM users u WHERE u.status = \'active\' AND u.id <> ? AND (u.username LIKE ? OR u.display_name LIKE ?)
                                ORDER BY (u.username = ?) DESC, u.display_name LIMIT 10', [$meId, $meId, mb_strtolower($like) . '%', '%' . $like . '%', mb_strtolower($q)]);
    }

    public function contacts(int $meId): array
    {
        return $this->db->all('SELECT ' . self::USER_COLS . ', 1 AS is_contact FROM user_contacts c JOIN users u ON u.id = c.contact_user_id
                                WHERE c.user_id = ? AND u.status = \'active\' ORDER BY u.display_name', [$meId]);
    }

    public function follow(int $meId, string $userPublicId, bool $on): void
    {
        $u = $this->db->one("SELECT id FROM users WHERE public_id = ? AND status = 'active'", [$userPublicId]);
        if ($u === null || (int)$u['id'] === $meId) {
            throw new UserError('user_not_found');
        }
        if ($on) {
            $this->db->run('INSERT IGNORE INTO user_contacts (user_id, contact_user_id, created_at) VALUES (?, ?, ?)', [$meId, $u['id'], Clock::db()]);
        } else {
            $this->db->run('DELETE FROM user_contacts WHERE user_id = ? AND contact_user_id = ?', [$meId, $u['id']]);
        }
    }

    public function conversations(int $meId): array
    {
        return $this->db->all("SELECT c.public_id AS id, c.type, c.is_channel, c.last_activity_at, cm.archived, cm.muted_until, cm.role,
                                      p.public_id AS peer_id, p.display_name AS peer_name, p.username AS peer_username, p.status AS peer_status,
                                      (SELECT COUNT(*) FROM conversation_members x WHERE x.conversation_id = c.id AND x.left_at IS NULL) AS member_count
                                 FROM conversation_members cm
                                 JOIN conversations c ON c.id = cm.conversation_id
                                 LEFT JOIN conversation_members pm ON c.type = 'direct' AND pm.conversation_id = c.id AND pm.user_id <> cm.user_id
                                 LEFT JOIN users p ON p.id = pm.user_id
                                WHERE cm.user_id = ? AND cm.left_at IS NULL
                                ORDER BY c.last_activity_at IS NULL, c.last_activity_at DESC, c.id DESC", [$meId]);
    }

    /** Creates (or returns) the direct conversation between two active users. */
    public function openDirect(int $meId, string $peerPublicId): string
    {
        $peer = $this->db->one("SELECT id FROM users WHERE public_id = ? AND status = 'active'", [$peerPublicId]);
        if ($peer === null || (int)$peer['id'] === $meId) {
            throw new UserError('user_not_found');
        }
        $a = min($meId, (int)$peer['id']);
        $b = max($meId, (int)$peer['id']);
        $pair = hash('sha256', $a . '|' . $b, true);
        return $this->db->transaction(function () use ($pair, $meId, $a, $b): string {
            $c = $this->db->one('SELECT public_id FROM conversations WHERE direct_pair_hash = ? FOR UPDATE', [$pair]);
            if ($c !== null) {
                return $c['public_id'];
            }
            $now = Clock::db();
            $pub = Ulid::generate();
            $id = $this->db->insert('INSERT INTO conversations (public_id, type, direct_pair_hash, created_by, created_at) VALUES (?, ?, ?, ?, ?)',
                [$pub, 'direct', $pair, $meId, $now]);
            foreach ([$a, $b] as $u) {
                $this->db->run('INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)', [$id, $u, 'member', $now]);
            }
            return $pub;
        });
    }

    private function membership(int $meId, string $convPublicId): ?array
    {
        return $this->db->one('SELECT c.id, c.type, c.is_channel, cm.role FROM conversations c JOIN conversation_members cm ON cm.conversation_id = c.id
                                WHERE c.public_id = ? AND cm.user_id = ? AND cm.left_at IS NULL', [$convPublicId, $meId]);
    }

    /**
     * Stores one encrypted message as per-device envelopes.
     * @param array<string, mixed> $in
     * @return array{message_id:string, created_at:string, duplicate:bool}
     * @throws DeviceMismatch when the envelopes do not exactly cover the active devices
     */
    public function send(int $meId, int $myDeviceId, array $in, ?\PM\Files\AttachmentService $files = null): array
    {
        $conv = is_string($in['conversation_id'] ?? null) ? $this->membership($meId, $in['conversation_id']) : null;
        if ($conv === null) {
            throw new UserError('conversation_not_found');
        }
        if ((int)$conv['is_channel'] === 1 && $conv['role'] === 'member') {
            throw new UserError('channel_read_only'); // in a channel only its admins post (clients enforce the same on the encrypted roster)
        }
        if ((int)($in['protocol_version'] ?? 0) !== self::PROTOCOL_VERSION) {
            throw new UserError('unsupported_protocol_version');
        }
        $clientId = is_string($in['client_msg_id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{22}$/', $in['client_msg_id']) ? $in['client_msg_id'] : null;
        if ($clientId === null) {
            throw new UserError('invalid_client_msg_id');
        }
        $clientRaw = self::rawId($clientId);
        if ($clientRaw === null) {
            throw new UserError('invalid_client_msg_id');
        }
        $envelopes = is_array($in['envelopes'] ?? null) ? $in['envelopes'] : [];
        if ($envelopes === [] || count($envelopes) > 50) {
            throw new UserError('invalid_envelopes');
        }

        $dup = $this->db->one('SELECT created_at FROM messages WHERE sender_device_id = ? AND client_msg_id = ?', [$myDeviceId, $clientRaw]);
        if ($dup !== null) {
            return ['message_id' => $clientId, 'created_at' => $dup['created_at'], 'duplicate' => true];
        }

        // Every active device of every member (except the sending device) must get exactly one envelope.
        $expected = [];
        foreach ($this->db->all("SELECT d.id, d.public_id FROM devices d JOIN conversation_members cm ON cm.user_id = d.user_id
                                  WHERE cm.conversation_id = ? AND cm.left_at IS NULL AND d.status = 'active' AND d.id <> ?", [$conv['id'], $myDeviceId]) as $d) {
            $expected[$d['public_id']] = (int)$d['id'];
        }
        $given = [];
        foreach ($envelopes as $e) {
            $dev = is_array($e) ? ($e['device_id'] ?? null) : null;
            $type = is_array($e) ? ($e['type'] ?? null) : null;
            $ct = is_array($e) ? B64::dec($e['ciphertext'] ?? null, null, self::MAX_ENVELOPE_BYTES * 2) : null;
            if (!is_string($dev) || !in_array($type, [0, 1], true) || $ct === null || strlen($ct) > self::MAX_ENVELOPE_BYTES || isset($given[$dev])) {
                throw new UserError('invalid_envelopes');
            }
            $given[$dev] = ['type' => $type, 'ct' => $ct];
        }
        // Direct chats: every device must be covered. Groups: the client deliberately encrypts only for members
        // announced in the end-to-end encrypted roster, so "missing" is allowed — but never a non-member device.
        $missing = $conv['type'] === 'group' ? [] : array_values(array_diff(array_keys($expected), array_keys($given)));
        $stale = array_values(array_diff(array_keys($given), array_keys($expected)));
        if ($missing !== [] || $stale !== []) {
            throw new DeviceMismatch($missing, $stale);
        }
        if ($expected === []) {
            throw new UserError('recipient_has_no_devices');
        }

        $attIds = is_array($in['attachment_ids'] ?? null) ? array_values(array_filter($in['attachment_ids'], 'is_string')) : [];
        if ($attIds !== [] && $files === null) {
            throw new UserError('attachments_unsupported');
        }
        $notify = ($in['notify'] ?? false) === true; // client hint: a visible message (not a receipt, reaction or edit)
        $res = $this->db->transaction(function () use ($conv, $meId, $myDeviceId, $clientRaw, $clientId, $given, $expected, $attIds, $files, $notify): array {
            $now = Clock::db();
            $msgId = $this->db->insert('INSERT INTO messages (conversation_id, sender_user_id, sender_device_id, client_msg_id, envelope_count, notify, created_at)
                                        VALUES (?, ?, ?, ?, ?, ?, ?)', [$conv['id'], $meId, $myDeviceId, $clientRaw, count($given), $notify ? 1 : 0, $now]);
            if ($attIds !== []) {
                $files->grant($meId, (int)$conv['id'], $msgId, $attIds);
            }
            $exp = Clock::dbIn('+' . self::UNDELIVERED_TTL_DAYS . ' days');
            foreach ($given as $pub => $e) {
                $envId = $this->db->insert('INSERT INTO message_envelopes (message_id, recipient_device_id, protocol_version, olm_type, ciphertext, created_at, expires_at)
                                            VALUES (?, ?, ?, ?, ?, ?, ?)', [$msgId, $expected[$pub], self::PROTOCOL_VERSION, $e['type'], $e['ct'], $now, $exp]);
                $this->db->run('UPDATE devices SET last_event_id = GREATEST(last_event_id, ?) WHERE id = ?', [$envId, $expected[$pub]]);
            }
            $this->db->run('UPDATE conversations SET last_activity_at = ? WHERE id = ?', [Clock::now()->format('Y-m-d H:i:00'), $conv['id']]);
            return ['message_id' => $clientId, 'created_at' => $now, 'duplicate' => false];
        });
        if ($notify) {
            \PM\Push\PushService::queue('message', (int)$conv['id'], $meId, $myDeviceId);
        }
        return $res;
    }

    /** Per-member conversation preferences, synced across the user's devices. Mute also suppresses push. */
    public function setPrefs(int $meId, string $convPublicId, array $in): array
    {
        $conv = $this->membership($meId, $convPublicId);
        if ($conv === null) {
            throw new UserError('conversation_not_found');
        }
        if (array_key_exists('muted_until', $in)) {
            $m = $in['muted_until'];
            $until = $m === null || $m === 0 ? null : ($m === -1 ? '2099-01-01 00:00:00' : (is_int($m) && $m > 0 && $m <= 366 * 86400 ? Clock::now()->modify("+$m seconds")->format('Y-m-d H:i:s') : false));
            if ($until === false) {
                throw new UserError('invalid_mute');
            }
            $this->db->run('UPDATE conversation_members SET muted_until = ? WHERE conversation_id = ? AND user_id = ?', [$until, $conv['id'], $meId]);
        }
        if (array_key_exists('archived', $in)) {
            $this->db->run('UPDATE conversation_members SET archived = ? WHERE conversation_id = ? AND user_id = ?', [($in['archived'] === true) ? 1 : 0, $conv['id'], $meId]);
        }
        return $this->db->one('SELECT archived, muted_until FROM conversation_members WHERE conversation_id = ? AND user_id = ?', [$conv['id'], $meId]) ?? [];
    }

    /** Online status of the other person in a direct chat (minute precision; only to members of that chat). */
    public function peerSeen(int $meId, string $convPublicId): ?string
    {
        $conv = $this->membership($meId, $convPublicId);
        if ($conv === null || $conv['type'] !== 'direct') {
            return null;
        }
        $v = $this->db->scalar("SELECT MAX(d.last_seen_at) FROM devices d JOIN conversation_members cm ON cm.user_id = d.user_id
                                 WHERE cm.conversation_id = ? AND cm.user_id <> ? AND d.status = 'active'", [$conv['id'], $meId]);
        return is_string($v) ? $v : null;
    }

    /**
     * Devices of the other members that synced in the last 20 seconds. Typing signals are encrypted
     * only for these: a signal that is never fetched would leave a gap in the ratchet, and vodozemac
     * refuses gaps above 2000 messages — so signals must not pile up for offline devices.
     * @return list<string>
     */
    public function presentDevices(int $meId, string $convPublicId): array
    {
        $conv = $this->membership($meId, $convPublicId);
        if ($conv === null) {
            throw new UserError('conversation_not_found');
        }
        return array_column($this->db->all("SELECT d.public_id FROM devices d JOIN conversation_members cm ON cm.user_id = d.user_id
                                             WHERE cm.conversation_id = ? AND cm.user_id <> ? AND d.status = 'active' AND d.last_seen_at > ?",
            [$conv['id'], $meId, Clock::now()->modify('-20 seconds')->format('Y-m-d H:i:s')]), 'public_id');
    }

    /** Short-lived encrypted signals (typing). Not stored as messages; expire after 15 seconds. */
    public function sendEphemeral(int $meId, int $myDeviceId, array $in): int
    {
        $conv = is_string($in['conversation_id'] ?? null) ? $this->membership($meId, $in['conversation_id']) : null;
        if ($conv === null || ($in['channel'] ?? '') !== 'typing') {
            throw new UserError('invalid_ephemeral');
        }
        $n = 0;
        foreach (array_slice(is_array($in['envelopes'] ?? null) ? $in['envelopes'] : [], 0, 20) as $e) {
            $ct = is_array($e) ? B64::dec($e['ciphertext'] ?? null, null, 8192) : null;
            $type = is_array($e) ? ($e['type'] ?? null) : null;
            $dev = is_array($e) && is_string($e['device_id'] ?? null) ? $this->db->one("SELECT d.id FROM devices d JOIN conversation_members cm ON cm.user_id = d.user_id
                     WHERE d.public_id = ? AND d.status = 'active' AND cm.conversation_id = ? AND d.id <> ?", [$e['device_id'], $conv['id'], $myDeviceId]) : null;
            if ($ct === null || $dev === null || !in_array($type, [0, 1], true)) {
                continue;
            }
            $this->db->insert('INSERT INTO ephemeral_envelopes (conversation_id, sender_device_id, recipient_device_id, channel, olm_type, ciphertext, created_at, expires_at)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$conv['id'], $myDeviceId, $dev['id'], 'typing', $type, $ct, Clock::db(), Clock::dbIn('+15 seconds')]);
            $n++;
        }
        return $n;
    }

    /** @return array<string, mixed> envelopes for this device after $cursor, plus notifications after $ncursor */
    public function sync(int $meId, int $myDeviceId, int $cursor, int $ncursor): array
    {
        $env = [];
        foreach ($this->db->all('SELECT e.id, e.olm_type, e.ciphertext, e.created_at, m.client_msg_id, c.public_id AS conversation_id,
                                        su.public_id AS sender_user_id, sd.public_id AS sender_device_id
                                   FROM message_envelopes e JOIN messages m ON m.id = e.message_id
                                   JOIN conversations c ON c.id = m.conversation_id
                                   JOIN users su ON su.id = m.sender_user_id JOIN devices sd ON sd.id = m.sender_device_id
                                  WHERE e.recipient_device_id = ? AND e.id > ? ORDER BY e.id LIMIT 100', [$myDeviceId, $cursor]) as $r) {
            $env[] = ['id' => (int)$r['id'], 'type' => (int)$r['olm_type'], 'ciphertext' => base64_encode($r['ciphertext']),
                'message_id' => rtrim(strtr(base64_encode($r['client_msg_id']), '+/', '-_'), '='),
                'conversation_id' => $r['conversation_id'], 'sender_user_id' => $r['sender_user_id'],
                'sender_device_id' => $r['sender_device_id'], 'created_at' => $r['created_at']];
        }
        $notes = $this->db->all('SELECT n.id, n.type, u.public_id AS subject_user_id FROM notifications n LEFT JOIN users u ON u.id = n.subject_user_id
                                  WHERE n.user_id = ? AND n.id > ? ORDER BY n.id LIMIT 100', [$meId, $ncursor]);
        foreach ($notes as &$n) {
            $n['id'] = (int)$n['id'];
        }
        $eph = [];
        foreach ($this->db->all('SELECT e.id, e.olm_type, e.ciphertext, c.public_id AS conversation_id, su.public_id AS sender_user_id, sd.public_id AS sender_device_id
                                   FROM ephemeral_envelopes e JOIN conversations c ON c.id = e.conversation_id JOIN devices sd ON sd.id = e.sender_device_id
                                   JOIN users su ON su.id = sd.user_id WHERE e.recipient_device_id = ? AND e.expires_at > ? ORDER BY e.id LIMIT 50',
            [$myDeviceId, Clock::db()]) as $r) {
            $eph[] = ['id' => (int)$r['id'], 'type' => (int)$r['olm_type'], 'ciphertext' => base64_encode($r['ciphertext']),
                'conversation_id' => $r['conversation_id'], 'sender_user_id' => $r['sender_user_id'], 'sender_device_id' => $r['sender_device_id']];
        }
        if ($eph !== []) {
            $in = implode(',', array_fill(0, count($eph), '?'));
            $this->db->run("DELETE FROM ephemeral_envelopes WHERE recipient_device_id = ? AND id IN ($in)", array_merge([$myDeviceId], array_column($eph, 'id')));
        }
        $this->db->run('UPDATE devices SET last_seen_at = ? WHERE id = ?', [Clock::now()->format('Y-m-d H:i:s'), $myDeviceId]);
        return ['envelopes' => $env, 'ephemeral' => $eph, 'notifications' => $notes,
            'cursor' => $env ? end($env)['id'] : $cursor, 'ncursor' => $notes ? end($notes)['id'] : $ncursor];
    }

    /** Deletes acknowledged envelopes (only this device's) and records delivery. */
    public function ack(int $meId, int $myDeviceId, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', array_slice($ids, 0, 200)), fn ($i) => $i > 0));
        if ($ids === []) {
            return 0;
        }
        return $this->db->transaction(function () use ($ids, $meId, $myDeviceId): int {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $msgIds = array_column($this->db->all("SELECT DISTINCT message_id FROM message_envelopes WHERE recipient_device_id = ? AND id IN ($in)",
                array_merge([$myDeviceId], $ids)), 'message_id');
            $n = $this->db->run("DELETE FROM message_envelopes WHERE recipient_device_id = ? AND id IN ($in)", array_merge([$myDeviceId], $ids))->rowCount();
            foreach ($msgIds as $m) {
                $this->db->run('INSERT IGNORE INTO message_receipts (message_id, user_id, delivered_at) VALUES (?, ?, ?)', [$m, $meId, Clock::db()]);
            }
            return $n;
        });
    }

    /** 16-byte message id from canonical unpadded base64url (22 chars); non-canonical encodings are rejected. */
    private static function rawId(string $id): ?string
    {
        $raw = base64_decode(strtr($id, '-_', '+/') . '==', true);
        if ($raw === false || strlen($raw) !== 16 || rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') !== $id) {
            return null;
        }
        return $raw;
    }

    /** Delivery status of messages I sent: delivered once any device of another member acknowledged. */
    public function status(int $myDeviceId, array $clientIds): array
    {
        $out = [];
        foreach (array_slice($clientIds, 0, 100) as $cid) {
            if (!is_string($cid) || !preg_match('/^[A-Za-z0-9_-]{22}$/', $cid)) {
                continue;
            }
            $raw = self::rawId($cid);
            if ($raw === null) {
                continue;
            }
            $r = $this->db->one('SELECT MIN(r.delivered_at) AS d FROM messages m JOIN message_receipts r ON r.message_id = m.id AND r.user_id <> m.sender_user_id
                                  WHERE m.sender_device_id = ? AND m.client_msg_id = ?', [$myDeviceId, $raw]);
            $out[$cid] = $r && $r['d'] ? 'delivered' : 'sent';
        }
        return $out;
    }
}
