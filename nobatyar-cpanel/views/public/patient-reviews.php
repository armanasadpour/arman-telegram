<?php
/**
 * Patient reviews: one per completed appointment of this account. Reviews appear on the doctor page after
 * the administrator approves them (when moderation is on).
 *
 * @var array $open completed appointments without a review @var array $mine reviews already sent
 */
$labels = ['pending' => 'در انتظار بررسی', 'approved' => 'منتشرشده', 'hidden' => 'منتشر نشد'];
?>
<section class="card">
  <h2>نظر درباره نوبت‌های انجام‌شده</h2>
  <p class="muted">فقط برای نوبت‌هایی که در مطب انجام شده‌اند می‌توانید نظر بدهید. نام کامل و شماره شما نمایش داده نمی‌شود؛ فقط نام کوچک کنار نظر می‌آید.</p>
  <?php if (!$open): ?><p class="empty-state">نوبت انجام‌شده‌ای که منتظر نظر شما باشد وجود ندارد.</p><?php endif; ?>
  <?php foreach ($open as $b): ?>
    <form method="post" class="review-form">
      <?php form_token('review'); ?>
      <input type="hidden" name="booking_id" value="<?= (int) $b['id'] ?>">
      <h3><?= esc(doctor_display_name($b['doctor'])) ?> <small class="muted">• <?= esc(jdate($b['starts_at'])) ?> • <?= esc($b['patient_name']) ?></small></h3>
      <fieldset class="filter-group"><legend>امتیاز شما</legend>
        <div class="seg">
          <?php for ($i = 1; $i <= 5; $i++): ?><label><input type="radio" name="rating" value="<?= $i ?>" required><span><?= fa_number($i) ?> ★</span></label><?php endfor; ?>
        </div>
      </fieldset>
      <fieldset class="filter-group"><legend>این پزشک را به دیگران پیشنهاد می‌کنید؟</legend>
        <div class="seg">
          <label><input type="radio" name="recommend" value="1" required><span>بله</span></label>
          <label><input type="radio" name="recommend" value="0"><span>خیر</span></label>
        </div>
      </fieldset>
      <label>توضیح (اختیاری، حداکثر ۶۰۰ نویسه)<textarea name="comment" maxlength="600" rows="3"></textarea></label>
      <button><?= icon('send') ?> ثبت نظر</button>
    </form>
  <?php endforeach; ?>
</section>
<?php if ($mine): ?>
<section class="card">
  <h2>نظرهای شما</h2>
  <ul class="review-list">
    <?php foreach ($mine as $r): ?>
      <li><strong><?= esc(doctor_display_name($r['doctor'])) ?></strong> <span class="stars"><?= str_repeat('★', (int) $r['rating']) . str_repeat('☆', 5 - (int) $r['rating']) ?></span> <span class="badge"><?= esc($labels[$r['status']] ?? $r['status']) ?></span>
        <?php if ($r['comment']): ?><p><?= esc($r['comment']) ?></p><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
