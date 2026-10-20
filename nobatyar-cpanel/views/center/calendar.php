<?php /** @var array $c @var array $s @var array $f @var array $rows @var array $doctors @var array $branches @var array $schedule */
$statusLabel = fn(array $r) => $r['status'] === 'confirmed' ? attendance_label($r) : booking_status_label($r['status']);
$days = [];
foreach ($rows as $r) {
    $days[substr($r['starts_at'], 0, 10)][] = $r;
}
$canReception = !empty($s['perm_bookings']);
$single = $f['from'] === $f['to'];
$base = ['center' => (int) $c['id'], 'tab' => 'calendar', 'branch' => $f['branch'], 'doctor' => $f['doctor']];
?>
<form method="get" class="filter-bar panel">
  <input type="hidden" name="center" value="<?= (int) $c['id'] ?>"><input type="hidden" name="tab" value="calendar">
  <label>از تاریخ<input data-jdate name="from" value="<?= esc(jdate($f['from'])) ?>" dir="ltr"></label>
  <label>تا تاریخ<input data-jdate name="to" value="<?= esc(jdate($f['to'])) ?>" dir="ltr"></label>
  <?php if (!$s['branch_id']): ?>
    <label>شعبه<select name="branch"><option value="0">همه</option><?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"<?= selected_attr($f['branch'] === (int) $b['id']) ?>><?= esc($b['name']) ?></option><?php endforeach; ?></select></label>
  <?php endif; ?>
  <label>پزشک<select name="doctor"><option value="0">همه</option><?php foreach ($doctors as $d): ?><option value="<?= (int) $d['id'] ?>"<?= selected_attr($f['doctor'] === (int) $d['id']) ?>><?= esc($d['name']) ?></option><?php endforeach; ?></select></label>
  <label>وضعیت<select name="status"><option value="">همه</option><?php foreach (['confirmed' => 'قطعی', 'completed' => 'ویزیت‌شده', 'no_show' => 'عدم مراجعه', 'cancelled' => 'لغوشده'] as $k => $l): ?><option value="<?= $k ?>"<?= selected_attr($f['status'] === $k) ?>><?= $l ?></option><?php endforeach; ?></select></label>
  <button><?= icon('filter') ?> نمایش</button>
</form>
<div class="calendar-toolbar">
  <a class="button secondary small" href="center.php?<?= esc(http_build_query($base + ['from' => jdate(date('Y-m-d', strtotime($f['from'] . ' -1 day'))), 'to' => jdate(date('Y-m-d', strtotime($f['from'] . ' -1 day')))])) ?>"><?= icon('chevron-right') ?> روز قبل</a>
  <a class="button secondary small" href="center.php?<?= esc(http_build_query($base + ['from' => jdate(date('Y-m-d')), 'to' => jdate(date('Y-m-d'))])) ?>">امروز</a>
  <a class="button secondary small" href="center.php?<?= esc(http_build_query($base + ['from' => jdate(date('Y-m-d')), 'to' => jdate(date('Y-m-d', strtotime('+6 days')))])) ?>">هفت روز آینده</a>
  <a class="button secondary small" href="center.php?<?= esc(http_build_query($base + ['from' => jdate(date('Y-m-d', strtotime($f['to'] . ' +1 day'))), 'to' => jdate(date('Y-m-d', strtotime($f['to'] . ' +1 day')))])) ?>">روز بعد <?= icon('chevron-left') ?></a>
  <span class="muted"><?= fa_number(count($rows)) ?> نوبت</span>
</div>
<?php foreach ($days as $day => $list): ?>
  <section class="panel day-block">
    <h2><?= esc(jdate($day)) ?> <small class="muted"><?= fa_weekdays()[(int) date('w', strtotime($day))] ?></small></h2>
    <div class="table-wrap"><table class="center-calendar">
      <thead><tr><th>ساعت</th><th>پزشک</th><th>شعبه / اتاق</th><th>بیمار</th><th>خدمت</th><th>کانال</th><th>وضعیت</th><?php if ($canReception): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
        <?php foreach ($list as $r): ?>
          <tr class="row-<?= esc($r['status']) ?>">
            <td><bdi><?= fa_number(substr($r['starts_at'], 11, 5)) ?></bdi></td>
            <td><?= esc($r['doctor']) ?></td>
            <td><?= esc($r['branch']) ?><?= $r['room'] ? '<br><small>' . esc($r['room']) . '</small>' : '' ?></td>
            <td><?php if ($r['visible']): ?><?= esc($r['patient_name']) ?><br><small><bdi><?= esc(fa_number($r['phone'])) ?></bdi></small><?php else: ?><span class="muted">محرمانه</span><?php endif; ?></td>
            <td><?= esc($r['service_name']) ?></td>
            <td><?= $r['booking_channel'] === 'online' ? 'آنلاین' : 'پذیرش' ?></td>
            <td><span class="badge status-<?= esc($r['status']) ?>"><?= esc($statusLabel($r)) ?></span></td>
            <?php if ($canReception): ?><td><?php if ($r['visible']): ?><a href="secretary.php?clinic=<?= (int) $r['clinic_id'] ?>&amp;tab=calendar&amp;date=<?= esc(jdate(substr($r['starts_at'], 0, 10))) ?>">پذیرش</a><?php endif; ?></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
<?php endforeach; ?>
<?php if (!$rows): ?><p class="empty-state">در این بازه نوبتی برای پزشکان مرکز ثبت نشده است.</p><?php endif; ?>
<p class="muted">نام و تماس بیمار فقط برای پزشکانی نمایش داده می‌شود که اجازه مدیریت نوبت داده‌اند؛ سوابق گذشته نیازمند اجازه جداگانه است. پزشکانی که فقط گزارش آماری مجاز کرده‌اند به‌صورت «محرمانه» دیده می‌شوند.</p>
