"""1.0.0-rc.11 over HTTP: large booking button on the doctor profile; patient details with national ID under
the last name, Jalali birth date (day / month / year) and insurance type, for the account holder and for
anyone booked for; incomplete family members blocked; daily Excel list for the doctor and the secretary
(columns, access, audit). Run after reception_http.py (uses its fixture)."""
from http_helpers import *
import zipfile, io

f = json.loads((ROOT.parent.parent / 'runtime' / 'reception-fixture.json').read_text())


def session(sid):
    c = client()
    jar = next(h.cookiejar for h in c.handlers if isinstance(h, urllib.request.HTTPCookieProcessor))
    jar.set_cookie(http.cookiejar.Cookie(0, 'PHPSESSID', sid, None, False, '127.0.0.1', False, False, '/', True, False, None, False, None, None, {}))
    return c


def sheet_rows(data):
    z = zipfile.ZipFile(io.BytesIO(data))
    xml = z.read('xl/worksheets/sheet1.xml').decode()
    rows = []
    for row in re.findall(r'<row r="\d+">(.*?)</row>', xml):
        rows.append([html_unescape(v) for v in re.findall(r'<c [^>]*>(?:<is><t xml:space="preserve">(.*?)</t></is>|<v>(.*?)</v>)</c>', row)])
    return [[a or b for a, b in r] for r in rows], xml


def html_unescape(t):
    return tuple(x.replace('&amp;', '&').replace('&lt;', '<').replace('&gt;', '>').replace('&quot;', '"').replace('&#039;', "'") for x in t)


# ---------- Doctor profile: booking button above share ----------
status, html = get(client(), f"doctor.php?id={f['doctor']}")
check(status == 200 and 'class="dp-cta"' in html and html.index('class="dp-cta"') < html.index('data-share '), 'large booking button above the share button')
check('dp-book-top' not in html and re.search(r'class="dp-cta" href="doctor\.php\?id=\d+&amp;clinic=\d+&amp;times=1"', html) is not None, 'booking button opens the time picker')

# ---------- Patient details ----------
c = client()
uid = login_user(c, '09127775501')
db('UPDATE users SET birth_date=NULL,insurance=NULL,birth_year=NULL,gender=NULL,national_id_enc=NULL,id_type=NULL,city_id=NULL WHERE id=?', [uid])
db("UPDATE settings SET value='required' WHERE `key`='patient_id_mode'")
status, html = get(c, f"doctor.php?id={f['doctor']}&clinic={f['clinic']}&service={f['service']}&date=" + urllib.parse.quote(f['date']))
book = re.findall(r'href="(service-book\.php\?[^"]+)"', html)[-1].replace('&amp;', '&')
status, html = get(c, book)
check('تکمیل اطلاعات' in html and all(f'name="{n}"' in html for n in ['national_id', 'birth_d', 'birth_m', 'birth_y', 'insurance']) and 'name="birth_year"' not in html, 'details form: national ID, day/month/year and insurance')
check(html.index('name="last_name"') < html.index('name="national_id"') < html.index('name="birth_d"'), 'national ID right under the last name')
check(all(l in html for l in ['تأمین اجتماعی', 'خدمات درمانی', 'نیروهای مسلح', 'روستایی', 'آزاد (بدون بیمه)']), 'insurance choices')
check('>فروردین<' in html and '>اسفند<' in html and '>۱۴۰۵<' in html, 'Jalali month names and years')
city = db('SELECT id,province_id FROM cities WHERE active=1 LIMIT 1')[0]
base = dict(action='profile', first_name='مریم', last_name='رضایی', national_id='0012345679', gender='female', insurance='tamin', province_id=city['province_id'], city_id=city['id'], birth_d='12', birth_m='4', birth_y='1365')
for bad, words, label in [
    (dict(birth_d='31', birth_m='7'), 'روز ۳۱ ندارد', 'impossible Jalali date (31 Mehr)'),
    (dict(birth_m=''), 'کامل انتخاب', 'partial birth date'),
    (dict(birth_d='', birth_m='', birth_y=''), 'تاریخ تولد', 'missing birth date'),
    (dict(birth_y='1500'), 'معتبر نیست', 'future year'),
    (dict(insurance=''), 'نوع بیمه را انتخاب کنید', 'missing insurance'),
    (dict(insurance='other'), 'نوع بیمه نامعتبر', 'unknown insurance'),
    (dict(national_id=''), 'کد ملی را وارد کنید', 'missing national ID'),
    (dict(national_id='0012345678'), 'کد ملی معتبر نیست', 'national ID check digit'),
]:
    status, html = submit(c, book, dict(base, **bad))
    check(words in html and db('SELECT birth_date FROM users WHERE id=?', [uid])[0]['birth_date'] is None, 'rejected: ' + label)
