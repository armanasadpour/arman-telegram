<?php if (isset($component)) { $__componentOriginal5863877a5171c196453bfa0bd807e410 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal5863877a5171c196453bfa0bd807e410 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.layouts.app','data' => ['title' => 'بارگذاری به‌روزرسانی']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('layouts.app'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'بارگذاری به‌روزرسانی']); ?>
  <div class="page-head">
    <div><h1>بارگذاری به‌روزرسانی</h1><p class="muted">نسخه نصب‌شده: <strong class="ltr"><?php echo e($version); ?></strong></p></div>
    <a class="btn btn-secondary" href="<?php echo e(route('system.status')); ?>">بازگشت</a>
  </div>
  <?php if (! ($zip)): ?><div class="alert alert-danger">افزونه zip در PHP هاست فعال نیست؛ از cPanel ← Select PHP Version آن را روشن کنید.</div><?php endif; ?>
  <div class="card w-640">
    <form method="post" action="<?php echo e(route('system.update.store')); ?>" enctype="multipart/form-data">
      <?php echo csrf_field(); ?>
      <div class="field">
        <label for="package">فایل ZIP به‌روزرسانی یا بسته کامل</label>
        <input id="package" name="package" type="file" accept=".zip,application/zip" class="input" required>
        <div class="help">بسته سبک (vpn-panel-update-…) یا بسته کامل (vpn-panel-…). سقف آپلود این هاست: <?php echo e($maxUpload < PHP_INT_MAX ? fa_bytes($maxUpload) : 'نامحدود'); ?>.</div>
      </div>
      <button class="btn btn-lg" type="submit">بارگذاری و نصب</button>
    </form>
    <ul class="muted small mt-8">
      <li>بعد از نصب فایل‌ها، به‌روزرسانی دیتابیس خودکار اجرا می‌شود.</li>
      <li>فایل ‎.env، تنظیمات، فایل‌های آپلودشده و لاگ‌ها دست نمی‌خورند.</li>
      <li>بسته سبک فقط روی همان نسخه‌ای نصب می‌شود که برایش ساخته شده؛ در غیر این صورت پیام می‌دهد و چیزی تغییر نمی‌کند.</li>
      <li>اگر بسته کامل از سقف آپلود بزرگ‌تر است، همان روش File Manager را برای آن استفاده کنید.</li>
    </ul>
  </div>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal5863877a5171c196453bfa0bd807e410)): ?>
<?php $attributes = $__attributesOriginal5863877a5171c196453bfa0bd807e410; ?>
<?php unset($__attributesOriginal5863877a5171c196453bfa0bd807e410); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal5863877a5171c196453bfa0bd807e410)): ?>
<?php $component = $__componentOriginal5863877a5171c196453bfa0bd807e410; ?>
<?php unset($__componentOriginal5863877a5171c196453bfa0bd807e410); ?>
<?php endif; ?>
<?php /**PATH /home/user/arman-telegram/panel/resources/views/system/update.blade.php ENDPATH**/ ?>