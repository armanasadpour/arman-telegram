<?php use function PM\Support\e; use PM\Moderation\PolicyService as P;
/** @var string $action  @var list<string> $keys  @var list<array> $current */
$off = array_column($current, null, 'service'); ?>
<form method="post" action="<?= e($base . $action) ?>">
  <?php include __DIR__ . '/../_csrf.php'; ?>
  <div class="perm-grid">
    <?php foreach ($keys as $k): $p = $off[$k] ?? null; ?>
      <label class="check"><input type="checkbox" name="off[]" value="<?= e($k) ?>" <?= $p ? 'checked' : '' ?>>
        <span><b><?= e(P::SERVICES[$k]) ?></b> غیرفعال<?php if ($p): ?> <span class="small muted">(<?= $p['until_at'] ? 'تا ' . e(\PM\Support\Fa::dateTime($p['until_at'])) : 'دائمی' ?>)</span><?php endif; ?></span></label>
    <?php endforeach; ?>
  </div>
  <div class="grid2">
    <label class="field"><span>مدت (برای موارد تیک‌خورده)</span><select name="duration"><?php foreach (P::DURATIONS as $dk => $dl): ?><option value="<?= e($dk) ?>"><?= e($dl) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>دلیل (به کاربر نمایش داده نمی‌شود)</span><input type="text" name="reason" maxlength="300"></label>
  </div>
  <button class="btn sm">ذخیره</button>
</form>
