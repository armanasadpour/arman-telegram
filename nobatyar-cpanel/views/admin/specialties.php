<?php
/** @var array $rows @var array $edit @var array $archived @var array $parents @var array $usage */
$levels = specialty_levels();
$icons = specialty_icons();
?>
<div class="grid-2 align-start">
  <section class="panel">
    <div class="panel-head"><h2>فهرست تخصص‌ها</h2><span class="muted">ترتیب نمایش با دکمه‌های بالا/پایین</span></div>
    <?php $activeCount = count(array_filter($rows, fn($r) => (int) $r['active'] === 1)); ?>
    <div class="<?= $activeCount ? 'muted small' : 'notice' ?> specialty-starter">
      <?php if (!$activeCount): ?><p><strong>هنوز هیچ تخصص فعالی تعریف نشده است.</strong> پزشکان هنگام ثبت‌نام تخصص را دستی می‌نویسند و جستجو بر اساس تخصص کار نمی‌کند.</p><?php endif; ?>
      <form method="post" class="inline-form">
        <?php form_token('specialty_starter'); ?>
        <button class="<?= $activeCount ? 'link-button' : 'button' ?>"><?= icon('plus') ?> افزودن فهرست پیشنهادی (<?= fa_number(count(specialty_starter_list())) ?> تخصص رایج؛ موارد موجود تکراری نمی‌شوند)</button>
      </form>
    </div>
    <?php $currentLevel = null; ?>
    <?php foreach ($rows as $r): ?>
      <?php if ($currentLevel !== $r['level']): $currentLevel = $r['level']; ?>
        <h3 class="list-group-title"><?= esc($levels[$r['level']] ?? $r['level']) ?></h3>
      <?php endif; ?>
      <div class="row-item">
        <?= specialty_visual($r) ?>
        <div class="row-main">
          <strong><?= esc(specialty_display_title($r['name'], $r['level'] ?? null)) ?></strong>
          <small><?= $r['parent_name'] ? 'زیرمجموعه ' . esc($r['parent_name']) . ' • ' : '' ?><?= fa_number($usage[$r['id']] ?? 0) ?> پزشک • <?= $r['active'] ? 'فعال' : 'غیرفعال' ?></small>
        </div>
        <div class="row-actions">
          <?php foreach (['up' => 'بالا', 'down' => 'پایین'] as $dir => $label): ?>
            <form method="post" class="inline-form">
              <?php form_token('move'); ?>
              <input type="hidden" name="type" value="specialties"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="direction" value="<?= $dir ?>">
              <button class="icon-button" title="<?= $label ?>" aria-label="انتقال به <?= $label ?>"><?= icon($dir === 'up' ? 'arrow-up' : 'arrow-down') ?></button>
            </form>
          <?php endforeach; ?>
          <a href="admin.php?tab=specialties&amp;edit=<?= (int) $r['id'] ?>">ویرایش</a>
          <form method="post" class="inline-form" data-confirm="این تخصص بایگانی شود؟ پزشکان دارای این تخصص مانع بایگانی هستند.">
            <?php form_token('delete_taxonomy'); ?>
            <input type="hidden" name="type" value="specialties"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button class="danger small">حذف</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$rows): ?><p class="empty-state">هنوز تخصصی تعریف نشده است.</p><?php endif; ?>
    <?php if ($archived): ?>
      <details>
        <summary>تخصص‌های بایگانی‌شده (<?= fa_number(count($archived)) ?>)</summary>
        <?php foreach ($archived as $r): ?>
          <div class="row-item">
            <div class="row-main"><strong><?= esc(specialty_display_title($r['name'], $r['level'] ?? null)) ?></strong><small>بایگانی: <?= esc(jdate($r['deleted_at'], true)) ?></small></div>
            <form method="post" class="inline-form">
              <?php form_token('restore_specialty'); ?>
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="secondary small">بازگردانی</button>
            </form>
          </div>
        <?php endforeach; ?>
      </details>
    <?php endif; ?>
  </section>

  <section class="panel sticky">
    <h2><?= !empty($edit['id']) ? 'ویرایش تخصص' : 'افزودن تخصص' ?></h2>
    <form method="post" enctype="multipart/form-data" class="stack">
      <?php form_token('taxonomy'); ?>
      <input type="hidden" name="type" value="specialties">
      <input type="hidden" name="id" value="<?= esc($edit['id'] ?? '') ?>">
      <p class="muted small">نام رشته را وارد کنید؛ پیشوند «متخصص» یا «فوق‌تخصص» بر اساس سطح انتخاب‌شده خودکار نمایش داده می‌شود.</p>
      <?php input('نام تخصص', 'name', $edit['name'] ?? '', 'text', true, 190); ?>
      <label>سطح
        <select name="level" data-level>
          <?php foreach ($levels as $key => $label): ?>
            <option value="<?= $key ?>"<?= selected_attr(($edit['level'] ?? 'specialty') === $key) ?>><?= esc($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label data-parent-field>تخصص مادر (برای فوق‌تخصص)
        <select name="parent_id"><option value="">—</option>
          <?php foreach ($parents as $p): if ((int) $p['id'] === (int) ($edit['id'] ?? 0)) continue; ?>
            <option value="<?= (int) $p['id'] ?>"<?= selected_attr((int) ($edit['parent_id'] ?? 0) === (int) $p['id']) ?>><?= esc($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <fieldset class="icon-picker">
        <legend>آیکن</legend>
        <?php foreach ($icons as $key => $label): ?>
          <label title="<?= esc($label) ?>"><input type="radio" name="icon" value="<?= $key ?>"<?= checked_attr(($edit['icon'] ?? 'stethoscope') === $key) ?>><?= icon('sp-' . $key) ?><span class="sr-only"><?= esc($label) ?></span></label>
        <?php endforeach; ?>
      </fieldset>
      <label>یا تصویر اختصاصی (PNG، JPEG یا WebP، حداکثر ۲ مگابایت)
        <input type="file" name="image" accept="image/png,image/jpeg,image/webp">
      </label>
      <?php if (!empty($edit['image_file'])): ?>
        <div class="current-image"><?= specialty_visual($edit) ?><label class="check"><input type="checkbox" name="remove_image" value="1"> حذف تصویر و استفاده از آیکن</label></div>
      <?php endif; ?>
      <label>توضیح کوتاه (اختیاری)<textarea name="description" maxlength="500"><?= esc($edit['description'] ?? '') ?></textarea></label>
      <?php input('ترتیب نمایش (خالی = انتهای فهرست)', 'sort_order', (string) ($edit['sort_order'] ?? ''), 'number', false, 6); ?>
      <label class="check"><input type="checkbox" name="active"<?= checked_attr((bool) ($edit['active'] ?? empty($edit['id']))) ?>> فعال</label>
      <p class="muted">غیرفعال‌سازی، پزشکان این تخصص را از نمایش عمومی خارج می‌کند. حذف، تخصص را بایگانی می‌کند و قابل بازگشت است.</p>
      <div class="actions"><button>ذخیره</button> <a href="admin.php?tab=specialties">فرم جدید</a></div>
    </form>
  </section>
</div>
<section class="panel">
  <div class="panel-head"><h2>خدمات تعریف‌شده پزشکان</h2><span class="muted small">هر پزشک خدمات و مدت آن را در پنل خود تعیین می‌کند؛ این فهرست نمای کلی است.</span></div>
  <?php if (!$services): ?>
    <p class="empty-state">هنوز پزشکی خدمتی تعریف نکرده است.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>خدمت</th><th>تعداد پزشکان</th><th>مدت (دقیقه)</th><th>فعال</th></tr></thead>
        <tbody>
        <?php foreach ($services as $s): ?>
          <tr>
            <td><?= esc($s['name']) ?></td>
            <td><?= fa_number($s['doctors']) ?></td>
            <td><?= fa_number($s['min_d'] === $s['max_d'] ? $s['min_d'] : $s['min_d'] . '–' . $s['max_d']) ?></td>
            <td><?= fa_number((int) $s['active']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
