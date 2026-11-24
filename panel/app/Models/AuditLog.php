<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit record. Never updated or deleted by the application;
 * integrity is verifiable through the SHA-256 hash chain (see AuditLogger::verifyChain).
 *
 * @property int $id
 * @property string $action
 * @property array|null $changes
 * @property string $hash
 * @property string|null $prev_hash
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Audit log records are immutable.');
        });
        static::deleting(function () {
            throw new LogicException('Audit log records cannot be deleted.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Fields covered by the hash, in a fixed order. */
    public function hashPayload(): string
    {
        return json_encode([
            $this->user_id,
            $this->actor_label,
            $this->action,
            $this->subject_type,
            $this->subject_id,
            $this->description,
            $this->changes,
            $this->reason,
            $this->ip,
            $this->user_agent,
            $this->created_at?->utc()->format('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
