<?php

namespace Tests\Feature\Pricing;

use App\Models\Discount;
use App\Models\Package;
use App\Models\PackageCategory;
use App\Services\Pricing\PricingEngine;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class PricingEngineTest extends TestCase
{
    private PackageCategory $monthly;

    private Package $pkg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->monthly = PackageCategory::query()->create(['name' => 'ماهانه']);
        $this->pkg = Package::query()->create(['name' => 'یک‌ماهه', 'type' => 'renewal', 'duration_days' => 30, 'price_rial' => 1_500_000, 'category_id' => $this->monthly->id]);
    }

    private function discount(array $a): Discount
    {
        return Discount::query()->create($a + ['name' => 'd', 'scope' => 'all', 'kind' => 'percent', 'value' => 10, 'priority' => 0, 'is_active' => true]);
    }

    private function quote(?CarbonImmutable $at = null)
    {
        return app(PricingEngine::class)->quote($this->pkg->fresh(), $at);
    }

    public function test_no_discount_keeps_base_price_unrounded(): void
    {
        $this->pkg->update(['price_rial' => 1_234_567]);
        $q = $this->quote();
        $this->assertNull($q->discount);
        $this->assertSame(1_234_567, $q->finalPrice);
    }

    public function test_percent_and_amount_discounts_with_round_down(): void
    {
        $this->discount(['value' => 15]);                                // 1,500,000 * 15% = 225,000 -> 1,275,000
        $this->assertSame(1_275_000, $this->quote()->finalPrice);

        Discount::query()->delete();
        $this->pkg->update(['price_rial' => 1_234_567]);
        $this->discount(['value' => 10]);                                // 1,111,110.3 -> floor to 1000 -> 1,111,000
        $this->assertSame(1_111_000, $this->quote()->finalPrice);
        $this->assertSame(1_234_567 - 1_111_000, $this->quote()->discountAmount);

        Discount::query()->delete();
        $this->discount(['kind' => 'amount', 'value' => 5_000_000]);     // larger than price -> free, never negative
        $this->assertSame(0, $this->quote()->finalPrice);
    }

    public function test_most_specific_scope_wins_and_discounts_never_stack(): void
    {
        $this->discount(['name' => 'all-50', 'scope' => 'all', 'value' => 50, 'priority' => 100]);
        $this->discount(['name' => 'cat-20', 'scope' => 'category', 'category_id' => $this->monthly->id, 'value' => 20]);
        $this->assertSame('cat-20', $this->quote()->discount['name'], 'category beats all even with lower priority and smaller value');
        $this->assertSame(1_200_000, $this->quote()->finalPrice, 'only one discount applied');

        $this->discount(['name' => 'pkg-5', 'scope' => 'package', 'package_id' => $this->pkg->id, 'value' => 5]);
        $this->assertSame('pkg-5', $this->quote()->discount['name']);
        $this->assertSame(1_425_000, $this->quote()->finalPrice);
    }

    public function test_within_same_scope_priority_then_bigger_discount(): void
    {
        $this->discount(['name' => 'p0-30', 'value' => 30, 'priority' => 0]);
        $this->discount(['name' => 'p5-10', 'value' => 10, 'priority' => 5]);
        $this->assertSame('p5-10', $this->quote()->discount['name']);
        $this->discount(['name' => 'p5-amount', 'kind' => 'amount', 'value' => 200_000, 'priority' => 5]);
        $this->assertSame('p5-amount', $this->quote()->discount['name'], 'tie on priority -> larger discount for the customer');
    }

    public function test_category_discount_does_not_leak_to_other_categories(): void
    {
        $other = PackageCategory::query()->create(['name' => 'سالانه']);
        $this->discount(['scope' => 'category', 'category_id' => $other->id, 'value' => 40]);
        $this->assertNull($this->quote()->discount);
        $this->pkg->update(['category_id' => null]);
        $this->assertNull($this->quote()->discount);
    }

    public function test_date_window_start_inclusive_end_exclusive_and_inactive_ignored(): void
    {
        $start = CarbonImmutable::parse('2026-12-01 00:00:00', 'UTC');
        $end = CarbonImmutable::parse('2026-12-02 00:00:00', 'UTC');
        $this->discount(['value' => 10, 'starts_at' => $start, 'ends_at' => $end]);

        $this->assertNull($this->quote($start->subSecond())->discount);
        $this->assertNotNull($this->quote($start)->discount, 'start is inclusive');
        $this->assertNotNull($this->quote($end->subSecond())->discount);
        $this->assertNull($this->quote($end)->discount, 'end is exclusive');

        Discount::query()->update(['is_active' => false]);
        $this->assertNull($this->quote($start)->discount);
    }

    public function test_snapshot_is_immutable_after_package_or_discount_changes(): void
    {
        $this->discount(['value' => 20]);
        $snap = $this->quote()->snapshot();

        $this->pkg->update(['price_rial' => 9_990_000, 'name' => 'renamed']);
        Discount::query()->update(['value' => 90]);

        $this->assertSame(1_200_000, $snap['final_price']);
        $this->assertSame('یک‌ماهه', $snap['package']['name']);
        $this->assertSame(20, $snap['discount']['value']);
        $check = $snap;
        unset($check['hash']);
        $this->assertSame($snap['hash'], hash('sha256', json_encode($check, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }

    public function test_rounding_unit_is_configurable(): void
    {
        app(Settings::class)->set('pricing.rounding_rial', '1');
        $this->pkg->update(['price_rial' => 1_234_567]);
        $this->discount(['value' => 10]);
        $this->assertSame(1_111_111, $this->quote()->finalPrice);
    }

    public function test_money_display(): void
    {
        $this->assertSame('۱۵۰٬۰۰۰ تومان', fa_money(1_500_000));
        app(Settings::class)->set('pricing.display_currency', 'rial');
        $this->app->forgetInstance(Settings::class);
        $this->assertSame('۱٬۵۰۰٬۰۰۰ ریال', fa_money(1_500_000));
    }
}
