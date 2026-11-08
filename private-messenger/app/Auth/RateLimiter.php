<?php
declare(strict_types=1);

namespace PM\Auth;

use PM\Infra\Database;
use PM\Support\Clock;

/** Fixed-window counters stored in MySQL (works on shared hosting without Redis/APCu). */
final class RateLimiter
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Counts a hit and returns true while the bucket is within $limit for the current window. */
    public function hit(string $bucket, int $limit, int $windowSeconds): bool
    {
        [$hash, $start] = $this->key($bucket, $windowSeconds);
        $this->db->run(
            'INSERT INTO rate_limits (bucket_hash, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1',
            [$hash, $start]
        );
        return $this->count($bucket, $windowSeconds) <= $limit;
    }

    public function count(string $bucket, int $windowSeconds): int
    {
        [$hash, $start] = $this->key($bucket, $windowSeconds);
        return (int)($this->db->scalar('SELECT hits FROM rate_limits WHERE bucket_hash = ? AND window_start = ?', [$hash, $start]) ?? 0);
    }

    public function exceeded(string $bucket, int $limit, int $windowSeconds): bool
    {
        return $this->count($bucket, $windowSeconds) >= $limit;
    }

    public function purge(): int
    {
        return $this->db->run('DELETE FROM rate_limits WHERE window_start < ?', [Clock::db(Clock::now()->modify('-1 day'))])->rowCount();
    }

    /** @return array{string, string} */
    private function key(string $bucket, int $windowSeconds): array
    {
        $now = Clock::now()->getTimestamp();
        $start = gmdate('Y-m-d H:i:s', $now - ($now % $windowSeconds));
        return [hash('sha256', $bucket . '|' . $windowSeconds, true), $start];
    }
}
