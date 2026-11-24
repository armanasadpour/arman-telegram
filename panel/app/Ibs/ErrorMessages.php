<?php

namespace App\Ibs;

use Throwable;

/** Turns IBSng / transport errors into short Persian messages for the UI. */
final class ErrorMessages
{
    private const CODES = [
        'NORMAL_USERNAME_EXISTS' => 'این نام کاربری در IBSng وجود دارد.',
        'NORMAL_USERNAME_DOESNT_EXISTS' => 'این نام کاربری در IBSng وجود ندارد.',
        'USERID_DOESNT_EXISTS' => 'این اکانت در IBSng وجود ندارد (احتمالاً حذف شده است).',
        'BAD_NORMAL_USERNAME' => 'نام کاربری شامل کاراکتر غیرمجاز است.',
        'GROUP_NAME_INVALID' => 'گروه انتخاب‌شده در IBSng وجود ندارد.',
        'ACCESS_DENIED' => 'مدیر IBSng تنظیم‌شده اجازه این عملیات را ندارد.',
        'INCORRECT_PASSWORD' => 'رمز مدیر IBSng نادرست است.',
        'ADMIN_USERNAME_INVALID' => 'نام کاربری مدیر IBSng وجود ندارد.',
        'LOGIN_FROM_THIS_IP_DENIED' => 'مدیر IBSng اجازه ورود از IP این هاست را ندارد.',
        'ADMIN_LOCKED' => 'حساب مدیر IBSng قفل است.',
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

            return str_contains($m, 'موقتاً') ? $m : 'ارتباط با IBSng برقرار نشد: '.mb_substr($m, 0, 160);
        }

        return 'خطای غیرمنتظره در ارتباط با IBSng.';
    }
}
