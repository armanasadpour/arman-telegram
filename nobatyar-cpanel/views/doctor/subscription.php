<?php
/** @var array $d @var array $status @var array $plans @var ?array $open @var array $orders @var array $periods @var bool $gatewayReady */
$id = (int) $d['id'];
$kinds = subscription_order_kinds();
$s = $status;
$isPro = doctor_is_pro($id, $d);
$want = (string) ($_GET['want'] ?? '');
$wantLabel = pro_catalog()[$want]['label'] ?? '';
?>
<?php if ($open): $canPay = (int) $open['payable_toman'] === 0 || $gatewayReady || (int) wallet_account('doctor', $id)['balance'] >= (int) $open['payable_toman']; ?>
  <section class="pf" aria-labelledby="pf-title">
    <header class="pf-head">
      <span class="pf-icon"><?= icon('receipt') ?></span>
      <div class="pf-head-text">
        <span class="pf-status"><span class="pf-dot"></span> در انتظار پرداخت</span>
        <h2 id="pf-title">پیش‌فاکتور اشتراک <?= esc($open['plan_name']) ?></h2>
        <p><?= fa_number($open['duration_days']) ?> روز • <?= esc($kinds[$open['kind']] ?? '') ?></p>
      </div>
      <a class="pf-print" href="subscription-invoice.php?id=<?= (int) $open['id'] ?>"><?= icon('print') ?><span>نمایش و چاپ</span></a>
    </header>
    <div class="pf-period"><?= icon('calendar') ?><span>دوره اشتراک</span><bdi><?= esc(jdate($open['period_start'])) ?></bdi><?= icon('arrow-left', 'icon pf-arrow') ?><bdi><?= esc(jdate($open['period_end'])) ?></bdi></div>
    <dl class="pf-lines">
      <div><dt>مبلغ پلن</dt><dd><?= esc(toman($open['base_toman'])) ?></dd></div>
      <?php if ($open['credit_toman'] > 0): ?><div class="minus"><dt>اعتبار پلن قبلی</dt><dd>− <?= esc(toman($open['credit_toman'])) ?></dd></div><?php endif; ?>
      <?php if ($open['discount_toman'] > 0): ?><div class="minus"><dt>تخفیف</dt><dd>− <?= esc(toman($open['discount_toman'])) ?></dd></div><?php endif; ?>
      <?php if ($open['vat_toman'] > 0): ?><div><dt>مالیات بر ارزش افزوده</dt><dd><?= esc(toman($open['vat_toman'])) ?></dd></div><?php endif; ?>
    </dl>
    <div class="pf-total">
      <span>مبلغ قابل پرداخت</span>
      <strong><b><?= fa_number(number_format((int) $open['payable_toman'], 0, '.', '٬')) ?></b> تومان</strong>
    </div>
    <p class="pf-expire"><?= icon('clock') ?> این پیش‌فاکتور تا <bdi><?= esc(jdate($open['expires_at'], true)) ?></bdi> معتبر است.</p>
    <?php if ($canPay): ?>
      <form method="post" id="pf-pay" class="pf-pay-form"><?php form_token('sub_checkout'); ?><input type="hidden" name="order_id" value="<?= (int) $open['id'] ?>"><?php if ((int) $open['payable_toman'] > 0) wallet_toggle('doctor', $id); ?></form>
    <?php else: ?>
      <p class="notice">درگاه پرداخت آنلاین هنوز توسط مدیر فعال نشده است. می‌توانید مبلغ را به حساب اعلام‌شده پشتیبانی واریز کنید تا مدیر با شماره پیگیری، پرداخت را ثبت کند.</p>
    <?php endif; ?>
    <div class="pf-actions">
      <?php if ($canPay): ?><button form="pf-pay" class="pf-pay-btn" data-pay-button><?= (int) $open['payable_toman'] === 0 ? 'فعال‌سازی' : 'پرداخت آنلاین' ?></button><?php endif; ?>
      <form method="post" class="pf-cancel" data-confirm="پیش‌فاکتور لغو شود؟ اگر پرداخت بانکی در جریان باشد و بعداً تأیید شود، مبلغ در فهرست بازگشت وجه ثبت خواهد شد."><?php form_token('sub_cancel'); ?><input type="hidden" name="order_id" value="<?= (int) $open['id'] ?>"><button class="pf-cancel-btn">لغو پیش‌فاکتور</button></form>
    </div>
    <p class="pf-secure"><?= icon('shield') ?> پرداخت امن از درگاه بانکی؛ اشتراک بلافاصله پس از پرداخت فعال می‌شود.</p>
  </section>
