<?php
declare(strict_types=1);

/** All frequently asked questions (managed in «مدیریت صفحه اصلی ← سوالات متداول»). */

require __DIR__ . '/core.php';
require_schema();
$faqs = faq_list();
$jsonld = $faqs ? [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => array_map(fn($f) => [
        '@type' => 'Question',
        'name' => $f['question'],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags(rich_text(faq_answer($f['answer'])))],
    ], $faqs),
] : null;
layout_start('سوالات متداول', ['description' => 'پاسخ پرسش‌های رایج درباره نوبت‌گیری، کارمزد رزرو، لغو و پرداخت', 'canonical' => 'faq.php', 'jsonld' => $jsonld]);
?>
<section class="faq-page">
  <header class="page-head"><span class="eyebrow">راهنما</span><h1>سوالات متداول</h1></header>
  <div class="faq-layout">
    <div class="faq-list">
      <?php foreach ($faqs as $i => $f): ?>
        <details class="faq" id="faq-<?= (int) $f['id'] ?>"<?= $i === 0 ? ' open' : '' ?>>
          <summary><?= esc($f['question']) ?><?= icon('chevron-down', 'icon chev') ?></summary>
          <div class="faq-answer"><?= rich_text(faq_answer($f['answer'])) ?></div>
        </details>
      <?php endforeach; ?>
      <?php if (!$faqs): ?><p class="empty-state">هنوز پرسشی ثبت نشده است.</p><?php endif; ?>
    </div>
    <aside class="faq-help">
      <span class="faq-help-icon"><?= icon('heart-handshake') ?></span>
      <strong>پاسخ سوالتان را پیدا نکردید؟</strong>
      <p>تیم پشتیبانی پاسخ‌گوی سوال‌های شماست.</p>
      <a class="button secondary" href="support.php"><?= icon('chat') ?> تماس با پشتیبانی</a>
    </aside>
  </div>
</section>
<?php layout_end();
