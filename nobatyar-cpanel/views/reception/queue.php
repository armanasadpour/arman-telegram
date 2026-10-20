<?php
/** @var int $clinic @var array $c @var bool $canManage */
reception_scope($clinic, 'bookings');
$today = date('Y-m-d');
$state = queue_state($clinic, $today);
$token = queue_token($clinic);
$displayUrl = app_base_url() . 'queue.php?t=' . $token;
$rows = query(
    "SELECT b.*,s.starts_at FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.clinic_id=? AND DATE(s.starts_at)=? AND b.status='confirmed' ORDER BY COALESCE(b.queue_number,9999),s.starts_at",
    [$clinic, $today],
)->fetchAll();
?>
<div class="queue-head">
  <article class="queue-now">
    <span>شماره در حال ویزیت</span>
    <strong><?= $state['current'] !== null ? fa_number($state['current']) : '—' ?></strong>
  </article>
  <article class="queue-now muted-box">
    <span>منتظر در مطب</span>
    <strong><?= fa_number(count($state['waiting'])) ?></strong>
  </article>
  <article class="queue-now muted-box">
    <span>ویزیت‌شده امروز</span>
    <strong><?= fa_number($state['served']) ?></strong>
  </article>
</div>
<?php if ($canManage): ?>
  <form method="post" class="no-print queue-call"><?php form_token('call_next'); ?><button <?= $state['waiting'] ? '' : 'disabled' ?>><?= icon('queue') ?> فراخوان نفر بعدی<?= $state['waiting'] ? ' (شماره ' . fa_number($state['waiting'][0]) . ')' : '' ?></button></form>
<?php endif; ?>
<p class="muted">با ثبت «مراجعه‌کرده» برای هر بیمار، شماره نوبت روز به او داده می‌شود. «در حال ویزیت» شماره را روی نمایشگر صف می‌برد. بیمار شماره و تعداد نفرات جلوتر را در پنل خود می‌بیند.</p>
<section class="panel">
  <h2>نمایشگر صف سالن انتظار</h2>
  <p>این نشانی را روی تلویزیون یا تبلت سالن انتظار باز کنید. فقط شماره‌ها و تعداد منتظران نمایش داده می‌شود؛ نام و اطلاعات بیماران نمایش داده نمی‌شود.</p>
  <p><a class="share-link" href="<?= esc($displayUrl) ?>" target="_blank" rel="noopener"><?= esc($displayUrl) ?></a></p>
  <?php if ($canManage): ?>
    <form method="post" data-confirm="نشانی قبلی نمایشگر از کار می‌افتد. ادامه می‌دهید؟"><?php form_token('queue_token'); ?><button class="secondary small">ساخت نشانی جدید</button></form>
  <?php endif; ?>
</section>
<section class="panel">
  <h2>نوبت‌های امروز</h2>
  <div class="table-wrap"><table class="reception-table">
    <thead><tr><th>شماره</th><th>ساعت نوبت</th><th>بیمار</th><th>وضعیت</th><th>زمان تقریبی</th><?php if ($canManage): ?><th class="no-print">تغییر زمان تقریبی</th><?php endif; ?></tr></thead>
    <tbody>
      <?php foreach ($rows as $b): ?>
        <tr class="<?= $b['attendance_state'] === 'in_visit' ? 'row-current' : '' ?>">
          <td><?= $b['queue_number'] ? '<strong>' . fa_number($b['queue_number']) . '</strong>' : '—' ?></td>
          <td><bdi><?= fa_number(substr($b['starts_at'], 11, 5)) ?></bdi></td>
          <td><?= esc($b['patient_name']) ?></td>
          <td><span class="badge"><?= esc(attendance_label($b)) ?></span></td>
          <td><bdi><?= fa_number(substr(booking_eta($b), 11, 5)) ?></bdi></td>
          <?php if ($canManage): ?>
            <td class="no-print">
              <?php if ($b['attendance_state'] !== 'in_visit'): ?>
                <form method="post" class="inline-form estimate-form">
                  <?php form_token('estimate'); ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                  <label class="sr-only" for="eta-<?= (int) $b['id'] ?>">زمان تقریبی</label>
                  <input id="eta-<?= (int) $b['id'] ?>" name="estimated_time" value="<?= esc(substr(booking_eta($b), 11, 5)) ?>" maxlength="5" dir="ltr" required>
                  <label class="sr-only" for="eta-r-<?= (int) $b['id'] ?>">علت</label>
                  <input id="eta-r-<?= (int) $b['id'] ?>" name="reason" placeholder="علت" maxlength="300" required>
                  <button class="small secondary">ثبت و اطلاع</button>
                </form>
              <?php endif; ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (!$rows): ?><p class="empty-state">امروز نوبت قطعی برای این مطب ثبت نشده است.</p><?php endif; ?>
</section>
