<?php
/**
 * Homepage landing. Sections render in the administrator's order; each one is skipped when disabled or
 * when it has no real data (no placeholder content is ever shown to visitors).
 * @var bool $preview @var bool $hasDraft @var array $layout @var ?array $city @var array $provinces @var array $capitals
 * @var string $notice @var array $chips @var array $banners @var array $specialtyCards @var array $available @var array $featured
 * @var array $newest @var array $allDoctors [cards, has more] @var array $centerCards @var array $faqs @var array $articles @var int $fee @var array $nearby @var bool $showCode
 */
$S = $layout['sections'];
$cityName = $city['name'] ?? '';
$inCity = fn(string $t) => $city ? $t : str_replace([' در شهر شما', ' شهر شما', ' شهر'], ['', '', ''], $t);
$head = fn(string $key, string $more = '', string $moreLabel = 'مشاهده همه') => render('home/section-head', ['id' => 'h-' . $key, 'title' => $inCity($S[$key]['title']), 'subtitle' => $S[$key]['subtitle'], 'more' => $more, 'moreLabel' => $moreLabel]);
$mobile = fn(string $key) => (int) ($S[$key]['mobile'] ?? 99);
$heroImage = home_image_url($S['hero']['image']) ?: 'assets/illustrations/hero.svg';
?>
<?php if ($preview): ?>
  <div class="preview-bar" role="status"><?= icon('eye') ?><span><?= $hasDraft ? 'پیش‌نمایش پیش‌نویس صفحه اصلی؛ بازدیدکنندگان هنوز نسخه منتشرشده را می‌بینند.' : 'پیش‌نمایش؛ پیش‌نویسی وجود ندارد و این همان نسخه منتشرشده است.' ?></span><a class="button small" href="admin.php?tab=home">بازگشت به مدیریت صفحه اصلی</a></div>
<?php endif; ?>
<?php if ($notice !== ''): ?><p class="home-notice" role="note"><?= icon('megaphone') ?><span><?= esc($notice) ?></span></p><?php endif; ?>
<?php
// 1.0.0-rc.21: a signed-in doctor, secretary or center staff gets their panel right on the home page.
$homeUser = user_now();
$homeWork = $homeUser ? account_workspaces($homeUser) : [];
if ($homeWork): ?>
  <section class="work-strip" aria-label="پنل‌های کاری شما">
    <?php foreach ($homeWork as $w): ?>
      <a class="work-card" href="<?= esc($w['url']) ?>">
        <span class="work-icon" aria-hidden="true"><?= icon($w['icon']) ?></span>
        <span class="work-text"><strong><?= esc($w['cta']) ?></strong><small><?= esc($w['text']) ?></small></span>
        <?= icon('chevron-left', 'icon work-go') ?>
      </a>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<?php if (!$homeUser): ?>
<section class="signup-cards" aria-label="ثبت‌نام در نوبت‌یار">
  <a class="signup-card signup-patient" href="login.php?next=patient.php">
    <span class="signup-art signup-person" aria-hidden="true"><?= icon('user') ?><i>+</i></span>
    <strong>ثبت‌نام کاربر</strong><small>نوبت بگیر، با خیال راحت</small>
    <span class="signup-link">شروع ثبت‌نام <?= icon('chevron-left') ?></span>
  </a>
  <a class="signup-card signup-doctor" href="register.php">
    <span class="signup-art"><img src="assets/illustrations/doctors.svg" alt="" width="140" height="110"></span>
    <strong>ثبت‌نام پزشک</strong><small>مطبت رو آنلاین کن</small>
    <span class="signup-link">پیوستن پزشکان <?= icon('chevron-left') ?></span>
  </a>
  <a class="signup-card signup-center" href="center-register.php">
    <span class="signup-art"><img src="assets/illustrations/centers.svg" alt="" width="140" height="110"></span>
    <strong>ثبت‌نام کلینیک</strong><small>مدیریت هوشمند کلینیک</small>
    <span class="signup-link">پیوستن کلینیک‌ها <?= icon('chevron-left') ?></span>
  </a>
</section>
<?php endif; ?>

