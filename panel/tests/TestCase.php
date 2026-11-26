<?php

namespace Tests;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->make(PermissionRegistry::class)->sync();
        // The panel ships with password re-confirmation off; the confirmation flow itself stays tested.
        config(['panel.require_password_confirmation' => true]);
    }

    protected function superAdmin(array $attrs = []): User
    {
        $u = User::factory()->create($attrs);
        $u->roles()->attach(Role::query()->where('name', 'super_admin')->value('id'));

        return $u->fresh();
    }

    /** User with a role holding exactly the given permissions. */
    protected function userWith(array $permissions, array $attrs = []): User
    {
        $role = Role::query()->create(['name' => 'r_'.uniqid(), 'title' => 'نقش آزمایشی']);
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));
        $u = User::factory()->create($attrs);
        $u->roles()->attach($role->id);

        return $u->fresh();
    }

    /** Mark the password as recently confirmed for sensitive routes. */
    protected function confirmed(): static
    {
        return $this->withSession(['auth.password_confirmed_at' => time()]);
    }
}
