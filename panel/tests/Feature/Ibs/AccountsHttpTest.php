<?php

namespace Tests\Feature\Ibs;

use App\Ibs\IbsUnavailableException;
use App\Models\AuditLog;
use App\Models\VpnAccount;
use App\Services\Ibs\AccountActions;
use App\Services\Ibs\AccountSync;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountsHttpTest extends IbsTestCase
{
    private function seedAccount(): VpnAccount
    {
        $this->syncCatalog();
        $this->ibsUser('ali');

        return app(AccountSync::class)->importByUsername('ali');
    }

    public function test_permissions_on_account_pages(): void
    {
        $a = $this->seedAccount();
        $nobody = $this->userWith([]);
        $viewer = $this->userWith(['accounts.view']);
        $this->actingAs($nobody)->get('/accounts')->assertForbidden();
        $this->actingAs($viewer)->get('/accounts')->assertOk()->assertSee('ali')->assertDontSee('ساخت اکانت');
        $this->actingAs($viewer)->get("/accounts/{$a->id}")->assertOk()
            ->assertDontSee(route('accounts.password', $a))->assertDontSee(route('accounts.lock', $a))
            ->assertDontSee(route('accounts.reveal', $a))->assertDontSee('حذف از سرور');
        $this->actingAs($viewer)->get('/accounts/create')->assertForbidden();
        $this->actingAs($viewer)->post("/accounts/{$a->id}/lock", ['reason' => 'xyz'])->assertForbidden();
        $this->actingAs($viewer)->confirmed()->post("/accounts/{$a->id}/reveal")->assertForbidden();
    }

    public function test_full_page_walk_as_super_admin(): void
    {
        $a = $this->seedAccount();
        $admin = $this->superAdmin();
        foreach (['/accounts', '/accounts/create', "/accounts/{$a->id}", '/accounts/jobs', '/ibs/groups', '/settings/ibsng', '/', '/system'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_reveal_requires_recent_password_confirmation(): void
    {
        $a = $this->seedAccount();
        $admin = $this->superAdmin();
        $this->actingAs($admin)->from("/accounts/{$a->id}")->post("/accounts/{$a->id}/reveal")->assertRedirect('/confirm-password');
        $this->actingAs($admin)->confirmed()->from("/accounts/{$a->id}")->post("/accounts/{$a->id}/reveal")
            ->assertSessionHas('revealed_password', 'pw123456');
    }

    public function test_ibs_settings_save_encrypts_password_and_needs_confirmation(): void
    {
        $admin = $this->superAdmin();
        $payload = ['host' => '45.9.145.13', 'port' => 1235, 'username' => 'panel', 'password' => 'Ibs$ecret1', 'timeout' => 15,
            'renewal_mode' => 'smart', 'stale_minutes' => 30, 'prefix' => 'u'];
        $this->actingAs($admin)->put('/settings/ibsng', $payload)->assertRedirect('/confirm-password');
        $this->actingAs($admin)->confirmed()->put('/settings/ibsng', $payload)->assertSessionHas('success');

        $this->app->forgetInstance(Settings::class);
        $this->assertSame('Ibs$ecret1', app(Settings::class)->get('ibs.password'));
        $this->assertStringNotContainsString('Ibs$ecret1', (string) DB::table('settings')->where('key', 'ibs.password')->value('value'));
        $this->assertStringNotContainsString('Ibs$ecret1', AuditLog::query()->get()->toJson());
        $this->actingAs($admin)->get('/settings/ibsng')->assertDontSee('Ibs$ecret1');
    }

    public function test_test_connection_button(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post('/settings/ibsng/test')->assertSessionHas('ibs_test', fn ($r) => $r['ok'] && $r['groups'] === 3);
        app(Settings::class)->set('ibs.password', 'bad', encrypt: true);
        $this->actingAs($admin)->post('/settings/ibsng/test')->assertSessionHas('ibs_test', fn ($r) => ! $r['ok'] && str_contains($r['error'], 'نادرست'));
    }

    public function test_panel_keeps_working_when_ibsng_is_down(): void
    {
        $a = $this->seedAccount();
        $admin = $this->superAdmin();
        for ($i = 0; $i < 6; $i++) {
            $this->fake->failNext('user.getUserInfo', new IbsUnavailableException('down'));
        }
        $this->actingAs($admin)->get('/accounts')->assertOk()->assertSee('ali');           // served from the mirror
        $this->actingAs($admin)->from("/accounts/{$a->id}")->post("/accounts/{$a->id}/refresh")
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'سرور'));
        $this->actingAs($admin)->get("/accounts/{$a->id}")->assertOk();
    }

    public function test_import_one_and_lock_via_http(): void
    {
        $this->syncCatalog();
        $this->ibsUser('remote1');
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post('/accounts/import', ['username' => 'nope'])->assertSessionHas('error');
        $r = $this->actingAs($admin)->post('/accounts/import', ['username' => 'remote1']);
        $a = VpnAccount::query()->where('username', 'remote1')->firstOrFail();
        $r->assertRedirect(route('accounts.show', $a));
        $this->actingAs($admin)->from("/accounts/{$a->id}")->post("/accounts/{$a->id}/lock", ['reason' => 'test lock'])->assertSessionHas('success');
        $this->assertTrue($a->fresh()->locked);
    }

    public function test_deleted_username_can_be_reused_and_unimported_one_offers_import(): void
    {
        $this->syncCatalog();
        $admin = $this->superAdmin();
        $id = $this->ibsUser('hamid');
        $this->actingAs($admin)->get('/accounts?q=hamid')->assertOk()->assertSee('جستجوی «hamid» در سرور');
        $this->actingAs($admin)->from('/accounts/create')->post('/accounts', ['job_key' => (string) Str::uuid(), 'group' => 'test', 'username' => 'hamid'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'وارد کردن یک اکانت از سرور'));
        $this->actingAs($admin)->post('/accounts/import', ['username' => 'hamid']);
        $a = VpnAccount::query()->where('username', 'hamid')->firstOrFail();
        app(AccountActions::class)->delete($a, 'test');

        $this->actingAs($admin)->from('/accounts/create')->post('/accounts', ['job_key' => (string) Str::uuid(), 'group' => 'test', 'username' => 'hamid']);
        $new = VpnAccount::query()->where('username', 'hamid')->first();
        $this->assertNotNull($new, 'the name of a deleted account is free again');
        $this->assertNotSame($a->id, $new->id);
        $this->assertSame('hamid~'.$a->id, VpnAccount::onlyTrashed()->find($a->id)->username, 'history kept under a renamed copy');
    }

    public function test_ui_says_server_not_ibsng_except_on_connection_page(): void
    {
        $a = $this->seedAccount();
        $admin = $this->superAdmin();
        foreach (['/', '/accounts', '/accounts/create', "/accounts/{$a->id}", '/accounts/jobs', '/ibs/groups', '/system', '/packages', '/packages/create'] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $visible = str_replace('اتصال به سرور IBSng', '', strip_tags($html));
            $this->assertStringNotContainsString('IBSng', $visible, "IBSng visible on $url");
        }
        $this->actingAs($admin)->get('/settings/ibsng')->assertOk()->assertSee('اتصال به سرور IBSng');
    }
}
