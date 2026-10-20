<?php
$roleLabels = ['patient' => 'بیمار', 'doctor' => 'پزشک', 'secretary' => 'منشی', 'clinic' => 'کلینیک'];
if (!empty($notFound)): ?>
  <p class="error">کاربر پیدا نشد.</p>
  <?php return; ?>
<?php endif; ?>

<?php if ($user): $u = $user; ?>
  <a class="back-link" href="admin.php?tab=users">&larr; بازگشت به کاربران</a>
  <div class="profile-head">
    <div>
      <h2><?= esc($u['name'] ?: 'کاربر بدون نام') ?></h2>
      <p class="muted">شماره تأییدشده: <bdi><?= esc(admin_mobile($u['mobile'])) ?></bdi> • عضویت: <?= esc(jdate($u['created_at'])) ?></p>
    </div>
    <div class="badges">
      <?php foreach ($roles as $r): ?><span class="badge"><?= esc($roleLabels[$r] ?? $r) ?></span><?php endforeach; ?>
      <span class="badge <?= $u['active'] ? 'status-confirmed' : 'status-cancelled' ?>"><?= $u['active'] ? 'فعال' : 'تعلیق‌شده' ?></span>
    </div>
  </div>
  <div class="grid-2 align-start">
    <section class="panel">
      <h3>وضعیت حساب</h3>
      <?php if (!empty($u['deleted_at'])): ?>
        <p class="error" role="alert">این حساب در <?= esc(jdate($u['deleted_at'], true)) ?> حذف شده است.</p>
      <?php elseif (!$u['active']): ?>
        <p class="notice">حساب تعلیق است<?= !empty($u['suspended_reason']) ? ': ' . esc($u['suspended_reason']) : '' ?>.</p>
        <form method="post" class="inline-form">
          <?php form_token('user_unsuspend'); ?>
          <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
          <button><?= icon('check') ?> رفع تعلیق</button>
        </form>
      <?php else: ?>
        <form method="post" class="stack">
          <?php form_token('user_suspend'); ?>
          <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
          <label>علت تعلیق (اختیاری؛ هنگام ورود به کاربر نمایش داده می‌شود)<input name="reason" maxlength="255"></label>
          <p class="muted small">کاربر از همه دستگاه‌ها خارج می‌شود و تا رفع تعلیق نمی‌تواند وارد شود. نوبت‌های قطعی او حفظ می‌شود.</p>
          <button class="secondary"><?= icon('lock') ?> تعلیق کاربر</button>
        </form>
      <?php endif; ?>
      <?php if (empty($u['deleted_at']) && admin_sensitive()): $futureU = (int) query("SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE b.patient_user_id=? AND b.status='confirmed' AND s.starts_at>NOW()", [$u['id']])->fetchColumn(); ?>
        <form method="post" class="stack danger-zone" data-confirm="حساب کاربر حذف شود؟ این کار برگشت‌پذیر نیست.">
          <?php form_token('user_delete'); ?>
          <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
          <p class="small"><strong>حذف کاربر:</strong> نام، کد ملی، تاریخ تولد و اعضای خانواده پاک، دسترسی‌های پزشک/منشی قطع و شماره موبایل آزاد می‌شود. سوابق نوبت‌های گذشته نزد پزشکان می‌ماند.<?= $futureU ? ' <strong>' . fa_number($futureU) . ' نوبت آینده او لغو می‌شود</strong> و کارمزد برای استرداد ثبت می‌شود.' : '' ?></p>
          <label>برای تأیید بنویسید «حذف»<input name="confirm" required autocomplete="off"></label>
          <button class="danger"><?= icon('trash') ?> حذف کاربر</button>
        </form>
      <?php endif; ?>
      <h3>نقش پزشک</h3>
      <?php if ($doctor): ?>
        <p>متصل به پزشک: <a href="admin.php?tab=doctors&amp;doctor=<?= (int) $doctor['id'] ?>"><?= esc($doctor['name']) ?></a></p>
        <form method="post" class="inline-form" data-confirm="دسترسی پزشک از این حساب حذف شود؟">
          <?php form_token('unlink_doctor'); ?>
          <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
          <button class="danger">قطع دسترسی پزشک</button>
        </form>
      <?php elseif ($linkable): ?>
        <form method="post" class="stack">
          <?php form_token('link_doctor'); ?>
          <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
          <?php select_field('پزشک با همین موبایل', 'doctor_id', $linkable); ?>
          <button>اتصال حساب پزشک</button>
        </form>
      <?php else: ?>
        <p class="muted">پزشکی با همین شماره موبایل و بدون حساب متصل وجود ندارد.</p>
      <?php endif; ?>
    </section>
    <section class="panel">
      <h3>دسترسی‌های مطب (منشی و کلینیک)</h3>
      <?php foreach ($scopes as $s): ?>
        <div class="row-item">
          <div class="row-main"><strong><?= esc($s['role'] === 'secretary' ? 'منشی' : 'کلینیک') ?></strong><small><?= esc($s['doctor'] . ' / ' . $s['clinic']) ?></small></div>
          <form method="post" class="inline-form">
            <?php form_token('revoke_scope'); ?>
            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="role" value="<?= esc($s['role']) ?>"><input type="hidden" name="clinic_id" value="<?= (int) $s['clinic_id'] ?>">
            <button class="danger small">لغو دسترسی</button>
          </form>
        </div>
      <?php endforeach; ?>
      <?php if (!$scopes): ?><p class="muted">دسترسی مطبی ثبت نشده است.</p><?php endif; ?>
      <form method="post" class="stack">
        <?php form_token('grant_scope'); ?>
        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
        <label>نقش<select name="role"><option value="secretary">منشی</option><option value="clinic">مسئول کلینیک</option></select></label>
        <?php select_field('مطب مجاز', 'clinic_id', $clinics); ?>
        <button>افزودن دسترسی به مطب</button>
      </form>
    </section>
  </div>
<?php else: ?>
  <p class="muted">منشی و مسئول کلینیک ابتدا با موبایل وارد شوند، سپس دسترسی هر مطب را به حساب آن‌ها بدهید.</p>
  <form method="get" class="filter-bar">
    <input type="hidden" name="tab" value="users">
    <label>نام یا موبایل<input name="q" value="<?= esc($q) ?>"></label>
    <label>نقش
      <select name="role"><option value="">همه</option>
        <?php foreach ($roleLabels as $key => $label): ?><option value="<?= $key ?>"<?= selected_attr($role === $key) ?>><?= $label ?></option><?php endforeach; ?>
      </select>
    </label>
    <button><?= icon('search') ?> جستجو</button>
  </form>
  <div class="table-wrap">
    <table>
      <thead><tr><th>نام / موبایل</th><th>نقش‌ها</th><th>وضعیت</th><th>عضویت</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= esc($r['name'] ?: '—') ?><br><bdi><?= esc(admin_mobile($r['mobile'])) ?></bdi></td>
          <td><?= esc(implode('، ', array_map(fn($x) => $roleLabels[$x] ?? $x, array_filter(explode(',', (string) $r['role_list']))))) ?></td>
          <td><?= $r['active'] ? 'فعال' : 'تعلیق‌شده' ?></td>
          <td><bdi><?= esc(jdate($r['created_at'], true)) ?></bdi></td>
          <td><a href="admin.php?tab=users&amp;user=<?= (int) $r['id'] ?>">مدیریت دسترسی</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (!$rows): ?><p class="empty-state">کاربری پیدا نشد.</p><?php endif; ?>
  <?= pagination($page, $total, 30, ['tab' => 'users', 'q' => $q, 'role' => $role]) ?>
<?php endif; ?>
