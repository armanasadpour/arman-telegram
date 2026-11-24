<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sellable package. Prices live here (not in IBSng); "new" packages map to a real IBSng group.
 *
 * @property string $type new|renewal|topup
 * @property string|null $volume_gb
 * @property int|null $duration_days
 * @property int $price_rial
 */
class Package extends Model
{
    use SoftDeletes;

    public const TYPES = ['new' => 'اکانت جدید', 'renewal' => 'تمدید', 'topup' => 'افزایش حجم'];

    /** Mirror the DB defaults so freshly created models behave the same as reloaded ones. */
    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    protected $fillable = ['category_id', 'name', 'type', 'volume_gb', 'duration_days', 'multi_login', 'ibs_group_name', 'price_rial', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'price_rial' => 'integer', 'duration_days' => 'integer', 'multi_login' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PackageCategory::class, 'category_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(IbsGroup::class, 'ibs_group_name', 'name');
    }

    public function hasVolume(): bool
    {
        return $this->volume_gb !== null && (float) $this->volume_gb > 0;
    }

    public function volumeMb(): ?int
    {
        return $this->hasVolume() ? (int) round((float) $this->volume_gb * 1024) : null;
    }

    /** زمانی | حجمی | ترکیبی */
    public function kindLabel(): string
    {
        return match (true) {
            $this->hasVolume() && $this->duration_days => 'ترکیبی',
            $this->hasVolume() => 'حجمی',
            default => 'زمانی',
        };
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** Human summary, e.g. "۳۰ روز، ۲۰ گیگابایت، ۲ اتصال". */
    public function summary(): string
    {
        $parts = [];
        if ($this->duration_days) {
            $parts[] = fa($this->duration_days).' روز';
        }
        $parts[] = $this->hasVolume() ? fa_bytes((int) ((float) $this->volume_gb * 1073741824)) : ($this->type === 'topup' ? '' : 'حجم نامحدود');
        if ($this->multi_login) {
            $parts[] = fa($this->multi_login).' اتصال';
        }

        return implode('، ', array_filter($parts));
    }
}
