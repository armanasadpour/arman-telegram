<?php /** @var array $rows @var array $edit */ ?>
<div class="grid-2 align-start">
  <section class="panel">
    <div class="panel-head"><h2>استان‌ها</h2><span class="muted"><?= fa_number(count($rows)) ?> استان</span></div>
    <form method="post" id="geo-bulk" class="bulk-bar" data-bulk>
  <?php form_token('geo_bulk'); ?>
  <input type="hidden" name="type" value="provinces">
  <label class="check bulk-all"><input type="checkbox" data-check-all> همه</label>
  <span class="bulk-count muted" data-bulk-count>هیچ استانی انتخاب نشده</span>
  <label class="check"><input type="checkbox" name="cascade" value="1" checked> شهرهای این استان‌ها هم</label>
  <button name="state" value="1" class="button small"><?= icon('check') ?> فعال‌سازی</button>
  <button name="state" value="0" class="button secondary small"><?= icon('x') ?> غیرفعال‌سازی</button>
</form>
    <div class="table-wrap">
      <table>
        <thead><tr><th class="sel"><span class="sr-only">انتخاب</span></th><th>ترتیب</th><th>استان</th><th>شهرها</th><th>وضعیت</th><th>عملیات</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="sel"><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" form="geo-bulk" data-row-check aria-label="انتخاب <?= esc($r['name']) ?>"></td>
            <td class="nowrap">
              <?php foreach (['up' => 'بالا', 'down' => 'پایین'] as $dir => $label): ?>
                <form method="post" class="inline-form">
                  <?php form_token('move'); ?>
                  <input type="hidden" name="type" value="provinces"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="direction" value="<?= $dir ?>">
                  <button class="icon-button" aria-label="انتقال به <?= $label ?>"><?= icon($dir === 'up' ? 'arrow-up' : 'arrow-down') ?></button>
                </form>
              <?php endforeach; ?>
            </td>
            <td><strong><?= esc($r['name']) ?></strong><?= $r['phone_code'] ? ' <small dir="ltr">' . esc(fa_number($r['phone_code'])) . '</small>' : '' ?></td>
            <td><a href="admin.php?tab=cities&amp;province=<?= (int) $r['id'] ?>"><?= fa_number($r['active_cities']) ?> از <?= fa_number($r['cities']) ?></a></td>
            <td><?= $r['active'] ? '<span class="badge status-confirmed">فعال</span>' : '<span class="badge">غیرفعال</span>' ?></td>
            <td class="nowrap">
              <a href="admin.php?tab=provinces&amp;edit=<?= (int) $r['id'] ?>">ویرایش</a>
              <form method="post" class="inline-form" data-confirm="این استان حذف شود؟ استان دارای شهر قابل حذف نیست.">
                <?php form_token('delete_taxonomy'); ?>
                <input type="hidden" name="type" value="provinces"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="danger small">حذف</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <section class="panel sticky">
    <h2><?= !empty($edit['id']) ? 'ویرایش استان' : 'افزودن استان' ?></h2>
    <form method="post" class="stack">
      <?php form_token('taxonomy'); ?>
      <input type="hidden" name="type" value="provinces">
      <input type="hidden" name="id" value="<?= esc($edit['id'] ?? '') ?>">
      <?php input('نام', 'name', $edit['name'] ?? '', 'text', true, 120); ?>
      <?php input('پیش‌شماره تلفن (اختیاری)', 'phone_code', $edit['phone_code'] ?? '', 'text', false, 4); ?>
      <?php input('ترتیب نمایش (خالی = انتهای فهرست)', 'sort_order', (string) ($edit['sort_order'] ?? ''), 'number', false, 6); ?>
      <label class="check"><input type="checkbox" name="active"<?= checked_attr((bool) ($edit['active'] ?? empty($edit['id']))) ?>> فعال</label>
      <p class="muted">غیرفعال‌سازی استان، شهرها و پزشکان آن را از نمایش عمومی خارج می‌کند؛ اطلاعات حذف نمی‌شود.</p>
      <div class="actions"><button>ذخیره</button> <a href="admin.php?tab=provinces">فرم جدید</a></div>
    </form>
  </section>
</div>
