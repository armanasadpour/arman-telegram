"""1.0.0-rc.3 sign-in and phone menu over HTTP: mobile formats, one-time code, no account before a real
name, profile step (API and full page), existing accounts sign in directly, wrong/expired codes, signed
events without codes, drawer menu entries, FAQ page, centers filter, booking time re-checked after
signing in, admin menu banner and sign-in log. Writes runtime/auth-fixture.json for browser_auth.cjs."""
from http_helpers import *

fixture = json.loads((ROOT.parent.parent / 'runtime' / 'phase4-fixture.json').read_text())
DOCTOR, CLINIC, SERVICE = fixture['doctor'], fixture['clinic'], fixture['service']


def php(code, args=[]):
    return subprocess.check_output([PHP] + (['-c', PHPINI] if PHPINI else []) + ['-r', 'require $argv[1];if(config()["database"]!=="nobatyar_test_http")exit(9);' + code, str(ROOT / 'core.php')] + [str(a) for a in args]).decode()


def scalar(sql, args=[]):
    rows = db(sql, args)
    return next(iter(rows[0].values())) if rows else None


def sid(c):
    jar = next(h.cookiejar for h in c.handlers if isinstance(h, urllib.request.HTTPCookieProcessor))
    return next(x.value for x in jar if x.name == 'PHPSESSID')


def challenge(c, phone):
    """Code request for this browser session through the CLI-only fake transport (no SMS leaves)."""
    api(c, 'session')
    code = """$i=json_decode(stream_get_contents(STDIN),true);session_id($i[0]);require $argv[1];if(config()['database']!=='nobatyar_test_http')exit(9);query('DELETE FROM auth_rate_limits');$plain=null;$r=otp_request($i[1],function($m,$code)use(&$plain){$plain=$code;return ['messageid'=>'TEST-RC3'];});echo json_encode([$r,$plain]);"""
    out = subprocess.check_output([PHP] + (['-c', PHPINI] if PHPINI else []) + ['-r', code, str(ROOT / 'core.php')], input=json.dumps([sid(c), phone]).encode())
    return json.loads(out)


def csrf(c):
    return api(c, 'session')[1]['data']['csrf']


def clean(html):
    return 'Warning:' not in html and 'Fatal error' not in html and 'Notice:' not in html


def admin_login():
    c = client()
    submit(c, 'admin.php', dict(action='login', email='admin@example.test', password='Test-password-012345'))
    return c


db('DELETE FROM auth_rate_limits')
db('DELETE FROM request_limits')
sms_start = scalar("SELECT value FROM settings WHERE `key`='sms_enabled'") or '0'
db("INSERT INTO settings(`key`,value) VALUES('sms_enabled','0') ON DUPLICATE KEY UPDATE value='0'")

# ---------- Mobile formats and the disabled-SMS answer ----------
g = client()
status, r = api(g, 'auth/request', dict(mobile='abc'), csrf(g))
check(status == 422 and 'موبایل' in r['error']['message'], 'invalid mobile rejected before any SMS work')
status, r = api(g, 'auth/request', dict(mobile='+98 912 000 1234'), csrf(g))
check(status == 503 and 'فعال نشده' in r['error']['message'], '+98 format accepted; SMS disabled answers the same for every number')
check(php("echo normalize_mobile('۰۹۱۲-۰۰۰-۱۲۳۴'),'|',normalize_mobile('9120001234'),'|',normalize_mobile('0098 9120001234');") == '09120001234|09120001234|09120001234', 'Persian digits, ten digits and 0098 normalized')

# ---------- New number: code first, then a real name, then the account ----------
NEW = '09125550101'
c = client()
ch, plain = challenge(c, NEW)
status, r = api(c, 'auth/verify', dict(challenge_id=ch['challenge_id'], code='000000' if plain != '000000' else '111111'), csrf(c))
check(status == 422 and 'اشتباه' in r['error']['message'] and 'بار دیگر' in r['error']['message'], 'wrong code says how many tries are left')
failed = db("SELECT detail FROM audit_log WHERE action='auth.otp_failed' ORDER BY id DESC LIMIT 1")
check(failed and NEW not in failed[0]['detail'] and plain not in failed[0]['detail'], 'failed code logged with a masked number and no code')
before = sid(c)
status, r = api(c, 'auth/verify', dict(challenge_id=ch['challenge_id'], code=plain), csrf(c))
check(status == 200 and r['data']['status'] == 'profile_required' and r['data']['mobile'] == NEW, 'verified new number asks for the name')
check(sid(c) != before, 'session id changes after the code (no fixation)')
check(scalar('SELECT COUNT(*) FROM users WHERE mobile=?', [NEW]) == 0, 'no account exists before the name is given')
s = api(c, 'session')[1]['data']
check(s['user'] is None and s['signup'] == {'mobile': NEW}, 'session reports the pending sign-up only')
check(api(c, 'auth/verify', dict(challenge_id=ch['challenge_id'], code=plain), csrf(c))[0] == 422, 'used code cannot be replayed')
token_ = csrf(c)
for data, label in [
    (dict(first_name='ع', last_name='کریمی', accept_terms=True), 'one-letter first name'),
    (dict(first_name='علی', last_name='', accept_terms=True), 'empty last name'),
    (dict(first_name='علی', last_name='کریمی'), 'terms not accepted'),
    (dict(first_name='<b>علی', last_name='کریمی', accept_terms=True), 'markup in name'),
    (dict(first_name='کاربر123', last_name='کریمی', accept_terms=True), 'digits in name'),
]:
    status, r = api(c, 'auth/complete', data, token_)
    check(status == 422, 'sign-up rejected: ' + label)
