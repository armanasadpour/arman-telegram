<?php /** @var array $filters @var array $rows @var int $page @var int $total @var array $admins @var array $prefixes */
$query = ['tab' => 'audit', 'from' => jdate($filters['from']), 'to' => jdate($filters['to']), 'actor' => $filters['actor'], 'action_prefix' => $filters['action_prefix']]; ?>
<form method="get" class="filter-bar panel">
  <input type="hidden" name="tab" value="audit">
  <label>از تاریخ<input data-jdate name="from" value="<?= esc(jdate($filters['from'])) ?>" dir="ltr" maxlength="10"></label>
  <label>تا تاریخ<input data-jdate name="to" value="<?= esc(jdate($filters['to'])) ?>" dir="ltr" maxlength="10"></label>
  <label>انجام‌دهنده<select name="actor">
    <option value="">همه</option>
    <option value="admins"<?= selected_attr($filters['actor'] === 'admins') ?>>همه مدیران</option>
    <option value="users"<?= selected_attr($filters['actor'] === 'users') ?>>کاربران</option>
    <?php foreach ($admins as $a): ?><option value="<?= (int) $a['id'] ?>"<?= selected_attr($filters['actor'] === (string) $a['id']) ?>><?= esc($a['name'] ?: $a['email']) ?></option><?php endforeach; ?>
  </select></label>
  <label>نوع رویداد<select name="action_prefix"><option value="">همه</option><?php foreach ($prefixes as $k => $l): ?><option value="<?= esc($k) ?>"<?= selected_attr($filters['action_prefix'] === $k) ?>><?= esc($l) ?></option><?php endforeach; ?></select></label>
  <button><?= icon('filter') ?> نمایش</button>
  <?php if (admin_can('export')): ?><a class="button secondary" href="admin.php?<?= esc(http_build_query($query + ['export' => 'xlsx'])) ?>"><?= icon('download') ?> اکسل</a><?php endif; ?>
</form>
<section class="panel">
  <div class="panel-head"><h2>رویدادهای حساب، دسترسی و تنظیمات</h2><span class="muted">تعداد: <?= fa_number($total) ?></span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>رویداد</th><th>انجام‌دهنده</th><th>شناسه مورد</th><th>جزئیات</th><th>زمان</th><?php if (admin_sensitive()): ?><th>IP</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $e): ?>
        <tr class="<?= $e['action'] === 'admin.denied' ? 'row-attention' : '' ?>">
          <td><?= esc(audit_label($e['action'])) ?></td>
          <td><?php if ($e['actor_admin_id']): ?>مدیر: <?= esc($e['admin_name'] ?: ($e['admin_email'] ?? '#' . $e['actor_admin_id'])) ?><?php elseif ($e['actor_user_id']): ?><a href="admin.php?tab=users&amp;user=<?= (int) $e['actor_user_id'] ?>"><bdi><?= admin_mobile($e['user_mobile']) ?></bdi></a><?php else: ?>سامانه<?php endif; ?></td>
          <td><?= $e['entity_id'] ? fa_number($e['entity_id']) : '—' ?></td>
          <td><small><?= esc($e['detail'] ?? '') ?></small></td>
          <td><bdi><?= esc(jdate($e['created_at'], true)) ?></bdi></td>
          <?php if (admin_sensitive()): ?><td><small dir="ltr"><?= esc($e['ip'] ?? '') ?></small></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (!$rows): ?><p class="empty-state">رویدادی در این بازه ثبت نشده است.</p><?php endif; ?>
  <?= pagination($page, $total, 50, $query) ?>
</section>
