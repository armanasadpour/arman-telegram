<?php
declare(strict_types=1);

/**
 * Doctor search (results page, map and partial updates). One result per doctor; each doctor's clinics are
 * listed with the clinics in the chosen city first. Every filter is validated here; SQL uses placeholders.
 *
 * First free times come from clinic_free_cache: a clinic is recomputed when its row is older than the
 * configured minutes, when any appointment event of its doctor is recorded (event_record), and by cron.
 * The booking page always re-checks the chosen time, so a cached time is never booked blindly.
 */

/** Admin-managed settings with defaults. */
function search_config(): array
{
    if (isset($GLOBALS['__search_cfg'])) {
        return $GLOBALS['__search_cfg'];
    }
    $raw = json_decode(setting('search_config', '{}'), true);
    $raw = is_array($raw) ? $raw : [];
    $filters = array_fill_keys(array_keys(search_filter_labels()), true);
    $badges = array_fill_keys(array_keys(search_badge_labels()), true);
    $cfg = [
        'filters' => array_merge($filters, array_intersect_key((array) ($raw['filters'] ?? []), $filters)),
        'badges' => array_merge($badges, array_intersect_key((array) ($raw['badges'] ?? []), $badges)),
        'default_sort' => (string) ($raw['default_sort'] ?? 'first'),
        'per_page' => max(6, min(48, (int) ($raw['per_page'] ?? 12))),
        'reviews' => (bool) ($raw['reviews'] ?? true),
        'reviews_min' => max(1, min(50, (int) ($raw['reviews_min'] ?? 3))),
        'reviews_moderation' => (bool) ($raw['reviews_moderation'] ?? true),
        'show_done' => (bool) ($raw['show_done'] ?? true),
        'show_code' => (bool) ($raw['show_code'] ?? true),
        'show_bio' => (bool) ($raw['show_bio'] ?? true),
        'show_address' => (bool) ($raw['show_address'] ?? true),
        'show_first' => (bool) ($raw['show_first'] ?? true),
        'map' => (bool) ($raw['map'] ?? true),
        'horizon' => in_array((int) ($raw['horizon'] ?? 30), [7, 14, 30, 60, 90], true) ? (int) ($raw['horizon'] ?? 30) : 30,
        'cache_minutes' => max(1, min(60, (int) ($raw['cache_minutes'] ?? 5))),
    ];
    if (!isset(search_sorts($cfg)[$cfg['default_sort']]) || $cfg['default_sort'] === 'distance') {
        $cfg['default_sort'] = 'first';
    }
    return $GLOBALS['__search_cfg'] = $cfg;
}

function search_filter_labels(): array
{
    return [
        'location' => 'استان و شهر',
        'specialty' => 'تخصص و فوق‌تخصص',
        'name' => 'نام پزشک',
        'gender' => 'جنسیت پزشک',
        'available' => 'دارای نوبت آزاد',
        'day' => 'نوبت امروز / فردا',
        'range' => 'بازه تاریخ دلخواه',
        'center' => 'نوع مرکز درمانی',
        'district' => 'محله یا محدوده',
        'local' => 'فقط مطب‌های همین شهر',
    ];
}

function search_badge_labels(): array
{
    return [
        'verified' => 'پزشک تأییدشده (مدارک بررسی‌شده)',
        'featured' => 'پزشک منتخب',
        'today' => 'نوبت امروز',
        'tomorrow' => 'نوبت فردا',
        'multi' => 'چند مطب فعال',
        'custom' => 'نشان ویژه تعیین‌شده توسط مدیر',
    ];
}

function search_sorts(?array $cfg = null): array
{
    $cfg ??= search_config();
    $sorts = ['first' => 'نزدیک‌ترین نوبت آزاد'];
    if ($cfg['reviews']) {
        $sorts += ['rating' => 'بیشترین امتیاز کاربران', 'reviews' => 'بیشترین تعداد نظرات'];
    }
    return $sorts + ['distance' => 'نزدیک‌ترین مطب', 'featured' => 'پزشکان منتخب', 'newest' => 'جدیدترین پزشکان'];
}

/**
 * Validated search input from the query string. Disabled filters are ignored.
 * Keys: q, specialty[], gender, available, day (today|tomorrow), from, to (Y-m-d), center[], district[],
 * sort, lat, lng, page, errors (messages for invalid values that were dropped).
 */
