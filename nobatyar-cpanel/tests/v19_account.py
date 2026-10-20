"""1.0.0-rc.7 over HTTP: 12-digit unique tracking numbers (new bookings, converted old ones, old claim links
still accepted), the new «حساب من» hub (no doctor or staff entries for a patient-only account, work panels
only for real roles), booking cards, phone menu without doctor entries for signed-in patients, and the light
typography. Writes runtime/account-fixture.json for browser_account.cjs."""
from http_helpers import *

fx = json.loads((ROOT.parent.parent / 'runtime' / 'phase4-fixture.json').read_text())
DOCTOR, CLINIC, SERVICE = fx['doctor'], fx['clinic'], fx['service']


def php(code, args=[]):
    return subprocess.check_output([PHP] + (['-c', PHPINI] if PHPINI else []) + ['-r', 'require $argv[1];if(config()["database"]!=="nobatyar_test_http")exit(9);' + code, str(ROOT / 'core.php')] + [str(a) for a in args]).decode()


def scalar(sql, args=[]):
    rows = db(sql, args)
    return next(iter(rows[0].values())) if rows else None


def clean(html):
    return 'Warning:' not in html and 'Fatal error' not in html and 'Notice:' not in html and 'Deprecated:' not in html


def sid(c):
    jar = next(h.cookiejar for h in c.handlers if isinstance(h, urllib.request.HTTPCookieProcessor))
    return next(x.value for x in jar if x.name == 'PHPSESSID')


db('DELETE FROM request_limits')
db('DELETE FROM auth_rate_limits')
db("UPDATE settings SET value='30' WHERE `key`='max_active_bookings'")
db("UPDATE booking_fee_rules SET active=0 WHERE scope='doctor' AND target_id=?", [DOCTOR])

# ---------- Tracking numbers ----------
codes = [r['tracking_code'] for r in db('SELECT tracking_code FROM bookings')]
check(codes and all(re.match(r'^[1-9][0-9]{11}$', c) for c in codes), 'every booking has a 12-digit tracking number (old ones converted)')
check(len(set(codes)) == len(codes), 'tracking numbers are unique')
legacy = db("SELECT COUNT(*) n FROM bookings WHERE legacy_tracking_code IS NOT NULL")[0]['n']
check(int(legacy) >= 1, 'old codes kept as legacy_tracking_code')
generated = json.loads(php('$o=[];for($i=0;$i<300;$i++)$o[]=new_tracking_code();echo json_encode($o);'))
check(all(re.match(r'^[1-9][0-9]{11}$', c) for c in generated) and len(set(generated)) == 300, 'generator: 12 digits, no leading zero, no repeats')
check(php("echo tracking_label('123456789012');") == '۱۲۳۴ ۵۶۷۸ ۹۰۱۲' and php("echo tracking_label('KEEP-CODE');") == 'KEEP-CODE', 'readable grouping in Persian digits; old codes shown as they are')

p = client()
uid = login_user(p, '09127771901')
# Complete booking profile (since rc.11: full birth date, insurance and national ID).
db("UPDATE users SET birth_year=1370,birth_date='1991-06-01',insurance='tamin',id_type='national',national_id_enc=?,gender=?,city_id=(SELECT id FROM cities LIMIT 1) WHERE id=?", [php("echo encrypt_secret('0012345679');"), 'female', uid])
at = json.loads(php(f"$o=null;for($i=0;$i<20&&!$o;$i++){{$a=availability({CLINIC},{SERVICE},date('Y-m-d',strtotime('+'.$i.' day')));if($a)$o=end($a)['starts_at'];}}echo json_encode($o);"))
book = f'service-book.php?clinic={CLINIC}&service={SERVICE}&move=0&at=' + urllib.parse.quote(at)
submit(p, book, dict(action='reserve', family_member_id=0))
bid = scalar("SELECT id FROM bookings WHERE patient_user_id=? ORDER BY id DESC LIMIT 1", [uid])
code = scalar('SELECT tracking_code FROM bookings WHERE id=?', [bid])
check(re.match(r'^[1-9][0-9]{11}$', code or ''), 'new booking gets a 12-digit tracking number')
status, co = get(p, f'checkout.php?id={bid}')
status, rc = submit(p, f'checkout.php?id={bid}', dict(action='confirm'))
label = php(f"echo tracking_label('{code}');")
check(label in rc and f'data-copy-text="{code}"' in rc, 'receipt shows the grouped number and copies the plain digits')
sms = db("SELECT * FROM sms_messages WHERE booking_id=? ORDER BY id DESC LIMIT 1", [bid])
if sms:
    check(code in json.dumps(sms[0], ensure_ascii=False), 'confirmation SMS carries the full 12-digit number')

