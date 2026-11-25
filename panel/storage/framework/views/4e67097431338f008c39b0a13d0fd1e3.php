<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['values', 'money' => false, 'kind' => 'primary', 'height' => 180]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter((['values', 'money' => false, 'kind' => 'primary', 'height' => 180]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
  $vals = array_values($values); $keys = array_keys($values); $n = max(1, count($vals));
  $max = max(1, max($vals ?: [0])); $w = 600; $h = (int) $height; $pad = 22; $bw = ($w - 8) / $n;
  $fmt = fn ($v) => $money ? fa_money((int) $v) : fa((int) $v);
  $label = fn ($k) => jdate(\Carbon\CarbonImmutable::parse($k, config('app.display_timezone')), 'm/d');
  $every = max(1, (int) ceil($n / 8));
?>
<figure class="chart chart-<?php echo e($kind); ?>">
  <svg viewBox="0 0 <?php echo e($w); ?> <?php echo e($h + $pad); ?>" role="img" aria-label="نمودار" preserveAspectRatio="none">
    <line class="chart-grid" x1="0" x2="<?php echo e($w); ?>" y1="<?php echo e($h); ?>" y2="<?php echo e($h); ?>"/>
    <line class="chart-grid" x1="0" x2="<?php echo e($w); ?>" y1="<?php echo e($h / 2); ?>" y2="<?php echo e($h / 2); ?>"/>
    <?php $__currentLoopData = $vals; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i => $v): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
      <?php ($bh = $v > 0 ? max(2, ($v / $max) * ($h - 8)) : 0); ?>
      <g>
        <title><?php echo e(jdate(\Carbon\CarbonImmutable::parse($keys[$i], config('app.display_timezone')), 'Y/m/d')); ?>: <?php echo e($fmt($v)); ?></title>
        <rect class="chart-hit" x="<?php echo e($w - ($i + 1) * $bw); ?>" y="0" width="<?php echo e($bw); ?>" height="<?php echo e($h); ?>"/>
        <rect class="chart-bar" x="<?php echo e($w - ($i + 1) * $bw + $bw * 0.15); ?>" y="<?php echo e($h - $bh); ?>" width="<?php echo e($bw * 0.7); ?>" height="<?php echo e($bh); ?>" rx="3"/>
      </g>
      <?php if($i % $every === 0 || $i === $n - 1): ?>
        <text class="chart-x" x="<?php echo e($w - ($i + 0.5) * $bw); ?>" y="<?php echo e($h + 16); ?>" text-anchor="middle"><?php echo e($label($keys[$i])); ?></text>
      <?php endif; ?>
    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
  </svg>
  <figcaption class="chart-cap"><span>بیشینه روزانه: <?php echo e($fmt($max)); ?></span><span>جمع: <?php echo e($fmt(array_sum($vals))); ?></span></figcaption>
</figure>
<?php /**PATH /home/user/arman-telegram/panel/resources/views/components/chart.blade.php ENDPATH**/ ?>