<?php use function PM\Support\e; use PM\Support\Fa; $title = 'نشست‌ها'; $nav = 'sessions'; include __DIR__ . '/../_head.php'; include __DIR__ . '/_nav.php'; ?>
<main class="page">
  <h1>نشست‌ها و دستگاه‌ها</h1>
  <div class="panel">
    <div class="table-wrap"><table>
      <tr><th>دستگاه</th><th>IP (کوتاه‌شده)</th><th class="hide-sm">ورود</th><th>آخرین فعالیت</th><th></th></tr>
      <?php foreach ($sessions as $s): ?>
      <tr>
        <td><?= e($s['user_agent_summary']) ?></td>
        <td class="ltr"><?= e($s['ip_display']) ?></td>
        <td class="hide-sm"><?= e(Fa::dateTime($s['created_at'])) ?></td>
        <td><?= e(Fa::dateTime($s['last_used_at'])) ?></td>
        <td><?php if ($s['public_id'] === $current): ?><span class="tag ok">همین دستگاه</span><?php else: ?>
          <form method="post" action="<?= e($base) ?>/sessions/revoke" class="inline"><?php include __DIR__ . '/../_csrf.php'; ?>
            <input type="hidden" name="session" value="<?= e($s['public_id']) ?>"><button class="btn danger sm">خروج</button></form>
        <?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <?php if (count($sessions) > 1): ?>
    <form method="post" action="<?= e($base) ?>/sessions/revoke-others" class="mt"><?php include __DIR__ . '/../_csrf.php'; ?><button class="btn danger">خروج از همه دستگاه‌های دیگر</button></form>
    <?php endif; ?>
  </div>
  <p class="small muted">در فاز ۳، هر دستگاه کلید رمزنگاری مخصوص خود را خواهد داشت و افزودن دستگاه جدید به مخاطبان اطلاع داده می‌شود.</p>
</main>
</body></html>
