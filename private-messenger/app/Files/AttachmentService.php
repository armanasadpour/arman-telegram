<?php
declare(strict_types=1);

namespace PM\Files;

use PM\Infra\Database;
use PM\Settings\Settings;
use PM\Support\Clock;
use PM\Support\UserError;

/**
 * Encrypted attachment storage. Files are encrypted on the sender's device BEFORE upload
 * (AES-256-GCM, STREAM construction, 256 KiB segments); the server only ever sees ciphertext and
 * cannot decrypt: the file key travels inside the end-to-end encrypted message.
 *
 * - chunked, resumable upload (chunk = whole number of encrypted segments, sized to the host's PHP limits)
 * - no artificial size cap (optional admin cap; real limits = disk/quota)
 * - stored outside the web root under a random name, never executable
 * - downloads only for members of a conversation the file was sent to, with HTTP Range
 * - 7-day retention (or permanent for accounts flagged by the super admin); expiry enforced at query time
 */
final class AttachmentService
{
    public const SEGMENT_PLAIN = 262144;          // 256 KiB plaintext per encrypted segment
    public const SEGMENT_CIPHER = 262144 + 16;    // + AES-GCM tag
    public const RETENTION_DAYS = 7;
    public const STALE_UPLOAD_HOURS = 48;
    private const FREE_SPACE_MARGIN = 50 * 1048576;

    public function __construct(private readonly Database $db, private readonly Settings $settings, private readonly string $storageDir)
    {
    }

    /** Network chunk size: whole encrypted segments, ~80% of the host's request limit, at most 8 MiB. */
    public static function chunkSize(): int
    {
        $post = self::iniBytes((string)ini_get('post_max_size'));
        $limit = $post > 0 ? min($post, 8 * 1048576) : 8 * 1048576;
        return max(1, intdiv((int)($limit * 0.8), self::SEGMENT_CIPHER)) * self::SEGMENT_CIPHER;
    }

