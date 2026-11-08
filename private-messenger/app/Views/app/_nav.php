<?php use function PM\Support\e; ?>
<header class="topbar">
  <nav><a href="<?= e($base) ?>/" class="<?= ($nav ?? '') === 'home' ? 'on' : '' ?>">خانه</a>
       <a href="<?= e($base) ?>/sessions" class="<?= ($nav ?? '') === 'sessions' ? 'on' : '' ?>">نشست‌ها و دستگاه‌ها</a>
       <a href="<?= e($base) ?>/password" class="<?= ($nav ?? '') === 'password' ? 'on' : '' ?>">تغییر رمز عبور</a></nav>
  <form method="post" action="<?= e($base) ?>/logout" class="inline"><?php include __DIR__ . '/../_csrf.php'; ?><button class="btn secondary sm">خروج</button></form>
</header>
