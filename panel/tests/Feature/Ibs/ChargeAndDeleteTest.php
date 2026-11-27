<?php

namespace Tests\Feature\Ibs;

use App\Ibs\IbsException;
use App\Ibs\XmlRpcCodec;
use App\Models\IbsCharge;
use App\Models\IbsGroup;
use App\Models\VpnAccount;

class ChargeAndDeleteTest extends IbsTestCase
{
    public function test_admin_creates_volume_charge_then_a_volume_group(): void
    {
        $this->syncCatalog();
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/ibs/groups')->assertOk()->assertSee('ساخت تعرفه در سرور');
        $this->actingAs($admin)->post('/ibs/charges', ['charge_name' => 'volume', 'charge_type' => 'volume'])->assertRedirect('/ibs/groups')->assertSessionHas('success');
        $this->assertSame(1.0, $this->fake->charges['volume'][0]['cpk']);
        $this->assertTrue(IbsCharge::query()->where('name', 'volume')->firstOrFail()->isVolume());

        $this->actingAs($admin)->post('/ibs/groups', ['name' => 'vol-45d', 'days' => 30, 'multi_login' => 1, 'charge' => 'volume'])->assertSessionHas('success');
        $this->assertTrue(IbsGroup::query()->where('name', 'vol-45d')->firstOrFail()->charge->isVolume());

        $this->actingAs($admin)->post('/ibs/charges', ['charge_name' => 'unl', 'charge_type' => 'unlimited'])->assertSessionHas('success');
        $this->assertFalse(IbsCharge::query()->where('name', 'unl')->firstOrFail()->isVolume());
        $this->assertDatabaseHas('audit_logs', ['action' => 'ibs_charges.create']);
    }

    public function test_half_created_charge_is_completed_on_retry(): void
    {
        $this->syncCatalog();
        $this->fake->failNext('charge.addInternetChargeRule', new IbsException('ACCESS_DENIED', 'denied'));
        $admin = $this->superAdmin();
        $this->actingAs($admin)->from('/ibs/groups')->post('/ibs/charges', ['charge_name' => 'vol2', 'charge_type' => 'volume'])->assertSessionHas('error');
        $this->assertSame([], $this->fake->charges['vol2']);
        $this->actingAs($admin)->post('/ibs/charges', ['charge_name' => 'vol2', 'charge_type' => 'volume'])->assertSessionHas('success');
        $this->assertCount(1, $this->fake->charges['vol2']);
        // a complete charge with the same name is refused
        $this->actingAs($admin)->from('/ibs/groups')->post('/ibs/charges', ['charge_name' => 'vol2', 'charge_type' => 'volume'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'تعرفه‌ای با این نام'));
        $this->actingAs($admin)->post('/ibs/charges', ['charge_name' => 'بد نام', 'charge_type' => 'volume'])->assertSessionHasErrors('charge_name');
    }

    public function test_delete_group_only_when_unused(): void
    {
        $this->syncCatalog();
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post('/ibs/groups', ['name' => 'old-group', 'days' => 30, 'multi_login' => 1, 'charge' => 'Internet'])->assertSessionHas('success');

        (new VpnAccount)->forceFill(['username' => 'u1', 'group_name' => 'old-group', 'ibs_user_id' => 999])->save();
        $this->actingAs($admin)->from('/ibs/groups')->delete('/ibs/groups/old-group')->assertSessionHas('error', fn ($m) => str_contains($m, 'اکانت دارد'));
        $this->assertArrayHasKey('old-group', $this->fake->groups);
        VpnAccount::query()->where('username', 'u1')->forceDelete();

        // a user on the server only (not in the panel): IBSng refuses with a clear message
        $this->fake->users[1] = ['username' => 'x', 'password' => 'p', 'group' => 'old-group', 'credit' => 0.0, 'attrs' => [], 'first_login' => null, 'created' => 0];
        $this->actingAs($admin)->from('/ibs/groups')->delete('/ibs/groups/old-group')->assertSessionHas('error', fn ($m) => str_contains($m, 'عضو این گروه'));
        unset($this->fake->users[1]);

        $this->actingAs($admin)->delete('/ibs/groups/old-group')->assertRedirect('/ibs/groups')->assertSessionHas('success');
        $this->assertArrayNotHasKey('old-group', $this->fake->groups);
        $this->assertNull(IbsGroup::query()->where('name', 'old-group')->first());
        $this->assertDatabaseHas('audit_logs', ['action' => 'ibs_groups.delete']);

        $this->actingAs($this->userWith(['ibs_groups.view']))->delete('/ibs/groups/test')->assertForbidden();
    }

    public function test_delete_charge_only_when_no_group_uses_it(): void
    {
        $this->syncCatalog();
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post('/ibs/charges', ['charge_name' => 'tmp', 'charge_type' => 'volume'])->assertSessionHas('success');
        $this->actingAs($admin)->post('/ibs/groups', ['name' => 'g-tmp', 'days' => 30, 'multi_login' => 1, 'charge' => 'tmp'])->assertSessionHas('success');
        $this->actingAs($admin)->from('/ibs/groups')->delete('/ibs/charges/tmp')->assertSessionHas('error', fn ($m) => str_contains($m, 'g-tmp'));
        $this->actingAs($admin)->delete('/ibs/groups/g-tmp')->assertSessionHas('success');
        $this->actingAs($admin)->delete('/ibs/charges/tmp')->assertSessionHas('success');
        $this->assertArrayNotHasKey('tmp', $this->fake->charges);
        $this->assertNull(IbsCharge::query()->where('name', 'tmp')->first());
    }

    public function test_days_of_week_go_as_a_struct_on_the_wire(): void
    {
        $xml = XmlRpcCodec::encodeValue((object) ['0' => 'Monday', '1' => 'Tuesday']);
        $this->assertStringContainsString('<struct><member><name>0</name><value><string>Monday</string>', $xml);
    }
}
