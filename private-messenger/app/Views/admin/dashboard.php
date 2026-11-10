<?php use function PM\Support\e; use PM\Support\Fa; use PM\Settings\Settings; $title = 'داشبورد'; include __DIR__ . '/_top.php';
$n = fn ($v) => e(Fa::digits(number_format((int)$v)));
$min = fn ($s) => e(Fa::digits(number_format(intdiv((int)$s, 60)))); ?>
<h1>داشبورد</h1>
<div class="alert">این پنل هیچ امکانی برای خواندن پیام‌ها یا فایل‌های کاربران ندارد. همه آمارها فقط از اطلاعات غیرمحتوایی (تعداد، زمان، حجم و وضعیت) محاسبه می‌شوند.</div>
<?php foreach ($warnings as $w): ?><div class="alert warn"><?= e($w) ?></div><?php endforeach; ?>
<h2>حساب‌ها</h2>
<div class="stats">
  <div class="stat"><span class="small muted">کل حساب‌ها</span><b><?= $n($o['users_total']) ?></b></div>
  <div class="stat"><span class="small muted">فعال</span><b><?= $n($o['users']['active'] ?? 0) ?></b></div>
  <div class="stat"><span class="small muted">در انتظار فعال‌سازی</span><b><?= $n($o['users']['pending'] ?? 0) ?></b></div>
  <div class="stat"><span class="small muted">غیرفعال (معلق / مسدود)</span><b><?= $n(($o['users']['suspended'] ?? 0) + ($o['users']['blocked'] ?? 0)) ?></b></div>
  <div class="stat"><span class="small muted">آنلاین اکنون</span><b><?= $n($o['online']) ?></b><span class="small">برنامه باز و همگام‌سازی در ۲ دقیقه اخیر</span></div>
  <div class="stat"><span class="small muted">فعال در ۷ روز</span><b><?= $n($o['active_7d']) ?></b></div>
  <div class="stat"><span class="small muted">دستگاه‌های فعال / نشست‌ها</span><b><?= $n($o['devices']) ?> / <?= $n($o['sessions']) ?></b></div>
  <div class="stat"><span class="small muted">حساب‌های قفل موقت</span><b><?= $n($o['locked']) ?></b></div>
</div>
<h2>فعالیت (فقط شمارش فراداده)</h2>
<div class="stats">
  <div class="stat"><span class="small muted">پیام‌ها و رویدادها — ۲۴ ساعت</span><b><?= $n($o['messages']['d1']) ?></b><span class="small">۷ روز: <?= $n($o['messages']['d7']) ?> · کل: <?= $n($o['messages']['total']) ?></span></div>
  <div class="stat"><span class="small muted">گفتگوهای دونفره / گروه‌ها</span><b><?= $n($o['directs']) ?> / <?= $n($o['groups']) ?></b></div>
  <div class="stat"><span class="small muted">در صف تحویل</span><b><?= $n($o['pending_envelopes']) ?></b><span class="small">بسته رمزنگاری‌شده برای دستگاه‌های آفلاین</span></div>
  <div class="stat"><span class="small muted">تماس‌ها — ۲۴ ساعت</span><b><?= $n($o['calls_1d']['n']) ?></b><span class="small"><?= $min($o['calls_1d']['secs']) ?> دقیقه · <?= $n($o['calls_1d']['failed']) ?> ناموفق · <?= $n($o['calls_1d']['missed']) ?> بی‌پاسخ</span></div>
  <div class="stat"><span class="small muted">تماس‌ها — ۷ روز</span><b><?= $n($o['calls_7d']['n']) ?></b><span class="small"><?= $min($o['calls_7d']['secs']) ?> دقیقه مکالمه</span></div>
</div>
<p class="small muted">«پیام‌ها و رویدادها» شامل پیام متنی، فایل، ویرایش، واکنش، رسید خواندن و سیگنال تماس است؛ چون همه رمزنگاری شده‌اند، سرور نوع آن‌ها را نمی‌داند.</p>
<h2>فضا</h2>
<div class="stats">
  <div class="stat"><span class="small muted">فایل‌های ذخیره‌شده</span><b><?= e(Fa::bytes($st['stored'])) ?></b><span class="small"><?= $n($st['files']) ?> فایل · دائمی: <?= e(Fa::bytes($st['permanent'])) ?></span></div>
  <div class="stat"><span class="small muted">منقضی، در انتظار حذف</span><b><?= e(Fa::bytes($st['expired_pending'])) ?></b></div>
  <div class="stat"><span class="small muted">فضای آزاد دیسک</span><b><?= e(Fa::bytes($st['free'])) ?></b><span class="small">گزارش سیستم‌عامل؛ ممکن است سهمیه حساب کمتر باشد</span></div>
  <div class="stat"><span class="small muted">سهمیه هاست</span><b><?= $st['quota'] ? e(Fa::bytes($st['quota'])) : 'نامعلوم' ?></b><?php if ($st['quota']): ?><span class="small">باقی‌مانده (تقریبی، فقط فایل‌ها): <?= e(Fa::bytes(max(0, $st['quota'] - $st['stored']))) ?></span><?php else: ?><span class="small">در تنظیمات وارد کنید</span><?php endif; ?></div>
</div>
<div class="panel">
  <h2>وضعیت سرویس‌ها</h2>
  <div class="health">
  <?php foreach ($health as $h): ?>
    <div><span class="tag <?= $h['ok'] === true ? 'ok' : ($h['ok'] === null ? 'warn' : 'fail') ?>"><?= $h['ok'] === true ? 'سالم' : ($h['ok'] === null ? 'هشدار' : 'خطا') ?></span><b><?= e($h['name']) ?></b><span class="small muted"><?= e($h['detail']) ?></span></div>
  <?php endforeach; ?>
  </div>
</div>
<div class="panel table-wrap">
  <h2>هفت روز اخیر</h2>
  <?php $max = max(1, ...array_map(fn ($r) => (int)$r['messages'], $week)); ?>
  <table><tr><th>روز (UTC)</th><th>پیام/رویداد</th><th></th><th>فرستنده</th><th>ورود</th><th>ورود ناموفق</th><th>تماس</th><th>آپلود</th></tr>
  <?php foreach ($week as $r): ?><tr><td class="ltr"><?= e($r['day']) ?></td><td><?= $n($r['messages']) ?></td><td><span class="bar w<?= (int)round(10 * $r['messages'] / $max) ?>"></span></td>
    <td><?= $n($r['senders']) ?></td><td><?= $n($r['logins']) ?></td><td><?= $n($r['login_failed']) ?></td><td><?= $n($r['calls']) ?></td><td><?= $n($r['uploads']) ?></td></tr><?php endforeach; ?></table>
</div>
<div class="panel small">روش ورود فعلی: <b><?= e(Settings::AUTH_METHODS[$method] ?? $method) ?></b></div>
<?php include __DIR__ . '/_bottom.php'; ?>
