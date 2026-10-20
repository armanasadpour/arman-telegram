<?php
/** Shared center identity form (registration and profile). @var array $v @var bool $registering */
$v = $v ?? [];
?>
<fieldset>
  <legend>مشخصات مرکز</legend>
  <div class="form-grid">
    <?php input('نام مرکز', 'center_name', $v['center_name'] ?? ($v['name'] ?? ''), 'text', true, 190); ?>
    <label>نوع مرکز<select name="kind" required><option value="">انتخاب کنید</option>
      <?php foreach (center_kinds() as $k => $l): ?><option value="<?= $k ?>"<?= selected_attr(($v['kind'] ?? '') === $k) ?>><?= esc($l) ?></option><?php endforeach; ?>
    </select></label>
    <?php input('تلفن مرکز با پیش‌شماره', 'phone', $v['phone'] ?? '', 'tel', true, 11); ?>
  </div>
  <?php location_fields($v); ?>
  <?php input('نشانی کامل', 'address', $v['address'] ?? '', 'text', true, 2000); ?>
  <?php map_fields($v); ?>
  <label>معرفی مرکز و خدمات (نمایش در صفحه عمومی)<textarea name="description" maxlength="3000"><?= esc($v['description'] ?? '') ?></textarea></label>
</fieldset>
<fieldset>
  <legend>احراز هویت و مجوز</legend>
  <div class="form-grid">
    <?php input('شماره پروانه بهره‌برداری', 'license_number', $v['license_number'] ?? '', 'text', true, 40); ?>
    <?php input('شناسه ملی شخص حقوقی (۱۱ رقم، اختیاری)', 'national_id', $v['national_id'] ?? '', 'text', false, 11); ?>
    <?php input('نام مدیر یا صاحب پروانه', 'manager_name', $v['manager_name'] ?? '', 'text', true, 190); ?>
    <?php input('نام مسئول فنی (اختیاری)', 'technical_manager', $v['technical_manager'] ?? '', 'text', false, 190); ?>
    <?php input('شماره نظام مسئول فنی (اختیاری)', 'technical_code', $v['technical_code'] ?? '', 'text', false, 30); ?>
  </div>
  <label>تصویر پروانه بهره‌برداری<?= empty($registering) ? ' (فقط برای جایگزینی)' : '' ?>
    <input type="file" name="document" accept="image/jpeg,image/png,image/webp"<?= !empty($registering) ? ' required' : '' ?>></label>
  <p class="muted">JPEG، PNG یا WebP تا ۴ مگابایت. تصویر فقط برای مدیر سامانه و مالک مرکز قابل مشاهده است.</p>
  <label>لوگوی مرکز (اختیاری)<input type="file" name="logo" accept="image/jpeg,image/png,image/webp"></label>
</fieldset>
