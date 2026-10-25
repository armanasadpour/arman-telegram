<?php /** 1.0.30 published reviews of the doctor, each with the doctor's reply. @var array $reviews */
$replyLabels = ['pending' => ['در انتظار تأیید مدیر', 'status-pending'], 'approved' => ['منتشرشده', 'status-confirmed'], 'hidden' => ['منتشر نشد', 'status-cancelled']]; ?>
<section class="card">
  <h2><?= icon('star') ?> نظرات بیماران</h2>
  <p class="muted">نظرهای منتشرشده بیماران درباره شما. می‌توانید زیر هر نظر پاسخ کوتاه و محترمانه بنویسید؛ پاسخ پس از تأیید مدیر در صفحه شما زیر همان نظر نمایش داده می‌شود. برای برداشتن پاسخ، متن را خالی کنید.</p>
</section>
<?php if (!$reviews): ?>
  <div class="empty-state"><?= icon('star') ?><p>هنوز نظر منتشرشده‌ای برای شما ثبت نشده است.</p></div>
<?php endif; ?>
<div class="rv-panel-list">
  <?php foreach ($reviews as $r): $st = $replyLabels[$r['reply_status'] ?? ''] ?? null; ?>
    <article class="card rv-panel-item">
      <header class="rv-panel-head">
        <span class="rv-avatar" aria-hidden="true"><?= esc(mb_substr($r['first_name'] !== '' ? $r['first_name'] : 'ب', 0, 1)) ?></span>
        <div>
          <strong><?= esc($r['first_name'] ?: 'بیمار') ?></strong>
          <small class="muted"><bdi><?= esc(jdate($r['created_at'])) ?></bdi></small>
        </div>
        <span class="stars" aria-label="<?= fa_number($r['rating']) ?> از ۵"><?= str_repeat('★', (int) $r['rating']) . str_repeat('☆', 5 - (int) $r['rating']) ?></span>
      </header>
      <?php if ($r['comment']): ?><p class="rv-panel-text"><?= esc($r['comment']) ?></p><?php endif; ?>
      <?php if ($r['recommend']): ?><small class="rv-yes"><?= icon('check') ?> شما را پیشنهاد می‌کند</small><?php endif; ?>
      <form method="post" class="rv-reply-form">
        <?php form_token('review_reply'); ?>
        <input type="hidden" name="review_id" value="<?= (int) $r['id'] ?>">
        <label>
          <span class="rv-reply-title"><?= icon('chat') ?> پاسخ شما <?php if ($st): ?><span class="badge <?= $st[1] ?>"><?= esc($st[0]) ?></span><?php endif; ?></span>
          <textarea name="reply" maxlength="500" rows="2" placeholder="مثلاً: از لطف شما سپاسگزارم؛ آرزوی سلامتی دارم."><?= esc((string) ($r['reply'] ?? '')) ?></textarea>
        </label>
        <button class="small"><?= $r['reply'] ? 'ویرایش پاسخ' : 'ثبت پاسخ' ?></button>
      </form>
    </article>
  <?php endforeach; ?>
</div>
