<?php

namespace Tests\Feature;

use App\Models\VpnAccount;
use Illuminate\Support\Str;
use Tests\Feature\Finance\ResellerTestCase;

class PasswordRulesTest extends ResellerTestCase
{
    public function test_any_password_for_new_account_manual_and_from_package(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post('/accounts', ['job_key' => (string) Str::uuid(), 'username' => 'free1', 'password' => '1', 'group' => 'test'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/accounts/from-package', ['job_key' => (string) Str::uuid(), 'package_id' => $this->newPkg->id, 'username' => 'free2', 'password' => 'رمز ساده 12!'])->assertSessionHasNoErrors();
        $pw = array_column($this->fake->users, 'password', 'username');
        $this->assertSame('1', $pw['free1']);
        $this->assertSame('رمز ساده 12!', $pw['free2']);
    }

    public function test_reseller_can_choose_password_when_selling(): void
    {
        $r = $this->reseller(creditRial: 5_000_000);
        $this->actingAs($r->user)->post('/r/sell', ['sale_key' => (string) Str::uuid(), 'package_id' => $this->newPkg->id, 'sale_price_toman' => 100000,
            'username' => 'cust1', 'password' => 'abc'])->assertSessionHas('new_password', 'abc');
        $this->assertSame('abc', $this->fake->users[VpnAccount::query()->where('username', 'cust1')->value('ibs_user_id')]['password']);
    }

    public function test_panel_users_need_only_four_characters(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->post('/resellers', ['name' => 'ساده', 'username' => 'simple', 'password' => 'abcd', 'password_confirmation' => 'abcd'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/resellers', ['name' => 'کوتاه', 'username' => 'short', 'password' => 'abc', 'password_confirmation' => 'abc'])->assertSessionHasErrors('password');
    }
}
