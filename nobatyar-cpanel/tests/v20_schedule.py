"""1.0.0-rc.8 over HTTP: simple weekly schedule (save the whole week at once, up to three shifts a day,
clear errors that change nothing), closures by date (whole days or a few hours), services list and add
form, «موقعیت فعلی من» on the clinic map, and the referrer policy map tiles need. Runs last and puts the
second clinic's hours back as they were."""
from http_helpers import *

fx = json.loads((ROOT.parent.parent / 'runtime' / 'phase4-fixture.json').read_text())
DOCTOR = fx['doctor']


def scalar(sql, args=[]):
    rows = db(sql, args)
    return next(iter(rows[0].values())) if rows else None


def php(code, args=[]):
    return subprocess.check_output([PHP] + (['-c', PHPINI] if PHPINI else []) + ['-r', 'require $argv[1];if(config()["database"]!=="nobatyar_test_http")exit(9);' + code, str(ROOT / 'core.php')] + [str(a) for a in args]).decode()


def clean(html):
    return 'Warning:' not in html and 'Fatal error' not in html and 'Notice:' not in html


db('DELETE FROM request_limits')
db('DELETE FROM auth_rate_limits')
doc = client()
login_user(doc, '09127770001')
C2 = int(scalar("SELECT id FROM clinics WHERE doctor_id=? AND name='مطب دوم'", [DOCTOR]))
page = f'doctor-panel.php?doctor={DOCTOR}&tab=schedule&clinic={C2}'
original = db('SELECT weekday,opens_at,closes_at FROM working_hours WHERE clinic_id=? AND active=1 ORDER BY weekday,opens_at', [C2])


def hours():
    return [(r['weekday'], r['opens_at'][:5], r['closes_at'][:5]) for r in db('SELECT weekday,opens_at,closes_at FROM working_hours WHERE clinic_id=? AND active=1 ORDER BY weekday,opens_at', [C2])]


def week(days):
    """days: {weekday: [(from, to), ...]} → form fields of the weekly editor."""
    data = dict(action='week', clinic_id=C2)
    for w, shifts in days.items():
        data[f'day[{w}][on]'] = '1'
        for i, (a, b) in enumerate(shifts):
            data[f'day[{w}][from][{i}]'] = a
            data[f'day[{w}][to][{i}]'] = b
    return submit(doc, page, data)


# ---------- Page ----------
status, html = get(doc, page)
check(status == 200 and clean(html) and 'data-schedule' in html and html.count('class="sc-day') == 7, 'weekly editor with seven days')
check('type="time"' not in html and '>۰۹:۰۰<' in html and '>۲۳:۴۵<' in html, 'times chosen from a Persian list (no English AM/PM picker)')
check('مدت هر نوبت' in html and 'بیمار در هر نوبت' in html and 'حداکثر بیمار در روز' in html and 'تنظیمات بیشتر' in html, 'booking settings in plain words, gap under «more»')
check('مرخصی و تعطیلی' in html and 'name="from_date"' in html and 'فقط چند ساعت از روز' in html, 'closures by date')

# ---------- Saving the week ----------
others = db("SELECT h.weekday,h.opens_at,h.closes_at FROM working_hours h JOIN clinics c ON c.id=h.clinic_id WHERE c.doctor_id=? AND h.clinic_id<>? AND h.active=1", [DOCTOR, C2])
busy = {r['weekday'] for r in others}
free_days = [w for w in [6, 0, 1, 2, 3, 4, 5] if w not in busy]
check(len(free_days) >= 2, 'test doctor has two days free in the other clinics')
d1, d2 = free_days[0], free_days[1]
status, html = week({d1: [('09:00', '13:00'), ('16:00', '20:00')], d2: [('10:00', '12:00')]})
check('برنامه هفتگی ذخیره شد' in html and hours() == sorted([(d1, '09:00', '13:00'), (d1, '16:00', '20:00'), (d2, '10:00', '12:00')]), 'whole week saved at once (two shifts on one day)')
check(scalar("SELECT COUNT(*) FROM appointment_events WHERE event_type='doctor.week'") >= 1 if scalar("SHOW TABLES LIKE 'appointment_events'") else True, 'change recorded in the doctor event log')
day = php(f"$d=date('Y-m-d',strtotime('+1 day'));while((int)date('w',strtotime($d))!=={d1})$d=date('Y-m-d',strtotime($d.' +1 day'));echo $d;")
slots = json.loads(php(f"echo json_encode(array_column(availability({C2},(int)query('SELECT id FROM doctor_services WHERE doctor_id=? AND active=1 ORDER BY id LIMIT 1',[{DOCTOR}])->fetchColumn(),'{day}'),'starts_at'));"))
check(slots and all('09:00' <= s[11:16] < '13:00' or '16:00' <= s[11:16] < '20:00' for s in slots) and any(s[11:16] >= '16:00' for s in slots), 'free times follow the new shifts (morning and evening)')

