<?php

namespace App\Payments;

use App\Models\Payment;

interface Gateway
{
    public function isConfigured(): bool;

    /** scheme://host of the bank pages the payer is sent to (allowed in the payment page's CSP form-action). */
    public function origin(): string;

    /** Register the payment with the bank; sets $payment->gateway_token. Throws PaymentException. */
    public function start(Payment $payment, string $callbackUrl): GatewayRedirect;

    /** Server-to-server verification using the callback parameters (which are not trusted on their own). */
    public function verify(Payment $payment, array $callback): VerifyResult;
}
