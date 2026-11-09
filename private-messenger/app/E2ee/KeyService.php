<?php
declare(strict_types=1);

namespace PM\E2ee;

use PM\Audit\AuditLog;
use PM\Infra\Database;
use PM\Support\B64;
use PM\Support\Clock;
use PM\Support\Crypto;
use PM\Support\Ulid;
use PM\Support\UserError;
use PM\Support\UserAgent;

/**
 * Public-key directory for end-to-end encryption. The server stores and distributes PUBLIC keys
 * only. It verifies every signature itself as defence in depth, but clients never trust it:
 * they verify certificates against the user's master key and pin that key (TOFU / safety number).
 *
 * Signed strings (all Ed25519 over UTF-8):
 *   device certificate  : canonical JSON {"v":1,"type":"pm-device-cert","user":U,"ik":IK,"sk":SK,"protocols":["olm-v1"]}  — by MSK
 *   one-time key        : "pm-otk-v1:<key id>:<public key>"  — by device signing key (sk)
 *   fallback key        : "pm-fbk-v1:<key id>:<public key>"  — by sk
 *   session binding     : "pm-bind-v1:<nonce b64>:<session public id>"  — by sk
 */
final class KeyService
{
    public const MAX_OTK_UPLOAD = 100;

    public function __construct(private readonly Database $db, private readonly ?AuditLog $audit = null)
    {
    }

    public static function certificateString(string $userPublicId, string $ik, string $sk): string
    {
        return (string)json_encode(['v' => 1, 'type' => 'pm-device-cert', 'user' => $userPublicId, 'ik' => $ik, 'sk' => $sk, 'protocols' => ['olm-v1']],
            JSON_UNESCAPED_SLASHES);
    }

    private static function verify(string $publicKeyRaw, string $message, ?string $sigRaw): bool
    {
        return $sigRaw !== null && strlen($sigRaw) === 64 && sodium_crypto_sign_verify_detached($sigRaw, $message, $publicKeyRaw);
    }

    public function currentMasterKey(int $userId): ?array
    {
        return $this->db->one('SELECT * FROM user_master_keys WHERE user_id = ? AND superseded_at IS NULL ORDER BY id DESC LIMIT 1', [$userId]);
    }

