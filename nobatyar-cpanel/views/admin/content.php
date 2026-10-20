<?php /** @var array $banners @var array $edit @var array $home */
$e = $post && ($post['action'] ?? '') === 'banner_save' ? $post : $edit;
$h = $post && ($post['action'] ?? '') === 'home_content' ? $post : $home;
$jd = fn($v) => $v && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? jdate($v) : (string) $v;
?>
<div class="grid-2 align-start">
  <section class="panel">
    <h2>نوار اطلاع‌رسانی</h2>
    <form method="post" class="stack">
      <?php form_token('home_content'); ?>
      <label>متن نوار بالای صفحه اصلی (خالی = نمایش داده نمی‌شود)<textarea name="home_notice" maxlength="300"><?= esc($h['home_notice'] ?? '') ?></textarea></label>
      <button>ذخیره</button>
    </form>
    <p class="muted small">عنوان، تصاویر و چیدمان صفحه اصلی در <a href="admin.php?tab=home">مدیریت صفحه اصلی</a> است.</p>
  </section>
  <section class="panel">
    <h2><?= !empty($e['id']) ? 'ویرایش بنر' : 'بنر جدید' ?></h2>
    <form method="post" enctype="multipart/form-data" class="stack">
      <?php form_token('banner_save'); ?>
      <input type="hidden" name="id" value="<?= esc($e['id'] ?? '') ?>">
      <?php input('عنوان', 'title', $e['title'] ?? '', 'text', true, 120); ?>
      <?php input('توضیح کوتاه', 'subtitle', $e['subtitle'] ?? '', 'text', false, 255); ?>
      <div class="form-grid">
        <?php input('پیوند (مثل centers.php یا https://...)', 'link_url', $e['link_url'] ?? '', 'text', false, 255); ?>
        <?php input('متن دکمه', 'link_label', $e['link_label'] ?? '', 'text', false, 40); ?>
        <?php input('شروع نمایش (اختیاری)', 'starts_on', $jd($e['starts_on'] ?? ''), 'text', false, 10); ?>
        <?php input('پایان نمایش (اختیاری)', 'ends_on', $jd($e['ends_on'] ?? ''), 'text', false, 10); ?>
      </div>
      <label>تصویر (اختیاری، به ۱۲۰۰×۴۰۰ برش می‌خورد؛ حداکثر ۲ مگابایت)<input type="file" name="image" accept="image/png,image/jpeg,image/webp"></label>
      <?php if (!empty($e['image_file'])): ?><label class="check"><input type="checkbox" name="remove_image" value="1"> حذف تصویر فعلی</label><?php endif; ?>
      <label class="check"><input type="checkbox" name="active" value="1"<?= checked_attr(!isset($e['active']) || (int) $e['active'] === 1) ?>> نمایش در صفحه اصلی</label>
      <button>ذخیره بنر</button>
      <?php if (!empty($e['id'])): ?><a href="admin.php?tab=content">انصراف</a><?php endif; ?>
    </form>
  </section>
</div>
<section class="panel">
  <div class="panel-head"><h2>بنرهای صفحه اصلی</h2><span class="muted small">حداکثر ۵ بنر فعال به ترتیب زیر نمایش داده می‌شود.</span></div>
  <?php foreach ($banners as $b): ?>
    <div class="row-item banner-row">
      <?php if ($b['image_file']): ?><img src="media.php?kind=banner&amp;file=<?= esc($b['image_file']) ?>" alt="" width="150" height="50" loading="lazy"><?php else: ?><span class="banner-thumb"><?= icon('image') ?></span><?php endif; ?>
      <div class="row-main">
        <strong><?= esc($b['title']) ?></strong>
        <small><?= $b['active'] ? 'فعال' : 'غیرفعال' ?><?= $b['starts_on'] ? ' • از ' . esc(jdate($b['starts_on'])) : '' ?><?= $b['ends_on'] ? ' • تا ' . esc(jdate($b['ends_on'])) : '' ?><?= $b['link_url'] ? ' • ' . esc($b['link_url']) : '' ?></small>
      </div>
      <div class="row-actions">
        <?php foreach (['up' => 'بالا', 'down' => 'پایین'] as $dir => $label): ?>
          <form method="post" class="inline-form"><?php form_token('banner_move'); ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="direction" value="<?= $dir ?>"><button class="icon-button" aria-label="انتقال به <?= $label ?>" title="<?= $label ?>"><?= icon($dir === 'up' ? 'arrow-up' : 'arrow-down') ?></button></form>
        <?php endforeach; ?>
        <a href="admin.php?tab=content&amp;edit=<?= (int) $b['id'] ?>">ویرایش</a>
        <form method="post" class="inline-form" data-confirm="این بنر حذف شود؟"><?php form_token('banner_delete'); ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="danger small">حذف</button></form>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$banners): ?><p class="empty-state">هنوز بنری ساخته نشده است.</p><?php endif; ?>
</section>
