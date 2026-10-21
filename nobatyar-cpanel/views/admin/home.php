<?php
/** @var string $view @var array $views */
$sub = fn($k) => 'admin.php?tab=home&amp;view=' . $k;
?>
<nav class="sub-tabs" aria-label="بخش‌های مدیریت صفحه اصلی">
  <?php foreach ($views as $k => $l): ?><a href="<?= $sub($k) ?>"<?= $view === $k ? ' class="current" aria-current="page"' : '' ?>><?= esc($l) ?></a><?php endforeach; ?>
</nav>

<?php if ($view === 'layout'): /** @var array $layout @var array $catalog @var bool $hasDraft @var array $specialties */
$S = $layout['sections'];
$img = fn($f, $fallback) => $f !== '' ? home_image_url($f) : $fallback;
?>
<section class="panel publish-bar<?= $hasDraft ? ' has-draft' : '' ?>">
  <div>
    <strong><?= $hasDraft ? 'پیش‌نویس منتشرنشده دارید' : 'نسخه منتشرشده و پیش‌نویس یکسان‌اند' ?></strong>
    <p class="muted small">تغییرات چیدمان، متن‌ها و تصاویر ابتدا در پیش‌نویس ذخیره می‌شوند. پیش‌نمایش را ببینید و سپس منتشر کنید. پزشکان منتخب، تخصص‌ها، سوالات و مقالات بلافاصله اعمال می‌شوند.</p>
  </div>
  <div class="row-actions">
    <a class="button secondary" href="index.php?preview=1" target="_blank" rel="noopener"><?= icon('eye') ?> پیش‌نمایش</a>
    <?php if ($hasDraft): ?>
      <form method="post" class="inline-form"><?php form_token('home_publish'); ?><button><?= icon('check') ?> انتشار</button></form>
      <form method="post" class="inline-form" data-confirm="پیش‌نویس کنار گذاشته شود؟"><?php form_token('home_discard'); ?><button class="danger">کنار گذاشتن پیش‌نویس</button></form>
    <?php endif; ?>
  </div>
</section>

