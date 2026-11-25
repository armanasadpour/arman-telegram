<?php

namespace Tests\Feature\Ibs;

use App\Ibs\IbsException;
use App\Models\IbsGroup;

class GroupManageTest extends IbsTestCase
{
    public function test_admin_creates_group_on_server(): void
    {
        $this->syncCatalog();
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/ibs/groups')->assertOk()->assertSee('تعریف گروه جدید در سرور');
        $this->actingAs($admin)->post('/ibs/groups', ['name' => '3-Month-50GB', 'days' => 90, 'multi_login' => 2, 'charge' => 'Volume-MB', 'comment' => 'سه ماهه'])
            ->assertRedirect('/ibs/groups')->assertSessionHas('success');
        $this->assertSame(['rel_exp_date' => 90 * 86400, 'multi_login' => 2, 'normal_charge' => 'Volume-MB'], $this->fake->groups['3-Month-50GB']['attrs']);
        $g = IbsGroup::query()->where('name', '3-Month-50GB')->firstOrFail();
        $this->assertSame(90 * 86400, $g->rel_exp_seconds);
        $this->assertTrue($g->charge->isVolume());
        $this->assertDatabaseHas('audit_logs', ['action' => 'ibs_groups.create']);
    }

    public function test_invalid_or_duplicate_names_are_rejected(): void
    {
        $this->syncCatalog();
        $admin = $this->superAdmin();
        $ok = ['days' => 30, 'multi_login' => 1, 'charge' => 'Internet'];
        $this->actingAs($admin)->post('/ibs/groups', ['name' => 'گروه یک'] + $ok)->assertSessionHasErrors('name');
        $this->actingAs($admin)->post('/ibs/groups', ['name' => 'test'] + $ok)->assertSessionHasErrors('name');
        $this->actingAs($admin)->post('/ibs/groups', ['name' => 'newg', 'charge' => 'NoSuch'] + $ok)->assertSessionHasErrors('charge');
        $this->assertArrayNotHasKey('newg', $this->fake->groups);
    }

    public function test_server_side_name_clash_shows_persian_error(): void
    {
        $this->syncCatalog();
        $this->fake->groups['made-elsewhere'] = ['id' => 99, 'attrs' => []];   // exists on server, panel not synced yet
        $this->actingAs($this->superAdmin())->from('/ibs/groups')
            ->post('/ibs/groups', ['name' => 'made-elsewhere', 'days' => 30, 'multi_login' => 1, 'charge' => 'Internet'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'گروهی با این نام در سرور وجود دارد'));
    }

    public function test_attr_failure_after_create_sends_admin_to_edit(): void
    {
        $this->syncCatalog();
        $this->fake->failNext('group.updateGroupAttrs', new IbsException('ACCESS_DENIED', 'denied'));
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post('/ibs/groups', ['name' => 'half', 'days' => 30, 'multi_login' => 1, 'charge' => 'Internet'])
            ->assertRedirect('/ibs/groups/half/edit')->assertSessionHas('error');
        $this->actingAs($admin)->get('/ibs/groups/half/edit')->assertOk();
        $this->actingAs($admin)->put('/ibs/groups/half', ['days' => 30, 'multi_login' => 1, 'charge' => 'Internet'])->assertSessionHas('success');
        $this->assertSame(30 * 86400, $this->fake->groups['half']['attrs']['rel_exp_date']);
    }

    public function test_edit_existing_group(): void
    {
        $this->syncCatalog();
        $admin = $this->superAdmin();
        $this->actingAs($admin)->put('/ibs/groups/test', ['days' => 45, 'multi_login' => 3, 'charge' => 'Internet'])->assertRedirect('/ibs/groups');
        $this->assertSame(45 * 86400, $this->fake->groups['test']['attrs']['rel_exp_date']);
        $this->assertSame(3, IbsGroup::query()->where('name', 'test')->value('multi_login'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'ibs_groups.update']);
    }

    public function test_view_only_user_cannot_create_or_edit(): void
    {
        $this->syncCatalog();
        $u = $this->userWith(['ibs_groups.view']);
        $this->actingAs($u)->get('/ibs/groups')->assertOk()->assertDontSee('تعریف گروه جدید در سرور');
        $this->actingAs($u)->post('/ibs/groups', ['name' => 'x', 'days' => 30, 'multi_login' => 1, 'charge' => 'Internet'])->assertForbidden();
        $this->actingAs($u)->put('/ibs/groups/test', ['days' => 30, 'multi_login' => 1, 'charge' => 'Internet'])->assertForbidden();
        $this->assertArrayNotHasKey('x', $this->fake->groups);
    }
}
