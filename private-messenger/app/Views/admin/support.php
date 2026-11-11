<?php use function PM\Support\e; use PM\Support\Fa; $title = 'چت پشتیبانی'; include __DIR__ . '/_top.php'; ?>
<h1>چت پشتیبانی</h1>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<div class="panel small">
  <p>وقتی فعال باشد، بالای فهرست گفتگوهای هر کاربر یک گفتگوی سنجاق‌شده با نام زیر و <b>تیک آبی</b> اضافه می‌شود.</p>
  <p>پیام کاربران برای همه پشتیبان‌های تعیین‌شده می‌رود. پاسخ هر پشتیبان برای کاربر با همین نام نمایش داده می‌شود، نه با نام خود پشتیبان.</p>
  <p>پیام‌ها رمزنگاری سرتاسری دارند و فقط برای دستگاه‌های پشتیبان‌ها رمز می‌شوند. پشتیبانی که بعداً اضافه شود، پیام‌های قبلی را نمی‌بیند.</p>
</div>
<form method="post" action="<?= e($base) ?>/support" class="panel">
  <?php include __DIR__ . '/../_csrf.php'; ?>
  <label class="check"><input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>> چت پشتیبانی فعال باشد</label>
  <label class="field"><span>نام نمایشی پشتیبانی (خالی = «<?= e($effective) ?>»)</span><input type="text" name="name" maxlength="60" value="<?= e($name) ?>"></label>
  <label class="field"><span>نام کاربری پشتیبان‌ها (با فاصله یا ویرگول جدا کنید؛ حداکثر ۱۰ نفر)</span>
    <input type="text" name="agents" class="ltr" value="<?= e(implode(' ', array_column($agents, 'username'))) ?>"></label>
  <button class="btn">ذخیره</button>
</form>
<div class="panel table-wrap"><h2>پشتیبان‌ها</h2><table>
  <tr><th>کاربر</th><th>وضعیت</th><th>دستگاه فعال</th></tr>
  <?php foreach ($agents as $a): ?><tr><td><?= e($a['display_name']) ?> <span class="ltr small muted">@<?= e($a['username']) ?></span></td><td><?= e($a['status']) ?></td>
    <td><?= (int)$a['devices'] ? e(Fa::digits((int)$a['devices'])) : '<span class="tag warn">هنوز وارد برنامه نشده — پیامی دریافت نمی‌کند</span>' ?></td></tr><?php endforeach; ?>
  <?php if (!$agents): ?><tr><td colspan="3" class="muted">هنوز پشتیبانی تعیین نشده است؛ بدون پشتیبان، چت پشتیبانی نمایش داده نمی‌شود.</td></tr><?php endif; ?></table>
  <p class="small muted">گفتگوهای پشتیبانی: <?= e(Fa::digits((int)($stats['chats'] ?? 0))) ?> · پیام‌ها: <?= e(Fa::digits((int)($stats['messages'] ?? 0))) ?> · آخرین پیام: <?= e(Fa::dateTime($stats['last'] ?? null)) ?></p></div>
<?php include __DIR__ . '/_bottom.php'; ?>
