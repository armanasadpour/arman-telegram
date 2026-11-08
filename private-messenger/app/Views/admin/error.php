<?php use function PM\Support\e; $title = 'خطا'; $nav = ''; include __DIR__ . '/_top.php'; ?>
<div class="alert error"><?= e($error) ?></div>
<a class="btn secondary" href="<?= e($base . $back) ?>">بازگشت</a>
<?php include __DIR__ . '/_bottom.php'; ?>
