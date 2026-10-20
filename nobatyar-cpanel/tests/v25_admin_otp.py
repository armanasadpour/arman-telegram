"""1.0.0-rc.13 over HTTP: two-step administrator sign-in for every role (SMS code to the administrator's own
mobile, then e-mail and password); first sign-in of an administrator without a mobile registers and confirms
one; password-only sign-in while SMS is off; mobile required when an owner adds an administrator; full
09… number without a +98 box in the sign-in forms. Temporarily switches config.php to development with
SMS test mode (nothing is sent) and restores both at the end. Run after v12_http.py."""
from http_helpers import *

PASSWORD = 'Strong-pass-2026'
OWNER_EMAIL, OWNER_PASS, OWNER_MOBILE = 'admin@example.test', 'Test-password-012345', '09121230001'
cfg_path = ROOT / 'config.php'
cfg_before = cfg_path.read_text()
settings_before = {r['key']: r['value'] for r in db("SELECT `key`,value FROM settings WHERE `key` IN ('sms_dev_mode','sms_enabled')")}


def setting(k, v):
    db('INSERT INTO settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [k, v])


def last_code(mobile):
    rows = db("SELECT tokens FROM sms_messages WHERE event='login' AND mobile=? ORDER BY id DESC LIMIT 1", [mobile])
    m = re.search(r'\d{6}', rows[0]['tokens']) if rows else None
    return m.group(0) if m else ''


def sms_count(mobile):
    return db("SELECT COUNT(*) n FROM sms_messages WHERE event='login' AND mobile=?", [mobile])[0]['n']


def act(c, data):
    # Direct post (not http_helpers.submit, which turns SMS off for older suites' admin login).
    return post(c, 'admin.php', dict(data, csrf=token(c, 'admin.php')))[1]


def signed_in(c):
    return 'class="admin-shell"' in get(c, 'admin.php')[1]


# ---------- SMS off: e-mail and password as before ----------
setting('sms_enabled', '0')
setting('sms_dev_mode', '0')
db('DELETE FROM request_limits')
c = client()
html = get(c, 'admin.php')[1]
check('ورود مدیران' in html and 'name="password"' in html and 'name="mobile"' not in html and 'کاوه‌نگار' in html, 'SMS off: e-mail and password, with a note about two-step sign-in')
act(c, dict(action='login', email=OWNER_EMAIL, password=OWNER_PASS))
check(signed_in(c), 'SMS off: owner signs in with the password')

# ---------- Add administrator: mobile required, unique, full number ----------
status, html = get(c, 'admin.php?tab=admins')
form = html.split('افزودن مدیر', 1)[1]
check('name="mobile"' in form and re.search(r'name="mobile"[^>]*required', form) is not None and 'placeholder="۰۹۱۲۳۴۵۶۷۸۹"' in form, 'add administrator: mobile field (required, full number)')
db("DELETE FROM admins WHERE email IN ('rc13-a@example.test','rc13-b@example.test')")
db('UPDATE admins SET mobile=NULL WHERE email=?', [OWNER_EMAIL])
html = submit(c, 'admin.php?tab=admins', dict(action='admin_save', name='بدون موبایل', email='rc13-a@example.test', password=PASSWORD, role='finance'))[1]
check('شماره موبایل مدیر را وارد کنید' in html and not db("SELECT id FROM admins WHERE email='rc13-a@example.test'"), 'administrator without a mobile is not created')
html = submit(c, 'admin.php?tab=admins', dict(action='admin_save', name='موبایل بد', email='rc13-a@example.test', mobile='12345', password=PASSWORD, role='finance'))[1]
check('۱۱ رقمی' in html, 'invalid mobile rejected')
html = submit(c, 'admin.php?tab=admins', dict(action='admin_save', name='مدیر مالی rc13', email='rc13-a@example.test', mobile='۰۹۱۲۱۲۳۰۰۰۲', password=PASSWORD, role='finance'))[1]
check('مدیر جدید ساخته شد' in html and db("SELECT mobile FROM admins WHERE email='rc13-a@example.test'")[0]['mobile'] == '09121230002', 'administrator created with the mobile (Persian digits accepted)')
html = submit(c, 'admin.php?tab=admins', dict(action='admin_save', name='تکراری', email='rc13-b@example.test', mobile='09121230002', password=PASSWORD, role='support'))[1]
check('برای مدیر دیگری ثبت شده' in html, 'mobile unique among administrators')
fin_id = db("SELECT id FROM admins WHERE email='rc13-a@example.test'")[0]['id']
html = submit(c, f'admin.php?tab=admins&admin={fin_id}', dict(action='admin_save', id=fin_id, name='مدیر مالی rc13', role='finance', mobile='09121230003'))[1]
check(db('SELECT mobile FROM admins WHERE id=?', [fin_id])[0]['mobile'] == '09121230003', 'owner changes an administrator mobile')
status, html = get(c, 'admin.php?tab=admins')
check('بدون موبایل' in html, 'administrators without a mobile are marked in the list')

# ---------- SMS on (test mode on a development copy) ----------
try:
    cfg_path.write_text(cfg_before.replace("'environment' => 'production'", "'environment' => 'development'"))
    setting('sms_dev_mode', '1')
    db('DELETE FROM auth_rate_limits')
    db('DELETE FROM request_limits')

    g = client()
    html = get(g, 'admin.php')[1]
    check('name="mobile"' in html and 'name="password"' not in html and 'کد پیامکی' in html and 'ایمیل و رمز' in html, 'step 1: mobile only (code first, then e-mail and password)')
    check('placeholder="۰۹۱۲۳۴۵۶۷۸۹"' in html and 'mobile-input' in html and '+۹۸' not in html, 'full 09… number, centred field, no +98 box')

    # Password first is refused for an administrator who has a mobile (same message as a wrong password).
    html = act(g, dict(action='login', email='rc13-a@example.test', password=PASSWORD))
    check('اطلاعات ورود اشتباه است' in html and not signed_in(g), 'password without the SMS step refused')

    # Unknown number: same screen, nothing sent, code never matches.
    before = sms_count('09129999999')
    html = act(g, dict(action='admin_otp_send', mobile='09129999999', purpose='login'))
    check('کد شش‌رقمی' in html and sms_count('09129999999') == before, 'unknown number: same screen, no SMS sent')
    html = act(g, dict(action='admin_otp_verify', code='123456'))
    check('کد تأیید اشتباه است' in html, 'unknown number: code rejected like a wrong code')
    act(g, dict(action='admin_auth_reset'))

    # Finance administrator: code → e-mail and password.
    html = act(g, dict(action='admin_otp_send', mobile='09121230003', purpose='login'))
    code = last_code('09121230003')
    check(len(code) == 6 and '۰۹۱۲۱۲۳۰۰۰۳' in html, 'code sent to the administrator mobile')
    wrong = '000000' if code != '000000' else '111111'
    html = act(g, dict(action='admin_otp_verify', code=wrong))
    check('کد تأیید اشتباه است' in html and 'بار دیگر' in html, 'wrong code: attempts left shown')
    check(db("SELECT attempts FROM otp_challenges WHERE mobile='09121230003' ORDER BY created_at DESC LIMIT 1")[0]['attempts'] == 1, 'wrong attempt counted')
    html = act(g, dict(action='admin_otp_verify', code=code))
    check('ایمیل و رمز عبور' in html and 'name="password"' in html and not signed_in(g), 'step 2 after the code; not signed in yet')
    html = act(g, dict(action='login', email=OWNER_EMAIL, password=OWNER_PASS))
    # The owner has no mobile yet at this point, so the owner's password leads to «register a mobile», never into the panel.
    check(('اطلاعات ورود اشتباه است' in html or 'ثبت شماره موبایل مدیر' in html) and not signed_in(g), "another administrator's password does not complete this sign-in")
    act(g, dict(action='admin_auth_reset'))
    html = act(g, dict(action='admin_otp_send', mobile='09121230003', purpose='login'))
    check('تعداد درخواست‌ها زیاد است' in html, 'resend cooldown: one code per number per minute')
    db('DELETE FROM auth_rate_limits')  # skip the one-minute wait in the test
    act(g, dict(action='admin_otp_send', mobile='09121230003', purpose='login'))
    act(g, dict(action='admin_otp_verify', code=last_code('09121230003')))
    html = act(g, dict(action='login', email='rc13-a@example.test', password=PASSWORD))
    check(signed_in(g) and 'مدیر مالی' in get(g, 'admin.php')[1], 'finance administrator signed in after code + password')
    check(db("SELECT detail FROM audit_log WHERE action='admin.login' ORDER BY id DESC LIMIT 1")[0]['detail'] == 'sms+password', 'two-step sign-in recorded')
    status, html = get(g, 'admin.php?tab=dashboard')
    check(status == 200, 'panel opens')

    # Code reuse is impossible.
    reused = client()
    get(reused, 'admin.php')
    html = act(reused, dict(action='admin_otp_verify', code=code))
    check('ابتدا شماره موبایل' in html, 'a code cannot be used from another session')

    # Owner without a mobile: password once → register the mobile → confirm → signed in.
    o = client()
    html = get(o, 'admin.php?first=1')[1]
    check('name="password"' in html and 'هنوز شماره موبایل' in html, 'first sign-in path for administrators without a mobile')
    html = act(o, dict(action='login', email=OWNER_EMAIL, password=OWNER_PASS))
    check('ثبت شماره موبایل مدیر' in html and not signed_in(o), 'owner without a mobile asked to register one')
    html = act(o, dict(action='admin_otp_send', mobile='09121230003', purpose='register'))
    check('برای مدیر دیگری ثبت شده' in html, "cannot register another administrator's mobile")
    html = act(o, dict(action='admin_otp_send', mobile=OWNER_MOBILE, purpose='register'))
    act(o, dict(action='admin_otp_verify', code=last_code(OWNER_MOBILE)))
    check(signed_in(o) and db('SELECT mobile FROM admins WHERE email=?', [OWNER_EMAIL])[0]['mobile'] == OWNER_MOBILE, 'mobile confirmed by code, saved, owner signed in')
    check(db("SELECT COUNT(*) n FROM audit_log WHERE action='admin.mobile_registered'")[0]['n'] >= 1, 'mobile registration recorded')

    # From now on the owner also needs the code first.
    db('DELETE FROM auth_rate_limits')  # skip the one-minute resend wait in the test
    o2 = client()
    html = act(o2, dict(action='login', email=OWNER_EMAIL, password=OWNER_PASS))
    check('اطلاعات ورود اشتباه است' in html and not signed_in(o2), 'owner now needs the SMS step')
    act(o2, dict(action='admin_otp_send', mobile=OWNER_MOBILE, purpose='login'))
    act(o2, dict(action='admin_otp_verify', code=last_code(OWNER_MOBILE)))
    act(o2, dict(action='login', email=OWNER_EMAIL, password=OWNER_PASS))
    check(signed_in(o2), 'owner signs in with code + password')

    # The patient / doctor sign-in uses the full number too.
    status, html = get(client(), 'login.php')
    check('+۹۸' not in html and 'phone-prefix' not in html and 'placeholder="۰۹۱۲۳۴۵۶۷۸۹"' in html and 'mobile-input' in html, 'patient and doctor sign-in: full 09… number, no +98 box')
finally:
    cfg_path.write_text(cfg_before)
    for k, v in settings_before.items():
        setting(k, v)
    if 'sms_dev_mode' not in settings_before:
        setting('sms_dev_mode', '0')
    db('DELETE FROM auth_rate_limits')
    db('DELETE FROM request_limits')

print('SUCCESS', checks, 'rc.13 administrator sign-in checks')