check(scalar('SELECT COUNT(*) FROM users WHERE mobile=?', [NEW]) == 0, 'rejected sign-ups create nothing')
status, r = api(c, 'auth/complete', dict(first_name='علی', last_name='کریمی‌زاده', accept_terms=True), token_)
check(status == 200 and r['data']['status'] == 'logged_in' and r['data']['name'] == 'علی کریمی‌زاده', 'sign-up completes and signs in')
u = db('SELECT * FROM users WHERE mobile=?', [NEW])[0]
check(u['first_name'] == 'علی' and u['last_name'] == 'کریمی‌زاده' and u['terms_accepted_at'], 'first name, last name and terms time stored')
check(scalar("SELECT COUNT(*) FROM user_roles WHERE user_id=? AND role='patient'", [u['id']]) == 1, 'new account is a patient only')
signup = db("SELECT detail FROM audit_log WHERE action='auth.signup' AND entity_id=?", [u['id']])
check(signup and NEW not in signup[0]['detail'], 'sign-up logged with a masked number')
check(api(c, 'session')[1]['data']['user']['name'] == 'علی کریمی‌زاده', 'session shows the signed-in user')
check(scalar("SELECT COUNT(*) FROM users WHERE TRIM(name)=''") == 0, 'no nameless accounts in the system')

# ---------- Existing account: straight in ----------
c2 = client()
ch, plain = challenge(c2, NEW)
status, r = api(c2, 'auth/verify', dict(challenge_id=ch['challenge_id'], code=plain), csrf(c2))
check(status == 200 and r['data']['status'] == 'logged_in' and r['data']['id'] == u['id'], 'existing account signs in directly after the code')
status, html = get(c2, 'login.php?next=patient.php')
check('login.php' not in html or 'تکمیل اطلاعات' not in html, 'signed-in user is not asked for the name again')

# ---------- Pending sign-up expires ----------
c3 = client()
ch, plain = challenge(c3, '09125550102')
api(c3, 'auth/verify', dict(challenge_id=ch['challenge_id'], code=plain), csrf(c3))
code = """$i=json_decode(stream_get_contents(STDIN),true);session_id($i[0]);require $argv[1];if(session_status()!==PHP_SESSION_ACTIVE)session_start();$_SESSION['signup']['at']=time()-901;session_write_close();"""
subprocess.check_output([PHP] + (['-c', PHPINI] if PHPINI else []) + ['-r', code, str(ROOT / 'core.php')], input=json.dumps([sid(c3)]).encode())
status, r = api(c3, 'auth/complete', dict(first_name='مینا', last_name='رحمانی', accept_terms=True), csrf(c3))
check(status == 401 and 'مهلت' in r['error']['message'], 'expired pending sign-up must start again')
check(scalar("SELECT COUNT(*) FROM users WHERE mobile='09125550102'") == 0, 'expired sign-up created nothing')

