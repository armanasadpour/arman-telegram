<?php /** @var array $c @var array $branches @var array $byBranch @var int $doctorCount */ ?>
<section class="public-hero clinic-hero">
  <?php if ($c['logo_file']): ?>
    <img class="doctor-avatar" src="media.php?kind=center&amp;file=<?= esc($c['logo_file']) ?>" alt="لوگوی <?= esc($c['name']) ?>" width="120" height="120">
  <?php else: ?>
    <span class="doctor-avatar avatar-placeholder" aria-hidden="true"><?= icon('building') ?></span>
  <?php endif; ?>
  <div>
    <span class="eyebrow"><?= esc(center_kinds()[$c['kind']] ?? '') ?> • <?= esc($c['province'] . '، ' . $c['city']) ?></span>
    <h1><?= esc($c['name']) ?></h1>
    <p class="muted"><?= icon('doctor') ?> <?= fa_number($doctorCount) ?> پزشک فعال • <?= fa_number(count($branches)) ?> شعبه • کد مرکز <bdi><?= fa_number($c['id']) ?></bdi></p>
    <p><a class="button secondary small" href="tel:<?= esc($c['phone']) ?>"><?= icon('phone') ?> <bdi><?= esc(fa_number($c['phone'])) ?></bdi></a></p>
  </div>
</section>
<?php if ($c['description']): ?>
  <article class="card"><h2>درباره مرکز</h2><p><?= nl2br(esc($c['description'])) ?></p></article>
<?php endif; ?>
<?php foreach ($branches as $b): ?>
  <section class="panel branch-public">
    <div class="between">
      <h2><?= icon('pin') ?> <?= esc($b['name']) ?></h2>
      <?php if ($b['latitude'] !== null): ?>
        <a class="button secondary small" href="https://www.openstreetmap.org/?mlat=<?= esc($b['latitude']) ?>&amp;mlon=<?= esc($b['longitude']) ?>#map=17/<?= esc($b['latitude']) ?>/<?= esc($b['longitude']) ?>" target="_blank" rel="noopener">مسیریابی روی نقشه</a>
      <?php endif; ?>
    </div>
    <p class="muted"><?= esc($b['city'] . '، ' . $b['address']) ?><?= $b['phone'] ? ' • <bdi>' . esc(fa_number($b['phone'])) . '</bdi>' : '' ?></p>
    <div class="doctor-grid">
      <?php foreach ($byBranch[$b['id']] ?? [] as $d): ?>
        <article class="doctor-card">
          <div class="doctor-card-head">
            <?php if ($d['photo_file']): ?>
              <img class="avatar" src="photo.php?id=<?= (int) $d['id'] ?>" alt="" loading="lazy" width="64" height="64">
            <?php else: ?>
              <span class="avatar placeholder" aria-hidden="true"><?= esc(mb_substr($d['name'], 0, 1)) ?></span>
            <?php endif; ?>
            <div>
              <h3><a href="doctor.php?id=<?= (int) $d['id'] ?>&amp;clinic=<?= (int) $d['clinic_id'] ?>"><?= esc(doctor_display_name($d['name'])) ?></a><?= pro_badge($d) ?></h3>
              <span class="tag"><?= esc(specialty_display_title($d['specialty'])) ?></span>
            </div>
          </div>
          <?php if ($d['professional_title']): ?><p class="doctor-meta"><?= esc($d['professional_title']) ?></p><?php endif; ?>
          <a class="button block" href="doctor.php?id=<?= (int) $d['id'] ?>&amp;clinic=<?= (int) $d['clinic_id'] ?>">نوبت در این شعبه</a>
        </article>
      <?php endforeach; ?>
    </div>
    <?php if (empty($byBranch[$b['id']])): ?><p class="muted">در حال حاضر پزشکی با نوبت‌دهی اینترنتی در این شعبه فعال نیست.</p><?php endif; ?>
  </section>
<?php endforeach; ?>
