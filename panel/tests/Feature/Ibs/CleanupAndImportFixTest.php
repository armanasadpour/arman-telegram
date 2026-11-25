<?php

namespace Tests\Feature\Ibs;

use App\Models\AuditLog;
use App\Models\VpnAccount;
use App\Services\Ibs\AccountSync;
use App\Services\Ibs\ExpiredCleanup;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanupAndImportFixTest extends IbsTestCase
{
    /** An IBSng user whose 5-day validity started $daysAgo days ago. */
    private function used(string $name, int $daysAgo): int
    {
        $id = $this->ibsUser($name);
        $this->fake->users[$id]['first_login'] = time() - $daysAgo * 86400;
        app(AccountSync::class)->importByUsername($name);

        return $id;
    }

    public function test_bulk_delete_only_long_expired_and_rechecks_the_server(): void
    {
        $this->syncCatalog();
        $old1 = $this->used('old1', 100);       // expired ~95 days ago
        $old2 = $this->used('old2', 90);
        $renewed = $this->used('renewed', 100); // renewed on the server since the panel last looked
        $online = $this->used('online1', 100);
        $recent = $this->used('recent', 20);    // expired 15 days ago: kept
        $fresh = $this->used('fresh', 1);       // active
        $this->fake->users[$renewed]['attrs']['rel_exp_date'] = 400 * 86400;
        $this->fake->onlines[$online] = ['ras_ip' => '10.0.0.1', 'in_bytes' => 1, 'out_bytes' => 1, 'login_time_epoch' => time(), 'unique_id_val' => 'x'];

        $admin = $this->superAdmin();
        $this->actingAs($admin)->get('/accounts/cleanup?days=60')->assertOk()->assertSee('old1')->assertDontSee('recent');
        $count = app(ExpiredCleanup::class)->query(app(ExpiredCleanup::class)->cutoffFor(60))->count();
        $this->assertSame(4, $count);

        $this->actingAs($admin)->post('/accounts/cleanup', ['days' => 60, 'reason' => 'پاک‌سازی', 'confirm_count' => 4])->assertRedirect('/confirm-password');
        $this->actingAs($admin)->confirmed()->from('/accounts/cleanup')->post('/accounts/cleanup', ['days' => 60, 'reason' => 'پاک‌سازی', 'confirm_count' => 3])->assertSessionHas('error');
        $this->actingAs($admin)->confirmed()->from('/accounts/cleanup')->post('/accounts/cleanup', ['days' => 60, 'reason' => 'پاک‌سازی', 'confirm_count' => 4])->assertSessionHas('success');
        $this->artisan('accounts:cleanup-step')->assertSuccessful();

        $st = app(ExpiredCleanup::class)->state();
        $this->assertFalse($st['running']);
        $this->assertSame(2, $st['deleted']);
        $this->assertSame(2, $st['skipped']);
        $this->assertArrayNotHasKey($old1, $this->fake->users);
        $this->assertArrayNotHasKey($old2, $this->fake->users);
        foreach ([$renewed, $online, $recent, $fresh] as $keep) {
            $this->assertArrayHasKey($keep, $this->fake->users);
        }
        $this->assertSame(2, VpnAccount::onlyTrashed()->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'accounts.delete')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'accounts.cleanup_finished']);
    }

    public function test_minimum_age_and_permissions(): void
    {
        $this->syncCatalog();
        $this->used('old1', 100);
        $this->actingAs($this->superAdmin())->confirmed()->post('/accounts/cleanup', ['days' => 5, 'reason' => 'xxx', 'confirm_count' => 1])->assertSessionHasErrors('days');
        $u = $this->userWith(['accounts.view']);
        $this->actingAs($u)->get('/accounts/cleanup')->assertForbidden();
        $this->actingAs($u)->confirmed()->post('/accounts/cleanup', ['days' => 60, 'reason' => 'xxx', 'confirm_count' => 1])->assertForbidden();
        $this->assertSame(1, VpnAccount::query()->count());
    }

    public function test_import_skips_a_record_the_database_rejects_and_continues(): void
    {
        $this->syncCatalog();
        foreach (['a1', 'badrow', 'a2'] as $n) {
            $this->ibsUser($n);
        }
        VpnAccount::saving(function (VpnAccount $a) {
            if ($a->username === 'badrow') {
                throw new QueryException('mysql', 'insert …', [], new \Exception("Incorrect datetime value: '2022-03-22 00:11:35'"));
            }
        });
        $sync = app(AccountSync::class);
        $sync->startImport();
        while (($st = $sync->importStep())['running']);
        $this->assertSame(2, $st['imported']);
        $this->assertSame(1, $st['errors']);
        $this->assertSame(3, $st['cursor']);
    }

    public function test_dates_are_jalali_everywhere(): void
    {
        $this->assertSame('۱۴۰۵/۰۹/۰۴ ۱۱:۱۵', fa_value('2026-11-25T07:45:00+00:00'));
        $this->assertSame('۱۴۰۱/۰۱/۰۲', fa_value('2022-03-22'));
        $this->assertSame('abc', fa_value('abc'));
        $this->assertSame('۱۴۰۰/۰۶/۳۱ ۱۹:۴۶', jdate_local('2021-09-22 19:46'));
        $this->get('/login');
        $this->actingAs($this->superAdmin())->get('/discounts/create')->assertOk()->assertSee('data-jdate', false)->assertSee('assets/jdate.js', false);
    }

    public function test_no_mysql_timestamp_columns_and_gap_times_store_fine(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL/MariaDB only');
        }
        $left = DB::select("SELECT COUNT(*) n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'timestamp'")[0]->n;
        $this->assertSame(0, (int) $left, 'every TIMESTAMP was converted to DATETIME');
        $hasTz = DB::selectOne("SELECT CONVERT_TZ('2022-01-01 00:00:00', 'UTC', 'Asia/Tehran') AS v")->v !== null;
        if ($hasTz) {
            DB::statement("SET time_zone = 'Asia/Tehran'");   // like a host in Iran
        }
        $a = VpnAccount::query()->create(['username' => 'gap', 'ibs_user_id' => 1, 'source' => 'imported', 'expires_at' => '2022-03-22 00:11:35']);
        $this->assertSame('2022-03-22 00:11:35', $a->fresh()->expires_at->format('Y-m-d H:i:s'));
        DB::statement("SET time_zone = '+00:00'");
        $this->assertTrue(Schema::hasTable('vpn_accounts'));
    }
}
