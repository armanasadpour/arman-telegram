<?php
declare(strict_types=1);

/** One health article. Drafts are visible only to administrators with the content permission (preview). */

require __DIR__ . '/core.php';
require_schema();
$slug = (string) ($_GET['slug'] ?? '');
$a = query('SELECT a.*,s.name specialty FROM articles a LEFT JOIN specialties s ON s.id=a.specialty_id WHERE a.slug=?', [$slug])->fetch();
$preview = $a && admin() && admin_can('content');
if (!$a || (!$preview && ($a['status'] !== 'published' || strtotime((string) $a['published_at']) > time()))) {
    http_response_code(404);
    layout_start('مقاله پیدا نشد');
    echo '<div class="empty-state wide"><p>این مقاله پیدا نشد یا هنوز منتشر نشده است.</p><a class="button secondary" href="articles.php">همه مقالات</a></div>';
    layout_end();
    exit();
}
if (!$preview) {
    query('UPDATE articles SET views=views+1 WHERE id=?', [$a['id']]);
}
$related = query("SELECT slug,title,published_at FROM articles WHERE status='published' AND published_at<=NOW() AND id<>? AND (specialty_id<=>? OR ? IS NULL) ORDER BY published_at DESC LIMIT 4", [$a['id'], $a['specialty_id'], $a['specialty_id']])->fetchAll();
$cover = $a['cover_file'] ? 'media.php?kind=article&file=' . rawurlencode($a['cover_file']) : '';
layout_start($a['title'], [
    'description' => $a['summary'] !== '' ? $a['summary'] : mb_substr(preg_replace('/\s+/u', ' ', $a['body']), 0, 160),
    'canonical' => article_url($a['slug']),
    'og_image' => $cover,
    'jsonld' => ['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $a['title'], 'datePublished' => date('c', strtotime((string) ($a['published_at'] ?? $a['created_at']))), 'dateModified' => date('c', strtotime((string) ($a['updated_at'] ?? $a['created_at']))), 'inLanguage' => 'fa'] + ($cover ? ['image' => app_base_url() . $cover] : []),
]);
?>
<article class="article-page">
  <?php if ($preview && $a['status'] !== 'published'): ?><p class="preview-bar" role="status"><?= icon('eye') ?> پیش‌نمایش پیش‌نویس؛ این مقاله برای بازدیدکنندگان منتشر نشده است.</p><?php endif; ?>
  <nav class="breadcrumb" aria-label="مسیر"><a href="index.php">صفحه اصلی</a><span>/</span><a href="articles.php">مقالات سلامت</a></nav>
  <h1><?= esc($a['title']) ?></h1>
  <p class="muted"><?= $a['specialty'] ? '<span class="tag">' . esc($a['specialty']) . '</span> ' : '' ?><bdi><?= esc(jdate($a['published_at'] ?? $a['created_at'])) ?></bdi> • <?= fa_number(reading_minutes($a['body'])) ?> دقیقه مطالعه</p>
  <?php if ($cover): ?><img class="article-hero" src="<?= esc($cover) ?>" alt="" width="1200" height="630"><?php endif; ?>
  <?php if ($a['summary'] !== ''): ?><p class="lead"><?= esc($a['summary']) ?></p><?php endif; ?>
  <div class="prose"><?= rich_text($a['body']) ?></div>
  <p class="notice small">این مطلب جایگزین معاینه و تشخیص پزشک نیست.<?php if ($a['specialty_id']): ?> <a href="<?= esc(specialty_url((int) $a['specialty_id'], (string) $a['specialty'])) ?>">نوبت از پزشکان <?= esc($a['specialty']) ?></a><?php endif; ?></p>
  <?php if ($related): ?>
    <section class="related"><h2>مطالب مرتبط</h2><ul class="list"><?php foreach ($related as $r): ?><li><a href="<?= esc(article_url($r['slug'])) ?>"><?= esc($r['title']) ?></a> <small><bdi><?= esc(jdate($r['published_at'])) ?></bdi></small></li><?php endforeach; ?></ul></section>
  <?php endif; ?>
</article>
<?php layout_end();
