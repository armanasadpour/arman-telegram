<?php
declare(strict_types=1);

namespace PM\Infra\Sms;

use PM\Settings\Settings;

final class SmsFactory
{
    public const PROVIDERS = [
        'none' => 'تنظیم نشده',
        'kavenegar' => 'کاوه‌نگار (verify/lookup)',
        'smsir' => 'sms.ir (send/verify)',
        'generic' => 'وب‌هوک HTTPS عمومی',
        'log' => 'فقط تست — ذخیره کد در فایل لاگ (هرگز در استفاده واقعی)',
    ];

    public static function make(Settings $s, string $logFile): ?SmsProvider
    {
        return match ($s->get('sms.provider')) {
            'kavenegar' => new KavenegarProvider($s->secret('sms.api_key'), (string)$s->get('sms.template')),
            'smsir' => new SmsIrProvider($s->secret('sms.api_key'), (int)$s->get('sms.template'), (string)$s->get('sms.param_name')),
            'generic' => new GenericHttpProvider(
                (string)$s->get('sms.generic_method'),
                (string)$s->get('sms.generic_url'),
                (string)$s->get('sms.generic_body'),
                (string)$s->get('sms.generic_auth_header'),
                $s->secret('sms.api_key'),
            ),
            'log' => new LogSmsProvider($logFile),
            default => null,
        };
    }
}