<?php endif; ?>

<?php if ($wantLabel !== '' && !$isPro): ?>
  <p class="notice pro-want"><?= icon('lock') ?> «<?= esc($wantLabel) ?>» با اشتراک پرو فعال می‌شود.</p>
<?php endif; ?>
<article class="subscription-card plan-card sub-status <?= $isPro ? 'is-pro' : 'is-free' ?>">
  <span class="sub-status-icon"><?= icon($isPro ? 'verified' : 'user') ?></span>
  <div class="sub-status-text">
    <span class="eyebrow">نسخه فعلی</span>
    <h2><?= $isPro ? icon('verified') . ' اشتراک پرو' . ($s['current'] && $s['current']['plan_name'] ? ' — ' . esc($s['current']['plan_name']) : '') : 'نسخه رایگان' ?></h2>
    <p>
      <?php if ($isPro && !$s['until']): ?>پرو توسط مدیر فعال شده است.<?php elseif ($isPro): ?>اعتبار تا <strong><bdi><?= esc(jdate((string) $s['until'])) ?></bdi></strong><?php if ($s['state'] === 'grace'): ?> • دوره فرجه تا <bdi><?= esc(jdate((string) $s['grace_until'])) ?></bdi>؛ پس از آن امکانات پرو غیرفعال می‌شود ولی پروفایل و نوبت‌دهی رایگان ادامه دارد.<?php endif; ?>
      <?php else: ?>پروفایل شما منتشر است و نوبت آنلاین می‌گیرید.<?php if ($s['state'] === 'expired'): ?> اشتراک پرو قبلی شما تمام شده است؛ اطلاعات، مطب‌ها، خدمت‌ها و منشی‌ها حفظ شده‌اند و با تمدید دوباره فعال می‌شوند.<?php endif; ?>
      <?php endif; ?>
    </p>
  </div>
  <?php if (!$isPro && $plans && !$open): ?><a class="sub-upgrade" href="doctor-panel.php?doctor=<?= $id ?>&amp;tab=subscription#pro-plans"><?= icon('sparkles') ?> ارتقا به پرو</a><?php endif; ?>
  <?php if ($isPro && $s['until']): ?><div class="days-left"><strong><?= fa_number($s['state'] === 'grace' ? $s['grace_left'] : $s['days_left']) ?></strong><span><?= $s['state'] === 'grace' ? 'روز تا پایان فرجه' : 'روز باقی‌مانده' ?></span></div><?php endif; ?>
</article>



<?php if (doctor_has($id, 'daily_summary_sms', $d)): ?>
  <section class="panel">
    <h2><?= icon('sms') ?> پیامک خلاصه پایان روز</h2>
    <form method="post" class="stack">
      <?php form_token('pro_prefs'); ?>
      <label class="check"><input type="checkbox" name="daily_summary" value="1"<?= checked_attr(empty($d['daily_summary_off'])) ?>> هر شب حدود ساعت <?= fa_number(daily_summary_hour()) ?> تعداد نوبت‌های فردا و خلاصه امروز برایم پیامک شود</label>
      <button class="secondary">ذخیره</button>
    </form>
  </section>
<?php endif; ?>

