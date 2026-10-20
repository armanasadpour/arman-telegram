<?php
declare(strict_types=1);

/**
 * Site shell for phones: the side menu (drawer) and its admin-managed banner.
 * Every menu entry points to a real page; pages that are not published are left out.
 */

/** Groups of medical-center kinds used by the menu and the centers page filter. */
function center_groups(): array
{
    return [
        'clinic' => ['label' => 'کلینیک‌ها', 'kinds' => ['clinic', 'dental']],
        'polyclinic' => ['label' => 'درمانگاه‌ها', 'kinds' => ['polyclinic', 'medical_center']],
        'special' => ['label' => 'مراکز تخصصی', 'kinds' => ['imaging', 'lab', 'rehab', 'hospital', 'other']],
    ];
}

/** Banner at the top of the phone menu: ['enabled','title','text','button','url','theme','image']. */
function drawer_banner(): array
{
    $b = json_decode(setting('drawer_banner', '{}'), true);
    $b = (is_array($b) ? $b : []) + ['enabled' => false, 'title' => '', 'text' => '', 'button' => '', 'url' => '', 'theme' => 'blue', 'image' => ''];
    $b['enabled'] = !empty($b['enabled']) && ($b['title'] !== '' || $b['image'] !== '');
    if (!isset(home_promo_themes()[$b['theme']])) {
        $b['theme'] = 'blue';
    }
    return $b;
}

function drawer_banner_save(array $d, array $files): void
{
    $old = drawer_banner();
    $b = [
        'enabled' => !empty($d['enabled']),
        'title' => mb_substr(trim((string) ($d['title'] ?? '')), 0, 80),
        'text' => mb_substr(trim((string) ($d['text'] ?? '')), 0, 160),
        'button' => mb_substr(trim((string) ($d['button'] ?? '')), 0, 30),
        'url' => safe_link((string) ($d['url'] ?? '')),
        'theme' => isset(home_promo_themes()[$d['theme'] ?? '']) ? (string) $d['theme'] : 'blue',
        'image' => $old['image'],
    ];
    if (!empty($files['image']['tmp_name'])) {
        $b['image'] = store_cover_image($files['image'], 'home', 720, 300, true);
    } elseif (!empty($d['remove_image'])) {
        $b['image'] = '';
    }
    if ($b['enabled'] && $b['title'] === '' && $b['image'] === '') {
        throw new RuntimeException('برای نمایش بنر، عنوان یا تصویر لازم است.');
    }
    if ($b['button'] !== '' && $b['url'] === '') {
        throw new RuntimeException('برای دکمه بنر، پیوند را وارد کنید.');
    }
    setting_set('drawer_banner', json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Menu tree for the drawer. Items: ['label','icon','url'] or ['label','icon','children' => [...]];
 * 'auth' => next page marks entries that open the sign-in sheet for guests.
 */
function drawer_menu(?array $user, ?array $city): array
{
    $pages = published_pages();
    $roles = $user ? roles((int) $user['id']) : [];
    $items = [];
    $items[] = [
        'key' => 'doctors',
        'label' => 'نوبت‌دهی پزشکان',
        'icon' => 'calendar',
        'children' => [
            ['label' => 'جستجوی پزشک', 'url' => 'index.php?view=doctors#search'],
            ['label' => 'جستجو براساس تخصص', 'url' => 'specialties.php'],
            ['label' => 'اولین نوبت‌های آزاد', 'url' => 'index.php?sort=first&available=1'],
            $city
                ? ['label' => 'پزشکان شهر من (' . $city['name'] . ')', 'url' => city_url((int) $city['id'], $city['name'])]
                : ['label' => 'پزشکان شهر من', 'url' => 'city.php'],
        ],
    ];
    $centers = [];
    foreach (center_groups() as $key => $g) {
        $centers[] = ['label' => $g['label'], 'url' => 'centers.php?group=' . $key];
    }
    $centers[] = ['label' => 'همه مراکز درمانی', 'url' => 'centers.php'];
    $items[] = ['key' => 'centers', 'label' => 'مراکز درمانی', 'icon' => 'hospital', 'children' => $centers];
    if ($user) {
        $items[] = ['label' => 'نوبت‌های من', 'icon' => 'calendar-check', 'url' => 'patient.php'];
    }
    $items[] = ['label' => 'مقالات سلامت', 'icon' => 'article', 'url' => 'articles.php'];
    if (in_array('doctor', $roles, true)) {
        $items[] = ['label' => 'پنل پزشک', 'icon' => 'doctor', 'url' => 'doctor-panel.php'];
    } elseif (!$user) {
        // Signed-in patients do not get doctor entries; the footer still links «ثبت‌نام پزشک».
        $items[] = ['label' => 'ورود / ثبت‌نام پزشکان', 'icon' => 'doctor', 'url' => 'login.php?next=doctor-panel.php', 'auth' => 'doctor-panel.php'];
    }
    $items[] = ['label' => 'ورود / ثبت‌نام کلینیک', 'icon' => 'hospital', 'url' => $user ? 'center.php' : 'login.php?next=center.php', 'auth' => 'center.php'];
    if (in_array('secretary', $roles, true)) {
        $items[] = ['label' => 'پنل منشی', 'icon' => 'users', 'url' => 'secretary.php'];
    }
    $items[] = ['label' => 'سوالات متداول', 'icon' => 'help', 'url' => 'faq.php'];
    foreach (['terms' => ['قوانین و مقررات', 'scale'], 'about' => ['درباره ' . site_name(), 'info'], 'contact' => ['تماس با ما', 'phone']] as $slug => [$label, $icon]) {
        if (isset($pages[$slug])) {
            $items[] = ['label' => $label, 'icon' => $icon, 'url' => page_url($slug)];
        }
    }
    $items[] = ['label' => 'پشتیبانی', 'icon' => 'headset', 'url' => 'support.php'];
    return $items;
}
