<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the permissions table and the super-admin role in sync with config/permissions.php.
 * Idempotent: safe to run on every install/upgrade.
 */
class PermissionRegistry
{
    /** @return list<string> all permission names defined in config */
    public static function names(): array
    {
        $out = [];
        foreach (config('permissions.modules') as $module => $def) {
            foreach ($def['actions'] as $action) {
                $out[] = "$module.$action";
            }
        }

        return $out;
    }

    /** @return array{created:int, removed:int} */
    public function sync(): array
    {
        return DB::transaction(function () {
            $wanted = [];
            foreach (config('permissions.modules') as $module => $def) {
                foreach ($def['actions'] as $action) {
                    $wanted["$module.$action"] = ['module' => $module, 'action' => $action];
                }
            }
            $existing = Permission::query()->pluck('id', 'name')->all();

            $created = 0;
            foreach ($wanted as $name => $attrs) {
                if (! isset($existing[$name])) {
                    Permission::query()->create(['name' => $name] + $attrs);
                    $created++;
                }
            }
            $obsolete = array_diff(array_keys($existing), array_keys($wanted));
            $removed = $obsolete ? Permission::query()->whereIn('name', $obsolete)->delete() : 0;

            $super = Role::query()->firstOrNew(['name' => config('permissions.super_role')]);
            $super->title = 'مدیرکل';
            $super->description = 'دسترسی کامل به همه بخش‌ها. قابل حذف یا ویرایش نیست.';
            $super->is_system = true;
            $super->save();

            return ['created' => $created, 'removed' => $removed];
        });
    }
}
