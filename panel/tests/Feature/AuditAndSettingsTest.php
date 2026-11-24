<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use App\Services\Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AuditAndSettingsTest extends TestCase
{
    public function test_audit_records_are_immutable(): void
    {
        $log = app(AuditLogger::class)->log('test.event', null, 'x', actorLabel: 'test');
        try {
            $log->update(['description' => 'changed']);
            $this->fail('update should throw');
        } catch (LogicException) {
        }
        try {
            $log->delete();
            $this->fail('delete should throw');
        } catch (LogicException) {
        }
        $this->assertSame('x', $log->fresh()->description);
    }

    public function test_hash_chain_detects_tampering_and_deletion(): void
    {
        $audit = app(AuditLogger::class);
        foreach (range(1, 5) as $i) {
            $audit->log('test.event', null, "event $i", actorLabel: 'test');
        }
        $this->assertNull($audit->verifyChain());

        DB::table('audit_logs')->where('description', 'event 3')->update(['description' => 'forged']);
        $this->assertNotNull($audit->verifyChain());

        DB::table('audit_logs')->where('description', 'forged')->update(['description' => 'event 3']);
        $this->assertNull($audit->verifyChain());

        DB::table('audit_logs')->where('description', 'event 2')->delete();
        $this->assertNotNull($audit->verifyChain());
    }

    public function test_secrets_are_redacted_in_changes(): void
    {
        $log = app(AuditLogger::class)->log('test.event', null, null, [
            'password' => ['old', 'new'], 'api_key' => [null, 'k'], 'telegram_token' => ['a', 'b'], 'name' => ['a', 'b'],
        ], actorLabel: 'test');
        $this->assertSame(['***', '***'], $log->changes['password']);
        $this->assertSame(['***', '***'], $log->changes['api_key']);
        $this->assertSame(['***', '***'], $log->changes['telegram_token']);
        $this->assertSame(['a', 'b'], $log->changes['name']);
    }

    public function test_audit_page_filters_and_verify_button(): void
    {
        $admin = $this->superAdmin();
        app(AuditLogger::class)->log('auth.login_failed', null, 'bad', actorLabel: 'x');
        $this->actingAs($admin)->get('/audit?action=auth.login_failed')->assertOk()->assertSee('ورود ناموفق');
        $this->actingAs($admin)->post('/audit/verify')->assertSessionHas('success');
    }

    public function test_settings_update_is_validated_and_audited(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->put('/settings', [
            'general__site_name' => '', 'general__display_timezone' => 'Mars/Base',
        ])->assertSessionHasErrors(['general__site_name', 'general__display_timezone']);

        $this->actingAs($admin)->put('/settings', [
            'general__site_name' => 'پنل مونینو', 'general__support_contact' => '@support', 'general__display_timezone' => 'Asia/Tehran',
        ])->assertSessionHas('success');
        $this->assertSame('پنل مونینو', app(Settings::class)->get('general.site_name'));
        $log = AuditLog::query()->where('action', 'settings.update')->firstOrFail();
        $this->assertSame(['پنل فروش VPN', 'پنل مونینو'], $log->changes['general.site_name']);
    }

    public function test_view_only_settings_user_cannot_update(): void
    {
        $u = $this->userWith(['settings.view']);
        $this->actingAs($u)->get('/settings')->assertOk()->assertDontSee('ذخیره تنظیمات');
        $this->actingAs($u)->put('/settings', ['general__site_name' => 'x', 'general__display_timezone' => 'UTC'])->assertForbidden();
    }

    public function test_encrypted_setting_round_trip_and_not_plaintext_in_db(): void
    {
        $s = app(Settings::class);
        $s->set('secret.test', 'top-secret-value', encrypt: true);
        $this->assertSame('top-secret-value', app(Settings::class)->get('secret.test'));
        $this->assertStringNotContainsString('top-secret-value', (string) DB::table('settings')->where('key', 'secret.test')->value('value'));
    }

    public function test_web_cron_requires_correct_key(): void
    {
        config(['panel.cron_key' => 'k_'.str_repeat('a', 40)]);
        $this->get('/cron/wrong')->assertNotFound();
        $this->artisan('schedule:list')->expectsOutputToContain('panel:heartbeat')->assertSuccessful();
        // The real scheduler spawns child PHP processes that would write outside the test transaction,
        // so the HTTP test only verifies authorisation and that schedule:run is invoked exactly once.
        Artisan::shouldReceive('call')->once()->with('schedule:run')->andReturn(0);
        $this->get('/cron/k_'.str_repeat('a', 40))->assertOk()->assertSee('OK');

        config(['panel.cron_key' => null]);
        $this->get('/cron/')->assertNotFound();
    }

    public function test_status_page_reports_cron_after_heartbeat(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/system')->assertOk()->assertSee('هنوز هیچ اجرایی ثبت نشده');
        $this->artisan('panel:heartbeat')->assertSuccessful();
        $this->actingAs($admin)->get('/system')->assertOk()->assertSee('آخرین اجرا');
    }
}
