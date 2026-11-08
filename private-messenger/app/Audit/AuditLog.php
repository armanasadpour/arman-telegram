<?php
declare(strict_types=1);

namespace PM\Audit;

use PM\Infra\Database;
use PM\Support\Clock;
use PM\Support\Ip;

/**
 * Append-only, hash-chained audit trail of administrative actions. Never contains message content,
 * passwords, OTP codes or tokens.
 */
final class AuditLog
{
    public function __construct(private readonly Database $db)
    {
    }

    public function record(
        string $actorType,
        ?int $actorId,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        array $details = [],
        ?string $ip = null,
    ): void {
        $lock = (int)$this->db->scalar("SELECT GET_LOCK('pm_audit', 10)");
        if ($lock !== 1) {
            throw new \RuntimeException('audit lock timeout');
        }
        try {
            $prev = $this->db->scalar('SELECT entry_hash FROM audit_logs ORDER BY id DESC LIMIT 1') ?? str_repeat("\0", 32);
            $createdAt = Clock::db();
            $detailsJson = $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ipBin = $ip !== null ? Ip::toBinary($ip) : null;
            $hash = self::hash($prev, $actorType, $actorId, $action, $targetType, $targetId, $details === [] ? null : $details, $createdAt);
            $this->db->run(
                'INSERT INTO audit_logs (actor_type, actor_id, action, target_type, target_id, details, ip, created_at, prev_hash, entry_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$actorType, $actorId, $action, $targetType, $targetId, $detailsJson, $ipBin, $createdAt, $prev, $hash]
            );
        } finally {
            $this->db->scalar("SELECT RELEASE_LOCK('pm_audit')");
        }
    }

    /** @return array{ok:bool, checked:int, broken_at:?int, head:string} */
    public function verify(): array
    {
        $prev = str_repeat("\0", 32);
        $n = 0;
        $st = $this->db->run('SELECT id, actor_type, actor_id, action, target_type, target_id, details, created_at, prev_hash, entry_hash FROM audit_logs ORDER BY id');
        while ($r = $st->fetch()) {
            $n++;
            // Hash a canonical (key-sorted) form: MySQL 8 normalizes JSON columns, MariaDB keeps the text.
            $details = $r['details'] === null ? null : json_decode((string)$r['details'], true);
            $expected = self::hash($prev, $r['actor_type'], $r['actor_id'] === null ? null : (int)$r['actor_id'], $r['action'],
                $r['target_type'], $r['target_id'] === null ? null : (int)$r['target_id'], $details, self::normTime((string)$r['created_at']));
            if (!hash_equals($r['prev_hash'], $prev) || !hash_equals($expected, $r['entry_hash'])) {
                return ['ok' => false, 'checked' => $n, 'broken_at' => (int)$r['id'], 'head' => bin2hex($prev)];
            }
            $prev = $r['entry_hash'];
        }
        return ['ok' => true, 'checked' => $n, 'broken_at' => null, 'head' => bin2hex($prev)];
    }

    private static function normTime(string $t): string
    {
        return (new \DateTimeImmutable($t, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }

    private static function canonical(mixed $v): mixed
    {
        if (is_array($v)) {
            if (!array_is_list($v)) {
                ksort($v, SORT_STRING);
            }
            return array_map([self::class, 'canonical'], $v);
        }
        return $v;
    }

    private static function hash(string $prev, string $actorType, ?int $actorId, string $action, ?string $tt, ?int $tid, ?array $details, string $createdAt): string
    {
        $canonical = json_encode([$actorType, $actorId, $action, $tt, $tid, self::canonical($details), $createdAt], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $prev . $canonical, true);
    }
}
