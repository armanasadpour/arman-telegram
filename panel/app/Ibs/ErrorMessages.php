<?php

namespace App\Ibs;

use Throwable;

/** Turns IBSng / transport errors into short Persian messages for the UI. */
final class ErrorMessages
{
    private const CODES = [
        'NORMAL_USERNAME_EXISTS' => 'این نام کاربری در سرور وجود دارد.',
        'NORMAL_USERNAME_DOESNT_EXISTS' => 'این نام کاربری در سرور وجود ندارد.',
        'USERID_DOESNT_EXISTS' => 'این اکانت در سرور وجود ندارد (احتمالاً حذف شده است).',
        'BAD_NORMAL_USERNAME' => 'نام کاربری شامل کاراکتر غیرمجاز است.',
        'GROUP_NAME_INVALID' => 'گروه انتخاب‌شده در سرور وجود ندارد.',
        'ACCESS_DENIED' => 'مدیر سرور تنظیم‌شده اجازه این عملیات را ندارد.',
        'INCORRECT_PASSWORD' => 'رمز مدیر سرور نادرست است.',
        'ADMIN_USERNAME_INVALID' => 'نام کاربری مدیر سرور وجود ندارد.',
        'LOGIN_FROM_THIS_IP_DENIED' => 'مدیر سرور اجازه ورود از IP این هاست را ندارد.',
        'ADMIN_LOCKED' => 'حساب مدیر سرور قفل است.',
        'INVALID_MULTI_LOGIN' => 'تعداد اتصال همزمان نامعتبر است (۰ تا ۲۵۵).',
        'INVALID_PASSWORD_LENGTH' => 'طول رمز نامعتبر است.',
    ];

    public static function for(Throwable $e): string
    {
        if ($e instanceof IbsException) {
            return self::CODES[$e->ibsCode] ?? ('IBSng: '.mb_substr($e->getMessage(), 0, 200));
        }
        if ($e instanceof IbsNotConfiguredException) {
            return $e->getMessage();
        }
        if ($e instanceof IbsUnavailableException) {
            $m = $e->getMessage();

            return str_contains($m, 'موقتاً') ? $m : 'ارتباط با سرور برقرار نشد: '.mb_substr($m, 0, 160);
        }

        return 'خطای غیرمنتظره در ارتباط با سرور.';
    }
}
