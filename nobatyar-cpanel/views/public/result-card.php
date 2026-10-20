<?php
/**
 * One doctor in the search results. Real data only: rating appears when enough approved reviews exist,
 * the completed-appointment count only when it is above zero, badges only when their condition holds.
 *
 * @var array $d search_cards() row @var array $cfg search_config()
 */
$primary = $d['clinics'][0];
$others = array_slice($d['clinics'], 1);
$profile = doctor_url((int) $d['id'], $d['name'], (int) $primary['id']);
$book = 'doctor.php?id=' . (int) $d['id'] . '&clinic=' . (int) $primary['id'] . '#booking';
$b = $cfg['badges'];
$badges = [];
if ($b['custom'] && $d['badge_text'] !== '') {
    $badges[] = ['custom', 'sparkles', $d['badge_text']];
}
if ($b['featured'] && $d['featured']) {
    $badges[] = ['featured', 'star', 'پزشک منتخب'];
}
if ($b['today'] && $d['today']) {
    $badges[] = ['today', 'zap', 'نوبت امروز'];
} elseif ($b['tomorrow'] && $d['tomorrow']) {
    $badges[] = ['tomorrow', 'calendar-clock', 'نوبت فردا'];
}
if ($b['multi'] && count($d['clinics']) > 1) {
    $badges[] = ['multi', 'building', fa_number(count($d['clinics'])) . ' مطب فعال'];
}
$extra = ($cfg['show_code'] && trim((string) $d['medical_code']) !== '') || $d['experience_years'];
?>
<article class="result-card" data-result-card>
  <div class="rc-head">
    <a class="rc-photo" href="<?= esc($profile) ?>" tabindex="-1" aria-hidden="true">
      <?php if ($d['photo_file']): ?><img src="photo.php?id=<?= (int) $d['id'] ?>" alt="" width="84" height="84" loading="lazy" decoding="async"><?php else: ?><span><?= esc(mb_substr(trim(preg_replace('/^دکتر\s+/u', '', $d['name'])), 0, 1)) ?></span><?php endif; ?>
    </a>
    <div class="rc-id">
      <h3><a href="<?= esc($profile) ?>"><?= esc(doctor_display_name($d['name'])) ?></a><?= pro_badge($d) ?></h3>
      <p class="rc-spec"><?= esc(specialty_display_title($d['specialty'])) ?></p>
      <?php if ($cfg['show_bio'] && trim((string) $d['professional_title']) !== ''): ?><p class="rc-bio"><?= esc($d['professional_title']) ?></p><?php endif; ?>
      <?php if ($cfg['reviews']): ?>
        <p class="rc-rating">
          <?php if ($d['rating']): ?>
            <span class="rc-stars"><?= icon('star') ?> <b><?= esc(fa_number(number_format($d['rating']['avg'], 1))) ?></b> <small>(<?= fa_number($d['rating']['count']) ?> نظر)</small></span>
            <span class="rc-rec"><?= icon('heart-handshake') ?> <?= fa_number($d['rating']['recommend']) ?>٪ پیشنهاد بیماران</span>
          <?php else: ?>
            <span class="rc-none">هنوز امتیازی ثبت نشده</span>
          <?php endif; ?>
        </p>
      <?php endif; ?>
      <?php if ($cfg['show_done'] && $d['done'] > 0): ?><p class="rc-done"><?= icon('badge') ?> <?= fa_number($d['done']) ?> نوبت انجام‌شده در <?= esc(site_name()) ?></p><?php endif; ?>
    </div>
  </div>
  <?php if ($badges || $b['verified']): ?>
    <ul class="rc-badges">
      <?php if ($b['verified']): ?><li class="b-verified" title="مدارک و شماره نظام پزشکی توسط مدیر سامانه بررسی شده است"><?= icon('shield') ?>پزشک تأییدشده</li><?php endif; ?>
      <?php foreach ($badges as [$k, $ic, $label]): ?><li class="b-<?= $k ?>"><?= icon($ic) ?><?= esc($label) ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <div class="rc-clinic">
    <p class="rc-clinic-name"><?= icon('building') ?><span><?= esc($primary['name']) ?> <small>• <?= esc($primary['city']) ?></small></span></p>
    <?php if ($cfg['show_address']): ?><p class="rc-address" title="<?= esc($primary['address']) ?>"><?= icon('pin') ?><span><?= esc(($primary['district'] !== '' ? $primary['district'] . '، ' : '') . $primary['address']) ?></span></p><?php endif; ?>
    <?php if ($cfg['show_first']): ?>
      <p class="rc-first<?= $primary['first_at'] ? '' : ' none' ?>"><span>اولین نوبت آزاد این مطب:</span> <b><?= $primary['first_at'] ? esc(free_label($primary['first_at'])) : 'فعلاً نوبت آزادی نیست' ?></b></p>
    <?php endif; ?>
  </div>
  <?php if ($others): ?>
    <details class="rc-more">
      <summary><?= icon('building') ?> <?= fa_number(count($others)) ?> مطب دیگر<?= icon('chevron-down', 'icon chev') ?></summary>
      <ul>
        <?php foreach ($others as $o): ?>
          <li>
            <a href="<?= esc('doctor.php?id=' . (int) $d['id'] . '&clinic=' . (int) $o['id'] . '#booking') ?>">
              <span><strong><?= esc($o['name']) ?></strong><small><?= esc($o['city'] . ($o['district'] !== '' ? '، ' . $o['district'] : '')) ?></small></span>
              <?php if ($cfg['show_first']): ?><em><?= $o['first_at'] ? esc(free_label($o['first_at'])) : 'بدون نوبت آزاد' ?></em><?php endif; ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </details>
  <?php endif; ?>
  <?php if ($extra): ?>
    <details class="rc-extra">
      <summary>اطلاعات تکمیلی<?= icon('chevron-down', 'icon chev') ?></summary>
      <dl>
        <?php if ($cfg['show_code'] && trim((string) $d['medical_code']) !== ''): ?><div><dt>شماره نظام پزشکی</dt><dd><bdi><?= esc(fa_number($d['medical_code'])) ?></bdi></dd></div><?php endif; ?>
        <?php if ($d['experience_years']): ?><div><dt>سابقه</dt><dd><?= fa_number($d['experience_years']) ?> سال</dd></div><?php endif; ?>
      </dl>
    </details>
  <?php endif; ?>
  <div class="rc-actions">
    <a class="button secondary" href="<?= esc($profile) ?>">مشاهده پروفایل</a>
    <a class="button" href="<?= esc($book) ?>"><?= icon('calendar-check') ?> دریافت نوبت</a>
  </div>
</article>