<?php foreach ($layout['order'] as $key): if (empty($S[$key]['enabled'])) continue; ?>
<?php switch ($key): case 'hero': ?>
  <section class="hero" aria-labelledby="hero-title">
    <div class="hero-text">
      <h1 id="hero-title"><?= esc($S['hero']['title']) ?></h1>
      <?php if ($S['hero']['subtitle'] !== ''): ?><p class="hero-sub"><?= esc($S['hero']['subtitle']) ?></p><?php endif; ?>
      <?php
      // 1.0.0-rc.12: province and city chosen right here (no separate page); choosing the city applies it.
      $heroProvince = (int) ($city['province_id'] ?? 0);
      $heroCities = $heroProvince ? cities_of_province($heroProvince) : [];
      ?>
      <div class="hero-box">
        <form class="hero-loc" action="city.php" method="get" data-geo aria-label="انتخاب استان و شهر">
          <input type="hidden" name="back" value="index.php">
          <span class="hero-loc-icon" aria-hidden="true"><?= icon('pin') ?></span>
          <label class="hero-sel"><span class="sr-only">استان</span>
            <select name="province" aria-label="استان">
              <option value="">استان</option>
              <?php foreach (provinces_list() as $p): ?><option value="<?= (int) $p['id'] ?>"<?= selected_attr($heroProvince === (int) $p['id']) ?>><?= esc($p['name']) ?></option><?php endforeach; ?>
            </select>
          </label>
          <label class="hero-sel"><span class="sr-only">شهر</span>
            <select name="city" aria-label="شهر" data-autosubmit data-selected="<?= (int) ($city['id'] ?? 0) ?>">
              <option value=""><?= $heroProvince ? 'شهر' : 'ابتدا استان' ?></option>
              <?php foreach ($heroCities as $c): ?><option value="<?= (int) $c['id'] ?>"<?= selected_attr((int) ($city['id'] ?? 0) === (int) $c['id']) ?>><?= esc($c['name']) ?></option><?php endforeach; ?>
            </select>
          </label>
          <noscript><button class="hero-loc-go">تأیید</button></noscript>
        </form>
        <form class="hero-search" action="index.php" method="get" role="search" data-suggest>
          <label class="sr-only" for="hero-q">نام پزشک، تخصص یا مرکز درمانی</label>
          <input id="hero-q" name="q" type="search" autocomplete="off" placeholder="نام پزشک، تخصص یا مرکز درمانی…" maxlength="190" role="combobox" aria-expanded="false" aria-controls="hero-suggest" aria-autocomplete="list" data-suggest-input>
          <input type="hidden" name="scope" value="<?= $city ? 'city' : 'all' ?>">
          <button aria-label="جستجوی پزشک"><?= icon('search') ?><span>جستجو</span></button>
          <div class="suggest-box" id="hero-suggest" role="listbox" hidden data-suggest-list></div>
        </form>
      </div>
      <?php if ($chips): ?>
        <div class="hero-chips"><span>پرجستجوها:</span>
          <?php foreach ($chips as $c): ?><a href="<?= esc(specialty_url((int) $c['id'], $c['name'])) ?>"><?= esc(specialty_display_title($c['name'])) ?></a><?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if (!$city): ?>
        <div class="hero-pick" data-city-prompt>
          <strong><?= icon('map-pinned') ?> شهر خود را انتخاب کنید</strong>
          <div class="chip-row">
            <?php foreach (array_slice($capitals, 0, 8) as $c): ?><a class="chip" href="index.php?city=<?= (int) $c['id'] ?>"><?= esc($c['name']) ?></a><?php endforeach; ?>
            <a class="chip ghost" href="city.php">همه شهرها</a>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <div class="hero-art"><img src="<?= esc($heroImage) ?>" alt="" width="520" height="400" fetchpriority="high"></div>
  </section>
