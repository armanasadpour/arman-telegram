<?php use function PM\Support\e; ?>
<!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer"><title>آدرس جدید</title></head>
<body>
<h1>آدرس پنل مدیریت تغییر کرد</h1>
<p>آدرس قبلی از همین لحظه کار نمی‌کند (خطای ۴۰۴). آدرس جدید را همین حالا یادداشت کنید:</p>
<p dir="ltr"><b><?= e($url) ?></b></p>
<p><a href="<?= e($url) ?>">ورود با آدرس جدید</a></p>
<p>اگر آدرس را گم کردید: مقدار ADMIN_PATH در فایل config/.env (از File Manager در cPanel) همان بخش آخر آدرس است.</p>
</body></html>
