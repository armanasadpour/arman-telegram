<?php use function PM\Support\e; use PM\Support\Fa; $title = 'سیستم'; include __DIR__ . '/_top.php'; ?>
<h1>سیستم و قابلیت‌های میزبان</h1>
<?php if ($paths): ?>
<div class="panel">
  <h2>آدرس‌های مخفی</h2>
  <p>برنامه کاربران: <span class="ltr secret"><?= e($paths['app']) ?></span></p>
  <p>پنل مدیریت: <span class="ltr secret"><?= e($paths['admin']) ?></span></p>
  <p class="small muted">اگر آدرسی لو رفته است، یک آدرس تصادفی جدید بسازید. آدرس قبلی بلافاصله خطای ۴۰۴ می‌دهد. بعد از تغییر آدرس برنامه، آدرس جدید را برای کاربران بفرستید؛ کاربرانی که برنامه را روی گوشی نصب کرده‌اند باید آن را حذف و با آدرس جدید دوباره نصب کنند (پیام‌ها و کلیدهای روی دستگاه حفظ می‌شوند، چون به دامنه وابسته‌اند نه به آدرس).</p>
  <form method="post" action="<?= e($base) ?>/system/paths" class="grid2">
    <?php include __DIR__ . '/../_csrf.php'; ?>
    <label class="field"><span>کدام آدرس؟</span><select name="which"><option value="app">برنامه کاربران</option><option value="admin">پنل مدیریت</option></select></label>
    <label class="field"><span>رمز عبور حساب شما</span><input type="password" name="password" class="ltr" autocomplete="current-password" required></label>
    <label class="field"><span>برای تأیید بنویسید: تغییر</span><input type="text" name="confirm" required></label>
    <div class="field"><span>&nbsp;</span><button class="btn danger">ساخت آدرس جدید</button></div>
  </form>
</div>
<?php endif; ?>
<div class="panel">
  <h2>دستور Cron (cPanel ← Cron Jobs)</h2>
  <div class="secret"><?= e($cron) ?></div>
  <p class="small muted">کارها: پاک‌سازی کدهای OTP قدیمی، شمارنده‌های محدودیت نرخ و نشست‌های منقضی. مسیر php را با مسیر هاست خود تطبیق دهید.</p>
</div>
<div class="panel table-wrap">
  <h2>فونت IRANSans</h2>
  <table><?php foreach ($fonts as $w => $f): ?><tr><td><?= e($w) ?></td>
    <td><?= $f ? '<span class="tag ok">موجود</span> <span class="ltr small">' . e($f) . '</span>' : '<span class="tag warn">یافت نشد</span>' ?></td></tr><?php endforeach; ?></table>
  <p class="small muted">محل فایل‌ها: <span class="ltr">app/resources/assets/fonts/</span> — در نبود فایل، Tahoma استفاده می‌شود.</p>
</div>
<div class="panel table-wrap">
  <h2>آخرین اجراهای Cron</h2>
  <table><tr><th>کار</th><th>منبع</th><th>شروع</th><th>وضعیت</th><th>آمار</th></tr>
  <?php foreach ($runs as $r): ?><tr><td class="ltr"><?= e($r['job']) ?></td><td><?= e($r['trigger_source']) ?></td><td><?= e(Fa::dateTime($r['started_at'])) ?></td>
    <td><span class="tag <?= $r['status'] === 'ok' ? 'ok' : 'warn' ?>"><?= e($r['status']) ?></span></td><td class="ltr small"><?= e((string)$r['stats']) ?></td></tr><?php endforeach; ?>
  <?php if (!$runs): ?><tr><td colspan="5" class="muted">هنوز اجرا نشده است.</td></tr><?php endif; ?></table>
</div>
<div class="panel table-wrap">
  <h2>قابلیت‌های میزبان (بررسی همین لحظه)</h2>
  <table><?php foreach ($checks as $c): ?><tr><td><?= e($c['name']) ?></td><td class="ltr"><?= e($c['value']) ?></td><td><span class="tag <?= e($c['status']) ?>"><?= e($c['status']) ?></span></td><td class="small muted"><?= e($c['note']) ?></td></tr><?php endforeach; ?></table>
</div>
<?php include __DIR__ . '/_bottom.php'; ?>