<?php break; case 'services': $v = $S['services']; ?>
  <section class="service-cards" aria-label="خدمات اصلی">
    <?php foreach (['doctors' => ['doctors.svg', 'stethoscope'], 'centers' => ['centers.svg', 'hospital']] as $card => [$art, $ic]): ?>
      <a class="service-card <?= $card ?>" href="<?= esc($v[$card . '_url']) ?>">
        <span class="service-text">
          <span class="service-icon"><?= icon($ic === 'stethoscope' ? 'doctor' : 'hospital') ?></span>
          <strong><?= esc($v[$card . '_title']) ?></strong>
          <span><?= esc($v[$card . '_text']) ?></span>
          <span class="service-cta"><?= esc($v[$card . '_label']) ?><?= icon('chevron-left') ?></span>
        </span>
        <img src="<?= esc(home_image_url($v[$card . '_image']) ?: 'assets/illustrations/' . $art) ?>" alt="" width="320" height="240" loading="lazy" decoding="async">
      </a>
    <?php endforeach; ?>
  </section>
<?php break; case 'banners': if (!$banners) break; ?>
  <section class="home-banners" aria-label="بنرها">
    <div class="rail" data-rail>
      <?php foreach ($banners as $b): ?>
        <article class="home-banner<?= $b['image_file'] ? ' has-image' : '' ?>">
          <?php if ($b['image_file']): ?><img src="media.php?kind=banner&amp;file=<?= esc($b['image_file']) ?>" alt="" width="1200" height="400" loading="lazy" decoding="async"><?php endif; ?>
          <div class="home-banner-text">
            <h2><?= esc($b['title']) ?></h2>
            <?php if ($b['subtitle'] !== ''): ?><p><?= esc($b['subtitle']) ?></p><?php endif; ?>
            <?php if ($b['link_url']): ?><a class="button<?= $b['image_file'] ? ' light' : '' ?>" href="<?= esc($b['link_url']) ?>"<?= str_starts_with($b['link_url'], 'https://') ? ' rel="noopener" target="_blank"' : '' ?>><?= esc($b['link_label'] !== '' ? $b['link_label'] : 'بیشتر بدانید') ?></a><?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>
<?php break; case 'specialties': if (!$specialtyCards) break; ?>
  <section class="home-sec" aria-labelledby="h-specialties">
    <?php $head('specialties'); ?>
    <?php
    $specCard = function (array $s): void { ?>
        <a class="spec-card" href="<?= esc(specialty_url((int) $s['id'], $s['name'])) ?>">
          <?= specialty_visual($s, 'spec-icon') ?><strong><?= esc(specialty_display_title($s['name'], $s['level'] ?? null)) ?></strong>
        </a>
    <?php };
    $withDoctors = array_filter($specialtyCards, fn($s) => (int) $s['doctors'] > 0); ?>
    <div class="spec-grid cols">
      <?php foreach ($withDoctors as $s) $specCard($s); ?>
      <button type="button" class="spec-card spec-more" data-spec-toggle aria-expanded="false" aria-controls="spec-all"><span class="spec-icon"><?= icon('grip') ?></span><strong>همه تخصص‌ها</strong><span class="spec-chev"><?= icon('chevron-down') ?></span></button>
    </div>
    <div class="spec-all" id="spec-all" data-spec-all hidden>
      <h3>همه تخصص‌ها</h3>
      <div class="spec-grid cols"><?php foreach ($specialtyCards as $s) $specCard($s); ?></div>
    </div>
  </section>
<?php break; case 'available': ?>
  <section class="home-sec" aria-labelledby="h-available">
    <?php $head('available', 'index.php?' . http_build_query(['view' => 'doctors', 'sort' => 'first', 'available' => 1, 'scope' => $city ? 'city' : 'all'])); ?>
    <?php if ($available): ?>
      <div class="rail doc-rail" data-rail>
        <?php foreach ($available as $i => $d) render('public/doctor-card', ['d' => $d, 'showCode' => $showCode, 'hideMobile' => $i >= $mobile('available')]); ?>
      </div>
    <?php else: ?>
      <div class="empty-state wide">
        <?= icon('calendar-clock') ?>
        <p><?= $city ? 'فعلاً در ' . esc($cityName) . ' پزشکی با نوبت آزاد در دو هفته آینده نداریم.' : 'فعلاً نوبت آزادی ثبت نشده است.' ?></p>
        <div class="chip-row">
          <?php if ($city && $nearby): ?><a class="button secondary" href="index.php?scope=nearby">جستجو در شهرهای اطراف</a><?php endif; ?>
          <a class="button secondary" href="index.php?scope=all">جستجو در سراسر کشور</a>
          <a class="button ghost" href="register.php">پزشک هستید؟ ثبت‌نام کنید</a>
        </div>
      </div>
    <?php endif; ?>
  </section>
