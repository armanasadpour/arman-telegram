<?php
declare(strict_types=1);

/**
 * Page shell and template rendering.
 * Templates live in /views and receive variables through render(); all output is escaped with esc().
 */

function render(string $template, array $vars = []): void
{
    $file = dirname(__DIR__) . '/views/' . $template . '.php';
    if (!is_file($file)) {
        throw new RuntimeException('قالب صفحه پیدا نشد.');
    }
    (static function (string $__file, array $__vars): void {
        extract($__vars, EXTR_SKIP);
        require $__file;
    })($file, $vars);
}

function render_to_string(string $template, array $vars = []): string
{
    ob_start();
    try {
        render($template, $vars);
        return (string) ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
}

/** Current public city chosen by the visitor (cookie or account), or null. */
function current_city(): ?array
{
    static $city = false;
    if ($city !== false) {
        return $city;
    }
    $id = (int) ($_COOKIE['nobatyar_city'] ?? 0);
    $city = $id ? city_row($id) : null;
    return $city;
}

function layout_start(string $title, array $options = []): void
{
    if (!empty($GLOBALS['panel_navigation'])) $options['body_class'] = trim(($options['body_class'] ?? '') . ' panel-workspace');
    $GLOBALS['__layout'] = $options;
    render('layout/header', [
        'title' => $title,
        'bodyClass' => (string) ($options['body_class'] ?? ''),
        'description' => (string) ($options['description'] ?? 'نوبت‌دهی اینترنتی پزشکان و مراکز درمانی'),
        'city' => schema_is_current_safe() ? current_city() : null,
        'user' => schema_is_current_safe() ? user_now_safe() : null,
        'isAdmin' => !empty($_SESSION['admin_id']),
        'siteName' => schema_is_current_safe() ? site_name() : 'نوبت‌یار',
        'unread' => schema_is_current_safe() && user_now_safe() ? unread_notifications((int) user_now_safe()['id']) : 0,
        'canonical' => (string) ($options['canonical'] ?? ''),
        'jsonld' => $options['jsonld'] ?? null,
        'ogImage' => (string) ($options['og_image'] ?? ''),
        'robots' => (string) ($options['robots'] ?? ''),
    ]);
    if (schema_is_current_safe()) panel_release_links();
    // The administration shell shows the message inside its own content column (1.0.0-rc.10).
    if (isset($_SESSION['flash']) && !str_contains((string) ($options['body_class'] ?? ''), 'admin-page')) {
        echo '<p class="notice" role="status">' . esc($_SESSION['flash']) . '</p>';
        unset($_SESSION['flash']);
    }
}

function layout_end(): void
{
    render('layout/footer', [
        'options' => $GLOBALS['__layout'] ?? [],
        'siteName' => schema_is_current_safe() ? site_name() : 'نوبت‌یار',
        'contact' => schema_is_current_safe() ? site_contact() : ['phone' => '', 'email' => '', 'hours' => '', 'footer' => ''],
        'bottomNav' => bottom_nav((string) (($GLOBALS['__layout'] ?? [])['body_class'] ?? '')),
        'shell' => shell_data((string) (($GLOBALS['__layout'] ?? [])['body_class'] ?? '')),
        'footer' => schema_is_current_safe()
            ? ['about' => setting('footer_about'), 'address' => setting('support_address'), 'social' => social_links(), 'badges' => trust_badges(), 'pages' => published_pages()]
            : ['about' => '', 'address' => '', 'social' => [], 'badges' => [], 'pages' => []],
    ]);
}

/**
 * Phone side menu and the sign-in sheet. Left out on the waiting-room display, error pages and before
 * installation; the sign-in sheet is only rendered for guests and never on the sign-in page itself.
 */
function shell_data(string $bodyClass): ?array
{
    if (!schema_is_current_safe() || preg_match('/admin-page|queue-display|error-page/', $bodyClass)) {
        return null;
    }
    $user = user_now_safe();
    $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    return [
        'user' => $user,
        'menu' => drawer_menu($user, current_city()),
        'banner' => drawer_banner(),
        'siteName' => site_name(),
        'isAdmin' => !empty($_SESSION['admin_id']),
        'auth' => !$user && $page !== 'login.php'
            ? ['available' => sms_enabled() || sms_dev_mode(), 'signup' => pending_signup()]
            : null,
    ];
}

/**
 * Phone bottom navigation (hidden on wide screens by CSS). Not shown on the admin panel, sign-in pages
 * and the waiting-room display, which have their own layout.
 */
function bottom_nav(string $bodyClass): array
{
    if (!empty($GLOBALS['panel_navigation'])) return $GLOBALS['panel_navigation']['bottom'];
    if (!schema_is_current_safe() || preg_match('/admin-page|auth-page|queue-display|error-page/', $bodyClass)) {
        return [];
    }
    $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $user = user_now_safe();
    $items = [
        ['url' => 'index.php', 'icon' => 'home', 'label' => 'خانه', 'pages' => ['index.php']],
        ['url' => 'index.php?view=doctors#search', 'icon' => 'search', 'label' => 'جستجو', 'pages' => ['doctor.php', 'specialties.php', 'centers.php', 'clinic.php', 'city.php']],
        ['url' => 'patient.php', 'auth' => $user ? null : 'patient.php', 'icon' => 'calendar-check', 'label' => 'نوبت‌های من', 'pages' => ['patient.php', 'receipt.php', 'checkout.php']],
        ['url' => $user ? 'panel.php' : 'login.php', 'auth' => $user ? null : '', 'icon' => 'user', 'label' => $user ? 'حساب من' : 'ورود', 'pages' => ['panel.php', 'login.php', 'notifications.php', 'support.php', 'doctor-panel.php', 'secretary.php', 'center.php'], 'count' => $user ? unread_notifications((int) $user['id']) : 0],
    ];
    if ($page === 'index.php' && isset($_GET['view'])) {
        $page = 'doctor.php';
    }
    foreach ($items as &$item) {
        $item['current'] = in_array($page, $item['pages'], true);
    }
    return $items;
}

/** Layout must render even before installation or upgrade, so database lookups are guarded. */
function schema_is_current_safe(): bool
{
    try {
        return is_file(dirname(__DIR__) . '/config.php') && schema_is_current();
    } catch (Throwable $e) {
        return false;
    }
}

function user_now_safe(): ?array
{
    try {
        return user_now();
    } catch (Throwable $e) {
        return null;
    }
}

/** Inline SVG icon from the built-in sprite (assets/icons.svg). */
function icon(string $name, string $class = 'icon'): string
{
    return '<svg class="' .
        esc($class) .
        '" aria-hidden="true" focusable="false"><use href="assets/icons.svg#' .
        esc($name) .
        '"></use></svg>';
}

function selected_attr(bool $condition): string
{
    return $condition ? ' selected' : '';
}

function checked_attr(bool $condition): string
{
    return $condition ? ' checked' : '';
}

/** Persian-digit pagination links preserving the current query string. */
function pagination(int $page, int $total, int $perPage, array $query): string
{
    $pages = max(1, (int) ceil($total / $perPage));
    if ($pages < 2) {
        return '';
    }
    $html = '<nav class="pagination" aria-label="صفحه‌بندی">';
    for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) {
        $html .=
            '<a class="page-link' .
            ($i === $page ? ' current' : '') .
            '" href="' . esc(basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'))) . '?' .
            esc(http_build_query(array_merge($query, ['page' => $i]))) .
            '"' .
            ($i === $page ? ' aria-current="page"' : '') .
            '>' .
            fa_number($i) .
            '</a>';
    }
    return $html . '</nav>';
}

/** Navigation context is set only after the selected doctor/clinic was authorized. */
function set_panel_navigation(string $page, array $tabs, string $active, int $doctor, int $clinic, string $title): void
{
    $icons = ['dashboard'=>'home','profile'=>'user','clinics'=>'map','services'=>'list','schedule'=>'clock','calendar'=>'calendar','bookings'=>'calendar-check','history'=>'list','staff'=>'users','centers'=>'building','report'=>'chart','promote'=>'share','subscription'=>'receipt','notifications'=>'bell','events'=>'list','patients'=>'users','book'=>'plus','queue'=>'list','delays'=>'clock','audit'=>'list'];
    $menu = [];
    foreach ($tabs as $key=>$label) {
        $menu[$key] = ['url'=>$page . '?' . http_build_query(['doctor'=>$doctor,'clinic'=>$clinic,'tab'=>$key]),'label'=>$label,'icon'=>$icons[$key] ?? 'list','current'=>$key===$active];
    }
    $priority = $page === 'secretary.php' ? ['dashboard','calendar','book','queue'] : ['dashboard','calendar','bookings','notifications'];
    $bottom = [];
    foreach ($priority as $key) if(isset($menu[$key])) $bottom[]=$menu[$key];
    $GLOBALS['panel_navigation'] = ['title'=>$title,'menu'=>$menu,'bottom'=>$bottom,'home'=>$menu['dashboard']['url'],'notifications'=>$menu['notifications']['url'] ?? $menu['dashboard']['url']];
}