function search_input(array $g): array
{
    $cfg = search_config();
    $on = $cfg['filters'];
    $in = ['q' => '', 'specialty' => [], 'gender' => '', 'available' => false, 'day' => '', 'from' => '', 'to' => '', 'center' => [], 'district' => [], 'sort' => $cfg['default_sort'], 'lat' => null, 'lng' => null, 'page' => 1, 'errors' => []];
    $scalar = fn(string $k) => isset($g[$k]) && is_scalar($g[$k]) ? trim((string) $g[$k]) : '';
    $list = function (string $k) use ($g): array {
        $v = $g[$k] ?? [];
        $v = is_array($v) ? $v : ($v === '' ? [] : [$v]);
        return array_slice(array_values(array_filter(array_map(fn($x) => is_scalar($x) ? trim((string) $x) : '', $v), fn($x) => $x !== '')), 0, 12);
    };
    if ($on['name']) {
        $in['q'] = mb_substr(fa_normalize($scalar('q')), 0, 100);
    }
    if ($on['specialty']) {
        $ids = array_map('intval', array_merge($list('specialty'), $scalar('specialty_id') !== '' ? [$scalar('specialty_id')] : []));
        $in['specialty'] = array_values(array_unique(array_filter($ids, fn($i) => $i > 0)));
    }
    if ($on['gender'] && in_array($scalar('gender'), ['male', 'female'], true)) {
        $in['gender'] = $scalar('gender');
    }
    if ($on['available']) {
        $in['available'] = $scalar('available') === '1';
    }
    if ($on['day'] && in_array($scalar('day'), ['today', 'tomorrow'], true)) {
        $in['day'] = $scalar('day');
    }
    if ($on['range'] && ($scalar('from') !== '' || $scalar('to') !== '')) {
        try {
            $from = $scalar('from') !== '' ? parse_jdate($scalar('from')) : date('Y-m-d');
            $to = $scalar('to') !== '' ? parse_jdate($scalar('to')) : date('Y-m-d', strtotime($from . ' +6 days'));
            if ($from < date('Y-m-d')) {
                $from = date('Y-m-d');
            }
            if ($to < $from) {
                throw new AuthError('پایان بازه پیش از آغاز آن است.');
            }
            if ((strtotime($to) - strtotime($from)) / 86400 > 13) {
                throw new AuthError('بازه تاریخ حداکثر ۱۴ روز است.');
            }
            [$in['from'], $in['to']] = [$from, $to];
        } catch (Throwable $e) {
            $in['errors'][] = $e instanceof AuthError ? $e->getMessage() : 'تاریخ بازه نامعتبر است؛ مثل ۱۴۰۵/۰۸/۰۱ وارد کنید.';
        }
    }
    if ($on['center']) {
        $in['center'] = array_values(array_intersect($list('center'), array_keys(center_groups())));
    }
    if ($on['district']) {
        $in['district'] = array_values(array_unique(array_map(fn($d) => mb_substr(fa_normalize($d), 0, 80), $list('district'))));
    }
    $sorts = search_sorts();
    if (isset($sorts[$scalar('sort')])) {
        $in['sort'] = $scalar('sort');
    } elseif ($scalar('sort') === 'name') {
        $in['sort'] = 'newest';
    }
    $lat = (float) $scalar('lat');
    $lng = (float) $scalar('lng');
    if ($lat >= 24 && $lat <= 40.5 && $lng >= 43.5 && $lng <= 64) {
        // Rounded to about 100 m: enough to order clinics, never stored.
        [$in['lat'], $in['lng']] = [round($lat, 3), round($lng, 3)];
    }
    if ($in['sort'] === 'distance' && $in['lat'] === null) {
        $in['errors'][] = 'برای «نزدیک‌ترین مطب» اجازه دسترسی به موقعیت لازم است؛ نتایج با نزدیک‌ترین نوبت آزاد مرتب شد.';
        $in['sort'] = 'first';
    }
    $in['page'] = max(1, min(500, (int) $scalar('page')));
    return $in;
}

/** Number of active filters shown on the filter button (location and sort are not counted). */
function search_active_count(array $in): int
{
    return ($in['q'] !== '' ? 1 : 0) + count($in['specialty']) + ($in['gender'] ? 1 : 0) + ($in['available'] ? 1 : 0) + ($in['day'] ? 1 : 0) + ($in['from'] ? 1 : 0) + count($in['center']) + count($in['district']);
}

/**
 * Where to search: the chosen city (city=ID stores it when $persist), an explicit province or the whole
 * country (province=0&city=0), else the visitor's saved city. scope: city | nearby | all.
 */
