<?php /** 1.0.27 audience chooser shared by announcements and bulk SMS. @var string $name @var array $options @var array $counts @var array $provinces @var string $selected @var array $post */
$icons = ['all' => 'users', 'patients' => 'user', 'doctors' => 'doctor', 'secretaries' => 'users', 'centers' => 'building', 'doctors_pro' => 'sparkles', 'doctors_free' => 'doctor', 'doctors_city' => 'map']; ?>
<fieldset class="audience-picker" data-audience-picker>
  <legend>گیرندگان</legend>
  <div class="audience-grid">
    <?php foreach ($options as $k => $l): ?>
      <label class="audience-option<?= str_starts_with($k, 'doctors_') ? ' is-doctor' : '' ?>">
        <input type="radio" name="<?= esc($name) ?>" value="<?= esc($k) ?>" required<?= $selected === $k ? ' checked' : '' ?>>
        <span class="audience-icon"><?= icon($icons[$k] ?? 'users') ?></span>
        <span class="audience-text"><strong><?= esc($l) ?></strong><small><?= isset($counts[$k]) ? fa_number($counts[$k]) . ' نفر' : 'پس از انتخاب شهر' ?></small></span>
      </label>
    <?php endforeach; ?>
  </div>
  <?php if (isset($options['doctors_city'])): ?>
    <div class="audience-city form-grid" data-geo data-audience-city<?= $selected === 'doctors_city' ? '' : ' hidden' ?>>
      <label>استان<select name="province_id"><option value="">انتخاب کنید</option><?php foreach ($provinces as $p): ?><option value="<?= (int) $p['id'] ?>"<?= selected_attr((int) ($post['province_id'] ?? 0) === (int) $p['id']) ?>><?= esc($p['name']) ?></option><?php endforeach; ?></select></label>
      <label>شهر<select name="city_id" data-selected="<?= (int) ($post['city_id'] ?? 0) ?>"><option value="">ابتدا استان را انتخاب کنید</option></select></label>
    </div>
  <?php endif; ?>
</fieldset>
