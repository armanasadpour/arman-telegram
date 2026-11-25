<?php ($pl = ['l2tp' => 'L2TP', 'wireguard' => 'WireGuard'][$proto ?? ''] ?? 'L2TP / WireGuard'); ?>
<?php if (isset($component)) { $__componentOriginal5863877a5171c196453bfa0bd807e410 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal5863877a5171c196453bfa0bd807e410 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.layouts.app','data' => ['title' => 'اکانت‌های '.$pl]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('layouts.app'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute('اکانت‌های '.$pl)]); ?>
  <div class="page-head">
    <div>
      <h1>اکانت‌های <?php echo e($pl); ?></h1>
      <p class="muted">مصرف و وضعیت هر دقیقه از روترها خوانده می‌شود؛ اکانت منقضی یا حجم‌تمام خودکار قطع می‌شود.</p>
    </div>
    <div class="actions">
      <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('routers.view')): ?><a class="btn btn-secondary" href="<?php echo e(route($proto === 'wireguard' ? 'wg.groups' : ($proto === 'l2tp' ? 'l2tp.groups' : 'mt.groups.index'))); ?>">گروه و تعرفه</a><?php endif; ?>
      <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('mt_accounts.create')): ?><a class="btn" href="<?php echo e(route('mt.accounts.create', $proto ? ['protocol' => $proto] : [])); ?>">ساخت اکانت <?php echo e($proto ? $pl : ''); ?></a><?php endif; ?>
    </div>
  </div>

  <div class="grid stats mb-16">
    <a class="card stat" href="<?php echo e(url()->current()); ?>"><div class="label">اکانت‌ها</div><div class="value"><?php echo e(fa((int) $counts->total)); ?></div></a>
    <a class="card stat" href="<?php echo e(url()->current().'?'.http_build_query(['status' => 'online'])); ?>"><div class="label">آنلاین</div><div class="value"><?php echo e(fa((int) $counts->online)); ?></div></a>
    <a class="card stat" href="<?php echo e(url()->current().'?'.http_build_query(['status' => 'expired'])); ?>"><div class="label">منقضی</div><div class="value"><?php echo e(fa((int) $counts->expired)); ?></div></a>
    <a class="card stat" href="<?php echo e(url()->current().'?'.http_build_query(['status' => 'depleted'])); ?>"><div class="label">حجم تمام‌شده</div><div class="value"><?php echo e(fa((int) $counts->depleted)); ?></div></a>
  </div>
  <?php if((int) $counts->problems > 0): ?>
    <div class="alert alert-warning"><?php echo e(fa((int) $counts->problems)); ?> اکانت در حال ساخت یا ناموفق است. <a href="<?php echo e(url()->current().'?'.http_build_query(['status' => 'pending'])); ?>">در حال ساخت</a> · <a href="<?php echo e(url()->current().'?'.http_build_query(['status' => 'failed'])); ?>">ناموفق</a></div>
  <?php endif; ?>

  <div class="card">
    <form method="get" class="form-grid mb-8">
      <div class="field"><label for="q">جستجو</label><input id="q" name="q" class="input" value="<?php echo e($filters['q'] ?? ''); ?>" placeholder="نام کاربری، توضیح یا IP"></div>
      <div class="field"><label for="status">وضعیت</label>
        <select id="status" name="status" class="input"><option value="">همه</option>
          <?php $__currentLoopData = ['online' => 'آنلاین', 'active' => 'فعال', 'not_started' => 'شروع‌نشده', 'expiring' => 'انقضا تا ۳ روز آینده', 'expired' => 'منقضی', 'depleted' => 'حجم تمام‌شده', 'disabled' => 'غیرفعال', 'pending' => 'در حال ساخت', 'failed' => 'ناموفق', 'deleted' => 'حذف‌شده']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $k => $v): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <option value="<?php echo e($k); ?>" <?php if(($filters['status'] ?? '') === $k): echo 'selected'; endif; ?>><?php echo e($v); ?></option>
          <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </select></div>
      <?php if (! ($proto)): ?><div class="field"><label for="protocol">پروتکل</label>
        <select id="protocol" name="protocol" class="input"><option value="">همه</option>
          <?php $__currentLoopData = \App\Models\MtGroup::PROTOCOLS; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $k => $v): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($k); ?>" <?php if(($filters['protocol'] ?? '') === $k): echo 'selected'; endif; ?>><?php echo e($v); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </select></div><?php endif; ?>
      <div class="field"><label for="group">گروه</label>
        <select id="group" name="group" class="input"><option value="">همه</option>
          <?php $__currentLoopData = $groups; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $g): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($g->id); ?>" <?php if((string) ($filters['group'] ?? '') === (string) $g->id): echo 'selected'; endif; ?>><?php echo e($g->name); ?> (<?php echo e($g->router?->name); ?>)</option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </select></div>
      <div class="field"><label for="creator">ساخته‌شده توسط</label>
        <select id="creator" name="creator" class="input"><option value="">همه مدیران</option>
          <?php $__currentLoopData = $creators; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $c): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($c->id); ?>" <?php if((string) ($filters['creator'] ?? '') === (string) $c->id): echo 'selected'; endif; ?>><?php echo e($c->name); ?> (<?php echo e($c->username); ?>)</option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </select></div>
      <div class="actions"><button class="btn" type="submit">اعمال</button><?php if(array_filter($filters)): ?><a class="btn btn-secondary" href="<?php echo e(url()->current()); ?>">حذف فیلتر</a><?php endif; ?></div>
    </form>

    <?php if($accounts->isEmpty()): ?>
      <div class="empty"><?php if (isset($component)) { $__componentOriginalce262628e3a8d44dc38fd1f3965181bc = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalce262628e3a8d44dc38fd1f3965181bc = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.icon','data' => ['name' => 'inbox']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'inbox']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalce262628e3a8d44dc38fd1f3965181bc)): ?>
<?php $attributes = $__attributesOriginalce262628e3a8d44dc38fd1f3965181bc; ?>
<?php unset($__attributesOriginalce262628e3a8d44dc38fd1f3965181bc); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalce262628e3a8d44dc38fd1f3965181bc)): ?>
<?php $component = $__componentOriginalce262628e3a8d44dc38fd1f3965181bc; ?>
<?php unset($__componentOriginalce262628e3a8d44dc38fd1f3965181bc); ?>
<?php endif; ?><div><?php echo e(array_filter($filters) ? 'نتیجه‌ای یافت نشد.' : 'هنوز اکانت '.$pl.' ساخته نشده است.'); ?></div></div>
    <?php else: ?>
      <div class="table-wrap">
        <div class="acc-list only-mobile">
          <?php $__currentLoopData = $accounts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $a): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php echo $__env->make('mt.accounts._card', ['a' => $a, 'url' => route('mt.accounts.show', $a)], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
        <table class="table only-desktop">
          <thead><tr><th>نام کاربری</th><th>پروتکل / گروه</th><th>سازنده</th><th>وضعیت</th><th>انقضا</th><th>حجم باقی‌مانده</th><th>مصرف</th><th>آخرین اتصال</th></tr></thead>
          <tbody>
          <?php $__currentLoopData = $accounts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $a): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?php ($lbl = $a->statusLabel()); ?>
            <tr>
              <td><a href="<?php echo e(route('mt.accounts.show', $a)); ?>"><bdi class="ltr"><?php echo e($a->username); ?></bdi></a><?php if($a->is_online): ?> <span class="badge badge-success">آنلاین</span><?php endif; ?>
