<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * L2TP or WireGuard account on a MikroTik router. The panel owns the limits and the usage counters;
 * the router only holds the PPP secret / peer, which the panel disables when a limit is reached.
 *
 * @property string $status pending|active|disabled|expired|depleted|failed
 */
class MtAccount extends Model
{
    use SoftDeletes;

    public const STATUS = [
        'pending' => ['در حال ساخت', 'primary'], 'active' => ['فعال', 'success'], 'not_started' => ['شروع‌نشده', 'primary'],
        'disabled' => ['غیرفعال', 'warning'], 'expired' => ['منقضی', 'danger'], 'depleted' => ['حجم تمام‌شده', 'danger'],
        'failed' => ['ساخته نشد', 'danger'], 'deleted' => ['حذف‌شده', 'danger'],
    ];

    protected $guarded = ['id'];

    protected $hidden = ['password', 'wg_private_key', 'wg_preshared_key'];

    protected $attributes = ['status' => 'pending', 'used_bytes' => 0, 'upload_bytes' => 0, 'download_bytes' => 0, 'router_disabled' => false, 'is_online' => false, 'online_count' => 0];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'wg_private_key' => 'encrypted', 'wg_preshared_key' => 'encrypted', 'counters' => 'array',
            'router_disabled' => 'boolean', 'is_online' => 'boolean', 'expires_at' => 'datetime', 'first_used_at' => 'datetime',
            'last_seen_at' => 'datetime', 'last_synced_at' => 'datetime', 'alerted_at' => 'datetime',
            'volume_limit_bytes' => 'integer', 'used_bytes' => 'integer', 'upload_bytes' => 'integer', 'download_bytes' => 'integer',
            'duration_days' => 'integer', 'multi_login' => 'integer', 'online_count' => 'integer'];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(MtGroup::class, 'mt_group_id');
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function creatorLabel(): string
    {
        return $this->creator?->name ?? ($this->created_by ? '—' : 'ربات / خودکار');
    }

    public function botUsers(): BelongsToMany
    {
        return $this->belongsToMany(BotUser::class, 'bot_mt_links')->withPivot('created_at');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(MtDevice::class)->orderBy('idx');
    }

    /** WireGuard devices wanted for this account (stored in multi_login; at least 1). */
    public function deviceCount(): int
    {
        return $this->isWireguard() ? max(1, (int) ($this->multi_login ?? 1)) : 0;
    }

    /** Device 1 (this row) followed by the extra devices. @return list<MtAccount|MtDevice> */
    public function allDevices(): array
    {
        return $this->isWireguard() ? array_merge([$this], $this->devices->all()) : [];
    }

    public function deviceNo(): int
    {
        return 1;
    }

    public function isWireguard(): bool
    {
        return $this->protocol === 'wireguard';
    }

    public function protocolLabel(): string
    {
        return MtGroup::PROTOCOLS[$this->protocol] ?? $this->protocol;
    }

    public function isVolumeLimited(): bool
    {
        return $this->volume_limit_bytes !== null;
    }

    public function remainingBytes(): ?int
    {
        return $this->isVolumeLimited() ? max(0, $this->volume_limit_bytes - $this->used_bytes) : null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isDepleted(): bool
    {
        return $this->isVolumeLimited() && $this->used_bytes >= $this->volume_limit_bytes;
    }

    /** Should the router let this account connect? */
    public function shouldBeEnabled(): bool
    {
        return $this->status === 'active' && ! $this->isExpired() && ! $this->isDepleted();
    }

    /** Display status (adds "not_started" and "deleted"). */
    public function displayStatus(): string
    {
        if ($this->trashed()) {
            return 'deleted';
        }
        if ($this->status === 'active' && $this->duration_days && ! $this->expires_at) {
            return 'not_started';
        }

        return $this->status;
    }

    public function statusLabel(): array
    {
        return self::STATUS[$this->displayStatus()] ?? [$this->status, 'primary'];
    }

    /** @return array{pct:int, remaining:int, total:int, started:bool}|null */
    public function timeMeter(): ?array
    {
        $total = (int) ($this->duration_days ?? 0) * 86400;
        if (! $this->expires_at) {
            return $total > 0 ? ['pct' => 100, 'remaining' => $total, 'total' => $total, 'started' => false] : null;
        }
        $remaining = max(0, $this->expires_at->getTimestamp() - now()->getTimestamp());
        $total = max($total, $remaining, 1);

        return ['pct' => (int) min(100, max(0, round($remaining * 100 / $total))), 'remaining' => $remaining, 'total' => $total, 'started' => true];
    }

    /** @return array{pct:int, remaining:int, total:int, used:int}|null */
    public function volumeMeter(): ?array
    {
        if (! $this->isVolumeLimited()) {
            return null;
        }
        $total = max(1, (int) $this->volume_limit_bytes);
        $rem = (int) $this->remainingBytes();

        return ['pct' => (int) min(100, max(0, round($rem * 100 / $total))), 'remaining' => $rem, 'total' => $total, 'used' => min($total, $this->used_bytes)];
    }

    public function isStale(): bool
    {
        return $this->last_synced_at === null || $this->last_synced_at->lt(now()->subMinutes(15));
    }

    /** RouterOS comment that ties the secret/peer to this panel row. */
    public function rosComment(): string
    {
        return 'panel:'.$this->create_key.' '.$this->username;
    }
}
