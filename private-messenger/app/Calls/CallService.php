<?php
declare(strict_types=1);

namespace PM\Calls;

use PM\Infra\Database;
use PM\Settings\Settings;
use PM\Support\Clock;
use PM\Support\Ulid;
use PM\Support\UserError;

/**
 * In-app (internet) voice/video calls between two users — WebRTC, not phone-line calls.
 * The server only keeps minimal metadata (who, when, how long, outcome). Offers/answers (SDP, which contains
 * the DTLS fingerprints) travel end-to-end encrypted through the message channel, so the server cannot
 * tamper with them. Media is encrypted by WebRTC (DTLS-SRTP), peer-to-peer or through a TURN relay.
 * Whether a call is audio or video is not stored.
 */
final class CallService
{
    private const FINAL = ['ended', 'missed', 'rejected', 'cancelled', 'failed'];

    public function __construct(private readonly Database $db, private readonly Settings $settings)
    {
    }

    public function start(int $meId, string $convPublicId): string
    {
        $c = $this->db->one('SELECT c.id, (SELECT cm2.user_id FROM conversation_members cm2 WHERE cm2.conversation_id = c.id AND cm2.user_id <> ? LIMIT 1) AS peer
                               FROM conversations c JOIN conversation_members cm ON cm.conversation_id = c.id
                              WHERE c.public_id = ? AND cm.user_id = ? AND cm.left_at IS NULL', [$meId, $convPublicId, $meId]);
        if ($c === null || $c['peer'] === null) {
            throw new UserError('conversation_not_found');
        }
        $pub = Ulid::generate();
        $now = Clock::db();
        $id = $this->db->insert('INSERT INTO calls (public_id, conversation_id, caller_user_id, callee_user_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$pub, $c['id'], $meId, $c['peer'], 'ringing', $now]);
        $this->db->run('INSERT INTO call_participants (call_id, user_id, joined_at) VALUES (?, ?, ?)', [$id, $meId, $now]);
        return $pub;
    }

    public function update(int $meId, string $callPublicId, string $status, ?string $reason): string
    {
        $c = $this->db->one('SELECT * FROM calls WHERE public_id = ? AND (caller_user_id = ? OR callee_user_id = ?)', [$callPublicId, $meId, $meId]);
        if ($c === null) {
            throw new UserError('call_not_found');
        }
        if (in_array($c['status'], self::FINAL, true)) {
            return $c['status']; // already finished: idempotent
        }
        $isCallee = (int)$c['callee_user_id'] === $meId;
        $allowed = match ($status) {
            'active', 'rejected' => $isCallee && $c['status'] === 'ringing',
            'cancelled', 'missed' => !$isCallee && $c['status'] === 'ringing',
            'ended', 'failed' => true,
            default => false,
        };
        if (!$allowed) {
            throw new UserError('invalid_call_transition');
        }
        $now = Clock::db();
        $reason = in_array($reason, ['hangup', 'timeout', 'ice_failed', 'busy', 'error'], true) ? $reason : null;
        if ($status === 'active') {
            $this->db->run('UPDATE calls SET status = ?, answered_at = ? WHERE id = ?', ['active', $now, $c['id']]);
            $this->db->run('INSERT INTO call_participants (call_id, user_id, joined_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE joined_at = VALUES(joined_at)', [$c['id'], $meId, $now]);
        } else {
            if ($status === 'ended' && $c['answered_at'] === null) {
                $status = $isCallee ? 'rejected' : 'cancelled'; // hung up before anyone answered
            }
            $dur = $c['answered_at'] !== null ? max(0, Clock::now()->getTimestamp() - strtotime($c['answered_at'] . ' UTC')) : null;
            $this->db->run('UPDATE calls SET status = ?, end_reason = ?, ended_at = ?, duration_seconds = ? WHERE id = ?', [$status, $reason, $now, $dur, $c['id']]);
            $this->db->run('UPDATE call_participants SET left_at = ? WHERE call_id = ? AND left_at IS NULL', [$now, $c['id']]);
        }
        return $status;
    }

    /** @return list<array<string, mixed>> */
    public function history(int $meId, int $limit = 50): array
    {
        $out = [];
        foreach ($this->db->all('SELECT c.public_id, c.status, c.end_reason, c.created_at, c.duration_seconds, c.caller_user_id, cv.public_id AS conversation_id,
                                        p.public_id AS peer_id, p.display_name AS peer_name
                                   FROM calls c JOIN conversations cv ON cv.id = c.conversation_id
                                   JOIN users p ON p.id = IF(c.caller_user_id = ?, c.callee_user_id, c.caller_user_id)
                                  WHERE c.caller_user_id = ? OR c.callee_user_id = ? ORDER BY c.id DESC LIMIT ' . max(1, min(200, $limit)), [$meId, $meId, $meId]) as $r) {
            $out[] = ['id' => $r['public_id'], 'status' => $r['status'], 'reason' => $r['end_reason'], 'created_at' => $r['created_at'],
                'duration' => $r['duration_seconds'] === null ? null : (int)$r['duration_seconds'], 'outgoing' => (int)$r['caller_user_id'] === $meId,
                'conversation_id' => $r['conversation_id'], 'peer' => ['id' => $r['peer_id'], 'display_name' => $r['peer_name']]];
        }
        return $out;
    }

    /** ICE servers for this user. TURN credentials are short-lived (coturn "use-auth-secret" / TURN REST API). */
    public function iceConfig(string $userPublicId): array
    {
        $s = $this->settings;
        $servers = [];
        $stun = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string)$s->get('calls.stun')) ?: [])));
        if ($stun) {
            $servers[] = ['urls' => $stun];
        }
        $turn = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string)$s->get('calls.turn_urls')) ?: [])));
        $mode = (string)$s->get('calls.turn_mode');
        if ($turn && $mode === 'secret' && $s->secret('calls.turn_secret') !== '') {
            $user = (time() + 3600) . ':' . $userPublicId;
            $servers[] = ['urls' => $turn, 'username' => $user, 'credential' => base64_encode(hash_hmac('sha1', $user, $s->secret('calls.turn_secret'), true))];
        } elseif ($turn && $mode === 'static') {
            $servers[] = ['urls' => $turn, 'username' => (string)$s->get('calls.turn_username'), 'credential' => $s->secret('calls.turn_password')];
        }
        $hasTurn = $turn && in_array($mode, ['secret', 'static'], true);
        return ['ice_servers' => $servers, 'relay_only' => $hasTurn && (bool)$s->get('calls.relay_only'), 'has_turn' => $hasTurn, 'ring_seconds' => 45];
    }

    /** @return array<string, int> non-content statistics for the admin dashboard */
    public function stats(): array
    {
        $since = Clock::now()->modify('-1 day')->format('Y-m-d H:i:s');
        $r = $this->db->one("SELECT COUNT(*) AS calls, COALESCE(SUM(duration_seconds), 0) AS seconds, SUM(status = 'failed') AS failed, SUM(status = 'missed') AS missed
                               FROM calls WHERE created_at > ?", [$since]);
        return array_map('intval', $r);
    }
}
