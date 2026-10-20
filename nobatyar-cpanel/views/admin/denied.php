<?php /** @var string $permission */ ?>
<section class="panel denied-panel" role="alert">
  <?= icon('lock') ?>
  <h2>دسترسی به این بخش ندارید</h2>
  <p class="muted">این بخش به مجوز «<?= esc($permission) ?>» نیاز دارد. اگر لازم است، از مالک سامانه بخواهید نقش شما را تغییر دهد.</p>
  <a class="button secondary" href="admin.php">بازگشت به داشبورد</a>
</section>
