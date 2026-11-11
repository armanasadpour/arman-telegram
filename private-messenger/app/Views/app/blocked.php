<?php use function PM\Support\e; use PM\Support\Fa; $title = 'حساب غیرفعال'; include __DIR__ . '/../_head.php';
$st = ['suspended' => 'تعلیق', 'blocked' => 'مسدود'][$u['status']] ?? $u['status']; ?>
<main class="center"><div class="card">
  <h2>حساب شما <?= e($st) ?> شده است</h2>
  <p><?= $u['status_until'] ? 'تا ' . e(Fa::dateTime($u['status_until'])) . ' (پس از آن خودکار فعال می‌شود)' : 'به‌صورت دائمی' ?></p>
  <?php if ($u['status_reason']): ?><div class="alert"><b>دلیل:</b> <?= e($u['status_reason']) ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="alert ok" role="status"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($last && $last['status'] === 'open'): ?>
    <div class="alert">درخواست بازبینی شما (<?= e(Fa::dateTime($last['created_at'])) ?>) در حال بررسی است.</div>
  <?php else: ?>
    <?php if ($last): ?><div class="alert <?= $last['status'] === 'rejected' ? 'warn' : 'ok' ?>">پاسخ به درخواست قبلی: <?= $last['status'] === 'rejected' ? 'رد شد' : 'پذیرفته شد' ?><?= $last['admin_reply'] ? ' — ' . e($last['admin_reply']) : '' ?></div><?php endif; ?>
    <form method="post" action="<?= e($base) ?>/appeal">
      <?php include __DIR__ . '/../_csrf.php'; ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <label class="field"><span>درخواست بازبینی (توضیح دهید چرا باید حساب شما دوباره فعال شود)</span>
        <textarea name="message" rows="5" maxlength="2000" required minlength="10"></textarea></label>
      <button class="btn block">ارسال درخواست بازبینی</button>
    </form>
  <?php endif; ?>
</div></main>
</body></html>
