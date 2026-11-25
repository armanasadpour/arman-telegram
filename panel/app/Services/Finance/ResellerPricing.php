<?php

namespace App\Services\Finance;

use App\Models\Package;
use App\Models\Reseller;
use App\Services\Pricing\PriceQuote;
use App\Services\Pricing\PricingEngine;

/**
 * Reseller purchase price. Exactly ONE rule applies, in this order:
 *   1. fixed price for this package
 *   2. reseller's category discount (% of base price)
 *   3. reseller's global discount (% of base price)
 *   4. otherwise the public price (with public discounts, phase 4)
 * Guard: the reseller never pays more than the public price at that moment (the lower one is
 * chosen; nothing is stacked).
 */
class ResellerPricing
{
    public function __construct(private PricingEngine $engine) {}

    public function quote(Reseller $reseller, Package $package): PriceQuote
    {
        $public = $this->engine->quote($package);
        $base = (int) $package->price_rial;

        [$source, $price, $rule] = $this->rulePrice($reseller, $package, $base);
        if ($source === null || $price >= $public->finalPrice) {
            return new PriceQuote($public->package, $public->basePrice, $public->discount, $public->discountAmount, $public->finalPrice,
                $public->computedAt, $source === null ? 'public' : 'public_lower_than_reseller_rule');
        }

        return new PriceQuote(
            package: PricingEngine::freeze($package),
            basePrice: $base,
            discount: $rule + ['reseller_id' => $reseller->id, 'reseller_code' => $reseller->code],
            discountAmount: $base - $price,
            finalPrice: $price,
            computedAt: $public->computedAt,
            priceSource: $source,
        );
    }

    /** @return array{0:?string,1:int,2:array<string,mixed>} */
    private function rulePrice(Reseller $r, Package $p, int $base): array
    {
        $fixed = $r->prices()->where('package_id', $p->id)->first();
        if ($fixed) {
            return ['reseller_fixed', (int) $fixed->price_rial, ['name' => 'قیمت اختصاصی همکار', 'kind' => 'fixed', 'value' => (int) $fixed->price_rial]];
        }
        if ($p->category_id) {
            $cat = $r->categoryDiscounts()->where('category_id', $p->category_id)->first();
            if ($cat && (float) $cat->percent > 0) {
                return ['reseller_category', $this->applyPercent($base, (float) $cat->percent), ['name' => 'تخفیف دسته‌ای همکار', 'kind' => 'percent', 'value' => (float) $cat->percent]];
            }
        }
        if ((float) $r->global_discount_percent > 0) {
            return ['reseller_global', $this->applyPercent($base, (float) $r->global_discount_percent), ['name' => 'تخفیف سراسری همکار', 'kind' => 'percent', 'value' => (float) $r->global_discount_percent]];
        }

        return [null, $base, []];
    }

    private function applyPercent(int $base, float $percent): int
    {
        $percent = min(100.0, max(0.0, $percent));

        return $this->engine->round(max(0, $base - (int) floor($base * $percent / 100)));
    }
}
