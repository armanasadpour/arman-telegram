<?php
declare(strict_types=1);

/**
 * Homepage composition, FAQ, health articles, site pages and public URLs.
 *
 * Layout: the order, visibility, texts, images, links and card counts of every homepage section are one
 * JSON document. The administrator edits a draft (setting home_layout_draft), previews it on the real
 * homepage (index.php?preview=1, administrators only) and publishes it to home_layout. Missing keys
 * always fall back to the built-in defaults, so new sections in later versions appear without migration.
 * FAQ, articles, featured doctors and specialty visibility are rows that take effect when saved.
 */

function home_section_catalog(): array
{
    return [
        'hero' => ['label' => 'بخش اصلی و جستجو', 'title' => 'نوبت پزشکت رو راحت و سریع بگیر', 'subtitle' => 'پزشک مورد نظرت رو در شهرت پیدا کن و در چند لحظه نوبت بگیر.', 'counts' => false],
        'services' => ['label' => 'کارت‌های خدمات اصلی', 'title' => '', 'subtitle' => '', 'counts' => false],
        'banners' => ['label' => 'اسلایدر و بنرها', 'title' => '', 'subtitle' => '', 'counts' => false],
        'specialties' => ['label' => 'تخصص‌ها', 'title' => 'دنبال چه تخصصی هستی؟', 'subtitle' => '', 'counts' => true, 'desktop' => 12, 'mobile' => 8],
        'available' => ['label' => 'پزشکان دارای نوبت آزاد', 'title' => 'پزشکان دارای نوبت در شهر شما', 'subtitle' => 'نزدیک‌ترین زمان‌های آزاد پزشکان تأییدشده', 'counts' => true, 'desktop' => 8, 'mobile' => 6],
        'promo' => ['label' => 'بنر تبلیغاتی پزشکان', 'title' => 'پزشک هستید؟', 'subtitle' => 'با نوبت‌یار، مدیریت نوبت‌های مطب خود را ساده‌تر کنید.', 'counts' => false],
        'featured' => ['label' => 'پزشکان منتخب', 'title' => 'پزشکان منتخب شهر', 'subtitle' => '', 'counts' => true, 'desktop' => 8, 'mobile' => 6],
        'all_doctors' => ['label' => 'همه پزشکان', 'title' => 'همه پزشکان', 'subtitle' => '', 'counts' => true, 'paged' => true, 'desktop' => 10, 'mobile' => 10],
        'newest' => ['label' => 'پزشکان تازه‌عضو', 'title' => 'پزشکان تازه‌عضوشده', 'subtitle' => '', 'counts' => true, 'desktop' => 8, 'mobile' => 6],
        'steps' => ['label' => 'مراحل دریافت نوبت', 'title' => 'چطور از نوبت‌یار نوبت بگیریم؟', 'subtitle' => '', 'counts' => false],
        'centers' => ['label' => 'مراکز درمانی', 'title' => 'مراکز درمانی شهر شما', 'subtitle' => '', 'counts' => true, 'desktop' => 4, 'mobile' => 4],
        'why' => ['label' => 'چرا نوبت‌یار', 'title' => 'چرا نوبت‌یار؟', 'subtitle' => '', 'counts' => false],
        'faq' => ['label' => 'سوالات متداول', 'title' => 'سوالات متداول', 'subtitle' => '', 'counts' => true, 'desktop' => 6, 'mobile' => 4],
        'articles' => ['label' => 'مقالات سلامت', 'title' => 'آخرین مقالات سلامت', 'subtitle' => '', 'counts' => true, 'desktop' => 4, 'mobile' => 2],
    ];
}

function home_default_layout(): array
{
    $sections = [];
    foreach (home_section_catalog() as $key => $meta) {
        $sections[$key] = ['enabled' => true, 'title' => $meta['title'], 'subtitle' => $meta['subtitle']] +
            ($meta['counts'] ? ['desktop' => $meta['desktop'], 'mobile' => $meta['mobile']] : []);
    }
    $sections['hero'] += ['image' => '', 'chips' => []];
    $sections['services'] += [
        'doctors_title' => 'نوبت‌دهی پزشکان', 'doctors_text' => 'پزشک مورد نظرت رو پیدا کن و اولین نوبت آزاد رو رزرو کن.', 'doctors_label' => 'مشاهده پزشکان', 'doctors_url' => 'index.php?view=doctors', 'doctors_image' => '',
        'centers_title' => 'مراکز درمانی', 'centers_text' => 'کلینیک‌ها و مراکز درمانی شهر خودت رو مشاهده کن.', 'centers_label' => 'مشاهده مراکز درمانی', 'centers_url' => 'centers.php', 'centers_image' => '',
    ];
    $sections['promo'] += ['label' => 'ثبت‌نام پزشکان', 'url' => 'register.php', 'image' => '', 'theme' => 'blue'];
    return ['order' => array_keys(home_section_catalog()), 'sections' => $sections];
}

function home_promo_themes(): array
{
    return ['blue' => 'آبی', 'indigo' => 'نیلی', 'teal' => 'فیروزه‌ای', 'navy' => 'سرمه‌ای'];
}

