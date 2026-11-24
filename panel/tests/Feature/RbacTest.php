<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class RbacTest extends TestCase
{
    public function test_user_without_permissions_gets_403_everywhere_and_sees_no_menu(): void
    {
        $u = $this->userWith([]);
        foreach (['/', '/staff', '/staff/create', '/roles', '/audit', '/settings', '/system'] as $url) {
            $this->actingAs($u)->get($url)->assertForbidden();
        }
        $this->actingAs($u)->get('/profile/password')->assertOk()
            ->assertDontSee('کاربران پنل')->assertDontSee('گزارش رویدادها');
    }

    public function test_view_permission_does_not_grant_create_or_update(): void
    {
        $u = $this->userWith(['staff.view']);
        $target = User::factory()->create();
        $this->actingAs($u)->get('/staff')->assertOk()->assertDontSee('کاربر جدید');
        $this->actingAs($u)->get('/staff/create')->assertForbidden();
        $this->actingAs($u)->post('/staff', [])->assertForbidden();
        $this->actingAs($u)->put("/staff/{$target->id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($u)->get('/roles')->assertForbidden();
    }

    public function test_super_admin_has_every_permission(): void
    {
        $u = $this->superAdmin();
        foreach (['/', '/staff', '/staff/create', '/roles', '/roles/create', '/audit', '/settings', '/system'] as $url) {
            $this->actingAs($u)->get($url)->assertOk();
        }
    }

    public function test_staff_manager_can_create_user_with_role(): void
    {
        $u = $this->userWith(['staff.view', 'staff.create']);
        $role = Role::query()->create(['name' => 'ops', 'title' => 'اپراتور']);
        $this->actingAs($u)->post('/staff', [
            'name' => 'علی', 'username' => 'ali.op', 'password' => 'Password12345', 'password_confirmation' => 'Password12345',
            'roles' => [$role->id],
        ])->assertRedirect('/staff');
        $ali = User::query()->where('username', 'ali.op')->firstOrFail();
        $this->assertTrue($ali->roles->contains($role));
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.create', 'subject_id' => (string) $ali->id]);
    }

    public function test_non_super_cannot_grant_super_role_or_touch_super_admin(): void
    {
        $u = $this->userWith(['staff.view', 'staff.create', 'staff.update', 'staff.sensitive', 'staff.delete']);
        $superRole = Role::query()->where('name', 'super_admin')->value('id');
        $this->actingAs($u)->post('/staff', [
            'name' => 'x', 'username' => 'sneaky', 'password' => 'Password12345', 'password_confirmation' => 'Password12345',
            'roles' => [$superRole],
        ])->assertForbidden();
        $this->assertDatabaseMissing('users', ['username' => 'sneaky']);

        $boss = $this->superAdmin();
        $this->actingAs($u)->get("/staff/{$boss->id}/edit")->assertForbidden();
        $this->actingAs($u)->confirmed()->post("/staff/{$boss->id}/toggle-active", ['reason' => 'test'])->assertForbidden();
        $this->actingAs($u)->confirmed()->delete("/staff/{$boss->id}", ['reason' => 'test'])->assertForbidden();
    }

    public function test_system_roles_cannot_be_edited_or_deleted(): void
    {
        $u = $this->superAdmin();
        $super = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->actingAs($u)->get("/roles/{$super->id}/edit")->assertForbidden();
        $this->actingAs($u)->confirmed()->delete("/roles/{$super->id}", ['reason' => 'test'])->assertForbidden();
    }

    public function test_role_permissions_take_effect_immediately(): void
    {
        $admin = $this->superAdmin();
        $role = Role::query()->create(['name' => 'auditor', 'title' => 'حسابرس']);
        $u = User::factory()->create();
        $u->roles()->attach($role);
        $this->actingAs($u)->get('/audit')->assertForbidden();

        $auditView = Permission::query()->where('name', 'audit.view')->value('id');
        $this->actingAs($admin)->put("/roles/{$role->id}", ['name' => 'auditor', 'title' => 'حسابرس', 'permissions' => [$auditView]])
            ->assertRedirect('/roles');
        $this->actingAs($u->fresh())->get('/audit')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'roles.update']);
    }

    public function test_role_in_use_cannot_be_deleted(): void
    {
        $admin = $this->superAdmin();
        $role = Role::query()->create(['name' => 'ops', 'title' => 'اپراتور']);
        User::factory()->create()->roles()->attach($role);
        $this->actingAs($admin)->confirmed()->delete("/roles/{$role->id}", ['reason' => 'cleanup'])->assertSessionHas('error');
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }
}
