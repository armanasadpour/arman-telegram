<?php
/** @var ?array $ticket @var array $admins @var array $filters @var array $rows @var int $total @var int $page @var array $counts */
$statuses = ticket_status_labels();
$cats = ticket_categories();
$prio = ticket_priorities();
?>
<?php if (!empty($notFound)): ?>
  <p class="empty-state">تیکت پیدا نشد. <a href="admin.php?tab=support">بازگشت به فهرست</a></p>
<?php elseif ($ticket): ?>
  <p><a href="admin.php?tab=support">← همه تیکت‌ها</a></p>
  <div class="ticket-layout">
    <section class="panel">
      <div class="panel-head">
        <h2>#<?= fa_number($ticket['id']) ?> — <?= esc($ticket['subject']) ?></h2>
        <span class="badge ticket-<?= esc($ticket['status']) ?>"><?= esc($statuses[$ticket['status']]) ?></span>
      </div>
      <p class="muted small"><?= esc($ticket['user_name'] ?: 'کاربر') ?> • <bdi><?= admin_mobile($ticket['user_mobile']) ?></bdi> • <?= esc($cats[$ticket['category']] ?? '') ?> • ثبت: <bdi><?= esc(jdate($ticket['created_at'], true)) ?></bdi></p>
      <ol class="thread">
        <?php foreach ($ticket['messages'] as $m): ?>
          <li class="<?= $m['admin_id'] ? 'from-support' : 'from-user' ?>">
            <header><strong><?= $m['admin_id'] ? 'پشتیبانی' . ($m['admin_name'] ? ' — ' . esc($m['admin_name']) : '') : esc($ticket['user_name'] ?: 'کاربر') ?></strong><small><bdi><?= esc(jdate($m['created_at'], true)) ?></bdi></small></header>
            <p><?= nl2br(esc($m['body'])) ?></p>
          </li>
        <?php endforeach; ?>
      </ol>
      <form method="post" class="stack">
        <?php form_token('ticket_reply'); ?>
        <input type="hidden" name="ticket_id" value="<?= (int) $ticket['id'] ?>">
        <label>پاسخ پشتیبانی<textarea name="body" required minlength="10" maxlength="3000"></textarea></label>
        <label class="check"><input type="checkbox" name="close" value="1"> پس از ارسال، تیکت بسته شود</label>
        <button><?= icon('chat') ?> ارسال پاسخ</button>
      </form>
    </section>
    <aside class="panel">
      <h2>وضعیت تیکت</h2>
      <form method="post" class="stack">
        <?php form_token('ticket_update'); ?>
        <input type="hidden" name="ticket_id" value="<?= (int) $ticket['id'] ?>">
        <label>وضعیت<select name="status"><?php foreach ($statuses as $k => $l): ?><option value="<?= $k ?>"<?= selected_attr($ticket['status'] === $k) ?>><?= esc($l) ?></option><?php endforeach; ?></select></label>
        <label>اولویت<select name="priority"><?php foreach ($prio as $k => $l): ?><option value="<?= $k ?>"<?= selected_attr($ticket['priority'] === $k) ?>><?= esc($l) ?></option><?php endforeach; ?></select></label>
        <label>مسئول پیگیری<select name="assigned_admin_id"><option value="">بدون مسئول</option><?php foreach ($admins as $a): ?><option value="<?= (int) $a['id'] ?>"<?= selected_attr((int) $ticket['assigned_admin_id'] === (int) $a['id']) ?>><?= esc($a['name'] ?: $a['email']) ?></option><?php endforeach; ?></select></label>
        <button class="secondary">ذخیره</button>
      </form>
    </aside>
  </div>
<?php else: ?>
  <form method="get" class="filter-bar panel">
    <input type="hidden" name="tab" value="support">
    <label>وضعیت<select name="status">
      <option value="active"<?= selected_attr($filters['status'] === 'active') ?>>باز و پاسخ‌داده</option>
      <?php foreach ($statuses as $k => $l): ?><option value="<?= $k ?>"<?= selected_attr($filters['status'] === $k) ?>><?= esc($l) ?> (<?= fa_number($counts[$k] ?? 0) ?>)</option><?php endforeach; ?>
      <option value="all"<?= selected_attr($filters['status'] === 'all') ?>>همه</option>
    </select></label>
    <label>دسته<select name="category"><option value="">همه دسته‌ها</option><?php foreach ($cats as $k => $l): ?><option value="<?= $k ?>"<?= selected_attr($filters['category'] === $k) ?>><?= esc($l) ?></option><?php endforeach; ?></select></label>
    <label>جست‌وجو<input name="q" value="<?= esc($filters['q']) ?>" placeholder="موضوع، شماره تیکت یا موبایل"></label>
    <button><?= icon('search') ?> نمایش</button>
  </form>
  <section class="panel">
    <div class="panel-head"><h2>تیکت‌ها</h2><span class="muted">تعداد: <?= fa_number($total) ?></span></div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>شماره</th><th>موضوع</th><th>کاربر</th><th>دسته</th><th>اولویت</th><th>وضعیت</th><th>آخرین تغییر</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $t): ?>
          <tr class="<?= $t['status'] === 'open' ? 'row-attention' : '' ?>">
            <td><a href="admin.php?tab=support&amp;ticket=<?= (int) $t['id'] ?>">#<?= fa_number($t['id']) ?></a></td>
            <td><a href="admin.php?tab=support&amp;ticket=<?= (int) $t['id'] ?>"><?= esc($t['subject']) ?></a><br><small><?= fa_number($t['messages']) ?> پیام</small></td>
            <td><?= esc($t['user_name'] ?: '—') ?><br><bdi><?= admin_mobile($t['user_mobile']) ?></bdi></td>
            <td><?= esc($cats[$t['category']] ?? '') ?></td>
            <td><span class="badge prio-<?= esc($t['priority']) ?>"><?= esc($prio[$t['priority']] ?? '') ?></span></td>
            <td><span class="badge ticket-<?= esc($t['status']) ?>"><?= esc($statuses[$t['status']] ?? '') ?></span></td>
            <td><bdi><?= esc(jdate($t['updated_at'], true)) ?></bdi></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (!$rows): ?><p class="empty-state">تیکتی با این فیلترها نیست.</p><?php endif; ?>
    <?= pagination($page, $total, 30, ['tab' => 'support'] + $filters) ?>
  </section>
<?php endif; ?>
