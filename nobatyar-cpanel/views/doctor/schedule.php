<?php
/**
 * Simple weekly schedule of one clinic: tick the days, enter from/to (up to three shifts), save once.
 * Each day may use its own appointment length (e.g. every 10 minutes on Saturdays); empty means the clinic default.
 * The program may hold only between two dates (empty = from now / without end).
 * Services patients can book are ticked at the top, so a doctor never ends up with hours but nothing to book.
 * Booking settings in plain words, and leave/closures by date only.
 * @var array $d @var array $cl @var int $clinic @var array $hours @var array $blocks @var array $rooms @var array $services
 */
$names = [6 => 'شنبه', 0 => 'یکشنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه'];
$posted = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'week' ? ($_POST['day'] ?? []) : null;
$week = [];
foreach ($names as $w => $_) {
    $week[$w] = ['on' => false, 'shifts' => [], 'room' => '', 'len' => ''];
}
if (is_array($posted)) {
    foreach ($names as $w => $_) {
        $p = is_array($posted[$w] ?? null) ? $posted[$w] : [];
        $week[$w]['on'] = !empty($p['on']);
        foreach ((array) ($p['from'] ?? []) as $i => $f) {
            if (trim((string) $f) !== '' || trim((string) ($p['to'][$i] ?? '')) !== '') {
                $week[$w]['shifts'][] = [(string) $f, (string) ($p['to'][$i] ?? '')];
            }
        }
        $week[$w]['room'] = (string) ($p['room'] ?? '');
        $week[$w]['len'] = is_scalar($p['len'] ?? '') ? (string) ($p['len'] ?? '') : '';
    }
} else {
    foreach ($hours as $h) {
        $w = (int) $h['weekday'];
        $week[$w]['on'] = true;
        $week[$w]['shifts'][] = [substr($h['opens_at'], 0, 5), substr($h['closes_at'], 0, 5)];
        $week[$w]['room'] = (string) ($h['room_id'] ?? '');
        $week[$w]['len'] = (string) ($h['slot_minutes'] ?? '');
    }
}
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$range = ['from' => '', 'until' => ''];
if ($posted !== null) {
    $range = ['from' => (string) ($_POST['valid_from'] ?? ''), 'until' => (string) ($_POST['valid_until'] ?? '')];
} elseif ($hours) {
    $range = ['from' => $hours[0]['valid_from'] ? jdate($hours[0]['valid_from']) : '', 'until' => $hours[0]['valid_until'] ? jdate($hours[0]['valid_until']) : ''];
}
$rangeEnded = $hours && $hours[0]['valid_until'] && $hours[0]['valid_until'] < date('Y-m-d');
$rangeLater = $hours && $hours[0]['valid_from'] && $hours[0]['valid_from'] > date('Y-m-d');
$activeServices = array_filter($services, fn($s) => !empty($s['active']) && empty($s['plan_locked']));
$picked = $isPost && ($_POST['action'] ?? '') === 'services_pick'
    ? array_flip(array_filter((array) ($_POST['svc'] ?? []), 'is_string'))
    : ($services ? array_flip(array_column(array_filter($services, fn($s) => !empty($s['active'])), 'name')) : ['ویزیت عمومی' => 0]);