status, html = submit(c, book, base)
row = db('SELECT birth_date,birth_year,insurance,gender,id_type FROM users WHERE id=?', [uid])[0]
check(row == dict(birth_date='1986-07-03', birth_year=1365, insurance='tamin', gender='female', id_type='national'), '12 Tir 1365 saved as 1986-07-03 with insurance and national ID')
status, html = get(c, book)
check('data-who-form' in html and 'تکمیل اطلاعات' not in html.split('data-who-form')[0], 'details asked once, then the patient chooses who the visit is for')
status, html = submit(c, book, dict(base, national_id='', insurance='salamat'))
check(db('SELECT insurance FROM users WHERE id=?', [uid])[0]['insurance'] == 'salamat' and db('SELECT national_id_enc FROM users WHERE id=?', [uid])[0]['national_id_enc'], 'editing keeps the saved national ID when the field is left empty')
status, html = submit(c, book, dict(base, national_id='AB12345', foreign='1', insurance='free'))
check(db('SELECT id_type FROM users WHERE id=?', [uid])[0]['id_type'] == 'foreign', 'foreign nationals give a passport or residency number')
# «اتباع خارجی هستم»: the tick alone is enough — no national ID needed, passport number optional.
status, html = get(c, book + '&x=1')
check('اتباع خارجی هستم (کد ملی ندارم)' in html and 'data-nid-foreign' in html, 'foreign-national tick under the national ID')
db('UPDATE users SET id_type=NULL,national_id_enc=NULL WHERE id=?', [uid])
status, html = submit(c, book, dict(base, national_id='', foreign='1'))
row = db('SELECT id_type,national_id_enc FROM users WHERE id=?', [uid])[0]
check(row['id_type'] == 'foreign' and row['national_id_enc'] is None and 'data-who-form' in html, 'foreign national saved without any ID number and not asked again')
status, html = submit(c, book, dict(base, national_id='', foreign=''))
check('کد ملی را وارد کنید' in html and db('SELECT id_type FROM users WHERE id=?', [uid])[0]['id_type'] == 'foreign', 'unticking requires a national ID again')
status, html = submit(c, book, dict(base, national_id='0012345679', foreign=''))
check(db('SELECT id_type FROM users WHERE id=?', [uid])[0]['id_type'] == 'national', 'switching back to a national ID')

# ---------- Another person ----------
fam = dict(action='family', name='علی رضایی', relationship='فرزند', gender='male', birth_d='5', birth_m='11', birth_y='1398', insurance='armed', national_id='1234567891')
status, html = get(c, book)
add = html.split('دریافت نوبت برای شخص دیگر', 1)[1]
check(all(f'name="{n}"' in add for n in ['national_id', 'birth_d', 'birth_m', 'birth_y', 'insurance', 'gender']) and re.search(r'name="national_id"[^>]*required', add) is not None and re.search(r'name="insurance"\s+required', add) is not None, 'another person: national ID, insurance and full birth date required')
for bad, words, label in [(dict(national_id=''), 'کد ملی را وارد کنید', 'national ID'), (dict(insurance=''), 'نوع بیمه را انتخاب کنید', 'insurance'), (dict(birth_d=''), 'کامل انتخاب', 'birth date'), (dict(gender=''), 'جنسیت', 'gender')]:
    status, html = submit(c, book, dict(fam, **bad))
    check(words in html and not db('SELECT id FROM family_members WHERE user_id=? AND name=?', [uid, 'علی رضایی']), 'another person rejected without ' + label)
