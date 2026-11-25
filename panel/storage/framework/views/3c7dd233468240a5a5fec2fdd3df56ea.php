<?php if(session('success')): ?>
  <div class="alert alert-success" role="status"><?php echo e(session('success')); ?></div>
<?php endif; ?>
<?php if(session('warning')): ?>
  <div class="alert alert-warning" role="status"><?php echo e(session('warning')); ?></div>
<?php endif; ?>
<?php if(session('error')): ?>
  <div class="alert alert-danger" role="alert"><?php echo e(session('error')); ?></div>
<?php endif; ?>
<?php if($errors->any()): ?>
  <div class="alert alert-danger" role="alert">
    لطفاً خطاهای زیر را برطرف کنید:
    <ul>
      <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $e): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <li><?php echo e($e); ?></li>
      <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </ul>
  </div>
<?php endif; ?>
<?php /**PATH /home/user/arman-telegram/panel/resources/views/partials/flash.blade.php ENDPATH**/ ?>