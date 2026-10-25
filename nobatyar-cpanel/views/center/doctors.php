<?php /** @var array $c @var array $s @var array $links @var array $presences @var array $branches @var bool $open @var array $post */
$canDoctors = !empty($s['perm_doctors']) && $open;
$canSchedule = !empty($s['perm_schedule']) && $open;
$perms = link_permissions();
$byDoctor = [];
foreach ($presences as $p) {
    $byDoctor[$p['doctor_id']][$p['branch_id']] = $p;
}
?>
<p class="notice">هر پزشک خودش تعیین می‌کند مرکز به چه چیزی دسترسی داشته باشد. اطلاعات مطب‌های شخصی پزشک هیچ‌گاه در این پنل نمایش داده نمی‌شود.</p>
<?php foreach ($links as $l): ?>
  <article class="card link-card">
    <div class="between">
      <h2><?= icon('doctor') ?> <?= esc(doctor_display_name((string) $l['doctor_name'])) ?> <small class="muted"><?= esc($l['specialty']) ?></small></h2>
      <span class="badge <?= $l['status'] === 'active' ? 'status-confirmed' : (in_array($l['status'], ['invited', 'requested'], true) ? 'status-held' : '') ?>"><?= esc(link_status_labels()[$l['status']]) ?></span>
    </div>
    <ul class="perm-chips">
      <?php foreach ($perms as $k => $label): ?>
        <li class="<?= $l[$k] ? 'on' : 'off' ?>"><?= $l[$k] ? icon('check') : icon('x') ?> <?= esc($label) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($l['message']): ?><p class="muted">پیام: <?= esc($l['message']) ?></p><?php endif; ?>
    <?php if ($l['status'] === 'requested' && $canDoctors): ?>
      <div class="actions">
        <form method="post"><?php form_token('link_accept'); ?><input type="hidden" name="link_id" value="<?= (int) $l['id'] ?>"><button>پذیرش درخواست</button></form>
        <form method="post" data-confirm="درخواست این پزشک رد شود؟"><?php form_token('link_reject'); ?><input type="hidden" name="link_id" value="<?= (int) $l['id'] ?>"><button class="secondary">رد</button></form>
      </div>
    <?php endif; ?>
    <?php if ($l['status'] === 'active'): ?>
      <h3>حضور در شعبه‌ها</h3>
      <ul class="presence-list">
        <?php foreach ($branches as $b): $p = $byDoctor[$l['doctor_id']][$b['id']] ?? null; ?>
          <li>
            <span><strong><?= esc($b['name']) ?></strong></span>
            <?php if ($p && $p['active']): ?>
              <span class="badge status-confirmed">نوبت‌دهی فعال</span>
              <a href="center.php?center=<?= (int) $c['id'] ?>&amp;tab=schedule&amp;branch=<?= (int) $b['id'] ?>#clinic-<?= (int) $p['id'] ?>">برنامه و اتاق</a>
              <?php if ($canSchedule && $l['perm_schedule']): ?>
                <form method="post" data-confirm="نوبت‌دهی جدید این پزشک در این شعبه متوقف شود؟ نوبت‌های قطعی حفظ می‌شوند."><?php form_token('presence_remove'); ?><input type="hidden" name="clinic_id" value="<?= (int) $p['id'] ?>"><button class="secondary small">توقف</button></form>
              <?php endif; ?>
            <?php elseif ($b['active'] && $canSchedule && $l['perm_schedule']): ?>
              <form method="post"><?php form_token('presence'); ?><input type="hidden" name="doctor_id" value="<?= (int) $l['doctor_id'] ?>"><input type="hidden" name="branch_id" value="<?= (int) $b['id'] ?>"><button class="small"><?= icon('plus') ?> افزودن به این شعبه</button></form>
            <?php else: ?>
              <span class="muted small"><?= $l['perm_schedule'] ? 'تعریف نشده' : 'تعریف حضور با خود پزشک است' ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if (in_array($l['status'], ['active', 'invited'], true) && $canDoctors): ?>
      <details>
        <summary><?= $l['status'] === 'active' ? 'پایان همکاری' : 'لغو دعوت' ?></summary>
        <form method="post" class="stack" data-confirm="این همکاری پایان یابد؟ نوبت‌دهی جدید پزشک در شعبه‌های مرکز متوقف می‌شود.">
          <?php form_token('link_end'); ?><input type="hidden" name="link_id" value="<?= (int) $l['id'] ?>">
          <?php input('علت', 'reason', '', 'text', true, 500); ?>
          <button class="danger">تأیید</button>
        </form>
      </details>
    <?php endif; ?>
  </article>
<?php endforeach; ?>
<?php if (!$links): ?><p class="empty-state">هنوز پزشکی به مرکز نپیوسته است.</p><?php endif; ?>

<?php if ($canDoctors): ?>
  <article class="card">
    <h2>دعوت پزشک</h2>
    <p class="muted">پزشک باید در نوبت‌یار ثبت‌نام و تأیید شده باشد. دسترسی‌های زیر پیشنهاد شماست و پزشک هنگام پذیرش می‌تواند آن‌ها را کم کند. کد این مرکز برای درخواست مستقیم پزشکان: <strong><bdi><?= fa_number($c['id']) ?></bdi></strong></p>
    <form method="post" class="stack">
      <?php form_token('invite'); ?>
      <div class="form-grid">
        <?php input('شماره نظام پزشکی', 'medical_code', $post['medical_code'] ?? '', 'text', true, 30); ?>
        <?php input('پیام برای پزشک (اختیاری)', 'message', $post['message'] ?? '', 'text', false, 500); ?>
      </div>
      <?php foreach ($perms as $k => $label): ?>
        <label class="check"><input type="checkbox" name="<?= $k ?>" value="1"<?= checked_attr($post ? !empty($post[$k]) : true) ?>> <?= esc($label) ?></label>
      <?php endforeach; ?>
      <button>ارسال دعوت</button>
    </form>
  </article>
<?php elseif (!$open): ?>
  <p class="muted">دعوت پزشکان پس از تأیید مرکز و فعال‌شدن اشتراک ممکن است.</p>
<?php endif; ?>
