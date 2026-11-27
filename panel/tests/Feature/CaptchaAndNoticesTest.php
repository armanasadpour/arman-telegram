<?php

namespace Tests\Feature;

use App\Models\PanelNotice;
use App\Models\User;
use App\Services\Notices;
use App\Support\Captcha;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CaptchaAndNoticesTest extends TestCase
{
    private function user(): User
    {
        return User::query()->create(['name' => 'Boss', 'username' => 'boss', 'password' => Hash::make('Password12345'), 'is_active' => true]);
    }

    public function test_login_requires_the_captcha_code(): void
    {
        config(['panel.login_captcha' => true]);
        $this->user();
        $this->get('/login')->assertOk()->assertSee('کد امنیتی')->assertSee('captcha.png');
        $img = $this->get('/captcha.png')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame("\x89PNG", substr($img->getContent(), 0, 4));

        // missing / wrong code: refused before the password is even checked
        $this->post('/login', ['username' => 'boss', 'password' => 'Password12345'])->assertSessionHasErrors('captcha');
        $this->assertGuest();
        $code = Captcha::issue(session()->driver());
        $this->post('/login', ['username' => 'boss', 'password' => 'Password12345', 'captcha' => '11111'])->assertSessionHasErrors('captcha');
        // a code works once (the wrong try above consumed it)
        $this->post('/login', ['username' => 'boss', 'password' => 'Password12345', 'captcha' => $code])->assertSessionHasErrors('captcha');

        // right code, typed with Persian digits
        $code = Captcha::issue(session()->driver());
        $fa = strtr($code, ['2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
        $this->post('/login', ['username' => 'boss', 'password' => 'Password12345', 'captcha' => $fa])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_captcha_can_be_turned_off(): void
    {
        config(['panel.login_captcha' => false]);
        $this->user();
        $this->get('/login')->assertOk()->assertDontSee('کد امنیتی');
        $this->get('/captcha.png')->assertNotFound();
        $this->post('/login', ['username' => 'boss', 'password' => 'Password12345'])->assertRedirect('/');
    }

    public function test_notices_open_once_and_resolve(): void
    {
        $n = app(Notices::class);
        $this->assertTrue($n->raise('router:1', 'سرور «هرنگ» قطع است', 'timeout'));
        $this->assertFalse($n->raise('router:1', 'سرور «هرنگ» قطع است', 'refused'));   // still open: no duplicate
        $this->assertSame(1, PanelNotice::query()->count());
        $this->assertSame('refused', PanelNotice::query()->first()->body);

        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/accounts')->assertSee('down-banner', false)->assertSee('سرور «هرنگ» قطع است');
        $this->actingAs($admin)->getJson('/notices/poll?after=0')->assertJsonPath('unread', 1)->assertJsonPath('problems', 1);

        $this->assertTrue($n->resolve('router:1', 'سرور «هرنگ» دوباره وصل شد'));
        $this->assertFalse($n->resolve('router:1', 'x'));
        $first = (int) PanelNotice::query()->min('id');
        $this->actingAs($admin)->getJson('/notices/poll?after='.$first)->assertJsonPath('new.0.level', 'success')->assertJsonPath('problems', 0);

        $this->actingAs($admin)->get('/notices')->assertOk()->assertSee('دوباره وصل شد');
        $this->actingAs($admin)->getJson('/notices/poll')->assertJsonPath('unread', 0);   // page visit marks them seen
        $this->actingAs($admin)->get('/accounts')->assertDontSee('down-banner', false);

        $this->actingAs($this->userWith(['accounts.view']))->get('/notices')->assertForbidden();
    }

    public function test_pages_still_open_before_the_notices_migration_ran(): void
    {
        Schema::drop('panel_notices');
        Schema::table('users', fn ($t) => $t->dropColumn('notices_seen_at'));
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/system')->assertOk();
        $this->actingAs($admin)->get('/system/update')->assertOk();
        $this->actingAs($admin)->get('/')->assertOk();
    }
}
