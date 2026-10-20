<?php
/** @var array $d @var array $links */
$perms = link_permissions();
$statusLabels = link_status_labels();
$permFields = function (array $values, bool $disabled = false) use ($perms): void {
    foreach ($perms as $key => $label) {
        echo '<label class="check"><input type="checkbox" name="' . $key . '" value="1"' . checked_attr(!empty($values[$key])) . ($disabled ? ' disabled' : '') . '> ' . esc($label) . '</label>';
    }
};
?>
<p class="notice">همکاری با مرکز فقط شعبه‌های همان مرکز را شامل می‌شود. مطب‌های شخصی شما و نوبت‌های آن‌ها هرگز برای مرکز قابل مشاهده نیست و دسترسی‌ها را فقط خودتان تعیین می‌کنید.</p>

<?php foreach ($links as $l): ?>
  <article class="card link-card status-<?= esc($l['status']) ?>">
    <div class="between">
      <h2><?= icon('building') ?> <?= esc($l['center_name']) ?></h2>
      <span class="badge <?= $l['status'] === 'active' ? 'status-confirmed' : ($l['status'] === 'invited' || $l['status'] === 'requested' ? 'status-held' : '') ?>"><?= esc($statusLabels[$l['status']]) ?></span>
    </div>
    <p class="muted"><?= esc(center_kinds()[$l['kind']] ?? '') ?> • <?= esc($l['city']) ?> • کد مرکز <bdi><?= fa_number($l['center_id']) ?></bdi></p>
    <?php if ($l['message']): ?><p>پیام: <?= esc($l['message']) ?></p><?php endif; ?>

    <?php if ($l['status'] === 'invited'): ?>
      <form method="post" class="stack">
        <?php form_token('center_accept'); ?><input type="hidden" name="link_id" value="<?= (int) $l['id'] ?>">
        <p>دسترسی‌های پیشنهادی مرکز را بررسی کنید؛ هر مورد را می‌توانید بردارید:</p>
        <?php $permFields($l); ?>
        <div class="actions"><button>پذیرش همکاری</button></div>
      </form>
      <form method="post" data-confirm="دعوت این مرکز رد شود؟"><?php form_token('center_reject'); ?><input type="hidden" name="link_id" value="<?= (int) $l['id'] ?>"><button class="secondary">رد دعوت</button></form>
    <?php elseif (in_array($l['status'], ['active', 'requested'], true)): ?>
      <details>
        <summary>دسترسی‌های مرکز</summary>
        <form method="post" class="stack">
          <?php form_token('center_perms'); ?><input type="hidden" name="link_id" value="<?= (int) $l['id'] ?>">
          <?php $permFields($l); ?>
          <button class="secondary">ذخیره دسترسی‌ها</button>
        </form>
      </details>
      <?php if ($l['status'] === 'active'): ?>
        <h3>شعبه‌ها</h3>
        <ul class="presence-list">
          <?php foreach ($l['branches'] as $b):
              $p = null;
              foreach ($l['presences'] as $x) {
                  if ((int) $x['branch_id'] === (int) $b['id']) {
                      $p = $x;
                  }
              } ?>
            <li>
              <span><strong><?= esc($b['name']) ?></strong> • <?= esc($b['city']) ?></span>
              <?php if ($p && $p['active']): ?>
                <span class="badge status-confirmed">نوبت‌دهی فعال</span>
                <a href="doctor-panel.php?doctor=<?= (int) $d['id'] ?>&amp;tab=schedule&amp;clinic=<?= (int) $p['id'] ?>">برنامه کاری</a>
                <form method="post" data-confirm="نوبت‌دهی جدید در این شعبه متوقف شود؟ نوبت‌های قطعی حفظ می‌شوند."><?php form_token('center_presence_remove'); ?><input type="hidden" name="clinic_id" value="<?= (int) $p['id'] ?>"><button class="secondary small">توقف</button></form>
              <?php else: ?>
                <form method="post"><?php form_token('center_presence'); ?><input type="hidden" name="link_id" value="<?= (int) $l['id'] ?>"><input type="hidden" name="branch_id" value="<?= (int) $b['id'] ?>"><button class="small">نوبت‌دهی در این شعبه</button></form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <details>
        <summary><?= $l['status'] === 'active' ? 'پایان همکاری' : 'لغو درخواست' ?></summary>
        <form method="post" class="stack" data-confirm="این همکاری پایان یابد؟">
          <?php form_token('center_end'); ?><input type="hidden" name="link_id" value="<?= (int) $l['id'] ?>">
          <?php input('علت', 'reason', '', 'text', true, 500); ?>
          <button class="danger">تأیید</button>
        </form>
      </details>
    <?php endif; ?>
  </article>
<?php endforeach; ?>
<?php if (!$links): ?><p class="empty-state">هنوز با هیچ مرکز درمانی همکاری ندارید.</p><?php endif; ?>

<article class="card">
  <h2>درخواست همکاری با یک مرکز</h2>
  <p class="muted">کد مرکز در صفحه عمومی هر مرکز نمایش داده می‌شود.</p>
  <form method="post" class="stack">
    <?php form_token('center_request'); ?>
    <?php input('کد مرکز', 'center_code', '', 'text', true, 10); ?>
    <?php input('پیام برای مرکز (اختیاری)', 'message', '', 'text', false, 500); ?>
    <?php $permFields(['perm_reports' => 1]); ?>
    <button>ارسال درخواست</button>
  </form>
</article>