# Old reception links (32-character codes) still open; anything else is refused.
old = db("SELECT id,legacy_tracking_code FROM bookings WHERE legacy_tracking_code REGEXP '^[A-F0-9]{32}$' LIMIT 1")
if old:
    status, html = get(p, 'reception-payment.php?code=' + old[0]['legacy_tracking_code'])
    check(status == 200, 'reception link with an old code still accepted')
status, html = get(p, 'reception-payment.php?code=' + code)
check(status == 200, 'reception link with a 12-digit number accepted')
status, html = get(p, 'reception-payment.php?code=12345')
check(status == 404, 'malformed code refused')

# ---------- «حساب من» for a patient-only account ----------
status, html = get(p, 'panel.php')
check(status == 200 and clean(html) and 'class="acc-hero"' in html, 'account hub renders')
for word in ['رزرو نوبت جدید', 'نوبت‌های من', 'اعضای خانواده', 'اعلان‌ها', 'سوابق نوبت‌ها', 'صف انتظار', 'پشتیبانی', 'نوبت‌های پیش رو', 'تنظیمات حساب', 'خروج از همه دستگاه‌ها']:
    check(word in html, 'patient hub shows ' + word)
main = html.split('<main', 1)[1].split('</main>', 1)[0]
check('doctor-panel.php' not in main and 'پنل پزشک' not in main and 'panel.php?role=' not in main and 'پنل‌های کاری' not in main, 'no doctor or staff entries for a patient')
check('class="appt"' in main and label in main, 'upcoming appointment shown as a card with its tracking number')
check('<table' not in main, 'no table on the account hub')
drawer = html.split('data-drawer', 1)[1]
check('ثبت‌نام پزشکان' not in drawer and 'doctor-panel.php' not in drawer, 'phone menu: no doctor entries for a signed-in patient')
status, guest_html = get(client(), 'index.php')
check('ورود / ثبت‌نام پزشکان' in guest_html, 'phone menu keeps the doctor sign-in for guests')
status, html = get(p, 'panel.php?role=doctor')
check(status == 403 and 'بازگشت به حساب من' in html, 'patient cannot open the doctor view (styled message)')
status, html = submit(p, 'panel.php', dict(action='profile', name='سمیرا داوری'))
check('اطلاعات ذخیره شد' in html and scalar('SELECT name FROM users WHERE id=?', [uid]) == 'سمیرا داوری', 'name saved from the hub')

# ---------- Bookings list ----------
status, html = get(p, 'patient.php')
check(status == 200 and 'class="page-tabs"' in html and 'class="appt"' in html and label in html, 'my appointments: tab bar and booking cards')
check('class="pager"' not in html and 'page-link' not in html, 'no page numbers when everything fits on one page')
status, html = get(p, 'patient.php?tab=past')
check('class="empty-state"' in html or 'class="appt"' in html, 'past tab: cards or a styled empty state')

# ---------- Accounts with work roles ----------
doc = client()
login_user(doc, '09127770001')
status, html = get(doc, 'panel.php')
check('پنل‌های کاری' in html and 'href="doctor-panel.php"' in html and 'ورود به پنل پزشک' in html, 'doctor account: work panel card')
status, html = get(doc, 'panel.php?role=doctor')
check(status == 200 and 'نوبت‌های محدوده دسترسی' in html and 'class="appt"' in html and '<table' not in html.split('<main', 1)[1].split('</main>')[0], 'staff view lists appointments as cards (with mobile numbers)')
sec = client()
smob = scalar("SELECT u.mobile FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role='secretary' WHERE NOT EXISTS(SELECT 1 FROM user_roles x WHERE x.user_id=u.id AND x.role IN ('doctor','clinic')) ORDER BY u.id LIMIT 1")
if not smob:
    smob = '09127771902'
    sid_ = login_user(sec, smob)
    db("INSERT IGNORE INTO user_roles(user_id,role) VALUES(?,'secretary')", [sid_])
else:
    login_user(sec, smob)
status, html = get(sec, 'panel.php')
check('ورود به پنل منشی' in html and 'ورود به پنل پزشک' not in html, 'secretary account: only the secretary panel')

# ---------- Styles ----------
css = (ROOT / 'assets' / 'style.css').read_text()
check('body{font-weight:300' in css and 'table.stackable' in css and '::file-selector-button' in css, 'light typography, stacked tables and styled file pickers in the stylesheet')
js = (ROOT / 'assets' / 'app.js').read_text()
check("setAttribute('data-label'" in js, 'tables get column labels for phones')

(ROOT.parent.parent / 'runtime' / 'account-fixture.json').write_text(json.dumps(dict(patient=sid(p), doctor=sid(doc), secretary=sid(sec), booking=bid)))
print('SUCCESS', checks, 'rc.7 account checks')
