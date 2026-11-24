<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $scope all|category|package
 * @property string $kind percent|amount
 * @property int $value
 */
class Discount extends Model
{
    public const SCOPES = ['package' => 'مخصوص یک بسته', 'category' => 'یک دسته', 'all' => 'همه بسته‌ها'];

    /** Lower number = more specific = wins. */
    public const SPECIFICITY = ['package' => 0, 'category' => 1, 'all' => 2];

    /** Mirror the DB defaults so freshly created models behave the same as reloaded ones. */
    protected $attributes = ['is_active' => true, 'priority' => 0];

    protected $fillable = ['name', 'scope', 'category_id', 'package_id', 'kind', 'value', 'priority', 'starts_at', 'ends_at', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'value' => 'integer', 'priority' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PackageCategory::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class)->withTrashed();
    }

    /** Active at $at: is_active, starts_at <= at (inclusive), at < ends_at (exclusive). */
    public function isLiveAt(\DateTimeInterface $at): bool
    {
        return $this->is_active
            && ($this->starts_at === null || $this->starts_at->getTimestamp() <= $at->getTimestamp())
            && ($this->ends_at === null || $at->getTimestamp() < $this->ends_at->getTimestamp());
    }

    public function describe(): string
    {
        return $this->kind === 'percent' ? fa($this->value).'٪' : fa_money($this->value);
    }
}
