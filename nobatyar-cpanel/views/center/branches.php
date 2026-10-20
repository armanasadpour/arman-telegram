<?php /** @var array $c @var array $s @var array $branches @var array $rooms @var array $post */
$canEdit = !empty($s['perm_center']) && $c['review_status'] !== 'suspended';
?>
<p class="muted">تغییر نشانی یا تلفن شعبه در صفحه همه پزشکان آن شعبه هم اعمال می‌شود. غیرفعال‌کردن شعبه، رزرو جدید را متوقف می‌کند ولی نوبت‌های قطعی حفظ می‌شوند.</p>
<?php foreach ($branches as $b): ?>
  <details class="card branch-card"<?= count($branches) === 1 ? ' open' : '' ?>>
    <summary>
      <strong><?= esc($b['name']) ?></strong> • <?= esc($b['city']) ?>
      <?= $b['is_main'] ? '<span class="badge status-confirmed">اصلی</span>' : '' ?>
      <?= $b['active'] ? '' : '<span class="badge">غیرفعال</span>' ?>
      <span class="badge"><?= fa_number($b['rooms']) ?> اتاق</span>
    </summary>
    <?php if ($canEdit): ?>
      <form method="post" class="stack">
        <?php form_token('branch'); ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
        <?php input('نام شعبه', 'name', $b['name'], 'text', true, 190); ?>
        <?php location_fields($b); ?>
        <?php input('نشانی', 'address', $b['address'], 'text', true, 2000); ?>
        <?php input('تلفن شعبه (خالی = تلفن مرکز)', 'phone', $b['phone'], 'tel', false, 11); ?>
        <?php map_fields($b); ?>
        <label class="check"><input type="checkbox" name="active" value="1"<?= checked_attr((bool) $b['active']) ?>> شعبه فعال است</label>
        <div class="actions"><button>ذخیره شعبه</button></div>
      </form>
      <?php if (!$b['is_main'] && $b['active'] && !$s['branch_id']): ?>
        <form method="post"><?php form_token('branch_main'); ?><input type="hidden" name="branch_id" value="<?= (int) $b['id'] ?>"><button class="secondary small">شعبه اصلی شود</button></form>
      <?php endif; ?>
    <?php endif; ?>
    <h3>اتاق‌ها</h3>
    <div class="table-wrap"><table>
      <thead><tr><th>اتاق</th><th>توضیح</th><th>وضعیت</th><?php if ($canEdit): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
        <?php foreach ($rooms[$b['id']] as $r): ?>
          <tr>
            <?php if ($canEdit): ?>
              <td colspan="4">
                <form method="post" class="inline-form room-form">
                  <?php form_token('room'); ?><input type="hidden" name="branch_id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <label>نام<input name="name" value="<?= esc($r['name']) ?>" maxlength="120" required></label>
                  <label>توضیح<input name="note" value="<?= esc($r['note']) ?>" maxlength="255"></label>
                  <label class="check"><input type="checkbox" name="active" value="1"<?= checked_attr((bool) $r['active']) ?>> فعال</label>
                  <button class="secondary small">ذخیره</button>
                </form>
              </td>
            <?php else: ?>
              <td><?= esc($r['name']) ?></td><td><?= esc($r['note']) ?></td><td><?= $r['active'] ? 'فعال' : 'غیرفعال' ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if (!$rooms[$b['id']]): ?><p class="muted">هنوز اتاقی تعریف نشده است.</p><?php endif; ?>
    <?php if ($canEdit): ?>
      <form method="post" class="inline-form room-form">
        <?php form_token('room'); ?><input type="hidden" name="branch_id" value="<?= (int) $b['id'] ?>">
        <label>اتاق جدید<input name="name" maxlength="120" required placeholder="مثلاً اتاق ۲"></label>
        <label>توضیح<input name="note" maxlength="255" placeholder="اختیاری"></label>
        <input type="hidden" name="active" value="1">
        <button class="small"><?= icon('plus') ?> افزودن اتاق</button>
      </form>
    <?php endif; ?>
  </details>
<?php endforeach; ?>
<?php if ($canEdit && !$s['branch_id']): ?>
  <article class="card">
    <h2>شعبه جدید</h2>
    <form method="post" class="stack">
      <?php form_token('branch'); ?>
      <?php input('نام شعبه', 'name', $post['name'] ?? '', 'text', true, 190); ?>
      <?php location_fields($post); ?>
      <?php input('نشانی', 'address', $post['address'] ?? '', 'text', true, 2000); ?>
      <?php input('تلفن شعبه (اختیاری)', 'phone', $post['phone'] ?? '', 'tel', false, 11); ?>
      <?php map_fields($post); ?>
      <input type="hidden" name="active" value="1">
      <button>ثبت شعبه</button>
    </form>
  </article>
<?php endif; ?>
