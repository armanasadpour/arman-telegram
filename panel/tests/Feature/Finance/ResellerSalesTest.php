<?php

namespace Tests\Feature\Finance;

use App\Ibs\IbsException;
use App\Ibs\IbsUnavailableException;
use App\Models\Discount;
use App\Models\LedgerEntry;
use App\Models\ProvisioningJob;
use App\Models\ResellerCategoryDiscount;
use App\Models\ResellerPrice;
use App\Models\ResellerSale;
use App\Models\VpnAccount;
use App\Services\Finance\InsufficientBalanceException;
use App\Services\Finance\ResellerPricing;
use App\Services\Finance\ResellerSales;
use App\Services\Finance\WalletService;
use App\Services\Ibs\Provisioning;
use Illuminate\Support\Str;
use RuntimeException;

class ResellerSalesTest extends ResellerTestCase
{
    private function sell($r, $pkg = null, ?string $key = null, int $salePrice = 1_200_000, $target = null): array
    {
        return app(ResellerSales::class)->sell($r, $pkg ?? $this->newPkg, $key ?? (string) Str::uuid(), $salePrice,
            ['customer_name' => 'علی', 'customer_phone' => '0912', 'username' => null], $target, $r->user_id);
    }

    // ---------------------------------------------------------------- pricing

    public function test_reseller_price_priority_fixed_then_category_then_global_then_public(): void
    {
        $r = $this->reseller(global: 10);
        $p = app(ResellerPricing::class);
        $this->assertSame(900_000, $p->quote($r, $this->newPkg)->finalPrice);
        $this->assertSame('reseller_global', $p->quote($r, $this->newPkg)->priceSource);

        ResellerCategoryDiscount::query()->create(['reseller_id' => $r->id, 'category_id' => $this->monthly->id, 'percent' => 25]);
        $this->assertSame(750_000, $p->quote($r, $this->newPkg)->finalPrice);
        $this->assertSame('reseller_category', $p->quote($r, $this->newPkg)->priceSource);
        $this->assertSame(720_000, $p->quote($r, $this->renewPkg)->finalPrice, 'category rule does not apply to an uncategorised package');

        ResellerPrice::query()->create(['reseller_id' => $r->id, 'package_id' => $this->newPkg->id, 'price_rial' => 820_000]);
        $this->assertSame(820_000, $p->quote($r, $this->newPkg)->finalPrice, 'fixed price wins even if a % rule would be cheaper');
        $this->assertSame('reseller_fixed', $p->quote($r, $this->newPkg)->priceSource);
    }

    public function test_reseller_never_pays_more_than_public_and_rules_do_not_stack(): void
    {
        $r = $this->reseller(global: 10);
        Discount::query()->create(['name' => 'حراج', 'scope' => 'all', 'kind' => 'percent', 'value' => 30, 'is_active' => true]);
        $q = app(ResellerPricing::class)->quote($r, $this->newPkg);
        $this->assertSame(700_000, $q->finalPrice, 'public 30% is lower than reseller 10%: public wins, not 10%+30%');
        $this->assertSame('public_lower_than_reseller_rule', $q->priceSource);
    }

    // ---------------------------------------------------------------- sales

    public function test_successful_new_sale_debits_provisions_and_records_profit(): void
    {
        $r = $this->reseller(global: 20, creditRial: 2_000_000);
        ['sale' => $s, 'password' => $pw] = $this->sell($r, salePrice: 1_100_000);
        $this->assertSame('completed', $s->status);
        $this->assertSame(800_000, $s->cost_rial);
        $this->assertSame(300_000, $s->profit_rial);
        $this->assertSame(1_200_000, $this->balance($r));
        $a = VpnAccount::query()->findOrFail($s->vpn_account_id);
        $this->assertSame($r->id, $a->reseller_id);
        $this->assertSame($pw, $this->fake->users[$a->ibs_user_id]['password']);
        $this->assertSame('reseller_global', $s->price_snapshot['price_source']);
    }

    public function test_insufficient_balance_does_nothing_on_the_server(): void
    {
        $r = $this->reseller(creditRial: 999_999);
        try {
            $this->sell($r);
            $this->fail('expected exception');
        } catch (InsufficientBalanceException) {
        }
        $this->assertNotContains('user.addNewUsers', $this->fake->calls);
        $this->assertSame(999_999, $this->balance($r));
        $this->assertSame('failed', ResellerSale::query()->first()->status);
        $this->assertSame(1, LedgerEntry::query()->count(), 'only the seed credit');
    }

