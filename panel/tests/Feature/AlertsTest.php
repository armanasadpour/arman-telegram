<?php

namespace Tests\Feature;

use App\Ibs\IbsConnector;
use App\Ibs\IbsUnavailableException;
use App\Models\BotOutbox;
use App\Models\BotUser;
use App\Models\VpnAccount;
use App\Services\Alerts\Alerts;
use App\Services\Finance\ResellerSales;
use App\Services\Ibs\AccountSync;
use App\Services\Settings;
use Illuminate\Support\Str;
use Tests\Feature\Bots\BotTestCase;

class AlertsTest extends BotTestCase
{
    private function customerWith(string $username, int $usedDays, string $group = 'test', float $credit = 0): VpnAccount
    {
        $id = $this->ibsUser($username, $group, $credit);
        $this->fake->users[$id]['first_login'] = $this->fake->now() - $usedDays * 86400;
        $a = app(AccountSync::class)->importByUsername($username);
        $bu = BotUser::query()->create(['bot_id' => $this->bot()->id, 'chat_id' => (string) (900 + $id % 100)]);
        $bu->accounts()->attach($a->id, ['created_at' => now()]);

        return $a;
    }

    private function travelDays(float $days): void
    {
        $this->travel((int) ($days * 86400))->seconds();
        $this->fake->clockSkew += (int) ($days * 86400);
    }

    private function texts(): array
    {
        return BotOutbox::query()->orderBy('id')->pluck('text')->all();
    }

    public function test_expiry_reminders_once_per_threshold_then_expired_notice(): void
    {
        $this->syncCatalog();
        $this->customerWith('cust1', 3);   // 5-day group: 2 days left
        $alerts = app(Alerts::class);
        $alerts->customerAlerts();
        $alerts->customerAlerts();
        $this->assertCount(1, $this->texts());
        $this->assertStringContainsString('۲ روز دیگر', $this->texts()[0]);
        $this->assertStringContainsString('تمدید اکانت من', $this->texts()[0]);

        $this->travelDays(1.5);   // 0.5 day left: the 1-day reminder
        $alerts->customerAlerts();
        $this->assertCount(2, $this->texts());
        $this->assertStringContainsString('کمتر از یک روز', $this->texts()[1]);

        $this->travelDays(1);     // expired
        $alerts->customerAlerts();
        $alerts->customerAlerts();
        $this->assertCount(3, $this->texts());
        $this->assertStringContainsString('منقضی شد', $this->texts()[2]);
    }

    public function test_no_reminder_when_already_renewed_on_the_server(): void
    {
        $this->syncCatalog();
        $a = $this->customerWith('cust2', 3);
        $this->fake->users[$a->ibs_user_id]['attrs']['rel_exp_date'] = 60 * 86400;   // renewed elsewhere
        VpnAccount::query()->whereKey($a->id)->update(['last_synced_at' => now()->subHour()]);
        app(Alerts::class)->customerAlerts();
        $this->assertSame([], $this->texts());
    }

    public function test_low_and_depleted_volume(): void
    {
        $this->syncCatalog();
        $a = $this->customerWith('vol1', 1, 'vol-30d', 10 * 1024);   // 10 GB seen first
        $this->fake->users[$a->ibs_user_id]['credit'] = 900;          // 900 MB left
        VpnAccount::query()->whereKey($a->id)->update(['last_synced_at' => now()->subHour()]);
        app(Alerts::class)->customerAlerts();
        $this->assertStringContainsString('رو به اتمام', $this->texts()[0]);
        $this->fake->users[$a->ibs_user_id]['credit'] = 0;
        VpnAccount::query()->whereKey($a->id)->update(['last_synced_at' => now()->subHour()]);
        app(Alerts::class)->customerAlerts();
        app(Alerts::class)->customerAlerts();
        $this->assertCount(2, $this->texts());
        $this->assertStringContainsString('تمام شد', $this->texts()[1]);
    }

    public function test_disabled_alerts_send_nothing(): void
    {
        $this->syncCatalog();
        foreach (['expiry_enabled', 'expired_enabled', 'volume_enabled'] as $k) {
            app(Settings::class)->set('alerts.'.$k, '0');
        }
        $this->customerWith('cust3', 3);
        app(Alerts::class)->customerAlerts();
        $this->assertSame([], $this->texts());
    }