# ---------- Full page (no JavaScript): profile step after the code ----------
status, html = get(client(), 'login.php')
check('ورود پیامکی هنوز توسط مدیر' in html and 'name="mobile"' not in html, 'sign-in page explains when SMS sign-in is off')
sms_before = scalar("SELECT value FROM settings WHERE `key`='sms_enabled'")
db("INSERT INTO settings(`key`,value) VALUES('sms_enabled','1') ON DUPLICATE KEY UPDATE value='1'")
c4 = client()
status, html = get(c4, 'login.php')
check(status == 200 and clean(html) and 'ورود یا ثبت‌نام' in html and 'phone-prefix' not in html and 'class="mobile-input auth-mobile"' in html and 'placeholder="۰۹۱۲۳۴۵۶۷۸۹"' in html and 'name="mobile"' in html and 'دریافت کد تأیید' in html, 'sign-in page: full 09… number field (no +98 box) and code button')
check('data-auth-sheet' not in html, 'sheet not duplicated on the sign-in page')
ch, plain = challenge(c4, '09125550103')
api(c4, 'auth/verify', dict(challenge_id=ch['challenge_id'], code=plain), csrf(c4))
status, html = get(c4, 'login.php?next=patient.php')
check('تکمیل اطلاعات' in html and 'name="first_name"' in html and '۰۹۱۲۵۵۵۰۱۰۳' in html, 'page shows the profile step with the verified number')
status, html = post(c4, 'login.php', dict(action='complete', csrf=token(c4, 'login.php'), first_name='سارا', last_name='نادری', next='patient.php'))
check('پذیرش قوانین' in html and scalar("SELECT COUNT(*) FROM users WHERE mobile='09125550103'") == 0, 'page rejects sign-up without accepting the terms')
status, html = post(c4, 'login.php', dict(action='complete', csrf=token(c4, 'login.php'), first_name='سارا', last_name='نادری', accept_terms='1', next='patient.php'))
check(status == 200 and scalar("SELECT name FROM users WHERE mobile='09125550103'") == 'سارا نادری', 'page sign-up creates the account')
check(get(c4, 'patient.php')[0] == 200 and 'سارا نادری' in get(c4, 'index.php')[1], 'page sign-up signs in')
status, html = get(client(), 'login.php?next=https://evil.example/')
check('evil.example' not in html, 'external return address ignored')
db("UPDATE settings SET value='0' WHERE `key`='sms_enabled'")

# ---------- Drawer menu ----------
status, html = get(client(), 'index.php')
check('data-drawer-open hidden' in html and 'id="side-drawer"' in html and 'data-auth-sheet' in html, 'guest page: menu button (shown by script), drawer and sign-in sheet')
for link in ['index.php?view=doctors#search', 'specialties.php', 'index.php?sort=first&amp;available=1', 'centers.php?group=clinic', 'centers.php?group=polyclinic', 'centers.php?group=special', 'articles.php', 'faq.php', 'support.php']:
    check(f'href="{link}"' in html, 'drawer link: ' + link)
drawer = html.split('id="side-drawer"')[1].split('</aside>')[0]
check('نوبت‌های من' not in drawer, 'guest menu has no «my appointments» entry')
check('data-auth-open="doctor-panel.php"' in drawer and 'data-auth-open' in drawer, 'guest doctor entry and login open the sign-in sheet')
pages = {r['slug']: r['published'] for r in db('SELECT slug,published FROM site_pages')}
check(('قوانین و مقررات' in drawer) == bool(int(pages.get('terms', 0))), 'terms entry only when the page is published')
status, html = get(c, 'index.php')
drawer = html.split('id="side-drawer"')[1].split('</aside>')[0]
check('علی کریمی‌زاده' in drawer and 'نوبت‌های من' in drawer and 'name="action" value="logout"' in drawer and 'data-auth-sheet' not in html, 'signed-in menu: name, my appointments, logout; no sheet')
qt = scalar('SELECT queue_token FROM clinics WHERE id=?', [CLINIC]) or 'ab' * 16
db('UPDATE clinics SET queue_token=? WHERE id=?', [qt, CLINIC])
status, html = get(client(), 'queue.php?t=' + qt)
check('id="side-drawer"' not in html, 'no menu on the waiting-room display')

# ---------- FAQ page, centers filter ----------
status, html = get(client(), 'faq.php')
check(status == 200 and clean(html) and 'سوالات متداول' in html and '"FAQPage"' in html and 'class="faq"' in html, 'FAQ page with structured data')
check('faq.php' in get(client(), 'sitemap.php')[1], 'FAQ page in the sitemap')
for group, label in [('clinic', 'کلینیک‌ها'), ('polyclinic', 'درمانگاه‌ها'), ('special', 'مراکز تخصصی')]:
    status, html = get(client(), f'centers.php?group={group}')
    check(status == 200 and clean(html) and label in html and 'aria-current="page">' + label in html, 'centers filter: ' + label)
status, html = get(client(), 'centers.php?group=nope')
check(status == 200 and 'aria-current="page">همه' in html, 'unknown center group shows all')

