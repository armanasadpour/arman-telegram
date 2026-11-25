<?php

namespace Tests\Feature\Finance;

use App\Models\Package;
use App\Models\PackageCategory;
use App\Models\Reseller;
use App\Models\Role;
use App\Models\User;
use App\Services\Finance\WalletService;
use Tests\Feature\Ibs\IbsTestCase;

abstract class ResellerTestCase extends IbsTestCase
{
    protected PackageCategory $monthly;

    protected Package $newPkg;

    protected Package $renewPkg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncCatalog();
        $this->monthly = PackageCategory::query()->create(['name' => 'ماهانه']);
        $this->newPkg = Package::query()->create(['name' => 'یک‌ماهه', 'type' => 'new', 'ibs_group_name' => 'test', 'duration_days' => 30,
            'price_rial' => 1_000_000, 'category_id' => $this->monthly->id]);
        $this->renewPkg = Package::query()->create(['name' => 'تمدید ۳۰ روز', 'type' => 'renewal', 'duration_days' => 30, 'price_rial' => 800_000]);
    }

    protected function reseller(string $username = 'shop1', float $global = 0, int $creditRial = 0): Reseller
    {
        $u = User::factory()->create(['username' => $username]);
        $u->roles()->attach(Role::query()->where('name', 'reseller')->value('id'));
        $r = Reseller::query()->create(['user_id' => $u->id, 'code' => 'R'.$u->id, 'name' => 'فروشگاه '.$username, 'global_discount_percent' => $global]);
        if ($creditRial > 0) {
            app(WalletService::class)->post(app(WalletService::class)->walletFor('reseller', $r->id), $creditRial, 'manual_credit', 'seed:'.$r->id);
        }

        return $r->fresh();
    }

    protected function balance(Reseller $r): int
    {
        return app(WalletService::class)->walletFor('reseller', $r->id)->fresh()->balance;
    }
}
