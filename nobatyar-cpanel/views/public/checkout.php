<?php
/**
 * Booking step 3 — confirm and pay the booking fee (or confirm a free booking). Only the booking service
 * is paid online; the visit fee is paid at the clinic and never added to the online total.
 *
 * @var array $b @var string $error @var bool $open @var array $gateways @var ?string $defaultGateway
 * @var bool $legacy @var array $transactions
 */
$fee = (int) $b['fee_toman'];
$discount = (int) ($b['discount_toman'] ?? 0);
// 1.0.0-rc.17: VAT on the booking fee, shown as its own line and added to the online total.
$vatPercent = vat_percent();
$vat = vat_amount($fee, $vatPercent);
$total = $fee + $vat;
$registry = payment_gateways();
$expires = strtotime((string) $b['expires_at']);
$left = max(0, $expires - time());
layout_start('تأیید و پرداخت', ['body_class' => 'booking-page checkout-page', 'robots' => 'noindex,nofollow']);
$summary = ['doctor_id' => $b['doctor_id'], 'doctor' => $b['doctor'], 'photo_file' => $b['photo_file'] ?? null, 'specialty' => $b['specialty'] ?? '', 'clinic' => $b['clinic'], 'address' => $b['address'], 'city' => $b['city'] ?? '', 'starts_at' => $b['starts_at'], 'service' => $b['service_name'], 'patient' => $b['patient_name']];
?>
<?php if ($open): ?>
  <div class="hold-bar" role="status" data-hold-bar data-expires="<?= $expires ?>" data-now="<?= time() ?>">
    <span><?= icon('info') ?> نوبت شما هنوز قطعی نشده است. برای تکمیل رزرو، <?= $fee > 0 ? 'پرداخت را انجام دهید' : 'آن را تأیید کنید' ?>.</span>
    <b class="hold-timer" dir="ltr"><?= icon('timer') ?><span data-hold-left><?= esc(fa_number(sprintf('%02d:%02d', intdiv($left, 60), $left % 60))) ?></span></b>
  </div>