before = hours()
names = {6: 'شنبه', 0: 'یکشنبه', 1: 'دوشنبه', 2: 'سه‌شنبه', 3: 'چهارشنبه', 4: 'پنجشنبه', 5: 'جمعه'}
status, html = submit(doc, page, {'action': 'week', 'clinic_id': C2, f'day[{d1}][on]': '1', f'day[{d1}][from][0]': '', f'day[{d1}][to][0]': ''})
check(names[d1] in html and 'ساعت حضور ندارد' in html and hours() == before, 'day ticked without hours: clear message, nothing changed')
status, html = week({d1: [('13:00', '09:00')]})
check('ساعت پایان باید بعد از ساعت شروع' in html and hours() == before, 'end before start refused')
status, html = week({d1: [('09:00', '13:00'), ('12:00', '15:00')]})
check('هم‌پوشانی' in html and hours() == before, 'overlapping shifts refused')
status, html = week({d1: [('9:00', '13:00')]})
check('۰۹:۳۰' in html and hours() == before, 'malformed time refused')
if others:
    o = others[0]
    status, html = week({o['weekday']: [(o['opens_at'][:5], o['closes_at'][:5])]})
    check('تداخل' in html and hours() == before, 'clash with another clinic of the doctor refused (nothing saved)')
status, html = submit(doc, page, dict(action='week', clinic_id=C2))
check('همه روزها تعطیل' in html and hours() == [], 'all days off saved')

# ---------- Booking settings ----------
status, html = submit(doc, page, dict(action='clinic_settings', clinic_id=C2, default_duration='20', slot_capacity='1', daily_capacity='40', gap_minutes='0'))
row = db('SELECT default_duration,gap_minutes,daily_capacity,slot_capacity FROM clinics WHERE id=?', [C2])[0]
check((row['default_duration'], row['gap_minutes'], row['daily_capacity'], row['slot_capacity']) == (20, 0, 40, 1), 'booking settings saved')

# ---------- Closures by date ----------
tomorrow = php("echo jdate(date('Y-m-d',strtotime('+1 day')));").strip()
after = php("echo jdate(date('Y-m-d',strtotime('+3 day')));").strip()
iso_t = php("echo date('Y-m-d',strtotime('+1 day'));").strip()
iso_a = php("echo date('Y-m-d',strtotime('+4 day'));").strip()
status, html = submit(doc, page, dict(action='block_days', clinic_id=C2, kind='leave', from_date=tomorrow, to_date=after, reason=''))
b = db('SELECT * FROM schedule_blocks WHERE doctor_id=? ORDER BY id DESC LIMIT 1', [DOCTOR])[0]
check(b['starts_at'] == iso_t + ' 00:00:00' and b['ends_at'] == iso_a + ' 00:00:00' and b['kind'] == 'leave' and b['reason'] == 'مرخصی پزشک' and int(b['clinic_id']) == C2, 'leave for whole days (default reason)')
status, html = submit(doc, page, dict(action='block_days', clinic_id=C2, kind='closed', from_date=tomorrow, partial='1', from_time='10:00', to_time='12:00', reason='جلسه'))
b2 = db('SELECT * FROM schedule_blocks WHERE doctor_id=? ORDER BY id DESC LIMIT 1', [DOCTOR])[0]
check(b2['starts_at'] == iso_t + ' 10:00:00' and b2['ends_at'] == iso_t + ' 12:00:00' and b2['reason'] == 'جلسه', 'closing a few hours of one day')
n = scalar('SELECT COUNT(*) FROM schedule_blocks WHERE doctor_id=?', [DOCTOR])
status, html = submit(doc, page, dict(action='block_days', clinic_id=C2, kind='leave', from_date=after, to_date=tomorrow))
check('نباید قبل' in html and scalar('SELECT COUNT(*) FROM schedule_blocks WHERE doctor_id=?', [DOCTOR]) == n, '«to» before «from» refused')
status, html = get(doc, page)
check('مرخصی پزشک' in html and 'جلسه' in html and 'sc-blocks' in html, 'closures listed with a remove button')
for bid in (b['id'], b2['id']):
    submit(doc, page, dict(action='block_remove', clinic_id=C2, id=bid))
