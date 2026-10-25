"""1.0.0-rc.14 over HTTP: doctor sign-up paths. «ورود پزشکان» for an account without a doctor profile leads to
the registration form (no dead-end «پزشک پیدا نشد»); a registered doctor sees the request status instead of a
second form; a doctor profile the administrator created with the same verified mobile is requested from support (never
linked automatically) and linked by the administrator;
a few corrected mistakes never lock the form. Run after v12_http.py."""
from http_helpers import *

spec = db('SELECT id FROM specialties WHERE active=1 ORDER BY id LIMIT 1')[0]['id']
city = db('SELECT c.id,c.province_id FROM cities c JOIN provinces p ON p.id=c.province_id WHERE c.active=1 AND p.active=1 ORDER BY c.id LIMIT 1')[0]
NEW, PRE = '09127700101', '09127700102'
db('DELETE FROM request_limits')
for m in (NEW, PRE):
    db('DELETE a FROM doctor_accounts a JOIN doctors d ON d.id=a.doctor_id WHERE d.mobile=?', [m])
    db('DELETE c FROM clinics c JOIN doctors d ON d.id=c.doctor_id WHERE d.mobile=?', [m])
    db('DELETE FROM doctors WHERE mobile=?', [m])


def body(html):
    m = re.search(r'<main.*?</main>', html, re.S)
    return m.group(0) if m else html


def form(mobile, **over):
    data = dict(name='ثبت‌نام rc14', medical_code=str(int(mobile[-6:]) + 300000), specialty_id=spec, gender='female',
                bio='', clinic_name='مطب rc14', province_id=city['province_id'], city_id=city['id'], district='',
                address='خیابان آزمون پلاک ۲', phone='', latitude='', longitude='', notes='', website='', consent='1')
    data.update(over)
    return {k: v for k, v in data.items() if v is not None}


# ---------- New doctor: «ورود پزشکان» → registration form ----------
c = client()
login_user(c, NEW)
status, html = get(c, 'doctor-panel.php')
check(status == 200 and 'ثبت‌نام پزشک' in html and 'name="medical_code"' in html and 'پزشک پیدا نشد' not in html, 'doctor sign-in without a profile opens the registration form')

# Mistakes are shown and do not lock the form.
for _ in range(6):
    html = submit(c, 'register.php', form(NEW, consent=None))[1]
check('رضایت' in html and 'تعداد درخواست‌ها زیاد است' not in html, 'six corrected mistakes: error shown, form not locked')
html = submit(c, 'register.php', form(NEW))[1]
check('درخواست شما ثبت شد' in html, 'registration saved')
d = db('SELECT id,review_status FROM doctors WHERE mobile=?', [NEW])
check(len(d) == 1 and d[0]['review_status'] == 'pending', 'pending doctor created and linked')

status, html = get(c, 'register.php')
check('در انتظار بررسی مدیر' in html and 'name="medical_code"' not in html and 'doctor-panel.php' in html, 'registered doctor sees the request status, not a second form')
status, html = get(c, 'doctor-panel.php')
check(status == 200 and 'نمای کلی' in html, 'pending doctor opens the doctor panel')
db("UPDATE doctors SET review_status='rejected',review_note='کد نظام پزشکی خوانا نیست' WHERE mobile=?", [NEW])
html = get(c, 'register.php')[1]
check('ردشده' in html and 'کد نظام پزشکی خوانا نیست' in html, 'rejected request shows the administrator note')

# ---------- Doctor created by the administrator: claim with the same verified mobile ----------
db("INSERT INTO doctors(name,specialty,specialty_id,mobile,medical_code,bio,city_id,clinic_address,active,review_status) VALUES('دکتر ازپیش‌ساخته','قلب',?,?,'771234','',?,'نشانی',1,'approved')",
   [spec, PRE, city['id']])
pre_id = db('SELECT id FROM doctors WHERE mobile=?', [PRE])[0]['id']
o = client()
login_user(o, PRE)
status, html = get(o, 'doctor-panel.php')
check('پروفایل شما در نوبت‌یار وجود دارد' in html and 'دکتر ازپیش‌ساخته' in html and 'name="medical_code"' not in html, 'existing profile offered for claiming instead of the form')
html = submit(o, 'register.php', form(PRE, medical_code='771235'))[1]
check(not db('SELECT id FROM doctors WHERE medical_code=?', ['771235']), 'no duplicate profile created while a claim is pending')
db("DELETE FROM support_tickets WHERE subject LIKE 'درخواست اتصال پروفایل پزشک%'")
status, html = submit(o, 'register.php', dict(action='claim'))
t = db("SELECT id,user_id,category FROM support_tickets WHERE subject=?", [f'درخواست اتصال پروفایل پزشک #{pre_id}'])
check(len(t) == 1 and t[0]['category'] == 'doctor' and 'درخواست اتصال شما ثبت شده است' in html, 'claim sends a link request to support')
check(not db('SELECT user_id FROM doctor_accounts WHERE doctor_id=?', [pre_id]), 'an administrator-entered number is never linked automatically')
submit(o, 'register.php', dict(action='claim'))
check(len(db("SELECT id FROM support_tickets WHERE subject=?", [f'درخواست اتصال پروفایل پزشک #{pre_id}'])) == 1, 'one open request per profile')
check(db("SELECT COUNT(*) n FROM audit_log WHERE action='doctor.claim_requested' AND entity_id=?", [pre_id])[0]['n'] == 1, 'request recorded in the activity log')

# The administrator approves with «اتصال به پزشک»; the doctor then reaches the panel.
fixture = json.loads((ROOT.parent.parent / 'runtime' / 'admin-fixture.json').read_text())
admin = client()
jar = next(h.cookiejar for h in admin.handlers if isinstance(h, urllib.request.HTTPCookieProcessor))
jar.set_cookie(http.cookiejar.Cookie(0, 'PHPSESSID', fixture['owner'], None, False, '127.0.0.1', False, False, '/', True, False, None, False, None, None, {}))
uid = db('SELECT id FROM users WHERE mobile=?', [PRE])[0]['id']
html = submit(admin, f'admin.php?tab=users&user={uid}', dict(action='link_doctor', user_id=uid, doctor_id=pre_id))[1]
check(db('SELECT user_id FROM doctor_accounts WHERE doctor_id=?', [pre_id]), 'administrator links the profile')
status, html = get(o, 'doctor-panel.php')
check('دکتر ازپیش‌ساخته' in html and 'نمای کلی' in html, 'linked doctor opens the doctor panel')
status, html = get(o, 'register.php')
check('نمای کلی' in html and 'name="medical_code"' not in html, 'approved doctor visiting registration goes to the panel')

# Someone else's number cannot ask for it.
x = client()
login_user(x, '09127700103')
html = submit(x, 'register.php', dict(action='claim'))[1]
check('name="medical_code"' in html and not db("SELECT id FROM support_tickets WHERE subject=? AND user_id<>?", [f'درخواست اتصال پروفایل پزشک #{pre_id}', uid]), 'request needs the same verified mobile')

db('DELETE FROM request_limits')
print('SUCCESS', checks, 'rc.14 doctor registration checks')
