<?php
/**
 * Appointment receipt; right after confirmation (fresh) it opens as the success page.
 * @var array $b @var ?array $tx @var ?array $payment @var string $error @var array $events @var bool $fresh
 */
$expired = $b['status'] === 'held' && $b['expires_at'] <= date('Y-m-d H:i:s');
$statusLabel = $expired ? 'منقضی‌شده' : booking_status_label($b['status']);
$paidFee = (int) $b['fee_toman'];
$discount = (int) ($b['discount_toman'] ?? 0);
$gw = $tx && $tx['gateway']==='wallet' ? 'کیف پول' : ($tx ? (payment_gateways()[$tx['gateway']]['label'] ?? $tx['gateway']) : '');
$ref = $tx['ref_num'] ?? ($payment['reference'] ?? null);
$share = site_name() . ' — نوبت ' . $b['doctor'] . "\n" . jalali_long($b['starts_at']) . "\n" . $b['clinic'] . '، ' . $b['address'] . "\nکد رهگیری: " . $b['tracking_code'];
$photo = !empty($b['photo_file']) ? '<img src="photo.php?id=' . (int) $b['doctor_id'] . '" alt="" width="72" height="72">' : esc(mb_substr(trim(preg_replace('/^دکتر\s+/u', '', $b['doctor'])), 0, 1));
?>
<div class="bk receipt-wrap">
  <?php if ($fresh): ?>
    <section class="success-hero" role="status">
      <span class="success-mark" aria-hidden="true"><svg viewBox="0 0 52 52"><circle cx="26" cy="26" r="24"/><path d="M15 27l7 7 15-16"/></svg></span>
      <h1>نوبت شما با موفقیت ثبت شد</h1>
      <p>رسید در «نوبت‌های من» ذخیره شد<?= sms_enabled() ? ' و پیامک تأیید برای شما ارسال می‌شود' : '' ?>.</p>
    </section>
  <?php endif; ?>
  <?php if ($error): ?><p class="alert alert-error" role="alert"><?= icon('alert') ?><?= esc($error) ?></p><?php endif; ?>

  <article class="receipt" aria-label="رسید نوبت">
    <header class="rc-top">
      <span class="bs-photo"><?= $photo ?></span>
      <span class="bs-id"><strong><?= esc(doctor_display_name($b['doctor'])) ?><?= pro_badge(['id'=>(int)$b['doctor_id']]) ?></strong><small><?= esc(specialty_display_title($b['specialty'] ?? '')) ?></small></span>
      <span class="badge status-<?= esc($expired ? 'cancelled' : $b['status']) ?>"><?= esc($statusLabel) ?></span>
    </header>
    <div class="track">
      <small>کد رهگیری</small>
      <b dir="ltr" data-copy-text="<?= esc($b['tracking_code']) ?>"><?= esc(tracking_label($b['tracking_code'])) ?></b>
      <button type="button" class="link-button" data-copy hidden><?= icon('clipboard') ?> کپی</button>
    </div>
    <dl class="receipt-details">
      <?php if (!empty($b['legacy_tracking_code'])): ?><div><dt>کد رهگیری قبلی</dt><dd><bdi dir="ltr"><?= esc($b['legacy_tracking_code']) ?></bdi></dd></div><?php endif; ?>
      <div><dt>زمان مراجعه</dt><dd><?= esc(jalali_long($b['starts_at'])) ?></dd></div>
      <div><dt>نام مراجعه‌کننده</dt><dd><?= esc($b['patient_name']) ?></dd></div>
      <div><dt>خدمت</dt><dd><?= esc($b['service_name']) ?></dd></div>
      <div><dt>مطب</dt><dd><?= esc($b['clinic']) ?></dd></div>
      <div><dt>نشانی</dt><dd><?= esc(($b['city'] ?? '') !== '' ? $b['city'] . '، ' . $b['address'] : $b['address']) ?></dd></div>
      <?php if ($b['clinic_phone']): ?><div><dt>تلفن مطب</dt><dd><a href="tel:<?= esc($b['clinic_phone']) ?>"><bdi><?= esc(fa_number($b['clinic_phone'])) ?></bdi></a></dd></div><?php endif; ?>
      <?php if (!empty($b['reminder_opt_in'])): ?><div><dt>پیامک یادآوری <?= fa_number($b['reminder_hours_snapshot']) ?> ساعت قبل</dt><dd><?= esc(toman($b['reminder_fee_toman'])) ?></dd></div><?php endif; ?>
      <div><dt>کارمزد رزرو</dt><dd><?= $paidFee > 0 ? esc(toman(max(0, $paidFee - (int)($b['reminder_fee_toman'] ?? 0)))) : 'رایگان' ?><?= $discount > 0 ? ' (پس از ' . esc(toman($discount)) . ' تخفیف)' : '' ?></dd></div>
      <?php if ((int) ($b['vat_toman'] ?? 0) > 0): /* 1.0.0-rc.17 */ ?>
        <div><dt>مالیات بر ارزش افزوده (<?= esc(vat_label((float) $b['vat_percent'])) ?>)</dt><dd><?= esc(toman((int) $b['vat_toman'])) ?></dd></div>
        <div><dt>جمع پرداختی</dt><dd><strong><?= esc(toman($paidFee + (int) $b['vat_toman'])) ?></strong></dd></div>
      <?php endif; ?>
      <?php if ($tx): ?><div><dt>پرداخت</dt><dd><?= esc(payment_status_label($tx['status']) . ' • ' . $gw) ?></dd></div><?php endif; ?>
      <?php if ($ref): ?><div><dt>شماره مرجع پرداخت</dt><dd><bdi><?= esc(fa_number((string) $ref)) ?></bdi></dd></div><?php endif; ?>
      <?php $wr=query('SELECT refunded,penalty FROM wallet_booking_refunds WHERE booking_id=?',[$b['id']])->fetch(); if($wr): ?><div><dt>بازگشت به کیف پول</dt><dd><?= esc(toman($wr['refunded'])) ?></dd></div><div><dt>جریمه لغو</dt><dd><?= esc(toman($wr['penalty'])) ?></dd></div><?php endif; ?>
      <?php if (!$tx && $payment): ?><div><dt>پرداخت</dt><dd><?= esc(['pending' => 'در انتظار بررسی', 'verified' => 'تأییدشده', 'refund_due' => 'نیازمند پیگیری بازگشت وجه', 'refunded' => 'بازگشت وجه ثبت شده'][$payment['status']] ?? 'در حال بررسی') ?></dd></div><?php endif; ?>
    </dl>
    <?= booking_live_line($b) ?>
    <p class="receipt-note"><?= icon('wallet') ?> هزینه ویزیت در مطب دریافت می‌شود. این رسید فقط با وضعیت «قطعی» برای مراجعه معتبر است.</p>
    <?php if ($paidFee > 0 && ($seller = setting('invoice_seller_name')) !== ''): ?>
      <p class="receipt-seller muted small">فروشنده: <?= esc($seller) ?><?= setting('invoice_seller_economic_code') !== '' ? ' • کد اقتصادی ' . esc(fa_number(setting('invoice_seller_economic_code'))) : '' ?><?= setting('invoice_seller_national_id') !== '' ? ' • شناسه ملی ' . esc(fa_number(setting('invoice_seller_national_id'))) : '' ?></p>
    <?php endif; ?>
  </article>

  <div class="receipt-actions no-print">
    <a class="button" href="patient.php"><?= icon('calendar-check') ?> مشاهده نوبت‌های من</a>
    <button type="button" class="button secondary" data-print hidden><?= icon('download') ?> ذخیره رسید (PDF)</button>
    <button type="button" class="button secondary" data-share data-share-text="<?= esc($share) ?>" data-share-title="رسید نوبت" hidden><?= icon('share') ?> اشتراک‌گذاری رسید</button>
    <a class="button ghost" href="index.php"><?= icon('home') ?> بازگشت به صفحه اصلی</a>
  </div>

  <?php if ($b['status'] === 'confirmed'): ?>
    <section class="bk-card no-print">
      <h2>تغییر یا لغو نوبت</h2>
      <ul class="how-list"><?php foreach (booking_cancel_rules() as $r): ?><li><?= esc($r) ?></li><?php endforeach; ?></ul>
      <p class="muted">تغییر زمان تا <?= fa_number(setting('patient_move_hours', '24')) ?> ساعت پیش از نوبت، در مطب‌های همین پزشک و بدون کارمزد دوباره ممکن است.</p>
      <div class="ac-actions">
        <?php if (setting('patient_move_enabled', '1') === '1' && strtotime($b['starts_at']) > time() + (int) setting('patient_move_hours', '24') * 3600): ?>
          <a class="button secondary" href="doctor.php?id=<?= (int) $b['doctor_id'] ?>&amp;move=<?= (int) $b['id'] ?>"><?= icon('calendar-clock') ?> انتخاب زمان جدید</a>
        <?php endif; ?>
      </div>
      <?php if (setting('patient_cancel_enabled', '1') === '1' && strtotime($b['starts_at']) > time() + (int) setting('patient_cancel_hours', '24') * 3600): ?>
        <details class="cancel-box">
          <summary class="danger-link"><?= icon('x') ?> لغو این نوبت</summary>
          <form method="post" data-confirm="این نوبت لغو و ظرفیت آزاد شود؟" class="bk-form">
            <?php form_token('change'); ?>
            <input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="revision" value="<?= (int) $b['revision'] ?>"><input type="hidden" name="operation" value="cancelled">
            <label class="field"><span>علت لغو</span><input name="reason" maxlength="500" required></label>
            <button class="button danger">لغو نوبت</button>
          </form>
        </details>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if ($events): ?>
    <details class="bk-card no-print">
      <summary>تاریخچه نوبت</summary>
      <ul class="timeline"><?php foreach ($events as $e): ?><li><span><?= esc(event_label($e['event_type'])) ?></span><small><?= esc(jdate($e['created_at'], true)) ?></small></li><?php endforeach; ?></ul>
    </details>
  <?php endif; ?>
</div>
<script src="assets/booking.js?v=<?= esc(APP_VERSION) ?>" defer></script>