check(scalar('SELECT COUNT(*) FROM schedule_blocks WHERE id IN (?,?) AND active=1', [b['id'], b2['id']]) == 0, 'closures removed')

# Put the original hours back.
restore = {}
for r in original:
    restore.setdefault(r['weekday'], []).append((r['opens_at'][:5], r['closes_at'][:5]))
week(restore) if restore else submit(doc, page, dict(action='week', clinic_id=C2))
check(hours() == sorted((r['weekday'], r['opens_at'][:5], r['closes_at'][:5]) for r in original), 'original hours restored')

# ---------- Services ----------
spage = f'doctor-panel.php?doctor={DOCTOR}&tab=services'
status, html = get(doc, spage)
check('class="svc-rows"' in html and 'datalist id="svc-names"' in html and 'افزودن خدمت' in html and 'مثل تنظیم مطب' in html, 'services: short list, suggestions, add form')
status, html = submit(doc, spage, dict(action='service', name='ویزیت آنلاین آزمون', duration_minutes='15', visit_fee='۲۰۰٬۰۰۰', active='1'))
sv = db('SELECT * FROM doctor_services WHERE doctor_id=? AND name=?', [DOCTOR, 'ویزیت آنلاین آزمون'])[0]
check(sv['duration_minutes'] == 15 and sv['visit_fee_toman'] == 200000 and sv['active'] == 1, 'service added with length and fee')
status, html = submit(doc, spage, dict(action='service', id=sv['id'], name='ویزیت آنلاین آزمون', duration_minutes='15', visit_fee=''))
check(scalar('SELECT active FROM doctor_services WHERE id=?', [sv['id']]) == 0 and scalar('SELECT visit_fee_toman FROM doctor_services WHERE id=?', [sv['id']]) is None, 'service switched off and fee cleared')
status, html = get(doc, spage)
check('svc-row off' in html and 'غیرفعال' in html, 'inactive service marked in the list')

# ---------- Map ----------
status, html = get(doc, f'doctor-panel.php?doctor={DOCTOR}&tab=clinics')
check('data-locate' in html and 'موقعیت فعلی من' in html and 'data-locate-status' in html, 'clinic form: «موقعیت فعلی من» button')
req = urllib.request.Request(BASE + 'index.php')
r = client().open(req)
check(r.headers.get('Referrer-Policy') == 'strict-origin-when-cross-origin', 'site sends only its origin to other sites (map tiles accept it)')
check("referrerPolicy: 'strict-origin-when-cross-origin'" in (ROOT / 'assets' / 'app.js').read_text() and "referrerPolicy: 'strict-origin-when-cross-origin'" in (ROOT / 'assets' / 'search.js').read_text(), 'map tiles request with the origin as referrer')
check('Referrer-Policy "strict-origin-when-cross-origin"' in (ROOT / '.htaccess').read_text(), '.htaccess header matches')
sid = next(x.value for h in doc.handlers if isinstance(h, urllib.request.HTTPCookieProcessor) for x in h.cookiejar if x.name == 'PHPSESSID')
(ROOT.parent.parent / 'runtime' / 'schedule-fixture.json').write_text(json.dumps(dict(doctor=DOCTOR, clinic=C2, session=sid, free=free_days)))
print('SUCCESS', checks, 'rc.8 schedule checks')