<?php endif; ?>
<div class="bk checkout" data-checkout>
  <?php render('public/booking-steps', ['current' => 3]); ?>
  <?php render('public/booking-summary', ['s' => $summary, 'collapsible' => true]); ?>
  <?php if ($error): ?><p class="alert alert-error" role="alert"><?= icon('alert') ?><?= esc($error) ?></p><?php endif; ?>

  <?php if ($open): ?>
    <form method="post" id="pay-form" class="bk-plain">
      <?php form_token($fee > 0 ? 'pay' : 'confirm'); ?>
      <?php if ($fee > 0): ?>
        <section class="bk-card">
          <h2>روش پرداخت</h2><?php wallet_toggle('user',(int)require_user()['id']); ?>
          <?php if ($gateways === []): ?>
            <p class="alert alert-error"><?= icon('alert') ?>درگاه بانکی فعال نیست؛ در صورت موجودی کافی، با روشن کردن گزینه کیف پول پرداخت کنید.</p>
          <?php else: ?>
            <div class="gw-list" role="radiogroup" aria-label="درگاه پرداخت">
              <?php foreach ($gateways as $i => $g): $logo = gateway_logo_url($g); ?>
                <label class="gw-card">
                  <input type="radio" name="gateway" value="<?= esc($g) ?>"<?= checked_attr($g === $defaultGateway || ($i === 0 && !in_array($defaultGateway, $gateways, true))) ?>>
                  <span class="gw-radio" aria-hidden="true"></span>
                  <span class="gw-logo"><?php if ($logo): ?><img src="<?= esc($logo) ?>" alt="" width="40" height="40"><?php else: ?><?= icon('card') ?><?php endif; ?></span>
                  <span class="gw-text"><strong><?= esc($registry[$g]['label']) ?></strong><small>پرداخت با همه کارت‌های عضو شتاب</small></span>
                </label>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    </form>

    <?php if ($fee + $discount > 0 && !$b['discount_code_id']): ?>
      <details class="bk-card discount-box"<?= $error && str_contains($error, 'کد') ? ' open' : '' ?>>
        <summary><span>کد تخفیف دارید؟</span><span class="link-like"><?= icon('plus') ?> افزودن کد</span></summary>
        <form method="post" class="discount-form">
          <?php form_token('discount'); ?>
          <label class="sr-only" for="dc">کد تخفیف</label>
          <input id="dc" name="code" maxlength="40" autocomplete="off" dir="ltr" placeholder="کد تخفیف" required>
          <button class="button secondary">اعمال</button>
        </form>
        <p class="field-help">کد تخفیف فقط روی کارمزد رزرو اعمال می‌شود.</p>
      </details>
    <?php elseif ($discount > 0): ?>
      <p class="alert alert-ok"><?= icon('check') ?>کد تخفیف اعمال شد: <?= esc(toman($discount)) ?> کمتر می‌پردازید.</p>
    <?php endif; ?>

    <section class="bk-card bill-card" aria-labelledby="inv-title">
      <h2 id="inv-title">جزئیات پرداخت</h2>
      <dl>
        <div><dt>هزینه ویزیت پزشک</dt><dd class="muted-dd"><?= !empty($b['visit_fee_toman']) ? esc(toman((int) $b['visit_fee_toman'])) . ' — ' : '' ?>پرداخت در مطب</dd></div>
        <?php if (!empty($b['reminder_opt_in'])): ?><div><dt>پیامک یادآوری <?= fa_number($b['reminder_hours_snapshot']) ?> ساعت قبل</dt><dd><?= esc(toman($b['reminder_fee_toman'])) ?></dd></div><?php endif; ?>
        <div><dt>کارمزد خدمات رزرو</dt><dd><?= $fee + $discount > 0 ? esc(toman(max(0, $fee + $discount - (int)($b['reminder_fee_toman'] ?? 0)))) : 'رایگان' ?></dd></div>
        <?php if ($discount > 0): ?><div class="inv-discount"><dt>تخفیف</dt><dd>− <?= esc(toman($discount)) ?></dd></div><?php endif; ?>
        <?php if ($vat > 0): ?><div class="inv-vat"><dt>مالیات بر ارزش افزوده (<?= esc(vat_label($vatPercent)) ?>)</dt><dd>+ <?= esc(toman($vat)) ?></dd></div><?php endif; ?>
        <div class="inv-total"><dt>مبلغ قابل پرداخت آنلاین</dt><dd><?= $total > 0 ? esc(toman($total)) : 'رایگان' ?></dd></div>
      </dl>
    </section>

    <section class="bk-card">
      <h2>ملاحظات پرداخت</h2>
      <p class="dp-text"><?= esc(booking_payment_note()) ?></p>
      <details class="rules">
        <summary><?= icon('info') ?> قوانین لغو نوبت و بازگشت کارمزد<?= icon('chevron-down', 'icon chev') ?></summary>
        <ul class="how-list"><?php foreach (booking_cancel_rules() as $r): ?><li><?= esc($r) ?></li><?php endforeach; ?></ul>
      </details>
      <?php $pages = published_pages(); ?>
      <p class="field-help"><?= $fee > 0 ? 'پرداخت' : 'تأیید' ?> به منزله پذیرش <?= isset($pages['terms']) ? '<a href="' . esc(page_url('terms')) . '" target="_blank" rel="noopener">قوانین و مقررات</a>' : 'قوانین' ?> <?= esc(site_name()) ?> است.</p>
    </section>

    <form method="post" class="release-form">
      <?php form_token('release'); ?>
      <input type="hidden" name="revision" value="<?= (int) $b['revision'] ?>">
      <button class="link-button danger-link"><?= icon('x') ?> انصراف و آزادسازی این زمان</button>
    </form>

    <div class="pay-bar" data-pay-bar>
      <span><small>مبلغ قابل پرداخت<?= $vat > 0 ? ' (با مالیات)' : '' ?></small><b><?= $total > 0 ? esc(toman($total)) : 'رایگان' ?></b></span>
      <?php if ($fee > 0): ?>
        <button class="button" form="pay-form" data-pay-button><?= icon('card') ?> پرداخت و ثبت نوبت</button>
      <?php else: ?>
        <button class="button" form="pay-form"><?= icon('check') ?> ثبت رایگان نوبت</button>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <section class="bk-card alert-card">
      <span class="ac-icon warn"><?= icon('clock') ?></span>
      <h2><?= $b['status'] === 'held' ? 'مهلت این رزرو تمام شده است' : 'وضعیت نوبت: ' . esc(booking_status_label($b['status'])) ?></h2>
      <p><?= $b['status'] === 'held' ? 'زمان نگه‌داشته‌شده آزاد شد و دیگر قابل پرداخت نیست. اگر هنوز آزاد باشد، می‌توانید دوباره همان زمان یا زمان دیگری را انتخاب کنید.' : 'جزئیات این نوبت در رسید آن است.' ?></p>
      <div class="ac-actions">
        <?php if ($b['status'] === 'held'): ?><a class="button" href="<?= esc('doctor.php?id=' . (int) $b['doctor_id'] . '&clinic=' . (int) $b['clinic_id'] . '&times=1') ?>"><?= icon('calendar') ?> انتخاب دوباره زمان</a><?php endif; ?>
        <a class="button secondary" href="receipt.php?id=<?= (int) $b['id'] ?>">مشاهده جزئیات</a>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($transactions): ?>
    <details class="bk-card">
      <summary>تلاش‌های پرداخت (<?= fa_number(count($transactions)) ?>)</summary>
      <ul class="tx-list">
        <?php foreach ($transactions as $t): ?>
          <li><span class="badge <?= esc($t['status']) ?>"><?= esc(payment_status_label($t['status'])) ?></span>
            <?= esc($registry[$t['gateway']]['label'] ?? $t['gateway']) ?> • <?= esc(toman($t['amount_toman'])) ?> • <?= esc(jdate($t['created_at'], true)) ?>
            <?php if ($t['ref_num']): ?> • مرجع <bdi><?= esc(fa_number($t['ref_num'])) ?></bdi><?php endif; ?></li>
        <?php endforeach; ?>
      </ul>
    </details>
  <?php endif; ?>

  <?php if ($legacy): ?>
    <form method="post"><?php form_token('verify'); ?><button class="button secondary block">بررسی مجدد نتیجه پرداخت قبلی</button></form>
  <?php endif; ?>
</div>
<script src="assets/booking.js?v=<?= esc(APP_VERSION) ?>" defer></script>
<?php
layout_end();
