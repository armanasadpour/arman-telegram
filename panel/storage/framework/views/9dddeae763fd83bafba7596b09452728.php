<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo e($title ?? 'ورود'); ?> — پنل فروش</title>
<?php if(is_file(public_path('fonts/IRANSans-400.woff2'))): ?><link rel="stylesheet" href="<?php echo e(asset('assets/fonts.css')); ?>?v=<?php echo e(config('panel.version')); ?>"><?php endif; ?>
<link rel="stylesheet" href="<?php echo e(asset('assets/app.css')); ?>?v=<?php echo e(config('panel.version')); ?>">
</head>
<body>
<main class="guest">
  <div class="card">
    <?php echo $__env->make('partials.flash', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    <?php echo e($slot); ?>

  </div>
</main>
<script src="<?php echo e(asset('assets/app.js')); ?>?v=<?php echo e(config('panel.version')); ?>"></script>
</body>
</html>
<?php /**PATH /home/user/arman-telegram/panel/resources/views/components/layouts/guest.blade.php ENDPATH**/ ?>