    public static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1' || $v === '0') {
            return 0;
        }
        $n = (int)$v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n,
        };
    }

    public function freeBytes(): ?int
    {
        $f = @disk_free_space($this->storageDir);
        return $f === false ? null : (int)$f;
    }

    private function tmpDir(): string
    {
        return dirname($this->storageDir) . '/tmp';
    }

    private function path(string $storageKey, bool $partial = false): string
    {
        return $this->storageDir . '/' . substr($storageKey, 0, 2) . '/' . substr($storageKey, 2, 2) . '/' . $storageKey . ($partial ? '.part' : '');
    }

    /** @return array{id:string, chunk_size:int, chunk_count:int} */
    public function init(int $userId, int $deviceId, string $convPublicId, mixed $size): array
    {
        $conv = $this->db->one('SELECT c.id FROM conversations c JOIN conversation_members cm ON cm.conversation_id = c.id
                                 WHERE c.public_id = ? AND cm.user_id = ? AND cm.left_at IS NULL', [$convPublicId, $userId]);
        if ($conv === null) {
            throw new UserError('conversation_not_found');
        }
        if (!is_int($size) || $size < 16) { // smallest ciphertext: an empty file = one 16-byte tag
            throw new UserError('invalid_size');
        }
        $max = (int)$this->settings->get('files.max_bytes'); // 0 = no software limit
        if ($max > 0 && $size > $max) {
            throw new UserError('file_too_large');
        }
        $quota = $this->userQuota($userId);
        if ($quota !== null && $this->userUsage($userId) + $size > $quota) {
            throw new UserError('user_quota_exceeded');
        }
        $free = $this->freeBytes();
        if ($free !== null && $free < $size + self::FREE_SPACE_MARGIN) {
            throw new UserError('insufficient_storage');
        }
        // Abuse control (not a size cap): unfinished uploads of one user may not take more than 25% of free space.
        $open = (int)$this->db->scalar("SELECT COALESCE(SUM(size_bytes), 0) FROM attachments WHERE uploader_user_id = ? AND upload_status IN ('initiated','uploading')", [$userId]);
        if ($free !== null && $open + $size > max(intdiv($free, 4), 1073741824)) {
            throw new UserError('too_many_unfinished_uploads');
        }
        $chunk = self::chunkSize();
        $public = bin2hex(random_bytes(16));
        $key = bin2hex(random_bytes(32));
        $this->db->insert('INSERT INTO attachments (public_id, storage_key, purpose, conversation_id, uploader_user_id, uploader_device_id, size_bytes, chunk_size, chunk_count, upload_status, created_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$public, $key, 'message', $conv['id'], $userId, $deviceId, $size, $chunk, (int)ceil($size / $chunk), 'initiated', Clock::db()]);
        return ['id' => $public, 'chunk_size' => $chunk, 'chunk_count' => (int)ceil($size / $chunk)];
    }

    /**
     * Bytes this user currently occupies on the server: finished files that have not expired (permanent ones
     * included) plus unfinished uploads. Expired files stop counting immediately, even before the cleanup runs.
     */
    public function userUsage(int $userId): int
    {
        return (int)$this->db->scalar("SELECT COALESCE(SUM(size_bytes), 0) FROM attachments WHERE uploader_user_id = ?
                                        AND (upload_status IN ('initiated','uploading') OR (upload_status = 'complete' AND (expires_at IS NULL OR expires_at > ?)))",
            [$userId, Clock::db()]);
    }

    /** The user's limit in bytes, or null for «no limit». Personal value first, then the default from settings. */
    public function userQuota(int $userId): ?int
    {
        $own = $this->db->scalar('SELECT storage_quota_bytes FROM users WHERE id = ?', [$userId]);
        $q = $own !== null ? (int)$own : (int)$this->settings->get('storage.user_quota_bytes');
        return $q > 0 ? $q : null;
    }

    /** @return array{used:int, quota:?int, personal:bool, retention_days:int} */
    public function userStorage(int $userId): array
    {
        return ['used' => $this->userUsage($userId), 'quota' => $this->userQuota($userId),
            'personal' => $this->db->scalar('SELECT storage_quota_bytes FROM users WHERE id = ?', [$userId]) !== null,
            'retention_days' => $this->settings->int('files.retention_days')];
    }

    private function ownUpload(int $userId, string $publicId): array
    {
        $a = $this->db->one("SELECT * FROM attachments WHERE public_id = ? AND uploader_user_id = ? AND upload_status IN ('initiated','uploading')", [$publicId, $userId]);
        if ($a === null) {
            throw new UserError('upload_not_found');
        }
        return $a;
    }

    /** Writes one chunk at its offset. Idempotent: re-sending an identical chunk is accepted. */
    public function putChunk(int $userId, string $publicId, int $index, string $sha256Hex, $input): void
    {
        $a = $this->ownUpload($userId, $publicId);
        $count = (int)$a['chunk_count'];
        $chunk = (int)$a['chunk_size'];
        if ($index < 0 || $index >= $count) {
            throw new UserError('invalid_chunk_index');
        }
        $expected = $index === $count - 1 ? (int)$a['size_bytes'] - $chunk * ($count - 1) : $chunk;
        if (!preg_match('/^[0-9a-f]{64}$/', $sha256Hex)) {
            throw new UserError('invalid_chunk_hash');
        }
        $have = $this->db->one('SELECT sha256 FROM upload_chunks WHERE attachment_id = ? AND chunk_index = ?', [$a['id'], $index]);
        if ($have !== null && bin2hex($have['sha256']) === $sha256Hex) {
            return; // already stored (retry after a lost response)
        }
        $free = $this->freeBytes();
        if ($free !== null && $free < $expected + self::FREE_SPACE_MARGIN) {
            throw new UserError('insufficient_storage');
        }
        $tmp = tempnam($this->tmpDir(), 'chunk');
        $out = fopen($tmp, 'wb');
        $ctx = hash_init('sha256');
        $n = 0;
        while (!feof($input)) {
            $buf = fread($input, 1048576);
            if ($buf === false || $buf === '') {
                break;
            }
            $n += strlen($buf);
            if ($n > $expected) {
                break;
            }
            hash_update($ctx, $buf);
            fwrite($out, $buf);
        }
        fclose($out);
        if ($n !== $expected || !hash_equals($sha256Hex, hash_final($ctx))) {
            @unlink($tmp);
            throw new UserError($n !== $expected ? 'chunk_size_mismatch' : 'chunk_hash_mismatch');
        }
        $part = $this->path($a['storage_key'], true);
        if (!is_dir(dirname($part)) && !@mkdir(dirname($part), 0750, true) && !is_dir(dirname($part))) {
            @unlink($tmp);
            throw new \RuntimeException('cannot create storage directory');
        }
        $fh = fopen($part, 'c+b');
        flock($fh, LOCK_EX);
        fseek($fh, $index * $chunk);
        $src = fopen($tmp, 'rb');
        stream_copy_to_stream($src, $fh);
        fclose($src);
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        @unlink($tmp);
        $this->db->run('INSERT INTO upload_chunks (attachment_id, chunk_index, size_bytes, sha256, received_at) VALUES (?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE size_bytes = VALUES(size_bytes), sha256 = VALUES(sha256), received_at = VALUES(received_at)',
            [$a['id'], $index, $n, hex2bin($sha256Hex), Clock::db()]);
        $this->db->run("UPDATE attachments SET upload_status = 'uploading', last_chunk_at = ? WHERE id = ? AND upload_status IN ('initiated','uploading')", [Clock::db(), $a['id']]);
        if ($this->db->scalar('SELECT upload_status FROM attachments WHERE id = ?', [$a['id']]) !== 'uploading') { // cancelled while this chunk was arriving: leave nothing behind
            @unlink($part);
            $this->db->run('DELETE FROM upload_chunks WHERE attachment_id = ?', [$a['id']]);
            throw new UserError('upload_cancelled');
        }
    }

    /**
     * The sender cancelled: the partial (or finished but never sent) ciphertext is deleted at once.
     * A file that was already sent in a message cannot be withdrawn this way.
     */
    public function abort(int $userId, string $publicId): bool
    {
        $a = $this->db->one("SELECT id, storage_key, upload_status FROM attachments WHERE public_id = ? AND uploader_user_id = ?
                               AND upload_status IN ('initiated','uploading','complete')", [$publicId, $userId]);
        if ($a === null || ($a['upload_status'] === 'complete' && $this->db->scalar('SELECT 1 FROM attachment_grants WHERE attachment_id = ? LIMIT 1', [$a['id']]) !== null)) {
            return false;
        }
        $this->db->run("UPDATE attachments SET upload_status = 'deleted', deleted_at = ?, delete_reason = 'user' WHERE id = ?", [Clock::db(), $a['id']]);
        @unlink($this->path($a['storage_key'], true));
        @unlink($this->path($a['storage_key']));
        $this->db->run('DELETE FROM upload_chunks WHERE attachment_id = ?', [$a['id']]);
        return true;
    }

    /** @return list<int> received chunk indexes (for resuming) */
    public function received(int $userId, string $publicId): array
    {
        $a = $this->ownUpload($userId, $publicId);
        return array_map('intval', array_column($this->db->all('SELECT chunk_index FROM upload_chunks WHERE attachment_id = ? ORDER BY chunk_index', [$a['id']]), 'chunk_index'));
    }

    /** @return array{expires_at:?string} */
    public function complete(int $userId, string $publicId): array
    {
        $a = $this->ownUpload($userId, $publicId);
        $n = (int)$this->db->scalar('SELECT COUNT(*) FROM upload_chunks WHERE attachment_id = ?', [$a['id']]);
        $part = $this->path($a['storage_key'], true);
        if ($n !== (int)$a['chunk_count'] || !is_file($part) || filesize($part) !== (int)$a['size_bytes']) {
            throw new UserError('upload_incomplete');
        }
        rename($part, $this->path($a['storage_key']));
        $permanent = (int)$this->db->scalar('SELECT permanent_file_retention FROM users WHERE id = ?', [$userId]) === 1;
        $now = Clock::db();
        $expires = $permanent ? null : Clock::dbIn('+' . max(1, $this->settings->int('files.retention_days')) . ' days');
        $done = $this->db->run("UPDATE attachments SET upload_status = 'complete', uploaded_at = ?, expires_at = ?, retention_permanent = ?,
                        ciphertext_sha256 = ? WHERE id = ? AND upload_status IN ('initiated','uploading')",
            [$now, $expires, $permanent ? 1 : 0, hash_file('sha256', $this->path($a['storage_key']), true), $a['id']])->rowCount();
        if ($done === 0) { // cancelled meanwhile
            @unlink($this->path($a['storage_key']));
            throw new UserError('upload_cancelled');
        }
        $this->db->run('DELETE FROM upload_chunks WHERE attachment_id = ?', [$a['id']]);
        return ['expires_at' => $expires];
    }

    /**
     * Allows a conversation to download attachments referenced by a message. The sender must own the file
     * (fresh upload) or already have access to it through another conversation (forwarding).
     * @param list<string> $publicIds
     */
    public function grant(int $userId, int $conversationId, int $messageId, array $publicIds): void
    {
        foreach (array_slice(array_unique($publicIds), 0, 20) as $pid) {
            $a = is_string($pid) ? $this->downloadable($userId, $pid) : null;
            if ($a === null) {
                throw new UserError('attachment_not_found');
            }
            $this->db->run('INSERT IGNORE INTO attachment_grants (attachment_id, conversation_id, message_id, granted_at) VALUES (?, ?, ?, ?)',
                [$a['id'], $conversationId, $messageId, Clock::db()]);
        }
    }

    /** Returns the attachment row if this user may download it now (member of a granted conversation, or uploader). */
    public function downloadable(int $userId, string $publicId): ?array
    {
        return $this->db->one("SELECT a.* FROM attachments a
                                WHERE a.public_id = ? AND a.upload_status = 'complete' AND (a.retention_permanent = 1 OR a.expires_at > ?)
                                  AND (a.uploader_user_id = ? OR EXISTS (SELECT 1 FROM attachment_grants g JOIN conversation_members cm
                                        ON cm.conversation_id = g.conversation_id AND cm.user_id = ? AND cm.left_at IS NULL WHERE g.attachment_id = a.id))",
            [$publicId, Clock::db(), $userId, $userId]);
    }

    /** True when the file existed but is gone (expired or deleted) — to show «removed from server» instead of «not found». */
    public function isExpired(string $publicId): bool
    {
        return $this->db->scalar("SELECT 1 FROM attachments WHERE public_id = ? AND (upload_status IN ('expired','deleted')
                                   OR (upload_status = 'complete' AND retention_permanent = 0 AND expires_at <= ?))", [$publicId, Clock::db()]) !== null;
    }

    public function filePath(array $a): string
    {
        return $this->path($a['storage_key']);
    }

    /**
     * Deletes expired files and stale unfinished uploads. Never decrypts anything: only ciphertext is removed.
     * @return array<string, int>
     */
    public function cleanup(int $limit = 500): array
    {
        $now = Clock::db();
        $stats = ['files_deleted' => 0, 'bytes_freed' => 0, 'stale_uploads_deleted' => 0];
        foreach ($this->db->all("SELECT id, storage_key, size_bytes FROM attachments WHERE upload_status = 'complete' AND retention_permanent = 0
                                  AND expires_at <= ? LIMIT $limit", [$now]) as $a) {
            $this->db->run("UPDATE attachments SET upload_status = 'expired' WHERE id = ?", [$a['id']]);
            if (is_file($p = $this->path($a['storage_key']))) {
                @unlink($p);
                $stats['bytes_freed'] += (int)$a['size_bytes'];
            }
            $this->db->run("UPDATE attachments SET upload_status = 'deleted', deleted_at = ?, delete_reason = 'expired' WHERE id = ?", [Clock::db(), $a['id']]);
            $this->db->run('DELETE FROM attachment_grants WHERE attachment_id = ?', [$a['id']]);
            $stats['files_deleted']++;
        }
        $stale = Clock::dbIn('-' . self::STALE_UPLOAD_HOURS . ' hours');
        foreach ($this->db->all("SELECT id, storage_key FROM attachments WHERE upload_status IN ('initiated','uploading')
                                  AND COALESCE(last_chunk_at, created_at) < ? LIMIT $limit", [$stale]) as $a) {
            @unlink($this->path($a['storage_key'], true));
            $this->db->run('DELETE FROM upload_chunks WHERE attachment_id = ?', [$a['id']]);
            $this->db->run("UPDATE attachments SET upload_status = 'deleted', deleted_at = ?, delete_reason = 'aborted' WHERE id = ?", [Clock::db(), $a['id']]);
            $stats['stale_uploads_deleted']++;
        }
        foreach (glob($this->tmpDir() . '/chunk*') ?: [] as $t) {
            if (@filemtime($t) < time() - 86400) {
                @unlink($t);
            }
        }
        return $stats;
    }

    /** @return array<string, int|null> storage overview for the admin dashboard (no content) */
    public function stats(): array
    {
        $r = $this->db->one("SELECT
              COALESCE(SUM(CASE WHEN upload_status = 'complete' THEN size_bytes END), 0) AS stored,
              COALESCE(SUM(CASE WHEN upload_status = 'complete' AND retention_permanent = 1 THEN size_bytes END), 0) AS permanent,
              COALESCE(SUM(CASE WHEN upload_status = 'complete' AND retention_permanent = 0 AND expires_at <= ? THEN size_bytes END), 0) AS expired_pending,
              COALESCE(SUM(CASE WHEN upload_status IN ('initiated','uploading') THEN size_bytes END), 0) AS partial,
              SUM(upload_status = 'complete') AS files,
              COALESCE(SUM(CASE WHEN delete_reason = 'expired' THEN size_bytes END), 0) AS deleted_total
              FROM attachments", [Clock::db()]);
        return array_map('intval', $r) + ['free' => $this->freeBytes(), 'quota' => (int)$this->settings->get('storage.quota_bytes') ?: null];
    }
}
