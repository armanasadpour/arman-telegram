<?php

namespace App\Models;

use App\Services\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Cached mirror of an IBSng account (IBSng is the source of truth).
 *
 * @property int $ibs_user_id
 * @property string $username
 * @property string|null $group_name
 * @property string $credit
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_synced_at
 */
class VpnAccount extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'locked' => 'boolean', 'is_online' => 'boolean', 'sessions' => 'array',
            'expires_at' => 'datetime', 'last_synced_at' => 'datetime', 'ibs_deleted_at' => 'datetime',
            'volume_total_bytes' => 'integer',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(IbsGroup::class, 'group_name', 'name');
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function charge(): ?IbsCharge
    {
        return $this->group?->charge;
    }

    public function effectiveRelExpSeconds(): ?int
    {
        return $this->rel_exp_seconds ?? $this->group?->rel_exp_seconds;
    }

    public function effectiveMultiLogin(): ?int
    {
        return $this->multi_login ?? $this->group?->multi_login;
    }

    public function isVolumeLimited(): bool
    {
        return (bool) $this->charge()?->isVolume();
    }

    /** Remaining traffic in bytes for volume-limited accounts; null when unlimited/unknown. */
    public function remainingBytes(): ?int
    {
        $bpc = $this->charge()?->bytesPerCredit();

        return $bpc === null ? null : (int) max(0, floor((float) $this->credit * $bpc));
    }

    public function usedBytes(): ?int
    {
        $rem = $this->remainingBytes();

        return ($rem !== null && $this->volume_total_bytes !== null) ? max(0, $this->volume_total_bytes - $rem) : null;
    }

    /** Operator comment without the internal "panel:<job-key>" tracking prefix. */
    public function displayComment(): ?string
    {
        $c = trim((string) preg_replace('/^panel:[0-9a-f-]{36}\s*/', '', (string) $this->comment));

        return $c === '' ? null : $c;
    }

    public function isStale(): bool
    {
        $minutes = (int) app(Settings::class)->get('sync.stale_minutes', '30');

        return $this->last_synced_at === null || $this->last_synced_at->lt(now()->subMinutes($minutes));
    }

    /** active | not_started | expired | depleted | locked | deleted */
    public function status(): string
    {
        if ($this->ibs_deleted_at) {
            return 'deleted';
        }
        if ($this->locked) {
            return 'locked';
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'expired';
        }
        if ($this->isVolumeLimited() && (float) $this->credit <= 0) {
            return 'depleted';
        }
        if ($this->first_login_ibs === null && $this->expires_at === null) {
            return 'not_started';
        }

        return 'active';
    }

    public const STATUS_LABELS = [
        'active' => ['فعال', 'success'], 'not_started' => ['شروع‌نشده', 'primary'], 'expired' => ['منقضی', 'danger'],
        'depleted' => ['حجم تمام‌شده', 'danger'], 'locked' => ['غیرفعال (قفل)', 'warning'], 'deleted' => ['حذف‌شده در سرور', 'danger'],
    ];
}
