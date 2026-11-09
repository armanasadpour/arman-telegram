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
</head>
<body<?= isset($bodyWorker) ? ' data-worker="' . e($bodyWorker) . '"' : '' ?>>