status, html = submit(c, book, dict(fam, name='همسر خارجی', relationship='همسر', national_id='', foreign='1'))
fx = db('SELECT id_type,national_id_enc FROM family_members WHERE user_id=? AND name=?', [uid, 'همسر خارجی'])
check(fx and fx[0]['id_type'] == 'foreign' and fx[0]['national_id_enc'] is None and 'اتباع خارجی' in html, 'another person: foreign national without ID number')
status, html = submit(c, book, fam)
m = db('SELECT * FROM family_members WHERE user_id=? AND name=?', [uid, 'علی رضایی'])[0]
check(m['birth_date'] == '2020-01-25' and m['gender'] == 'male' and m['insurance'] == 'armed' and m['id_type'] == 'national' and m['national_id_enc'], 'another person saved with every detail')
db('INSERT INTO family_members(user_id,name,relationship,birth_date) VALUES(?,?,?,?)', [uid, 'عضو قدیمی', 'سایر', '2010-01-01'])
old = db('SELECT id FROM family_members WHERE user_id=? AND name=?', [uid, 'عضو قدیمی'])[0]['id']
status, html = get(c, book)
check('اطلاعات ناقص است' in html and f'complete={old}' in html, 'members saved before this version are marked incomplete')
status, html = submit(c, book, dict(action='reserve', family_member_id=old))
check('کامل نیست' in html and not db("SELECT id FROM bookings WHERE family_member_id=?", [old]), 'booking for an incomplete member is blocked')
status, html = get(c, book + f'&complete={old}')
check(f'id="member-{old}"' in html and f'name="id" value="{old}"' in html, 'complete-details form for that member')
status, html = submit(c, book, dict(fam, id=old, name='عضو قدیمی', relationship='سایر', national_id='0012345679', insurance='rural'))
check(not db('SELECT 1 FROM family_members WHERE id=? AND (insurance IS NULL OR national_id_enc IS NULL OR gender IS NULL)', [old]), 'incomplete member completed in place')
status, html = submit(c, book, dict(action='reserve', family_member_id=m['id']))
b = db('SELECT id FROM bookings WHERE patient_user_id=? AND family_member_id=? ORDER BY id DESC LIMIT 1', [uid, m['id']])
check(bool(b), 'booking for the completed family member')
db("UPDATE bookings SET status='confirmed',expires_at=NULL WHERE id=?", [b[0]['id']])

# Patient panel family page uses the same fields.
status, html = get(c, 'patient.php?tab=family')
check('name="birth_d"' in html and 'name="insurance"' in html and 'booking.js' in html, 'family page: same fields')
status, html = submit(c, 'patient.php?tab=family', dict(fam, name='نرگس رضایی', relationship='همسر', gender='female', national_id='', insurance='tamin'))
check('کد ملی را وارد کنید' in html and not db('SELECT id FROM family_members WHERE name=?', ['نرگس رضایی']), 'family page enforces the national ID')

# ---------- Secretary: optional details ----------
s = session(f['secretary_session'])
path = f"secretary.php?clinic={f['clinic']}&tab=patients"
status, html = get(s, path)
check('reception-more' in html and 'name="birth_d"' in html and 'name="insurance"' in html and not re.search(r'name="birth_d"[^>]*required', html), 'secretary form: optional details')
status, html = submit(s, path, dict(action='patient', name='بیمار تلفنی rc11', mobile='09121239901'))
check(db("SELECT COUNT(*) n FROM reception_patients WHERE name='بیمار تلفنی rc11'")[0]['n'] == 1, 'name and mobile still enough at the desk')
status, html = submit(s, path, dict(action='patient', name='بیمار کامل rc11', mobile='09121239902', national_id='1111'))
check('کد ملی معتبر نیست' in html, 'secretary: invalid national ID rejected')
status, html = submit(s, path, dict(action='patient', name='بیمار کامل rc11', mobile='09121239902', national_id='0012345679', gender='female', birth_d='1', birth_m='1', birth_y='1360', insurance='rural', province_id=city['province_id'], city_id=city['id']))
check(db("SELECT gender,birth_date,insurance,city_id FROM reception_patients WHERE name='بیمار کامل rc11'")[0] == dict(gender='female', birth_date='1981-03-21', insurance='rural', city_id=city['id']), 'secretary: full details saved')