    /**
     * Registers (or, with $reset, replaces) the user's identity: master key + this device.
     * @param array<string, mixed> $in
     * @return string the new device public id
     */
    public function registerIdentity(array $user, int $sessionId, array $in, bool $reset, string $userAgent): string
    {
        $userId = (int)$user['id'];
        $msk = B64::dec($in['msk'] ?? null, 32);
        $dev = is_array($in['device'] ?? null) ? $in['device'] : [];
        $ik = B64::dec($dev['identity_key'] ?? null, 32);
        $sk = B64::dec($dev['signing_key'] ?? null, 32);
        $sig = B64::dec($dev['certificate_sig'] ?? null, 64);
        if ($msk === null || $ik === null || $sk === null || $sig === null) {
            throw new UserError('invalid_keys');
        }
        $cert = self::certificateString((string)$user['public_id'], B64::enc($ik), B64::enc($sk));
        if (!self::verify($msk, $cert, $sig)) {
            throw new UserError('bad_certificate');
        }
        $otks = $this->validatedPrekeys($sk, $dev['one_time_keys'] ?? [], 'pm-otk-v1');
        $fbk = $this->validatedPrekeys($sk, isset($dev['fallback_key']) ? [$dev['fallback_key']] : [], 'pm-fbk-v1');
        if (count($otks) < 1 || count($fbk) !== 1) {
            throw new UserError('missing_prekeys');
        }

        return $this->db->transaction(function () use ($userId, $sessionId, $msk, $ik, $sk, $cert, $sig, $otks, $fbk, $reset, $userAgent): string {
            $current = $this->db->one('SELECT id FROM user_master_keys WHERE user_id = ? AND superseded_at IS NULL FOR UPDATE', [$userId]);
            $now = Clock::db();
            if ($current !== null) {
                if (!$reset) {
                    throw new UserError('identity_exists');
                }
                $this->supersedeIdentity($userId, 'user_reset');
            }
            $this->db->insert('INSERT INTO user_master_keys (public_id, user_id, public_key, created_at) VALUES (?, ?, ?, ?)',
                [Ulid::generate(), $userId, $msk, $now]);
            $devicePublic = Ulid::generate();
            $deviceId = $this->db->insert(
                'INSERT INTO devices (public_id, user_id, label, status, identity_key, signing_key, certificate, certificate_sig, protocols, created_at, activated_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$devicePublic, $userId, UserAgent::summary($userAgent), 'active', $ik, $sk, $cert, $sig, 'olm-v1', $now, $now, Clock::now()->format('Y-m-d H:i:s')]);
            $this->insertPrekeys($deviceId, $otks, 'one_time');
            $this->insertPrekeys($deviceId, $fbk, 'fallback');
            $this->db->run('UPDATE user_sessions SET device_id = ?, state = ? WHERE id = ?', [$deviceId, 'active', $sessionId]);
            if ($current !== null) {
                $this->notifyContacts($userId, 'contact_identity_changed');
            }
            return $devicePublic;
        });
    }

    /** Marks the current identity superseded and revokes all devices (their pending envelopes are dropped). */
    public function supersedeIdentity(int $userId, string $reason): void
    {
        $now = Clock::db();
        $this->db->run('UPDATE user_master_keys SET superseded_at = ?, superseded_reason = ? WHERE user_id = ? AND superseded_at IS NULL', [$now, $reason, $userId]);
        $devs = array_map('intval', array_column($this->db->all("SELECT id FROM devices WHERE user_id = ? AND status <> 'revoked'", [$userId]), 'id'));
        foreach ($devs as $d) {
            $this->revokeDeviceRow($d, $reason === 'admin_reset' ? 'admin' : 'user');
        }
    }

    private function revokeDeviceRow(int $deviceId, string $by): void
    {
        $this->db->run("UPDATE devices SET status = 'revoked', revoked_at = ?, revoked_by = ? WHERE id = ?", [Clock::db(), $by, $deviceId]);
        $this->db->run('DELETE FROM message_envelopes WHERE recipient_device_id = ?', [$deviceId]);
        $this->db->run('DELETE FROM device_public_keys WHERE device_id = ?', [$deviceId]);
        $this->db->run("UPDATE user_sessions SET device_id = NULL, state = 'device_pending' WHERE device_id = ?", [$deviceId]);
    }

    /** Admin: revoke one device. */
    public function adminRevokeDevice(int $adminId, int $userId, string $devicePublicId, string $ip): void
    {
        $d = $this->db->one("SELECT id FROM devices WHERE user_id = ? AND public_id = ? AND status <> 'revoked'", [$userId, $devicePublicId]);
        if ($d === null) {
            throw new UserError('دستگاه یافت نشد.');
        }
        $this->db->transaction(function () use ($d, $userId): void {
            $this->revokeDeviceRow((int)$d['id'], 'admin');
            $this->notifyContacts($userId, 'contact_device_added'); // contacts refresh the device list
        });
        $this->audit?->record('admin', $adminId, 'user.device.revoke', 'user', $userId, [], $ip);
    }

    /** Admin: reset the user's encryption identity. Old messages can never be recovered by anyone. */
    public function adminResetIdentity(int $adminId, int $userId, string $ip): void
    {
        $this->db->transaction(function () use ($userId): void {
            $this->supersedeIdentity($userId, 'admin_reset');
            $this->db->run('UPDATE users SET identity_reset_count = identity_reset_count + 1 WHERE id = ?', [$userId]);
            $this->notifyContacts($userId, 'contact_identity_changed');
            $this->db->insert('INSERT INTO notifications (user_id, type, created_at) VALUES (?, ?, ?)', [$userId, 'admin_reset', Clock::db()]);
        });
        $this->audit?->record('admin', $adminId, 'user.identity.reset', 'user', $userId, [], $ip);
    }

    /** @return list<array{id:string, key:string, sig:string}> validated, raw-decoded */
    private function validatedPrekeys(string $sk, mixed $list, string $prefix): array
    {
        if (!is_array($list) || count($list) > self::MAX_OTK_UPLOAD) {
            throw new UserError('invalid_prekeys');
        }
        $out = [];
        foreach ($list as $k) {
            $id = is_array($k) ? ($k['id'] ?? null) : null;
            $pub = is_array($k) ? B64::dec($k['key'] ?? null, 32) : null;
            $sig = is_array($k) ? B64::dec($k['sig'] ?? null, 64) : null;
            if (!is_string($id) || !preg_match('#^[A-Za-z0-9+/=]{1,64}$#', $id) || $pub === null
                || !self::verify($sk, $prefix . ':' . $id . ':' . B64::enc($pub), $sig)) {
                throw new UserError('bad_prekey_signature');
            }
            $out[] = ['id' => $id, 'key' => $pub, 'sig' => $sig];
        }
        return $out;
    }

    private function insertPrekeys(int $deviceId, array $keys, string $type): void
    {
        if ($type === 'fallback') {
            $this->db->run("DELETE FROM device_public_keys WHERE device_id = ? AND key_type = 'fallback'", [$deviceId]);
        }
        foreach ($keys as $k) {
            $this->db->run('INSERT IGNORE INTO device_public_keys (device_id, key_type, key_id, public_key, signature, created_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$deviceId, $type, $k['id'], $k['key'], $k['sig'], Clock::db()]);
        }
    }

    public function uploadPrekeys(int $deviceId, mixed $otks, mixed $fallback): int
    {
        $d = $this->db->one("SELECT signing_key FROM devices WHERE id = ? AND status = 'active'", [$deviceId]);
        if ($d === null) {
            throw new UserError('device_not_active');
        }
        $this->insertPrekeys($deviceId, $this->validatedPrekeys($d['signing_key'], $otks ?? [], 'pm-otk-v1'), 'one_time');
        if ($fallback !== null) {
            $this->insertPrekeys($deviceId, $this->validatedPrekeys($d['signing_key'], [$fallback], 'pm-fbk-v1'), 'fallback');
        }
        return $this->otkCount($deviceId);
    }

    public function otkCount(int $deviceId): int
    {
        return (int)$this->db->scalar("SELECT COUNT(*) FROM device_public_keys WHERE device_id = ? AND key_type = 'one_time' AND claimed_at IS NULL", [$deviceId]);
    }

    /** @return array<string, mixed>|null public identity of a user (current MSK + certified active devices) */
    public function userKeys(string $userPublicId): ?array
    {
        $u = $this->db->one("SELECT id, public_id FROM users WHERE public_id = ? AND status = 'active'", [$userPublicId]);
        if ($u === null) {
            return null;
        }
        $msk = $this->currentMasterKey((int)$u['id']);
        $devices = [];
        foreach ($this->db->all("SELECT public_id, identity_key, signing_key, certificate, certificate_sig FROM devices WHERE user_id = ? AND status = 'active' ORDER BY id", [$u['id']]) as $d) {
            $devices[] = ['id' => $d['public_id'], 'identity_key' => B64::enc($d['identity_key']), 'signing_key' => B64::enc($d['signing_key']),
                'certificate' => $d['certificate'], 'certificate_sig' => B64::enc($d['certificate_sig'])];
        }
        return ['user_id' => $u['public_id'], 'msk' => $msk ? B64::enc($msk['public_key']) : null, 'devices' => $devices];
    }

    /**
     * Hands out one one-time key per requested device (fallback key when exhausted).
     * @param list<string> $devicePublicIds
     * @return array<string, array{type:string, id:string, key:string, sig:string}>
     */
    public function claim(array $devicePublicIds, int $claimerDeviceId): array
    {
        $out = [];
        foreach (array_slice(array_unique($devicePublicIds), 0, 50) as $pid) {
            if (!is_string($pid)) {
                continue;
            }
            $dev = $this->db->one("SELECT id FROM devices WHERE public_id = ? AND status = 'active'", [$pid]);
            if ($dev === null) {
                continue;
            }
            $k = $this->db->transaction(function () use ($dev, $claimerDeviceId): ?array {
                $row = $this->db->one("SELECT id, key_id, public_key, signature FROM device_public_keys
                                        WHERE device_id = ? AND key_type = 'one_time' AND claimed_at IS NULL ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED", [$dev['id']]);
                if ($row !== null) {
                    $this->db->run('UPDATE device_public_keys SET claimed_at = ?, claimed_by_device = ? WHERE id = ?', [Clock::db(), $claimerDeviceId, $row['id']]);
                    return $row + ['type' => 'one_time'];
                }
                $fb = $this->db->one("SELECT key_id, public_key, signature FROM device_public_keys WHERE device_id = ? AND key_type = 'fallback' ORDER BY id DESC LIMIT 1", [$dev['id']]);
                return $fb === null ? null : $fb + ['type' => 'fallback'];
            });
            if ($k !== null) {
                $out[$pid] = ['type' => $k['type'], 'id' => $k['key_id'], 'key' => B64::enc($k['public_key']), 'sig' => B64::enc($k['signature'])];
            }
        }
        return $out;
    }

    /** Issues a one-time nonce that the browser must sign with its device key to re-attach this login session. */
    public function bindChallenge(int $sessionId): string
    {
        $nonce = random_bytes(32);
        $this->db->run('UPDATE user_sessions SET bind_nonce = ?, bind_nonce_expires = ? WHERE id = ?', [$nonce, Clock::dbIn('+2 minutes'), $sessionId]);
        return B64::enc($nonce);
    }

    public function bind(array $session, string $devicePublicId, mixed $signatureB64): bool
    {
        $row = $this->db->one('SELECT bind_nonce, bind_nonce_expires FROM user_sessions WHERE id = ?', [$session['session_id']]);
        $this->db->run('UPDATE user_sessions SET bind_nonce = NULL, bind_nonce_expires = NULL WHERE id = ?', [$session['session_id']]);
        if ($row === null || $row['bind_nonce'] === null || strtotime($row['bind_nonce_expires'] . ' UTC') < Clock::now()->getTimestamp()) {
            return false;
        }
        $d = $this->db->one("SELECT id, signing_key FROM devices WHERE public_id = ? AND user_id = ? AND status = 'active'", [$devicePublicId, $session['id']]);
        $msg = 'pm-bind-v1:' . B64::enc($row['bind_nonce']) . ':' . $session['session_public_id'];
        if ($d === null || !self::verify($d['signing_key'], $msg, B64::dec($signatureB64, 64))) {
            return false;
        }
        $this->db->run("UPDATE user_sessions SET device_id = ?, state = 'active' WHERE id = ?", [$d['id'], $session['session_id']]);
        return true;
    }

    /** Tells everyone who shares a conversation with $userId to refresh that user's keys. */
    public function notifyContacts(int $userId, string $type): void
    {
        $now = Clock::db();
        foreach ($this->db->all('SELECT DISTINCT m2.user_id FROM conversation_members m1 JOIN conversation_members m2
                                  ON m2.conversation_id = m1.conversation_id AND m2.user_id <> m1.user_id WHERE m1.user_id = ?', [$userId]) as $r) {
            $this->db->insert('INSERT INTO notifications (user_id, type, subject_user_id, created_at) VALUES (?, ?, ?, ?)', [$r['user_id'], $type, $userId, $now]);
        }
    }

    public function devicesOf(int $userId): array
    {
        return $this->db->all('SELECT d.public_id, d.label, d.status, d.created_at, d.last_seen_at, d.revoked_at, d.revoked_by, d.identity_key,
                                      (SELECT COUNT(*) FROM device_public_keys k WHERE k.device_id = d.id AND k.key_type = \'one_time\' AND k.claimed_at IS NULL) AS otks
                                 FROM devices d WHERE d.user_id = ? ORDER BY d.id DESC LIMIT 20', [$userId]);
    }

    /** Short fingerprint for display (admin cannot use this to read anything; it only identifies a key). */
    public static function fingerprint(string $raw): string
    {
        return implode(' ', str_split(strtoupper(substr(bin2hex(Crypto::sha256($raw)), 0, 16)), 4));
    }
}
