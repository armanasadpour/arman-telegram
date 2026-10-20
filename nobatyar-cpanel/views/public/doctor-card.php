<?php /** @var array $d @var bool $showCode @var bool $hideMobile */
$url = doctor_url((int) $d['id'], $d['name'], (int) $d['clinic_id']);
$first = $d['first_available'] ?? null;
?>
<article class="doc-card<?= !empty($hideMobile) ? ' hide-mobile' : '' ?>">
  <?php if ($first): ?><span class="doc-badge"><?= icon('calendar-check') ?> نوبت آزاد</span><?php endif; ?>
  <a class="doc-photo" href="<?= esc($url) ?>" tabindex="-1" aria-hidden="true">
    <?php if ($d['photo_file']): ?>
      <img src="photo.php?id=<?= (int) $d['id'] ?>" alt="" width="96" height="96" loading="lazy" decoding="async">
    <?php else: ?>
      <span class="doc-initial"><?= esc(mb_substr($d['name'], 0, 1)) ?></span>
    <?php endif; ?>
  </a>
  <h3><a href="<?= esc($url) ?>"><?= esc(doctor_display_name($d['name'])) ?></a><?= pro_badge($d) ?></h3>
  <p class="doc-spec"><?= icon('doctor') ?><?= esc(specialty_display_title($d['specialty'])) ?></p>
  <?php if (!empty($showCode) && !empty($d['medical_code'])): ?><p class="doc-meta">نظام پزشکی: <bdi><?= esc(fa_number($d['medical_code'])) ?></bdi></p><?php endif; ?>
  <p class="doc-meta"><?= icon('building') ?><span><?= esc($d['clinic_name']) ?> • <?= esc($d['city']) ?></span></p>
  <p class="doc-meta clamp"><?= icon('pin') ?><span><?= esc(mb_strimwidth((string) $d['address'], 0, 60, '…')) ?></span></p>
  <p class="doc-first<?= $first ? '' : ' none' ?>"><?= icon('clock') ?><span><?= $first ? 'اولین نوبت: <bdi>' . esc($first['label']) . '</bdi>' : 'نوبت آزاد در ۱۴ روز آینده ندارد' ?></span></p>
  <a class="button block" href="<?= esc($url) ?>">مشاهده نوبت‌ها</a>
</article>
