<?php /** 1.0.29 audience chooser shared by announcements and bulk SMS: a group, then all cities or one city.
 * @var string $name @var array $options @var array $counts @var array $provinces @var string $selected @var array $post */
$groups = [
    'کاربران' => ['all' => 'users', 'booked' => 'calendar-check', 'patients' => 'user'],
    'پزشکان' => ['doctors' => 'doctor', 'doctors_pro' => 'sparkles', 'doctors_free' => 'sp-stethoscope', 'doctors_new' => 'user-plus', 'doctors_noschedule' => 'calendar-clock'],
    'کادر' => ['secretaries' => 'headset', 'centers' => 'building'],
];
$byCity = !empty($post['by_city']); ?>
<fieldset class="audience-picker" data-audience-picker data-counts-url="admin.php?tab=announcements&amp;audience_counts=1">
  <legend>گیرندگان</legend>
  <?php foreach ($groups as $title => $keys): ?>
    <p class="audience-group"><?= esc($title) ?></p>
    <div class="audience-grid">
      <?php foreach ($keys as $k => $ic): if (!isset($options[$k])) continue; ?>
        <label class="audience-option is-<?= $title === 'پزشکان' ? 'doctor' : ($title === 'کادر' ? 'staff' : 'user') ?><?= $k === 'doctors_pro' ? ' is-pro' : '' ?>">
          <input type="radio" name="<?= esc($name) ?>" value="<?= esc($k) ?>" required<?= $selected === $k ? ' checked' : '' ?>>
          <span class="audience-icon"><?= icon($ic) ?></span>
          <span class="audience-text"><strong><?= esc($options[$k]) ?></strong><small data-audience-count="<?= esc($k) ?>"><?= fa_number($counts[$k] ?? 0) ?> نفر</small></span>
        </label>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  <p class="audience-group">محدوده</p>
  <div class="audience-scope" role="radiogroup">
    <label><input type="radio" name="by_city" value="0"<?= $byCity ? '' : ' checked' ?>><span><?= icon('map') ?> همه شهرها</span></label>
    <label><input type="radio" name="by_city" value="1"<?= $byCity ? ' checked' : '' ?>><span><?= icon('pin') ?> فقط یک شهر</span></label>
  </div>
  <div class="audience-city form-grid" data-geo data-audience-city<?= $byCity ? '' : ' hidden' ?>>
    <label>استان<select name="province_id"><option value="">انتخاب کنید</option><?php foreach ($provinces as $p): ?><option value="<?= (int) $p['id'] ?>"<?= selected_attr((int) ($post['province_id'] ?? 0) === (int) $p['id']) ?>><?= esc($p['name']) ?></option><?php endforeach; ?></select></label>
    <label>شهر<select name="city_id" data-selected="<?= (int) ($post['city_id'] ?? 0) ?>"><option value="">ابتدا استان را انتخاب کنید</option></select></label>
    <p class="muted small audience-city-note">کاربرانی که این شهر را در سایت انتخاب کرده‌اند یا در آن نوبت گرفته‌اند، و پزشکان و منشی‌هایی که مطبشان در این شهر است.</p>
  </div>
</fieldset>