function search_scope(array $params, bool $persist = false): array
{
    $city = null;
    $province = 0;
    if (isset($params['city']) && is_scalar($params['city']) && (int) $params['city'] > 0) {
        try {
            $city = $persist ? set_visitor_city((int) $params['city']) : city_row((int) $params['city']);
        } catch (AuthError $e) {
            $city = null;
        }
        if ($city && empty($city['active'] ?? 1)) {
            $city = null;
        }
    } elseif (isset($params['province']) || isset($params['city'])) {
        $province = max(0, (int) (is_scalar($params['province'] ?? null) ? $params['province'] : 0));
        if ($province && !query('SELECT id FROM provinces WHERE id=? AND active=1', [$province])->fetch()) {
            $province = 0;
        }
    } else {
        $city = visitor_city();
    }
    $scope = is_scalar($params['scope'] ?? null) ? (string) $params['scope'] : ($city ? 'city' : 'all');
    if (!in_array($scope, ['city', 'nearby', 'all'], true) || (!$city && $scope !== 'all')) {
        $scope = $city ? 'city' : 'all';
    }
    $nearby = $city ? cities_nearby((int) $city['id']) : [];
    $cityIds = [];
    if ($scope === 'city') {
        $cityIds = [(int) $city['id']];
    } elseif ($scope === 'nearby') {
        $cityIds = array_merge([(int) $city['id']], array_map(fn($c) => (int) $c['id'], $nearby));
    }
    return ['city' => $city, 'province' => $province, 'scope' => $scope, 'nearby' => $nearby, 'cityIds' => $cityIds];
}

/* ---------- First free time cache ---------- */

function clinic_day_free(int $clinic, string $day): bool
{
    $services = query('SELECT ds.id FROM doctor_services ds JOIN clinics cl ON cl.doctor_id=ds.doctor_id WHERE cl.id=? AND ds.active=1 AND ds.plan_locked=0 ORDER BY ds.id', [$clinic])->fetchAll(PDO::FETCH_COLUMN);
    foreach ($services as $s) {
        if (availability($clinic, (int) $s, $day)) {
            return true;
        }
    }
    foreach (query("SELECT s.*,d.subscription_until FROM slots s JOIN doctors d ON d.id=s.doctor_id WHERE s.clinic_id=? AND s.source='legacy' AND s.starts_at>NOW() AND DATE(s.starts_at)=?", [$clinic, $day])->fetchAll() as $s) {
        if (legacy_slot_open($s)) {
            return true;
        }
    }
    return false;
}

/** Recomputes and stores one clinic's first free time and today/tomorrow flags. */
function clinic_free_refresh(int $clinic): array
{
    $first = first_available($clinic, search_config()['horizon']);
    $at = $first['starts_at'] ?? null;
    $today = $at !== null && substr($at, 0, 10) === date('Y-m-d');
    $tomorrowDay = date('Y-m-d', strtotime('+1 day'));
    $tomorrow = $at !== null && (substr($at, 0, 10) === $tomorrowDay || ($today && clinic_day_free($clinic, $tomorrowDay)));
    query(
        'INSERT INTO clinic_free_cache(clinic_id,first_at,today,tomorrow,computed_at) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE first_at=VALUES(first_at),today=VALUES(today),tomorrow=VALUES(tomorrow),computed_at=VALUES(computed_at)',
        [$clinic, $at, $today ? 1 : 0, $tomorrow ? 1 : 0, time()],
    );
    return ['first_at' => $at, 'today' => $today, 'tomorrow' => $tomorrow];
}

/** Marks every clinic of a doctor for recomputation (called from event_record). */
function clinic_free_forget_doctor(int $doctor): void
{
    query('UPDATE clinic_free_cache f JOIN clinics cl ON cl.id=f.clinic_id SET f.computed_at=0 WHERE cl.doctor_id=?', [$doctor]);
}

/** Refreshes stale cache rows for the given clinics, at most $limit per call. */
function clinic_free_ensure(array $clinics, int $limit = 80): int
{
    $clinics = array_values(array_unique(array_map('intval', $clinics)));
    if (!$clinics) {
        return 0;
    }
    $fresh = time() - search_config()['cache_minutes'] * 60;
    $rows = query(
        'SELECT cl.id FROM clinics cl LEFT JOIN clinic_free_cache f ON f.clinic_id=cl.id WHERE cl.id IN (' . placeholders($clinics) . ') AND (f.clinic_id IS NULL OR f.computed_at<? OR (f.first_at IS NOT NULL AND f.first_at<NOW()) OR (f.today=1 AND DATE(FROM_UNIXTIME(f.computed_at))<CURDATE())) LIMIT ' . max(1, $limit),
        array_merge($clinics, [$fresh]),
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $id) {
        clinic_free_refresh((int) $id);
    }
    return count($rows);
}