<form method="post" enctype="multipart/form-data" class="home-editor">
  <?php form_token('home_draft'); ?>
  <section class="panel">
    <div class="panel-head"><h2>ترتیب و نمایش بخش‌ها</h2><span class="muted small">بکشید و رها کنید، یا با دکمه‌های بالا و پایین جابه‌جا کنید.</span></div>
    <ol class="sort-list" data-sortable>
      <?php foreach ($layout['order'] as $key): $meta = $catalog[$key]; $s = $S[$key]; ?>
        <li class="sort-item" data-sort-item>
          <input type="hidden" name="order[]" value="<?= esc($key) ?>">
          <span class="grip" aria-hidden="true"><?= icon('grip') ?></span>
          <div class="sort-main">
            <label class="check"><input type="checkbox" name="s[<?= esc($key) ?>][enabled]" value="1"<?= checked_attr(!empty($s['enabled'])) ?>> <strong><?= esc($meta['label']) ?></strong></label>
            <?php if ($key !== 'services' && $key !== 'banners'): ?>
              <div class="form-grid">
                <label>عنوان<input name="s[<?= esc($key) ?>][title]" value="<?= esc($s['title']) ?>" maxlength="120"></label>
                <label>توضیح<input name="s[<?= esc($key) ?>][subtitle]" value="<?= esc($s['subtitle']) ?>" maxlength="300"></label>
              </div>
            <?php else: ?>
              <input type="hidden" name="s[<?= esc($key) ?>][title]" value=""><input type="hidden" name="s[<?= esc($key) ?>][subtitle]" value="">
              <?php if ($key === 'banners'): ?><p class="muted small">بنرها در «<a href="admin.php?tab=content">اسلایدر و نوار اطلاع‌رسانی</a>» مدیریت می‌شوند.</p><?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($meta['paged'])): ?>
              <div class="count-fields">
                <label>تعداد پزشک در هر بار نمایش (و با هر «بیشتر»)<input type="number" name="s[<?= esc($key) ?>][desktop]" value="<?= (int) $s['desktop'] ?>" min="1" max="24"></label>
                <input type="hidden" name="s[<?= esc($key) ?>][mobile]" value="24">
              </div>
            <?php elseif ($meta['counts']): ?>
              <div class="count-fields">
                <label>تعداد کارت در دسکتاپ<input type="number" name="s[<?= esc($key) ?>][desktop]" value="<?= (int) $s['desktop'] ?>" min="1" max="24"></label>
                <label>تعداد در موبایل<input type="number" name="s[<?= esc($key) ?>][mobile]" value="<?= (int) $s['mobile'] ?>" min="1" max="24"></label>
              </div>
            <?php endif; ?>
          </div>
          <span class="sort-buttons">
            <button type="button" class="icon-button" data-move="up" aria-label="انتقال <?= esc($meta['label']) ?> به بالا"><?= icon('arrow-up') ?></button>
            <button type="button" class="icon-button" data-move="down" aria-label="انتقال <?= esc($meta['label']) ?> به پایین"><?= icon('arrow-down') ?></button>
          </span>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>

  <div class="grid-2 align-start">
    <section class="panel">
      <h2>بخش اصلی (Hero)</h2>
      <div class="image-field">
        <img src="<?= esc($img($S['hero']['image'], 'assets/illustrations/hero.svg')) ?>" alt="" width="260" height="200" loading="lazy">
        <div>
          <label>تصویر (PNG، JPEG یا WebP؛ برش به ۱۰۰۰×۷۶۰)<input type="file" name="img_hero" accept="image/png,image/jpeg,image/webp"></label>
          <?php if ($S['hero']['image'] !== ''): ?><label class="check"><input type="checkbox" name="remove_hero" value="1"> بازگشت به طرح پیش‌فرض</label><?php endif; ?>
        </div>
      </div>
      <fieldset>
        <legend>تخصص‌های پرجستجو زیر کادر جستجو (حداکثر ۸؛ خالی = پرپزشک‌ترین تخصص‌ها)</legend>
        <input type="hidden" name="hero_chips[]" value="0">
        <div class="check-grid">
          <?php foreach ($specialties as $sp): ?><label class="check"><input type="checkbox" name="hero_chips[]" value="<?= (int) $sp['id'] ?>"<?= checked_attr(in_array((int) $sp['id'], $S['hero']['chips'], true)) ?>> <?= esc($sp['name']) ?></label><?php endforeach; ?>
        </div>
      </fieldset>
    </section>
    <section class="panel">
      <h2>بنر تبلیغاتی پزشکان</h2>
      <div class="image-field">
        <img src="<?= esc($img($S['promo']['image'], 'assets/illustrations/promo.svg')) ?>" alt="" width="200" height="150" loading="lazy">
        <div>
          <label>تصویر (برش به ۷۲۰×۵۴۰)<input type="file" name="img_promo" accept="image/png,image/jpeg,image/webp"></label>
          <?php if ($S['promo']['image'] !== ''): ?><label class="check"><input type="checkbox" name="remove_promo" value="1"> بازگشت به طرح پیش‌فرض</label><?php endif; ?>
        </div>
      </div>
      <div class="form-grid">
        <label>متن دکمه<input name="promo_label" value="<?= esc($S['promo']['label']) ?>" maxlength="40" required></label>
        <label>پیوند دکمه<input name="promo_url" value="<?= esc($S['promo']['url']) ?>" maxlength="255" dir="ltr" required></label>
      </div>
      <fieldset><legend>رنگ پس‌زمینه (رنگ‌ها با کنتراست مناسب متن سفید انتخاب شده‌اند)</legend>
        <div class="swatch-picker">
          <?php foreach (home_promo_themes() as $k => $l): ?><label class="swatch-option theme-<?= $k ?>"><input type="radio" name="promo_theme" value="<?= $k ?>"<?= checked_attr($S['promo']['theme'] === $k) ?>><span><?= esc($l) ?></span></label><?php endforeach; ?>
        </div>
      </fieldset>
      <p class="muted small">عنوان و توضیح این بنر در فهرست بخش‌ها (بالا) است.</p>
    </section>
  </div>

  <section class="panel">
    <h2>کارت‌های خدمات اصلی</h2>
    <div class="grid-2 align-start">
      <?php foreach (['doctors' => ['نوبت‌دهی پزشکان', 'doctors.svg'], 'centers' => ['مراکز درمانی', 'centers.svg']] as $card => [$label, $art]): ?>
        <fieldset>
          <legend>کارت <?= esc($label) ?></legend>
          <div class="image-field">
            <img src="<?= esc($img($S['services'][$card . '_image'], 'assets/illustrations/' . $art)) ?>" alt="" width="160" height="120" loading="lazy">
            <div>
              <label>تصویر (برش به ۶۴۰×۴۸۰)<input type="file" name="img_<?= $card ?>_image" accept="image/png,image/jpeg,image/webp"></label>
              <?php if ($S['services'][$card . '_image'] !== ''): ?><label class="check"><input type="checkbox" name="remove_<?= $card ?>_image" value="1"> طرح پیش‌فرض</label><?php endif; ?>
            </div>
          </div>
          <label>عنوان<input name="svc[<?= $card ?>_title]" value="<?= esc($S['services'][$card . '_title']) ?>" maxlength="60"></label>
          <label>توضیح<input name="svc[<?= $card ?>_text]" value="<?= esc($S['services'][$card . '_text']) ?>" maxlength="200"></label>
          <div class="form-grid">
            <label>متن دکمه<input name="svc[<?= $card ?>_label]" value="<?= esc($S['services'][$card . '_label']) ?>" maxlength="40"></label>
            <label>پیوند<input name="svc[<?= $card ?>_url]" value="<?= esc($S['services'][$card . '_url']) ?>" maxlength="255" dir="ltr"></label>
          </div>
        </fieldset>
      <?php endforeach; ?>
    </div>
  </section>
  <div class="sticky-actions"><button><?= icon('check') ?> ذخیره در پیش‌نویس</button> <a class="button secondary" href="index.php?preview=1" target="_blank" rel="noopener"><?= icon('eye') ?> پیش‌نمایش</a></div>