<?php if ($s['trial_available']): ?>
  <section class="panel highlight-panel">
    <h2>دوره آزمایشی رایگان</h2>
    <p><?= fa_number(setting_int('subscription_trial_days', 14, 1, 90)) ?> روز استفاده رایگان، یک بار برای هر پزشک.</p>
    <form method="post"><?php form_token('sub_trial'); ?><button>فعال‌سازی دوره آزمایشی</button></form>
  </section>
<?php endif; ?>


<section class="panel pro-shop" id="pro-plans">
  <div class="pro-hero">
    <span class="pro-hero-tag"><?= icon('sparkles') ?> اشتراک پرو</span>
    <h2>بیمار بیشتر، مدیریت ساده‌تر</h2>
    <p>پزشکان پرو بالاتر در نتایج جستجو دیده می‌شوند، تیک آبی می‌گیرند و با منشی، چک نوبت و گزارش‌ها کار مطب را سریع‌تر پیش می‌برند.</p>
    <ul class="pro-hero-points">
      <li><?= icon('verified') ?> تیک آبی کنار نام</li>
      <li><?= icon('search') ?> بالاتر در جستجو</li>
      <li><?= icon('calendar-check') ?> چک نوبت و منشی</li>
    </ul>
  </div>
  <?php if (!$plans): ?>
    <p class="empty-state">پلن فعالی تعریف نشده است. با مدیر سامانه تماس بگیرید.</p>
  <?php else: ?>
    <form method="post" class="stack">
      <?php form_token('sub_order'); ?>
      <?php
      // Monthly equivalent of each plan; the dearest month is the baseline for «صرفه‌جویی».
      $monthlyOf = fn(array $p) => intdiv((int) $p['price_toman'] * 30, max(1, (int) $p['duration_days']));
      $topMonthly = max(array_map($monthlyOf, $plans)) ?: 1;
      $checkedId = (int) (array_values(array_filter($plans, fn($p) => !empty($p['highlight'])))[0]['id'] ?? $plans[0]['id']);
      ?>
      <div class="plan-grid plan-rail">
        <?php foreach ($plans as $p): $monthly = $monthlyOf($p); $save = (int) round((1 - $monthly / $topMonthly) * 100); $daily = intdiv((int) $p['price_toman'], max(1, (int) $p['duration_days'])); ?>
          <label class="plan-card pc<?= $p['highlight'] ? ' featured' : '' ?>">
            <input type="radio" name="plan_id" value="<?= (int) $p['id'] ?>"<?= checked_attr((int) $p['id'] === $checkedId) ?> required>
            <span class="pc-top">
              <span class="pc-name"><?= esc($p['name']) ?></span>
              <?php if ($save >= 5): ?><span class="pc-save"><?= fa_number($save) ?>٪ صرفه‌جویی</span><?php endif; ?>
            </span>
            <span class="pc-price"><b><?= fa_number(number_format((int) $p['price_toman'], 0, '.', '٬')) ?></b><small>تومان</small></span>
            <span class="pc-period"><?= fa_number($p['duration_days']) ?> روز<?= (int) $p['duration_days'] > 31 ? ' • ماهانه ' . esc(toman($monthly)) : '' ?></span>
            <span class="pc-daily"><?= icon('zap') ?> روزی فقط <?= esc(toman($daily)) ?></span>
            <?php if (plan_features($p) || $p['max_clinics'] || $p['max_secretaries'] || !empty($p['max_services'])): ?>
            <ul>
              <?php foreach (plan_features($p) as $f): ?><li><span class="pc-tick"><?= icon('check') ?></span><?= esc($f) ?></li><?php endforeach; ?>
              <?php if ($p['max_clinics']): ?><li><span class="pc-tick"><?= icon('check') ?></span>حداکثر <?= fa_number($p['max_clinics']) ?> مطب فعال</li><?php endif; ?>
              <?php if ($p['max_secretaries']): ?><li><span class="pc-tick"><?= icon('check') ?></span>حداکثر <?= fa_number($p['max_secretaries']) ?> منشی</li><?php endif; ?>
              <?php if (!empty($p['max_services'])): ?><li><span class="pc-tick"><?= icon('check') ?></span>حداکثر <?= fa_number($p['max_services']) ?> خدمت</li><?php endif; ?>
            </ul>
            <?php endif; ?>
            <span class="pc-pick"><span class="pc-pick-off">این پلن را می‌خواهم</span><span class="pc-pick-on"><?= icon('check') ?> انتخاب شد</span></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="form-grid">
        <label>نوع خرید
          <select name="kind">
            <?php if ($s['state'] === 'active'): ?>
              <option value="renew">تمدید — شروع از پایان اعتبار فعلی، بدون از دست رفتن روزها</option>
              <option value="change_later">تغییر پلن از پایان دوره فعلی</option>
              <?php if (!$s['has_future'] && $s['current'] && $s['current']['order_id']): ?><option value="change_now">تغییر فوری پلن با احتساب اعتبار روزهای باقی‌مانده</option><?php endif; ?>
            <?php else: ?>
              <option value="new">خرید اشتراک — شروع از امروز</option>
            <?php endif; ?>
          </select>
        </label>
        <label>کد تخفیف (اختیاری)<input name="discount_code" maxlength="40" dir="ltr" autocomplete="off"></label>
      </div>
      <button class="pc-buy"><?= icon('zap') ?> فعال‌سازی پرو و صدور پیش‌فاکتور</button>
      <p class="pc-trust"><?= icon('shield') ?> پرداخت امن از درگاه بانکی • فعال‌سازی فوری پس از پرداخت • اطلاعات شما همیشه حفظ می‌شود</p>
    </form>
  <?php endif; ?>