/** Cron: keeps the cache of every public clinic warm (oldest first). */
function clinic_free_cron(int $limit = 300): int
{
    $fresh = time() - search_config()['cache_minutes'] * 60;
    $ids = query(
        'SELECT cl.id FROM clinics cl JOIN doctors d ON d.id=cl.doctor_id JOIN specialties sp ON sp.id=d.specialty_id JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id LEFT JOIN clinic_free_cache f ON f.clinic_id=cl.id WHERE ' .
            visible_doctor_sql() . ' AND ' . public_clinic_sql() .
            ' AND (f.clinic_id IS NULL OR f.computed_at<?) ORDER BY COALESCE(f.computed_at,0) LIMIT ' . max(1, $limit),
        [$fresh],
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        clinic_free_refresh((int) $id);
    }
    return count($ids);
}

/** True when the clinic has a free time on some day between $from and $to (Y-m-d). */
function clinic_free_in_range(int $clinic, string $from, string $to): bool
{
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        if (clinic_day_free($clinic, $d)) {
            return true;
        }
    }
    return false;
}

/* ---------- Search ---------- */

/**
 * WHERE clause shared by results, count, map and district list. $in from search_input();
 * $cityIds limits to cities (scope), $province otherwise. Returns [sql, args].
 */
function search_where(array $in, array $cityIds, int $province, bool $withCache = true): array
{
    $w = visible_doctor_sql() . ' AND ' . public_clinic_sql();
    $a = [];
    if ($cityIds) {
        $w .= ' AND cl.city_id IN (' . placeholders($cityIds) . ')';
        array_push($a, ...$cityIds);
    } elseif ($province) {
        $w .= ' AND c.province_id=?';
        $a[] = $province;
    }
    if ($in['specialty']) {
        $w .= ' AND (sp.id IN (' . placeholders($in['specialty']) . ') OR sp.parent_id IN (' . placeholders($in['specialty']) . '))';
        array_push($a, ...$in['specialty'], ...$in['specialty']);
    }
    if ($in['q'] !== '') {
        $w .= ' AND (d.name LIKE ? OR sp.name LIKE ? OR cl.name LIKE ? OR c.name LIKE ? OR cl.address LIKE ? OR cl.district LIKE ? OR d.professional_title LIKE ?)';
        for ($i = 0; $i < 7; $i++) {
            $a[] = '%' . $in['q'] . '%';
        }
    }
    if ($in['gender'] !== '') {
        $w .= ' AND d.gender=?';
        $a[] = $in['gender'];
    }
    if ($in['center']) {
        $kinds = [];
        foreach ($in['center'] as $g) {
            $kinds = array_merge($kinds, center_groups()[$g]['kinds']);
        }
        $w .= ' AND cl.center_id IS NOT NULL AND EXISTS(SELECT 1 FROM centers ce_k WHERE ce_k.id=cl.center_id AND ce_k.kind IN (' . placeholders($kinds) . '))';
        array_push($a, ...$kinds);
    }
    if ($in['district']) {
        $w .= ' AND cl.district IN (' . placeholders($in['district']) . ')';
        array_push($a, ...$in['district']);
    }
    if ($withCache) {
        if ($in['available']) {
            $w .= ' AND fc.first_at IS NOT NULL';
        }
        if ($in['day'] === 'today') {
            $w .= ' AND fc.today=1';
        } elseif ($in['day'] === 'tomorrow') {
            $w .= ' AND fc.tomorrow=1';
        }
    }
    return [$w, $a];
}

function search_from(): string
{
    return ' FROM doctors d JOIN specialties sp ON sp.id=d.specialty_id JOIN clinics cl ON cl.doctor_id=d.id JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id LEFT JOIN clinic_free_cache fc ON fc.clinic_id=cl.id';
}

/**
 * Runs the search. Returns ['total','page','pages','per_page','items' => [doctor rows with 'clinics'],
 * 'error' => ?string]. Each item: id,name,photo_file,professional_title,experience_years,medical_code,
 * specialty,featured,badge_text,gender,rating (avg,count,recommend|null),done,clinics (primary first),
 * today,tomorrow,first_at.
 */
