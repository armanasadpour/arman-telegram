<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * Writes append-only, hash-chained audit records. Secrets never reach the log:
 * any field whose name looks sensitive is replaced with "***".
 */
class AuditLogger
{
    private const SECRET_PATTERN = '/pass|secret|token|private|api_?key|credential/i';

    /**
     * @param  array<string, array{0:mixed,1:mixed}>|null  $changes  field => [old, new]
     */
    public function log(
        string $action,
        ?Model $subject = null,
        ?string $description = null,
        ?array $changes = null,
        ?string $reason = null,
        ?User $actor = null,
        ?string $actorLabel = null,
    ): AuditLog {
        $actor ??= Auth::user();

        return DB::transaction(function () use ($action, $subject, $description, $changes, $reason, $actor, $actorLabel) {
            $prev = AuditLog::query()->orderByDesc('id')->lockForUpdate()->first(['id', 'hash']);

            $log = new AuditLog([
                'user_id' => $actor?->id,
                'actor_label' => mb_substr($actorLabel ?? $actor?->username ?? 'system', 0, 120),
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
                'description' => $description !== null ? mb_substr($description, 0, 500) : null,
                'changes' => $changes ? self::redact($changes) : null,
                'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
                'ip' => Request::ip(),
                'user_agent' => mb_substr((string) Request::userAgent(), 0, 255),
            ]);
            $log->created_at = now()->startOfSecond();
            $log->prev_hash = $prev?->hash;
            $log->hash = hash('sha256', ($prev?->hash ?? '').'|'.$log->hashPayload());
            $log->save();

            return $log;
        });
    }

    /**
     * Build a [old, new] diff of the given attributes between two arrays.
     *
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     * @return array<string, array{0:mixed,1:mixed}>
     */
    public static function diff(array $before, array $after): array
    {
        $out = [];
        foreach ($after as $k => $v) {
            $old = $before[$k] ?? null;
            if ($old != $v) {
                $out[$k] = [$old, $v];
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $data */
    public static function redact(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match(self::SECRET_PATTERN, $k)) {
                $data[$k] = is_array($v) && array_is_list($v) ? array_fill(0, count($v), '***') : '***';
            } elseif (is_array($v)) {
                $data[$k] = self::redact($v);
            }
        }

        return $data;
    }

    /**
     * Walk the whole chain. Returns null when intact, or the id of the first broken record.
     */
    public function verifyChain(): ?int
    {
        $prevHash = null;
        $broken = null;
        AuditLog::query()->orderBy('id')->chunk(500, function ($rows) use (&$prevHash, &$broken) {
            foreach ($rows as $row) {
                $expected = hash('sha256', ($prevHash ?? '').'|'.$row->hashPayload());
                if ($row->prev_hash !== $prevHash || ! hash_equals($expected, $row->hash)) {
                    $broken = $row->id;

                    return false;
                }
                $prevHash = $row->hash;
            }

            return true;
        });

        return $broken;
    }
}
