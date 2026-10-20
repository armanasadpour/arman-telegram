<?php
/**
 * Appointment summary card used on the patient and payment steps.
 * @var array $s keys: doctor_id, doctor, photo_file, specialty, clinic, address, city, starts_at, service, patient (optional)
 * @var bool $collapsible
 */
$photo = !empty($s['photo_file']) ? '<img src="photo.php?id=' . (int) $s['doctor_id'] . '" alt="" width="64" height="64">' : esc(mb_substr(trim(preg_replace('/^دکتر\s+/u', '', $s['doctor'])), 0, 1));
$head = '<span class="bs-photo">' . $photo . '</span><span class="bs-id"><strong>' . esc(doctor_display_name($s['doctor'])) . pro_badge(['id'=>(int)$s['doctor_id']]) . '</strong><small>حضوری • ' . esc(jalali_day_label($s['starts_at']) . ' ' . fa_number(jalali_parts($s['starts_at'])[0])) . ' - <bdi>' . esc(fa_number(substr($s['starts_at'], 11, 5))) . '</bdi></small></span>';
$rows = [['تخصص', $s['specialty']], ['خدمت', $s['service']], ['مطب', $s['clinic']], ['آدرس', $s['city'] . '، ' . $s['address']]];
if (!empty($s['patient'])) {
    $rows[] = ['نام مراجعه‌کننده', $s['patient']];
}
?>
<?php if ($collapsible): ?>
<details class="booking-summary" open>
  <summary><?= $head ?><?= icon('chevron-down', 'icon chev') ?></summary>
<?php else: ?>
<section class="booking-summary">
  <div class="bs-head"><?= $head ?></div>
<?php endif; ?>
  <dl class="bs-rows">
    <?php foreach ($rows as [$k, $v]): ?><div><dt><?= esc($k) ?></dt><dd><?= esc($v) ?></dd></div><?php endforeach; ?>
  </dl>
<?= $collapsible ? '</details>' : '</section>' ?>