function search_run(array $in, array $cityIds, int $province): array
{
    $cfg = search_config();
    $per = $cfg['per_page'];
    [$baseWhere, $baseArgs] = search_where($in, $cityIds, $province, false);
    $candidates = query('SELECT DISTINCT cl.id' . search_from() . ' WHERE ' . $baseWhere . ' LIMIT 2001', $baseArgs)->fetchAll(PDO::FETCH_COLUMN);
    $error = null;
    if (count($candidates) > 2000) {
        $error = 'نتایج زیاد است؛ برای دقت بیشتر شهر یا تخصص را مشخص کنید.';
    }
    // Availability-based filters and sorting need fresh first free times for the candidates.
    $needsFree = $in['available'] || $in['day'] !== '' || $in['sort'] === 'first' || $in['from'] !== '';
    if ($needsFree) {
        clinic_free_ensure($candidates, 80);
    }
    [$where, $args] = search_where($in, $cityIds, $province);
    if ($in['from'] !== '') {
        $ids = query('SELECT DISTINCT cl.id' . search_from() . ' WHERE ' . $where . ' LIMIT 151', $args)->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) > 150) {
            return ['total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => $per, 'items' => [], 'error' => 'برای جستجو در بازه تاریخ، شهر یا تخصص را مشخص کنید (حداکثر ۱۵۰ مطب).'];
        }
        $keep = array_values(array_filter($ids, fn($id) => clinic_free_in_range((int) $id, $in['from'], $in['to'])));
        $where .= $keep ? ' AND cl.id IN (' . placeholders($keep) . ')' : ' AND 1=0';
        array_push($args, ...array_map('intval', $keep));
    }
    $total = (int) query('SELECT COUNT(DISTINCT d.id)' . search_from() . ' WHERE ' . $where, $args)->fetchColumn();
    $pages = max(1, (int) ceil($total / $per));
    $page = min($in['page'], $pages);
    $min = $cfg['reviews_min'];
    $dist = '';
    $distArgs = [];
    if ($in['lat'] !== null) {
        $dist = ',MIN(CASE WHEN cl.latitude IS NULL THEN NULL ELSE 6371*2*ASIN(SQRT(POWER(SIN(RADIANS(cl.latitude-?)/2),2)+COS(RADIANS(?))*COS(RADIANS(cl.latitude))*POWER(SIN(RADIANS(cl.longitude-?)/2),2))) END) dist';
        $distArgs = [$in['lat'], $in['lat'], $in['lng']];
    }
    // Aggregates are computed in a derived table so the outer ORDER BY works the same on MySQL and MariaDB.
    $order = match ($in['sort']) {
        'rating' => 'rv_ok DESC,rv_avg DESC,rv_n DESC,first_null,first_free,id DESC',
        'reviews' => 'rv_n DESC,rv_avg DESC,id DESC',
        'distance' => 'dist IS NULL,dist,first_null,first_free',
        'featured' => 'featured DESC,featured_order,pro DESC,first_null,first_free,id DESC',
        'newest' => 'created DESC,id DESC',
        // rc.20: Pro doctors rank first among doctors whose first free time is on the same day; a Pro doctor
        // never passes a doctor with an earlier free day, and filters are never bypassed.
        default => 'first_null,DATE(first_free),pro DESC,first_free,featured DESC,id DESC',
    };
    $rv = $cfg['reviews']
        ? 'LEFT JOIN (SELECT doctor_id,AVG(rating) avg_rating,COUNT(*) n FROM reviews WHERE status=\'approved\' GROUP BY doctor_id) rv ON rv.doctor_id=d.id'
        : 'LEFT JOIN (SELECT 0 doctor_id,0 avg_rating,0 n) rv ON 1=0';
    $inner = 'SELECT d.id,MIN(fc.first_at) first_free,MIN(fc.first_at) IS NULL first_null,MAX(d.featured) featured,MAX(' . pro_boost_sql('d') . ') pro,MIN(d.featured_order) featured_order,MAX(d.created_at) created,COALESCE(MAX(rv.n),0) rv_n,COALESCE(MAX(rv.avg_rating),0) rv_avg,(COALESCE(MAX(rv.n),0)>=' . $min . ') rv_ok' . $dist .
        search_from() . ' ' . $rv . ' WHERE ' . $where . ' GROUP BY d.id';
    $sql = 'SELECT id FROM (' . $inner . ') ranked ORDER BY ' . $order . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per);
    $ids = query($sql, array_merge($distArgs, $args))->fetchAll(PDO::FETCH_COLUMN);
    return ['total' => $total, 'page' => $page, 'pages' => $pages, 'per_page' => $per, 'items' => search_cards(array_map('intval', $ids), $cityIds, $in), 'error' => $error];
}

/**
 * Card data for the given doctors in order: profile, rating, completed appointments and all public clinics
 * (in the chosen cities first, then by first free time). Used by the results page and the map.
 */
