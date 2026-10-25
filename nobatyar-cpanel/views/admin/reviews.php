<?php /** @var string $status @var array $counts @var array $rows @var array $replies */
$labels = ['pending' => 'در انتظار بررسی', 'approved' => 'منتشرشده', 'hidden' => 'پنهان'];
?>
<nav class="sub-tabs">
  <?php foreach ($labels as $k => $l): ?><a href="admin.php?tab=reviews&amp;status=<?= $k ?>"<?= $status === $k ? ' class="current" aria-current="page"' : '' ?>><?= esc($l) ?> (<?= fa_number((int) ($counts[$k] ?? 0)) ?>)</a><?php endforeach; ?>
</nav>
<section class="panel">
  <h2>نظرات بیماران</h2>
  <p class="muted small">هر نظر به یک نوبت «انجام‌شده» همان بیمار وابسته است؛ برای هر نوبت فقط یک نظر ثبت می‌شود. کنار نظر منتشرشده فقط نام کوچک بیمار نمایش داده می‌شود.</p>
  <?php if (!$rows): ?><p class="empty-state">نظری در این بخش نیست.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>پزشک</th><th>امتیاز</th><th>پیشنهاد</th><th>متن</th><th>بیمار</th><th>زمان</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= esc($r['doctor']) ?></td>
        <td><span class="stars"><?= str_repeat('★', (int) $r['rating']) ?></span> <?= fa_number($r['rating']) ?></td>
        <td><?= $r['recommend'] ? 'بله' : 'خیر' ?></td>
        <td><?= esc($r['comment'] ?? '—') ?></td>
        <td><?= esc(($r['first_name'] ?: '—') . ' • ') ?><bdi><?= esc(admin_sensitive() ? fa_number($r['mobile']) : mask_mobile($r['mobile'])) ?></bdi></td>
        <td><bdi><?= esc(jdate($r['created_at'], true)) ?></bdi></td>
        <td class="row-actions">
          <?php if ($r['status'] !== 'approved'): ?><form method="post" class="inline-form"><?php form_token('review_moderate'); ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="status" value="approved"><button class="small">انتشار</button></form><?php endif; ?>
          <?php if ($r['status'] !== 'hidden'): ?><form method="post" class="inline-form"><?php form_token('review_moderate'); ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="status" value="hidden"><button class="small danger">پنهان</button></form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php if ($replies): /* 1.0.30 */ ?>
<section class="panel">
  <h2>پاسخ پزشکان در انتظار تأیید (<?= fa_number(count($replies)) ?>)</h2>
  <p class="muted small">پاسخ پزشک پس از انتشار زیر همان نظر در صفحه پزشک نمایش داده می‌شود.</p>
  <div class="table-wrap"><table>
    <thead><tr><th>پزشک</th><th>نظر بیمار</th><th>پاسخ پزشک</th><th>زمان</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($replies as $r): ?>
      <tr>
        <td><?= esc(doctor_display_name($r['doctor'])) ?></td>
        <td><span class="stars"><?= str_repeat('★', (int) $r['rating']) ?></span> <?= esc($r['comment'] ?? '—') ?></td>
        <td><?= esc($r['reply']) ?></td>
        <td><bdi><?= esc(jdate($r['reply_at'], true)) ?></bdi></td>
        <td class="row-actions">
          <form method="post" class="inline-form"><?php form_token('review_reply_moderate'); ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="status" value="approved"><button class="small">انتشار</button></form>
          <form method="post" class="inline-form"><?php form_token('review_reply_moderate'); ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="status" value="hidden"><button class="small danger">پنهان</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
