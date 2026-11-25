<?php

namespace App\Payments;

final class VerifyResult
{
    private function __construct(
        public readonly string $outcome,          // paid | failed | uncertain
        public readonly ?string $ref = null,
        public readonly ?string $cardPan = null,
        public readonly ?int $amount = null,
        public readonly ?string $error = null,
    ) {}

    public static function paid(string $ref, ?string $cardPan = null, ?int $amount = null): self
    {
        return new self('paid', $ref, $cardPan, $amount);
    }

    public static function failed(string $error): self
    {
        return new self('failed', error: $error);
    }

    /** The bank could not be reached or answered ambiguously: try verifying again later. */
    public static function uncertain(string $error): self
    {
        return new self('uncertain', error: $error);
    }
}
