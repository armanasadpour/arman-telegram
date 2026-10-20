<div class="grid-2 align-start">
  <section class="panel">
    <h2>قوانین رزرو بیمار</h2>
    <form method="post" class="stack">
      <?php form_token('booking_config'); ?>
      <?php
      foreach (
          [
              'hold_minutes' => 'مهلت رزرو موقت (۱ تا ۳۰ دقیقه)',
              'max_active_bookings' => 'سقف نوبت فعال هر حساب، شامل خانواده',
              'patient_cancel_hours' => 'مهلت لغو، ساعت پیش از شروع',
              'patient_move_hours' => 'مهلت تغییر زمان، ساعت پیش از شروع',
          ]
          as $key => $label
      ) {
          input($label, $key, setting($key), 'number');
      }
      ?>
      <label class="check"><input type="checkbox" name="patient_cancel_enabled" value="1"<?= checked_attr(setting('patient_cancel_enabled') === '1') ?>> لغو توسط بیمار فعال باشد</label>
      <label class="check"><input type="checkbox" name="patient_move_enabled" value="1"<?= checked_attr(setting('patient_move_enabled') === '1') ?>> تغییر زمان توسط بیمار فعال باشد</label>
      <label>کارمزد نوبت‌های ثبت‌شده توسط منشی
        <select name="secretary_fee_policy">
          <option value="exempt"<?= selected_attr(setting('secretary_fee_policy') === 'exempt') ?>>معاف از کارمزد</option>
          <option value="online"<?= selected_attr(setting('secretary_fee_policy') === 'online') ?>>پرداخت آنلاین کارمزد توسط بیمار</option>
        </select>
      </label>
      <p class="muted">مبلغ طبق قواعد کارمزد (پزشک، شهر یا عمومی) محاسبه می‌شود. در حالت پرداخت آنلاین، نوبت منشی تا پرداخت بیمار موقت می‌ماند. الگوی پیامک تأخیر در بخش «پیامک و ورود» تنظیم می‌شود.</p>
      <button>ذخیره قوانین</button>
    </form>
  </section>
  <section class="panel">
    <h2>صفحه انتخاب زمان و اطلاعات مراجعه‌کننده</h2>
    <form method="post" class="stack">
      <?php form_token('booking_page'); ?>
      <label>تعداد روزهای نوار تاریخ
        <select name="booking_days"><?php foreach ([7, 14, 21, 30] as $n): ?><option value="<?= $n ?>"<?= selected_attr(booking_days() === $n) ?>><?= fa_number($n) ?> روز</option><?php endforeach; ?></select>
      </label>
      <label>کد ملی مراجعه‌کننده
        <select name="patient_id_mode">
          <?php foreach (['required' => 'الزامی (پیش‌فرض)', 'optional' => 'اختیاری'] as $k => $l): ?><option value="<?= $k ?>"<?= selected_attr(patient_id_mode() === $k) ?>><?= $l ?></option><?php endforeach; ?>
        </select>
      </label>
      <p class="muted">کد ملی برای صاحب حساب و هر شخصی که برایش نوبت گرفته می‌شود پرسیده می‌شود. مقدار رمزنگاری‌شده ذخیره می‌شود؛ در صفحه‌ها فقط چهار رقم آخر نمایش داده می‌شود و مقدار کامل فقط در فایل اکسل نوبت‌های روز برای پزشک و منشی همان مطب است. اتباع خارجی شماره گذرنامه یا شناسه اتباع وارد می‌کنند.</p>
      <label class="check"><input type="checkbox" name="booking_profile_fields" value="1"<?= checked_attr(setting('booking_profile_fields', '1') === '1') ?>> تاریخ تولد، جنسیت، نوع بیمه و شهر پیش از اولین رزرو پرسیده شود</label>
      <label>توضیح بالای دکمه پرداخت (خالی = متن پیش‌فرض)
        <textarea name="booking_payment_note" rows="3" maxlength="400" placeholder="<?= esc(booking_payment_note()) ?>"><?= esc(setting('booking_payment_note', '')) ?></textarea>
      </label>
      <p class="muted">مبلغ ویزیت هیچ‌گاه آنلاین دریافت نمی‌شود و فقط برای اطلاع در فاکتور با عنوان «پرداخت در مطب» نمایش داده می‌شود.</p>
      <button>ذخیره تنظیمات صفحه رزرو</button>
    </form>
  </section>
  <section class="panel">
    <h2>کارمزد و پرداخت</h2>
    <p>مبلغ کارمزد رزرو، قواعد شهر و پزشک و سیاست بازگشت کارمزد در بخش «کارمزد رزرو» تنظیم می‌شود. درگاه‌ها و تأیید اتصال آن‌ها در «درگاه‌های پرداخت» و موارد استرداد در «تراکنش‌ها و استرداد» است.</p>
    <p class="actions"><a class="button secondary" href="admin.php?tab=settings">کارمزد رزرو</a> <a class="button secondary" href="admin.php?tab=gateways">درگاه‌ها</a> <a class="button secondary" href="admin.php?tab=payments">تراکنش‌ها</a></p>
  </section>
</div>
