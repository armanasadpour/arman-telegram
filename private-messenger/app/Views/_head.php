<?php use function PM\Support\e; ?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<meta name="referrer" content="no-referrer">
<title><?= e($title ?? 'ورود') ?></title>
<link rel="stylesheet" href="<?= e($asset('style.css')) ?>">
<?php if (!empty($theme)): ?><link rel="stylesheet" href="<?= e($theme) ?>">
<?php endif; if (!empty($manifest)): ?><link rel="manifest" href="<?= e($manifest) ?>">
<link rel="icon" href="<?= e($icon) ?>"><link rel="apple-touch-icon" href="<?= e($icon) ?>">
<meta name="theme-color" content="<?= e($themeColor) ?>">
<?php endif; ?>
</head>
<body<?= isset($bodyWorker) ? ' data-worker="' . e($bodyWorker) . '"' : '' ?>>
