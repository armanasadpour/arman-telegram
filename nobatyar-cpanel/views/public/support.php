<?php /** @var ?array $ticket @var array $tickets @var array $contact @var string $error @var array $post */
$statuses = ticket_status_labels();
$cats = ticket_categories();
?>
<div class="support-layout">
  <section>
    <h1><?= icon('chat') ?> پشتیبانی</h1>
    <?php if ($error): ?><p class="error" role="alert"><?= esc($error) ?></p><?php endif; ?>
    <?php if ($ticket): ?>
      <p><a href="support.php">← همه تیکت‌های من</a></p>
      <article class="card">
        <div class="between"><h2>#<?= fa_number($ticket['id']) ?> — <?= esc($ticket['subject']) ?></h2><span class="badge ticket-<?= esc($ticket['status']) ?>"><?= esc($statuses[$ticket['status']]) ?></span></div>
        <p class="muted small"><?= esc($cats[$ticket['category']] ?? '') ?> • <bdi><?= esc(jdate($ticket['created_at'], true)) ?></bdi></p>
        <ol class="thread">
          <?php foreach ($ticket['messages'] as $m): ?>
            <li class="<?= $m['admin_id'] ? 'from-support' : 'from-user' ?>">
              <header><strong><?= $m['admin_id'] ? 'پشتیبانی' : 'شما' ?></strong><small><bdi><?= esc(jdate($m['created_at'], true)) ?></bdi></small></header>
              <p><?= nl2br(esc($m['body'])) ?></p>
            </li>
          <?php endforeach; ?>
        </ol>
        <form method="post" class="stack">
          <?php form_token('ticket_reply'); ?>
          <input type="hidden" name="id" value="<?= (int) $ticket['id'] ?>">
          <label><?= $ticket['status'] === 'closed' ? 'پیام جدید (تیکت دوباره باز می‌شود)' : 'پیام شما' ?><textarea name="body" required minlength="10" maxlength="3000"><?= esc($post['body'] ?? '') ?></textarea></label>
          <button>ارسال پیام</button>
        </form>
        <?php if ($ticket['status'] !== 'closed'): ?>
          <form method="post" class="inline-form" data-confirm="مشکل حل شد و تیکت بسته شود؟">
            <?php form_token('ticket_close'); ?><input type="hidden" name="id" value="<?= (int) $ticket['id'] ?>">
            <button class="secondary small"><?= icon('check') ?> مشکلم حل شد؛ بستن تیکت</button>
          </form>
        <?php endif; ?>
      </article>
    <?php else: ?>
      <h2>تیکت‌های من</h2>
      <?php foreach ($tickets as $t): ?>
        <a class="card ticket-card" href="support.php?id=<?= (int) $t['id'] ?>">
          <span><strong>#<?= fa_number($t['id']) ?> — <?= esc($t['subject']) ?></strong><small><?= esc($cats[$t['category']] ?? '') ?> • آخرین تغییر <bdi><?= esc(jdate($t['updated_at'], true)) ?></bdi></small></span>
          <span class="badge ticket-<?= esc($t['status']) ?>"><?= esc($statuses[$t['status']]) ?></span>
        </a>
      <?php endforeach; ?>
      <?php if (!$tickets): ?><p class="empty-state">هنوز تیکتی ثبت نکرده‌اید.</p><?php endif; ?>
    <?php endif; ?>
  </section>
  <aside>
    <?php if (!$ticket): ?>
      <section class="card">
        <h2>تیکت جدید</h2>
        <form method="post" class="stack">
          <?php form_token('ticket_new'); ?>
          <label>دسته موضوع<select name="category" required><option value="">انتخاب کنید</option><?php foreach ($cats as $k => $l): ?><option value="<?= $k ?>"<?= selected_attr(($post['category'] ?? '') === $k) ?>><?= esc($l) ?></option><?php endforeach; ?></select></label>
          <?php input('موضوع', 'subject', $post['subject'] ?? '', 'text', true, 190); ?>
          <label>شرح مشکل<textarea name="body" required minlength="10" maxlength="3000" placeholder="کد رهگیری نوبت یا تراکنش را اگر دارید بنویسید."><?= esc($post['body'] ?? '') ?></textarea></label>
          <p class="muted small">اطلاعات پزشکی، تصویر مدارک یا رمز کارت بانکی را در تیکت ننویسید.</p>
          <button><?= icon('chat') ?> ثبت تیکت</button>
        </form>
      </section>
    <?php endif; ?>
    <?php if ($contact['phone'] || $contact['email'] || $contact['hours']): ?>
      <section class="card">
        <h2>راه‌های تماس</h2>
        <?php if ($contact['phone']): ?><p><?= icon('phone') ?> <a href="tel:<?= esc(preg_replace('/[^0-9+]/', '', $contact['phone'])) ?>"><bdi><?= esc(fa_number($contact['phone'])) ?></bdi></a></p><?php endif; ?>
        <?php if ($contact['email']): ?><p><a href="mailto:<?= esc($contact['email']) ?>" dir="ltr"><?= esc($contact['email']) ?></a></p><?php endif; ?>
        <?php if ($contact['hours']): ?><p class="muted small"><?= esc($contact['hours']) ?></p><?php endif; ?>
      </section>
    <?php endif; ?>
  </aside>
</div>
