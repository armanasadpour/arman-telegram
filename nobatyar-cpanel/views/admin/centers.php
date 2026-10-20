<?php
/** @var ?array $center */
$statusLabels = center_status_labels();
$kinds = center_kinds();
?>
<?php if (!empty($notFound)): ?>
  <p class="error">مرکز پیدا نشد.</p>
<?php elseif ($center): $c = $center; ?>
  <p><a href="admin.php?tab=centers">← بازگشت به فهرست مراکز</a></p>
  <div class="grid-2 align-start">
    <section class="panel">
      <h2><?= esc($c['name']) ?> <span class="badge <?= $c['review_status'] === 'approved' ? 'status-confirmed' : ($c['review_status'] === 'pending' ? 'status-held' : 'status-cancelled') ?>"><?= esc($statusLabels[$c['review_status']]) ?></span></h2>
      <dl class="facts">
        <dt>کد مرکز</dt><dd><bdi><?= fa_number($c['id']) ?></bdi></dd>
        <dt>نوع</dt><dd><?= esc($kinds[$c['kind']] ?? $c['kind']) ?></dd>
        <dt>شماره پروانه</dt><dd><bdi><?= esc(admin_identity($c['license_number'])) ?></bdi></dd>
        <dt>شناسه ملی</dt><dd><bdi><?= esc(admin_identity($c['national_id'] ?? '')) ?></bdi></dd>
        <dt>مدیر / صاحب پروانه</dt><dd><?= esc($c['manager_name']) ?></dd>
        <dt>مسئول فنی</dt><dd><?= esc($c['technical_manager'] ?: '—') ?> <?= $c['technical_code'] ? '(<bdi>' . esc(fa_number($c['technical_code'])) . '</bdi>)' : '' ?></dd>
        <dt>ثبت‌کننده</dt><dd><?= esc($c['owner_name'] ?: '—') ?> • <bdi><?= esc(admin_mobile($c['owner_mobile'])) ?></bdi></dd>
        <dt>نشانی</dt><dd><?= esc($c['province'] . '، ' . $c['city'] . '، ' . $c['address']) ?></dd>
        <dt>تلفن</dt><dd><bdi><?= esc(fa_number($c['phone'])) ?></bdi></dd>
        <dt>ثبت</dt><dd><bdi><?= esc(jdate($c['created_at'], true)) ?></bdi></dd>
      </dl>
      <?php if ($c['document_file']): ?><p><a class="button secondary" href="center-document.php?id=<?= (int) $c['id'] ?>" target="_blank" rel="noopener"><?= icon('eye') ?> مشاهده تصویر پروانه</a></p><?php endif; ?>
      <?php if ($c['review_note']): ?><p class="muted">یادداشت بررسی: <?= esc($c['review_note']) ?></p><?php endif; ?>
    </section>
    <section class="panel">
      <h2>بررسی و وضعیت</h2>
      <form method="post" class="stack">
        <?php form_token('center_review'); ?><input type="hidden" name="center_id" value="<?= (int) $c['id'] ?>">
        <label>وضعیت<select name="status"><?php foreach ($statusLabels as $k => $l): ?><option value="<?= $k ?>"<?= selected_attr($c['review_status'] === $k) ?>><?= esc($l) ?></option><?php endforeach; ?></select></label>
        <label>یادداشت (برای رد یا تعلیق الزامی است؛ به مرکز نمایش داده می‌شود)<textarea name="note" maxlength="2000"><?= esc($c['review_note'] ?? '') ?></textarea></label>
        <button>ثبت وضعیت</button>
      </form>
      <form method="post" class="inline-form">
        <?php form_token('center_active'); ?><input type="hidden" name="center_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="active" value="<?= $c['active'] ? '0' : '1' ?>">
        <span>انتشار: <?= $c['active'] ? 'فعال' : 'غیرفعال' ?></span>
        <button class="<?= $c['active'] ? 'danger' : '' ?> small"><?= $c['active'] ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?></button>
      </form>
      <h3>اشتراک مرکز: <?= esc($sub['label']) ?></h3>
      <p class="muted"><?= $sub['until'] ? 'اعتبار تا ' . esc(jdate($sub['until'])) : 'بدون اشتراک' ?></p>
      <form method="post" class="inline-form">
        <?php form_token('center_grant'); ?><input type="hidden" name="center_id" value="<?= (int) $c['id'] ?>">
        <label>روز<input type="number" name="days" min="1" max="3650" value="30" required></label>
        <label>نوع<select name="kind"><option value="gift">هدیه</option><option value="manual">تمدید دستی</option></select></label>
        <label>توضیح<input name="note" maxlength="255" required></label>
        <button class="small">ثبت</button>
      </form>
      <?php if ($plans && $c['review_status'] === 'approved'): ?>
        <form method="post" class="inline-form">
          <?php form_token('center_order'); ?><input type="hidden" name="center_id" value="<?= (int) $c['id'] ?>">
          <label>صدور پیش‌فاکتور<select name="plan_id"><?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>"><?= esc($p['name']) ?> — <?= esc(toman($p['price_toman'])) ?></option><?php endforeach; ?></select></label>
          <button class="small secondary">صدور</button>
        </form>
      <?php endif; ?>
    </section>
  </div>
  <section class="panel">
    <h2>سفارش‌های اشتراک</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>#</th><th>شماره</th><th>پلن</th><th>مبلغ</th><th>وضعیت</th><th>ثبت واریز</th></tr></thead>
      <tbody>
        <?php foreach ($orders as $o): ?>
          <tr><td><?= fa_number($o['id']) ?></td><td><a href="center-invoice.php?id=<?= (int) $o['id'] ?>" target="_blank"><bdi><?= esc($o['number'] ?: 'پیش‌فاکتور') ?></bdi></a></td><td><?= esc($o['plan_name']) ?></td><td><?= esc(toman($o['payable_toman'])) ?></td><td><?= esc(order_status_label($o['status'])) ?></td>
            <td><?php if ($o['status'] === 'proforma'): ?><form method="post" class="inline-form"><?php form_token('center_offline'); ?><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>"><input name="reference" placeholder="شماره پیگیری" maxlength="100" required><button class="small">ثبت پرداخت</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if (!$orders): ?><p class="muted">سفارشی ثبت نشده است.</p><?php endif; ?>
  </section>
  <div class="grid-2 align-start">
    <section class="panel">
      <h2>شعبه‌ها (<?= fa_number(count($branches)) ?>)</h2>
      <ul class="tx-list"><?php foreach ($branches as $b): ?><li><strong><?= esc($b['name']) ?></strong> • <?= esc($b['city']) ?> • <?= fa_number($b['rooms']) ?> اتاق<?= $b['active'] ? '' : ' • غیرفعال' ?></li><?php endforeach; ?></ul>
      <h2>کارکنان</h2>
      <ul class="tx-list"><?php foreach ($staffList as $m): ?><li><?= esc($m['name'] ?: '—') ?> • <bdi><?= esc(admin_mobile($m['mobile'])) ?></bdi> • <?= esc(center_roles()[$m['role']]['label'] ?? $m['role']) ?><?= $m['active'] ? '' : ' • غیرفعال' ?></li><?php endforeach; ?></ul>
    </section>
    <section class="panel">
      <h2>پزشکان</h2>
      <ul class="tx-list"><?php foreach ($links as $l): ?><li><a href="admin.php?tab=doctors&amp;doctor=<?= (int) $l['doctor_id'] ?>"><?= esc($l['doctor_name']) ?></a> • <?= esc(link_status_labels()[$l['status']]) ?></li><?php endforeach; ?></ul>
      <?php if (!$links): ?><p class="muted">پزشکی متصل نیست.</p><?php endif; ?>
    </section>
  </div>
  <section class="panel">
    <h2>رویدادهای اخیر</h2>
    <ul class="tx-list"><?php foreach ($events as $e): ?><li><bdi><?= esc(jdate($e['created_at'], true)) ?></bdi> • <?= esc(center_event_label($e['event_type'])) ?> • <?= esc($e['actor'] ?: ($e['actor_admin_id'] ? 'مدیر سامانه' : 'سامانه')) ?></li><?php endforeach; ?></ul>
  </section>
