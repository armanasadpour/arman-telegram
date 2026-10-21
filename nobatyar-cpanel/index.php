<?php
declare(strict_types=1);

/**
 * Public home and doctor search.
 * Scope: the visitor's city (default once chosen), the city plus nearby cities, or the whole country.
 * First-time visitors see the city picker on top of nationwide results.
 */

require __DIR__ . '/core.php';
require_schema();

$params = $_GET;
['city' => $city, 'province' => $province, 'scope' => $scope, 'nearby' => $nearby, 'cityIds' => $cityIds] = search_scope($params, true);

// The landing page shows when the visitor has not searched or filtered; any search parameter shows results.
$searchKeys = ['q', 'specialty_id', 'specialty', 'scope', 'province', 'sort', 'days', 'available', 'page', 'view', 'gender', 'day', 'from', 'to', 'center', 'district', 'lat', 'lng'];
$landing = !array_intersect_key($params, array_flip($searchKeys));
$input = search_input($params);
$filters = ['q' => $input['q'], 'specialty_id' => $input['specialty'][0] ?? 0];
$searchError = implode(' ', $input['errors']);
$result = ['total' => 0, 'items' => [], 'page' => 1, 'pages' => 1, 'per_page' => 12, 'error' => null];
if (!$landing) {
    try {
        $result = search_run($input, $cityIds, $province);
        if ($result['error']) {
            $searchError = trim($searchError . ' ' . $result['error']);
        }
    } catch (Throwable $e) {
        $searchError = error_message($e);
    }
}
$searchBase = array_filter(['scope' => $scope !== ($city ? 'city' : 'all') ? $scope : '', 'province' => !$city && $province ? $province : ''], fn($v) => $v !== '' && $v !== 0);
if (!$landing && isset($params['partial'])) {
    // Results fragment for the filter chips, sorting and "show more" without reloading the page.
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    $append = $params['partial'] === 'more';
    render('public/results-list', ['result' => $result, 'input' => $input, 'base' => $searchBase, 'city' => $city, 'cityIds' => $cityIds, 'append' => $append, 'searchError' => $searchError, 'scope' => $scope, 'nearby' => $nearby]);
    if (!$append) {
        // The filter form is sent along so its fields always match the results (for example after removing a chip).
        echo '<template data-filter-template data-map-query="' . esc(search_query($input, $searchBase)) . '">';
        render('public/results-filters', [
            'input' => $input, 'city' => $city, 'scope' => $scope, 'nearby' => $nearby, 'base' => $searchBase,
            'specialties' => specialties_with_doctors($cityIds),
            'districts' => search_config()['filters']['district'] ? search_districts($city ? [(int) $city['id']] : []) : [],
        ]);
        echo '</template>';
    }
    exit();
}

$capitals = query(
    'SELECT c.id,c.name,p.name province_name FROM cities c JOIN provinces p ON p.id=c.province_id WHERE c.is_capital=1 AND c.active=1 AND p.active=1 ORDER BY p.sort_order LIMIT 31',
)->fetchAll();
$provinces = provinces_list();

$preview = !empty($params['preview']) && admin() && admin_can('content');
$layout = home_layout($preview);
$cityLabel = $city ? $city['name'] : '';
$specialtyName = $input['specialty'] ? (string) query('SELECT name FROM specialties WHERE id=?', [$input['specialty'][0]])->fetchColumn() : '';
$where = $city && $scope !== 'all' ? ' در ' . $city['name'] : '';
$title = $landing
    ? ($layout['sections']['hero']['title'] ?: 'نوبت‌دهی اینترنتی پزشکان')
    : ($specialtyName !== '' ? 'نوبت پزشک ' . $specialtyName . $where : ($where !== '' ? 'پزشکان' . $where : 'جستجوی پزشک و نوبت اینترنتی'));