</form>

<?php elseif ($view === 'featured'): /** @var array $featured @var array $candidates @var string $q */ ?>
<div class="grid-2 align-start">
  <section class="panel">
    <div class="panel-head"><h2>پزشکان منتخب</h2><span class="muted small">فقط پزشکان تأییدشده با اشتراک معتبر در صفحه اصلی دیده می‌شوند؛ هر پزشک در شهر مطب خود.</span></div>
    <?php if (!$featured): ?>
      <p class="empty-state">هنوز پزشک منتخبی انتخاب نشده است؛ تا آن زمان این بخش در صفحه اصلی نمایش داده نمی‌شود.</p>
    <?php else: ?>
      <form method="post">
        <?php form_token('home_featured_order'); ?><input type="hidden" name="return_view" value="featured">
        <ol class="sort-list compact" data-sortable>
          <?php foreach ($featured as $d): ?>
            <li class="sort-item" data-sort-item>
              <input type="hidden" name="order[]" value="<?= (int) $d['id'] ?>">
              <span class="grip" aria-hidden="true"><?= icon('grip') ?></span>
              <div class="sort-main"><strong><?= esc($d['name']) ?></strong><small class="muted"><?= esc(($d['specialty'] ?? '') . ' • ' . ($d['cities'] ?? '')) ?></small></div>
              <span class="sort-buttons">
                <button type="button" class="icon-button" data-move="up" aria-label="بالا"><?= icon('arrow-up') ?></button>
                <button type="button" class="icon-button" data-move="down" aria-label="پایین"><?= icon('arrow-down') ?></button>
                <button class="danger small" form="unfeature-<?= (int) $d['id'] ?>">حذف</button>
              </span>
            </li>
          <?php endforeach; ?>
        </ol>
        <button class="secondary">ذخیره ترتیب</button>
      </form>
      <?php foreach ($featured as $d): ?>
        <form method="post" id="unfeature-<?= (int) $d['id'] ?>" class="sr-only-form"><?php form_token('home_featured'); ?><input type="hidden" name="return_view" value="featured"><input type="hidden" name="doctor_id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="on" value="0"></form>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>
  <section class="panel">
    <h2>افزودن پزشک منتخب</h2>
    <form method="get" class="filter-bar">
      <input type="hidden" name="tab" value="home"><input type="hidden" name="view" value="featured">
      <label>جستجوی نام<input name="q" value="<?= esc($q) ?>"></label><button><?= icon('search') ?> جستجو</button>
    </form>
    <ul class="list">
      <?php foreach ($candidates as $d): ?>
        <li class="between">
          <span><strong><?= esc($d['name']) ?></strong> <small class="muted"><?= esc(($d['specialty'] ?? '') . ' • ' . ($d['cities'] ?? '')) ?><?= $d['subscription_until'] && $d['subscription_until'] < date('Y-m-d') ? ' • اشتراک منقضی' : '' ?></small></span>
          <form method="post" class="inline-form"><?php form_token('home_featured'); ?><input type="hidden" name="return_view" value="featured"><input type="hidden" name="doctor_id" value="<?= (int) $d['id'] ?>"><input type="hidden" name="on" value="1"><button class="secondary small"><?= icon('plus') ?> افزودن</button></form>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if (!$candidates): ?><p class="empty-state">پزشک تأییدشده‌ای با این نام نیست.</p><?php endif; ?>
  </section>