function search_cards(array $ids, array $cityIds = [], ?array $in = null): array
{
    if (!$ids) {
        return [];
    }
    $cfg = search_config();
    $doctors = [];
    foreach (query('SELECT d.id,d.name,d.subscription_until,d.photo_file,d.professional_title,d.experience_years,d.medical_code,d.featured,d.badge_text,d.gender,d.created_at,sp.name specialty FROM doctors d JOIN specialties sp ON sp.id=d.specialty_id WHERE d.id IN (' . placeholders($ids) . ')', $ids)->fetchAll() as $d) {
        $doctors[(int) $d['id']] = $d + ['clinics' => [], 'rating' => null, 'done' => 0, 'today' => false, 'tomorrow' => false, 'first_at' => null];
    }
    $clinics = query(
        'SELECT cl.id,cl.doctor_id,cl.name,cl.address,cl.district,cl.city_id,cl.latitude,cl.longitude,cl.is_primary,c.name city,p.name province FROM clinics cl JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE cl.doctor_id IN (' . placeholders($ids) . ') AND ' . public_clinic_sql(),
        $ids,
    )->fetchAll();
    clinic_free_ensure(array_column($clinics, 'id'), 200);
    $free = $clinics ? query('SELECT * FROM clinic_free_cache WHERE clinic_id IN (' . placeholders(array_column($clinics, 'id')) . ')', array_column($clinics, 'id'))->fetchAll(PDO::FETCH_UNIQUE) : [];
    foreach ($clinics as $c) {
        $f = $free[$c['id']] ?? null;
        $c['first_at'] = $f['first_at'] ?? null;
        $c['today'] = !empty($f['today']);
        $c['tomorrow'] = !empty($f['tomorrow']);
        $c['in_city'] = !$cityIds || in_array((int) $c['city_id'], $cityIds, true);
        $c['matches'] = $c['in_city'] && (!$in || !$in['district'] || in_array($c['district'], $in['district'], true));
        $d = &$doctors[(int) $c['doctor_id']];
        $d['clinics'][] = $c;
        $d['today'] = $d['today'] || $c['today'];
        $d['tomorrow'] = $d['tomorrow'] || $c['tomorrow'];
        if ($c['first_at'] && (!$d['first_at'] || $c['first_at'] < $d['first_at'])) {
            $d['first_at'] = $c['first_at'];
        }
        unset($d);
    }
    foreach ($doctors as &$d) {
        usort($d['clinics'], fn($a, $b) => [$b['matches'], $a['first_at'] === null, $a['first_at'] ?? '', -$a['is_primary']] <=> [$a['matches'], $b['first_at'] === null, $b['first_at'] ?? '', -$b['is_primary']]);
    }
    unset($d);
    if ($cfg['reviews']) {
        foreach (query("SELECT doctor_id,AVG(rating) a,COUNT(*) n,AVG(recommend) r FROM reviews WHERE status='approved' AND doctor_id IN (" . placeholders($ids) . ') GROUP BY doctor_id', $ids)->fetchAll() as $r) {
            if ((int) $r['n'] >= $cfg['reviews_min']) {
                $doctors[(int) $r['doctor_id']]['rating'] = ['avg' => round((float) $r['a'], 1), 'count' => (int) $r['n'], 'recommend' => (int) round((float) $r['r'] * 100)];
            }
        }
    }
    if ($cfg['show_done']) {
        foreach (query("SELECT s.doctor_id,COUNT(*) n FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE b.status='completed' AND s.doctor_id IN (" . placeholders($ids) . ') GROUP BY s.doctor_id', $ids)->fetchAll() as $r) {
            $doctors[(int) $r['doctor_id']]['done'] = (int) $r['n'];
        }
    }
    $out = [];
    foreach ($ids as $id) {
        if (isset($doctors[$id]) && $doctors[$id]['clinics']) {
            $out[] = $doctors[$id];
        }
    }
    return $out;
}

/** «امروز ۱۰:۳۰»، «فردا ۰۹:۰۰» or the Jalali date and time. */
function free_label(?string $at): string
{
    if (!$at) {
        return '';
    }
    $day = substr($at, 0, 10);
    $time = fa_number(substr($at, 11, 5));
    if ($day === date('Y-m-d')) {
        return 'امروز ' . $time;
    }
    if ($day === date('Y-m-d', strtotime('+1 day'))) {
        return 'فردا ' . $time;
    }
    return jalali_weekday($day) . ' ' . jdate($day) . ' ' . $time;
}

function jalali_weekday(string $day): string
{
    return ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'][(int) date('w', strtotime($day))];
}

