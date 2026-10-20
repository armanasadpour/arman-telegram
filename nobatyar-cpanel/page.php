<?php
declare(strict_types=1);

/** Editable site pages (guides, terms, privacy, about, contact). Unpublished pages are admin-preview only. */

require __DIR__ . '/core.php';
require_schema();
$slug = (string) ($_GET['slug'] ?? '');
$p = preg_match('/^[a-z-]{2,40}$/', $slug) ? site_page($slug, false) : null;
$preview = $p && admin() && admin_can('content');
if (!$p || (!$p['published'] && !$preview)) {
    http_response_code(404);
    layout_start('صفحه پیدا نشد');
    echo '<div class="empty-state wide"><p>این صفحه پیدا نشد.</p><a class="button secondary" href="index.php">صفحه اصلی</a></div>';
    layout_end();
    exit();
}
$contact = site_contact();
layout_start($p['title'], ['description' => mb_substr(preg_replace('/\s+/u', ' ', strip_tags($p['body'])), 0, 160), 'canonical' => page_url($slug)]);
?>
<article class="article-page">
  <?php if (!$p['published']): ?><p class="preview-bar" role="status"><?= icon('eye') ?> پیش‌نمایش؛ این صفحه هنوز منتشر نشده است.</p><?php endif; ?>
  <h1><?= esc($p['title']) ?></h1>
  <div class="prose"><?= rich_text($p['body']) ?></div>
  <?php if ($slug === 'contact'): ?>
    <div class="contact-cards">
      <?php if ($contact['phone']): ?><a class="card" href="tel:<?= esc(preg_replace('/[^0-9+]/', '', $contact['phone'])) ?>"><?= icon('phone') ?><strong>تلفن</strong><bdi><?= esc(fa_number($contact['phone'])) ?></bdi></a><?php endif; ?>
      <?php if ($contact['email']): ?><a class="card" href="mailto:<?= esc($contact['email']) ?>"><?= icon('mail') ?><strong>ایمیل</strong><span dir="ltr"><?= esc($contact['email']) ?></span></a><?php endif; ?>
      <a class="card" href="support.php"><?= icon('chat') ?><strong>تیکت پشتیبانی</strong><span>پاسخ در حساب کاربری</span></a>
    </div>
  <?php endif; ?>
  <p class="muted small">آخرین به‌روزرسانی: <bdi><?= esc(jdate($p['updated_at'])) ?></bdi></p>
</article>
<?php layout_end();
