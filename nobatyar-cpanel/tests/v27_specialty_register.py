"""1.0.0-rc.16 over HTTP: doctor registration never stops at «ثبت‌نام پس از تعریف شهر و تخصص توسط مدیر فعال
می‌شود». A doctor whose specialty is not listed — or a site with no active specialty — types it in; the
administrator sees the typed specialty and must pick a listed one before approval. The administrator adds a
suggested specialty list with one click (existing names are never duplicated) and the dashboard flags a site
without specialties. Run after v12_http.py."""
from http_helpers import *

fixture = json.loads((ROOT.parent.parent / 'runtime' / 'admin-fixture.json').read_text())
owner = client()
jar = next(h.cookiejar for h in owner.handlers if isinstance(h, urllib.request.HTTPCookieProcessor))
jar.set_cookie(http.cookiejar.Cookie(0, 'PHPSESSID', fixture['owner'], None, False, '127.0.0.1', False, False, '/', True, False, None, False, None, None, {}))

# ---------- Suggested list, one click ----------
html = get(owner, 'admin.php?tab=specialties')[1]
check('افزودن فهرست پیشنهادی' in html, 'specialties tab offers the suggested list')
before = db('SELECT COUNT(*) n FROM specialties')[0]['n']
html = submit(owner, 'admin.php?tab=specialties', dict(action='specialty_starter'))[1]
names = {r['name']: r for r in db('SELECT s.name,s.level,p.name parent FROM specialties s LEFT JOIN specialties p ON p.id=s.parent_id')}
check('تخصص به فهرست اضافه شد' in html and all(n in names for n in ['پزشک عمومی', 'قلب و عروق', 'زنان و زایمان', 'کودکان', 'دندان‌پزشک عمومی', 'فیزیوتراپی']), 'suggested specialties added')
check(names['گوارش و کبد']['level'] == 'subspecialty' and names['گوارش و کبد']['parent'] == 'داخلی', 'subspecialties under their parent')
after = db('SELECT COUNT(*) n FROM specialties')[0]['n']
html = submit(owner, 'admin.php?tab=specialties', dict(action='specialty_starter'))[1]
check('از قبل وجود دارند' in html and db('SELECT COUNT(*) n FROM specialties')[0]['n'] == after > before, 'second click adds nothing (no duplicates)')

city = db('SELECT c.id,c.province_id FROM cities c JOIN provinces p ON p.id=c.province_id WHERE c.active=1 AND p.active=1 ORDER BY c.id LIMIT 1')[0]
listed = db("SELECT id FROM specialties WHERE name='قلب و عروق'")[0]['id']
MOB = ['09127700201', '09127700202']
db('DELETE FROM request_limits')
for m in MOB:
    db('DELETE a FROM doctor_accounts a JOIN doctors d ON d.id=a.doctor_id WHERE d.mobile=?', [m])
    db('DELETE c FROM clinics c JOIN doctors d ON d.id=c.doctor_id WHERE d.mobile=?', [m])
    db('DELETE e FROM appointment_events e JOIN doctors d ON d.id=e.doctor_id WHERE d.mobile=?', [m])
    db('DELETE FROM doctors WHERE mobile=?', [m])


def form(mobile, **over):
    data = dict(name='تخصص rc16', medical_code=str(int(mobile[-6:]) + 500000), specialty_id='', specialty_other='', gender='',
                bio='', clinic_name='مطب rc16', province_id=city['province_id'], city_id=city['id'], district='',
                address='خیابان آزمون پلاک ۳', phone='', latitude='', longitude='', notes='', website='', consent='1')
    data.update(over)
    return data