# ---------- Daily Excel ----------
doc = session(f['session'])
status, html = get(doc, f"doctor-panel.php?doctor={f['doctor']}&tab=calendar&clinic={f['clinic']}&date=" + urllib.parse.quote(f['date']))
check('export=day' in html and 'دریافت اکسل نوبت‌های' in html, 'doctor calendar: Excel button for the day')
status, html = get(doc, f"doctor-panel.php?doctor={f['doctor']}")
check('export=day' in html and 'دریافت اکسل نوبت‌های امروز' in html, 'doctor dashboard: Excel button for today')
r = doc.open(BASE + f"doctor-panel.php?doctor={f['doctor']}&export=day&clinic={f['clinic']}&date=" + urllib.parse.quote(f['date']))
check('spreadsheetml' in r.headers.get('Content-Type', '') and 'appointments-' in r.headers.get('Content-Disposition', ''), 'doctor downloads an xlsx file')
rows, xml = sheet_rows(r.read())
check(rows[0] == ['ردیف', 'آقا / خانم', 'نام و نام خانوادگی', 'کد ملی', 'تاریخ تولد', 'نوع بیمه', 'شماره تماس', 'تاریخ و ساعت نوبت', 'استان', 'شهر'], 'columns in the requested order')
mine = [x for x in rows[1:] if x[2] == 'علی رضایی']
check(len(mine) == 1 and mine[0][1] == 'آقا' and mine[0][3] == '1234567891' and mine[0][4] == '1398/11/05' and mine[0][5] == 'نیروهای مسلح' and mine[0][6] == '09127775501' and mine[0][7].startswith('1405/') and mine[0][9] != '', 'family member row: full national ID, birth date, insurance, account phone and city')
check([x[0] for x in rows[1:]] == [str(i) for i in range(1, len(rows))], 'row numbers')
fid = db('SELECT id FROM family_members WHERE user_id=? AND name=?', [uid, 'همسر خارجی'])[0]['id']
check('rightToLeft="1"' in xml and re.search(r'<c r="D\d+" t="inlineStr"', xml) is not None, 'right-to-left sheet; national ID kept as text')
statuses = db("SELECT b.status FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.clinic_id=? AND DATE(s.starts_at)=?", [f['clinic'], f['day']])
check(len(rows) - 1 == sum(1 for x in statuses if x['status'] in ('confirmed', 'completed', 'no_show')), 'cancelled and unpaid appointments left out')
check(db("SELECT COUNT(*) n FROM audit_log WHERE action='export.day_list'")[0]['n'] >= 1, 'export recorded in the activity log')

status, html = get(s, f"secretary.php?clinic={f['clinic']}&tab=calendar&date=" + urllib.parse.quote(f['date']))
check('export=day' in html, 'secretary calendar: Excel button')
r = s.open(BASE + f"secretary.php?clinic={f['clinic']}&export=day&date=" + urllib.parse.quote(f['date']))
check('spreadsheetml' in r.headers.get('Content-Type', ''), 'secretary downloads the same list')
srows, _ = sheet_rows(r.read())
check(srows[0] == rows[0] and len(srows) == len(rows), 'secretary file matches the doctor file for the clinic')

anon = client()
r = anon.open(BASE + f"doctor-panel.php?doctor={f['doctor']}&export=day")
check('login.php' in r.geturl(), 'guests cannot download')
other = db('SELECT id FROM clinics WHERE id<>? AND doctor_id<>? LIMIT 1', [f['clinic'], f['doctor']])
if other:
    status, html = get(s, f"secretary.php?clinic={other[0]['id']}&export=day")
    check(status == 403 and 'spreadsheetml' not in html[:300], 'secretary cannot download another clinic')
patient_doc = get(c, f"doctor-panel.php?doctor={f['doctor']}&export=day")
check(patient_doc[0] in (403, 404) and 'ردیف' not in patient_doc[1], 'a patient cannot download the doctor list')

print('SUCCESS', checks, 'rc.11 patient details checks')
