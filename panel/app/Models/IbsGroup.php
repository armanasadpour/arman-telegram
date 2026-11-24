<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $name
 * @property int|null $rel_exp_seconds
 * @property int|null $multi_login
 * @property string|null $charge_name
 */
class IbsGroup extends Model
{
    protected $fillable = ['name', 'ibs_group_id', 'rel_exp_seconds', 'multi_login', 'charge_name', 'comment', 'raw_attrs', 'present', 'synced_at'];

    protected function casts(): array
    {
        return ['raw_attrs' => 'array', 'present' => 'boolean', 'synced_at' => 'datetime'];
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(IbsCharge::class, 'charge_name', 'name');
    }
}
