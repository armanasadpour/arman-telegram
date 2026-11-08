<?php
declare(strict_types=1);

namespace PM\Infra\Sms;

/**
 * TEST/DEVELOPMENT ONLY. Writes OTP codes to storage/logs/sms-test.log instead of sending them.
 * The admin panel shows a permanent red warning while this provider is active.
 */
final class LogSmsProvider implements SmsProvider
{
    public function __construct(private readonly string $file)
    {
    }

    public function sendOtp(string $phoneE164, string $code, string $message): string
    {
        $ref = bin2hex(random_bytes(6));
        file_put_contents($this->file, gmdate('c') . "\t" . $ref . "\t" . $phoneE164 . "\t" . $code . "\n", FILE_APPEND | LOCK_EX);
        @chmod($this->file, 0600);
        return 'log-' . $ref;
    }
}
