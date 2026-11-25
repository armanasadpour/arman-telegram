
<?php if($m): ?><progress class="mini-bar lvl-<?php echo e(\App\Models\VpnAccount::meterLevel($m['pct'])); ?>" max="100" value="<?php echo e($m['pct']); ?>"><?php echo e($m['pct']); ?>%</progress><?php endif; ?>
<?php /**PATH /home/user/arman-telegram/panel/resources/views/accounts/_mini.blade.php ENDPATH**/ ?>