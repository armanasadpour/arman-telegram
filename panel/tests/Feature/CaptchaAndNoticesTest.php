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
        $this->get('/login')->assertOk()->assertSee('کد امنیتی')->assertSee('src="data:image/', false)->assertDontSee('onclick=', false);
        $img = $this->getJson('/login/captcha')->assertOk()->json('img');
        $this->assertStringStartsWith('data:image/png;base64,', $img);
        $this->assertSame("\x89PNG", substr(base64_decode(substr($img, 22)), 0, 4));
        $this->assertStringStartsWith('<svg', Captcha::svg('23456'));   // GD-less fallback

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
        $this->get('/login/captcha')->assertNotFound();
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

    public function test_test_notice_pops_up_right_away(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/notices')->assertSee('ارسال اعلان آزمایشی');
        $res = $this->actingAs($admin)->from('/notices')->post('/notices/test')->assertSessionHas('notice_test');
        $id = (int) PanelNotice::query()->max('id');
        $this->actingAs($admin)->withSession(['notice_test' => $id])->get('/notices')->assertSee('data-poll-now', false)->assertSee('data-last="'.($id - 1).'"', false);
        $this->actingAs($admin)->getJson('/notices/poll?after='.($id - 1))->assertJsonPath('new.0.title', '🔔 اعلان آزمایشی');
        $this->actingAs($this->userWith([]))->post('/notices/test')->assertForbidden();
    }
}