# ---------- Booking: guest time links, re-check after signing in ----------
free = json.loads(php(f"$o=[];for($i=0;$i<14&&!$o;$i++){{$o=availability({CLINIC},{SERVICE},date('Y-m-d',strtotime('+'.$i.' day')));}}echo json_encode($o?$o[0]['starts_at']:null);"))
check(free is not None, 'test clinic has a free time in the next two weeks')
day = free[:10]
jday = php(f"echo jdate('{day}');").strip()
status, html = get(client(), f'doctor.php?id={DOCTOR}&clinic={CLINIC}&service={SERVICE}&date=' + urllib.parse.quote(jday))
check('class="time-option" data-auth-required' in html, 'guest time links open the sign-in sheet')
status, html = get(c, f'doctor.php?id={DOCTOR}&clinic={CLINIC}&service={SERVICE}&date=' + urllib.parse.quote(jday))
check('class="time-option" href=' in html and 'data-auth-required' not in html, 'signed-in time links go straight to booking')
status, html = get(c, f'service-book.php?clinic={CLINIC}&service={SERVICE}&at=' + urllib.parse.quote(free))
check(status == 200 and ('رزرو موقت و ادامه' in html or 'ثبت و ادامه' in html) and 'دیگر آزاد نیست' not in html, 'free time: booking form after signing in')
status, html = get(c, f'service-book.php?clinic={CLINIC}&service={SERVICE}&at=' + urllib.parse.quote('2020-01-04 10:00:00'))
check('این زمان دیگر آزاد نیست' in html and 'رزرو موقت و ادامه' not in html and 'ثبت و ادامه' not in html and f'doctor.php?id={DOCTOR}&amp;clinic={CLINIC}' in html, 'past or taken time: message and a link to choose another')
status, html = get(c, f'service-book.php?clinic={CLINIC}&service={SERVICE}&at=bad')
check('این زمان دیگر آزاد نیست' in html, 'malformed time treated as unavailable')
guest_book = client()
status, html = get(guest_book, f'service-book.php?clinic={CLINIC}&service={SERVICE}&at=' + urllib.parse.quote(free))
check('ورود یا ثبت‌نام' in html, 'guest booking link without script falls back to the sign-in page')

# ---------- Admin: menu banner and sign-in log ----------
owner = admin_login()
status, html = get(owner, 'admin.php?tab=home&view=drawer')
check(status == 200 and clean(html) and 'بنر بالای منوی موبایل' in html and 'گزینه‌های منو' in html, 'admin menu banner page')
status, html = post(owner, 'admin.php?tab=home&view=drawer', dict(action='drawer_banner_save', csrf=token(owner, 'admin.php?tab=home&view=drawer'), return_view='drawer', enabled='1', title='', theme='blue'))
check('عنوان یا تصویر' in html, 'enabled banner needs a title or image')
status, html = post(owner, 'admin.php?tab=home&view=drawer', dict(action='drawer_banner_save', csrf=token(owner, 'admin.php?tab=home&view=drawer'), return_view='drawer', enabled='1', title='پزشک هستید؟', text='مطب خود را آنلاین کنید', button='ثبت‌نام', url='javascript:alert(1)', theme='navy'))
check('پیوند باید' in html, 'unsafe banner link rejected')
status, html = post(owner, 'admin.php?tab=home&view=drawer', dict(action='drawer_banner_save', csrf=token(owner, 'admin.php?tab=home&view=drawer'), return_view='drawer', enabled='1', title='پزشک هستید؟', text='مطب خود را آنلاین کنید', button='ثبت‌نام', url='register.php', theme='navy'))
check('بنر منوی موبایل ذخیره شد' in html, 'banner saved')
status, html = get(client(), 'index.php')
check('drawer-banner theme-navy' in html and 'href="register.php"' in html and 'پزشک هستید؟' in html, 'banner shown at the top of the menu')
check(scalar("SELECT COUNT(*) FROM audit_log WHERE action='home.drawer_banner'") >= 1, 'banner change in the activity log')
post(owner, 'admin.php?tab=home&view=drawer', dict(action='drawer_banner_save', csrf=token(owner, 'admin.php?tab=home&view=drawer'), return_view='drawer', title='پزشک هستید؟', theme='navy'))
check('drawer-banner' not in get(client(), 'index.php')[1], 'banner hidden when switched off')
status, html = get(owner, 'admin.php?tab=sms')
section = html.split('رویدادهای ورود و ثبت‌نام')[1] if 'رویدادهای ورود و ثبت‌نام' in html else ''
check(section and 'کد ورود اشتباه' in section and 'ثبت‌نام کاربر جدید' in section and NEW not in section, 'admin sees sign-in errors and sign-ups with masked numbers')
check(plain not in section, 'no code shown in the admin log')

# Fixture for browser_auth.cjs: a free time for the booking-return check and the install path.
(ROOT.parent.parent / 'runtime' / 'auth-fixture.json').write_text(json.dumps(dict(root=str(ROOT), doctor=DOCTOR, clinic=CLINIC, service=SERVICE, free=free, jday=jday, existing=NEW)))
db("UPDATE settings SET value=? WHERE `key`='sms_enabled'", [sms_start])
print(f'SUCCESS {checks} rc.3 sign-in and menu checks')
