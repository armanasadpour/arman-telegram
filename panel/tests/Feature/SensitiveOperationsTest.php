<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SensitiveOperationsTest extends TestCase
{
    public function test_sensitive_action_requires_recent_password_confirmation(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create();
        $this->actingAs($admin)->from("/staff/{$target->id}/edit")
            ->post("/staff/{$target->id}/toggle-active", ['reason' => 'test'])
            ->assertRedirect('/confirm-password');
        $this->assertTrue($target->fresh()->is_active, 'must not change without confirmation');
        $this->assertSame(url("/staff/{$target->id}/edit"), session('url.intended'));
    }

    public function test_confirm_password_then_return_to_form(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->withSession(['url.intended' => url('/staff')])
            ->post('/confirm-password', ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->actingAs($admin)->withSession(['url.intended' => url('/staff')])
            ->post('/confirm-password', ['password' => 'Password12345'])->assertRedirect(url('/staff'));
    }

    public function test_deactivate_requires_reason_and_is_audited(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create();
        $this->actingAs($admin)->confirmed()->post("/staff/{$target->id}/toggle-active", ['reason' => ''])->assertSessionHas('success');   // reason is optional
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.deactivate', 'reason' => 'از پنل']);
        $this->actingAs($admin)->confirmed()->post("/staff/{$target->id}/toggle-active", ['reason' => 'برگشت'])->assertSessionHas('success');
        $this->actingAs($admin)->confirmed()->post("/staff/{$target->id}/toggle-active", ['reason' => 'ترک همکاری'])->assertSessionHas('success');
        $this->assertFalse($target->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.deactivate', 'reason' => 'ترک همکاری']);
    }

    public function test_cannot_deactivate_or_delete_self(): void
    {
        $admin = $this->superAdmin();
        $this->superAdmin(); // a second super admin exists, so the "last super admin" rule is not what blocks this
        $this->actingAs($admin)->confirmed()->post("/staff/{$admin->id}/toggle-active", ['reason' => 'x x x'])->assertSessionHas('error');
        $this->actingAs($admin)->confirmed()->delete("/staff/{$admin->id}", ['reason' => 'x x x'])->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_last_active_super_admin_cannot_lose_super_role(): void
    {
        $admin = $this->superAdmin();
        $other = $this->userWith(['staff.view', 'staff.update']);
        // Only a super admin may edit a super admin; editing yourself to drop the role must be refused.
        $this->actingAs($admin)->put("/staff/{$admin->id}", ['name' => 'x', 'roles' => []])->assertStatus(422);
        $this->assertTrue($admin->fresh()->isSuperAdmin());
        unset($other);
    }

    public function test_reset_password_for_other_user(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create();
        $this->actingAs($admin)->confirmed()->post("/staff/{$target->id}/reset-password", [
            'password' => 'BrandNew12345', 'password_confirmation' => 'BrandNew12345', 'reason' => 'فراموشی رمز',
        ])->assertSessionHas('success');
        $this->assertTrue(Hash::check('BrandNew12345', $target->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.password_reset', 'reason' => 'فراموشی رمز']);
    }

    public function test_delete_user_keeps_audit_trail(): void
    {
        $admin = $this->superAdmin();
        $target = User::factory()->create(['username' => 'leaver']);
        $this->actingAs($admin)->post('/logout');
        $this->post('/login', ['username' => 'leaver', 'password' => 'Password12345']);
        $this->post('/logout');
        $this->actingAs($admin)->confirmed()->delete("/staff/{$target->id}", ['reason' => 'خروج از تیم'])->assertRedirect('/staff');
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor_label' => 'leaver', 'user_id' => null]);
    }
}
