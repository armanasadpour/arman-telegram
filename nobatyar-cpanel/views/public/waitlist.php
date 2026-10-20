<?php /** @var array $entries */ $labels = waitlist_status_labels(); ?>
<p class="muted">اگر برای پزشکی زمان آزاد پیدا نکردید، از صفحه پزشک «خبرم کن» را بزنید. با آزادشدن ظرفیت در بازه انتخابی، به ترتیب ثبت درخواست اعلان (و در صورت تمایل پیامک) می‌گیرید. اعلان، زمان را برای شما نگه نمی‌دارد؛ هر کس زودتر رزرو کند زمان را می‌گیرد.</p>
<?php foreach ($entries as $w): ?>
  <article class="appointment-card">
    <div class="appointment-time"><strong><?= esc(jdate($w['date_from'])) ?></strong><small>تا <?= esc(jdate($w['date_to'])) ?></small></div>
    <div class="appointment-info">
      <h3><?= esc(doctor_display_name($w['doctor'])) ?></h3>
      <p><?= esc($w['patient_name'] . ' • ' . $w['clinic'] . ' • ' . ($w['service'] ?? '')) ?></p>
      <small><?= $w['notify_count'] ? 'آخرین اطلاع‌رسانی: ' . esc(jdate($w['last_notified_at'], true)) . ' • ' . fa_number($w['notify_count']) . ' بار' : 'هنوز ظرفیتی آزاد نشده است.' ?></small>
    </div>
    <span class="badge <?= $w['status'] === 'waiting' ? 'status-held' : ($w['status'] === 'booked' ? 'status-confirmed' : '') ?>"><?= esc($labels[$w['status']] ?? $w['status']) ?></span>
    <?php if ($w['status'] === 'waiting'): ?>
      <a class="button secondary" href="doctor.php?id=<?= (int) $w['doctor_id'] ?>&amp;clinic=<?= (int) $w['clinic_id'] ?>">بررسی زمان‌ها</a>
      <form method="post"><?php form_token('waitlist_cancel'); ?><input type="hidden" name="id" value="<?= (int) $w['id'] ?>"><button class="secondary">خروج از صف</button></form>
    <?php endif; ?>
  </article>
<?php endforeach; ?>
<?php if (!$entries): ?><p class="empty-state">در صف انتظار هیچ پزشکی نیستید.</p><?php endif; ?>
