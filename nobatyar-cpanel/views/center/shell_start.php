<?php
/** @var array $a @var array $tabs @var string $tab @var array $mine @var callable $allowed @var string $error */
$c = $a['center'];
$sub = center_subscription_state($c);
$roles = center_roles();
?>
<div class="workspace center-workspace">
  <aside class="doctor-sidebar center-sidebar">
    <a class="brand-mark" href="center.php?center=<?= (int) $c['id'] ?>">نوبت‌یار <small>پنل مرکز درمانی</small></a>
    <div class="identity">
      <?php if ($c['logo_file']): ?>
        <img class="doctor-avatar" src="media.php?kind=center&amp;file=<?= esc($c['logo_file']) ?>" alt="">
      <?php else: ?>
        <span class="doctor-avatar avatar-placeholder" aria-hidden="true"><?= icon('building') ?></span>
      <?php endif; ?>
      <strong><?= esc($c['name']) ?></strong>
      <small><?= esc($roles[$a['staff']['role']]['label'] ?? 'کارمند') ?><?= $a['staff']['branch_id'] ? ' • یک شعبه' : '' ?></small>
    </div>
    <?php if (count($mine) > 1): ?>
      <form method="get" class="center-switch">
        <label class="sr-only" for="center-switch">تغییر مرکز</label>
        <select id="center-switch" name="center" data-autosubmit>
          <?php foreach ($mine as $m): ?><option value="<?= (int) $m['id'] ?>"<?= selected_attr((int) $m['id'] === (int) $c['id']) ?>><?= esc($m['name']) ?></option><?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
    <nav aria-label="پنل مرکز">
      <?php foreach ($tabs as $key => $t): if (!$allowed($t['perm'])) { continue; } ?>
        <a <?= $tab === $key ? 'class="current" aria-current="page"' : '' ?> href="center.php?center=<?= (int) $c['id'] ?>&amp;tab=<?= $key ?>"><?= icon($t['icon']) ?> <?= esc($t['label']) ?></a>
      <?php endforeach; ?>
    </nav>
    <a class="back-account" href="panel.php">بازگشت به حساب من</a>
  </aside>
  <section class="doctor-content">
    <div class="workspace-heading">
      <div><span class="eyebrow"><?= esc(center_kinds()[$c['kind']] ?? '') ?> • <?= esc(jdate(date('Y-m-d'))) ?></span><h1><?= esc($tabs[$tab]['label']) ?></h1></div>
      <?php if (center_is_open($c)): ?><a class="button secondary" href="clinic.php?id=<?= (int) $c['id'] ?>">صفحه عمومی مرکز ↗</a><?php endif; ?>
    </div>
    <?php if ($error): ?><p class="error" role="alert"><?= esc($error) ?></p><?php endif; ?>
    <?php if ($c['review_status'] === 'pending'): ?>
      <p class="notice"><?= icon('clock') ?> مدارک مرکز در انتظار بررسی مدیر سامانه است. تا تأیید، فقط تکمیل مشخصات، شعبه‌ها، اتاق‌ها و کارکنان ممکن است.</p>
    <?php elseif ($c['review_status'] === 'rejected'): ?>
      <p class="error">درخواست مرکز رد شد<?= $c['review_note'] ? ': ' . esc($c['review_note']) : '' ?>. پس از اصلاح مشخصات یا مدارک، دوباره برای بررسی ارسال می‌شود.</p>
    <?php elseif ($c['review_status'] === 'suspended'): ?>
      <p class="error">مرکز تعلیق شده است<?= $c['review_note'] ? ': ' . esc($c['review_note']) : '' ?>. نوبت‌های پزشکان حفظ شده‌اند.</p>
    <?php elseif (!$c['active']): ?>
      <p class="error">مرکز توسط مدیر سامانه غیرفعال شده است.</p>
    <?php elseif (subscriptions_on() && ($sub['state'] === 'none' || $sub['state'] === 'expired')): ?>
      <p class="notice"><?= icon('alert') ?> <?= $sub['state'] === 'none' ? 'مرکز هنوز اشتراک فعالی ندارد.' : 'اشتراک مرکز به پایان رسیده است.' ?> امکانات کارکنان، تقویم تجمیعی، گزارش‌ها، اطلاعیه و صفحه عمومی مرکز پس از فعال‌سازی اشتراک در دسترس است. <?php if (!empty($a['staff']['perm_billing'])): ?><a href="center.php?center=<?= (int) $c['id'] ?>&amp;tab=subscription">فعال‌سازی اشتراک</a><?php endif; ?></p>
    <?php elseif (subscriptions_on() && $sub['state'] === 'grace'): ?>
      <p class="notice">اشتراک مرکز در دوره فرجه است و تا <?= esc(jdate($sub['grace_end'])) ?> فعال می‌ماند.</p>
    <?php endif; ?>
