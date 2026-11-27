<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $code
 * @property string $global_discount_percent
 * @property string $status active|suspended
 */
class Reseller extends Model
{
    protected $attributes = ['status' => 'active', 'global_discount_percent' => 0];

    /** Prefix rule: starts with a letter, lowercase letters / digits, 2–8 characters. */
    public const PREFIX_RULE = '/^[a-z][a-z0-9]{1,7}$/';

    /** Username suffix rule (the part the reseller types). */
    public const SUFFIX_RULE = '/^[a-z0-9][a-z0-9_.-]{1,23}$/';

    /** Full account username for a suffix the reseller typed (prefix + suffix, lowercase). */
    public function accountUsername(string $suffix): string
    {
        return mb_strtolower((string) $this->account_prefix).mb_strtolower(trim($suffix));
    }

    protected $fillable = ['user_id', 'code', 'name', 'phone', 'telegram', 'global_discount_percent', 'status', 'notes'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class, 'owner_id')->where('owner_type', 'reseller');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ResellerPrice::class);
    }

    public function categoryDiscounts(): HasMany
    {
        return $this->hasMany(ResellerCategoryDiscount::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(ResellerSale::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(VpnAccount::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
