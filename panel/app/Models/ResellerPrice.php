<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerPrice extends Model
{
    protected $fillable = ['reseller_id', 'package_id', 'price_rial'];

    protected function casts(): array
    {
        return ['price_rial' => 'integer'];
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class)->withTrashed();
    }
}
