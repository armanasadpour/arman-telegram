<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerCategoryDiscount extends Model
{
    protected $fillable = ['reseller_id', 'category_id', 'percent'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(PackageCategory::class);
    }
}
