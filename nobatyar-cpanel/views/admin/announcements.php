<?php /** @var array $rows @var array $audiences @var array $counts */
$statuses = announcement_status_labels(); ?>
<div class="grid-2 align-start">
  <section class="panel">
    <h2>اطلاعیه جدید</h2>
    <p class="muted small">اطلاعیه به‌صورت اعلان داخل حساب کاربران ثبت می‌شود و برای کسانی که اعلان مرورگر و دسته «اطلاعیه» را فعال کرده‌اند، Push هم ارسال می‌شود. پیامک ارسال نمی‌شود. گروه‌های بزرگ در چند مرحله با Cron ارسال می‌شوند.</p>
    <form method="post" class="stack" data-confirm="اطلاعیه برای همه کاربران گروه انتخاب‌شده ارسال شود؟ این کار قابل بازگشت نیست.">
      <?php form_token('announce_create'); ?>
      <label>گیرندگان<select name="audience" required><option value="">انتخاب کنید</option><?php foreach ($audiences as $k => $l): ?><option value="<?= $k ?>"<?= selected_attr(($post['audience'] ?? '') === $k) ?>><?= esc($l) ?> — <?= fa_number($counts[$k]) ?> نفر</option><?php endforeach; ?></select></label>
      <?php input('عنوان', 'title', $post['title'] ?? '', 'text', true, 120); ?>
      <label>متن<textarea name="body" required maxlength="1000"><?= esc($post['body'] ?? '') ?></textarea></label>
      <?php input('پیوند (اختیاری، مثل centers.php یا https://...)', 'url', $post['url'] ?? '', 'text', false, 255); ?>
      <button><?= icon('megaphone') ?> ارسال اطلاعیه</button>
    </form>
  </section>
  <section class="panel">
    <div class="panel-head"><h2>اطلاعیه‌های ارسال‌شده</h2></div>
    <?php foreach ($rows as $a): ?>
      <article class="announcement">
        <div class="between"><strong><?= esc($a['title']) ?></strong><span class="badge announce-<?= esc($a['status']) ?>"><?= esc($statuses[$a['status']] ?? '') ?></span></div>
        <p><?= esc($a['body']) ?></p>
        <small class="muted"><?= esc($audiences[$a['audience']] ?? '') ?> • <?= fa_number($a['sent_count']) ?> از <?= fa_number($a['total']) ?> نفر • <bdi><?= esc(jdate($a['created_at'], true)) ?></bdi> • <?= esc($a['admin_name'] ?: ($a['admin_email'] ?? '—')) ?></small>
        <?php if (in_array($a['status'], ['queued', 'sending'], true)): ?>
          <form method="post" class="inline-form"><?php form_token('announce_cancel'); ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="danger small">توقف ارسال</button></form>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
    <?php if (!$rows): ?><p class="empty-state">هنوز اطلاعیه‌ای ارسال نشده است.</p><?php endif; ?>
  </section>
</div>