$description = $landing
    ? ($layout['sections']['hero']['subtitle'] ?: 'نوبت‌دهی اینترنتی پزشکان و مراکز درمانی')
    : ($result['total'] ? fa_number($result['total']) . ' ' : '') . 'پزشک' . ($specialtyName !== '' ? ' ' . $specialtyName : '') . $where . ' با نوبت اینترنتی؛ اولین نوبت آزاد را ببینید و رزرو کنید. هزینه ویزیت در مطب پرداخت می‌شود.';
// Only plain city / specialty listings are indexed; narrowed or personal views are not.
$narrow = $input['gender'] || $input['available'] || $input['day'] || $input['from'] || $input['center'] || $input['district'] || $input['q'] !== '' || count($input['specialty']) > 1 || isset($params['sort']) || isset($params['lat']);
$canonical = '';
if (!$landing && !$narrow) {
    if ($input['specialty'] && !$city) {
        $canonical = specialty_url($input['specialty'][0], $specialtyName);
    } elseif ($city && !$input['specialty']) {
        $canonical = city_url((int) $city['id'], $city['name']);
    } elseif ($city && $input['specialty']) {
        $canonical = 'index.php?' . http_build_query(['city' => $city['id'], 'specialty' => [$input['specialty'][0]]]);
    }
}
layout_start($title, [
    'body_class' => $landing ? 'home-page landing' : 'home-page results-page',
    'description' => $description,
    'canonical' => $landing ? 'index.php' : $canonical,
    'robots' => !$landing && $narrow ? 'noindex,follow' : '',
    'jsonld' => $landing ? [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => site_name(),
        'url' => app_base_url(),
        'potentialAction' => ['@type' => 'SearchAction', 'target' => app_base_url() . 'index.php?q={q}', 'query-input' => 'required name=q'],
    ] : null,
]);
if ($landing) {
    $sections = $layout['sections'];
    $count = fn(string $k) => (int) ($sections[$k]['desktop'] ?? 8);
    $enabled = fn(string $k) => !empty($sections[$k]['enabled']);
    render('public/landing', [
        'preview' => $preview,
        'hasDraft' => $preview && home_has_draft(),
        'layout' => $layout,
        'city' => $city,
        'provinces' => $provinces,
        'capitals' => $capitals,
        'notice' => setting('home_notice'),
        'chips' => $enabled('hero') ? home_chips($sections['hero']['chips'], $city) : [],
        'banners' => $enabled('banners') ? home_banners() : [],
        // 1.0.13: five per row, nine + «بیشتر» on the page and every category in the bottom sheet.
        'specialtyCards' => $enabled('specialties') ? home_specialties($city, 200) : [],
        'available' => $enabled('available') ? home_doctor_cards('available', $city, $count('available')) : [],
        'featured' => $enabled('featured') ? home_doctor_cards('featured', $city, $count('featured')) : [],
        'newest' => $enabled('newest') ? home_doctor_cards('newest', $city, $count('newest')) : [],
        'allDoctors' => $enabled('all_doctors') ? home_all_doctors($city, 0, $count('all_doctors')) : [[], false],
        'centerCards' => $enabled('centers') && $city ? public_centers_in_city((int) $city['id'], $count('centers')) : ($enabled('centers') ? public_centers_all($count('centers')) : []),
        'faqs' => $enabled('faq') ? faq_list(true, $count('faq')) : [],
        'articles' => $enabled('articles') ? articles_public($count('articles')) : [],
        'fee' => booking_fee(),
        'nearby' => $nearby,
        'showCode' => setting('home_show_medical_code', '0') === '1',
    ]);
} else {
    render('public/results', [
        'city' => $city,
        'province' => $province,
        'provinces' => $provinces,
        'scope' => $scope,
        'nearby' => $nearby,
        'cityIds' => $cityIds,
        'specialties' => specialties_with_doctors($cityIds),
        'districts' => search_config()['filters']['district'] ? search_districts($city ? [(int) $city['id']] : []) : [],
        'input' => $input,
        'result' => $result,
        'base' => $searchBase,
        'searchError' => $searchError,
        'notice' => setting('home_notice'),
        'specialtyName' => $specialtyName,
        'title' => $title,
    ]);
}
layout_end();
