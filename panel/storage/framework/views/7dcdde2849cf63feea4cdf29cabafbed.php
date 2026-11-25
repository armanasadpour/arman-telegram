<?php if($paginator->hasPages()): ?>
<nav aria-label="صفحه‌بندی">
  <ul class="pagination">
    <?php if($paginator->onFirstPage()): ?>
      <li><span class="disabled">قبلی</span></li>
    <?php else: ?>
      <li><a href="<?php echo e($paginator->previousPageUrl()); ?>" rel="prev">قبلی</a></li>
    <?php endif; ?>
    <?php $__currentLoopData = $elements; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $element): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <?php if(is_string($element)): ?>
        <li><span class="disabled">…</span></li>
      <?php endif; ?>
      <?php if(is_array($element)): ?>
        <?php $__currentLoopData = $element; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $page => $url): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
          <?php if($page == $paginator->currentPage()): ?>
            <li><span class="current" aria-current="page"><?php echo e(fa((string) $page)); ?></span></li>
          <?php else: ?>
            <li><a href="<?php echo e($url); ?>"><?php echo e(fa((string) $page)); ?></a></li>
          <?php endif; ?>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
      <?php endif; ?>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    <?php if($paginator->hasMorePages()): ?>
      <li><a href="<?php echo e($paginator->nextPageUrl()); ?>" rel="next">بعدی</a></li>
    <?php else: ?>
      <li><span class="disabled">بعدی</span></li>
    <?php endif; ?>
  </ul>
</nav>
<?php endif; ?>
<?php /**PATH /home/user/arman-telegram/panel/resources/views/partials/pagination.blade.php ENDPATH**/ ?>