</div>

<?php elseif ($view === 'specialties'): /** @var array $rows */ ?>
<section class="panel">
  <div class="panel-head"><h2>تخصص‌های صفحه اصلی</h2><span class="muted small">تخصص‌هایی که پزشک فعال دارند همیشه با آیکن نمایش داده می‌شوند؛ تیک یعنی تخصص حتی بدون پزشک هم (با «به‌زودی») نمایش داده شود. ترتیب همین فهرست، ترتیب نمایش در همه صفحه‌هاست. تصویر و آیکن هر تخصص در <a href="admin.php?tab=specialties">تخصص‌ها و خدمات</a>.</span></div>
  <form method="post">
    <?php form_token('home_specialties'); ?><input type="hidden" name="return_view" value="specialties">
    <ol class="sort-list compact" data-sortable>
      <?php foreach ($rows as $s): ?>
        <li class="sort-item" data-sort-item>
          <input type="hidden" name="order[]" value="<?= (int) $s['id'] ?>">
          <span class="grip" aria-hidden="true"><?= icon('grip') ?></span>
          <?= specialty_visual($s) ?>
          <label class="check sort-main"><input type="checkbox" name="show[]" value="<?= (int) $s['id'] ?>"<?= checked_attr((int) $s['show_on_home'] === 1) ?>> <?= esc($s['name']) ?><?= $s['active'] ? '' : ' <small class="muted">(غیرفعال)</small>' ?></label>
          <span class="sort-buttons">
            <button type="button" class="icon-button" data-move="up" aria-label="بالا"><?= icon('arrow-up') ?></button>
            <button type="button" class="icon-button" data-move="down" aria-label="پایین"><?= icon('arrow-down') ?></button>
          </span>
        </li>
      <?php endforeach; ?>
    </ol>
    <button>ذخیره</button>
  </form>
</section>

<?php elseif ($view === 'faq'): /** @var array $rows @var array $edit */ $e = $post && ($post['action'] ?? '') === 'faq_save' ? $post : $edit; ?>
<div class="grid-2 align-start">
  <section class="panel">
    <div class="panel-head"><h2>سوالات متداول</h2></div>
    <form method="post">
      <?php form_token('faq_order'); ?><input type="hidden" name="return_view" value="faq">
      <ol class="sort-list compact" data-sortable>
        <?php foreach ($rows as $f): ?>
          <li class="sort-item" data-sort-item>
            <input type="hidden" name="order[]" value="<?= (int) $f['id'] ?>">
            <span class="grip" aria-hidden="true"><?= icon('grip') ?></span>
            <div class="sort-main"><strong><?= esc($f['question']) ?></strong><small class="muted"><?= $f['active'] ? 'فعال' : 'غیرفعال' ?></small></div>
            <span class="sort-buttons">
              <button type="button" class="icon-button" data-move="up" aria-label="بالا"><?= icon('arrow-up') ?></button>
              <button type="button" class="icon-button" data-move="down" aria-label="پایین"><?= icon('arrow-down') ?></button>
              <a href="admin.php?tab=home&amp;view=faq&amp;edit=<?= (int) $f['id'] ?>">ویرایش</a>
            </span>
          </li>
        <?php endforeach; ?>
      </ol>
      <?php if ($rows): ?><button class="secondary">ذخیره ترتیب</button><?php else: ?><p class="empty-state">هنوز سوالی ثبت نشده است.</p><?php endif; ?>
    </form>
  </section>
  <section class="panel">
    <h2><?= !empty($e['id']) ? 'ویرایش سوال' : 'سوال جدید' ?></h2>
    <form method="post" class="stack">
      <?php form_token('faq_save'); ?><input type="hidden" name="return_view" value="faq"><input type="hidden" name="id" value="<?= esc($e['id'] ?? '') ?>">
      <label>سوال<input name="question" value="<?= esc($e['question'] ?? '') ?>" maxlength="255" required></label>
      <label>پاسخ<textarea name="answer" maxlength="3000" required><?= esc($e['answer'] ?? '') ?></textarea></label>
      <p class="muted small">در پاسخ می‌توانید از <code dir="ltr">{booking_fee}</code> (کارمزد فعلی) و <code dir="ltr">{cancel_hours}</code> (مهلت لغو) استفاده کنید تا همیشه با تنظیمات یکی باشد.</p>
      <label class="check"><input type="checkbox" name="active" value="1"<?= checked_attr(!isset($e['active']) || (int) $e['active'] === 1) ?>> نمایش در سایت</label>
      <button>ذخیره</button>
    </form>
    <?php if (!empty($e['id'])): ?>
      <form method="post" class="inline-form" data-confirm="این سوال حذف شود؟"><?php form_token('faq_delete'); ?><input type="hidden" name="return_view" value="faq"><input type="hidden" name="id" value="<?= (int) $e['id'] ?>"><button class="danger small">حذف سوال</button></form>
      <a href="admin.php?tab=home&amp;view=faq">سوال جدید</a>
    <?php endif; ?>
  </section>
