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
  <h2>اعلان وقتی برنامه بسته است (Web Push)</h2>
  <p class="small">وضعیت: <b><?= $push_on ? 'فعال' : 'غیرفعال (تنظیمات یا افزونه‌های PHP)' ?></b> · پراکسی اعلان: <b><?= $push_proxy ? 'تنظیم شده' : 'ندارد' ?></b></p>
  <?php if ($push_overview): ?><div class="table-wrap"><table><tr><th>سرویس</th><th>دستگاه</th><th>آخرین ارسال موفق</th><th>خطا</th></tr>
    <?php foreach ($push_overview as $o): ?><tr><td class="ltr"><?= e($o['host']) ?></td><td><?= e(Fa::digits($o['devices'])) ?></td><td><?= e(Fa::dateTime($o['last_success'])) ?></td>
      <td class="ltr small"><?= $o['failing'] ? e(Fa::digits($o['failing']) . ' — ' . (string)$o['last_error']) : '—' ?></td></tr><?php endforeach; ?></table></div>
  <?php else: ?><p class="small muted">هنوز هیچ دستگاهی برای اعلان پس‌زمینه ثبت نشده است.</p><?php endif; ?>
  <?php if ($reach !== null): ?><div class="health mt"><?php foreach ($reach as $r): ?>
    <div><span class="tag <?= $r['ok'] ? 'ok' : 'fail' ?>"><?= $r['ok'] ? 'در دسترس' : 'در دسترس نیست' ?></span><b><?= e($r['label']) ?></b><span class="small muted ltr"><?= e($r['host'] . ($r['ok'] ? ' (HTTP ' . $r['code'] . ')' : ' — ' . $r['error'])) ?></span></div>
  <?php endforeach; ?></div>
  <p class="small muted">«در دسترس» یعنی این سرور می‌تواند به آن سرویس وصل شود. اگر سرویس گوگل در دسترس نیست، اعلان پس‌زمینه روی Chrome و اندروید نمی‌رسد؛ «پراکسی اعلان» را در تنظیمات وارد کنید. اگر وصل می‌شود ولی ارسال‌ها خطای ۴۰۳ می‌گیرند، معمولاً IP سرور (مثلاً داخل ایران) مسدود است و باز هم پراکسی راه‌حل است.</p>
  <?php endif; ?>
  <a class="btn secondary sm mt" href="<?= e($base) ?>/system?push=1">بررسی دسترسی سرور به سرویس‌های اعلان</a>
</div>
<div class="panel">
  <h2>کارهای زمان‌بندی‌شده (Cron) <?= $cron_real ? '<span class="tag ok">آخرین اجرای واقعی: ' . e(Fa::dateTime($cron_real)) . '</span>' : '<span class="tag warn">Cron واقعی هنوز اجرا نشده</span>' ?></h2>
  <p class="small">بدون Cron هم برنامه کار می‌کند: کارهای نگهداری هنگام بازدید کاربران انجام می‌شود. ولی برای حذف به‌موقع فایل‌های منقضی و پایان خودکار تعلیق‌ها و مسدودیت‌ها، یکی از دو روش زیر را فعال کنید.</p>
  <h3>روش ۱ — Cron Jobs در cPanel (پیشنهادی)</h3>
  <ol class="small">
    <li>وارد cPanel شوید و در بخش «Advanced» روی <b>Cron Jobs</b> بزنید.</li>
    <li>در «Common Settings» گزینه <b>Once Per Fifteen Minutes</b> (هر ۱۵ دقیقه) را انتخاب کنید.</li>
    <li>دستور زیر را کامل در کادر <b>Command</b> بچسبانید و «Add New Cron Job» را بزنید.</li>
  </ol>
  <div class="secret"><?= e(preg_replace('/^\S+ \S+ \S+ \S+ \S+ /', '', $cron)) ?></div>
  <p class="small muted">اگر بعد از ۱۵ دقیقه بالای همین بخش «آخرین اجرای واقعی» نمایش داده نشد، مسیر php هاست شما فرق دارد. به‌جای دستور بالا، این دستور را بگذارید (با curl و همان آدرس روش ۲):</p>
  <div class="secret">curl -fsS "<?= e($cron_url) ?>" &gt; /dev/null</div>
  <h3 class="mt">روش ۲ — سرویس Cron اینترنتی (اگر هاست Cron ندارد)</h3>
  <p class="small">در یک سرویس مانند cron-job.org، این آدرس را برای اجرا «هر ۱۵ دقیقه» ثبت کنید. آدرس محرمانه است و فقط کارهای نگهداری را اجرا می‌کند:</p>
  <div class="secret"><?= e($cron_url) ?></div>
  <details class="small mt"><summary>دستور کامل با زمان‌بندی (برای مدیران فنی)</summary><div class="secret"><?= e($cron) ?></div></details>
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
