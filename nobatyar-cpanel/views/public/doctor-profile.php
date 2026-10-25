<?php
/**
 * Doctor profile. Every figure is real: rating only from approved reviews (above the minimum), completed
 * appointments from bookings, first free time from the booking engine.
 *
 * @var array $d @var array $clinics @var array $cl @var int $selected @var array $services @var int $fee
 * @var array $free first free time per clinic @var array $reviews @var array $dist @var string $rsort
 * @var array $related @var int $done @var array $hours @var array $cfg
 */
$id = (int) $d['id'];
$timesUrl = fn(int $clinic) => 'doctor.php?id=' . $id . '&clinic=' . $clinic . '&times=1';
$firstAt = $free[$selected] ?? null;
$summary = $reviews['summary'] ?? null;
$pageUrl = app_base_url() . doctor_url($id, $d['name']);
$visitFees = array_filter(array_map(fn($s) => $s['visit_fee_toman'] ?? null, $services));
$tabs = ['about' => 'درباره پزشک', 'services' => 'خدمات', 'clinics' => 'مطب‌ها', 'reviews' => 'نظرات', 'faq' => 'سوالات'];
if (trim((string) $d['education']) !== '' || $d['experience_years']) {
    $tabs = ['about' => 'درباره پزشک', 'education' => 'سوابق'] + array_slice($tabs, 1, null, true);
}
if (!$cfg['reviews']) {
    unset($tabs['reviews']);
}
?>
<div class="dp" data-doctor-page>
  <a class="back-results" href="index.php?view=doctors" data-back-results hidden><?= icon('chevron-right') ?> بازگشت به نتایج جستجو</a>

  <?php if (($notice = doctor_notice($d)) !== ''): /* rc.20 doctor announcement (Pro) */ ?>
    <p class="dp-notice" role="note"><?= icon('megaphone') ?><span><strong>اطلاعیه:</strong> <?= esc($notice) ?></span></p>
  <?php endif; ?>
  <section class="dp-hero" aria-labelledby="dp-name">
    <div class="dp-photo-shell"><?= doctor_avatar_markup($d) ?></div>
    <div class="dp-id">
      <h1 id="dp-name"><?= esc(doctor_display_name($d['name'])) ?><?= pro_badge($d) ?></h1>
      <p class="dp-spec"><?= esc(specialty_display_title($d['specialty_name'])) ?><?php if (trim((string) $d['professional_title']) !== ''): ?> <span>• <?= esc($d['professional_title']) ?></span><?php endif; ?></p>
      <ul class="dp-meta">
        <li class="ok"><?= icon('shield') ?>پزشک تأییدشده</li>
        <?php if (trim((string) $d['medical_code']) !== ''): ?><li><?= icon('id-card') ?>نظام پزشکی <bdi><?= esc(fa_number($d['medical_code'])) ?></bdi></li><?php endif; ?>
        <?php if ($d['experience_years']): ?><li><?= icon('badge') ?><?= fa_number($d['experience_years']) ?> سال سابقه</li><?php endif; ?>
      </ul>
      <?php $reviewCount = (int) ($reviews['count'] ?? 0); ?>
      <div class="dp-stats">
        <?php if ($cfg['reviews']): ?>
          <a href="#reviews" class="dp-stat dp-stat-rate"><span class="dp-stat-icon"><?= icon('star') ?></span><b><?= $summary ? esc(fa_number(number_format($summary['avg'], 1))) : '—' ?></b><small><?= $summary ? 'امتیاز • ' . fa_number($summary['recommend']) . '٪ پیشنهاد' : 'امتیاز' ?></small></a>
          <a href="#reviews" class="dp-stat dp-stat-reviews"><span class="dp-stat-icon"><?= icon('chat') ?></span><b><?= fa_number($reviewCount) ?></b><small>نظر</small></a>
        <?php endif; ?>
        <?php if ($cfg['show_done']): ?>
          <span class="dp-stat dp-stat-done"><span class="dp-stat-icon"><?= icon('calendar-check') ?></span><b><?= fa_number($done) ?></b><small>نوبت موفق</small></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="dp-hero-actions">
      <a class="dp-cta" href="<?= esc($timesUrl($selected)) ?>">
        <span class="dp-cta-icon" aria-hidden="true"><?= icon('calendar-check') ?></span>
        <span class="dp-cta-text"><strong>دریافت نوبت</strong><small><?= $firstAt ? 'اولین نوبت آزاد: ' . esc(free_label($firstAt)) : 'مشاهده روزها و ساعت‌های آزاد' ?></small></span>
        <span class="dp-cta-arrow" aria-hidden="true"><?= icon('chevron-left') ?></span>
      </a>
      <button type="button" class="button ghost dp-share" data-share data-share-url="<?= esc($pageUrl) ?>" data-share-title="<?= esc(doctor_display_name($d['name']) . ' — ' . $d['specialty_name']) ?>" hidden><?= icon('share') ?> اشتراک‌گذاری</button>
      <?php $me = user_now_safe(); $saved = $me && favorite_saved((int) $me['id'], $id); /* 1.0.30 saved doctors */ ?>
      <?php if ($me): ?>
        <button type="button" class="button ghost dp-save<?= $saved ? ' is-saved' : '' ?>" data-favorite="<?= $id ?>" aria-pressed="<?= $saved ? 'true' : 'false' ?>"><?= icon('heart') ?><span data-favorite-label><?= $saved ? 'ذخیره شده' : 'ذخیره پزشک' ?></span></button>
      <?php else: ?>
        <a class="button ghost dp-save" href="login.php?next=<?= esc(rawurlencode('doctor.php?id=' . $id)) ?>"><?= icon('heart') ?><span>ذخیره پزشک</span></a>
      <?php endif; ?>
    </div>
  </section>

  <nav class="dp-tabs" aria-label="بخش‌های پروفایل" data-dp-tabs>
    <?php foreach ($tabs as $k => $l): ?><a href="#<?= $k ?>"><?= esc($l) ?></a><?php endforeach; ?>
  </nav>

  <div class="dp-layout">
    <div class="dp-main">
      <section class="dp-card" id="about">
        <h2>درباره پزشک</h2>
        <?php if (trim((string) $d['bio']) !== ''): ?><div class="dp-text"><?= nl2br(esc($d['bio'])) ?></div><?php else: ?><p class="dp-empty">پزشک هنوز معرفی خود را ننوشته است.</p><?php endif; ?>
      </section>

      <?php if (isset($tabs['education'])): ?>
        <section class="dp-card" id="education">
          <h2>سوابق تحصیلی و حرفه‌ای</h2>
          <?php if (trim((string) $d['education']) !== ''): ?><div class="dp-text"><?= nl2br(esc($d['education'])) ?></div><?php endif; ?>
          <?php if ($d['experience_years']): ?><p class="dp-line"><?= icon('badge') ?> <?= fa_number($d['experience_years']) ?> سال سابقه فعالیت</p><?php endif; ?>
        </section>
      <?php endif; ?>

      <section class="dp-card" id="services">
        <h2>خدمات قابل رزرو</h2>
        <?php if ($services): ?>
          <ul class="svc-list">
            <?php foreach ($services as $s): ?>
              <li>
                <span class="svc-name"><?= icon('sp-stethoscope') ?><?= esc($s['name']) ?></span>
                <span class="svc-meta"><?= $s['duration_minutes'] ? fa_number($s['duration_minutes']) . ' دقیقه' : '' ?><?= !empty($s['visit_fee_toman']) ? ' • ویزیت ' . esc(toman((int) $s['visit_fee_toman'])) . ' (در مطب)' : '' ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
          <p class="dp-note"><?= icon('wallet') ?> هزینه ویزیت فقط در مطب پرداخت می‌شود<?= $visitFees ? '؛ مبالغ بالا را پزشک اعلام کرده است' : '' ?>.</p>
        <?php else: ?>
          <p class="dp-empty">برنامه خدمات این پزشک هنوز منتشر نشده است.</p>
        <?php endif; ?>
      </section>

      <section class="dp-card" id="clinics">
        <h2>مطب‌ها و محل فعالیت</h2>
        <div class="clinic-picks">
          <?php foreach ($clinics as $c): $on = (int) $c['id'] === $selected; ?>
            <a class="clinic-pick<?= $on ? ' is-selected' : '' ?>" href="<?= esc('doctor.php?id=' . $id . '&clinic=' . (int) $c['id'] . '#clinics') ?>"<?= $on ? ' aria-current="true"' : '' ?>>
              <span class="cp-radio" aria-hidden="true"></span>
              <span class="cp-body">
                <strong><?= esc($c['name']) ?></strong>
                <small><?= esc($c['province'] . '، ' . $c['city'] . ($c['district'] !== '' ? '، ' . $c['district'] : '')) ?></small>
                <small class="cp-address"><?= esc($c['address']) ?></small>
                <em class="<?= !empty($free[$c['id']]) ? 'free' : 'none' ?>"><?= !empty($free[$c['id']]) ? 'اولین نوبت: ' . esc(free_label($free[$c['id']])) : 'فعلاً نوبت آزاد ندارد' ?></em>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
        <div class="clinic-detail">
          <?php if ($cl['latitude'] !== null && $cl['longitude'] !== null): $coords = esc($cl['latitude'] . ',' . $cl['longitude']); ?>
            <div class="map-view" data-map-view data-lat="<?= esc($cl['latitude']) ?>" data-lng="<?= esc($cl['longitude']) ?>" data-tiles="<?= esc(setting('map_tile_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png')) ?>" data-attribution="<?= esc(setting('map_attribution', '© OpenStreetMap')) ?>"></div>
            <p class="map-links">
              <a class="button ghost small" href="https://www.google.com/maps/search/?api=1&amp;query=<?= $coords ?>" target="_blank" rel="noopener"><?= icon('map') ?> مسیریابی با گوگل‌مپ</a>
              <a class="button ghost small" href="geo:<?= $coords ?>"><?= icon('pin') ?> برنامه نقشه گوشی</a>
              <a class="button ghost small" href="https://www.openstreetmap.org/?mlat=<?= esc($cl['latitude']) ?>&amp;mlon=<?= esc($cl['longitude']) ?>#map=17/<?= esc($cl['latitude']) ?>/<?= esc($cl['longitude']) ?>" target="_blank" rel="noopener">OpenStreetMap</a>
            </p>
          <?php endif; ?>
          <?php if ($cl['phone']): ?><p class="dp-line"><?= icon('phone') ?> <a href="tel:<?= esc($cl['phone']) ?>">تماس با مطب: <bdi><?= esc(fa_number($cl['phone'])) ?></bdi></a></p><?php endif; ?>
        </div>
      </section>

      <?php if ($cfg['reviews']): ?>
        <section class="dp-card" id="reviews">
          <h2>نظر بیماران</h2>
          <?php if ($summary): $max = max(1, max($dist)); ?>
            <div class="rv-summary">
              <div class="rv-score"><b><?= esc(fa_number(number_format($summary['avg'], 1))) ?></b><span class="stars" aria-hidden="true"><?= str_repeat('★', (int) round($summary['avg'])) . str_repeat('☆', 5 - (int) round($summary['avg'])) ?></span><small><?= fa_number($summary['count']) ?> نظر تأییدشده</small></div>
              <ul class="rv-bars">
                <?php foreach ($dist as $star => $n): ?>
                  <li><span><?= fa_number($star) ?> ★</span><span class="bar"><i style="width:<?= (int) round($n / $max * 100) ?>%"></i></span><small><?= fa_number($n) ?></small></li>
                <?php endforeach; ?>
              </ul>
              <p class="rv-rec"><?= icon('heart-handshake') ?> <?= fa_number($summary['recommend']) ?>٪ از بیماران این پزشک را پیشنهاد کرده‌اند.</p>
            </div>
            <?php if ($reviews['items']): ?>
              <nav class="rv-sort" aria-label="مرتب‌سازی نظرها">
                <?php foreach (['new' => 'جدیدترین', 'high' => 'بیشترین امتیاز', 'low' => 'کمترین امتیاز'] as $k => $l): ?>
                  <a href="<?= esc('doctor.php?id=' . $id . '&clinic=' . $selected . '&rsort=' . $k . '#reviews') ?>"<?= $rsort === $k ? ' class="current" aria-current="true"' : '' ?>><?= esc($l) ?></a>
                <?php endforeach; ?>
              </nav>
              <ul class="review-list">
                <?php foreach ($reviews['items'] as $it): ?>
                  <li>
                    <div class="rv-head"><span class="rv-avatar" aria-hidden="true"><?= esc(mb_substr($it['first_name'] !== '' ? $it['first_name'] : 'ب', 0, 1)) ?></span><strong><?= esc($it['first_name'] !== '' ? $it['first_name'] : 'بیمار') ?></strong><span class="stars" aria-label="<?= fa_number($it['rating']) ?> از ۵"><?= str_repeat('★', (int) $it['rating']) . str_repeat('☆', 5 - (int) $it['rating']) ?></span><small><?= esc(jdate($it['created_at'])) ?></small></div>
                    <p><?= esc($it['comment']) ?></p>
                    <?php if ($it['recommend']): ?><small class="rv-yes"><?= icon('check') ?> این پزشک را پیشنهاد می‌کند</small><?php endif; ?>
                    <?php if (!empty($it['reply'])): /* 1.0.30 */ ?><div class="rv-reply"><strong><?= icon('chat') ?> پاسخ <?= esc(doctor_display_name($d['name'])) ?></strong><p><?= esc($it['reply']) ?></p></div><?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          <?php else: ?>
            <div class="rv-empty">
              <span><?= icon('star') ?></span>
              <strong>هنوز امتیازی ثبت نشده است</strong>
              <p>فقط بیمارانی که نوبتشان در مطب انجام شده می‌توانند نظر بدهند و نظرها پس از بررسی منتشر می‌شوند.</p>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <section class="dp-card" id="faq">
        <h2>سوالات و اطلاعات تکمیلی</h2>
        <div class="dp-faq">
          <details open>
            <summary><?= icon('pin') ?>آدرس مطب<?= icon('chevron-down', 'icon chev') ?></summary>
            <div><p><?= esc($cl['name'] . ' — ' . $cl['province'] . '، ' . $cl['city'] . '، ' . ($cl['district'] !== '' ? $cl['district'] . '، ' : '') . $cl['address']) ?></p></div>
          </details>
          <details>
            <summary><?= icon('clock') ?>ساعت کاری<?= icon('chevron-down', 'icon chev') ?></summary>
            <div>
              <?php if ($hours): ?>
                <ul class="hours-list"><?php foreach ($hours as $h): ?><li><span><?= esc($h['day']) ?></span><bdi><?= esc(implode('، ', $h['ranges'])) ?></bdi></li><?php endforeach; ?></ul>
              <?php else: ?><p>ساعت کاری این مطب هنوز ثبت نشده است.</p><?php endif; ?>
            </div>
          </details>
          <details>
            <summary><?= icon('calendar-check') ?>نحوه دریافت نوبت<?= icon('chevron-down', 'icon chev') ?></summary>
            <div><ol class="how-list"><li>«مشاهده نوبت‌های آزاد» را بزنید و روز و ساعت را انتخاب کنید.</li><li>با شماره موبایل وارد شوید و مراجعه‌کننده (خودتان یا عضو خانواده) را انتخاب کنید.</li><li><?= $fee > 0 ? 'کارمزد رزرو (' . esc(toman($fee)) . ') را آنلاین بپردازید' : 'رزرو را بدون پرداخت تأیید کنید' ?>؛ کد رهگیری نمایش داده و پیامک می‌شود.</li></ol></div>
          </details>
          <?php if (trim((string) ($cl['required_docs'] ?? '')) !== ''): ?>
            <details>
              <summary><?= icon('file') ?>مدارک موردنیاز<?= icon('chevron-down', 'icon chev') ?></summary>
              <div class="dp-text"><?= nl2br(esc($cl['required_docs'])) ?></div>
            </details>
          <?php endif; ?>
          <details>
            <summary><?= icon('alert') ?>قوانین لغو نوبت<?= icon('chevron-down', 'icon chev') ?></summary>
            <div><ul class="how-list"><?php foreach (booking_cancel_rules() as $r): ?><li><?= esc($r) ?></li><?php endforeach; ?></ul></div>
          </details>
          <details>
            <summary><?= icon('info') ?>اطلاعات تکمیلی پزشک<?= icon('chevron-down', 'icon chev') ?></summary>
            <div><ul class="how-list"><li>تخصص: <?= esc(specialty_display_title($d['specialty_name'])) ?></li><?php if (trim((string) $d['medical_code']) !== ''): ?><li>شماره نظام پزشکی: <bdi><?= esc(fa_number($d['medical_code'])) ?></bdi></li><?php endif; ?><li>هزینه ویزیت در مطب پرداخت می‌شود و <?= esc(site_name()) ?> از آن سهمی ندارد.</li></ul></div>
          </details>
        </div>
      </section>

      <?php if ($related): ?>
        <section class="dp-related" aria-labelledby="rel-title">
          <h2 id="rel-title">پزشکان مرتبط در <?= esc($cl['city']) ?></h2>
          <div class="result-list"><?php foreach ($related as $r): ?><?php render('public/result-card', ['d' => $r, 'cfg' => $cfg]); ?><?php endforeach; ?></div>
        </section>
      <?php endif; ?>
    </div>

    <aside class="dp-aside">
      <section class="book-card" aria-labelledby="book-title">
        <h2 id="book-title"><?= icon('calendar-check') ?> دریافت نوبت حضوری</h2>
        <p class="bc-clinic"><?= icon('building') ?> <?= esc($cl['name']) ?> <small>• <?= esc($cl['city']) ?></small></p>
        <?php if ($firstAt): ?>
          <div class="bc-first"><span>اولین نوبت آزاد</span><strong><?= esc(free_label($firstAt)) ?></strong><small><?= esc(jalali_long(substr($firstAt, 0, 10))) ?></small></div>
          <p class="bc-state ok"><?= icon('check') ?> ظرفیت آزاد دارد</p>
        <?php else: ?>
          <div class="bc-first none"><span>اولین نوبت آزاد</span><strong>در <?= fa_number($cfg['horizon']) ?> روز آینده نوبت آزاد نیست</strong></div>
          <p class="bc-state">می‌توانید در صفحه نوبت‌ها در صف انتظار ثبت شوید.</p>
        <?php endif; ?>
        <p class="bc-fee"><?= icon('wallet') ?> <?= $fee > 0 ? 'کارمزد رزرو: <b>' . esc(toman($fee)) . '</b>' : 'رزرو <b>رایگان</b>' ?> <small>• ویزیت در مطب</small></p>
        <a class="button block" href="<?= esc($timesUrl($selected)) ?>"><?= icon('calendar') ?> مشاهده نوبت‌های آزاد</a>
      </section>
    </aside>
  </div>
</div>
<div class="book-bar" data-book-bar>
  <span><small>اولین نوبت آزاد</small><b><?= $firstAt ? esc(free_label($firstAt)) : 'به‌زودی' ?></b></span>
  <a class="button" href="<?= esc($timesUrl($selected)) ?>"><?= icon('calendar-check') ?> دریافت نوبت</a>
</div>
<script src="assets/booking.js?v=<?= esc(APP_VERSION) ?>" defer></script>
