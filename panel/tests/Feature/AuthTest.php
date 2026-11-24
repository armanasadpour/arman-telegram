<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('ورود به پنل');
    }

    public function test_login_success_records_audit_and_last_login(): void
    {
        $u = $this->superAdmin(['username' => 'boss']);
        $this->post('/login', ['username' => 'boss', 'password' => 'Password12345'])->assertRedirect('/');
        $this->assertAuthenticatedAs($u);
        $this->assertNotNull($u->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $u->id]);
    }

    public function test_wrong_password_and_unknown_user_give_same_message(): void
    {
        $this->superAdmin(['username' => 'boss']);
        $a = $this->post('/login', ['username' => 'boss', 'password' => 'nope'])->assertSessionHasErrors('username');
        $b = $this->post('/login', ['username' => 'ghost', 'password' => 'nope'])->assertSessionHasErrors('username');
        $this->assertSame(session('errors')->first('username'), 'نام کاربری یا رمز عبور نادرست است.');
        $this->assertGuest();
        $this->assertSame(2, AuditLog::query()->where('action', 'auth.login_failed')->count());
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::factory()->inactive()->create(['username' => 'off']);
        $this->post('/login', ['username' => 'off', 'password' => 'Password12345'])->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_blocked']);
    }

    public function test_lockout_after_five_failures_even_with_correct_password(): void
    {
        $this->superAdmin(['username' => 'boss']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'boss', 'password' => 'bad'.$i]);
        }
        $this->post('/login', ['username' => 'boss', 'password' => 'Password12345'])->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->assertStringContainsString('تلاش‌های ناموفق زیاد', session('errors')->first('username'));
        RateLimiter::clear('login:boss|127.0.0.1');
    }

    public function test_deactivated_user_is_logged_out_on_next_request(): void
    {
        $u = $this->superAdmin();
        $this->actingAs($u)->get('/')->assertOk();
        $u->forceFill(['is_active' => false])->save();
        $this->actingAs($u->fresh())->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_logout(): void
    {
        $u = $this->superAdmin();
        $this->actingAs($u)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout']);
    }

    public function test_change_password_requires_current_and_strong_password(): void
    {
        $u = $this->superAdmin();
        $this->actingAs($u)->put('/profile/password', ['current_password' => 'wrong', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])
            ->assertSessionHasErrors('current_password');
        $this->actingAs($u)->put('/profile/password', ['current_password' => 'Password12345', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->actingAs($u)->put('/profile/password', ['current_password' => 'Password12345', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('NewPassword123', $u->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.password_changed']);
        $this->assertStringNotContainsString('NewPassword123', AuditLog::query()->get()->toJson());
    }

    public function test_security_headers_present(): void
    {
        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy');
    }
}
