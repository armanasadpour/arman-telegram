
<?php ($st = $a->displayStatus()); ?>
<?php ($lbl = $a->statusLabel()); ?>
<?php ($tm = $a->timeMeter()); ?>
<?php ($vm = $a->volumeMeter()); ?>
<a class="acc-card acc-<?php echo e($st); ?>" href="<?php echo e($url); ?>">
  <div class="acc-top">
    <span class="<?php echo \Illuminate\Support\Arr::toCssClasses(['acc-avatar', 'is-online' => $a->is_online]); ?>"><?php echo e($a->isWireguard() ? 'W' : 'L'); ?></span>
    <div class="acc-id">
      <bdi class="acc-name ltr"><?php echo e($a->username); ?></bdi>
      <span class="acc-group"><?php echo e($a->protocolLabel()); ?> · <bdi><?php echo e($a->group?->name ?? '—'); ?></bdi><?php if(! request()->routeIs('portal.*')): ?> · 👤 <?php echo e($a->creatorLabel()); ?><?php endif; ?></span>
    </div>
    <span class="acc-chevron" aria-hidden="true">‹</span>
  </div>
  <div class="acc-badges"><span class="badge badge-<?php echo e($lbl[1]); ?>"><?php echo e($lbl[0]); ?></span><?php if($a->is_online): ?><span class="badge badge-success">آنلاین</span><?php endif; ?></div>
  <div class="acc-meters">
    <div class="acc-meter">
      <div class="acc-meter-row"><span>زمان</span>
        <strong><?php if(! $tm): ?>نامحدود<?php elseif(! $tm['started']): ?><?php echo e(fa_duration($tm['total'])); ?><?php elseif($tm['remaining'] > 0): ?><?php echo e(fa_duration($tm['remaining'])); ?><?php else: ?> منقضی<?php endif; ?></strong></div>
      <progress class="mini-bar lvl-<?php echo e($tm ? \App\Models\VpnAccount::meterLevel($tm['pct']) : 'unlimited'); ?>" max="100" value="<?php echo e($tm['pct'] ?? 100); ?>"><?php echo e($tm['pct'] ?? 100); ?>%</progress>
    </div>
    <div class="acc-meter">
      <div class="acc-meter-row"><span>حجم</span><strong><?php echo e($vm ? fa_bytes($vm['remaining']) : 'نامحدود'); ?></strong></div>
      <progress class="mini-bar lvl-<?php echo e($vm ? \App\Models\VpnAccount::meterLevel($vm['pct']) : 'unlimited'); ?>" max="100" value="<?php echo e($vm['pct'] ?? 100); ?>"><?php echo e($vm['pct'] ?? 100); ?>%</progress>
    </div>
  </div>
  <div class="acc-foot">
    <span><?php echo e($a->expires_at ? 'انقضا '.jdate($a->expires_at, 'Y/m/d') : ($a->duration_days ? 'شروع با اولین اتصال' : 'بدون انقضا')); ?></span>
    <span>مصرف <?php echo e(fa_bytes($a->used_bytes)); ?></span>
  </div>
</a>
<?php /**PATH /home/user/arman-telegram/panel/resources/views/mt/accounts/_card.blade.php ENDPATH**/ ?>