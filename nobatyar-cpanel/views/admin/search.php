<?php /** @var array $cfg @var array $cache @var array $genders @var int $districts */ ?>
<form method="post" class="stack">
  <?php form_token('search_settings'); ?>
  <div class="grid-2 align-start">
    <section class="panel">
      <h2>فیلترها</h2>
      <p class="muted small">فیلترهای خاموش در صفحه جستجو نمایش داده نمی‌شوند و مقدارشان در نشانی هم نادیده گرفته می‌شود.</p>
      <?php foreach (search_filter_labels() as $k => $l): ?>
        <label class="check"><input type="checkbox" name="filter[<?= esc($k) ?>]" value="1"<?= checked_attr($cfg['filters'][$k]) ?>> <?= esc($l) ?></label>
      <?php endforeach; ?>
      <p class="muted small">جنسیت برای <?= fa_number((int) $genders['known']) ?> از <?= fa_number((int) $genders['total']) ?> پزشک تأییدشده ثبت شده است (در پروفایل پزشک یا بخش پزشکان). محله برای <?= fa_number($districts) ?> مطب ثبت شده؛ فیلتر محله فقط وقتی دیده می‌شود که در شهر انتخابی محله‌ای ثبت شده باشد.</p>
      <h2>نتایج</h2>
      <div class="form-grid">
        <label>ترتیب پیش‌فرض
          <select name="default_sort">
            <?php foreach (['first' => 'نزدیک‌ترین نوبت آزاد', 'rating' => 'بیشترین امتیاز', 'reviews' => 'بیشترین تعداد نظر', 'featured' => 'پزشکان منتخب', 'newest' => 'جدیدترین پزشکان'] as $k => $l): ?>
              <option value="<?= $k ?>"<?= selected_attr($cfg['default_sort'] === $k) ?>><?= esc($l) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <?php input('تعداد پزشک در هر صفحه (۶ تا ۴۸)', 'per_page', (string) $cfg['per_page'], 'number', true, 2); ?>
        <label>بازه محاسبه اولین نوبت آزاد
          <select name="horizon"><?php foreach ([7, 14, 30, 60, 90] as $n): ?><option value="<?= $n ?>"<?= selected_attr($cfg['horizon'] === $n) ?>><?= fa_number($n) ?> روز آینده</option><?php endforeach; ?></select>
        </label>
        <?php input('اعتبار محاسبه نوبت آزاد (دقیقه، ۱ تا ۶۰)', 'cache_minutes', (string) $cfg['cache_minutes'], 'number', true, 2); ?>
      </div>
      <p class="muted small">«پزشکان منتخب» در <a href="admin.php?tab=home&amp;view=featured">مدیریت صفحه اصلی ← پزشکان منتخب</a> انتخاب می‌شوند.</p>
    </section>
    <section class="panel">
      <h2>کارت پزشک</h2>
      <label class="check"><input type="checkbox" name="show_first" value="1"<?= checked_attr($cfg['show_first']) ?>> نمایش اولین نوبت آزاد هر مطب</label>
      <label class="check"><input type="checkbox" name="show_bio" value="1"<?= checked_attr($cfg['show_bio']) ?>> نمایش عنوان حرفه‌ای (توضیح کوتاه)</label>
      <label class="check"><input type="checkbox" name="show_address" value="1"<?= checked_attr($cfg['show_address']) ?>> نمایش نشانی کوتاه مطب</label>
      <label class="check"><input type="checkbox" name="show_code" value="1"<?= checked_attr($cfg['show_code']) ?>> نمایش شماره نظام پزشکی در «اطلاعات تکمیلی»</label>
      <label class="check"><input type="checkbox" name="show_done" value="1"<?= checked_attr($cfg['show_done']) ?>> نمایش تعداد نوبت‌های انجام‌شده (فقط از داده واقعی؛ صفر نمایش داده نمی‌شود)</label>
      <h3>نشان‌ها</h3>
      <?php foreach (search_badge_labels() as $k => $l): ?>
        <label class="check"><input type="checkbox" name="badge[<?= esc($k) ?>]" value="1"<?= checked_attr($cfg['badges'][$k]) ?>> <?= esc($l) ?></label>
      <?php endforeach; ?>
      <p class="muted small">نشان ویژه هر پزشک در «پزشکان و احراز هویت ← اطلاعات پزشک» نوشته می‌شود. «نوبت امروز/فردا» و «چند مطب» خودکار از برنامه واقعی محاسبه می‌شوند.</p>
      <h3>امتیاز و نظرات</h3>
      <label class="check"><input type="checkbox" name="reviews" value="1"<?= checked_attr($cfg['reviews']) ?>> ثبت و نمایش نظر بیماران (فقط برای نوبت‌های انجام‌شده)</label>
      <label class="check"><input type="checkbox" name="reviews_moderation" value="1"<?= checked_attr($cfg['reviews_moderation']) ?>> نمایش نظر فقط پس از تأیید مدیر</label>
      <?php input('حداقل تعداد نظر برای نمایش امتیاز', 'reviews_min', (string) $cfg['reviews_min'], 'number', true, 2); ?>
      <h3>نقشه</h3>
      <label class="check"><input type="checkbox" name="map" value="1"<?= checked_attr($cfg['map']) ?>> دکمه «نمایش روی نقشه» در نتایج</label>
      <p class="muted small">سرویس کاشی نقشه در <a href="admin.php?tab=cities">شهرها</a> تنظیم می‌شود. فقط مطب‌هایی که موقعیتشان ثبت شده روی نقشه می‌آیند.</p>
    </section>
  </div>
  <div class="sticky-actions"><button><?= icon('check') ?> ذخیره تنظیمات جستجو</button></div>
</form>
<section class="panel">
  <h2>اولین نوبت‌های آزاد</h2>
  <p class="muted">برای سرعت جستجو، اولین نوبت آزاد هر مطب ذخیره و هر <?= fa_number($cfg['cache_minutes']) ?> دقیقه، با هر تغییر نوبت یا برنامه پزشک، و با Cron به‌روز می‌شود. صفحه رزرو همیشه آزاد بودن ساعت را دوباره بررسی می‌کند.</p>
  <p><?= fa_number((int) $cache['n']) ?> مطب محاسبه شده • <?= fa_number((int) $cache['free']) ?> مطب دارای نوبت آزاد</p>
  <form method="post" class="inline-form"><?php form_token('search_cache'); ?><button class="secondary"><?= icon('refresh') ?> محاسبه دوباره همین حالا</button></form>
</section>
