<?php
/**
 * «چک نوبت» for the doctor panel and the secretary panel (1.0.21): pick a day, list its appointments, then find a
 * patient by national ID or name in a popup and record «ثبت حضور».
 * @var int $doctorId @var int $clinic @var array $clinics @var string $day @var array $rows @var bool $locked
 * @var array $hidden extra GET fields of the panel (doctor/tab) @var string $page doctor-panel.php or secretary.php
 */
$counts = ['all' => count($rows), 'in' => 0, 'wait' => 0];
foreach ($rows as $r) {
    if (in_array($r['state'], ['arrived', 'in_visit', 'completed'], true)) $counts['in']++;
    elseif ($r['state'] === 'waiting') $counts['wait']++;
}
$badge = ['waiting' => 'status-held', 'arrived' => 'status-confirmed', 'in_visit' => 'status-completed', 'completed' => 'status-completed', 'no_show' => 'status-no_show'];
?>
<section class="ck">
  <form method="get" action="<?= esc($page) ?>" class="ck-search">
    <?php foreach ($hidden as $k => $v): ?><input type="hidden" name="<?= esc($k) ?>" value="<?= esc((string) $v) ?>"><?php endforeach; ?>
    <?php if (count($clinics) > 1): ?>
      <label class="field"><span>مطب</span><select name="clinic"><?php foreach ($clinics as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected_attr((int) $c['id'] === $clinic) ?>><?= esc($c['name'] . (!empty($c['doctor']) ? ' — ' . $c['doctor'] : '')) ?></option><?php endforeach; ?></select></label>
    <?php else: ?><input type="hidden" name="clinic" value="<?= (int) $clinic ?>"><?php endif; ?>
    <label class="field"><span>تاریخ چک نوبت</span><input data-jdate name="date" value="<?= esc(jdate($day)) ?>" dir="ltr" inputmode="numeric" maxlength="10" required></label>
    <button class="button"><?= icon('search') ?> جستجو</button>
  </form>

  <?php if ($locked): ?>
    <?= pro_feature_card($doctorId, 'checkin', 'با آن حضور بیماران را با کد ملی یا نام در چند ثانیه ثبت کنید.') ?>
  <?php else: ?>
    <div class="ck-head">
      <div>
        <h2>نوبت‌های <?= esc(jalali_long($day)) ?></h2>
        <p class="ck-stats"><span><?= fa_number($counts['all']) ?> نوبت</span><span class="ok"><?= fa_number($counts['in']) ?> مراجعه‌کرده</span><span class="wait"><?= fa_number($counts['wait']) ?> در انتظار</span></p>
      </div>
      <button type="button" class="button ck-open" data-ck-open><?= icon('calendar-check') ?> چک نوبت</button>
    </div>

    <?php if (!$rows): ?>
      <div class="empty-state"><?= icon('calendar') ?><p>برای این روز نوبتی ثبت نشده است.</p></div>
    <?php else: ?>
      <div class="ck-table-wrap">
        <table class="ck-table">
          <thead><tr><th>ردیف</th><th>نام بیمار</th><th>کد ملی</th><th>تاریخ و ساعت نوبت</th><th>وضعیت نوبت</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $i => $r): ?>
              <tr data-ck-row="<?= (int) $r['id'] ?>">
                <td data-label="ردیف"><?= fa_number($i + 1) ?></td>
                <td data-label="نام بیمار"><strong><?= esc($r['patient_name']) ?></strong></td>
                <td data-label="کد ملی"><bdi dir="ltr"><?= $r['national_id'] !== '' ? esc(fa_number($r['national_id'])) : '—' ?></bdi></td>
                <td data-label="تاریخ و ساعت"><bdi><?= esc(jdate($r['starts_at'], true)) ?></bdi></td>
                <td data-label="وضعیت"><span class="badge <?= esc($badge[$r['state']] ?? '') ?>" data-ck-state><?= esc($r['state_label']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <dialog class="ck-dialog" data-ck-dialog data-clinic="<?= (int) $clinic ?>" data-date="<?= esc($day) ?>" aria-labelledby="ck-title">
      <div class="ck-dialog-head"><h2 id="ck-title"><?= icon('calendar-check') ?> چک نوبت</h2><button type="button" class="ck-x" data-ck-close aria-label="بستن"><?= icon('x') ?></button></div>
      <form class="ck-find" data-ck-find data-no-busy>
        <label class="field"><span>کد ملی یا نام و نام خانوادگی بیمار</span><input name="q" autocomplete="off" required maxlength="120" placeholder="مثلاً ۰۰۱۲۳۴۵۶۷۸ یا علی احمدی"></label>
        <button class="button"><?= icon('search') ?> تأیید</button>
      </form>
      <p class="ck-msg" data-ck-msg role="status"></p>
      <div class="ck-results" data-ck-results></div>
    </dialog>
  <?php endif; ?>
</section>