</div>

<?php elseif ($view === 'pages'): /** @var array $rows @var ?array $edit */ ?>
<div class="grid-2 align-start">
  <section class="panel">
    <h2>صفحه‌های سایت</h2>
    <ul class="list">
      <?php foreach ($rows as $p): ?>
        <li class="between"><a href="admin.php?tab=home&amp;view=pages&amp;page=<?= esc($p['slug']) ?>"><?= esc($p['title']) ?></a>
          <span><span class="badge <?= $p['published'] ? 'status-confirmed' : 'status-held' ?>"><?= $p['published'] ? 'منتشرشده' : 'پیش‌نویس' ?></span> <a href="<?= esc(page_url($p['slug'])) ?>" target="_blank" rel="noopener">مشاهده</a></span></li>
      <?php endforeach; ?>
    </ul>
    <p class="muted small">قوانین و حریم خصوصی تا انتشار شما در پانویس نمایش داده نمی‌شوند. متن آن‌ها را با مشاور حقوقی بازبینی کنید.</p>
  </section>
  <?php if ($edit): $e = $post && ($post['action'] ?? '') === 'page_save' ? $post + $edit : $edit; ?>
    <section class="panel">
      <h2>ویرایش «<?= esc($edit['title']) ?>»</h2>
      <form method="post" class="stack">
        <?php form_token('page_save'); ?><input type="hidden" name="return_view" value="pages"><input type="hidden" name="slug" value="<?= esc($edit['slug']) ?>">
        <label>عنوان<input name="title" value="<?= esc($e['title']) ?>" maxlength="190" required></label>
        <label>متن<textarea name="body" class="tall" required><?= esc($e['body']) ?></textarea></label>
        <p class="muted small">قالب‌بندی ساده: خط خالی بین پاراگراف‌ها، «## » برای عنوان و «- » برای فهرست.</p>
        <label class="check"><input type="checkbox" name="published" value="1"<?= checked_attr((int) $e['published'] === 1) ?>> منتشر شود</label>
        <button>ذخیره</button>
      </form>
    </section>
  <?php endif; ?>
</div>

<?php elseif ($view === 'drawer'): /** @var array $banner @var array $menu */ $b = $post && ($post['action'] ?? '') === 'drawer_banner_save' ? $post + $banner : $banner; ?>
<div class="grid-2 align-start">
  <form method="post" enctype="multipart/form-data" class="panel">
    <?php form_token('drawer_banner_save'); ?><input type="hidden" name="return_view" value="drawer">
    <h2>بنر بالای منوی موبایل</h2>
    <p class="muted small">در گوشی، با زدن دکمه منو، این بنر بالای فهرست دیده می‌شود. برای نمایش، عنوان یا تصویر لازم است.</p>
    <label class="check"><input type="checkbox" name="enabled" value="1"<?= checked_attr(!empty($b['enabled'])) ?>> نمایش بنر</label>
    <label>عنوان<input name="title" value="<?= esc($b['title']) ?>" maxlength="80"></label>
    <label>متن کوتاه<input name="text" value="<?= esc($b['text']) ?>" maxlength="160"></label>
    <div class="form-grid">
      <label>متن دکمه (اختیاری)<input name="button" value="<?= esc($b['button']) ?>" maxlength="30"></label>
      <label>پیوند<input name="url" value="<?= esc($b['url']) ?>" dir="ltr" placeholder="register.php یا https://…"></label>
    </div>
    <fieldset><legend>رنگ زمینه (آبی، نیلی و فیروزه‌ای روشن با متن تیره؛ سرمه‌ای تیره با متن سفید)</legend>
    <div class="swatch-picker">
      <?php foreach (home_promo_themes() as $k => $l): ?><label class="swatch-option theme-<?= $k ?>"><input type="radio" name="theme" value="<?= $k ?>"<?= checked_attr($b['theme'] === $k) ?>><span><?= esc($l) ?></span></label><?php endforeach; ?>
    </div>
    </fieldset>
    <div class="image-field">
      <?php if ($banner['image'] !== ''): ?><img src="<?= esc(home_image_url($banner['image'])) ?>" alt="" width="180" height="75"><?php endif; ?>
      <div>
        <label>تصویر (برش به ۷۲۰×۳۰۰؛ PNG، JPG یا WebP)<input type="file" name="image" accept="image/png,image/jpeg,image/webp"></label>
        <?php if ($banner['image'] !== ''): ?><label class="check"><input type="checkbox" name="remove_image" value="1"> حذف تصویر</label><?php endif; ?>
      </div>
    </div>
    <button><?= icon('check') ?> ذخیره بنر</button>
  </form>
  <section class="panel">
    <h2>گزینه‌های منو</h2>
    <p class="muted small">گزینه‌ها به صفحه‌های واقعی سامانه وصل‌اند. «نوبت‌های من» فقط برای کاربر واردشده و «قوانین»، «درباره» و «تماس» فقط پس از انتشار همان صفحه در زبانه «صفحه‌ها» نمایش داده می‌شوند.</p>
    <ol class="menu-preview">
      <?php foreach ($menu as $m): ?>
        <li><?= icon($m['icon']) ?> <?= esc($m['label']) ?>
          <?php if (!empty($m['children'])): ?><ul><?php foreach ($m['children'] as $c): ?><li><?= esc($c['label']) ?></li><?php endforeach; ?></ul><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>