# ---------- Specialty not in the list ----------
c = client()
login_user(c, MOB[0])
html = get(c, 'register.php')[1]
check('ثبت‌نام پس از تعریف شهر و تخصص' not in html and 'name="specialty_id"' in html and 'name="specialty_other"' in html, 'form shows the list and a field for an unlisted specialty')
check(re.search(r'<select name="specialty_id"[^>]*required', html) is None, 'list not mandatory when the specialty can be typed')
html = submit(c, 'register.php', form(MOB[0]))[1]
check('تخصص را از فهرست انتخاب کنید یا' in html and not db('SELECT id FROM doctors WHERE mobile=?', [MOB[0]]), 'neither chosen nor typed: clear error')
html = submit(c, 'register.php', form(MOB[0], specialty_other='طب سنتی'))[1]
check('درخواست شما ثبت شد' in html, 'registered with a typed specialty')
d = db('SELECT id,specialty,specialty_id FROM doctors WHERE mobile=?', [MOB[0]])[0]
check(d['specialty'] == 'طب سنتی' and d['specialty_id'] is None, 'typed specialty kept for the administrator')

path = f'admin.php?tab=doctors&doctor={d["id"]}'
html = get(owner, path)[1]
check('تخصص اعلام‌شده توسط پزشک' in html and 'طب سنتی' in html, 'administrator sees the typed specialty')
html = submit(owner, path, dict(action='review', id=d['id'], status='approved', active='1', review_note=''))[1]
check('پیش از تأیید' in html and db('SELECT review_status FROM doctors WHERE id=?', [d['id']])[0]['review_status'] == 'pending', 'approval needs a listed specialty')
row = db('SELECT * FROM doctors WHERE id=?', [d['id']])[0]
submit(owner, path, dict(action='edit_doctor', id=d['id'], name=row['name'], mobile=row['mobile'], medical_code=row['medical_code'], specialty_id=listed, bio=''))
submit(owner, path, dict(action='review', id=d['id'], status='approved', active='1', review_note=''))
check(db('SELECT review_status,specialty_id FROM doctors WHERE id=?', [d['id']])[0] == {'review_status': 'approved', 'specialty_id': listed}, 'approved after the administrator picks a listed specialty')

# ---------- Listed specialty still works ----------
c2 = client()
login_user(c2, MOB[1])
html = submit(c2, 'register.php', form(MOB[1], specialty_id=listed))[1]
check('درخواست شما ثبت شد' in html and db('SELECT specialty_id FROM doctors WHERE mobile=?', [MOB[1]])[0]['specialty_id'] == listed, 'registered with a listed specialty')

# ---------- No active specialty at all: form still opens, typed specialty required ----------
for t in ['doctor_accounts a JOIN doctors d ON d.id=a.doctor_id', 'clinics a JOIN doctors d ON d.id=a.doctor_id', 'appointment_events a JOIN doctors d ON d.id=a.doctor_id']:
    db(f'DELETE a FROM {t} WHERE d.mobile=?', [MOB[1]])
db('DELETE FROM doctors WHERE mobile=?', [MOB[1]])
active_before = [r['id'] for r in db('SELECT id FROM specialties WHERE active=1')]
try:
    db('UPDATE specialties SET active=0')
    html = get(c2, 'register.php')[1]
    check('ثبت‌نام پس از تعریف شهر و تخصص' not in html and 'name="specialty_id"' not in html and re.search(r'name="specialty_other"[^>]*required', html) is not None, 'no specialty list: the form still opens with a typed specialty')
    html = submit(c2, 'register.php', form(MOB[1], specialty_other='قلب'))[1]
    check('درخواست شما ثبت شد' in html, 'no specialty list: registration completes')
    html = get(owner, 'admin.php?tab=dashboard')[1]
    check('هیچ تخصصی تعریف نشده' in html, 'dashboard flags a site without specialties')
    check('هنوز هیچ تخصص فعالی تعریف نشده است' in get(owner, 'admin.php?tab=specialties')[1], 'specialties tab explains the empty list')
finally:
    if active_before:
        db('UPDATE specialties SET active=1 WHERE id IN (' + ','.join('?' * len(active_before)) + ')', active_before)

db('DELETE FROM request_limits')
print('SUCCESS', checks, 'rc.16 specialty registration checks')
