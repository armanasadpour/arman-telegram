<?php
/**
 * Services as a short list (name, length, visit fee, on/off) with in-place editing, and one small form to add
 * a service. Field names are the same as before (action=service: name, duration_minutes, visit_fee, active).
 * @var array $services @var int $doctorId @var array $defaultDurations clinic name => default length
 */
$lengths = [0, 10, 15, 20, 25, 30, 40, 45, 60, 90, 120];
$suggest = ['ویزیت عمومی', 'ویزیت تخصصی', 'ویزیت مجدد (پیگیری)', 'مشاوره', 'معاینه و تجویز', 'نوار قلب', 'سونوگرافی', 'تزریق و پانسمان'];
$lengthSelect = function (int $value) use ($lengths): string {
    $opts = $lengths;
    if (!in_array($value, $opts, true)) {
        $opts[] = $value;
        sort($opts);
    }
    $out = '<select name="duration_minutes">';
    foreach ($opts as $m) {
        $out .= '<option value="' . $m . '"' . selected_attr($m === $value) . '>' . ($m === 0 ? 'مثل تنظیم مطب' : fa_number($m) . ' دقیقه') . '</option>';
    }
    return $out . '</select>';
};
$form = function (array $s) use ($lengthSelect, $suggest): void {
    $id = (int) ($s['id'] ?? 0);
    ?>
    <form method="post" class="svc-form">
      <?php form_token('service'); ?>
      <?php if ($id): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
      <label class="field"><span>نام خدمت</span><input name="name" value="<?= esc((string) ($s['name'] ?? '')) ?>" maxlength="190" required list="svc-names" placeholder="مثلاً ویزیت عمومی"></label>
      <div class="sc-grid two">
        <label class="field"><span>مدت</span><?= $lengthSelect((int) ($s['duration_minutes'] ?? 0)) ?></label>
        <label class="field"><span>تعرفه ویزیت (تومان، اختیاری)</span><input name="visit_fee" value="<?= !empty($s['visit_fee_toman']) ? number_format((int) $s['visit_fee_toman']) : '' ?>" inputmode="numeric" dir="ltr" maxlength="20" placeholder="—"></label>
      </div>
      <label class="check"><input type="checkbox" name="active" value="1"<?= checked_attr(!$id || !empty($s['active'])) ?>> قابل رزرو برای بیماران</label>
      <button class="button<?= $id ? ' secondary' : '' ?>"><?= icon($id ? 'check' : 'plus') ?> <?= $id ? 'ذخیره تغییرات' : 'افزودن خدمت' ?></button>
    </form>
    <?php
};
?>
<datalist id="svc-names"><?php foreach ($suggest as $n): ?><option value="<?= esc($n) ?>"><?php endforeach; ?></datalist>
<div class="sched">
  <section class="sc-card">
    <div class="sc-head"><div><h2>خدمات شما</h2><p class="muted">بیمار هنگام رزرو یکی از این خدمات را انتخاب می‌کند. تعرفه فقط برای اطلاع بیمار است و در مطب دریافت می‌شود.</p></div></div>
    <?php if (!$services): ?>
      <div class="empty-state"><?= icon('sp-stethoscope') ?><p>هنوز خدمتی تعریف نکرده‌اید؛ دست‌کم یک خدمت (مثلاً «ویزیت عمومی») لازم است.</p></div>
    <?php else: ?>
      <ul class="svc-rows">
        <?php foreach ($services as $s): ?>
          <li class="svc-row<?= $s['active'] ? '' : ' off' ?>">
            <details>
              <summary>
                <span class="svc-ico"><?= icon('sp-stethoscope') ?></span>
                <span class="svc-main"><strong><?= esc($s['name']) ?></strong>
                  <small><?= (int) $s['duration_minutes'] ? fa_number($s['duration_minutes']) . ' دقیقه' : 'مدت مثل تنظیم مطب' ?><?= !empty($s['visit_fee_toman']) ? ' • ویزیت ' . esc(toman((int) $s['visit_fee_toman'])) : '' ?></small></span>
                <span class="badge <?= $s['active'] ? 'status-confirmed' : '' ?>"><?= $s['active'] ? 'فعال' : 'غیرفعال' ?></span>
                <?php if (!empty($s['plan_locked'])): ?><span class="badge pro-locked"><?= icon('lock') ?> بدون رزرو جدید تا فعال‌سازی پرو</span><?php endif; ?>
                <span class="svc-edit"><?= icon('edit') ?> ویرایش</span>
              </summary>
              <?php $form($s); ?>
            </details>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p class="muted small">تغییر نام یا مدت خدمت، نوبت‌هایی را که قبلاً گرفته شده تغییر نمی‌دهد.</p>
  </section>
  <section class="sc-card">
    <div class="sc-head"><div><h2>افزودن خدمت</h2></div></div>
    <?php if (pro_limit_reached($doctorId, 'services', count(array_filter($services, fn($x) => !empty($x['active']))))): ?>
      <?= pro_limit_card($doctorId, 'services') ?>
    <?php else: ?>
      <?php $form([]); ?>
    <?php endif; ?>
  </section>
</div>
