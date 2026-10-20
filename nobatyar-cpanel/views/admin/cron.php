<?php /** @var array $tasks @var array $status @var string $token @var string $lastRun */ ?>
<section class="panel">
  <h2>تنظیم Cron Job در cPanel</h2>
  <p>در cPanel بخش <strong>Cron Jobs</strong> یک وظیفه با فاصله هر ۵ دقیقه (<code dir="ltr">*/5 * * * *</code>) بسازید و یکی از دستورهای زیر را وارد کنید. نیازی به SSH نیست.</p>
  <h3>روش اول (پیشنهادی): اجرای مستقیم PHP</h3>
  <pre class="code" dir="ltr">php -q <?= esc(dirname(__DIR__, 2)) ?>/cron.php</pre>
  <p class="muted">اگر هاست چند نسخه PHP دارد، مسیر کامل آن را به‌کار ببرید؛ مثلاً <code dir="ltr">/usr/local/bin/php</code> یا <code dir="ltr">/opt/cpanel/ea-php82/root/usr/bin/php</code>.</p>
  <h3>روش دوم: فراخوانی نشانی</h3>
  <pre class="code" dir="ltr">wget -q -O /dev/null "<?= esc(app_base_url()) ?>cron.php?token=<?= esc($token) ?>"</pre>
  <p class="muted">این نشانی محرمانه است. اگر لو رفت، مقدار cron_token را در config.php عوض کنید.</p>
  <p>آخرین اجرا: <strong><?= $lastRun ? esc(jdate($lastRun, true)) : 'هنوز اجرا نشده' ?></strong></p>
  <form method="post" class="inline-form"><?php form_token('cron_run'); ?><button class="secondary"><?= icon('refresh') ?> اجرای دستی همین حالا</button></form>
</section>
<section class="panel">
  <h2>وظایف زمان‌بندی‌شده</h2>
  <div class="table-wrap">
    <table>
      <thead><tr><th>وظیفه</th><th>آخرین اجرا</th><th>وضعیت</th><th>نتیجه</th><th>دفعات</th></tr></thead>
      <tbody>
      <?php foreach ($tasks as $name => $t): $s = $status[$name] ?? null; ?>
        <tr>
          <td><?= esc($t['label']) ?></td>
          <td><bdi><?= $s && $s['last_finished_at'] ? esc(jdate($s['last_finished_at'], true)) : '—' ?></bdi></td>
          <td><?= !$s ? '<span class="badge">اجرا نشده</span>' : ($s['last_status'] === 'ok' ? '<span class="badge status-confirmed">موفق</span>' : '<span class="badge status-cancelled">' . esc($s['last_status'] === 'running' ? 'در حال اجرا' : 'خطا') . '</span>') ?></td>
          <td><small><?= esc($s['last_message'] ?? '') ?></small></td>
          <td><?= fa_number($s['runs'] ?? 0) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