/** Stored layout merged over the defaults; $draft reads the unpublished version when there is one. */
function home_layout(bool $draft = false): array
{
    $layout = home_default_layout();
    $raw = $draft ? setting('home_layout_draft', '') : '';
    if ($raw === '') {
        $raw = setting('home_layout', '');
    }
    $stored = $raw !== '' ? json_decode($raw, true) : null;
    if (is_array($stored)) {
        foreach ($stored['sections'] ?? [] as $key => $values) {
            if (isset($layout['sections'][$key]) && is_array($values)) {
                $layout['sections'][$key] = array_intersect_key($values, $layout['sections'][$key]) + $layout['sections'][$key];
            }
        }
        $order = array_values(array_filter((array) ($stored['order'] ?? []), fn($k) => isset($layout['sections'][$k])));
        $layout['order'] = array_values(array_unique(array_merge($order, $layout['order'])));
    }
    return $layout;
}

function home_has_draft(): bool
{
    return setting('home_layout_draft', '') !== '';
}

/** Validates the admin form into a layout and stores it as the draft. */
function home_save_draft(array $d, array $files): string
{
    $layout = home_layout(true);
    $catalog = home_section_catalog();
    $order = array_values(array_filter((array) ($d['order'] ?? []), fn($k) => is_string($k) && isset($catalog[$k])));
    if ($order) {
        $layout['order'] = array_values(array_unique(array_merge($order, array_keys($catalog))));
    }
    foreach ($catalog as $key => $meta) {
        $in = (array) ($d['s'][$key] ?? []);
        if (!$in) {
            continue;
        }
        $s = &$layout['sections'][$key];
        $s['enabled'] = !empty($in['enabled']);
        // An emptied title falls back to the built-in one, so a section never loses its heading.
        $s['title'] = mb_substr(trim((string) ($in['title'] ?? $s['title'])), 0, 120) ?: $meta['title'];
        $s['subtitle'] = mb_substr(trim((string) ($in['subtitle'] ?? $s['subtitle'])), 0, 300);
        if ($meta['counts']) {
            $s['desktop'] = max(1, min(24, (int) digits((string) ($in['desktop'] ?? $s['desktop']))));
            $s['mobile'] = max(1, min($s['desktop'], (int) digits((string) ($in['mobile'] ?? $s['mobile']))));
        }
        unset($s);
    }
    $hero = &$layout['sections']['hero'];
    if (isset($d['hero_chips'])) {
        $hero['chips'] = array_slice(array_values(array_unique(array_filter(array_map('intval', (array) $d['hero_chips'])))), 0, 8);
    }
    unset($hero);
    $svc = &$layout['sections']['services'];
    foreach (['doctors', 'centers'] as $card) {
        foreach (['title' => 60, 'text' => 200, 'label' => 40] as $f => $max) {
            if (isset($d['svc'][$card . '_' . $f])) {
                $svc[$card . '_' . $f] = mb_substr(trim((string) $d['svc'][$card . '_' . $f]), 0, $max);
            }
        }
        if (isset($d['svc'][$card . '_url'])) {
            $svc[$card . '_url'] = safe_link((string) $d['svc'][$card . '_url']) ?: $svc[$card . '_url'];
        }
    }
    unset($svc);
    $promo = &$layout['sections']['promo'];
    if (isset($d['promo_label'])) {
        $promo['label'] = mb_substr(trim((string) $d['promo_label']), 0, 40);
        $promo['url'] = safe_link((string) ($d['promo_url'] ?? '')) ?: 'register.php';
        $promo['theme'] = isset(home_promo_themes()[$d['promo_theme'] ?? '']) ? $d['promo_theme'] : 'blue';
    }
    unset($promo);
    // Images: hero, the two service cards and the promo banner.
    foreach (['hero' => ['hero', 'image', 1000, 760], 'doctors_image' => ['services', 'doctors_image', 640, 480], 'centers_image' => ['services', 'centers_image', 640, 480], 'promo' => ['promo', 'image', 720, 540]] as $field => [$section, $key, $w, $h]) {
        if (($files['img_' . $field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $layout['sections'][$section][$key] = store_cover_image($files['img_' . $field], 'home', $w, $h, true);
        } elseif (!empty($d['remove_' . $field])) {
            $layout['sections'][$section][$key] = '';
        }
    }
    setting_set('home_layout_draft', json_encode($layout, JSON_UNESCAPED_UNICODE));
    audit('home.draft');
    return 'پیش‌نویس ذخیره شد. پیش‌نمایش را ببینید و سپس منتشر کنید.';
}

function home_publish(): string
{
    if (!home_has_draft()) {
        throw new RuntimeException('پیش‌نویس منتشرنشده‌ای وجود ندارد.');
    }
    setting_set('home_layout', setting('home_layout_draft'));
    setting_set('home_layout_draft', '');
    audit('home.publish');
    return 'صفحه اصلی منتشر شد.';
}

function home_discard(): string
{
    setting_set('home_layout_draft', '');
    audit('home.discard');
    return 'پیش‌نویس کنار گذاشته شد؛ نسخه منتشرشده بدون تغییر است.';
}

function home_image_url(string $file): string
{
    return $file !== '' ? 'media.php?kind=home&file=' . rawurlencode($file) : '';
}

/* ---------- Data for the sections ---------- */

/** SQL (1/0) for «Pro» like doctor_is_pro(): the administrator's override, else the subscription (everyone when sales are off). */
function doctor_pro_sql(string $alias = 'd'): string
{
    // The override is compared as text: MySQL and MariaDB disagree on JSON true/false arithmetic inside COALESCE.
    $override = "JSON_UNQUOTE(JSON_EXTRACT(COALESCE($alias.feature_overrides,'{}'),'$.pro'))";
    return "CASE WHEN $override IN ('true','1') THEN 1 WHEN $override IN ('false','0') THEN 0 ELSE " .
        (subscriptions_on() ? "($alias.subscription_until IS NOT NULL AND DATE_ADD($alias.subscription_until,INTERVAL " . grace_days() . ' DAY)>=CURDATE())' : '1') . ' END';
}

/**
 * 1.0.27 homepage ranking: Pro first, then most bookings, then most reviews, then the rest.
 * Returns [doctor id => [pro, bookings, reviews]].
 */
function doctor_rank_stats(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $out = [];
    foreach (query(
        'SELECT d.id,(' . doctor_pro_sql() . ")+0 pro,
            (SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.doctor_id=d.id AND b.status IN ('confirmed','completed')) bookings_n,
            (SELECT COUNT(*) FROM reviews r WHERE r.doctor_id=d.id AND r.status='approved') reviews_n
         FROM doctors d WHERE d.id IN (" . placeholders($ids) . ')',
        $ids,
    )->fetchAll() as $r) {
        $out[(int) $r['id']] = [(int) $r['pro'], (int) $r['bookings_n'], (int) $r['reviews_n']];
    }
    return $out;
}

/**
 * Public doctor cards: approved, active, open subscription, public clinic; one card per doctor.
 * $specialty (with its subspecialties) narrows the list for the homepage specialty filter.
 */
function home_doctor_cards(string $mode, ?array $city, int $limit, int $specialty = 0): array
{
    $where = visible_doctor_sql() . ' AND ' . public_clinic_sql();
    $args = [];
    if ($city) {
        $where .= ' AND c.id=?';
        $args[] = (int) $city['id'];
    }
    if ($specialty > 0) {
        $where .= ' AND (sp.id=? OR sp.parent_id=?)';
        array_push($args, $specialty, $specialty);
    }
    $order = match ($mode) {
        'featured' => 'd.featured_order,d.id',
        'newest' => 'COALESCE(d.status_changed_at,d.created_at) DESC,d.id DESC',
        default => 'd.featured DESC,d.id DESC',
    };
    if ($mode === 'featured') {
        $where .= ' AND d.featured=1';
    }
    $rows = query(
        "SELECT d.id,d.name,d.photo_file,d.medical_code,d.professional_title,sp.name specialty,cl.id clinic_id,cl.name clinic_name,cl.address,cl.street_main,c.name city,p.name province
         FROM doctors d JOIN specialties sp ON sp.id=d.specialty_id JOIN clinics cl ON cl.doctor_id=d.id JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id
         WHERE $where ORDER BY $order,cl.is_primary DESC,cl.id LIMIT " . ($mode === 'available' ? 60 : $limit * 3),
        $args,
    )->fetchAll();
    $seen = [];
    $out = [];
    foreach ($rows as $r) {
        if (isset($seen[$r['id']])) {
            continue;
        }
        $r['first_available'] = first_available((int) $r['clinic_id'], 14);
        if ($mode === 'available' && !$r['first_available']) {
            continue;
        }
        $seen[$r['id']] = true;
        $out[] = $r;
    }
    if ($mode === 'available') {
        // 1.0.27: Pro by bookings, then reviews; then regular doctors the same way; the earlier free time breaks ties.
        $rank = doctor_rank_stats(array_column($out, 'id'));
        usort($out, function ($a, $b) use ($rank) {
            $x = $rank[(int) $a['id']] ?? [0, 0, 0];
            $y = $rank[(int) $b['id']] ?? [0, 0, 0];
            return [$y, $a['first_available']['starts_at']] <=> [$x, $b['first_available']['starts_at']];
        });
    }
    return array_slice($out, 0, $limit);
}

/**
 * «همه پزشکان» on the homepage, one batch at a time (the section's count; «بیشتر» loads the next batch).
 * Order (1.0.27): doctors with a free time first; then Pro before regular; within each, most bookings, then most
 * reviews.
 * Limited to the visitor's city when it has doctors, otherwise country-wide. Returns [cards, has more].
 */
function home_all_doctors(?array $city, int $offset, int $limit): array
{
    $cityCond = $city ? ' AND c.id=?' : '';
    $args = $city ? [(int) $city['id']] : [];
    $pro = doctor_pro_sql();
    // Fresh «first free time» for the clinics in scope, so «has a free time» is current.
    clinic_free_ensure(query('SELECT cl.id FROM clinics cl JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE ' . public_clinic_sql() . $cityCond, $args)->fetchAll(PDO::FETCH_COLUMN), 120);
    $sql = "SELECT d.id,d.name,d.photo_file,d.medical_code,d.professional_title,d.subscription_until,d.feature_overrides,sp.name specialty,($pro)+0 is_pro,
            (SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.doctor_id=d.id AND b.status IN ('confirmed','completed')) bookings_n,
            COALESCE(rv.a,0) rating,COALESCE(rv.n,0) reviews_n,
            EXISTS(SELECT 1 FROM clinics fcl JOIN clinic_free_cache f ON f.clinic_id=fcl.id WHERE fcl.doctor_id=d.id AND f.first_at>NOW()) has_free
         FROM doctors d JOIN specialties sp ON sp.id=d.specialty_id
         LEFT JOIN (SELECT doctor_id,AVG(rating) a,COUNT(*) n FROM reviews WHERE status='approved' GROUP BY doctor_id) rv ON rv.doctor_id=d.id
         WHERE " . visible_doctor_sql() . " AND EXISTS(SELECT 1 FROM clinics cl JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE cl.doctor_id=d.id AND " . public_clinic_sql() . $cityCond . ")
         ORDER BY has_free DESC,is_pro DESC,bookings_n DESC,reviews_n DESC,d.id
         LIMIT " . ($limit + 1) . ' OFFSET ' . max(0, $offset);
    $rows = query($sql, $args)->fetchAll();
    if ($city && !$rows && $offset === 0) {
        return home_all_doctors(null, 0, $limit);
    }
    $more = count($rows) > $limit;
    $out = [];
    foreach (array_slice($rows, 0, $limit) as $r) {
        $clinicSql = 'SELECT cl.id clinic_id,cl.name clinic_name,cl.address,cl.street_main,c.name city,p.name province FROM clinics cl JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE cl.doctor_id=? AND ' . public_clinic_sql();
        $cl = query($clinicSql . $cityCond . ' ORDER BY cl.is_primary DESC,cl.id LIMIT 1', array_merge([(int) $r['id']], $args))->fetch()
            ?: query($clinicSql . ' ORDER BY cl.is_primary DESC,cl.id LIMIT 1', [(int) $r['id']])->fetch();
        if (!$cl) {
            continue;
        }
        $r += $cl;
        $r['first_available'] = first_available((int) $r['clinic_id'], 14);
        $out[] = $r;
    }
    return [$out, $more];
}

/**
 * Homepage specialty categories: every active specialty (general, specialty, allied) — the grid shows those with
 * doctors, «همه تخصص‌ها» opens the full list — most doctors in the city
 * (or country) first, then the administrator's order. A specialty is hidden by deactivating it. When the city has
 * no doctors at all, country-wide counts decide the order.
 */
function home_specialties(?array $city, int $limit): array
{
    $args = [];
    $cityCond = '';
    if ($city) {
        $cityCond = ' AND c.id=?';
        $args[] = (int) $city['id'];
    }
    $rows = query(
        "SELECT s.id,s.name,s.icon,s.image_file,s.level,s.show_on_home,(SELECT COUNT(DISTINCT d.id) FROM doctors d JOIN specialties sp ON sp.id=d.specialty_id JOIN clinics cl ON cl.doctor_id=d.id JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE (sp.id=s.id OR sp.parent_id=s.id) AND " .
            visible_doctor_sql() . ' AND ' . public_clinic_sql() . $cityCond . ") doctors
         FROM specialties s WHERE s.active=1 AND s.deleted_at IS NULL AND s.level IN ('general','specialty','allied') ORDER BY doctors DESC,s.sort_order,s.name LIMIT " . max(1, $limit),
        $args,
    )->fetchAll();
    if ($city && !array_filter($rows, fn($r) => (int) $r['doctors'] > 0)) {
        return home_specialties(null, $limit);
    }
    return $rows;
}

/** Hero chips: the administrator's choice, otherwise the specialties with the most doctors. */
function home_chips(array $ids, ?array $city): array
{
    if ($ids) {
        $rows = query('SELECT id,name FROM specialties WHERE active=1 AND deleted_at IS NULL AND id IN (' . placeholders($ids) . ')', $ids)->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_values(array_filter(array_map(fn($id) => isset($rows[$id]) ? ['id' => $id, 'name' => $rows[$id]] : null, $ids)));
    }
    return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']], array_slice(array_filter(home_specialties($city, 40), fn($s) => $s['doctors'] > 0), 0, 6));
}

function faq_answer(string $text): string
{
    $fee = booking_fee();
    return strtr($text, [
        '{booking_fee}' => $fee > 0 ? toman($fee) : 'رایگان',
        '{cancel_hours}' => fa_number(setting('patient_cancel_hours', '2')),
    ]);
}

function faq_list(bool $activeOnly = true, int $limit = 100): array
{
    return query('SELECT * FROM faqs' . ($activeOnly ? ' WHERE active=1' : '') . ' ORDER BY sort_order,id LIMIT ' . max(1, $limit))->fetchAll();
}

function faq_save(array $d): string
{
    $id = (int) ($d['id'] ?? 0);
    $q = field($d, 'question', 255);
    $a = trim((string) ($d['answer'] ?? ''));
    if (mb_strlen($a) < 5 || mb_strlen($a) > 3000) {
        throw new RuntimeException('پاسخ باید بین ۵ تا ۳۰۰۰ نویسه باشد.');
    }
    $active = !empty($d['active']) ? 1 : 0;
    if ($id) {
        query('UPDATE faqs SET question=?,answer=?,active=? WHERE id=?', [$q, $a, $active, $id]);
    } else {
        query('INSERT INTO faqs(question,answer,active,sort_order) VALUES(?,?,?,(SELECT COALESCE(MAX(f.sort_order),0)+10 FROM faqs f))', [$q, $a, $active]);
        $id = (int) db()->lastInsertId();
    }
    audit('home.faq', $id);
    return 'سوال ذخیره شد.';
}

/** Reorders rows of a whitelisted table from a list of ids (drag and drop or arrow buttons). */
function reorder_rows(string $table, string $column, array $ids): void
{
    if (!in_array("$table.$column", ['faqs.sort_order', 'doctors.featured_order', 'site_banners.sort_order', 'specialties.sort_order'], true)) {
        throw new RuntimeException('ترتیب نامعتبر است.');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    db_transaction(function () use ($table, $column, $ids) {
        foreach ($ids as $i => $id) {
            query("UPDATE `$table` SET `$column`=? WHERE id=?", [($i + 1) * 10, $id]);
        }
    });
}

/* ---------- Light formatting for articles and pages ---------- */

/**
 * Safe text formatting: the text is escaped first, then blank lines make paragraphs, "## " starts a
 * heading and "- " list items. No HTML from the author reaches the page.
 */
function rich_text(string $text): string
{
    $html = '';
    foreach (preg_split("/\n\s*\n/u", str_replace("\r", '', trim($text))) as $block) {
        $lines = array_map('trim', explode("\n", $block));
        if (str_starts_with($lines[0], '## ')) {
            $html .= '<h2>' . esc(mb_substr($lines[0], 3)) . '</h2>';
            array_shift($lines);
            if (!$lines) {
                continue;
            }
        }
        if ($lines && array_reduce($lines, fn($c, $l) => $c && str_starts_with($l, '- '), true)) {
            $html .= '<ul>' . implode('', array_map(fn($l) => '<li>' . esc(mb_substr($l, 2)) . '</li>', $lines)) . '</ul>';
        } elseif ($lines) {
            $html .= '<p>' . implode('<br>', array_map('esc', $lines)) . '</p>';
        }
    }
    return $html;
}

function slugify(string $text): string
{
    $text = fa_normalize(mb_strtolower(trim($text)));
    $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text);
    return trim(mb_substr((string) $text, 0, 120), '-') ?: 'post';
}

/* ---------- Articles ---------- */

function article_save(array $d, array $files): string
{
    $id = (int) ($d['id'] ?? 0);
    $row = $id ? query('SELECT * FROM articles WHERE id=?', [$id])->fetch() : null;
    if ($id && !$row) {
        throw new RuntimeException('مقاله پیدا نشد.');
    }
    $title = field($d, 'title', 190);
    $slug = slugify(trim((string) ($d['slug'] ?? '')) !== '' ? (string) $d['slug'] : $title);
    if (query('SELECT id FROM articles WHERE slug=? AND id<>?', [$slug, $id])->fetch()) {
        $slug .= '-' . substr(bin2hex(random_bytes(3)), 0, 4);
    }
    $summary = field($d, 'summary', 400, false);
    $body = trim((string) ($d['body'] ?? ''));
    if (mb_strlen($body) < 20) {
        throw new RuntimeException('متن مقاله را کامل‌تر بنویسید.');
    }
    $status = ($d['status'] ?? '') === 'published' ? 'published' : 'draft';
    $published = $row['published_at'] ?? null;
    if (trim((string) ($d['published_at'] ?? '')) !== '') {
        $published = parse_jdate((string) $d['published_at']) . ' ' . date('H:i:s');
    } elseif ($status === 'published' && !$published) {
        $published = date('Y-m-d H:i:s');
    }
    $specialty = (int) ($d['specialty_id'] ?? 0) ?: null;
    $cover = $row['cover_file'] ?? null;
    if (($files['cover']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $cover = store_cover_image($files['cover'], 'articles', 1200, 630);
    } elseif (!empty($d['remove_cover'])) {
        $cover = null;
    }
    $values = [$slug, $title, $summary, $body, $cover, $specialty, $status, $published];
    if ($id) {
        query('UPDATE articles SET slug=?,title=?,summary=?,body=?,cover_file=?,specialty_id=?,status=?,published_at=?,updated_at=NOW() WHERE id=?', [...$values, $id]);
    } else {
        query('INSERT INTO articles(slug,title,summary,body,cover_file,specialty_id,status,published_at,author_admin_id,updated_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())', [...$values, $_SESSION['admin_id'] ?? null]);
        $id = (int) db()->lastInsertId();
    }
    if ($row && $row['cover_file'] && $row['cover_file'] !== $cover) {
        @unlink(media_path('articles', $row['cover_file']));
    }
    audit('article.save', $id, $status);
    $_GET['edit'] = (string) $id; // return to the saved article
    return $status === 'published' ? 'مقاله منتشر شد.' : 'پیش‌نویس مقاله ذخیره شد.';
}

function article_delete(int $id): void
{
    $row = query('SELECT * FROM articles WHERE id=?', [$id])->fetch();
    if (!$row) {
        throw new RuntimeException('مقاله پیدا نشد.');
    }
    query('DELETE FROM articles WHERE id=?', [$id]);
    if ($row['cover_file']) {
        @unlink(media_path('articles', $row['cover_file']));
    }
    audit('article.delete', $id);
}

/** Published articles: visible from their publication time on. */
function articles_public(int $limit, int $offset = 0, int $specialty = 0): array
{
    $args = [];
    $where = "a.status='published' AND a.published_at<=NOW()";
    if ($specialty) {
        $where .= ' AND a.specialty_id=?';
        $args[] = $specialty;
    }
    return query("SELECT a.*,s.name specialty FROM articles a LEFT JOIN specialties s ON s.id=a.specialty_id WHERE $where ORDER BY a.published_at DESC,a.id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset), $args)->fetchAll();
}

function reading_minutes(string $body): int
{
    return max(1, (int) ceil(count(preg_split('/\s+/u', trim($body))) / 200));
}

/* ---------- Site pages ---------- */

function site_page(string $slug, bool $publishedOnly = true): ?array
{
    $row = query('SELECT * FROM site_pages WHERE slug=?' . ($publishedOnly ? ' AND published=1' : ''), [$slug])->fetch();
    return $row ?: null;
}

function site_page_save(array $d): string
{
    $slug = (string) ($d['slug'] ?? '');
    if (!site_page($slug, false)) {
        throw new RuntimeException('صفحه پیدا نشد.');
    }
    $body = trim((string) ($d['body'] ?? ''));
    if (mb_strlen($body) < 5) {
        throw new RuntimeException('متن صفحه را بنویسید.');
    }
    query('UPDATE site_pages SET title=?,body=?,published=?,updated_at=NOW() WHERE slug=?', [field($d, 'title', 190), $body, !empty($d['published']) ? 1 : 0, $slug]);
    audit('home.page', null, $slug);
    return 'صفحه ذخیره شد.';
}

function published_pages(bool $fresh = false): array
{
    static $pages = null;
    if ($pages === null || $fresh) {
        $pages = query('SELECT slug,title FROM site_pages WHERE published=1')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $pages;
}

/* ---------- Footer extras ---------- */

function social_networks(): array
{
    return ['instagram' => 'اینستاگرام', 'telegram' => 'تلگرام', 'eitaa' => 'ایتا', 'bale' => 'بله', 'rubika' => 'روبیکا', 'aparat' => 'آپارات'];
}

function social_links(): array
{
    $links = json_decode(setting('social_links', '{}'), true);
    return is_array($links) ? array_intersect_key($links, social_networks()) : [];
}

function trust_badges(): array
{
    $rows = json_decode(setting('trust_badges', '[]'), true);
    return is_array($rows) ? array_values(array_filter($rows, fn($b) => is_array($b) && !empty($b['image']))) : [];
}

function footer_settings_save(array $d, array $files): string
{
    $links = [];
    foreach (social_networks() as $k => $label) {
        $v = trim((string) ($d['social'][$k] ?? ''));
        if ($v !== '') {
            if (!preg_match('~^https://[^\s<>"\']+$~', $v)) {
                throw new RuntimeException("نشانی $label باید با https:// شروع شود.");
            }
            $links[$k] = mb_substr($v, 0, 255);
        }
    }
    $badges = trust_badges();
    for ($i = 0; $i < 3; $i++) {
        $url = trim((string) ($d['badge_url'][$i] ?? ''));
        if ($url !== '' && !preg_match('~^https://[^\s<>"\']+$~', $url)) {
            throw new RuntimeException('پیوند نماد باید با https:// شروع شود.');
        }
        $image = $badges[$i]['image'] ?? '';
        if (($files['badge_' . $i]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $image = store_cover_image($files['badge_' . $i], 'home', 240, 240, true);
        } elseif (!empty($d['badge_remove'][$i])) {
            $image = '';
        }
        $badges[$i] = ['image' => $image, 'url' => $url, 'title' => mb_substr(trim((string) ($d['badge_title'][$i] ?? '')), 0, 60)];
    }
    settings_save([
        'social_links' => json_encode($links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'trust_badges' => json_encode(array_values(array_filter($badges, fn($b) => $b['image'] !== '')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'footer_about' => field($d, 'footer_about', 300, false),
        'support_address' => field($d, 'support_address', 200, false),
        'home_show_medical_code' => !empty($d['home_show_medical_code']) ? '1' : '0',
    ]);
    audit('home.footer');
    return 'تنظیمات پانویس ذخیره شد.';
}

/* ---------- Public URLs ---------- */

/** True when the web server rewrites friendly URLs (.htaccess sets NOBATYAR_REWRITE). */
function pretty_urls(): bool
{
    return ($_SERVER['NOBATYAR_REWRITE'] ?? $_SERVER['REDIRECT_NOBATYAR_REWRITE'] ?? getenv('NOBATYAR_REWRITE')) === '1';
}

function url_slug(string $name): string
{
    return rawurlencode(slugify($name));
}

function doctor_url(int $id, string $name = '', int $clinic = 0): string
{
    if (pretty_urls()) {
        return 'doctor/' . $id . ($name !== '' ? '/' . url_slug($name) : '') . ($clinic ? '?clinic=' . $clinic : '');
    }
    return 'doctor.php?id=' . $id . ($clinic ? '&clinic=' . $clinic : '');
}

function city_url(int $id, string $name = ''): string
{
    return pretty_urls() ? 'city/' . $id . ($name !== '' ? '/' . url_slug($name) : '') : 'index.php?city=' . $id;
}

function specialty_url(int $id, string $name = ''): string
{
    return pretty_urls() ? 'specialty/' . $id . ($name !== '' ? '/' . url_slug($name) : '') : 'index.php?specialty_id=' . $id;
}

function article_url(string $slug): string
{
    return pretty_urls() ? 'article/' . rawurlencode($slug) : 'article.php?slug=' . rawurlencode($slug);
}

function page_url(string $slug): string
{
    return pretty_urls() ? 'page/' . rawurlencode($slug) : 'page.php?slug=' . rawurlencode($slug);
}

/** Pages reached through a friendly URL need <base> so relative asset and page links keep working. */
function needs_base_href(): bool
{
    $uri = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return PHP_SAPI !== 'cli' && (bool) preg_match('~^' . preg_quote($dir, '~') . '/(doctor|city|specialty|article|page)/~', $uri);
}

/** Approved centers nationwide (for visitors who have not chosen a city). */
function public_centers_all(int $limit): array
{
    return query(
        "SELECT ce.id,ce.name,ce.kind,ce.logo_file,ce.address,ci.name city,(SELECT COUNT(DISTINCT l.doctor_id) FROM center_doctors l WHERE l.center_id=ce.id AND l.status='active') doctors FROM centers ce JOIN cities ci ON ci.id=ce.city_id WHERE " .
            center_open_sql('ce') . ' ORDER BY doctors DESC,ce.name LIMIT ' . max(1, min(50, $limit)),
    )->fetchAll();
}

/**
 * Search suggestions for the homepage box: matching visible doctors (in the visitor's city first),
 * specialties and centers. At most five of each; never returns unpublished or expired doctors.
 */
function search_suggest(string $q, ?array $city): array
{
    $q = fa_normalize(trim($q));
    if (mb_strlen($q) < 2) {
        return ['doctors' => [], 'specialties' => [], 'centers' => []];
    }
    $like = '%' . $q . '%';
    $args = [$like, $like];
    $cityOrder = '0';
    if ($city) {
        $cityOrder = 'MAX(c.id=?)';
        array_unshift($args, (int) $city['id']);
    }
    $doctors = query(
        "SELECT d.id,d.name,sp.name specialty,MIN(c.name) city,$cityOrder in_city FROM doctors d JOIN specialties sp ON sp.id=d.specialty_id JOIN clinics cl ON cl.doctor_id=d.id JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE " .
            visible_doctor_sql() . ' AND ' . public_clinic_sql() . ' AND (d.name LIKE ? OR sp.name LIKE ?) GROUP BY d.id,d.name,sp.name ORDER BY in_city DESC,d.name LIMIT 5',
        $args,
    )->fetchAll();
    $specialties = query("SELECT id,name FROM specialties WHERE active=1 AND deleted_at IS NULL AND name LIKE ? ORDER BY sort_order,name LIMIT 5", [$like])->fetchAll();
    $centers = query('SELECT ce.id,ce.name,ci.name city FROM centers ce JOIN cities ci ON ci.id=ce.city_id WHERE ' . center_open_sql('ce') . ' AND ce.name LIKE ? ORDER BY ce.name LIMIT 5', [$like])->fetchAll();
    return [
        'doctors' => array_map(fn($d) => ['name' => $d['name'], 'meta' => $d['specialty'] . ' • ' . $d['city'], 'url' => doctor_url((int) $d['id'], $d['name'])], $doctors),
        'specialties' => array_map(fn($s) => ['name' => $s['name'], 'meta' => 'تخصص', 'url' => specialty_url((int) $s['id'], $s['name'])], $specialties),
        'centers' => array_map(fn($c) => ['name' => $c['name'], 'meta' => 'مرکز درمانی • ' . $c['city'], 'url' => 'clinic.php?id=' . (int) $c['id']], $centers),
    ];
}

/* ---------- Admin: homepage manager data ---------- */

function admin_home_views(): array
{
    return ['layout' => 'چیدمان و متن‌ها', 'featured' => 'پزشکان منتخب', 'specialties' => 'تخصص‌های صفحه اصلی', 'faq' => 'سوالات متداول', 'pages' => 'صفحه‌ها', 'footer' => 'پانویس و نمادها', 'drawer' => 'منوی موبایل'];
}

function admin_home_data(): array
{
    $view = (string) ($_GET['view'] ?? 'layout');
    if (!isset(admin_home_views()[$view])) {
        $view = 'layout';
    }
    $data = ['view' => $view, 'views' => admin_home_views()];
    switch ($view) {
        case 'layout':
            return $data + [
                'layout' => home_layout(true),
                'catalog' => home_section_catalog(),
                'hasDraft' => home_has_draft(),
                'specialties' => query("SELECT id,name FROM specialties WHERE active=1 AND deleted_at IS NULL ORDER BY sort_order,name")->fetchAll(),
            ];
        case 'featured':
            $q = trim((string) ($_GET['q'] ?? ''));
            return $data + [
                'featured' => query("SELECT d.id,d.name,sp.name specialty,(SELECT GROUP_CONCAT(DISTINCT c.name SEPARATOR '، ') FROM clinics cl JOIN cities c ON c.id=cl.city_id WHERE cl.doctor_id=d.id) cities FROM doctors d LEFT JOIN specialties sp ON sp.id=d.specialty_id WHERE d.featured=1 ORDER BY d.featured_order,d.id")->fetchAll(),
                'q' => $q,
                'candidates' => query(
                    "SELECT d.id,d.name,sp.name specialty,d.subscription_until,(SELECT GROUP_CONCAT(DISTINCT c.name SEPARATOR '، ') FROM clinics cl JOIN cities c ON c.id=cl.city_id WHERE cl.doctor_id=d.id) cities FROM doctors d LEFT JOIN specialties sp ON sp.id=d.specialty_id WHERE d.review_status='approved' AND d.featured=0" .
                        ($q !== '' ? ' AND d.name LIKE ?' : '') . ' ORDER BY d.name LIMIT 30',
                    $q !== '' ? ['%' . fa_normalize($q) . '%'] : [],
                )->fetchAll(),
            ];
        case 'specialties':
            return $data + ['rows' => query("SELECT id,name,level,icon,image_file,show_on_home,active FROM specialties WHERE deleted_at IS NULL AND level IN ('general','specialty','allied') ORDER BY sort_order,name")->fetchAll()];
        case 'faq':
            $edit = !empty($_GET['edit']) ? (query('SELECT * FROM faqs WHERE id=?', [(int) $_GET['edit']])->fetch() ?: []) : [];
            return $data + ['rows' => faq_list(false), 'edit' => $edit];
        case 'pages':
            $slug = (string) ($_GET['page'] ?? '');
            return $data + ['rows' => query('SELECT slug,title,published,updated_at FROM site_pages ORDER BY slug')->fetchAll(), 'edit' => $slug !== '' ? site_page($slug, false) : null];
        case 'drawer':
            return $data + ['banner' => drawer_banner(), 'menu' => drawer_menu(null, null)];
        case 'footer':
            return $data + ['social' => social_links(), 'badges' => trust_badges(), 'about' => setting('footer_about'), 'address' => setting('support_address'), 'showCode' => setting('home_show_medical_code', '0') === '1'];
    }
    return $data;
}

function admin_articles_data(): array
{
    $edit = null;
    if (isset($_GET['edit'])) {
        $edit = (int) $_GET['edit'] ? (query('SELECT * FROM articles WHERE id=?', [(int) $_GET['edit']])->fetch() ?: null) : [];
    }
    return [
        'edit' => $edit,
        'rows' => query('SELECT a.id,a.slug,a.title,a.status,a.published_at,a.views,s.name specialty FROM articles a LEFT JOIN specialties s ON s.id=a.specialty_id ORDER BY COALESCE(a.published_at,a.created_at) DESC LIMIT 200')->fetchAll(),
        'specialties' => query("SELECT id,name FROM specialties WHERE active=1 AND deleted_at IS NULL ORDER BY sort_order,name")->fetchAll(),
    ];
}

function home_featured_set(int $doctor, bool $on): string
{
    if ($on && !query("SELECT id FROM doctors WHERE id=? AND review_status='approved'", [$doctor])->fetch()) {
        throw new RuntimeException('فقط پزشک تأییدشده منتخب می‌شود.');
    }
    query('UPDATE doctors SET featured=?,featured_order=IF(?=1,(SELECT o FROM (SELECT COALESCE(MAX(featured_order),0)+10 o FROM doctors) x),featured_order) WHERE id=?', [$on ? 1 : 0, $on ? 1 : 0, $doctor]);
    audit('home.featured', $doctor, $on ? 'on' : 'off');
    return $on ? 'پزشک به منتخب‌ها اضافه شد.' : 'پزشک از منتخب‌ها حذف شد.';
}

function home_specialties_save(array $d): string
{
    $show = array_map('intval', (array) ($d['show'] ?? []));
    $order = array_map('intval', (array) ($d['order'] ?? []));
    db_transaction(function () use ($show, $order) {
        query("UPDATE specialties SET show_on_home=0 WHERE level IN ('general','specialty','allied')");
        if ($show) {
            query('UPDATE specialties SET show_on_home=1 WHERE id IN (' . placeholders($show) . ')', $show);
        }
        if ($order) {
            reorder_rows('specialties', 'sort_order', $order);
        }
    });
    audit('home.specialties');
    return 'تخصص‌های صفحه اصلی ذخیره شد.';
}
