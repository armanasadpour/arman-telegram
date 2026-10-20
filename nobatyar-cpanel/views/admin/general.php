<?php /** @var array $values */ ?>
<section class="panel narrow-panel">
  <h2>اطلاعات عمومی سامانه</h2>
  <form method="post" class="stack" enctype="multipart/form-data">
    <?php form_token('general_settings'); ?>
    <label>لوگوی سایت<input type="file" name="site_logo" accept="image/png,image/jpeg,image/webp"><small>PNG شفاف پیشنهاد می‌شود؛ حداکثر ۲ مگابایت و ۴۰۰۰ پیکسل. تصویر بدون بریدن در سربرگ و پوستر پزشکان نمایش داده می‌شود.</small></label>
    <?php if(site_logo_url()): ?><img src="<?= esc(site_logo_url()) ?>" alt="لوگوی فعلی" style="max-width:240px;max-height:120px;object-fit:contain"><label class="check"><input type="checkbox" name="remove_logo" value="1">بازگشت به لوگوی پیش‌فرض</label><?php endif; ?>
    <?php input('نام سامانه (در سربرگ و عنوان صفحه‌ها)', 'site_name', $values['site_name'] ?: 'نوبت‌یار', 'text', true, 60); ?>
    <div class="form-grid">
      <?php input('تلفن پشتیبانی', 'support_phone', $values['support_phone'], 'tel', false, 20); ?>
      <?php input('ایمیل پشتیبانی', 'support_email', $values['support_email'], 'email', false, 190); ?>
    </div>
    <?php input('ساعات پاسخ‌گویی (مثلاً شنبه تا چهارشنبه ۹ تا ۱۷)', 'support_hours', $values['support_hours'], 'text', false, 120); ?>
    <label>متن پانویس (اختیاری)<textarea name="footer_text" maxlength="300"><?= esc($values['footer_text']) ?></textarea></label>
    <?php input('سقف تیکت باز هر کاربر', 'ticket_max_open', $values['ticket_max_open'] ?: '3', 'number', true, 2); ?>
    <button>ذخیره</button>
  </form>
  <p class="muted small">تنظیمات کارمزد، اشتراک، پیامک، درگاه و اعلان هر کدام بخش جداگانه دارند.</p>
</section>
