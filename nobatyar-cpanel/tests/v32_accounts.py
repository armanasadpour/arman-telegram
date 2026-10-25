"""1.0.0-rc.21 over HTTP: suspending and deleting users and doctors from the administration panel, the doctor
and secretary panel shortcuts on the home page and in the header, and the welcome box at the top of the doctor
dashboard. Uses its own users and doctor (the fixtures of other suites are left untouched). Run after v31."""
from http_helpers import *

afx = json.loads((ROOT.parent.parent / 'runtime' / 'admin-fixture.json').read_text())
fx = json.loads((ROOT.parent.parent / 'runtime' / 'phase6-fixture.json').read_text())
owner = client()
jar = next(h.cookiejar for h in owner.handlers if isinstance(h, urllib.request.HTTPCookieProcessor))
jar.set_cookie(http.cookiejar.Cookie(0, 'PHPSESSID', afx['owner'], None, False, '127.0.0.1', False, False, '/', True, False, None, False, None, None, {}))
db('DELETE FROM request_limits')
db('DELETE FROM auth_rate_limits')


def php(code):
    return subprocess.check_output([PHP] + (['-c', PHPINI] if PHPINI else []) + ['-r', 'require $argv[1];' + code, str(ROOT / 'core.php')]).decode()


def scalar(sql, args=[]):
    rows = db(sql, args)
    return next(iter(rows[0].values())) if rows else None


def otp_attempt(mobile):
    """Requests and verifies a sign-in code; returns (status, response) without asserting success."""
    c = client()
    status, r = api(c, 'session')
    csrf = r['data']['csrf']
    sid = next(x.value for h in c.handlers if isinstance(h, urllib.request.HTTPCookieProcessor) for x in h.cookiejar if x.name == 'PHPSESSID')
    code = """$i=json_decode(stream_get_contents(STDIN),true);session_id($i[0]);require $argv[1];query('DELETE FROM auth_rate_limits');$plain=null;$r=otp_request($i[1],function($m,$code)use(&$plain){$plain=$code;return ['messageid'=>'T'];});echo json_encode([$r,$plain]);"""
    challenge, plain = json.loads(subprocess.check_output([PHP] + (['-c', PHPINI] if PHPINI else []) + ['-r', code, str(ROOT / 'core.php')], input=json.dumps([sid, mobile]).encode()))
    return api(c, 'auth/verify', dict(challenge_id=challenge['challenge_id'], code=plain), csrf)


def future_booking(doctor, clinic, patient_uid, phone):
    """A confirmed booking tomorrow for this doctor, built from an existing slot row (test only)."""
    cols = [r['Field'] for r in db('SHOW COLUMNS FROM slots') if r['Field'] != 'id']
    src = db('SELECT id FROM slots ORDER BY id DESC LIMIT 1')[0]['id']
    sel = [('?' if c in ('doctor_id', 'clinic_id', 'starts_at', 'ends_at') else f'`{c}`') for c in cols]
    start = php('echo date("Y-m-d 10:00:00",strtotime("+1 day"));')
    end = php('echo date("Y-m-d 10:15:00",strtotime("+1 day"));')
    vals = []
    for c in cols:
        if c == 'doctor_id': vals.append(doctor)
        elif c == 'clinic_id': vals.append(clinic)
        elif c == 'starts_at': vals.append(start)
        elif c == 'ends_at': vals.append(end)
    db(f"INSERT INTO slots({','.join('`'+c+'`' for c in cols)}) SELECT {','.join(sel)} FROM slots WHERE id=?", vals + [src])
    slot = scalar('SELECT MAX(id) FROM slots WHERE doctor_id=?', [doctor])
    code = php('echo new_tracking_code();')
    db("INSERT INTO bookings(slot_id,patient_name,phone,tracking_code,patient_user_id,status,confirmed_at,fee_toman) VALUES(?,?,?,?,?,'confirmed',NOW(),0)", [slot, 'بیمار آزمون حذف', phone, code, patient_uid])
    return scalar('SELECT MAX(id) FROM bookings WHERE slot_id=?', [slot])