</section>

<div class="grid-2 align-start sub-history">
  <section class="panel">
    <h2><?= icon('receipt') ?> فاکتورها و سفارش‌ها</h2>
    <?php if (!$orders): ?>
      <p class="empty-state">هنوز سفارشی ثبت نشده است.</p>
    <?php else: ?>
      <ul class="order-list">
        <?php foreach ($orders as $o): ?>
          <li>
            <span class="order-ico <?= $o['status'] === 'paid' ? 'paid' : '' ?>"><?= icon($o['status'] === 'paid' ? 'check' : 'receipt') ?></span>
            <span class="order-main"><strong><?= esc($o['plan_name']) ?></strong><small><?php if (!empty($o['number'])): ?><bdi><?= esc($o['number']) ?></bdi> • <?php endif; ?><bdi><?= esc(jdate($o['paid_at'] ?? $o['created_at'], true)) ?></bdi></small></span>
            <span class="order-side"><b><?= esc(toman($o['payable_toman'])) ?></b><span class="badge order-<?= esc($o['status']) ?>"><?= esc(order_status_label($o['status'])) ?></span></span>
            <a class="order-link" href="subscription-invoice.php?id=<?= (int) $o['id'] ?>" aria-label="<?= $o['status'] === 'paid' ? 'فاکتور' : 'پیش‌فاکتور' ?>"><?= icon('chevron-left') ?></a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
  <section class="panel">
    <h2><?= icon('clock') ?> سوابق خرید و تمدید</h2>
    <?php if (!$periods): ?>
      <p class="empty-state">سابقه‌ای ثبت نشده است.</p>
    <?php else: ?>
      <ol class="sub-timeline">
        <?php foreach ($periods as $p): ?>
          <li><strong><?= esc(subscription_kind_label($p['kind'])) ?><?= $p['plan_name'] ? ' — ' . esc($p['plan_name']) : '' ?></strong>
            <small><bdi><?= esc(jdate($p['starts_on'])) ?></bdi> تا <bdi><?= esc(jdate($p['ends_on'])) ?></bdi><?= $p['note'] ? ' • ' . esc($p['note']) : '' ?></small></li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </section>
</div>
