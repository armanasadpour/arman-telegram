<?php use function PM\Support\e; use PM\Support\Fa; $title = 'درخواست‌های بازبینی'; include __DIR__ . '/_top.php'; ?>
<h1>درخواست‌های بازبینی</h1>
<p class="small muted">کاربر تعلیق‌شده یا مسدودشده، پس از وارد کردن رمز درست، دلیل و مدت محدودیت را می‌بیند و می‌تواند درخواست بازبینی بفرستد. پذیرفتن درخواست، حساب را فوراً فعال می‌کند. پاسخ شما را کاربر در همان صفحه می‌بیند.</p>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<div class="row"><?php foreach (['open' => 'در انتظار', 'accepted' => 'پذیرفته', 'rejected' => 'رد شده', 'all' => 'همه'] as $k => $l): ?>
  <a class="btn sm <?= $status === $k ? '' : 'secondary' ?>" href="<?= e($base) ?>/appeals?status=<?= e($k) ?>"><?= e($l) ?></a><?php endforeach; ?></div>
<?php foreach ($list as $a): ?>
<div class="panel mt">
  <div class="row"><b><a href="<?= e($base) ?>/users/<?= (int)$a['user_id'] ?>"><?= e($a['display_name']) ?></a></b><span class="ltr small muted">@<?= e($a['username']) ?></span>
    <span class="tag <?= e($a['user_status']) ?>"><?= e($a['user_status']) ?></span><span class="small muted"><?= e(Fa::dateTime($a['created_at'])) ?></span></div>
  <?php if ($a['status_reason']): ?><p class="small">دلیل محدودیت: <?= e($a['status_reason']) ?><?= $a['status_until'] ? ' — تا ' . e(Fa::dateTime($a['status_until'])) : ' — دائمی' ?></p><?php endif; ?>
  <div class="alert"><?= nl2br(e($a['message'])) ?></div>
  <?php if ($a['status'] === 'open'): ?>
  <form method="post" action="<?= e($base) ?>/appeals/<?= (int)$a['id'] ?>"><?php include __DIR__ . '/../_csrf.php'; ?>
    <label class="field"><span>پاسخ به کاربر (اختیاری)</span><input type="text" name="reply" maxlength="1000"></label>
    <div class="row"><button class="btn" name="decision" value="accept">پذیرفتن و فعال‌سازی حساب</button><button class="btn danger" name="decision" value="reject">رد</button></div>
  </form>
  <?php else: ?><p class="small"><?= $a['status'] === 'accepted' ? 'پذیرفته شد' : 'رد شد' ?> توسط <span class="ltr"><?= e((string)$a['admin']) ?></span><?= $a['admin_reply'] ? ' — ' . e($a['admin_reply']) : '' ?></p><?php endif; ?>
</div>
<?php endforeach; if (!$list): ?><div class="panel mt muted">درخواستی نیست.</div><?php endif; ?>
<?php include __DIR__ . '/_bottom.php'; ?>