/** Districts entered for public clinics in the given cities (for the district filter). */
function search_districts(array $cityIds): array
{
    if (!$cityIds) {
        return [];
    }
    return query(
        "SELECT cl.district,COUNT(DISTINCT cl.doctor_id) n FROM clinics cl JOIN doctors d ON d.id=cl.doctor_id JOIN specialties sp ON sp.id=d.specialty_id JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE cl.district<>'' AND cl.city_id IN (" . placeholders($cityIds) . ') AND ' . visible_doctor_sql() . ' AND ' . public_clinic_sql() . ' GROUP BY cl.district ORDER BY cl.district',
        $cityIds,
    )->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Map markers: clinics with coordinates matching the search (max 300). Clinic address only, no private data. */
function search_markers(array $in, array $cityIds, int $province): array
{
    [$where, $args] = search_where($in, $cityIds, $province);
    $rows = query(
        'SELECT cl.id clinic_id,cl.name clinic,cl.latitude lat,cl.longitude lng,c.name city,d.id doctor_id,d.name doctor,sp.name specialty,fc.first_at' .
            search_from() . ' WHERE ' . $where . ' AND cl.latitude IS NOT NULL AND cl.longitude IS NOT NULL ORDER BY fc.first_at IS NULL,fc.first_at LIMIT 300',
        $args,
    )->fetchAll();
    return array_map(fn($r) => [
        'lat' => (float) $r['lat'],
        'lng' => (float) $r['lng'],
        'doctor' => $r['doctor'],
        'specialty' => $r['specialty'],
        'clinic' => $r['clinic'] . '، ' . $r['city'],
        'first' => $r['first_at'] ? free_label($r['first_at']) : '',
        'url' => doctor_url((int) $r['doctor_id'], $r['doctor'], (int) $r['clinic_id']),
    ], $rows);
}

/** Query string for the current search without one value (chip removal) or with changes. */
function search_query(array $in, array $base, array $change = []): string
{
    $q = $base + array_filter([
        'q' => $in['q'],
        'specialty' => $in['specialty'],
        'gender' => $in['gender'],
        'available' => $in['available'] ? '1' : '',
        'day' => $in['day'],
        'from' => $in['from'] !== '' ? jdate($in['from']) : '',
        'to' => $in['to'] !== '' ? jdate($in['to']) : '',
        'center' => $in['center'],
        'district' => $in['district'],
        'sort' => $in['sort'] !== search_config()['default_sort'] ? $in['sort'] : '',
        'lat' => $in['sort'] === 'distance' ? $in['lat'] : null,
        'lng' => $in['sort'] === 'distance' ? $in['lng'] : null,
    ], fn($v) => $v !== '' && $v !== [] && $v !== null && $v !== false);
    foreach ($change as $k => $v) {
        if ($v === null) {
            unset($q[$k]);
        } elseif (is_array($v) && isset($v['remove'])) {
            $q[$k] = array_values(array_filter((array) ($q[$k] ?? []), fn($x) => (string) $x !== (string) $v['remove']));
            if (!$q[$k]) {
                unset($q[$k]);
            }
        } else {
            $q[$k] = $v;
        }
    }
    if (empty($q['view'])) {
        $q = ['view' => 'doctors'] + $q;
    }
    return http_build_query($q);
}

/* ---------- Reviews (verified: one per completed appointment) ---------- */

/** Completed appointments of this account that have no review yet. */
function reviewable_bookings(int $user): array
{
    if (!search_config()['reviews']) {
        return [];
    }
    return query(
        "SELECT b.id,b.patient_name,s.starts_at,d.id doctor_id,d.name doctor FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN doctors d ON d.id=s.doctor_id LEFT JOIN reviews r ON r.booking_id=b.id WHERE b.patient_user_id=? AND b.status='completed' AND r.id IS NULL AND s.starts_at>DATE_SUB(NOW(),INTERVAL 180 DAY) ORDER BY s.starts_at DESC LIMIT 20",
        [$user],
    )->fetchAll();
}

function review_save(array $u, array $d): string
{
    if (!search_config()['reviews']) {
        throw new AuthError('ثبت نظر غیرفعال است.', 403);
    }
    throttle('review', 20, 3600);
    $booking = positive_id($d['booking_id'] ?? 0);
    $b = query(
        "SELECT b.id,s.doctor_id FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE b.id=? AND b.patient_user_id=? AND b.status='completed'",
        [$booking, $u['id']],
    )->fetch();
    if (!$b) {
        throw new AuthError('فقط برای نوبت انجام‌شده خودتان می‌توانید نظر ثبت کنید.', 403);
    }
    $rating = (int) ($d['rating'] ?? 0);
    if ($rating < 1 || $rating > 5) {
        throw new AuthError('امتیاز را از یک تا پنج انتخاب کنید.');
    }
    if (!in_array((string) ($d['recommend'] ?? ''), ['0', '1'], true)) {
        throw new AuthError('بفرمایید این پزشک را به دیگران پیشنهاد می‌کنید یا نه.');
    }
    $comment = trim(preg_replace('/\s+/u', ' ', field($d, 'comment', 600, false)));
    if (query('SELECT id FROM reviews WHERE booking_id=?', [$booking])->fetch()) {
        throw new AuthError('برای این نوبت قبلاً نظر ثبت کرده‌اید.', 409);
    }
    $status = search_config()['reviews_moderation'] ? 'pending' : 'approved';
    query('INSERT INTO reviews(booking_id,doctor_id,user_id,rating,recommend,comment,status) VALUES(?,?,?,?,?,?,?)', [
        $booking, $b['doctor_id'], $u['id'], $rating, (int) $d['recommend'], $comment !== '' ? $comment : null, $status,
    ]);
    audit('review.create', $booking);
    return $status === 'pending' ? 'نظر شما ثبت شد و پس از بررسی نمایش داده می‌شود.' : 'نظر شما ثبت شد.';
}

function review_moderate(int $id, string $status): string
{
    if (!in_array($status, ['approved', 'hidden'], true)) {
        throw new AuthError('وضعیت نامعتبر است.');
    }
    $n = query('UPDATE reviews SET status=?,moderated_by=?,moderated_at=NOW() WHERE id=?', [$status, $_SESSION['admin_id'] ?? null, $id])->rowCount();
    if (!$n && !query('SELECT id FROM reviews WHERE id=?', [$id])->fetch()) {
        throw new AuthError('نظر پیدا نشد.', 404);
    }
    audit('review.' . $status, $id);
    return $status === 'approved' ? 'نظر منتشر شد.' : 'نظر پنهان شد.';
}

/** Approved reviews for the doctor page. */
function doctor_reviews(int $doctor, int $limit = 10, string $sort = 'new'): array
{
    if (!search_config()['reviews']) {
        return ['summary' => null, 'items' => []];
    }
    $s = query("SELECT AVG(rating) a,COUNT(*) n,AVG(recommend) r FROM reviews WHERE doctor_id=? AND status='approved'", [$doctor])->fetch();
    $summary = (int) $s['n'] >= search_config()['reviews_min'] ? ['avg' => round((float) $s['a'], 1), 'count' => (int) $s['n'], 'recommend' => (int) round((float) $s['r'] * 100)] : null;
    $items = $summary
        ? query("SELECT r.rating,r.recommend,r.comment,r.created_at,u.first_name FROM reviews r JOIN users u ON u.id=r.user_id WHERE r.doctor_id=? AND r.status='approved' AND r.comment IS NOT NULL ORDER BY " . match ($sort) { 'high' => 'r.rating DESC,r.id DESC', 'low' => 'r.rating ASC,r.id DESC', default => 'r.id DESC' } . ' LIMIT ' . max(1, $limit), [$doctor])->fetchAll()
        : [];
    return ['summary' => $summary, 'items' => $items, 'count' => (int) $s['n']];
}

/** Search settings form (admin). */
function search_config_save(array $d): string
{
    $filters = [];
    foreach (array_keys(search_filter_labels()) as $k) {
        $filters[$k] = !empty($d['filter'][$k]);
    }
    $badges = [];
    foreach (array_keys(search_badge_labels()) as $k) {
        $badges[$k] = !empty($d['badge'][$k]);
    }
    $sort = (string) ($d['default_sort'] ?? 'first');
    $cfg = [
        'filters' => $filters,
        'badges' => $badges,
        'default_sort' => in_array($sort, ['first', 'rating', 'reviews', 'featured', 'newest'], true) ? $sort : 'first',
        'per_page' => number_range($d, 'per_page', 6, 48),
        'reviews' => !empty($d['reviews']),
        'reviews_min' => number_range($d, 'reviews_min', 1, 50),
        'reviews_moderation' => !empty($d['reviews_moderation']),
        'show_done' => !empty($d['show_done']),
        'show_code' => !empty($d['show_code']),
        'show_bio' => !empty($d['show_bio']),
        'show_address' => !empty($d['show_address']),
        'show_first' => !empty($d['show_first']),
        'map' => !empty($d['map']),
        'horizon' => in_array((int) ($d['horizon'] ?? 30), [7, 14, 30, 60, 90], true) ? (int) $d['horizon'] : 30,
        'cache_minutes' => number_range($d, 'cache_minutes', 1, 60),
    ];
    if (!$cfg['reviews'] && in_array($cfg['default_sort'], ['rating', 'reviews'], true)) {
        $cfg['default_sort'] = 'first';
    }
    setting_set('search_config', json_encode($cfg, JSON_UNESCAPED_UNICODE));
    unset($GLOBALS['__search_cfg']);
    query('UPDATE clinic_free_cache SET computed_at=0');
    audit('search.settings');
    return 'تنظیمات جستجو ذخیره شد.';
}
