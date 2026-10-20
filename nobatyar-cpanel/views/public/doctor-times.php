<?php
/**
 * Time picker: a full-height page on phones (closes back to the profile), a wide centered panel on desktop.
 * Day cards show the real number of free times; booking.js swaps the times of another day in place.
 *
 * @var array $d @var array $clinics @var array $cl @var int $selected @var array $services @var int $service
 * @var int $move @var array $strip @var string $day @var array $times @var ?string $next @var array $legacy
 * @var string $error @var string $waitError @var int $fee @var string $auth @var array $free
 */
$id = (int) $d['id'];
$base = fn(array $p = []) => 'doctor.php?' . http_build_query(array_merge(['id' => $id, 'clinic' => $selected, 'service' => $service], $move ? ['move' => $move] : [], $p));
$selectedDay = null;
foreach ($strip as $s) {
    if ($s['date'] === $day) {
        $selectedDay = $s;
    }
}
$dayTitle = ($day === date('Y-m-d') ? 'امروز ' : ($day === date('Y-m-d', strtotime('+1 day')) ? 'فردا ' : jalali_weekday($day) . ' ')) . jalali_day_label($day);
$partial = ($_GET['partial'] ?? '') === 'times';
ob_start();
?>
<div class="tp-times" data-times-region data-day="<?= esc($day) ?>">
  <h2 class="tp-day-title"><?= esc($dayTitle) ?></h2>
  <?php if ($error): ?><p class="notice" role="alert"><?= esc($error) ?></p><?php endif; ?>
  <?php if ($times): ?>
    <div class="time-grid">
      <?php foreach ($times as $t): ?>
        <a class="time-option"<?= $auth ?> href="<?= esc('service-book.php?' . http_build_query(['clinic' => $selected, 'service' => $service, 'move' => $move, 'at' => $t['starts_at']])) ?>">
          <strong dir="ltr"><?= esc(fa_number(substr($t['starts_at'], 11, 5))) ?></strong>
          <?php if ((int) $t['capacity'] > 1): ?><small><?= fa_number($t['remaining']) ?> ظرفیت</small><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
    <p class="tp-hint"><?= icon('clock') ?> مدت هر نوبت <?= fa_number($times[0]['duration']) ?> دقیقه • ساعت‌ها به وقت تهران</p>
  <?php else: ?>
    <div class="tp-empty">
      <span class="tp-empty-icon"><?= icon('calendar') ?></span>
      <p>برای این روز نوبت آزادی وجود ندارد.</p>
      <?php if ($next): ?>
        <a class="button success" href="<?= esc($base(['date' => jdate($next)])) ?>" data-day-link data-date="<?= esc($next) ?>">برو به اولین نوبت آزاد بعدی <small>(<?= esc(jalali_day_label($next)) ?>)</small> <?= icon('chevron-left') ?></a>
      <?php elseif ($service): ?>
        <p class="muted">در ۹۰ روز آینده نوبت آزادی برای این مطب پیدا نشد؛ می‌توانید در صف انتظار ثبت شوید.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php