</td>
              <td><?php echo e($a->protocolLabel()); ?><div class="muted small"><?php echo e($a->group?->name); ?></div></td>
              <td class="small"><?php echo e($a->creatorLabel()); ?></td>
              <td><span class="badge badge-<?php echo e($lbl[1]); ?>"><?php echo e($lbl[0]); ?></span></td>
              <td><?php echo e($a->expires_at ? jdate($a->expires_at, 'Y/m/d') : ($a->duration_days ? fa($a->duration_days).' روز از اولین اتصال' : '∞')); ?>

                <?php echo $__env->make('accounts._mini', ['m' => $a->timeMeter()], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?></td>
              <td><?php echo e($a->isVolumeLimited() ? fa_bytes($a->remainingBytes()) : 'نامحدود'); ?> <?php echo $__env->make('accounts._mini', ['m' => $a->volumeMeter()], array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?></td>
              <td><?php echo e(fa_bytes($a->used_bytes)); ?></td>
              <td class="small"><?php echo e($a->is_online ? 'هم‌اکنون' : ($a->last_seen_at ? fa_ago($a->last_seen_at) : '—')); ?></td>
            </tr>
          <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
          </tbody>
        </table>
      </div>
      <?php echo e($accounts->links()); ?>

    <?php endif; ?>
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
<?php /**PATH /home/user/arman-telegram/panel/resources/views/mt/accounts/index.blade.php ENDPATH**/ ?>