    public function test_admin_daily_report_once_after_report_hour(): void
    {
        $admin = $this->superAdmin();
        BotUser::query()->create(['bot_id' => $this->bot()->id, 'chat_id' => '1', 'user_id' => $admin->id]);
        $r = $this->reseller(creditRial: 5_000_000);
        app(ResellerSales::class)->sell($r, $this->newPkg, (string) Str::uuid(), 1_200_000, [], null, $r->user_id);
        $this->travelTo(now('Asia/Tehran')->addDay()->setTime(8, 0)->utc());
        app(Settings::class)->set('alerts.report_hour', '9');
        $this->assertSame(0, app(Alerts::class)->dailyAdminReport());
        $this->travelTo(now('Asia/Tehran')->setTime(9, 5)->utc());
        $this->assertSame(1, app(Alerts::class)->dailyAdminReport());
        $this->assertSame(0, app(Alerts::class)->dailyAdminReport(), 'once a day');
        $text = BotOutbox::query()->where('chat_id', '1')->value('text');
        $this->assertStringContainsString('فروش همکاران: ۱', $text);
        $this->assertStringContainsString('۱۰۰٬۰۰۰ تومان', $text);
    }

    public function test_reseller_digest_lists_expiring_accounts_and_low_balance(): void
    {
        $this->syncCatalog();
        $r = $this->reseller(creditRial: 50_000);
        BotUser::query()->create(['bot_id' => $this->bot()->id, 'chat_id' => '77', 'user_id' => $r->user_id]);
        $id = $this->ibsUser('rcust', 'test');
        $this->fake->users[$id]['first_login'] = $this->fake->now() - 4 * 86400;
        app(AccountSync::class)->importByUsername('rcust')->forceFill(['reseller_id' => $r->id])->save();
        app(Settings::class)->set('alerts.report_hour', '0');
        app(Alerts::class)->resellerDigests();
        app(Alerts::class)->resellerDigests();
        $texts = BotOutbox::query()->where('chat_id', '77')->pluck('text');
        $this->assertCount(1, $texts);
        $this->assertStringContainsString('rcust', $texts[0]);
        $this->assertStringContainsString('کمتر از', $texts[0]);
    }

    public function test_server_down_and_up_alerts_only_on_change(): void
    {
        $admin = $this->superAdmin();
        BotUser::query()->create(['bot_id' => $this->bot()->id, 'chat_id' => '5', 'user_id' => $admin->id]);
        $alerts = app(Alerts::class);
        app(IbsConnector::class)->listGroups();      // a successful call
        $this->assertSame(0, $alerts->serverHealth(), 'first observation: nothing to report');
        for ($i = 0; $i < 3; $i++) {
            $this->fake->failNext('group.listGroups', new IbsUnavailableException('down'));
        }
        try {
            app(IbsConnector::class)->listGroups();
        } catch (\Throwable) {
        }
        $this->assertSame(1, $alerts->serverHealth());
        $this->assertSame(0, $alerts->serverHealth());
        app(IbsConnector::class)->resetBreaker();
        app(IbsConnector::class)->listGroups();
        $this->assertSame(1, $alerts->serverHealth());
        $texts = BotOutbox::query()->where('chat_id', '5')->pluck('text')->all();
        $this->assertStringContainsString('قطع', $texts[0]);
        $this->assertStringContainsString('دوباره برقرار', $texts[1]);
    }

    public function test_settings_page_and_outbox_log(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/settings/alerts')->assertOk()->assertSee('یادآوری نزدیک شدن انقضا');
        $this->actingAs($admin)->from('/settings/alerts')->put('/settings/alerts', ['expiry_days' => '5, 2', 'volume_pct' => 10, 'volume_mb' => 500,
            'reseller_low_balance_toman' => 0, 'report_hour' => 8, 'expiry_enabled' => 1])->assertSessionHas('success');
        $this->assertSame('5,2', app(Alerts::class)->get('alerts.expiry_days'));
        $this->assertSame('0', app(Alerts::class)->get('alerts.volume_enabled'));
        $this->actingAs($admin)->put('/settings/alerts', ['expiry_days' => 'abc', 'volume_pct' => 10, 'volume_mb' => 1, 'reseller_low_balance_toman' => 0, 'report_hour' => 8])->assertSessionHasErrors('expiry_days');
        $this->actingAs($this->userWith(['settings.view']))->put('/settings/alerts', ['expiry_days' => '1'])->assertForbidden();
        $this->actingAs($admin)->from('/settings/alerts')->post('/settings/alerts/test')->assertSessionHas('error');   // not linked yet
        BotUser::query()->create(['bot_id' => $this->bot()->id, 'chat_id' => '3', 'user_id' => $admin->id]);
        $this->actingAs($admin)->from('/settings/alerts')->post('/settings/alerts/test')->assertSessionHas('success');
        $this->actingAs($admin)->get('/bots/outbox')->assertOk()->assertSee('گزارش روز');
        $this->artisan('alerts:run')->assertSuccessful();
    }
}
