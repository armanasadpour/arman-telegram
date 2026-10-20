<?php $withPlans = subscriptions_on(); ?>
<div class="dr-wizard">
  <header class="dr-top"><a href="panel.php" aria-label="بازگشت به حساب من">→</a><strong>عضویت پزشکان <span>نوبت‌یار</span></strong><a href="support.php">راهنمایی</a></header>
  <div class="dr-progress" data-progress hidden><div class="dr-track" role="progressbar" aria-label="پیشرفت ثبت‌نام" aria-valuemin="1" aria-valuemax="<?= $withPlans ? 4 : 3 ?>"><span></span></div><p data-step-caption aria-live="polite"></p></div>
  <form method="post" enctype="multipart/form-data" data-doctor-wizard data-server-error="<?= $hasError ? '1' : '0' ?>">
    <?php form_token('register'); ?>
    <section data-step="مشخصات پزشک">
      <span class="dr-eyebrow">شروع یک همراهی حرفه‌ای</span><h1 tabindex="-1">خودتان را معرفی کنید</h1><p class="dr-description">پروفایل شما، اولین آشنایی بیمار با شماست.</p>
      <div class="dr-verified"><?= icon('check') ?> شماره موبایل شما تأیید شده است</div>
      <label class="dr-photo"><span class="dr-photo-preview"><?= icon('user') ?><img data-photo-preview alt="پیش‌نمایش تصویر پزشک" hidden></span><span><b>تصویر پروفایل شما</b><small>اختیاری · JPG یا PNG تا ۳ مگابایت</small><input type="file" name="photo" accept="image/jpeg,image/png"></span></label>
      <?php if ($hasError): ?><p class="muted small">اگر تصویر انتخاب کرده بودید، لطفاً دوباره آن را انتخاب کنید.</p><?php endif; ?>
      <div class="dr-fields"><?php doctor_fields($values, true); ?></div>
    </section>
    <section data-step="مطب و موقعیت">
      <span class="dr-eyebrow">محل مراجعه بیماران</span><h2 tabindex="-1">مطب شما کجاست؟</h2><p class="dr-description">اطلاعات اولین مطب را وارد کنید. مطب‌های دیگر را بعداً در پنل اضافه کنید.</p>
      <?php clinic_fields($values); ?>
    </section>
    <?php if ($withPlans): ?>
    <section data-step="انتخاب نسخه">
      <span class="dr-eyebrow">متناسب با نیاز شما</span><h2 tabindex="-1">با کدام نسخه شروع می‌کنید؟</h2><p class="dr-description">هر دو نسخه امکان نوبت‌دهی آنلاین دارند. انتخابتان را بعداً می‌توانید تغییر دهید.</p>
      <?php render('public/plan-compare', ['choose'=>true, 'selected'=>($values['plan_choice'] ?? 'free') === 'pro' ? 'pro' : 'free']); ?>
      <p class="dr-note">انتخاب پرو در این مرحله پرداختی ندارد؛ فعال‌سازی اشتراک پس از تأیید درخواست، در پنل پزشک انجام می‌شود.</p>
    </section>
    <?php endif; ?>
    <section data-step="بررسی و تأیید">
      <span class="dr-eyebrow">فقط یک قدم تا ثبت درخواست</span><h2 tabindex="-1">همه چیز درست است؟</h2><p class="dr-description">اطلاعات را مرور کنید؛ پس از ثبت، درخواست شما برای بررسی مدیر ارسال می‌شود.</p>
      <div class="dr-review" data-review hidden></div>
      <div class="honeypot" aria-hidden="true"><label>وب‌سایت<input name="website" tabindex="-1" autocomplete="off"></label></div>
      <label class="dr-consent"><input type="checkbox" name="consent" required <?= isset($values['consent']) ? 'checked' : '' ?>><span>صحت اطلاعات را تأیید می‌کنم و با نگهداری آن برای بررسی درخواست توسط مدیر موافقم.</span></label>
      <p class="dr-note"><?= icon('lock') ?> کد نظام پزشکی و موبایل در پروفایل عمومی نمایش داده نمی‌شوند. مدرک پزشکی یا اطلاعات بیمار در این فرم وارد نکنید.</p>
    </section>
    <p class="error" data-wizard-error role="alert" hidden></p>
    <div class="dr-actions"><button type="button" class="secondary" data-prev hidden>مرحله قبل</button><button type="button" data-next hidden>ادامه ←</button><button type="submit" data-submit>ثبت درخواست بررسی</button></div>
  </form>
</div>
