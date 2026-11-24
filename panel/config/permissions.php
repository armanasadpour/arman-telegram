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
        'ibs_groups' => ['title' => 'گروه‌ها و تعرفه‌های IBSng', 'actions' => ['view', 'update']],
        'staff' => ['title' => 'کاربران پنل', 'actions' => ['view', 'create', 'update', 'delete', 'sensitive']],
        'roles' => ['title' => 'نقش‌ها و دسترسی‌ها', 'actions' => ['view', 'create', 'update', 'delete']],
        'audit' => ['title' => 'گزارش رویدادها (Audit)', 'actions' => ['view']],
        'settings' => ['title' => 'تنظیمات', 'actions' => ['view', 'update', 'sensitive']],
        'system' => ['title' => 'وضعیت سرویس‌ها', 'actions' => ['view']],
    ],

    // Role that bypasses all checks. Created by the installer; cannot be deleted or edited.
    'super_role' => 'super_admin',
];
