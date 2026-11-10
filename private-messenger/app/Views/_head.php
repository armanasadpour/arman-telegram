<?php use function PM\Support\e; ?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover, interactive-widget=resizes-content">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<meta name="referrer" content="no-referrer">
<meta name="format-detection" content="telephone=no">
<title><?= e($title ?? 'ورود') ?></title>
<link rel="stylesheet" href="<?= e($asset('style.css')) ?>">
<?php if (!empty($theme)): ?><link rel="stylesheet" href="<?= e($theme) ?>">
<?php endif; if (!empty($manifest)): ?><link rel="manifest" href="<?= e($manifest) ?>">
<link rel="icon" href="<?= e($icon) ?>"><link rel="apple-touch-icon" href="<?= e($appleIcon ?? $icon) ?>">
<meta name="theme-color" content="<?= e($themeColor) ?>">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= e($title ?? '') ?>">
<?php endif; ?>
<script src="<?= e($asset('nozoom.js')) ?>"></script>
</head>
<body<?= isset($bodyWorker) ? ' data-worker="' . e($bodyWorker) . '"' : '' ?><?= isset($brand) ? ' data-brand="' . e($brand) . '" data-logov="' . e($logoV) . '"' : '' ?>>