# ---------- Suspend / unsuspend a user ----------
U_MOB = '09127700901'
u = client()
uid = login_user(u, U_MOB)
html = get(owner, f'admin.php?tab=users&user={uid}')[1]
check('تعلیق کاربر' in html and 'حذف کاربر' in html and 'name="confirm"' in html, 'user page: suspend and delete (owner)')
submit(owner, f'admin.php?tab=users&user={uid}', dict(action='user_suspend', user_id=uid, reason='تخلف در ثبت نوبت'))
check(scalar('SELECT active FROM users WHERE id=?', [uid]) == 0, 'user suspended')
check(get(u, 'panel.php')[0] != 200 or 'name="action" value="logout"' not in get(u, 'panel.php')[1], 'suspended user signed out everywhere')
st, r = otp_attempt(U_MOB)
check(st == 403 and 'تعلیق' in r['error']['message'] and 'تخلف در ثبت نوبت' in r['error']['message'], 'sign-in refused with the reason')
html = get(owner, f'admin.php?tab=users&user={uid}')[1]
check('رفع تعلیق' in html and 'تخلف در ثبت نوبت' in html, 'admin sees the reason and «رفع تعلیق»')
submit(owner, f'admin.php?tab=users&user={uid}', dict(action='user_unsuspend', user_id=uid))
st, r = otp_attempt(U_MOB)
check(scalar('SELECT active FROM users WHERE id=?', [uid]) == 1 and st == 200, 'suspension lifted: sign-in works again')

# ---------- Delete a user ----------
clinic = fx['clinic']
doctor = fx['doctor']
bid = future_booking(doctor, clinic, uid, U_MOB)
db("INSERT INTO family_members(user_id,name,relationship,active) VALUES(?,'عضو آزمون','فرزند',1)", [uid]) if db("SHOW COLUMNS FROM family_members LIKE 'relationship'") else None
html = submit(owner, f'admin.php?tab=users&user={uid}', dict(action='user_delete', user_id=uid, confirm='بله'))[1]
check('«حذف»' in html and scalar('SELECT deleted_at FROM users WHERE id=?', [uid]) is None, 'delete needs the typed word «حذف»')
html = submit(owner, f'admin.php?tab=users&user={uid}', dict(action='user_delete', user_id=uid, confirm='حذف'))[1]
row = db('SELECT * FROM users WHERE id=?', [uid])[0]
check(row['deleted_at'] and row['name'] == 'کاربر حذف‌شده' and row['mobile'] != U_MOB and row['active'] == 0 and row['national_id_enc'] is None, 'user deleted: anonymized and deactivated')
check(scalar('SELECT status FROM bookings WHERE id=?', [bid]) == 'cancelled' and 'نوبت آینده او لغو' in html, "user's future booking cancelled")
check(scalar('SELECT COUNT(*) FROM family_members WHERE user_id=? AND name<>?', [uid, 'عضو حذف‌شده']) == 0, 'family members anonymized')
check(f'admin.php?tab=users&amp;user={uid}"' not in get(owner, 'admin.php?tab=users&q=09127700901')[1], 'deleted user not listed')
st, r = otp_attempt(U_MOB)
check(st == 200 and r['data']['status'] == 'profile_required', 'the number is free: signing in starts a new account')

# ---------- Doctor: suspend / unsuspend / delete ----------
D_MOB = '09127700902'
dc = client()
login_user(dc, D_MOB)
city = db('SELECT c.id,c.province_id FROM cities c JOIN provinces p ON p.id=c.province_id WHERE c.active=1 AND p.active=1 ORDER BY c.id LIMIT 1')[0]
spec = db('SELECT id FROM specialties WHERE active=1 AND deleted_at IS NULL ORDER BY id LIMIT 1')[0]['id']
for t in ['doctor_accounts a JOIN doctors d ON d.id=a.doctor_id', 'clinics a JOIN doctors d ON d.id=a.doctor_id', 'appointment_events a JOIN doctors d ON d.id=a.doctor_id']:
    db(f'DELETE a FROM {t} WHERE d.mobile=?', [D_MOB])
db('DELETE FROM doctors WHERE mobile=?', [D_MOB])
html = submit(dc, 'register.php', dict(name='حذف آزمون', medical_code='998877', specialty_id=spec, specialty_other='', gender='male', bio='',
                                       clinic_name='مطب حذف', province_id=city['province_id'], city_id=city['id'], district='', address='نشانی آزمون',
                                       phone='', latitude='', longitude='', notes='', website='', consent='1', plan_choice='free'))[1]
did = scalar('SELECT id FROM doctors WHERE mobile=?', [D_MOB])
dcl = scalar('SELECT id FROM clinics WHERE doctor_id=?', [did])
submit(owner, f'admin.php?tab=doctors&doctor={did}', dict(action='review', id=did, status='approved', active='1', review_note=''))
check(get(client(), f'doctor.php?id={did}')[0] == 200, 'test doctor approved and public')

# Welcome box and home shortcuts for the doctor.
html = get(dc, f'doctor-panel.php?doctor={did}')[1]
check('dr-welcome' in html and 'آقای دکتر حذف آزمون، خوش آمدید' in html, 'welcome box: «آقای دکتر …، خوش آمدید» (no double «دکتر»)')
check('نوع اشتراک' in html and 'plan-chip is-free' in html and 'خرید اشتراک پرو' in html and 'tab=subscription' in html, 'welcome box: plan «رایگان» and «خرید اشتراک پرو»')
home = get(dc, 'index.php')[1]
check('work-strip' in home and 'ورود به پنل پزشک' in home and 'href="doctor-panel.php"' in home, 'home page: «ورود به پنل پزشک» for a doctor number')
check('header-work' in home, 'header: doctor panel button')
check('work-strip' not in get(client(), 'index.php')[1], 'guests: no panel shortcuts')