<?php break; case 'all_doctors': [$docs, $docsMore] = $allDoctors; if (!$docs) break; ?>
  <section class="home-sec" aria-labelledby="h-all_doctors">
    <div class="sec-head"><div><h2 id="h-all_doctors" class="sec-title-icon"><span class="sec-icon"><?= icon('users') ?></span><?= esc($S['all_doctors']['title']) ?></h2><?php if ($S['all_doctors']['subtitle'] !== ''): ?><p><?= esc($S['all_doctors']['subtitle']) ?></p><?php endif; ?></div></div>
    <div class="doc-grid" data-docs-grid>
      <?php foreach ($docs as $d) render('public/doctor-card', ['d' => $d, 'showCode' => $showCode]); ?>
    </div>
    <?php if ($docsMore): ?>
      <div class="docs-more"><button type="button" class="button secondary" data-docs-more data-offset="<?= count($docs) ?>"><?= icon('chevron-down') ?> بیشتر</button></div>
    <?php endif; ?>
  </section>
<?php break; case 'promo': $v = $S['promo']; ?>
  <section class="promo theme-<?= esc($v['theme']) ?>" aria-labelledby="h-promo">
    <div class="promo-text">
      <h2 id="h-promo"><?= esc($v['title']) ?></h2>
      <?php if ($v['subtitle'] !== ''): ?><p><?= esc($v['subtitle']) ?></p><?php endif; ?>
      <a class="button light" href="<?= esc($v['url']) ?>"><?= icon('user-plus') ?><?= esc($v['label']) ?></a>
    </div>
    <img src="<?= esc(home_image_url($v['image']) ?: 'assets/illustrations/promo.svg') ?>" alt="" width="360" height="260" loading="lazy" decoding="async">
  </section>
<?php break; case 'featured': case 'newest': $list = $key === 'featured' ? $featured : $newest; if (!$list) break; ?>
  <section class="home-sec" aria-labelledby="h-<?= $key ?>">
    <?php $head($key, 'index.php?view=doctors&scope=' . ($city ? 'city' : 'all')); ?>
    <div class="rail doc-rail" data-rail>
      <?php foreach ($list as $i => $d) render('public/doctor-card', ['d' => $d, 'showCode' => $showCode, 'hideMobile' => $i >= $mobile($key)]); ?>
    </div>
  </section>
<?php break; case 'steps': ?>
  <section class="home-sec steps-sec" aria-labelledby="h-steps">
    <?php $head('steps'); ?>
    <ol class="steps">
      <li><span class="step-icon"><?= icon('search') ?></span><strong>انتخاب شهر و جستجوی پزشک</strong><span>شهرت رو انتخاب کن و پزشک یا تخصص مورد نظرت رو پیدا کن.</span></li>
      <li><span class="step-icon"><?= icon('calendar-clock') ?></span><strong>انتخاب تاریخ و ساعت مراجعه</strong><span>از بین زمان‌های آزاد، روز و ساعت مناسب رو انتخاب کن.</span></li>
      <li><span class="step-icon"><?= icon($fee > 0 ? 'card' : 'check') ?></span><strong><?= $fee > 0 ? 'پرداخت کارمزد رزرو و دریافت کد رهگیری' : 'تأیید نوبت و دریافت کد رهگیری' ?></strong><span><?= $fee > 0 ? 'با پرداخت ' . esc(toman($fee)) . ' کارمزد، نوبتت قطعی می‌شه. هزینه ویزیت در مطب پرداخت می‌شه.' : 'رزرو رایگانه؛ با تأیید، کد رهگیری پیامک می‌شه.' ?></span></li>
    </ol>
  </section>
