<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $balance cached SUM(ledger_entries.amount), rial */
class Wallet extends Model
{
    protected $attributes = ['balance' => 0];

    protected $fillable = ['owner_type', 'owner_id'];

    protected function casts(): array
    {
        return ['balance' => 'integer'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
