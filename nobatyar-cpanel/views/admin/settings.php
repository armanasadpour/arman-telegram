<?php /** @var array $rules @var array $doctors @var array $provinces @var bool $available */
$subsOn = subscriptions_on();
$vatNow = (float) setting('invoice_vat_percent', '0');
?>
<section class="panel revenue-panel" id="revenue">
  <h2>درآمد و مالیات</h2>
  <form method="post" class="stack">
    <?php form_token('revenue_settings'); ?>
    <p class="muted">تنظیمات فروش و امکانات پرو به <a href="admin.php?tab=subscriptions&amp;view=settings">اشتراک پرو ← تنظیمات</a> منتقل شده است.</p>
    <h3>مالیات بر ارزش افزوده</h3>
    <div class="form-grid">
      <?php input('درصد مالیات بر ارزش افزوده (۰ = بدون مالیات)', 'invoice_vat_percent', fa_number(setting('invoice_vat_percent', '0')), 'text', true, 6); ?>
    </div>
    <p class="muted small">این درصد روی کارمزد رزرو (پس از کسر تخفیف) محاسبه و به مبلغ پرداختی بیمار اضافه می‌شود و در صفحه پرداخت و رسید بیمار به‌صورت جدا نمایش داده می‌شود؛ روی فاکتور اشتراک هم اعمال می‌شود. هزینه ویزیت که در مطب پرداخت می‌شود مشمول آن نیست.<?= $vatNow > 0 ? ' نمونه: کارمزد ۱۰٬۰۰۰ تومان ← ' . esc(toman(10000 + vat_amount(10000))) . ' با مالیات.' : '' ?></p>
    <h3>اطلاعات فروشنده در فاکتور و رسید</h3>
    <div class="form-grid">
      <?php input('نام حقیقی یا حقوقی فروشنده', 'invoice_seller_name', setting('invoice_seller_name'), 'text', false, 190); ?>
      <?php input('شناسه ملی / کد ملی', 'invoice_seller_national_id', setting('invoice_seller_national_id'), 'text', false, 20); ?>
      <?php input('کد اقتصادی', 'invoice_seller_economic_code', setting('invoice_seller_economic_code'), 'text', false, 20); ?>
      <?php input('تلفن', 'invoice_seller_phone', setting('invoice_seller_phone'), 'text', false, 20); ?>
    </div>
    <?php input('نشانی', 'invoice_seller_address', setting('invoice_seller_address'), 'text', false, 500); ?>
    <button>ذخیره درآمد و مالیات</button>
  </form>
