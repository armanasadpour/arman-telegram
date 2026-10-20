<?php /** @var array $c @var array $s @var array $branches @var int $branch @var array $presences @var array $schedule @var array $rooms @var bool $open */
$days = fa_weekdays();
$canSchedule = !empty($s['perm_schedule']) && $open;
$byClinic = [];
foreach ($schedule as $h) {
    $byClinic[$h['clinic_id']][] = $h;
}
?>
<form method="get" class="filter-bar panel">
  <input type="hidden" name="center" value="<?= (int) $c['id'] ?>"><input type="hidden" name="tab" value="schedule">
  <label>شعبه<select name="branch" data-autosubmit>
    <?php if (!$s['branch_id']): ?><option value="0">همه شعبه‌ها</option><?php endif; ?>
    <?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"<?= selected_attr($branch === (int) $b['id']) ?>><?= esc($b['name']) ?></option><?php endforeach; ?>
  </select></label>
  <button><?= icon('filter') ?> نمایش</button>
</form>

<section class="panel">
  <h2>جدول اتاق‌ها در هفته</h2>
  <p class="muted">هر اتاق در هر ساعت فقط به یک پزشک داده می‌شود؛ سامانه هم‌پوشانی را نمی‌پذیرد.</p>
  <div class="table-wrap"><table class="room-grid">
    <thead><tr><th>روز</th><?php foreach ($branches as $b): if ($branch && (int) $b['id'] !== $branch) { continue; } foreach ($rooms[$b['id']] ?? [] as $r): ?><th><?= esc($r['name']) ?><br><small><?= esc($b['name']) ?></small></th><?php endforeach; endforeach; ?><th>بدون اتاق</th></tr></thead>
    <tbody>
      <?php foreach ($days as $w => $label): ?>
        <tr>
          <th><?= $label ?></th>
          <?php foreach ($branches as $b): if ($branch && (int) $b['id'] !== $branch) { continue; } foreach ($rooms[$b['id']] ?? [] as $r): ?>
            <td><?php foreach ($schedule as $h): if ((int) $h['weekday'] === $w && (int) $h['room_id'] === (int) $r['id']): ?><span class="shift-chip"><bdi><?= fa_number(substr($h['opens_at'], 0, 5) . '–' . substr($h['closes_at'], 0, 5)) ?></bdi> <?= esc($h['doctor']) ?></span><?php endif; endforeach; ?></td>
          <?php endforeach; endforeach; ?>
          <td><?php foreach ($schedule as $h): if ((int) $h['weekday'] === $w && !$h['room_id']): ?><span class="shift-chip muted-chip"><bdi><?= fa_number(substr($h['opens_at'], 0, 5) . '–' . substr($h['closes_at'], 0, 5)) ?></bdi> <?= esc($h['doctor']) ?> • <?= esc($h['branch']) ?></span><?php endif; endforeach; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<?php foreach ($presences as $p): $roomList = $rooms[$p['branch_id']] ?? []; $editable = $canSchedule && $p['perm_schedule']; ?>
  <article class="card" id="clinic-<?= (int) $p['id'] ?>">
    <div class="between"><h2><?= esc($p['doctor']) ?> <small class="muted">• <?= esc($p['branch']) ?></small></h2>
      <?php if (!$p['perm_schedule']): ?><span class="badge">برنامه با خود پزشک</span><?php endif; ?></div>
    <div class="shift-list">
      <?php foreach ($byClinic[$p['id']] ?? [] as $h): ?>
        <div class="shift-row">
          <strong><?= $days[$h['weekday']] ?></strong>
          <bdi><?= fa_number(substr($h['opens_at'], 0, 5) . ' — ' . substr($h['closes_at'], 0, 5)) ?></bdi>
          <span class="badge"><?= $h['room'] ? esc($h['room']) : 'بدون اتاق' ?></span>
          <?php if ($editable): ?>
            <form method="post" data-confirm="این بازه حذف شود؟ نوبت‌های قطعی قبلی حفظ می‌شوند."><?php form_token('hours_remove'); ?><input type="hidden" name="hours_id" value="<?= (int) $h['id'] ?>"><button class="secondary small">حذف</button></form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (empty($byClinic[$p['id']])): ?><p class="muted">ساعت حضوری ثبت نشده است.</p><?php endif; ?>
    </div>
    <?php if ($editable): ?>
      <details>
        <summary>افزودن ساعت حضور</summary>
        <form method="post" class="inline-form hours-form">
          <?php form_token('hours'); ?><input type="hidden" name="clinic_id" value="<?= (int) $p['id'] ?>">
          <label>روز<select name="weekday"><?php foreach ($days as $w => $l): ?><option value="<?= $w ?>"><?= $l ?></option><?php endforeach; ?></select></label>
          <label>از<input name="opens_at" value="09:00" maxlength="5" dir="ltr" required></label>
          <label>تا<input name="closes_at" value="13:00" maxlength="5" dir="ltr" required></label>
          <label>اتاق<select name="room_id"><option value="">بدون اتاق</option><?php foreach ($roomList as $r): ?><option value="<?= (int) $r['id'] ?>"><?= esc($r['name']) ?></option><?php endforeach; ?></select></label>
          <button class="small">ثبت</button>
        </form>
      </details>
      <details>
        <summary>مدت و ظرفیت نوبت</summary>
        <form method="post" class="inline-form">
          <?php form_token('presence_settings'); ?><input type="hidden" name="clinic_id" value="<?= (int) $p['id'] ?>">
          <label>مدت نوبت (دقیقه)<input type="number" name="default_duration" value="<?= (int) $p['default_duration'] ?>" min="5" max="180" required></label>
          <label>فاصله<input type="number" name="gap_minutes" value="<?= (int) $p['gap_minutes'] ?>" min="0" max="120" required></label>
          <label>ظرفیت هر بازه<input type="number" name="slot_capacity" value="<?= (int) $p['slot_capacity'] ?>" min="1" max="20" required></label>
          <label>ظرفیت روزانه<input type="number" name="daily_capacity" value="<?= (int) $p['daily_capacity'] ?>" min="1" max="500" required></label>
          <button class="small secondary">ذخیره</button>
        </form>
      </details>
    <?php endif; ?>
  </article>
<?php endforeach; ?>
<?php if (!$presences): ?><p class="empty-state">هنوز پزشکی در شعبه‌ها نوبت‌دهی ندارد. از بخش «پزشکان» پزشک را به شعبه اضافه کنید.</p><?php endif; ?>
