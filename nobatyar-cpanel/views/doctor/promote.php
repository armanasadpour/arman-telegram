<?php
/**
 * 1.0.0-rc.20 — booking link, QR code and printable poster (Pro «لینک، QR و پوستر») and the doctor's
 * announcement to patients (Pro «اطلاعیه به بیماران»).
 * @var array $d @var string $link @var bool $qr @var bool $notice
 */
$id = (int) $d['id'];
?>
<div class="two-column promote">
  <section class="card">
    <h2><?= icon('share') ?> لینک و QR نوبت‌دهی</h2>
    <?php if ($qr): ?>
      <p class="muted">این لینک صفحه نوبت‌دهی شماست. QR را چاپ کنید و در مطب نصب کنید یا در شبکه‌های اجتماعی بگذارید.</p>
      <p class="promote-link"><bdi dir="ltr"><?= esc($link) ?></bdi> <button type="button" class="button secondary small" data-copy-link="<?= esc($link) ?>" hidden><?= icon('clipboard') ?> کپی لینک</button></p>
      <div class="promote-qr" data-qr="<?= esc($link) ?>" data-cell="6"></div>
      <p><a class="button" href="doctor-poster.php?doctor=<?= $id ?>" target="_blank" rel="noopener"><?= icon('download') ?> خروجی پوستر QR — عکس و چاپ A۴</a></p>
      <div class="promote-poster print-only">
        <h1>نوبت آنلاین <?= esc($d['name']) ?></h1>
        <p><?= esc($d['specialty_name'] ?? $d['specialty'] ?? '') ?></p>
        <div class="promote-qr" data-qr="<?= esc($link) ?>" data-cell="10"></div>
        <p>با دوربین گوشی QR را اسکن کنید و نوبت بگیرید</p>
        <p dir="ltr"><?= esc($link) ?></p>
      </div>
    <?php else: ?>
      <?= pro_feature_card($id, 'booking_qr', 'لینک کوتاه صفحه شما، QR و پوستر آماده چاپ برای نصب در مطب.') ?>
    <?php endif; ?>
  </section>
  <section class="card">
    <h2><?= icon('megaphone') ?> اطلاعیه به بیماران</h2>
    <?php if ($notice): ?>
      <p class="muted">بالای صفحه شما نمایش داده می‌شود، مثلاً «مطب پنجشنبه تعطیل است». در صورت انتخاب، برای بیماران دارای نوبت قطعی تا تاریخ پایان هم اعلان فرستاده می‌شود.</p>
      <form method="post" class="stack">
        <?php form_token('notice_save'); ?>
        <label>متن اطلاعیه (حداکثر ۵۰۰ نویسه؛ خالی = برداشتن)<textarea name="notice_text" maxlength="500" rows="3"><?= esc((string) ($d['notice_text'] ?? '')) ?></textarea></label>
        <label>نمایش تا تاریخ (اختیاری)<input data-jdate name="notice_until" value="<?= esc(!empty($d['notice_until']) ? jdate($d['notice_until']) : '') ?>" placeholder="۱۴۰۵/۰۷/۲۱" dir="ltr" maxlength="10"></label>
        <label class="check"><input type="checkbox" name="notify_patients" value="1"> برای بیماران دارای نوبت قطعی هم اعلان فرستاده شود</label>
        <button>ذخیره اطلاعیه</button>
      </form>
    <?php else: ?>
      <?= pro_feature_card($id, 'doctor_notice', 'پیام بالای صفحه شما و اعلان به بیماران دارای نوبت، مثلاً برای تعطیلی مطب.') ?>
    <?php endif; ?>
  </section>
</div>
