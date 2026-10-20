<?php /** @var array $c @var array $s @var array $staffList @var array $branches @var array $post */
$roles = center_roles();
$perms = center_permissions();
$canStaff = !empty($s['perm_staff']) && !in_array($c['review_status'], ['suspended', 'rejected'], true);
?>
<p class="muted">کارکنان دارای «مدیریت نوبت‌ها و پذیرش» برای پزشکانی که اجازه داده‌اند، به پنل منشی (ثبت نوبت تلفنی/حضوری، پذیرش، تأخیر) دسترسی می‌گیرند. این دسترسی با پایان اشتراک مرکز یا پایان همکاری پزشک خودکار برداشته می‌شود.</p>
<div class="table-wrap panel"><table>
  <thead><tr><th>نام</th><th>نقش</th><th>محدوده</th><th>دسترسی‌ها</th><th>وضعیت</th><?php if ($canStaff): ?><th></th><?php endif; ?></tr></thead>
  <tbody>
    <?php foreach ($staffList as $m): ?>
      <tr>
        <td><?= esc($m['name'] ?: '—') ?><br><small><bdi><?= esc(fa_number($m['mobile'])) ?></bdi></small></td>
        <td><?= esc($roles[$m['role']]['label'] ?? $m['role']) ?></td>
        <td><?= esc($m['branch'] ?: 'همه شعبه‌ها') ?></td>
        <td><small><?= esc(implode('، ', array_map(fn($k) => $perms[$k], array_filter(array_keys($perms), fn($k) => !empty($m[$k]))))) ?: '—' ?></small></td>
        <td><?= $m['active'] ? '<span class="badge status-confirmed">فعال</span>' : '<span class="badge">غیرفعال</span>' ?></td>
        <?php if ($canStaff): ?><td><?php if ($m['active'] && $m['role'] !== 'owner' && (int) $m['user_id'] !== (int) $s['user_id']): ?>
          <form method="post" data-confirm="دسترسی این کارمند لغو شود؟"><?php form_token('staff_remove'); ?><input type="hidden" name="user_id" value="<?= (int) $m['user_id'] ?>"><button class="danger small">لغو دسترسی</button></form>
        <?php endif; ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
<?php if ($canStaff): ?>
  <article class="card">
    <h2>تعریف یا ویرایش کارمند</h2>
    <p class="muted">کارمند باید یک بار با موبایل خود وارد نوبت‌یار شده باشد. با ثبت دوباره همان موبایل، دسترسی‌ها به‌روز می‌شود. فقط دسترسی‌هایی را می‌توانید واگذار کنید که خودتان دارید.</p>
    <form method="post" class="stack" data-role-presets='<?= esc(json_encode(array_map(fn($r) => $r['perms'], $roles))) ?>'>
      <?php form_token('staff'); ?>
      <div class="form-grid">
        <?php input('موبایل کارمند', 'mobile', $post['mobile'] ?? '', 'tel', true, 11); ?>
        <label>نقش<select name="role" data-role-select><?php foreach ($roles as $k => $r): if ($k === 'owner') { continue; } ?><option value="<?= $k ?>"<?= selected_attr(($post['role'] ?? 'secretary') === $k) ?>><?= esc($r['label']) ?></option><?php endforeach; ?></select></label>
        <label>محدوده<select name="branch_id">
          <?php if (!$s['branch_id']): ?><option value="">همه شعبه‌ها</option><?php endif; ?>
          <?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"<?= selected_attr((int) ($post['branch_id'] ?? 0) === (int) $b['id']) ?>><?= esc($b['name']) ?></option><?php endforeach; ?>
        </select></label>
      </div>
      <fieldset><legend>دسترسی‌ها</legend>
        <?php $preset = $roles[$post['role'] ?? 'secretary']['perms']; foreach ($perms as $k => $label): ?>
          <label class="check"><input type="checkbox" name="<?= $k ?>" value="1"<?= checked_attr($post ? !empty($post[$k]) : !empty($preset[$k])) ?><?= empty($s[$k]) ? ' disabled' : '' ?>> <?= esc($label) ?></label>
        <?php endforeach; ?>
      </fieldset>
      <button>ذخیره دسترسی</button>
    </form>
  </article>
<?php endif; ?>
