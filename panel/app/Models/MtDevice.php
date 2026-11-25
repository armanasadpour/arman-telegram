<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Extra WireGuard device (2..N) of a MikroTik account. Same field names as the account's own (device 1)
 * WireGuard columns, so services can treat both alike.
 */
class MtDevice extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['wg_private_key', 'wg_preshared_key'];

    protected function casts(): array
    {
        return ['wg_private_key' => 'encrypted', 'wg_preshared_key' => 'encrypted', 'counters' => 'array', 'is_online' => 'boolean', 'last_seen_at' => 'datetime', 'idx' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MtAccount::class, 'mt_account_id')->withTrashed();
    }

    public function deviceNo(): int
    {
        return $this->idx;
    }
}