$chips = array_values(array_unique(array_merge(array_column($services, 'name'), service_presets())));
$durations = [10, 15, 20, 25, 30, 40, 45, 60, 90, 120];
if (!in_array((int) $cl['default_duration'], $durations, true)) {
    $durations[] = (int) $cl['default_duration'];
    sort($durations);
}
$step = max(5, (int) $cl['default_duration'] + (int) $cl['gap_minutes']);
$lengths = $durations;
foreach ($week as $day) {
    if ($day['len'] !== '' && !in_array((int) $day['len'], $lengths, true)) {
        $lengths[] = (int) $day['len'];
    }
}
sort($lengths);
$count = function (array $shifts, string $len = '') use ($step, $cl): int {
    $step = $len !== '' ? max(5, (int) $len + (int) $cl['gap_minutes']) : $step;
    $n = 0;
    foreach ($shifts as [$a, $b]) {
        if (preg_match('/^\d\d:\d\d$/', $a) && preg_match('/^\d\d:\d\d$/', $b) && $a < $b) {
            [$ah, $am] = explode(':', $a);
            [$bh, $bm] = explode(':', $b);
            $n += intdiv(((int) $bh * 60 + (int) $bm) - ((int) $ah * 60 + (int) $am), $step);
        }
    }
    return min($n * max(1, (int) $cl['slot_capacity']), (int) $cl['daily_capacity']);
};
$timeSelect = function (string $name, string $value, string $label): string {
    $out = '<select name="' . esc($name) . '" aria-label="' . esc($label) . '" dir="ltr"><option value="">—</option>';
    $times = [];
    for ($m = 0; $m < 1440; $m += 15) {
        $times[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }
    if ($value !== '' && !in_array($value, $times, true)) {
        $times[] = $value;
        sort($times);
    }
    foreach ($times as $t) {
        $out .= '<option value="' . $t . '"' . selected_attr($t === $value) . '>' . fa_number($t) . '</option>';
    }
    return $out . '</select>';
};
$token = function (string $action) use ($clinic) {
    form_token($action);
    echo '<input type="hidden" name="clinic_id" value="' . (int) $clinic . '">';
};
?>
<div class="sched" data-schedule data-step="<?= $step ?>" data-gap="<?= (int) $cl['gap_minutes'] ?>" data-seats="<?= max(1, (int) $cl['slot_capacity']) ?>" data-daily="<?= (int) $cl['daily_capacity'] ?>">
  <section class="sc-card sc-services">
    <div class="sc-head"><div><h2>خدماتی که بیمار رزرو می‌کند</h2><p class="muted">بیمار هنگام گرفتن نوبت یکی از این خدمات را انتخاب می‌کند؛ هر کدام را که ارائه می‌دهید روشن کنید.</p></div></div>
    <?php if (!$activeServices): ?>
      <p class="sc-warn"><?= icon('alert') ?><span>هنوز خدمت فعالی ندارید؛ بدون خدمت، بیماران نمی‌توانند نوبت بگیرند.<?= $d['is_owner'] ? ' دست‌کم یکی را انتخاب و ذخیره کنید (با ذخیره برنامه هفتگی، «ویزیت عمومی» خودکار اضافه می‌شود).' : ' از پزشک بخواهید خدمات را تعریف کند.' ?></span></p>
    <?php endif; ?>
    <?php if ($d['is_owner']): ?>
      <form method="post" class="sc-svc-form">
        <?php $token('services_pick'); ?>
        <div class="svc-chips" role="group" aria-label="خدمات">
          <?php foreach ($chips as $n): ?><label class="svc-chip"><input type="checkbox" name="svc[]" value="<?= esc($n) ?>"<?= checked_attr(isset($picked[$n])) ?>><span><?= icon('check') ?><?= esc($n) ?></span></label><?php endforeach; ?>
        </div>
        <label class="field sc-svc-other"><span>خدمت دیگر (اختیاری)</span><input name="svc_other" maxlength="190" placeholder="مثلاً لیزر موهای زائد"></label>
        <div class="sc-svc-actions">
          <button class="button secondary"><?= icon('check') ?> ذخیره خدمات</button>
          <a href="doctor-panel.php?doctor=<?= (int) $cl['doctor_id'] ?>&amp;tab=services"><?= icon('edit') ?> مدت و تعرفه هر خدمت</a>
        </div>
      </form>
    <?php elseif ($activeServices): ?>
      <p class="muted"><?= esc(implode('، ', array_column($activeServices, 'name'))) ?></p>
    <?php endif; ?>
  </section>

  <section class="sc-card">
    <div class="sc-head">
      <div><h2>ساعت حضور در <?= esc($cl['name']) ?></h2><p class="muted">بازه تاریخ برنامه را (در صورت نیاز) تعیین کنید، روزهایی که در مطب هستید را تیک بزنید و ساعت شروع و پایان را وارد کنید. اگر روزی فاصله نوبت‌ها فرق دارد (مثلاً شنبه‌ها هر ۱۰ دقیقه)، «مدت هر نوبت» همان روز را تغییر دهید. در پایان «ذخیره برنامه» را بزنید.</p></div>
    </div>
    <form method="post" class="sc-week" data-week-form>
      <?php $token('week'); ?>
      <?php if ($rangeEnded): ?><p class="sc-warn"><?= icon('alert') ?><span>این برنامه در <?= esc(jalali_long($hours[0]['valid_until'])) ?> تمام شده و نوبتی داده نمی‌شود؛ تاریخ پایان را تمدید یا خالی کنید.</span></p><?php endif; ?>
      <?php if ($rangeLater): ?><p class="notice"><?= icon('calendar') ?> این برنامه از <?= esc(jalali_long($hours[0]['valid_from'])) ?> شروع می‌شود.</p><?php endif; ?>
      <div class="sc-range">
        <label class="field"><span>از تاریخ</span><input name="valid_from" value="<?= esc($range['from']) ?>" dir="ltr" inputmode="numeric" maxlength="10" placeholder="از امروز"></label>
        <label class="field"><span>تا تاریخ</span><input name="valid_until" value="<?= esc($range['until']) ?>" dir="ltr" inputmode="numeric" maxlength="10" placeholder="بدون پایان"></label>
        <small class="muted">مثلاً ۱۴۰۵/۰۸/۰۱ تا ۱۴۰۵/۰۹/۳۰؛ خالی یعنی از امروز و بدون تاریخ پایان.</small>
      </div>
      <div class="sc-tools" hidden data-week-tools>
        <button type="button" class="button ghost small" data-copy-week><?= icon('clock') ?> همین ساعت‌ها برای همه روزهای روشن</button>
        <button type="button" class="button ghost small" data-copy-len><?= icon('timer') ?> همین مدت نوبت برای همه روزهای روشن</button>
      </div>
      <?php foreach ($names as $w => $label): $day = $week[$w]; $shifts = $day['shifts'] ?: [['09:00', '13:00']]; ?>
        <div class="sc-day<?= $day['on'] ? ' on' : '' ?>" data-day>
          <label class="sc-switch">
            <input type="checkbox" name="day[<?= $w ?>][on]" value="1"<?= checked_attr($day['on']) ?> data-day-on>
            <span class="sc-knob" aria-hidden="true"></span>
            <strong><?= $label ?></strong>
          </label>
          <div class="sc-shifts" data-shifts>
            <?php for ($i = 0; $i < 3; $i++): $s = $shifts[$i] ?? ['', '']; $empty = $s[0] === '' && $s[1] === '' && $i > 0; ?>
              <div class="sc-shift" data-shift<?= $empty ? ' data-empty' : '' ?>>
                <span class="sc-from" aria-hidden="true">از</span>
                <?= $timeSelect('day[' . $w . '][from][]', substr($s[0], 0, 5), $label . ' بازه ' . fa_number($i + 1) . ' از ساعت') ?>
                <span class="sc-dash" aria-hidden="true">تا</span>
                <?= $timeSelect('day[' . $w . '][to][]', substr($s[1], 0, 5), $label . ' بازه ' . fa_number($i + 1) . ' تا ساعت') ?>
                <?php if ($i > 0): ?><button type="button" class="icon-button sc-remove" data-shift-remove hidden aria-label="حذف این بازه"><?= icon('x') ?></button><?php endif; ?>
              </div>
            <?php endfor; ?>
            <button type="button" class="link-button sc-add" data-shift-add hidden><?= icon('plus') ?> افزودن بازه دیگر (مثلاً عصر)</button>
            <label class="sc-len">مدت هر نوبت
              <select name="day[<?= $w ?>][len]" data-day-len>
                <option value="">مثل تنظیم مطب (<?= fa_number((int) $cl['default_duration']) ?> دقیقه)</option>
                <?php foreach ($lengths as $m): ?><option value="<?= $m ?>"<?= selected_attr($day['len'] === (string) $m) ?>><?= fa_number($m) ?> دقیقه</option><?php endforeach; ?>
              </select>
            </label>
            <?php if ($rooms): ?>
              <label class="sc-room">اتاق مرکز<select name="day[<?= $w ?>][room]"><option value="">بدون اتاق مشخص</option><?php foreach ($rooms as $r): ?><option value="<?= (int) $r['id'] ?>"<?= selected_attr($day['room'] === (string) $r['id']) ?>><?= esc($r['name']) ?></option><?php endforeach; ?></select></label>
            <?php endif; ?>
          </div>
          <span class="sc-off">تعطیل</span>
          <small class="sc-count" data-count><?= $day['on'] ? '≈ ' . fa_number($count($day['shifts'], $day['len'])) . ' نوبت' : '' ?></small>
        </div>
      <?php endforeach; ?>
      <div class="sc-save">
        <button class="button"><?= icon('check') ?> ذخیره برنامه</button>
        <small class="muted">نوبت‌های قطعی قبلی با تغییر برنامه حذف نمی‌شوند.</small>
      </div>
    </form>
  </section>

  <section class="sc-card">
    <div class="sc-head"><div><h2>نوبت‌دهی</h2><p class="muted">مدت پیش‌فرض هر نوبت و تعداد بیماران؛ برای روزهایی که در برنامه هفتگی مدت جداگانه دارند، همان مدت به کار می‌رود.</p></div></div>
    <form method="post" class="sc-settings">
      <?php $token('clinic_settings'); ?>
      <div class="sc-grid">
        <label class="field"><span>مدت هر نوبت</span>
          <select name="default_duration"><?php foreach ($durations as $m): ?><option value="<?= $m ?>"<?= selected_attr((int) $cl['default_duration'] === $m) ?>><?= fa_number($m) ?> دقیقه</option><?php endforeach; ?></select>
        </label>
        <label class="field"><span>بیمار در هر نوبت</span><input type="number" name="slot_capacity" min="1" max="20" value="<?= (int) $cl['slot_capacity'] ?>" inputmode="numeric"></label>
        <label class="field"><span>حداکثر بیمار در روز</span><input type="number" name="daily_capacity" min="1" max="500" value="<?= (int) $cl['daily_capacity'] ?>" inputmode="numeric"></label>
      </div>
      <details class="sc-more"<?= (int) $cl['gap_minutes'] > 0 ? ' open' : '' ?>>
        <summary>تنظیمات بیشتر</summary>
        <label class="field"><span>استراحت بین دو نوبت (دقیقه)</span><input type="number" name="gap_minutes" min="0" max="120" value="<?= (int) $cl['gap_minutes'] ?>" inputmode="numeric"></label>
      </details>
      <button class="button secondary"><?= icon('check') ?> ذخیره</button>
    </form>
  </section>

  <section class="sc-card">
    <div class="sc-head"><div><h2>مرخصی و تعطیلی</h2><p class="muted">در این روزها نوبت جدید داده نمی‌شود.</p></div></div>
    <form method="post" class="sc-block" data-block-form>
      <?php $token('block_days'); ?>
      <div class="seg sc-kind">
        <?php foreach (['leave' => 'مرخصی', 'holiday' => 'تعطیلی', 'closed' => 'بستن مطب'] as $k => $l): ?><label><input type="radio" name="kind" value="<?= $k ?>"<?= checked_attr($k === 'leave') ?>><span><?= $l ?></span></label><?php endforeach; ?>
      </div>
      <div class="sc-grid two">
        <label class="field"><span>از تاریخ</span><input name="from_date" value="<?= esc(jdate(date('Y-m-d'))) ?>" dir="ltr" inputmode="numeric" placeholder="۱۴۰۵/۰۷/۲۶" required></label>
        <label class="field"><span>تا تاریخ</span><input name="to_date" value="" dir="ltr" inputmode="numeric" placeholder="همان روز"></label>
      </div>
      <label class="check"><input type="checkbox" name="partial" value="1" data-partial> فقط چند ساعت از روز</label>
      <div class="sc-grid two" data-partial-times>
        <label class="field"><span>از ساعت</span><?= $timeSelect('from_time', '10:00', 'از ساعت') ?></label>
        <label class="field"><span>تا ساعت</span><?= $timeSelect('to_time', '12:00', 'تا ساعت') ?></label>
      </div>
      <label class="field"><span>توضیح (اختیاری)</span><input name="reason" maxlength="500" placeholder="مثلاً سفر کاری"></label>
      <?php if ($d['is_owner']): ?><label class="check"><input type="checkbox" name="all_clinics" value="1"> برای همه مطب‌های من</label><?php endif; ?>
      <button class="button secondary"><?= icon('calendar') ?> ثبت</button>
    </form>
    <?php if ($blocks): ?>
      <ul class="sc-blocks">
        <?php foreach ($blocks as $b): $whole = substr($b['starts_at'], 11) === '00:00:00' && substr($b['ends_at'], 11) === '00:00:00'; ?>
          <li>
            <span class="badge"><?= esc(['leave' => 'مرخصی', 'holiday' => 'تعطیلی', 'closed' => 'بسته'][$b['kind']] ?? 'بسته') ?></span>
            <span class="sc-when"><?= esc($whole ? (substr($b['starts_at'], 0, 10) === date('Y-m-d', strtotime($b['ends_at'] . ' -1 day')) ? jalali_long(substr($b['starts_at'], 0, 10)) : jdate($b['starts_at']) . ' تا ' . jdate(date('Y-m-d', strtotime($b['ends_at'] . ' -1 day')))) : jdate($b['starts_at'], true) . ' تا ' . jdate($b['ends_at'], true)) ?><small><?= esc($b['reason']) ?><?= $b['clinic_id'] ? '' : ' • همه مطب‌ها' ?></small></span>
            <?php if ($d['is_owner'] || $b['clinic_id']): ?>
              <form method="post" class="inline-form"><?php $token('block_remove'); ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="link-button danger-link">حذف</button></form>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
