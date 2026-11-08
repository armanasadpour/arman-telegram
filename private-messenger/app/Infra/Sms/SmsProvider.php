<?php
declare(strict_types=1);

namespace PM\Infra\Sms;

interface SmsProvider
{
    /** Sends an OTP code. Returns a provider reference id. Throws SmsException on failure. */
    public function sendOtp(string $phoneE164, string $code, string $message): string;
}
