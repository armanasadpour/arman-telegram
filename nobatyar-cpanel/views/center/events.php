<?php /** @var array $events */ ?>
<div class="table-wrap panel"><table>
  <thead><tr><th>زمان</th><th>رویداد</th><th>انجام‌دهنده</th><th>جزئیات</th></tr></thead>
  <tbody>
    <?php foreach ($events as $e): $d = json_decode($e['details'], true) ?: []; ?>
      <tr><td><bdi><?= esc(jdate($e['created_at'], true)) ?></bdi></td><td><?= esc(center_event_label($e['event_type'])) ?></td><td><?= esc($e['actor'] ?: ($e['actor_admin_id'] ? 'مدیر سامانه' : 'سامانه')) ?></td>
        <td><small><?= esc(implode(' • ', array_map(fn($k, $v) => $k . ': ' . (is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)), array_keys($d), $d))) ?></small></td></tr>
    <?php endforeach; ?>
  </tbody>
</table></div>
<?php if (!$events): ?><p class="empty-state">رویدادی ثبت نشده است.</p><?php endif; ?>
