<?php /** @var array $rows @var string $category @var array $prefs @var array $devices @var bool $pushAvailable @var int $unread @var string $error */
$cats = notification_categories();
?>
<div class="notify-layout">
  <section>
    <div class="between">
      <h1><?= icon('bell') ?> اعلان‌ها <?php if ($unread): ?><span class="badge status-held"><?= fa_number($unread) ?> خوانده‌نشده</span><?php endif; ?></h1>
      <?php if ($unread): ?><form method="post"><?php form_token('read_all'); ?><button class="secondary small">همه را خواندم</button></form><?php endif; ?>
    </div>
    <?php if ($error): ?><p class="error" role="alert"><?= esc($error) ?></p><?php endif; ?>
    <nav class="chip-row" aria-label="دسته اعلان">
      <a class="chip<?= $category === '' ? ' current' : '' ?>" href="notifications.php">همه</a>
      <?php foreach ($cats as $k => $l): ?><a class="chip<?= $category === $k ? ' current' : '' ?>" href="notifications.php?category=<?= $k ?>"><?= esc($l) ?></a><?php endforeach; ?>
    </nav>
    <?php foreach ($rows as $n): ?>
      <article class="card notification <?= $n['read_at'] ? 'read' : 'unread' ?>">
        <div class="between"><h3><?= esc($n['title']) ?></h3><small><bdi><?= esc(jdate($n['created_at'], true)) ?></bdi></small></div>
        <p><?= esc($n['body']) ?></p>
        <div class="actions">
          <span class="badge"><?= esc($cats[$n['category'] ?? 'general'] ?? '') ?></span>
          <?php if (!empty($n['url'])): ?><a href="<?= esc($n['url']) ?>">مشاهده ←</a><?php endif; ?>
          <?php if (!$n['read_at']): ?><form method="post"><?php form_token('read'); ?><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><button class="secondary small">خواندم</button></form><?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
    <?php if (!$rows): ?><p class="empty-state">اعلانی وجود ندارد.</p><?php endif; ?>
  </section>
  <aside>
    <section class="card push-card" data-push-card>
      <h2><?= icon('bell') ?> اعلان مرورگر</h2>
      <?php if (!$pushAvailable): ?>
        <p class="muted">اعلان مرورگر در این سامانه فعال نیست.</p>
      <?php else: ?>
        <p class="muted">حتی وقتی نوبت‌یار باز نیست، یادآوری‌ها و تغییرات نوبت روی این دستگاه نمایش داده می‌شود. در اعلان فقط عنوان کوتاه می‌آید و جزئیات پزشکی فقط داخل حساب شما دیده می‌شود.</p>
        <label class="check"><input type="checkbox" data-push-consent> با دریافت اعلان مرورگر روی این دستگاه موافقم.</label>
        <div class="actions">
          <button type="button" data-push-enable disabled><?= icon('bell') ?> فعال‌سازی روی این دستگاه</button>
          <button type="button" class="secondary" data-push-disable hidden>غیرفعال‌سازی این دستگاه</button>
        </div>
        <p class="muted small" data-push-status role="status"></p>
        <?php if ($devices): ?>
          <p class="small"><?= fa_number(count($devices)) ?> دستگاه فعال • رضایت از <?= esc(jdate($prefs['push_consent_at'] ?? null, true)) ?></p>
          <form method="post" data-confirm="اعلان مرورگر برای همه دستگاه‌ها غیرفعال شود؟"><?php form_token('push_off'); ?><button class="secondary small">غیرفعال‌سازی همه دستگاه‌ها</button></form>
        <?php endif; ?>
      <?php endif; ?>
    </section>
    <section class="card">
      <h2>تنظیمات دریافت</h2>
      <form method="post" class="stack">
        <?php form_token('preferences'); ?>
        <div class="table-wrap"><table class="pref-table">
          <thead><tr><th>نوع اعلان</th><th>اعلان مرورگر</th><th>پیامک</th></tr></thead>
          <tbody>
            <?php foreach ($cats as $k => $l): ?>
              <tr>
                <td><?= esc($l) ?></td>
                <td><input type="checkbox" name="push_<?= $k ?>" value="1" aria-label="اعلان مرورگر: <?= esc($l) ?>"<?= checked_attr(!empty($prefs['push_' . $k])) ?>></td>
                <td><?php if (in_array($k, sms_optional_categories(), true)): ?><input type="checkbox" name="sms_<?= $k ?>" value="1" aria-label="پیامک: <?= esc($l) ?>"<?= checked_attr(!empty($prefs['sms_' . $k])) ?>><?php else: ?><small class="muted">همیشه</small><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table></div>
        <p class="muted small">پیامک تأیید، لغو و جابه‌جایی نوبت و کد ورود همیشه ارسال می‌شود. اعلان داخل حساب همیشه ثبت می‌شود.<?= $prefs['sms_consent_at'] ? ' رضایت پیامک از ' . esc(jdate($prefs['sms_consent_at'], true)) . '.' : '' ?></p>
        <button>ذخیره تنظیمات</button>
      </form>
    </section>
  </aside>
</div>
