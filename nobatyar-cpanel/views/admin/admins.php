<?php /** @var array $rows @var array $edit @var array $roles @var array $permissions */
$me = (int) ($_SESSION['admin_id'] ?? 0); ?>
<div class="grid-2 align-start">
  <section class="panel">
    <div class="panel-head"><h2>مدیران</h2><span class="muted">تعداد: <?= fa_number(count($rows)) ?></span></div>
    <label>جستجوی زنده مدیر یا نقش<input type="search" data-live-filter="admin-rows" placeholder="نام، شماره، ایمیل یا نقش"></label><div class="table-wrap">
      <table>
        <thead><tr><th>مدیر</th><th>نقش</th><th>آخرین ورود</th><th>وضعیت</th><th></th></tr></thead>
        <tbody id="admin-rows">
        <?php foreach ($rows as $a): ?>
          <tr class="<?= $a['active'] ? '' : 'row-muted' ?>">
            <td><strong><?= esc($a['name'] ?: '—') ?></strong><br><small dir="ltr"><?= esc($a['email']) ?></small><br><small><?= !empty($a['mobile']) ? '<bdi dir="ltr">' . esc(admin_sensitive() ? fa_number($a['mobile']) : mask_mobile($a['mobile'])) . '</bdi>' : '<span class="badge status-pending">بدون موبایل</span>' ?></small></td>
            <td><?= esc($roles[$a['role']]['label'] ?? $a['role']) ?></td>
            <td><bdi><?= esc(jdate($a['last_login_at'], true)) ?></bdi></td>
            <td><span class="badge <?= $a['active'] ? 'status-confirmed' : 'status-cancelled' ?>"><?= $a['active'] ? 'فعال' : 'غیرفعال' ?></span></td>
            <td class="row-actions">
              <a href="admin.php?tab=admins&amp;admin=<?= (int) $a['id'] ?>">ویرایش</a>
              <?php if ((int) $a['id'] !== $me): ?>
                <form method="post" class="inline-form"<?= $a['active'] ? ' data-confirm="این مدیر غیرفعال و از همه دستگاه‌ها خارج شود؟"' : '' ?>>
                  <?php form_token('admin_status'); ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="active" value="<?= $a['active'] ? '0' : '1' ?>">
                  <button class="<?= $a['active'] ? 'danger' : 'secondary' ?> small"><?= $a['active'] ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?></button>
                </form>
              <?php else: ?><small class="muted">حساب شما</small><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <section class="panel">
    <?php if ($edit): ?>
      <h2>ویرایش <?= esc($edit['name'] ?: $edit['email']) ?></h2>
      <form method="post" class="stack">
        <?php form_token('admin_save'); ?>
        <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
        <?php input('نام', 'name', $edit['name'], 'text', false, 120); ?>
        <label>شماره موبایل ورود<input class="mobile-input" name="mobile" type="tel" inputmode="numeric" maxlength="16" dir="ltr" placeholder="۰۹۱۲۳۴۵۶۷۸۹" value="<?= esc((string) ($edit['mobile'] ?? '')) ?>"></label>
        <?php if (empty($edit['mobile'])): ?><p class="muted small">این مدیر هنوز شماره ندارد؛ در اولین ورود پس از ایمیل و رمز، شماره خود را ثبت و تأیید می‌کند.</p><?php endif; ?>
        <label>نقش<select name="role"><?php foreach ($roles as $k => $r): ?><option value="<?= $k ?>"<?= selected_attr($edit['role'] === $k) ?>><?= esc($r['label']) ?></option><?php endforeach; ?></select></label>
        <p class="muted small">تغییر نقش، این مدیر را از همه دستگاه‌ها خارج می‌کند تا محدودیت جدید فوراً اعمال شود.</p>
        <button>ذخیره</button> <a href="admin.php?tab=admins">انصراف</a>
      </form>
      <form method="post" class="stack" autocomplete="off">
        <?php form_token('admin_reset_password'); ?>
        <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
        <?php input('رمز عبور جدید (حداقل ۱۲ نویسه، حرف و عدد)', 'password', '', 'password', true, 255); ?>
        <button class="secondary">تغییر رمز این مدیر</button>
      </form>
    <?php else: ?>
      <h2>افزودن مدیر</h2>
      <form method="post" class="stack" autocomplete="off">
        <?php form_token('admin_save'); ?>
        <?php input('نام', 'name', $post['name'] ?? '', 'text', false, 120); ?>
        <?php input('ایمیل ورود', 'email', $post['email'] ?? '', 'email', true, 190); ?>
        <label>شماره موبایل (کد ورود به این شماره پیامک می‌شود)<input class="mobile-input" name="mobile" type="tel" inputmode="numeric" maxlength="16" required dir="ltr" placeholder="۰۹۱۲۳۴۵۶۷۸۹" value="<?= esc($post['mobile'] ?? '') ?>"></label>
        <?php input('رمز عبور (حداقل ۱۲ نویسه، حرف و عدد)', 'password', '', 'password', true, 255); ?>
        <label>نقش<select name="role" required><?php foreach ($roles as $k => $r): ?><option value="<?= $k ?>"<?= selected_attr(($post['role'] ?? 'support') === $k) ?>><?= esc($r['label']) ?></option><?php endforeach; ?></select></label>
        <button><?= icon('plus') ?> ساخت حساب</button>
      </form>
    <?php endif; ?>
  </section>
</div>
<section class="panel">
  <div class="panel-head"><h2>نقش‌ها و دسترسی‌ها</h2></div>
  <div class="table-wrap">
    <table class="role-matrix">
      <thead><tr><th>دسترسی</th><?php foreach ($roles as $r): ?><th><?= esc($r['label']) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($permissions as $p => $label): ?>
        <tr><td><?= esc($label) ?></td><?php foreach ($roles as $r): $on = in_array($p, $r['perms'], true); ?><td><?= $on ? icon('check', 'icon ok') . '<span class="sr-only">دارد</span>' : '<span class="muted" aria-label="ندارد">—</span>' ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
