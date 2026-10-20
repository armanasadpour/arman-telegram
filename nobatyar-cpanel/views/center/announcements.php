<?php /** @var array $c @var array $s @var array $branches @var array $doctors @var array $history @var bool $smsReady @var array $post */ ?>
<div class="two-column">
  <article class="card">
    <h2>اطلاعیه جدید</h2>
    <p class="muted">فقط بیماران دارای نوبت قطعی آینده، نزد پزشکانی که اجازه تماس با بیماران را داده‌اند، اطلاعیه را دریافت می‌کنند. برای هر نوبت یک اعلان داخل برنامه و برای هر شماره حداکثر یک پیامک ثبت می‌شود.</p>
    <form method="post" class="stack" data-confirm="اطلاعیه برای بیماران ارسال شود؟">
      <?php form_token('announce'); ?>
      <div class="form-grid">
        <?php if (!$s['branch_id']): ?>
          <label>شعبه<select name="branch_id"><option value="">همه شعبه‌ها</option><?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"<?= selected_attr((int) ($post['branch_id'] ?? 0) === (int) $b['id']) ?>><?= esc($b['name']) ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <label>پزشک<select name="doctor_id"><option value="">همه پزشکان</option><?php foreach ($doctors as $d): ?><option value="<?= (int) $d['id'] ?>"<?= selected_attr((int) ($post['doctor_id'] ?? 0) === (int) $d['id']) ?>><?= esc($d['name']) ?></option><?php endforeach; ?></select></label>
        <?php input('نوبت‌ها از تاریخ', 'date_from', $post['date_from'] ?? jdate(date('Y-m-d')), 'text', true, 20, 'data-jdate'); ?>
        <?php input('تا تاریخ', 'date_to', $post['date_to'] ?? jdate(date('Y-m-d', strtotime('+7 days'))), 'text', true, 20, 'data-jdate'); ?>
      </div>
      <?php input('عنوان', 'title', $post['title'] ?? '', 'text', true, 120); ?>
      <label>متن اطلاعیه<textarea name="body" maxlength="1000" required><?= esc($post['body'] ?? '') ?></textarea></label>
      <label class="check"><input type="checkbox" name="send_sms" value="1"<?= $smsReady ? checked_attr(!empty($post['send_sms'])) : ' disabled' ?>> ارسال پیامک هم انجام شود</label>
      <?php if (!$smsReady): ?><p class="muted small">الگوی پیامک «اطلاعیه مرکز درمانی» در پنل مدیرکل فعال نیست؛ فقط اعلان داخل برنامه ارسال می‌شود.</p><?php else: ?><p class="muted small">در پیامک فقط تاریخ و ساعت نوبت، نام مرکز و عنوان اطلاعیه ارسال می‌شود؛ متن کامل در پنل بیمار است.</p><?php endif; ?>
      <button><?= icon('megaphone') ?> ارسال اطلاعیه</button>
    </form>
  </article>
  <article class="card">
    <h2>اطلاعیه‌های قبلی</h2>
    <?php foreach ($history as $h): ?>
      <div class="announcement">
        <strong><?= esc($h['title']) ?></strong>
        <p><?= nl2br(esc($h['body'])) ?></p>
        <small class="muted"><?= esc(jdate($h['created_at'], true)) ?> • <?= esc($h['sender'] ?: '—') ?> • <?= esc($h['branch'] ?: 'همه شعبه‌ها') ?> • <?= esc($h['doctor'] ?: 'همه پزشکان') ?> • نوبت‌های <?= esc(jdate($h['date_from'])) ?> تا <?= esc(jdate($h['date_to'])) ?> • <?= fa_number($h['recipients']) ?> نوبت<?= $h['send_sms'] ? '، ' . fa_number($h['sms_queued']) . ' پیامک' : '' ?></small>
      </div>
    <?php endforeach; ?>
    <?php if (!$history): ?><p class="muted">هنوز اطلاعیه‌ای ارسال نشده است.</p><?php endif; ?>
  </article>
</div>
