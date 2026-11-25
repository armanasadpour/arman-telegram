<?php if (isset($component)) { $__componentOriginal5863877a5171c196453bfa0bd807e410 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal5863877a5171c196453bfa0bd807e410 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.layouts.app','data' => ['title' => 'داشبورد']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('layouts.app'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'داشبورد']); ?>
  <div class="page-head">
    <div>
      <h1>داشبورد</h1>
      <p class="muted">آخرین به‌روزرسانی: <?php echo e(jdate($generatedAt, 'Y/m/d H:i:s')); ?></p>
    </div>
  </div>

  <?php if($problems): ?>
    <div class="alert alert-warning">
      <strong>هشدارهای سیستم:</strong>
      <ul>
        <?php $__currentLoopData = $problems; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $p): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
          <li><?php echo e($p['label']); ?>: <?php echo e($p['detail']); ?></li>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
      </ul>
      <a href="<?php echo e(route('system.status')); ?>">مشاهده وضعیت سرویس‌ها</a>
    </div>
  <?php endif; ?>

  <?php ($attn = array_values(array_filter([
    $attention['payments_review'] && auth()->user()->can('payments.view') ? ['url' => route('payments.index', ['status' => 'review']), 'text' => fa($attention['payments_review']).' پرداخت منتظر بررسی'] : null,
    $attention['sales_review'] && auth()->user()->can('resellers.view') ? ['url' => route('resellers.index'), 'text' => fa($attention['sales_review']).' فروش همکار'] : null,
    $attention['orders_review'] && auth()->user()->can('payments.view') ? ['url' => route('payments.index'), 'text' => fa($attention['orders_review']).' سفارش مشتری'] : null,
    $attention['jobs'] && auth()->user()->can('accounts.view') ? ['url' => route('accounts.jobs'), 'text' => fa($attention['jobs']).' ساخت نیمه‌کاره'] : null,
  ]))); ?>
  <?php if($attn): ?>
    <div class="alert alert-warning">
      <strong>نیازمند رسیدگی:</strong>
      <?php $__currentLoopData = $attn; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i => $x): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if($i > 0): ?> · <?php endif; ?><a href="<?php echo e($x['url']); ?>"><?php echo e($x['text']); ?></a><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>
  <?php endif; ?>

  <?php if($finance): ?>
    <?php ($sum = $finance['sum']); ?>
    <div class="kpis mb-16">
      <a class="kpi" href="<?php echo e(route('reports.index', ['from' => jdate(now(), 'Y/m/d'), 'to' => jdate(now(), 'Y/m/d')])); ?>"><div class="label">فروش امروز</div><div class="value"><?php echo e(fa_money($sum($finance['today']))); ?></div><div class="hint"><?php echo e(fa($finance['today']['sales_count'] + $finance['today']['orders_count'])); ?> فروش — پرداخت <?php echo e(fa_money($finance['today']['payments_rial'])); ?></div></a>
      <a class="kpi" href="<?php echo e(route('reports.index', ['from' => jdate(now()->subDays(6), 'Y/m/d'), 'to' => jdate(now(), 'Y/m/d')])); ?>"><div class="label">۷ روز اخیر</div><div class="value"><?php echo e(fa_money($sum($finance['week']))); ?></div><div class="hint"><?php echo e(fa($finance['week']['sales_count'] + $finance['week']['orders_count'])); ?> فروش</div></a>
      <a class="kpi" href="<?php echo e(route('reports.index')); ?>"><div class="label">۳۰ روز اخیر</div><div class="value"><?php echo e(fa_money($sum($finance['month']))); ?></div><div class="hint"><?php echo e(fa($finance['month']['sales_count'] + $finance['month']['orders_count'])); ?> فروش — <?php echo e(fa($finance['month']['new_accounts'])); ?> اکانت جدید</div></a>
    </div>
    <div class="charts mb-16">
      <div class="card"><h2>فروش ۱۴ روز اخیر</h2><?php if (isset($component)) { $__componentOriginalc621b3f3a1dd664e2d7a5c269bd63ea4 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc621b3f3a1dd664e2d7a5c269bd63ea4 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.chart','data' => ['values' => $finance['daily']['revenue'],'money' => true,'kind' => 'success']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('chart'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['values' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($finance['daily']['revenue']),'money' => true,'kind' => 'success']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalc621b3f3a1dd664e2d7a5c269bd63ea4)): ?>
<?php $attributes = $__attributesOriginalc621b3f3a1dd664e2d7a5c269bd63ea4; ?>
<?php unset($__attributesOriginalc621b3f3a1dd664e2d7a5c269bd63ea4); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalc621b3f3a1dd664e2d7a5c269bd63ea4)): ?>
<?php $component = $__componentOriginalc621b3f3a1dd664e2d7a5c269bd63ea4; ?>
<?php unset($__componentOriginalc621b3f3a1dd664e2d7a5c269bd63ea4); ?>
<?php endif; ?></div>
      <div class="card"><h2>تعداد فروش ۱۴ روز اخیر</h2><?php if (isset($component)) { $__componentOriginalc621b3f3a1dd664e2d7a5c269bd63ea4 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc621b3f3a1dd664e2d7a5c269bd63ea4 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.chart','data' => ['values' => $finance['daily']['count']]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('chart'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['values' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($finance['daily']['count'])]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalc621b3f3a1dd664e2d7a5c269bd63ea4)): ?>
<?php $attributes = $__attributesOriginalc621b3f3a1dd664e2d7a5c269bd63ea4; ?>
<?php unset($__attributesOriginalc621b3f3a1dd664e2d7a5c269bd63ea4); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalc621b3f3a1dd664e2d7a5c269bd63ea4)): ?>
<?php $component = $__componentOriginalc621b3f3a1dd664e2d7a5c269bd63ea4; ?>
<?php unset($__componentOriginalc621b3f3a1dd664e2d7a5c269bd63ea4); ?>
<?php endif; ?></div>
    </div>
  <?php endif; ?>

  <div class="mb-16 grid stats">
    <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('staff.view')): ?>
      <a class="card stat" href="<?php echo e(route('staff.index')); ?>">
        <div class="label">کاربران فعال پنل</div>
        <div class="value"><?php echo e(fa($stats['staff_active'])); ?></div>
        <div class="hint">از مجموع <?php echo e(fa($stats['staff_total'])); ?> کاربر</div>
      </a>
    <?php endif; ?>
    <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('audit.view')): ?>
      <a class="card stat" href="<?php echo e(route('audit.index', ['action' => 'auth.login', 'days' => 1])); ?>">
        <div class="label">ورودهای امروز</div>
        <div class="value"><?php echo e(fa($stats['logins_today'])); ?></div>
        <div class="hint">از ساعت ۰۰:۰۰ امروز</div>
      </a>
      <a class="card stat" href="<?php echo e(route('audit.index', ['action' => 'auth.login_failed', 'days' => 1])); ?>">
        <div class="label">ورودهای ناموفق ۲۴ ساعت</div>
        <div class="<?php echo \Illuminate\Support\Arr::toCssClasses(['value', 'text-danger' => $stats['failed_24h'] > 0]); ?>"><?php echo e(fa($stats['failed_24h'])); ?></div>
        <div class="hint">برای بررسی کلیک کنید</div>
      </a>
    <?php endif; ?>
  </div>

  <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('audit.view')): ?>
    <div class="card">
      <div class="card-head">
        <h2>آخرین رویدادها</h2>
        <a href="<?php echo e(route('audit.index')); ?>" class="btn btn-secondary btn-sm">همه رویدادها</a>
      </div>
      <?php if($recent->isEmpty()): ?>
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
<?php endif; ?><div>هنوز رویدادی ثبت نشده است.</div></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table stack">
            <thead><tr><th>زمان</th><th>کاربر</th><th>رویداد</th><th>توضیح</th></tr></thead>
            <tbody>
            <?php $__currentLoopData = $recent; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $log): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
              <tr>
                <td data-label="زمان"><?php echo e(jdate($log->created_at)); ?></td>
                <td data-label="کاربر"><bdi><?php echo e($log->actor_label); ?></bdi></td>
                <td data-label="رویداد"><a href="<?php echo e(route('audit.show', $log)); ?>"><?php echo e(\App\Http\Controllers\Admin\AuditController::LABELS[$log->action] ?? $log->action); ?></a></td>
                <td data-label="توضیح"><?php echo e($log->description); ?></td>
              </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

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
<?php /**PATH /home/user/arman-telegram/panel/resources/views/dashboard.blade.php ENDPATH**/ ?>