</section>
<div class="grid-2 align-start">
  <section class="panel">
    <h2>کارمزد ثابت رزرو بیمار</h2>
    <p>مدل درآمد: <?= $subsOn ? 'اشتراک پزشکان و مراکز و مبلغ ثابت رزرو بیمار' : 'فقط مبلغ ثابت رزرو بیمار (فروش اشتراک خاموش است)' ?><?= $vatNow > 0 ? '؛ مالیات بر ارزش افزوده ' . esc(fa_number(setting('invoice_vat_percent', '0'))) . '٪ جدا به مبلغ بیمار اضافه می‌شود' : '' ?>. هزینه ویزیت در مطب پرداخت می‌شود و هیچ درصدی از آن به سامانه نمی‌رسد.</p>
    <p>مبلغ عمومی: <strong><?= esc(toman(setting('booking_fee', '0'))) ?></strong>
      <?= setting('booking_fee_enabled') === '1' ? '<span class="badge status-confirmed">فعال</span>' : '<span class="badge">غیرفعال — رزرو رایگان</span>' ?></p>
    <?php if (!$available): ?><p class="notice">هیچ درگاه تأییدشده‌ای فعال نیست؛ تا آن زمان فقط رزرو رایگان ممکن است. <a href="admin.php?tab=gateways">تنظیم درگاه</a></p><?php endif; ?>
    <form method="post" class="stack">
      <?php form_token('settings'); ?>
      <?php input('کارمزد عمومی (تومان)', 'fee', fa_number(number_format((int) setting('booking_fee', '0'), 0, '.', '٬')), 'text'); ?>
      <label class="check"><input type="checkbox" name="enabled"<?= checked_attr(setting('booking_fee_enabled') === '1') ?>> دریافت کارمزد فعال باشد</label>
      <label class="check"><input type="checkbox" name="refund_on_cancel" value="1"<?= checked_attr(setting('booking_fee_refund_on_cancel', '1') === '1') ?>> با لغو نوبت پرداخت‌شده (توسط بیمار، پزشک یا منشی) کارمزد برای استرداد ثبت شود</label>
      <p class="muted">برای رزرو رایگان، تیک را بردارید یا مبلغ را صفر کنید. حداقل مبلغ مثبت ۱٬۰۰۰ تومان است. قواعد شهر و پزشک بر مبلغ عمومی مقدم‌اند (پزشک ← شهر ← عمومی).</p>
      <button>ذخیره</button>
    </form>
  </section>
  <section class="panel">
    <h2>کارمزد ویژه شهر یا پزشک</h2>
    <form method="post" class="stack">
      <?php form_token('fee_rule_save'); ?>
      <input type="hidden" name="scope" value="city">
      <div class="form-grid" data-geo>
        <label>استان<select name="province_id" required><option value="">انتخاب کنید</option><?php foreach ($provinces as $p): ?><option value="<?= (int) $p['id'] ?>"><?= esc($p['name']) ?></option><?php endforeach; ?></select></label>
        <label>شهر<select name="city_id" required><option value="">ابتدا استان را انتخاب کنید</option></select></label>
      </div>
      <?php input('کارمزد این شهر (تومان؛ ۰ = رایگان)', 'fee_toman', '', 'text', true, 12); ?>
      <?php input('یادداشت (اختیاری)', 'note', '', 'text', false, 255); ?>
      <label class="check"><input type="checkbox" name="active" value="1" checked> فعال</label>
      <button>ذخیره قاعده شهر</button>
    </form>
    <hr>
    <form method="post" class="stack">
      <?php form_token('fee_rule_save'); ?>
      <input type="hidden" name="scope" value="doctor">
      <?php select_field('پزشک', 'target_id', $doctors); ?>
      <?php input('کارمزد این پزشک (تومان؛ ۰ = رایگان)', 'fee_toman', '', 'text', true, 12); ?>
      <?php input('یادداشت (اختیاری)', 'note', '', 'text', false, 255); ?>
      <label class="check"><input type="checkbox" name="active" value="1" checked> فعال</label>
      <button>ذخیره قاعده پزشک</button>
    </form>
  </section>
</div>
<section class="panel">
  <h2>قواعد ثبت‌شده</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>نوع</th><th>شهر / پزشک</th><th>کارمزد</th><th>وضعیت</th><th>یادداشت</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rules as $r): ?>
        <tr>
          <td><?= $r['scope'] === 'city' ? 'شهر' : 'پزشک' ?></td>
          <td><?= esc($r['target'] ?? '—') ?></td>
          <td><?= (int) $r['fee_toman'] === 0 ? 'رایگان' : esc(toman($r['fee_toman'])) ?></td>
          <td><?= $r['active'] ? '<span class="badge status-confirmed">فعال</span>' : '<span class="badge">غیرفعال</span>' ?></td>
          <td><?= esc($r['note'] ?? '') ?></td>
          <td><form method="post" data-confirm="این قاعده حذف شود؟"><?php form_token('fee_rule_delete'); ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="danger small"><?= icon('trash') ?> حذف</button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (!$rules): ?><p class="empty-state">قاعده ویژه‌ای ثبت نشده؛ برای همه نوبت‌ها مبلغ عمومی اعمال می‌شود.</p><?php endif; ?>
</section>
