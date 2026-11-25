<?php

namespace Tests\Feature\Mikrotik;

use App\Models\AuditLog;
use App\Models\MtAccount;
use App\Models\MtGroup;
use App\Models\Package;
use App\Services\Mikrotik\MtProvisioning;
use Illuminate\Support\Str;

class MtHttpTest extends MikrotikTestCase
{
    public function test_admin_creates_wireguard_group_reading_the_router(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/mt/groups')->assertOk()->assertSee('هرنگ')->assertSee('+ گروه WireGuard');
        $this->actingAs($admin)->get('/mt/groups/create?router='.$this->router->id.'&protocol=wireguard')->assertOk()->assertSee('wg1');
        $this->actingAs($admin)->post('/mt/groups', ['router_id' => $this->router->id, 'protocol' => 'wireguard', 'name' => 'WG دوم', 'wg_interface' => 'wg1',
            'rate_limit' => '10M/20M', 'start_on_first_use' => 1, 'is_active' => 1])->assertRedirect('/mt/groups');
        $g = MtGroup::query()->where('name', 'WG دوم')->firstOrFail();
        $this->assertSame(['10.20.0.0/24', '10.20.0.1', $this->serverPub, 13231], [$g->wg_network, $g->wg_server_ip, $g->wg_server_public_key, $g->wg_listen_port]);
    }

    public function test_l2tp_group_creates_rate_limited_profile_and_reads_psk(): void
    {
        $this->ros->menus['interface/l2tp-server/server'] = [['enabled' => 'true', 'use-ipsec' => 'required', 'ipsec-secret' => 'ROUTER-PSK']];
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post('/mt/groups', ['router_id' => $this->router->id, 'protocol' => 'l2tp', 'name' => 'L2TP ۱۰ مگ', 'ppp_profile' => 'l2tp-profile',
            'new_profile' => 'panel-10M', 'new_profile_rate' => '10M/10M', 'multi_login' => 2, 'is_active' => 1])->assertRedirect('/mt/groups');
        $p = $this->ros->find('ppp/profile', 'name', 'panel-10M');
        $this->assertSame(['10M/10M', 'l2tp-pool', '10.10.0.1'], [$p['rate-limit'], $p['remote-address'], $p['local-address']]);
        $g = MtGroup::query()->where('name', 'L2TP ۱۰ مگ')->firstOrFail();
        $this->assertSame(['panel-10M', 'ROUTER-PSK', 2], [$g->ppp_profile, $g->ipsec_psk, $g->multi_login]);
    }

    public function test_create_account_form_reveals_credentials_once_then_needs_confirmation(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/mt/accounts/create')->assertOk()->assertSee('L2TP ایران')->assertSee('حجم و روز دلخواه');
        $res = $this->actingAs($admin)->post('/mt/accounts', ['job_key' => (string) Str::uuid(), 'mt_group_id' => $this->wg->id, 'username' => 'shop.ali', 'days' => 45, 'volume_gb' => 15]);
        $a = MtAccount::query()->firstOrFail();
        $res->assertRedirect(route('mt.accounts.show', $a));
        $this->followRedirects($res)->assertSee('PrivateKey')->assertSee('<svg', false)->assertSee('۴۵ روز');
        $this->assertSame([45, 15 * 1073741824], [$a->duration_days, $a->volume_limit_bytes]);

        $this->actingAs($admin)->get(route('mt.accounts.show', $a))->assertOk()->assertDontSee('PrivateKey');
        $this->flushSession();
        $this->actingAs($admin)->get(route('mt.accounts.connect', $a))->assertRedirect('/confirm-password');
        $this->actingAs($admin)->confirmed()->get(route('mt.accounts.connect', $a))->assertOk()->assertSee('PrivateKey');
        $this->assertStringNotContainsString($a->wg_private_key, AuditLog::query()->get()->toJson());
    }