    public function test_resubmitting_the_same_sale_never_debits_twice(): void
    {
        $r = $this->reseller(creditRial: 5_000_000);
        $key = (string) Str::uuid();
        $this->sell($r, key: $key);
        $again = $this->sell($r, key: $key);
        $this->assertSame('completed', $again['sale']->status);
        $this->assertNull($again['password']);
        $this->assertSame(4_000_000, $this->balance($r));
        $this->assertCount(1, $this->fake->users);
        $this->assertSame(1, ResellerSale::query()->count());
    }

    public function test_definite_failure_refunds_automatically(): void
    {
        $r = $this->reseller(creditRial: 1_000_000);
        $this->fake->failNext('user.addNewUsers', new IbsException('ACCESS_DENIED', 'Access Denied'));
        ['sale' => $s] = $this->sell($r);
        $this->assertSame('refunded', $s->status);
        $this->assertSame(1_000_000, $this->balance($r));
        $this->assertSame(['manual_credit', 'purchase', 'refund'], LedgerEntry::query()->orderBy('id')->pluck('type')->all());
        $this->assertTrue(app(WalletService::class)->reconcile(app(WalletService::class)->walletFor('reseller', $r->id))['ok']);
    }

    public function test_compensated_provisioning_refunds(): void
    {
        $r = $this->reseller(creditRial: 1_000_000);
        $this->fake->failNext('user.updateUserAttrs', new IbsException('NORMAL_USERNAME_EXISTS', 'exists'));
        ['sale' => $s] = $this->sell($r);
        $this->assertSame('refunded', $s->status);
        $this->assertCount(0, $this->fake->users);
        $this->assertSame(1_000_000, $this->balance($r));
    }

    public function test_uncertain_outcome_holds_money_then_settles_when_job_completes(): void
    {
        $r = $this->reseller(creditRial: 1_000_000);
        $this->fake->failNext('user.updateUserAttrs', new IbsUnavailableException('timeout'));
        ['sale' => $s] = $this->sell($r);
        $this->assertSame('pending', $s->status, 'job still raw_created: sale stays pending');
        $this->assertSame(0, $this->balance($r), 'money held, not lost, not refunded yet');

        app(Provisioning::class)->run(ProvisioningJob::query()->first());   // cron retry of the job
        $this->assertSame(1, app(ResellerSales::class)->syncPending());   // cron settles the sale
        $s->refresh();
        $this->assertSame('completed', $s->status);
        $this->assertSame($r->id, VpnAccount::query()->find($s->vpn_account_id)->reseller_id);
        $this->assertSame(0, $this->balance($r));
    }

    public function test_step1_timeout_needs_review_and_admin_refund(): void
    {
        $r = $this->reseller(creditRial: 1_000_000);
        $this->fake->failNext('user.addNewUsers', new IbsUnavailableException('timeout'));
        ['sale' => $s] = $this->sell($r);
        $this->assertSame('needs_review', $s->status);
        $this->assertSame(0, $this->balance($r));
        $admin = $this->superAdmin();
        app(ResellerSales::class)->adminRefund($s, 'در سرور ساخته نشده بود', $admin->id);
        $this->assertSame('refunded', $s->fresh()->status);
        $this->assertSame(1_000_000, $this->balance($r));
        $this->expectException(RuntimeException::class);
        app(ResellerSales::class)->adminRefund($s->fresh(), 'twice', $admin->id);
    }

    public function test_renewal_only_on_own_accounts(): void
    {
        $r = $this->reseller(creditRial: 5_000_000);
        $other = $this->reseller('shop2', creditRial: 5_000_000);
        ['sale' => $s] = $this->sell($r);
        $mine = VpnAccount::query()->find($s->vpn_account_id);

        ['sale' => $renew] = $this->sell($r, $this->renewPkg, target: $mine, salePrice: 900_000);
        $this->assertSame('completed', $renew->status);
        $this->assertSame(60 * 86400, $this->fake->users[$mine->ibs_user_id]['attrs']['rel_exp_date']);
        $this->assertSame(5_000_000 - 1_000_000 - 800_000, $this->balance($r));

        $this->expectException(RuntimeException::class);
        $this->sell($other, $this->renewPkg, target: $mine);
    }

    public function test_renewal_with_server_down_goes_to_review(): void
    {
        $r = $this->reseller(creditRial: 5_000_000);
        ['sale' => $s] = $this->sell($r);
        $a = VpnAccount::query()->find($s->vpn_account_id);
        for ($i = 0; $i < 3; $i++) {
            $this->fake->failNext('user.getUserInfo', new IbsUnavailableException('down'));
        }
        ['sale' => $renew] = $this->sell($r, $this->renewPkg, target: $a);
        $this->assertSame('needs_review', $renew->status);
    }

    public function test_suspended_reseller_cannot_sell(): void
    {
        $r = $this->reseller(creditRial: 5_000_000);
        $r->update(['status' => 'suspended']);
        $this->expectException(RuntimeException::class);
        $this->sell($r->fresh());
    }
}