# Secretary shortcut (another user with a secretary scope).
s = client()
sec_uid = login_user(s, '09127700903')
db("INSERT IGNORE INTO user_roles(user_id,role) VALUES(?,'secretary')", [sec_uid])
db("INSERT INTO staff_scopes(user_id,clinic_id,role) VALUES(?,?,'secretary')", [sec_uid, dcl])
home = get(s, 'index.php')[1]
check('ورود به پنل منشی' in home and 'href="secretary.php"' in home, 'home page: «ورود به پنل منشی» for a secretary')

# Suspend.
html = submit(owner, f'admin.php?tab=doctors&doctor={did}', dict(action='doctor_suspend', doctor_id=did, reason=''))[1]
check('علت تعلیق' in html and scalar('SELECT review_status FROM doctors WHERE id=?', [did]) == 'approved', 'suspending needs a reason')
submit(owner, f'admin.php?tab=doctors&doctor={did}', dict(action='doctor_suspend', doctor_id=did, reason='مدارک ناقص'))
check(scalar('SELECT review_status FROM doctors WHERE id=?', [did]) == 'suspended' and get(client(), f'doctor.php?id={did}')[0] == 404, 'doctor suspended and hidden')
submit(owner, f'admin.php?tab=doctors&doctor={did}', dict(action='doctor_unsuspend', doctor_id=did))
check(scalar('SELECT review_status FROM doctors WHERE id=?', [did]) == 'approved' and get(client(), f'doctor.php?id={did}')[0] == 200, 'suspension lifted: public again')

# Delete with a future booking.
p = client()
puid = login_user(p, '09127700904')
pb = future_booking(did, dcl, puid, '09127700904')
html = get(owner, f'admin.php?tab=doctors&doctor={did}')[1]
check('حذف پزشک' in html and '۱ نوبت آینده لغو می‌شود' in html, 'delete warns about the future booking')
html = submit(owner, f'admin.php?tab=doctors&doctor={did}', dict(action='doctor_delete', doctor_id=did, confirm='حذف'))[1]
drow = db('SELECT * FROM doctors WHERE id=?', [did])[0]
check(drow['deleted_at'] and drow['mobile'] is None and drow['medical_code'] is None and drow['active'] == 0, 'doctor deleted: mobile and council number released')
check(scalar('SELECT status FROM bookings WHERE id=?', [pb]) == 'cancelled' and scalar("SELECT COUNT(*) FROM notifications WHERE booking_id=? AND user_id=? AND title LIKE '%لغو%'", [pb, puid]) == 1, 'future booking cancelled and the patient notified')
check(get(client(), f'doctor.php?id={did}')[0] == 404 and f'doctor={did}"' not in get(owner, 'admin.php?tab=doctors')[1], 'deleted doctor hidden from the site and the admin list')
check(not db('SELECT 1 FROM doctor_accounts WHERE doctor_id=?', [did]) and not db('SELECT 1 FROM staff_scopes WHERE clinic_id=?', [dcl]), "doctor account link and secretaries' access removed")
status, html = get(dc, 'doctor-panel.php')
check('ثبت‌نام پزشک' in html and 'work-strip' not in get(dc, 'index.php')[1], 'former doctor account: registration form again, no panel shortcut')

# ---------- Permissions: support may suspend, not delete ----------
db("DELETE FROM admins WHERE email='rc21-support@example.test'")
submit(owner, 'admin.php?tab=admins', dict(action='admin_save', name='پشتیبان rc21', email='rc21-support@example.test', mobile='09121009977', password='Strong-pass-2026', role='support'))
sup = client()
submit(sup, 'admin.php', dict(action='login', email='rc21-support@example.test', password='Strong-pass-2026'))
t = client()
tuid = login_user(t, '09127700905')
html = get(sup, f'admin.php?tab=users&user={tuid}')[1]
check('تعلیق کاربر' in html and 'حذف کاربر' not in html, 'support: suspend shown, delete hidden')
html = submit(sup, f'admin.php?tab=users&user={tuid}', dict(action='user_delete', user_id=tuid, confirm='حذف'))[1]
check(scalar('SELECT deleted_at FROM users WHERE id=?', [tuid]) is None and 'مالک سامانه و مدیر اجرایی' in html, 'support: delete refused on the server')

db('DELETE FROM request_limits')
db('DELETE FROM auth_rate_limits')
print('SUCCESS', checks, 'rc.21 account checks')
