<?php

namespace App\Services\Pricing;

use App\Models\Discount;
use App\Models\Package;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Public price of a package at a moment in time.
 *
 * Rules (docs/phase-4/README.md):
 *  - At most ONE discount; discounts never stack.
 *  - Most specific scope wins: package > category > all.
 *  - Within the same scope: higher priority wins; on a tie, the larger discount for the customer.
 *  - Window: starts_at inclusive, ends_at exclusive; inactive discounts ignored.
 *  - Discounted price >= 0, rounded DOWN to the rounding unit (default 1000 rial).
 *
 * Phase 5 adds reseller pricing in front of this chain (fixed reseller price ->
 * reseller category discount -> reseller global discount -> this public price).
 */
class PricingEngine
{
    public function __construct(private Settings $settings) {}

    public function quote(Package $package, ?DateTimeInterface $at = null, ?Collection $discounts = null): PriceQuote
    {
        $at ??= now();
        $base = (int) $package->price_rial;
        $best = $this->selectDiscount($package, $at, $discounts ?? Discount::query()->where('is_active', true)->get());
        $amount = $best ? $this->amountFor($best, $base) : 0;
        // Rounding only when a discount applied: an operator-entered base price is never altered.
        $final = $best ? $this->round(max(0, $base - $amount)) : $base;

        return new PriceQuote(
            package: self::freeze($package),
            basePrice: $base,
            discount: $best ? [
                'id' => $best->id, 'name' => $best->name, 'scope' => $best->scope, 'kind' => $best->kind,
                'value' => $best->value, 'priority' => $best->priority,
            ] : null,
            discountAmount: $base - $final,
            finalPrice: $final,
            computedAt: CarbonImmutable::instance($at)->utc()->toIso8601String(),
        );
    }

    /** @param Collection<int,Discount> $all */
    public function selectDiscount(Package $package, DateTimeInterface $at, Collection $all): ?Discount
    {
        $base = (int) $package->price_rial;

        return $all
            ->filter(fn (Discount $d) => $d->isLiveAt($at) && $this->appliesTo($d, $package))
            ->sort(function (Discount $a, Discount $b) use ($base) {
                return [Discount::SPECIFICITY[$a->scope], -$a->priority, -$this->amountFor($a, $base), $a->id]
                    <=> [Discount::SPECIFICITY[$b->scope], -$b->priority, -$this->amountFor($b, $base), $b->id];
            })
            ->first();
    }

    public function appliesTo(Discount $d, Package $p): bool
    {
        return match ($d->scope) {
            'all' => true,
            'category' => $p->category_id !== null && (int) $d->category_id === (int) $p->category_id,
            'package' => (int) $d->package_id === (int) $p->id,
            default => false,
        };
    }

    public function amountFor(Discount $d, int $base): int
    {
        return match ($d->kind) {
            'percent' => intdiv($base * min(100, max(0, $d->value)), 100),
            'amount' => min($base, max(0, $d->value)),
            default => 0,
        };
    }

    public function round(int $rial): int
    {
        $unit = max(1, (int) $this->settings->get('pricing.rounding_rial', '1000'));

        return intdiv($rial, $unit) * $unit;
    }

    /** @return array<string,mixed> */
    public static function freeze(Package $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'type' => $p->type, 'category_id' => $p->category_id,
            'volume_gb' => $p->volume_gb !== null ? (float) $p->volume_gb : null, 'duration_days' => $p->duration_days,
            'multi_login' => $p->multi_login, 'ibs_group_name' => $p->ibs_group_name, 'price_rial' => (int) $p->price_rial,
            'protocol' => $p->protocol ?? 'openvpn', 'mt_group_id' => $p->mt_group_id,
        ];
    }
}