<?php break; case 'centers': if (!$centerCards) break; ?>
  <section class="home-sec" aria-labelledby="h-centers">
    <?php $head('centers', $city ? 'centers.php?city=' . (int) $city['id'] : 'centers.php'); ?>
    <div class="rail center-rail" data-rail>
      <?php foreach ($centerCards as $i => $c): ?>
        <a class="center-tile<?= $i >= $mobile('centers') ? ' hide-mobile' : '' ?>" href="clinic.php?id=<?= (int) $c['id'] ?>">
          <span class="center-cover"><?php if ($c['logo_file']): ?><img src="media.php?kind=center&amp;file=<?= esc($c['logo_file']) ?>" alt="" width="96" height="96" loading="lazy"><?php else: ?><?= icon('hospital') ?><?php endif; ?></span>
          <strong><?= esc($c['name']) ?></strong>
          <small><?= esc(center_kinds()[$c['kind']] ?? '') ?> • <?= fa_number($c['doctors']) ?> پزشک</small>
          <?php if (!empty($c['city']) || $city): ?><small class="muted"><?= icon('pin') ?><?= esc($c['city'] ?? $cityName) ?></small><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
<?php break; case 'why': ?>
  <section class="home-sec why-sec" aria-labelledby="h-why">
    <?php $head('why'); ?>
    <ul class="why-grid">
      <li><?= icon('calendar-check') ?><strong>رزرو در چند لحظه</strong><span>بدون تماس تلفنی و انتظار پشت خط</span></li>
      <li><?= icon('bell') ?><strong>یادآوری پیش از نوبت</strong><span>پیامک و اعلان پیش از زمان مراجعه</span></li>
      <li><?= icon('wallet') ?><strong>ویزیت در مطب</strong><span>هزینه ویزیت را فقط به پزشک می‌پردازید</span></li>
      <li><?= icon('chat') ?><strong>پشتیبانی همراه</strong><span>از رزرو تا مراجعه کنار شما هستیم</span></li>
    </ul>
  </section>
<?php break; case 'faq': if (!$faqs) break; ?>
  <section class="home-sec faq-sec" aria-labelledby="h-faq">
    <?php $head('faq', 'faq.php', 'همه سوالات'); ?>
    <div class="faq-layout">
      <div class="faq-list">
        <?php foreach ($faqs as $i => $f): ?>
          <details class="faq<?= $i >= $mobile('faq') ? ' hide-mobile' : '' ?>"<?= $i === 0 ? ' open' : '' ?>>
            <summary><?= esc($f['question']) ?><?= icon('chevron-down', 'icon chev') ?></summary>
            <div class="faq-answer"><?= rich_text(faq_answer($f['answer'])) ?></div>
          </details>
        <?php endforeach; ?>
      </div>
      <aside class="faq-help">
        <span class="faq-help-icon"><?= icon('heart-handshake') ?></span>
        <strong>هنوز سوالی داری؟</strong>
        <p>تیم پشتیبانی پاسخ‌گوی سوال‌های شماست.</p>
        <a class="button secondary" href="support.php"><?= icon('chat') ?> تماس با پشتیبانی</a>
      </aside>
    </div>
  </section>
<?php break; case 'articles': if (!$articles) break; ?>
  <section class="home-sec" aria-labelledby="h-articles">
    <?php $head('articles', 'articles.php', 'همه مقالات'); ?>
    <div class="rail article-rail" data-rail>
      <?php foreach ($articles as $i => $a): ?>
        <a class="article-card<?= $i >= $mobile('articles') ? ' hide-mobile' : '' ?>" href="<?= esc(article_url($a['slug'])) ?>">
          <span class="article-cover"><?php if ($a['cover_file']): ?><img src="media.php?kind=article&amp;file=<?= esc($a['cover_file']) ?>" alt="" width="600" height="315" loading="lazy" decoding="async"><?php else: ?><?= icon('article') ?><?php endif; ?></span>
          <span class="article-body">
            <?php if ($a['specialty']): ?><span class="tag"><?= esc(specialty_display_title($a['specialty'])) ?></span><?php endif; ?>
            <strong><?= esc($a['title']) ?></strong>
            <?php if ($a['summary'] !== ''): ?><span class="clamp2"><?= esc($a['summary']) ?></span><?php endif; ?>
            <small><bdi><?= esc(jdate($a['published_at'])) ?></bdi> • <?= fa_number(reading_minutes($a['body'])) ?> دقیقه مطالعه</small>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
<?php break; endswitch; ?>
<?php endforeach; ?>
