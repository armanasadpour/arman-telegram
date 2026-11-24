<?php

namespace Tests\Feature\Ibs;

use App\Ibs\IbsUnavailableException;
use App\Models\AuditLog;
use App\Models\VpnAccount;
use App\Services\Ibs\AccountActions;
use App\Services\Ibs\AccountSync;
use App\Services\Settings;
use RuntimeException;

class AccountActionsTest extends IbsTestCase
{
    private function account(string $group = 'test', float $credit = 0): VpnAccount
    {
        $this->syncCatalog();
        $this->ibsUser('ali', $group, $credit);

        return app(AccountSync::class)->importByUsername('ali');
    }

    public function test_lock_and_unlock_round_trip_through_ibsng(): void
    {
        $a = $this->account();
        $actions = app(AccountActions::class);
        $a = $actions->lock($a, 'بدهی');
        $this->assertTrue($a->locked);
        $this->assertSame('بدهی', $this->fake->users[$a->ibs_user_id]['attrs']['lock']);
        $this->assertSame('locked', $a->status());

        $a = $actions->unlock($a, 'پرداخت شد');
        $this->assertFalse($a->locked);
        $this->assertArrayNotHasKey('lock', $this->fake->users[$a->ibs_user_id]['attrs']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'accounts.unlock', 'reason' => 'پرداخت شد']);
    }

    public function test_change_password_and_reveal(): void
    {
        $a = $this->account();
        app(AccountActions::class)->changePassword($a, 'NewPw2026');
        $this->assertSame('NewPw2026', $this->fake->users[$a->ibs_user_id]['password']);
        $this->assertSame('NewPw2026', app(AccountActions::class)->revealPassword($a));
        $this->assertDatabaseHas('audit_logs', ['action' => 'accounts.password_revealed']);
        $this->assertStringNotContainsString('NewPw2026', AuditLog::query()->get()->toJson());
    }

    public function test_renew_not_started_account_extends_validity(): void
    {
        $a = $this->account(); // group test = 5 days
        [$a, $rel] = app(AccountActions::class)->renew($a, 30, 'order 7');
        $this->assertSame(35 * 86400, $rel);
        $this->assertSame(35 * 86400, $this->fake->users[$a->ibs_user_id]['attrs']['rel_exp_date']);
        $this->assertNull($a->expires_at, 'still counted from first login');
    }

    public function test_renew_active_account_adds_to_current_expiry(): void
    {
        $a = $this->account();
        $this->fake->connect('ali');                       // first login now -> expires in 5 days
        $a = app(AccountSync::class)->refresh($a);
        $before = $a->expires_at;
        [$a] = app(AccountActions::class)->renew($a, 30, 'r');
        $this->assertEqualsWithDelta($before->addDays(30)->timestamp, $a->expires_at->timestamp, 3700);
    }

    public function test_smart_renew_of_expired_account_starts_from_now(): void
    {
        $a = $this->account();
        $this->fake->connect('ali');
        $this->fake->users[$a->ibs_user_id]['first_login'] = time() - 10 * 86400; // expired 5 days ago
        $a = app(AccountSync::class)->refresh($a);
        $this->assertSame('expired', $a->status());

        [$a] = app(AccountActions::class)->renew($a, 30, 'r');
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $a->expires_at->timestamp, 3700);
        $this->assertSame('active', $a->status());
    }

    public function test_from_expiry_mode_keeps_dead_time(): void
    {
        app(Settings::class)->set('accounts.renewal_mode', 'from_expiry');
        $a = $this->account();
        $this->fake->connect('ali');
        $this->fake->users[$a->ibs_user_id]['first_login'] = time() - 10 * 86400;
        $a = app(AccountSync::class)->refresh($a);
        [$a] = app(AccountActions::class)->renew($a, 30, 'r');
        $this->assertEqualsWithDelta(now()->addDays(25)->timestamp, $a->expires_at->timestamp, 3700);
    }

    public function test_add_volume_only_for_volume_charges(): void
    {
        $a = $this->account('test');
        try {
            app(AccountActions::class)->addVolume($a, 1024, 'x');
            $this->fail('expected refusal');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('حجمی نیست', $e->getMessage());
        }

        $this->ibsUser('vol', 'vol-30d', 2048);
        $v = app(AccountSync::class)->importByUsername('vol');
        $v = app(AccountActions::class)->addVolume($v, 5 * 1024, 'خرید ۵ گیگ');
        $this->assertSame(7 * 1073741824, $v->remainingBytes());
        $this->assertNull($v->volume_total_bytes, 'unknown total for imported accounts is not invented');
    }

    public function test_delete_removes_from_ibsng_and_soft_deletes_mirror(): void
    {
        $a = $this->account();
        app(AccountActions::class)->delete($a, 'درخواست مشتری');
        $this->assertNull($this->fake->idByUsername('ali'));
        $this->assertSoftDeleted('vpn_accounts', ['id' => $a->id]);
        $this->assertNotNull(VpnAccount::withTrashed()->find($a->id)->ibs_deleted_at);
    }

    public function test_actions_on_account_deleted_outside_panel_are_refused(): void
    {
        $a = $this->account();
        unset($this->fake->users[$a->ibs_user_id]);
        $this->expectException(RuntimeException::class);
        app(AccountActions::class)->lock($a, 'x');
    }

    public function test_ibsng_down_during_action_raises_unavailable_and_changes_nothing(): void
    {
        $a = $this->account();
        for ($i = 0; $i < 3; $i++) {
            $this->fake->failNext('user.getUserInfo', new IbsUnavailableException('down'));
        }
        try {
            app(AccountActions::class)->lock($a, 'x');
            $this->fail('expected exception');
        } catch (IbsUnavailableException) {
        }
        $this->assertArrayNotHasKey('lock', $this->fake->users[$a->ibs_user_id]['attrs']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'accounts.lock']);
    }
}
