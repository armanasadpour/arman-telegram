<?php

namespace Tests\Feature\Pricing;

use App\Models\AuditLog;
use App\Models\Discount;
use App\Models\Package;
use App\Models\ProvisioningJob;
use App\Models\VpnAccount;
use App\Services\Ibs\AccountSync;
use App\Services\Pricing\PackageApplier;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Ibs\IbsTestCase;

class PackagesTest extends IbsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->syncCatalog(); // groups: 1-Month-kerio (unlimited), test (unlimited), vol-30d (volume charge)
    }

    private function form(array $o = []): array
    {
        return $o + ['name' => 'ماهانه', 'type' => 'new', 'ibs_group_name' => '1-Month-kerio', 'duration_days' => 30,
            'volume_gb' => null, 'multi_login' => 2, 'price_toman' => 150000, 'is_active' => 1];
    }

    public function test_package_must_match_ibsng_group_charge(): void
    {
        $admin = $this->superAdmin();
        // volume on an unlimited (cpk=0) group: IBSng would never enforce it -> refused
        $this->actingAs($admin)->post('/packages', $this->form(['volume_gb' => 20]))->assertSessionHasErrors('volume_gb');
        // volume group without volume -> refused
        $this->actingAs($admin)->post('/packages', $this->form(['ibs_group_name' => 'vol-30d']))->assertSessionHasErrors('volume_gb');
        // unknown group -> refused
        $this->actingAs($admin)->post('/packages', $this->form(['ibs_group_name' => 'ghost']))->assertSessionHasErrors('ibs_group_name');
        // topup cannot have days; renewal needs days
        $this->actingAs($admin)->post('/packages', $this->form(['type' => 'topup', 'volume_gb' => 5, 'duration_days' => 10]))->assertSessionHasErrors('duration_days');
        $this->actingAs($admin)->post('/packages', $this->form(['type' => 'renewal', 'duration_days' => null]))->assertSessionHasErrors('duration_days');
        $this->assertSame(0, Package::query()->count());

        $this->actingAs($admin)->post('/packages', $this->form())->assertRedirect('/packages');
        $this->actingAs($admin)->post('/packages', $this->form(['name' => 'حجمی', 'ibs_group_name' => 'vol-30d', 'volume_gb' => 20]))->assertRedirect('/packages');
        $p = Package::query()->where('name', 'ماهانه')->first();
        $this->assertSame(1_500_000, $p->price_rial, 'toman input stored as rial');
        $this->assertSame('زمانی', $p->kindLabel());
        $this->assertSame('ترکیبی', Package::query()->where('name', 'حجمی')->first()->kindLabel());
        $this->assertDatabaseHas('audit_logs', ['action' => 'packages.create']);
    }

    public function test_create_account_from_package_stores_price_snapshot(): void
    {
        $p = Package::query()->create(['name' => 'حجمی ۲۰ گیگ', 'type' => 'new', 'ibs_group_name' => 'vol-30d', 'duration_days' => 45,
            'volume_gb' => 20, 'multi_login' => 3, 'price_rial' => 2_000_000]);
        Discount::query()->create(['name' => 'یلدا', 'scope' => 'package', 'package_id' => $p->id, 'kind' => 'percent', 'value' => 25, 'is_active' => true]);

        $job = app(PackageApplier::class)->createAccount($p, (string) Str::uuid(), ['username' => 'pkguser', 'password' => 'Pass12345', 'comment' => null], null);
        $this->assertSame('completed', $job->status);

        $id = $this->fake->idByUsername('pkguser');
        $this->assertSame(20480.0, (float) $this->fake->users[$id]['credit']);        // 20 GB at 1 MB per credit
        $this->assertSame(45 * 86400, $this->fake->users[$id]['attrs']['rel_exp_date']);
        $this->assertSame('3', $this->fake->users[$id]['attrs']['multi_login']);
        $snap = ProvisioningJob::query()->first()->spec['package'];
        $this->assertSame(1_500_000, $snap['final_price']);
        $this->assertSame('یلدا', $snap['discount']['name']);
        $this->assertSame(20 * 1073741824, VpnAccount::query()->where('username', 'pkguser')->first()->volume_total_bytes);
    }

    public function test_apply_renewal_and_topup_packages(): void
    {
        $this->ibsUser('vol1', 'vol-30d', 1024);
        $a = app(AccountSync::class)->importByUsername('vol1');
        $renew = Package::query()->create(['name' => 'تمدید ۳۰ روزه + ۱۰ گیگ', 'type' => 'renewal', 'duration_days' => 30, 'volume_gb' => 10, 'price_rial' => 1_000_000]);
        $top = Package::query()->create(['name' => '۵ گیگ اضافه', 'type' => 'topup', 'volume_gb' => 5, 'price_rial' => 300_000]);

        $a = app(PackageApplier::class)->applyToAccount($renew, $a, 'سفارش ۱۲');
        $this->assertSame(60 * 86400, $this->fake->users[$a->ibs_user_id]['attrs']['rel_exp_date']);
        $a = app(PackageApplier::class)->applyToAccount($top, $a, 'سفارش ۱۳');
        $this->assertSame((1 + 10 + 5) * 1073741824, $a->remainingBytes());
        $this->assertSame(2, AuditLog::query()->where('action', 'accounts.package_applied')->count());
    }

    public function test_volume_package_refused_on_unlimited_account(): void
    {
        $this->ibsUser('unl', 'test');
        $a = app(AccountSync::class)->importByUsername('unl');
        $top = Package::query()->create(['name' => '۵ گیگ', 'type' => 'topup', 'volume_gb' => 5, 'price_rial' => 300_000]);
        $this->expectException(RuntimeException::class);
        app(PackageApplier::class)->applyToAccount($top, $a, 'x');
    }

    public function test_inactive_or_wrong_type_package_cannot_create_account(): void
    {
        $p = Package::query()->create(['name' => 'x', 'type' => 'new', 'ibs_group_name' => 'test', 'duration_days' => 5, 'price_rial' => 1, 'is_active' => false]);
        $this->expectException(RuntimeException::class);
        app(PackageApplier::class)->createAccount($p, (string) Str::uuid(), [], null);
    }

    public function test_soft_deleted_package_keeps_history_and_discount_form_parses_jalali_dates(): void
    {
        $admin = $this->superAdmin();
        $p = Package::query()->create(['name' => 'old', 'type' => 'renewal', 'duration_days' => 10, 'price_rial' => 100_000]);
        $this->actingAs($admin)->delete("/packages/{$p->id}", ['reason' => 'منسوخ'])->assertRedirect('/packages');
        $this->assertSoftDeleted('packages', ['id' => $p->id]);

        $this->actingAs($admin)->post('/discounts', ['name' => 'یلدا', 'scope' => 'all', 'kind' => 'percent', 'value' => 10,
            'starts_at' => '۱۴۰۵/۰۹/۳۰', 'ends_at' => '1405/10/01', 'is_active' => 1])->assertRedirect('/discounts');
        $d = Discount::query()->firstOrFail();
        // 1405/09/30 = 2026-12-21; Tehran midnight = 20:30 UTC on the previous day; end = start of the day after 1405/10/01.
        $this->assertSame('2026-12-20 20:30:00', $d->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-22 20:30:00', $d->ends_at->utc()->format('Y-m-d H:i:s'));

        $this->actingAs($admin)->post('/discounts', ['name' => 'bad', 'scope' => 'all', 'kind' => 'percent', 'value' => 150, 'is_active' => 1])->assertSessionHasErrors('value');
        $this->actingAs($admin)->post('/discounts', ['name' => 'bad', 'scope' => 'category', 'kind' => 'percent', 'value' => 5])->assertSessionHasErrors('category_id');
    }

    public function test_permissions_and_page_walk(): void
    {
        $viewer = $this->userWith(['packages.view']);
        $this->actingAs($viewer)->get('/packages')->assertOk()->assertDontSee('بسته جدید');
        $this->actingAs($viewer)->get('/packages/create')->assertForbidden();
        $this->actingAs($viewer)->get('/discounts')->assertForbidden();

        $admin = $this->superAdmin();
        $p = Package::query()->create(['name' => 'ماهانه', 'type' => 'new', 'ibs_group_name' => 'test', 'duration_days' => 30, 'price_rial' => 1_500_000]);
        $this->ibsUser('ali');
        $a = app(AccountSync::class)->importByUsername('ali');
        Package::query()->create(['name' => 'تمدید', 'type' => 'renewal', 'duration_days' => 30, 'price_rial' => 1_000_000]);
        foreach (['/packages', '/packages/create', "/packages/{$p->id}/edit", '/discounts', '/discounts/create', '/accounts/create', "/accounts/{$a->id}", '/settings'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
        $this->actingAs($admin)->get('/accounts/create')->assertSee('ساخت از روی بسته')->assertSee('۱۵۰٬۰۰۰ تومان');
        $this->actingAs($admin)->get("/accounts/{$a->id}")->assertSee('اعمال بسته');
    }
}
