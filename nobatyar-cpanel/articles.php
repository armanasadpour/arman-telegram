<?php
declare(strict_types=1);

/** Health articles: published list with optional specialty filter and paging. */

require __DIR__ . '/core.php';
require_schema();
$page = max(1, (int) ($_GET['page'] ?? 1));
$specialty = max(0, (int) ($_GET['specialty'] ?? 0));
$args = $specialty ? [$specialty] : [];
$total = (int) query("SELECT COUNT(*) FROM articles WHERE status='published' AND published_at<=NOW()" . ($specialty ? ' AND specialty_id=?' : ''), $args)->fetchColumn();
$rows = articles_public(12, ($page - 1) * 12, $specialty);
$topics = query("SELECT s.id,s.name,COUNT(*) n FROM articles a JOIN specialties s ON s.id=a.specialty_id WHERE a.status='published' AND a.published_at<=NOW() GROUP BY s.id,s.name ORDER BY n DESC LIMIT 12")->fetchAll();
layout_start('مقالات سلامت', ['description' => 'مقالات آموزشی سلامت و راهنمای مراجعه به پزشک', 'canonical' => 'articles.php']);
?>
<section class="page-head">
  <h1>مقالات سلامت</h1>
  <?php if ($topics): ?>
    <nav class="chip-row" aria-label="موضوع">
      <a class="chip<?= $specialty ? '' : ' current' ?>" href="articles.php">همه</a>
      <?php foreach ($topics as $t): ?><a class="chip<?= $specialty === (int) $t['id'] ? ' current' : '' ?>" href="articles.php?specialty=<?= (int) $t['id'] ?>"><?= esc($t['name']) ?></a><?php endforeach; ?>
    </nav>
  <?php endif; ?>
</section>
<div class="article-grid">
  <?php foreach ($rows as $a): ?>
    <a class="article-card" href="<?= esc(article_url($a['slug'])) ?>">
      <span class="article-cover"><?php if ($a['cover_file']): ?><img src="media.php?kind=article&amp;file=<?= esc($a['cover_file']) ?>" alt="" width="600" height="315" loading="lazy" decoding="async"><?php else: ?><?= icon('article') ?><?php endif; ?></span>
      <span class="article-body">
        <?php if ($a['specialty']): ?><span class="tag"><?= esc($a['specialty']) ?></span><?php endif; ?>
        <strong><?= esc($a['title']) ?></strong>
        <?php if ($a['summary'] !== ''): ?><span class="clamp2"><?= esc($a['summary']) ?></span><?php endif; ?>
        <small><bdi><?= esc(jdate($a['published_at'])) ?></bdi> • <?= fa_number(reading_minutes($a['body'])) ?> دقیقه مطالعه</small>
      </span>
    </a>
  <?php endforeach; ?>
</div>
<?php if (!$rows): ?><p class="empty-state">هنوز مقاله‌ای منتشر نشده است.</p><?php endif; ?>
<?= pagination($page, $total, 12, $specialty ? ['specialty' => $specialty] : []) ?>
<?php layout_end();