<?php else: ?>
  <div class="stat-grid">
    <?php foreach ($statusLabels as $k => $l): ?>
      <a class="stat-card" href="admin.php?tab=centers&amp;status=<?= $k ?>"><span><?= esc($l) ?></span><strong><?= fa_number($counts[$k] ?? 0) ?></strong></a>
    <?php endforeach; ?>
  </div>
  <form method="get" class="filter-bar panel">
    <input type="hidden" name="tab" value="centers">
    <label>جستجو<input name="q" value="<?= esc($q) ?>" placeholder="نام، شماره پروانه یا کد مرکز"></label>
    <label>وضعیت<select name="status"><option value="">همه</option><?php foreach ($statusLabels as $k => $l): ?><option value="<?= $k ?>"<?= selected_attr($status === $k) ?>><?= esc($l) ?></option><?php endforeach; ?></select></label>
    <button><?= icon('filter') ?> نمایش</button>
  </form>
  <section class="panel">
    <div class="table-wrap"><table>
      <thead><tr><th>کد</th><th>مرکز</th><th>شهر</th><th>پروانه</th><th>شعبه / پزشک</th><th>اشتراک</th><th>وضعیت</th></tr></thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= fa_number($r['id']) ?></td>
            <td><a href="admin.php?tab=centers&amp;center=<?= (int) $r['id'] ?>"><?= esc($r['name']) ?></a><br><small><?= esc($kinds[$r['kind']] ?? '') ?></small></td>
            <td><?= esc($r['city']) ?></td>
            <td><bdi><?= esc(admin_identity($r['license_number'])) ?></bdi></td>
            <td><?= fa_number($r['branches']) ?> / <?= fa_number($r['doctors']) ?></td>
            <td><?= $r['subscription_until'] ? esc(jdate($r['subscription_until'])) : '—' ?></td>
            <td><span class="badge <?= $r['review_status'] === 'approved' ? 'status-confirmed' : ($r['review_status'] === 'pending' ? 'status-held' : 'status-cancelled') ?>"><?= esc($statusLabels[$r['review_status']]) ?></span><?= $r['active'] ? '' : ' <span class="badge">غیرفعال</span>' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if (!$rows): ?><p class="empty-state">مرکزی با این شرایط پیدا نشد.</p><?php endif; ?>
  </section>
  <section class="panel narrow">
    <h2>تنظیمات مراکز درمانی</h2>
    <form method="post" class="stack">
      <?php form_token('center_settings'); ?>
      <label class="check"><input type="checkbox" name="center_registration_enabled" value="1"<?= checked_attr(setting('center_registration_enabled', '1') === '1') ?>> ثبت مرکز جدید توسط کاربران فعال باشد</label>
      <p class="notice">کلینیک‌ها فقط با اشتراک پرو فعالیت می‌کنند؛ نسخه رایگان و دوره آزمایشی ندارند.</p>
      <div class="form-grid">
        <?php input('دوره فرجه پس از پایان اشتراک (روز)', 'center_grace_days', setting('center_grace_days', '3'), 'number', true, 2); ?>
      </div>
      <p class="muted">قیمت پلن‌های مراکز در «اشتراک‌ها و تعرفه‌ها ← پلن‌ها» با مخاطب «کلینیک‌ها و مراکز درمانی» تعیین می‌شود.</p>
      <button>ذخیره</button>
    </form>
  </section>
<?php endif; ?>
