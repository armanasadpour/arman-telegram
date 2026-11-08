<?php use function PM\Support\e; $title = 'نصب'; include __DIR__ . '/../_head.php'; ?>
<div class="center"><div class="card">
  <h1>نصب</h1>
  <p class="small muted">برای اثبات دسترسی به هاست، محتوای فایل <span class="ltr">storage/install-token.txt</span> را از File Manager در cPanel باز کنید و اینجا وارد کنید.</p>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e($base) ?>/">
    <?php include __DIR__ . '/../_csrf.php'; ?>
    <label class="field"><span>کد نصب</span><input type="text" name="token" class="ltr" autocomplete="off" required></label>
    <button class="btn block">ادامه</button>
  </form>
</div></div>
</body></html>
