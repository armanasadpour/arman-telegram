<?php use function PM\Support\e; use PM\Support\Fa; use PM\Controllers\AdminController as AC; $title = 'فونت'; include __DIR__ . '/_top.php'; ?>
<h1>فونت IRANSans</h1>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<?php if (!$writable): ?><div class="alert warn">پوشه فونت قابل نوشتن نیست؛ در File Manager مجوز پوشه <span class="ltr">private-messenger/app/resources/assets/fonts</span> را 755 کنید.</div><?php endif; ?>
<div class="panel">
  <p class="small muted">فایل‌های <b>‎.woff2‎</b> فونت را برای هر وزن انتخاب کنید (لازم نیست همه وزن‌ها را داشته باشید؛ حداقل «معمولی» و «ضخیم» پیشنهاد می‌شود). فونت فقط از سرور خودتان و فقط زیر آدرس مخفی بارگذاری می‌شود.</p>
  <table>
    <tr><th>وزن</th><th>وضعیت</th><th></th></tr>
    <?php foreach (AC::FONT_WEIGHTS as $w => $label): $f = $fonts[$w]; ?>
    <tr><td><?= e($label) ?></td>
      <td><?= $f ? '<span class="tag ok">نصب‌شده</span> <span class="ltr small">' . e($f['name']) . '</span> <span class="small muted">' . e(Fa::digits(number_format($f['size'] / 1024))) . ' KB</span>' : '<span class="tag warn">نصب نشده</span>' ?></td>
      <td><?php if ($f && $can_edit): ?><form method="post" action="<?= e($base) ?>/fonts/delete" class="inline"><?php include __DIR__ . '/../_csrf.php'; ?><input type="hidden" name="weight" value="<?= e($w) ?>"><button class="btn danger sm">حذف</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php if ($can_edit): ?>
<form method="post" action="<?= e($base) ?>/fonts" enctype="multipart/form-data" class="panel">
  <?php include __DIR__ . '/../_csrf.php'; ?>
  <h2>بارگذاری فایل‌ها</h2>
  <div class="grid2">
    <?php foreach (AC::FONT_WEIGHTS as $w => $label): ?>
    <label class="field"><span><?= e($label) ?></span><input type="file" name="font_<?= e($w) ?>" accept=".woff2,font/woff2"></label>
    <?php endforeach; ?>
  </div>
  <button class="btn">بارگذاری</button>
</form>
<?php endif; ?>
<?php include __DIR__ . '/_bottom.php'; ?>
