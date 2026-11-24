<?php

namespace Tests\Feature\Ibs;

use App\Ibs\IbsConnector;
use App\Models\IbsGroup;
use App\Models\Setting;
use App\Models\VpnAccount;
use App\Services\Ibs\AccountSync;
use App\Services\Settings;
use Tests\Fakes\FakeIbs;

class SyncTest extends IbsTestCase
{
    public function test_catalog_detects_volume_charges_and_removed_groups(): void
    {
        $this->syncCatalog();
        $this->assertSame(['1-Month-kerio', 'test', 'vol-30d'], IbsGroup::query()->orderBy('name')->pluck('name')->all());
        $g = IbsGroup::query()->where('name', '1-Month-kerio')->first();
        $this->assertSame(2592000, (int) $g->rel_exp_seconds);
        $this->assertSame(2, (int) $g->multi_login);
        $this->assertSame('Internet', $g->charge_name);
        $this->assertFalse($g->charge->isVolume());
        $this->assertTrue(IbsGroup::query()->where('name', 'vol-30d')->first()->charge->isVolume());

        unset($this->fake->groups['test']);
        $this->syncCatalog();
        $this->assertFalse((bool) IbsGroup::query()->where('name', 'test')->value('present'));
    }

    public function test_expiry_uses_relative_time_and_ignores_ibsng_clock_skew(): void
    {
        // Production IBSng clock was measured ~7h55m behind (phase 1).
        $this->useFake(new FakeIbs('system', 'secret', clockSkew: -28506));
        $this->syncCatalog();
        $this->ibsUser('ali', '1-Month-kerio');
        $this->fake->connect('ali');
        $a = app(AccountSync::class)->importByUsername('ali');
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $a->expires_at->timestamp, 5);
        $this->assertSame('active', $a->status());
    }

    public function test_onlines_sync_marks_online_imports_unknown_and_clears_offline(): void
    {
        $this->syncCatalog();
        $this->ibsUser('known');
        app(AccountSync::class)->importByUsername('known');
        $this->ibsUser('stranger');
        $this->fake->connect('known', 100, 2000);
        $this->fake->connect('stranger');

        $this->assertSame(2, app(AccountSync::class)->syncOnlines());
        $known = VpnAccount::query()->where('username', 'known')->first();
        $this->assertTrue($known->is_online);
        $this->assertSame(2000, $known->sessions[0]['out_bytes']);
        $this->assertTrue(VpnAccount::query()->where('username', 'stranger')->first()->is_online, 'unknown online user imported');

        $this->fake->onlines = [];
        app(AccountSync::class)->syncOnlines();
        $this->assertSame(0, VpnAccount::query()->where('is_online', true)->count());
    }

    public function test_batch_sync_isolates_accounts_deleted_in_ibsng(): void
    {
        $this->syncCatalog();
        foreach (['a1', 'a2', 'a3'] as $u) {
            $this->ibsUser($u);
            app(AccountSync::class)->importByUsername($u);
        }
        $gone = $this->fake->idByUsername('a2');
        unset($this->fake->users[$gone]);
        $this->fake->users[$this->fake->idByUsername('a1')]['credit'] = 99;

        $this->assertSame(3, app(AccountSync::class)->syncAccounts());
        $this->assertNotNull(VpnAccount::query()->where('username', 'a2')->value('ibs_deleted_at'));
        $this->assertSame(99.0, (float) VpnAccount::query()->where('username', 'a1')->value('credit'));
        $this->assertNull(VpnAccount::query()->where('username', 'a3')->value('ibs_deleted_at'));
    }

    public function test_bulk_import_pages_through_all_users_and_skips_raw_ones(): void
    {
        $this->syncCatalog();
        for ($i = 0; $i < 7; $i++) {
            $this->ibsUser("bulk$i");
        }
        $raw = $this->fake->nextId++;
        $this->fake->users[$raw] = ['username' => null, 'password' => null, 'group' => 'test', 'credit' => 0, 'attrs' => [], 'first_login' => null, 'created' => time()];

        $sync = app(AccountSync::class);
        $sync->startImport();
        $st = $sync->importStep(3);
        $this->assertTrue($st['running']);
        $this->assertSame(8, $st['total']);
        while ($st['running']) {
            $st = $sync->importStep(3);
        }
        $this->assertSame(7, $st['imported']);
        $this->assertSame(1, $st['skipped']);
        $this->assertSame(7, VpnAccount::query()->count());

        // Running again imports nothing new.
        $sync->startImport();
        while (($st = $sync->importStep(3))['running']);
        $this->assertSame(0, $st['imported']);
    }

    public function test_artisan_sync_commands_are_noops_when_not_configured(): void
    {
        Setting::query()->where('key', 'ibs.host')->delete();
        $this->app->forgetInstance(Settings::class);
        $this->app->forgetInstance(IbsConnector::class);
        $this->artisan('ibs:sync-onlines')->expectsOutputToContain('not configured')->assertSuccessful();
    }
}
