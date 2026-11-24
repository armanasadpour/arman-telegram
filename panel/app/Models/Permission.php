<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string $module
 * @property string $action
 */
class Permission extends Model
{
    protected $fillable = ['name', 'module', 'action'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function label(): string
    {
        $modules = config('permissions.modules');
        $actions = config('permissions.actions');

        return ($actions[$this->action] ?? $this->action).' '.($modules[$this->module]['title'] ?? $this->module);
    }
}
