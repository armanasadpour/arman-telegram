<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackageCategory extends Model
{
    /** Mirror the DB defaults so freshly created models behave the same as reloaded ones. */
    protected $attributes = ['is_active' => true, 'sort_order' => 0];

    protected $fillable = ['name', 'description', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class, 'category_id');
    }
}