$timesHtml = (string) ob_get_clean();
if ($partial) {
    // Only the times of the chosen day (booking.js swaps them in), without the page around it.
    echo $timesHtml;
    return;
}
?>
<div class="tp" data-times-page>
  <header class="tp-head">
    <a class="tp-close" href="<?= esc($move ? 'receipt.php?id=' . $move : 'doctor.php?id=' . $id . '&clinic=' . $selected) ?>" aria-label="بستن و بازگشت به پروفایل"><?= icon('x') ?></a>
    <h1><?= $move ? 'انتخاب زمان جدید' : 'انتخاب نوبت' ?></h1>
  </header>

  <section class="tp-doctor">
    <span class="tp-photo"><?php if ($d['photo_file']): ?><img src="photo.php?id=<?= $id ?>" alt="" width="56" height="56"><?php else: ?><?= esc(mb_substr(trim(preg_replace('/^دکتر\s+/u', '', $d['name'])), 0, 1)) ?><?php endif; ?></span>
    <span class="tp-id">
      <strong><?= esc(doctor_display_name($d['name'])) ?><?= pro_badge($d) ?></strong>
      <small><?= esc(specialty_display_title($d['specialty_name'])) ?> • <?= esc($cl['name']) ?></small>
      <small class="tp-addr"><?= icon('pin') ?><?= esc($cl['city'] . '، ' . $cl['address']) ?></small>
    </span>
  </section>

  <?php if (count($clinics) > 1 && !$move): ?>
    <nav class="tp-chips" aria-label="انتخاب مطب">
      <?php foreach ($clinics as $c): ?>
        <a class="chip<?= (int) $c['id'] === $selected ? ' active' : '' ?>" href="<?= esc('doctor.php?' . http_build_query(['id' => $id, 'clinic' => $c['id'], 'times' => 1])) ?>"<?= (int) $c['id'] === $selected ? ' aria-current="true"' : '' ?>><?= icon('building') ?><?= esc($c['name'] . ' • ' . $c['city']) ?></a>
      <?php endforeach; ?>
    </nav>
  <?php endif; ?>

  <?php if (count($services) > 1): ?>
    <nav class="tp-chips" aria-label="انتخاب خدمت">
      <?php foreach ($services as $s): ?>
        <a class="chip<?= (int) $s['id'] === $service ? ' active' : '' ?>" href="<?= esc($base(['service' => $s['id']])) ?>"<?= (int) $s['id'] === $service ? ' aria-current="true"' : '' ?>><?= esc($s['name']) ?></a>
      <?php endforeach; ?>
    </nav>
  <?php elseif ($services): ?>
    <p class="tp-service"><?= icon('sp-stethoscope') ?> <?= esc($services[0]['name']) ?></p>
  <?php endif; ?>

  <?php if (trim((string) ($cl['notes'] ?? '')) !== ''): ?>
    <section class="tp-notes" data-clamp>
      <h2><?= icon('info') ?> ملاحظات <?= esc($cl['name']) ?></h2>
      <div class="dp-text" data-clamp-body><?= nl2br(esc($cl['notes'])) ?></div>
    </section>
  <?php endif; ?>

  <?php if (!$services): ?>
    <div class="tp-empty"><p>برنامه خدمات این پزشک هنوز منتشر نشده است.</p></div>
  <?php else: ?>
    <div class="day-strip" role="list" aria-label="روزهای پیش رو" data-day-strip>
      <?php foreach ($strip as $s): $on = $s['date'] === $day; ?>
        <a role="listitem" class="day-card state-<?= $s['state'] ?><?= $on ? ' is-selected' : '' ?><?= $s['today'] ? ' is-today' : '' ?>" href="<?= esc($base(['date' => jdate($s['date'])])) ?>" data-day-link data-date="<?= esc($s['date']) ?>"<?= $on ? ' aria-current="date"' : '' ?>>
          <span class="dc-week"><?= esc($s['weekday']) ?></span>
          <strong class="dc-date"><?= esc($s['label']) ?></strong>
          <small class="dc-count"><?= match ($s['state']) { 'free' => fa_number($s['free']) . ' نوبت', 'full' => 'تکمیل', 'closed' => 'تعطیل', default => '—' } ?></small>
        </a>
      <?php endforeach; ?>
    </div>
    <?= $timesHtml ?>
    <details class="tp-month">
      <summary><?= icon('calendar') ?> انتخاب از تقویم ماه<?= icon('chevron-down', 'icon chev') ?></summary>
      <?php public_calendar($id, $selected, $service, $day, $move); ?>
    </details>
  <?php endif; ?>

  <?php if ($services && !$move && setting('waitlist_enabled', '1') === '1' && doctor_has($id, 'waitlist', $d)): $viewer = user_now(); ?>
    <details class="waitlist-box"<?= $waitError || !$times ? ' open' : '' ?>>
      <summary><?= icon('bell') ?> زمان مناسب پیدا نکردید؟ خبرم کن<?= icon('chevron-down', 'icon chev') ?></summary>
      <?php if ($waitError): ?><p class="error" role="alert"><?= esc($waitError) ?></p><?php endif; ?>
      <p class="muted">با آزادشدن ظرفیت این مطب در بازه انتخابی، به ترتیب ثبت درخواست اعلان می‌گیرید (حداکثر <?= fa_number(setting('waitlist_max_days', '14')) ?> روز).</p>
      <form method="post" class="waitlist-form" action="<?= esc($base(['date' => jdate($day)])) ?>">
        <?php form_token('waitlist'); ?>
        <div class="form-grid">
          <label>از تاریخ<input name="date_from" value="<?= esc(jdate(max($day, date('Y-m-d')))) ?>" dir="ltr" inputmode="numeric" required></label>
          <label>تا تاریخ<input name="date_to" value="<?= esc(jdate(date('Y-m-d', strtotime(max($day, date('Y-m-d')) . ' +6 days')))) ?>" dir="ltr" inputmode="numeric" required></label>
          <?php if ($viewer): ?>
            <label>برای<select name="family_member_id"><option value="0">خودم</option><?php foreach (query('SELECT id,name FROM family_members WHERE user_id=? AND active=1', [$viewer['id']])->fetchAll() as $f): ?><option value="<?= (int) $f['id'] ?>"><?= esc($f['name']) ?></option><?php endforeach; ?></select></label>
            <?php if (!$viewer['name']): ?><label>نام بیمار<input name="name" maxlength="190" required></label><?php endif; ?>
          <?php endif; ?>
        </div>
        <button><?= icon('bell') ?> <?= $viewer ? 'ثبت در صف انتظار' : 'ورود و ثبت در صف انتظار' ?></button>
      </form>
    </details>
  <?php endif; ?>

  <?php if ($legacy): ?>
    <section class="tp-legacy">
      <h2>نوبت‌های از پیش منتشرشده</h2>
      <div class="time-grid">
        <?php foreach ($legacy as $s): ?><a class="time-option"<?= $auth ?> href="book.php?slot=<?= (int) $s['id'] ?>"><strong><?= esc(jdate($s['starts_at'], true)) ?></strong><small>رزرو</small></a><?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <p class="tp-fee"><?= icon('wallet') ?> <?= $fee > 0 ? 'کارمزد رزرو اینترنتی ' . esc(toman($fee)) : 'رزرو این مطب رایگان است' ?>؛ هزینه ویزیت در مطب پرداخت می‌شود.</p>
</div>
<script src="assets/booking.js?v=<?= esc(APP_VERSION) ?>" defer></script>