    public function test_permissions(): void
    {
        $a = app(MtProvisioning::class)->create($this->l2tp, (string) Str::uuid(), ['username' => 'perm1', 'password' => 'Secret-77'], null);
        $viewer = $this->userWith(['mt_accounts.view']);
        $this->actingAs($viewer)->get('/mt/accounts')->assertOk()->assertSee('perm1');
        $this->actingAs($viewer)->get(route('mt.accounts.show', $a))->assertOk()->assertDontSee('Secret-77');
        $this->actingAs($viewer)->confirmed()->get(route('mt.accounts.connect', $a))->assertForbidden();
        $this->actingAs($viewer)->post(route('mt.accounts.renew', $a), ['days' => 5])->assertForbidden();
        $this->actingAs($viewer)->get('/mt/accounts/create')->assertForbidden();
        $this->actingAs($viewer)->get('/mt/groups')->assertForbidden();
        $this->actingAs($viewer)->confirmed()->delete(route('mt.accounts.destroy', $a), ['reason' => 'xxx'])->assertForbidden();
        $this->actingAs($this->userWith([]))->get('/mt/accounts')->assertForbidden();
    }

    public function test_actions_from_the_account_page(): void
    {
        $admin = $this->superAdmin();
        $a = app(MtProvisioning::class)->create($this->l2tp, (string) Str::uuid(), ['username' => 'act1', 'days' => 30, 'volume_gb' => 5], null);
        $this->actingAs($admin)->from(route('mt.accounts.show', $a))->post(route('mt.accounts.volume', $a), ['gb' => 5])->assertSessionHas('success');
        $this->assertSame(10 * 1073741824, $a->fresh()->volume_limit_bytes);
        $this->actingAs($admin)->from(route('mt.accounts.show', $a))->post(route('mt.accounts.toggle', $a), ['reason' => 'بدهی'])->assertSessionHas('success');
        $this->assertSame('true', $this->ros->find('ppp/secret', 'name', 'act1')['disabled']);
        $this->actingAs($admin)->from(route('mt.accounts.show', $a))->post(route('mt.accounts.toggle', $a))->assertSessionHas('success');
        $this->assertSame('false', $this->ros->find('ppp/secret', 'name', 'act1')['disabled']);
        $this->actingAs($admin)->confirmed()->from(route('mt.accounts.show', $a))->post(route('mt.accounts.password', $a), ['password' => 'neu-pass'])->assertSessionHas('success');
        $this->assertSame('neu-pass', $this->ros->find('ppp/secret', 'name', 'act1')['password']);
        $this->actingAs($admin)->get(route('mt.accounts.show', $a))->assertOk();
        $this->actingAs($admin)->confirmed()->delete(route('mt.accounts.destroy', $a), ['reason' => 'تست حذف'])->assertRedirect('/mt/accounts');
        $this->assertNull($this->ros->find('ppp/secret', 'name', 'act1'));
        $this->actingAs($admin)->get(route('mt.accounts.show', $a))->assertOk()->assertSee('حذف‌شده');
    }

    public function test_mikrotik_packages_in_package_form(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/packages/create')->assertOk()->assertSee('گروه میکروتیک');
        $this->actingAs($admin)->post('/packages', ['name' => 'WG ۳۰ روز', 'type' => 'new', 'protocol' => 'mikrotik', 'price_toman' => 50000, 'duration_days' => 30, 'is_active' => 1])
            ->assertSessionHasErrors('mt_group_id');
        $this->actingAs($admin)->post('/packages', ['name' => 'WG ۳۰ روز', 'type' => 'new', 'protocol' => 'mikrotik', 'mt_group_id' => $this->wg->id, 'price_toman' => 50000,
            'duration_days' => 30, 'is_active' => 1])->assertRedirect('/packages');
        $p = Package::query()->where('name', 'WG ۳۰ روز')->firstOrFail();
        $this->assertSame(['mikrotik', $this->wg->id, null], [$p->protocol, $p->mt_group_id, $p->ibs_group_name]);
        $this->actingAs($admin)->get('/packages')->assertOk()->assertSee('WireGuard');
        $this->actingAs($admin)->get('/mt/accounts/create')->assertOk()->assertSee('WG ۳۰ روز');
        $res = $this->actingAs($admin)->post('/mt/accounts/from-package', ['job_key' => (string) Str::uuid(), 'package_id' => $p->id]);
        $a = MtAccount::query()->firstOrFail();
        $this->assertSame([$p->id, 30, null], [$a->package_id, $a->duration_days, $a->volume_limit_bytes]);
        $res->assertRedirect(route('mt.accounts.show', $a));
    }
}
