<?php
declare(strict_types=1);

/** How to install Nobatyar as an app on Android, iPhone/iPad and desktop browsers. */

require __DIR__ . '/core.php';
layout_start('نصب برنامه', ['description' => 'نصب نوبت‌یار روی گوشی، تبلت و رایانه']);
render('public/app-install', ['siteName' => schema_is_current_safe() ? site_name() : 'نوبت‌یار']);
layout_end();
