<?php
/**
 * 1.0.0-rc.20 — Free / Pro side by side, built from the feature catalog the administrator sets.
 * $choose: radio buttons for the registration form (name plan_choice); otherwise display only.
 * @var bool $choose @var string $selected free|pro @var bool $isPro
 */
$catalog = pro_catalog();
$set = pro_settings();
$freeItems = [];
$proItems = [];
foreach ($catalog as $key => $f) {
    if ($f['kind'] === 'base') {
        $freeItems[] = $f['label'];
        continue;
    }
    if ($f['kind'] === 'limit') {
        $limit = $set['limits'][$key] ?? null;
        if (!$set['pro'][$key] || $limit === null) {
            $freeItems[] = $f['unit'] . ' نامحدود';
            continue;
        }
        $freeItems[] = $limit === 0 ? 'بدون ' . $f['unit'] : fa_number($limit) . ' ' . $f['unit'];
        $proItems[] = $f['unit'] . ' نامحدود';
        continue;
    }
    if ($set['pro'][$key]) {
        $proItems[] = $f['label'];
    } else {
        $freeItems[] = $f['label'];
    }
}
$price = pro_price_from();
$trial = setting('subscription_trial_enabled', '0') === '1' ? setting_int('subscription_trial_days', 14, 1, 90) : 0;
$choose = $choose ?? false;
$selected = $selected ?? 'free';
$isPro = $isPro ?? false;
$card = function (string $plan, string $title, string $tagline, string $priceText, array $items, string $lead = '') use ($choose, $selected, $isPro): void {
    $tag = $choose ? 'label' : 'article';
    ?>
    <<?= $tag ?> class="plan-col plan-<?= $plan ?><?= $choose && $selected === $plan ? ' is-selected' : '' ?><?= !$choose && ($plan === 'pro') === $isPro ? ' is-current' : '' ?>">
      <?php if ($choose): ?><input type="radio" name="plan_choice" value="<?= $plan ?>"<?= checked_attr($selected === $plan) ?> required><?php endif; ?>
      <span class="plan-name"><?= $plan === 'pro' ? icon('verified') : '' ?><?= esc($title) ?></span>
      <span class="plan-tagline"><?= esc($tagline) ?></span>
      <span class="plan-price"><?= $priceText ?></span>
      <?php if ($lead !== ''): ?><span class="plan-lead"><?= esc($lead) ?></span><?php endif; ?>
      <ul class="plan-list">
        <?php foreach ($items as $it): ?><li><?= icon('check') ?><?= esc($it) ?></li><?php endforeach; ?>
      </ul>
      <?php if ($choose): ?><span class="plan-pick"><span class="plan-pick-on"><?= icon('check') ?> انتخاب شد</span><span class="plan-pick-off">انتخاب <?= esc($title) ?></span></span><?php elseif (($plan === 'pro') === $isPro): ?><span class="plan-current">نسخه فعلی شما</span><?php endif; ?>
    </<?= $tag ?>>
    <?php
};
?>
<div class="plan-compare" role="<?= $choose ? 'radiogroup' : 'group' ?>" aria-label="نسخه نوبت‌یار">
  <?php
  $card('free', 'رایگان', 'برای شروع نوبت‌دهی آنلاین', '<b>۰</b> تومان', $freeItems);
  $card(
      'pro',
      'پرو',
      'برای دیده‌شدن بیشتر و مدیریت کامل مطب',
      $price ? 'از <b>' . esc(fa_number(number_format($price['monthly']))) . '</b> تومان در ماه' : 'قیمت به‌زودی اعلام می‌شود',
      $proItems,
      'همه امکانات رایگان، به‌علاوه:' . ($trial ? ' (' . fa_number($trial) . ' روز آزمایشی رایگان)' : ''),
  );
  ?>
</div>
