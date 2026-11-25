<?php

/*
| Permission registry. The database is synced from this file by `php artisan permissions:sync`
| (also run by the installer and on every deploy). Later phases add modules here.
|
| Actions: view, create, update, delete, sensitive (password reset, deactivation, secrets...).
*/

return [
    'actions' => [
        'view' => 'مشاهده',
        'create' => 'ایجاد',
        'update' => 'ویرایش',
        'delete' => 'حذف',
        'sensitive' => 'عملیات حساس',
    ],

    'modules' => [
        'dashboard' => ['title' => 'داشبورد', 'actions' => ['view']],
        'accounts' => ['title' => 'اکانت‌ها', 'actions' => ['view', 'create', 'update', 'delete', 'sensitive']],
        'packages' => ['title' => 'بسته‌ها و دسته‌ها', 'actions' => ['view', 'create', 'update', 'delete']],
        'discounts' => ['title' => 'تخفیف‌ها', 'actions' => ['view', 'create', 'update', 'delete']],
        'resellers' => ['title' => 'همکاران فروش', 'actions' => ['view', 'create', 'update', 'delete']],
        'wallets' => ['title' => 'کیف پول و دفتر کل', 'actions' => ['view', 'sensitive']],
        'ibs_groups' => ['title' => 'گروه‌ها و تعرفه‌های سرور', 'actions' => ['view', 'update']],
        'staff' => ['title' => 'کاربران پنل', 'actions' => ['view', 'create', 'update', 'delete', 'sensitive']],
        'roles' => ['title' => 'نقش‌ها و دسترسی‌ها', 'actions' => ['view', 'create', 'update', 'delete']],
        'audit' => ['title' => 'گزارش رویدادها (Audit)', 'actions' => ['view']],
        'settings' => ['title' => 'تنظیمات', 'actions' => ['view', 'update', 'sensitive']],
        'system' => ['title' => 'وضعیت سرویس‌ها', 'actions' => ['view']],
        'bots' => ['title' => 'ربات‌ها (تلگرام، بله، روبیکا)', 'actions' => ['view', 'update', 'sensitive']],
        'payments' => ['title' => 'پرداخت‌ها و درگاه‌ها', 'actions' => ['view', 'sensitive']],
        'reports' => ['title' => 'گزارش‌ها و خروجی اکسل', 'actions' => ['view', 'export']],
    ],

    // Role that bypasses all checks. Created by the installer; cannot be deleted or edited.
    'super_role' => 'super_admin',

    // System role for reseller logins: no admin permissions; access only to the reseller portal (/r).
    'reseller_role' => 'reseller',
];