</div>
<?php elseif ($view === 'footer'): /** @var array $social @var array $badges @var string $about @var string $address @var bool $showCode */ ?>
<form method="post" enctype="multipart/form-data" class="grid-2 align-start">
  <?php form_token('footer_save'); ?><input type="hidden" name="return_view" value="footer">
  <section class="panel">
    <h2>پانویس</h2>
    <label>معرفی کوتاه<textarea name="footer_about" maxlength="300"><?= esc($post['footer_about'] ?? $about) ?></textarea></label>
    <label>نشانی دفتر (اختیاری)<input name="support_address" value="<?= esc($post['support_address'] ?? $address) ?>" maxlength="200"></label>
    <p class="muted small">تلفن، ایمیل و ساعات پاسخ‌گویی در <a href="admin.php?tab=general">تنظیمات عمومی</a> است.</p>
    <h3>شبکه‌های اجتماعی</h3>
    <?php foreach (social_networks() as $k => $l): ?><label><?= esc($l) ?><input name="social[<?= $k ?>]" value="<?= esc($post['social'][$k] ?? ($social[$k] ?? '')) ?>" dir="ltr" placeholder="https://"></label><?php endforeach; ?>
    <label class="check"><input type="checkbox" name="home_show_medical_code" value="1"<?= checked_attr($showCode) ?>> نمایش شماره نظام پزشکی روی کارت پزشکان صفحه اصلی</label>
  </section>
  <section class="panel">
    <h2>نمادهای قانونی</h2>
    <p class="muted small">پس از دریافت نماد اعتماد یا مجوز، تصویر و پیوند تأیید آن را اینجا قرار دهید. کد اسکریپتی نمادها به دلیل سیاست امنیتی سایت پذیرفته نمی‌شود؛ تصویر و پیوند صفحه استعلام کافی است.</p>
    <?php for ($i = 0; $i < 3; $i++): $b = $badges[$i] ?? ['image' => '', 'url' => '', 'title' => '']; ?>
      <fieldset>
        <legend>نماد <?= fa_number($i + 1) ?></legend>
        <?php if ($b['image'] !== ''): ?><img src="<?= esc(home_image_url($b['image'])) ?>" alt="" width="64" height="64"><label class="check"><input type="checkbox" name="badge_remove[<?= $i ?>]" value="1"> حذف</label><?php endif; ?>
        <label>تصویر<input type="file" name="badge_<?= $i ?>" accept="image/png,image/jpeg,image/webp"></label>
        <div class="form-grid">
          <label>عنوان<input name="badge_title[<?= $i ?>]" value="<?= esc($b['title']) ?>" maxlength="60"></label>
          <label>پیوند استعلام<input name="badge_url[<?= $i ?>]" value="<?= esc($b['url']) ?>" dir="ltr" placeholder="https://"></label>
        </div>
      </fieldset>
    <?php endfor; ?>
    <button>ذخیره پانویس</button>
  </section>
</form>
<?php endif; ?>
