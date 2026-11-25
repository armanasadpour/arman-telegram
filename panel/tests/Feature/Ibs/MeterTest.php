<?php

namespace Tests\Feature\Ibs;

use App\Models\VpnAccount;
use App\Services\Ibs\AccountSync;

class MeterTest extends IbsTestCase
{
    public function test_time_and_volume_meters_and_text_bar(): void
    {
        $this->syncCatalog();
        $a = new VpnAccount(['username' => 'm', 'group_name' => 'vol-30d', 'rel_exp_seconds' => 30 * 86400, 'credit' => 5 * 1024, 'volume_total_bytes' => 20 * 1073741824]);
        $a->expires_at = now()->addDays(6);
        $this->assertSame(20, $a->timeMeter()['pct']);
        $this->assertSame(25, $a->volumeMeter()['pct']);
        $this->assertSame('low', VpnAccount::meterLevel(10));
        $this->assertSame('warn', VpnAccount::meterLevel(25));
        $this->assertSame('ok', VpnAccount::meterLevel(80));
        $this->assertSame('▰▰▱▱▱▱▱▱▱▱', VpnAccount::textBar(20));

        $notStarted = new VpnAccount(['username' => 'n', 'group_name' => 'test', 'rel_exp_seconds' => 5 * 86400]);
        $this->assertSame(['pct' => 100, 'remaining' => 432000, 'total' => 432000, 'started' => false], $notStarted->timeMeter());
        $this->assertNull($notStarted->volumeMeter(), 'unlimited charge: no volume meter');

        $expired = new VpnAccount(['username' => 'e', 'group_name' => 'test', 'rel_exp_seconds' => 5 * 86400]);
        $expired->expires_at = now()->subDay();
        $this->assertSame(0, $expired->timeMeter()['pct']);
    }

    public function test_meters_render_on_account_pages(): void
    {
        $this->syncCatalog();
        $this->ibsUser('volx', 'vol-30d', 4096);
        $a = app(AccountSync::class)->importByUsername('volx');
        $this->actingAs($this->superAdmin())->get('/accounts/'.$a->id)->assertOk()
            ->assertSee('حجم باقی‌مانده')->assertSee('زمان باقی‌مانده')->assertSee('<progress class="meter-bar"', false)->assertSee('۱۰۰٪');
    }
}
