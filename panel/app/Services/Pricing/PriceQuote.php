<?php

namespace App\Services\Pricing;

/**
 * Immutable result of pricing one package at one moment. snapshot() is what orders store:
 * later edits to the package or discounts never change an existing snapshot.
 */
final class PriceQuote
{
    /**
     * @param  array<string,mixed>  $package  frozen package fields
     * @param  array<string,mixed>|null  $discount  frozen discount fields (null = none applied)
     */
    public function __construct(
        public readonly array $package,
        public readonly int $basePrice,
        public readonly ?array $discount,
        public readonly int $discountAmount,
        public readonly int $finalPrice,
        public readonly string $computedAt,
        public readonly string $priceSource = 'public',
    ) {}

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $data = [
            'v' => 1,
            'package' => $this->package,
            'currency' => 'IRR',
            'base_price' => $this->basePrice,
            'discount' => $this->discount,
            'discount_amount' => $this->discountAmount,
            'final_price' => $this->finalPrice,
            'price_source' => $this->priceSource,
            'computed_at' => $this->computedAt,
        ];
        $data['hash'] = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $data;
    }
}
