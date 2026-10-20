"""1.0.0-rc.10 over HTTP: administration shell (own sidebar and top bar instead of the public header and
footer), light/dark theme preference, redesigned dashboard sections and their permission gating.
Run after v12_http.py (uses its owner fixture and creates one extra viewer administrator)."""
from http_helpers import *

PASSWORD = 'Strong-pass-2026'
fixture = json.loads((ROOT.parent.parent / 'runtime' / 'admin-fixture.json').read_text())
owner = client()
jar = next(h.cookiejar for h in owner.handlers if isinstance(h, urllib.request.HTTPCookieProcessor))
jar.set_cookie(http.cookiejar.Cookie(0, 'PHPSESSID', fixture['owner'], None, False, '127.0.0.1', False, False, '/', True, False, None, False, None, None, {}))


def clean(html):
    return 'Warning:' not in html and 'Fatal error' not in html and 'Notice:' not in html


status, html = get(owner, 'admin.php?tab=dashboard&range=year')
check(status == 200 and clean(html), 'dashboard renders')
check('class="site-header"' not in html and 'class="site-footer"' not in html and 'class="bottom-nav"' not in html, 'admin pages use their own shell, not the public header and footer')
check('assets/admin.css?v=' in html and 'assets/admin.js?v=' in html and 'assets/shell.js' not in html, 'admin stylesheet and script are loaded')
check(re.search(r'<script(?![^>]*\bsrc=)(?![^>]*application/ld\+json)[^>]*>', html) is None, 'no inline script (CSP)')
check('admin-page admin-dark' in html, 'dark theme by default')
for words in ['تحلیل عملکرد', 'وضعیت نوبت‌ها', 'خلاصه درآمد', 'نیازمند رسیدگی', 'فعالیت‌های اخیر', 'آخرین تراکنش‌ها', 'شهرهای پرنوبت', 'آمار استان‌ها', 'اشتراک‌های نزدیک انقضا', 'درخواست‌های در انتظار تأیید']:
    check(words in html, 'owner dashboard section: ' + words)
check('درآمد امروز' in html and 'درآمد سال جاری' in html and 'روند درآمد' in html and 'رشد کاربران' in html, 'revenue and growth labels kept')
check(html.count('class="ad-kpi') >= 4 and 'data-count=' in html, 'four headline cards with count-up values')
check(re.search(r'class="ad-delta (up|down|flat|none|new)"', html) is not None and 'نسبت به' in html, 'change against the previous period')
check('id="pane-revenue" role="tabpanel">' in html and 'id="pane-users" role="tabpanel" hidden' in html, 'revenue chart open first, others hidden')
check(html.count('data-kind="area"') == 3 and 'class="chart donut"' in html, 'three area charts and a booking ring chart')
check('Cron' in html and 'ad-status' in html, 'scheduled jobs status in the heading')
check('data-finder hidden' in html and 'data-menu-open' in html and 'href="#admin-menu"' in html, 'finder and phone menu (with no-JavaScript fallback)')

status, html = get(owner, 'admin.php?tab=dashboard&range=30d&chart=users')
check('id="pane-users" role="tabpanel">' in html and 'id="pane-revenue" role="tabpanel" hidden' in html and 'chart=users' in html, 'chart choice from the address, kept in range links')
status, html = get(owner, 'admin.php?tab=dashboard&chart=<script>')
check(status == 200 and '<script>' not in html.split('<body', 1)[1].replace('<script src=', ''), 'unknown chart value ignored')

# Theme preference: redirect without the parameter, cookie, body class; invalid values ignored.
req = urllib.request.Request(BASE + 'admin.php?tab=users&theme=light')
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None
nr = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(jar), NoRedirect())
try:
    r = nr.open(req)
except urllib.error.HTTPError as e:
    r = e
check(r.status in (302, 303) and 'theme=' not in r.headers.get('Location', '') and 'tab=users' in r.headers.get('Location', ''), 'theme switch redirects back without the parameter')
check('ny_admin_theme=light' in r.headers.get('Set-Cookie', '') and 'HttpOnly' in r.headers.get('Set-Cookie', '') and 'SameSite=Lax' in r.headers.get('Set-Cookie', ''), 'theme cookie is HttpOnly and SameSite')
jar.extract_cookies(r, req)
status, html = get(owner, 'admin.php?tab=users')
check('admin-page admin-light' in html and 'theme=dark' in html, 'light theme applied, switch offers dark')
get(owner, 'admin.php?tab=users&theme=purple')
status, html = get(owner, 'admin.php?tab=users')
check('admin-page admin-light' in html, 'invalid theme value ignored')
get(owner, 'admin.php?tab=users&theme=dark')
check('admin-page admin-dark' in get(owner, 'admin.php?tab=users')[1], 'dark theme restored')

# Every section renders inside the shell with the current menu item marked.
for tab in ['users', 'doctors', 'payments', 'support', 'sms', 'security', 'update']:
    status, html = get(owner, 'admin.php?tab=' + tab)
    check(status == 200 and clean(html) and 'class="admin-shell"' in html and f'href="admin.php?tab={tab}" data-label=' in html and 'aria-current="page"' in html, f'{tab} inside the admin shell')
status, html = get(owner, 'admin.php?tab=account')
check(status == 200 and clean(html) and 'class="admin-me current"' in html, 'my account opened from the profile in the sidebar')

# Dashboard-only role: no finance or activity widgets, no menu badges for other sections.
email = 'rc10-viewer@example.test'
if not db('SELECT id FROM admins WHERE email=?', [email]):
    status, html = post(owner, 'admin.php?tab=admins', dict(csrf=token(owner, 'admin.php?tab=admins'), action='admin_save', name='بیننده', email=email, mobile='09121009998', password=PASSWORD, role='viewer'))
    check('مدیر جدید ساخته شد' in html, 'viewer administrator created')
viewer = client()
submit(viewer, 'admin.php', dict(action='login', email=email, password=PASSWORD))
status, html = get(viewer, 'admin.php?tab=dashboard&range=year')
check(status == 200 and clean(html) and 'تحلیل عملکرد' in html and 'رشد کاربران' in html, 'viewer sees the dashboard')
for words in ['خلاصه درآمد', 'آخرین تراکنش‌ها', 'فعالیت‌های اخیر', 'روند درآمد', 'درآمد امروز', 'درآمد بازه انتخابی', 'اکسل روند']:
    check(words not in html, 'viewer does not see: ' + words)
check('id="pane-bookings" role="tabpanel">' in html and 'pane-revenue' not in html, 'viewer starts on the bookings chart')
check('نوبت‌های امروز' in html and 'class="nav-count"' not in html and 'admin.php?tab=cron' not in html, 'viewer headline and menu without other sections')
check('admin.php?tab=payments' not in html and 'admin.php?tab=doctors' not in html, 'no links to sections the viewer cannot open')

print('SUCCESS', checks, 'rc.10 admin UI checks')
