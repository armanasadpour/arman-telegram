<?php use function PM\Support\e; use PM\Support\Fa; use PM\Moderation\PolicyService as P; $title = 'سرویس‌ها'; include __DIR__ . '/_top.php'; ?>
<h1>فعال / غیرفعال کردن سرویس‌ها</h1>
<div class="panel">
  <h2>برای همه کاربران</h2>
  <p class="small muted">تیک = آن سرویس برای همه غیرفعال است. «ارسال پیام در گروه/کانال» برای همه گروه‌ها و کانال‌ها اعمال می‌شود. برای یک کاربر خاص از صفحه همان کاربر، و برای یک گروه/کانال خاص از صفحه «گروه‌ها و کانال‌ها» اقدام کنید.</p>
  <?php $action = '/services'; $keys = array_keys(P::SERVICES); $current = $global; include __DIR__ . '/_services_form.php'; ?>
</div>
<div class="panel table-wrap">
  <h2>محدودیت‌های کاربر یا گروه/کانال خاص</h2>
  <table><tr><th>سرویس</th><th>برای</th><th>تا</th><th>دلیل</th><th>توسط</th><th></th></tr>
  <?php foreach ($scoped as $p): ?><tr><td><?= e(P::SERVICES[$p['service']] ?? $p['service']) ?></td>
    <td><?php if ($p['scope'] === 'user'): ?><a href="<?= e($base) ?>/users/<?= (int)$p['scope_id'] ?>" class="ltr">@<?= e((string)$p['username']) ?></a><?php else: ?><a href="<?= e($base) ?>/conversations/<?= (int)$p['scope_id'] ?>">گروه/کانال #<?= e(Fa::digits((int)$p['scope_id'])) ?></a><?php endif; ?></td>
    <td><?= $p['until_at'] ? e(Fa::dateTime($p['until_at'])) : 'دائمی' ?></td><td class="small"><?= e((string)$p['reason']) ?></td><td class="ltr small"><?= e((string)$p['admin']) ?></td>
    <td><form method="post" action="<?= e($base) ?>/services/<?= (int)$p['id'] ?>/remove" class="inline"><?php include __DIR__ . '/../_csrf.php'; ?><button class="btn secondary sm">برداشتن</button></form></td></tr>
  <?php endforeach; if (!$scoped): ?><tr><td colspan="6" class="muted">موردی نیست.</td></tr><?php endif; ?></table>
</div>
<div class="panel small muted">
  <p>سرور این‌ها را خودش کنترل می‌کند: ساخت گروه و کانال، ارسال در گروه/کانال، شروع تماس، و پیوست فایل.</p>
  <p>نوع پیام (متن، عکس، ویدیو، صوت یا فایل) و صوتی یا تصویری بودن تماس را فقط دستگاه فرستنده می‌داند، چون محتوا رمزنگاری سرتاسری دارد. برنامه رسمی این محدودیت‌ها را دقیق اجرا می‌کند و سرور هم نوعی را که برنامه اعلام می‌کند بررسی می‌کند. اما یک نسخه دست‌کاری‌شده از برنامه می‌تواند نوع پیام را اشتباه اعلام کند.</p>
</div>
<?php include __DIR__ . '/_bottom.php'; ?>
