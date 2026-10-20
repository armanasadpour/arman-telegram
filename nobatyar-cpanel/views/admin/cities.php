<?php /** @var int $province @var string $q @var array $edit @var array $rows @var array $provinces @var array $geo */ ?>
<form method="get" class="filter-bar">
  <input type="hidden" name="tab" value="cities">
  <label>استان
    <select name="province" data-autosubmit>
      <option value="0">انتخاب استان</option>
      <?php foreach ($provinces as $p): ?>
        <option value="<?= (int) $p['id'] ?>"<?= selected_attr($province === (int) $p['id']) ?>><?= esc($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>جستجوی نام شهر در همه استان‌ها<input name="q" value="<?= esc($q) ?>" placeholder="مثلاً بستک"></label>
  <button><?= icon('search') ?> نمایش</button>
</form>

<div class="grid-2 align-start">
  <section class="panel">
    <?php if (!$rows): ?>
      <p class="empty-state">برای مشاهده و مرتب‌سازی شهرها، استان را انتخاب کنید یا نام شهر را جستجو کنید.</p>
    <?php else: ?>
      <div class="panel-head"><h2>شهرها</h2><span class="muted"><?= fa_number(count($rows)) ?> شهر</span></div>
      <form method="post" id="geo-bulk" class="bulk-bar" data-bulk>
  <?php form_token('geo_bulk'); ?>
  <input type="hidden" name="type" value="cities">
  <label class="check bulk-all"><input type="checkbox" data-check-all> همه</label>
  <span class="bulk-count muted" data-bulk-count>هیچ شهری انتخاب نشده</span>
  
  <button name="state" value="1" class="button small"><?= icon('check') ?> فعال‌سازی</button>
  <button name="state" value="0" class="button secondary small"><?= icon('x') ?> غیرفعال‌سازی</button>
</form>
      <div class="table-wrap">
        <table>
          <thead><tr><th class="sel"><span class="sr-only">انتخاب</span></th><?php if (!$q): ?><th>ترتیب</th><?php endif; ?><th>شهر</th><th>مطب</th><th>نقشه</th><th>وضعیت</th><th>عملیات</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="sel"><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" form="geo-bulk" data-row-check aria-label="انتخاب <?= esc($r['name']) ?>"></td>
              <?php if (!$q): ?>
                <td class="nowrap">
                  <?php foreach (['up' => 'بالا', 'down' => 'پایین'] as $dir => $label): ?>
                    <form method="post" class="inline-form">
                      <?php form_token('move'); ?>
                      <input type="hidden" name="type" value="cities"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="direction" value="<?= $dir ?>">
                      <button class="icon-button" aria-label="انتقال به <?= $label ?>"><?= icon($dir === 'up' ? 'arrow-up' : 'arrow-down') ?></button>
                    </form>
                  <?php endforeach; ?>
                </td>
              <?php endif; ?>
              <td><strong><?= esc($r['name']) ?></strong><?= $r['is_capital'] ? ' <span class="tag">مرکز استان</span>' : '' ?><?= $q ? '<br><small>' . esc($r['province_name']) . '</small>' : '' ?></td>
              <td><?= fa_number($r['clinics']) ?></td>
              <td><?= $r['latitude'] !== null ? icon('check') : '<small class="muted">ندارد</small>' ?></td>
              <td><?= $r['active'] ? '<span class="badge status-confirmed">فعال</span>' : '<span class="badge">غیرفعال</span>' ?></td>
              <td class="nowrap">
                <a href="admin.php?tab=cities&amp;province=<?= (int) $r['province_id'] ?>&amp;edit=<?= (int) $r['id'] ?>">ویرایش</a>
                <form method="post" class="inline-form" data-confirm="این شهر حذف شود؟ شهر دارای مطب قابل حذف نیست.">
                  <?php form_token('delete_taxonomy'); ?>
                  <input type="hidden" name="type" value="cities"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button class="danger small">حذف</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <div class="stack">
    <section class="panel">
      <h2><?= !empty($edit['id']) ? 'ویرایش شهر' : 'افزودن شهر' ?></h2>
      <form method="post" class="stack">
        <?php form_token('taxonomy'); ?>
        <input type="hidden" name="type" value="cities">
        <input type="hidden" name="id" value="<?= esc($edit['id'] ?? '') ?>">
        <?php input('نام', 'name', $edit['name'] ?? '', 'text', true, 120); ?>
        <?php select_field('استان', 'province_id', $provinces, $edit['province_id'] ?? $province); ?>
        <?php input('ترتیب نمایش (خالی = انتهای فهرست)', 'sort_order', (string) ($edit['sort_order'] ?? ''), 'number', false, 6); ?>
        <?php map_fields($edit, true); ?>
        <label class="check"><input type="checkbox" name="is_capital"<?= checked_attr(!empty($edit['is_capital'])) ?>> مرکز استان</label>
        <label class="check"><input type="checkbox" name="active"<?= checked_attr((bool) ($edit['active'] ?? empty($edit['id']))) ?>> فعال</label>
        <div class="actions"><button>ذخیره</button> <a href="admin.php?tab=cities&amp;province=<?= $province ?>">فرم جدید</a></div>
      </form>
    </section>
    <details class="panel">
      <summary>تنظیمات نقشه و شهرهای اطراف</summary>
      <form method="post" class="stack">
        <?php form_token('geo_settings'); ?>
        <?php input('شعاع شهرهای اطراف (کیلومتر)', 'nearby_radius_km', $geo['radius'], 'number', true, 3); ?>
        <?php input('نشانی کاشی‌های نقشه', 'map_tile_url', $geo['tiles'], 'url', true, 300); ?>
        <?php input('متن منبع نقشه', 'map_attribution', $geo['attribution'], 'text', true, 200); ?>
        <p class="muted">پیش‌فرض OpenStreetMap است. اگر از سرویس نقشه داخلی استفاده می‌کنید، نشانی کاشی‌ها را طبق مستندات آن سرویس وارد کنید.</p>
        <button>ذخیره تنظیمات نقشه</button>
      </form>
    </details>
  </div>
</div>
