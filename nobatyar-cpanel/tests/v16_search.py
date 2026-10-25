"""1.0.0-rc.4 doctor search over HTTP: one card per doctor, every filter checked against the database
(gender, free time, today, tomorrow, date range, center type, district, specialty, name), sorting (first free
time, rating, reviews, distance, featured, newest), server-side validation, pagination and partial updates,
active-filter chips, map markers, SEO tags, verified reviews (only completed appointments, moderation,
minimum count), badges, card settings, free-time cache invalidation and the doctor page booking box.
Needs the earlier suites (phase4 fixture and the 24-hour clinic from v11). Writes runtime/search-fixture.json."""
from http_helpers import *

fixture = json.loads((ROOT.parent.parent / 'runtime' / 'phase4-fixture.json').read_text())
DOCTOR = fixture['doctor']


def php(code, args=[]):
    return subprocess.check_output([PHP] + (['-c', PHPINI] if PHPINI else []) + ['-r', 'require $argv[1];if(config()["database"]!=="nobatyar_test_http")exit(9);' + code, str(ROOT / 'core.php')] + [str(a) for a in args]).decode()


def scalar(sql, args=[]):
    rows = db(sql, args)
    return next(iter(rows[0].values())) if rows else None


def clean(html):
    return 'Warning:' not in html and 'Fatal error' not in html and 'Notice:' not in html


def admin_login():
    c = client()
    submit(c, 'admin.php', dict(action='login', email='admin@example.test', password='Test-password-012345'))
    return c


def q(params):
    return 'index.php?' + urllib.parse.urlencode(params, doseq=True)


def card_ids(html):
    """Doctor ids of the result cards, in order."""
    out = []
    for block in html.split('data-result-card')[1:]:
        m = re.search(r'doctor\.php\?id=(\d+)&amp;clinic=', block)
        out.append(int(m.group(1)) if m else None)
    return out


def results(params, c=None):
    status, html = get(c or guest, q(dict({'scope': 'all', 'view': 'doctors'}, **params)))
    assert status == 200 and clean(html), (status, params, html[-400:])
    return html


def visible_doctors():
    return {r['id'] for r in db("SELECT DISTINCT d.id FROM doctors d JOIN specialties sp ON sp.id=d.specialty_id JOIN clinics cl ON cl.doctor_id=d.id JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE " + php('echo visible_doctor_sql()," AND ",public_clinic_sql();'))}


def fa(v):
    return str(v).translate(str.maketrans('0123456789', '۰۱۲۳۴۵۶۷۸۹'))


owner = admin_login()
guest = client()
db('DELETE FROM request_limits')
db("UPDATE settings SET value='{}' WHERE `key`='search_config'")
D24 = scalar("SELECT id FROM doctors WHERE name='صف آزمون'")
C24 = scalar('SELECT id FROM clinics WHERE doctor_id=?', [D24])
check(D24 is not None, 'fixture: around-the-clock clinic from the notification suite')
# A few extra approved doctors without schedules, so paging and «no free time» states are real.
spec = scalar('SELECT specialty_id FROM doctors WHERE id=?', [DOCTOR])
city = scalar('SELECT city_id FROM clinics WHERE id=?', [C24])
province = scalar('SELECT province_id FROM cities WHERE id=?', [city])
extra = []
for i in range(6):
    did = int(php("echo register_doctor(['name'=>$argv[2],'mobile'=>$argv[3],'medical_code'=>$argv[4],'specialty_id'=>(int)$argv[5],'bio'=>'','clinic_name'=>'مطب جستجو','province_id'=>(int)$argv[6],'city_id'=>(int)$argv[7],'address'=>'خیابان آزمون','phone'=>''],true);", [f'جستجو {fa(i + 1)}', f'0912880000{i}', f'7788{i}01', spec, province, city]).strip())
    extra.append(did)
db("UPDATE doctors SET review_status='approved',active=1,subscription_until='2099-01-01' WHERE id IN (" + ','.join(map(str, extra)) + ')')
visible = visible_doctors()

# ---------- Page, one card per doctor ----------
html = results({})
ids = card_ids(html)
total = len(visible)
check(f'<b>{fa(total)}</b> پزشک یافت شد' in html, f'count shows every visible doctor ({total})')
check('data-search-toolbar hidden' in html and 'data-sheet="filters"' in html and 'data-sheet="sort"' in html, 'filter / sort / map bar and sheets present (bar shown by script)')
check(len(ids) == len(set(ids)), 'no doctor repeated on a page')
check('data-filter-form' in html and 'name="gender"' in html and 'name="day"' in html and 'name="center[]"' in html, 'filter form with the enabled filters')
check('<meta name="robots"' not in html, 'plain listing is indexable')
check('class="results-pages"' in html or total <= 12, 'page links for visitors without JavaScript')
multi = scalar("SELECT doctor_id FROM clinics WHERE active=1 GROUP BY doctor_id HAVING COUNT(*)>1 LIMIT 1")
if multi and multi in visible:
    check(ids.count(multi) <= 1 and 'مطب دیگر' in html, 'doctor with several clinics: one card, other clinics collapsible')

# ---------- Server-side validation ----------
for bad, label in [({'gender': 'x'}, 'unknown gender ignored'), ({'sort': 'drop'}, 'unknown sort ignored'), ({'specialty[]': ['abc', '-3']}, 'non-numeric specialty ignored'), ({'center[]': ['nope']}, 'unknown center type ignored'), ({'day': 'yesterday'}, 'unknown day ignored'), ({'q': "' OR 1=1 -- <script>"}, 'injection-like text is just text')]:
    h = results(bad)
    check('<script>' not in h.split('</header>')[1] if '</header>' in h else True, label)
h = results({'q': "' OR 1=1 --"})
check('پزشکی یافت نشد' in h, 'quote text matches nothing instead of everything')
h = results({'from': '۱۴۰۰/۰۱/۰۱', 'to': '۱۴۰۰/۰۱/۰۲'})
check('</p>' in h and 'notice' in h, 'past range clamped or reported')
today_j = php("echo jdate(date('Y-m-d'));").strip()
far_j = php("echo jdate(date('Y-m-d',strtotime('+20 days')));").strip()
h = results({'from': today_j, 'to': far_j})
check('حداکثر ۱۴ روز' in h, 'range longer than 14 days rejected with a message')
h = results({'from': far_j, 'to': today_j})
check('پیش از آغاز' in h, 'range ending before it starts rejected')
h = results({'from': 'abc'})
check('تاریخ بازه نامعتبر' in h, 'malformed date rejected')

# ---------- Filters checked against the database ----------
db("UPDATE doctors SET gender=NULL")
before = scalar('SELECT name FROM doctors WHERE id=?', [D24])
row = db('SELECT * FROM doctors WHERE id=?', [D24])[0]
path = f'admin.php?tab=doctors&doctor={D24}'
submit(owner, path, dict(action='edit_doctor', id=D24, name=row['name'], mobile=row['mobile'], medical_code=row['medical_code'], specialty_id=row['specialty_id'], bio=row['bio'] or '', gender='female', badge_text='عضو هیئت علمی'))
check(scalar('SELECT gender FROM doctors WHERE id=?', [D24]) == 'female' and scalar('SELECT badge_text FROM doctors WHERE id=?', [D24]) == 'عضو هیئت علمی', 'admin sets gender and special badge')
check(card_ids(results({'gender': 'female'})) == [D24], 'gender filter: only doctors with that stated gender')
check(card_ids(results({'gender': 'male'})) == [], 'doctors without a stated gender are not guessed')
php('clinic_free_cron(500);')
free = {r['doctor_id'] for r in db('SELECT DISTINCT cl.doctor_id FROM clinic_free_cache f JOIN clinics cl ON cl.id=f.clinic_id WHERE f.first_at IS NOT NULL')} & visible
check(set(card_ids(results({'available': '1'}))) == free, 'available filter equals doctors with a computed free time')
check(D24 in free, 'around-the-clock clinic has a free time')
truth_today = {d for d in visible if any(php(f"echo clinic_day_free({c},date('Y-m-d'))?1:0;") == '1' for c in [r['id'] for r in db('SELECT id FROM clinics WHERE doctor_id=? AND active=1', [d])])}
check(set(card_ids(results({'day': 'today'}))) == truth_today, 'today filter matches a live availability check')
truth_tomorrow = {d for d in visible if any(php(f"echo clinic_day_free({c},date('Y-m-d',strtotime('+1 day')))?1:0;") == '1' for c in [r['id'] for r in db('SELECT id FROM clinics WHERE doctor_id=? AND active=1', [d])])}
check(set(card_ids(results({'day': 'tomorrow'}))) == truth_tomorrow, 'tomorrow filter matches a live availability check')
d3 = php("echo jdate(date('Y-m-d',strtotime('+3 days')));").strip()
d5 = php("echo jdate(date('Y-m-d',strtotime('+5 days')));").strip()
truth_range = {d for d in visible if any(php(f"echo clinic_free_in_range({c},date('Y-m-d',strtotime('+3 days')),date('Y-m-d',strtotime('+5 days')))?1:0;") == '1' for c in [r['id'] for r in db('SELECT id FROM clinics WHERE doctor_id=? AND active=1', [d])])}
h = results({'from': d3, 'to': d5})
check(set(card_ids(h)) == truth_range and 'از ' in h, 'date range filter matches a live check and shows its chip')
order = card_ids(results({'sort': 'first'}))
firsts = [scalar('SELECT MIN(f.first_at) FROM clinic_free_cache f JOIN clinics cl ON cl.id=f.clinic_id WHERE cl.doctor_id=?', [d]) for d in order]
known = [f for f in firsts if f]
check(known == sorted(known) and all(f is None for f in firsts[len(known):]), 'sorted by nearest free time; doctors without one last')
check(card_ids(results({'q': 'صف آزمون'})) == [D24], 'name filter')
two = db('SELECT id FROM specialties WHERE active=1 ORDER BY id LIMIT 2')
h = results({'specialty[]': [two[0]['id'], two[1]['id']]})
truth = {r['id'] for r in db('SELECT d.id FROM doctors d JOIN specialties sp ON sp.id=d.specialty_id WHERE sp.id IN (%s,%s) OR sp.parent_id IN (%s,%s)' % (two[0]['id'], two[1]['id'], two[0]['id'], two[1]['id']))} & visible
check(set(card_ids(h)) == truth and '<meta name="robots" content="noindex,follow">' in h, 'several specialties at once; narrowed view not indexed')
# District through the clinic form (admin), then the filter list for that city.
cl = db('SELECT * FROM clinics WHERE id=?', [C24])[0]
status, page = submit(owner, path, dict(action='clinic', id=C24, doctor_id=D24, clinic_name=cl['name'], province_id=province, city_id=city, address=cl['address'], phone=cl['phone'] or '', active='1', is_primary='1', district='  محله   آزمون ', latitude='27.1865', longitude='56.2808'))
check(scalar('SELECT district FROM clinics WHERE id=?', [C24]) == 'محله آزمون', 'clinic district saved (spaces normalized)')
vis = client()
get(vis, f'city.php?select={city}')
h = get(vis, 'index.php?view=doctors')[1]
check('name="district[]" value="محله آزمون"' in h, 'district list of the chosen city in the filters')
check(card_ids(get(vis, 'index.php?' + urllib.parse.urlencode({'view': 'doctors', 'district[]': 'محله آزمون'}))[1]) == [D24], 'district filter')
center_truth = {r['doctor_id'] for r in db("SELECT DISTINCT cl.doctor_id FROM clinics cl JOIN centers ce ON ce.id=cl.center_id WHERE ce.kind IN ('clinic','dental')")} & visible
check(set(card_ids(results({'center[]': 'clinic'}))) == center_truth, 'center type filter (clinics)')

# ---------- Chips, partial updates, paging ----------
h = results({'gender': 'female', 'day': 'today'})
chips = h.split('class="active-chips"')[1].split('</ul>')[0]
check('پزشک خانم' in chips and ('نوبت امروز' in chips) and 'پاک کردن همه' in chips, 'active filters shown as chips with «clear all»')
gchip = re.search(r'href="([^"]+)"[^>]*aria-label="حذف فیلتر پزشک خانم"', chips).group(1).replace('&amp;', '&')
check('gender' not in gchip and 'day=today' in gchip, 'removing one chip keeps the other filters')
status, part = get(guest, q({'scope': 'all', 'view': 'doctors', 'partial': '1'}))
check(status == 200 and '<html' not in part and 'data-filter-template' in part and 'data-active-count="0"' in part, 'partial update returns results and a fresh filter form only')
submit(owner, 'admin.php?tab=search', dict(action='search_settings', per_page='6', default_sort='first', horizon='30', cache_minutes='5', reviews_min='3', reviews='1', reviews_moderation='1', show_first='1', show_bio='1', show_address='1', show_code='1', show_done='1', map='1', **{f'filter[{k}]': '1' for k in ['location', 'specialty', 'name', 'gender', 'available', 'day', 'range', 'center', 'district', 'local']}, **{f'badge[{k}]': '1' for k in ['verified', 'featured', 'today', 'tomorrow', 'multi', 'custom']}))
check(json.loads(scalar("SELECT value FROM settings WHERE `key`='search_config'"))['per_page'] == 6, 'admin sets results per page')
p1 = results({})
check(len(card_ids(p1)) == 6 and 'data-load-more' in p1, 'first page limited, «show more» offered')
status, more = get(guest, q({'scope': 'all', 'view': 'doctors', 'page': '2', 'partial': 'more'}))
ids2 = card_ids(more)
check(status == 200 and ids2 and not set(ids2) & set(card_ids(p1)) and 'results-meta' not in more, 'next page appends only new cards')
allp = card_ids(p1) + ids2
if len(visible) > 12:
    allp += card_ids(get(guest, q({'scope': 'all', 'view': 'doctors', 'page': '3', 'partial': 'more'}))[1])
check(set(allp) == visible, 'pages together list every visible doctor exactly once')
check(card_ids(results({'page': '999'})) == card_ids(results({'page': str((len(visible) + 5) // 6)})), 'page past the end shows the last page')

# ---------- Visibility rules ----------
db("UPDATE doctors SET review_status='suspended' WHERE id=?", [D24])
check(D24 not in card_ids(results({'q': 'صف'})), 'suspended doctor never listed')
db("UPDATE doctors SET review_status='approved',subscription_until=DATE_SUB(CURDATE(),INTERVAL 30 DAY) WHERE id=?", [D24])
check(D24 in card_ids(results({'q': 'صف'})), 'doctor without a valid subscription still listed (free plan, rc.20)')
db("UPDATE doctors SET subscription_until='2099-01-01' WHERE id=?", [D24])

# ---------- Map ----------
status, r = api(guest, 'search/map&scope=all')
markers = r['data']
check(status == 200 and any(m['doctor'] == before for m in markers), 'map marker for the clinic with a position')
check(all(set(m) == {'lat', 'lng', 'doctor', 'specialty', 'clinic', 'first', 'url'} for m in markers), 'markers carry no phone, mobile or other private data')
check(all(not m['url'].startswith('http') for m in markers), 'marker links stay on the site')
status, r = api(guest, 'search/map&scope=all&gender=male')
check(r['data'] == [], 'map follows the filters')

# ---------- Distance ----------
h = results({'sort': 'distance'})
check('اجازه دسترسی به موقعیت' in h, 'distance sort without a position explains and falls back')
h = results({'sort': 'distance', 'lat': '27.19', 'lng': '56.28'})
check(card_ids(h)[0] == D24, 'nearest clinic first when the visitor shares a position')
h = results({'sort': 'distance', 'lat': '51.5', 'lng': '-0.1'})
check('اجازه دسترسی' in h, 'positions outside Iran are ignored')

# ---------- Reviews: only for completed appointments ----------
pa = client()
pa_id = login_user(pa, '09121110010')
bk = scalar("SELECT b.id FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE b.patient_user_id=? AND s.doctor_id=? ORDER BY b.id LIMIT 1", [pa_id, D24])
check(bk is not None, 'fixture: patient has an appointment with the doctor')
status, page = get(pa, 'patient.php?tab=reviews')
check('نوبت انجام‌شده‌ای که منتظر نظر شما باشد وجود ندارد' in page, 'no review form before the visit is completed')
status, page = post(pa, 'patient.php?tab=reviews', dict(action='review', csrf=token(pa, 'patient.php?tab=reviews'), booking_id=bk, rating='5', recommend='1'))
check('فقط برای نوبت انجام‌شده' in page, 'review for an unfinished appointment refused')
db("UPDATE bookings SET status='completed' WHERE id=?", [bk])
status, page = get(pa, 'patient.php?tab=reviews')
check(f'name="booking_id" value="{bk}"' in page, 'completed appointment can be reviewed')
status, page = post(pa, 'patient.php?tab=reviews', dict(action='review', csrf=token(pa, 'patient.php?tab=reviews'), booking_id=bk, rating='9', recommend='1'))
check('یک تا پنج' in page, 'rating outside 1–5 refused')
status, page = post(pa, 'patient.php?tab=reviews', dict(action='review', csrf=token(pa, 'patient.php?tab=reviews'), booking_id=bk, rating='5', recommend='1', comment='برخورد <b>عالی</b> و دقیق'))
check('پس از بررسی' in page and scalar('SELECT status FROM reviews WHERE booking_id=?', [bk]) == 'pending', 'review saved for moderation')
status, page = post(pa, 'patient.php?tab=reviews', dict(action='review', csrf=token(pa, 'patient.php?tab=reviews'), booking_id=bk, rating='1', recommend='0'))
check(scalar('SELECT COUNT(*) FROM reviews WHERE booking_id=?', [bk]) == 1, 'one review per appointment')
other = client()
login_user(other, '09121110011')
post(other, 'patient.php?tab=reviews', dict(action='review', csrf=token(other, 'patient.php?tab=reviews'), booking_id=bk, rating='1', recommend='0'))
check(scalar('SELECT COUNT(*) FROM reviews WHERE booking_id=?', [bk]) == 1, "another patient cannot review someone else's appointment")
check('هنوز امتیازی ثبت نشده' in results({'q': 'صف آزمون'}), 'pending review not shown')
status, page = get(owner, 'admin.php?tab=reviews')
rid = scalar('SELECT id FROM reviews WHERE booking_id=?', [bk])
check(status == 200 and clean(page) and 'برخورد &lt;b&gt;عالی&lt;/b&gt;' in page, 'admin sees the pending review (escaped)')
submit(owner, 'admin.php?tab=reviews', dict(action='review_moderate', id=rid, status='approved'))
check(scalar('SELECT status FROM reviews WHERE id=?', [rid]) == 'approved', 'admin approves')
check('هنوز امتیازی ثبت نشده' in results({'q': 'صف آزمون'}), 'rating stays hidden below the minimum number of reviews')
cfg = json.loads(scalar("SELECT value FROM settings WHERE `key`='search_config'"))
cfg['reviews_min'] = 1
db("UPDATE settings SET value=? WHERE `key`='search_config'", [json.dumps(cfg)])
h = results({'q': 'صف آزمون'})
check('rc-stars' in h and '<b>۵٫۰</b>' in h.replace('۵.۰', '۵٫۰') or '<b>۵.۰</b>' in h, 'approved rating shown once the minimum is reached')
check('(۱ نظر)' in h and '۱۰۰٪ پیشنهاد بیماران' in h, 'review count and recommendation share from real reviews')
done = scalar("SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE b.status='completed' AND s.doctor_id=?", [D24])
check(f'{fa(done)} نوبت انجام‌شده' in h, 'completed appointments count from real bookings')
check(card_ids(results({'sort': 'rating'}))[0] == D24 and card_ids(results({'sort': 'reviews'}))[0] == D24, 'rating and review-count sorting')
status, page = get(guest, f'doctor.php?id={D24}')
check('id="reviews"' in page and 'برخورد &lt;b&gt;عالی&lt;/b&gt; و دقیق' in page and '09121110010' not in page, 'doctor page shows the approved review escaped, without the mobile')
status, page = get(owner, 'admin.php?tab=reviews&status=approved')
submit(owner, 'admin.php?tab=reviews&status=approved', dict(action='review_moderate', id=rid, status='hidden'))
check('هنوز امتیازی ثبت نشده' in results({'q': 'صف آزمون'}), 'hidden review removed from the rating')

# ---------- Badges and card settings ----------
h = results({'q': 'صف آزمون'})
check('b-verified' in h and 'عضو هیئت علمی' in h, 'verified and admin badge on the card')
check(('b-today' in h) == (D24 in truth_today) and (('b-tomorrow' in h) or D24 in truth_today or D24 not in truth_tomorrow), 'today / tomorrow badge only with real free time')
fav = scalar('SELECT id FROM doctors WHERE featured=1 LIMIT 1')
if fav and fav in visible:
    check('b-featured' in results({'sort': 'featured'}).split('data-result-card')[1] and card_ids(results({'sort': 'featured'}))[0] == fav, 'featured badge and featured sorting')
cfg['show_code'] = False
cfg['badges']['custom'] = False
cfg['filters']['gender'] = False
db("UPDATE settings SET value=? WHERE `key`='search_config'", [json.dumps(cfg)])
h = results({'q': 'صف آزمون', 'gender': 'male'})
check('<dt>شماره نظام پزشکی' not in h and 'عضو هیئت علمی' not in h, 'card settings hide medical code and special badge')
check('name="gender"' not in h and D24 in card_ids(h), 'disabled filter hidden and its value ignored')
db("UPDATE settings SET value='{}' WHERE `key`='search_config'")

# ---------- Free time cache follows appointments ----------
php(f'clinic_free_refresh({C24});')
check(scalar('SELECT computed_at FROM clinic_free_cache WHERE clinic_id=?', [C24]) > 0, 'cache stored for the clinic')
php(f"event_record({D24},'schedule.hours',[]);")
check(scalar('SELECT computed_at FROM clinic_free_cache WHERE clinic_id=?', [C24]) == 0, 'any appointment or schedule event marks the clinic for recomputation')
results({'q': 'صف آزمون'})
check(scalar('SELECT computed_at FROM clinic_free_cache WHERE clinic_id=?', [C24]) > 0, 'recomputed on the next search')
status, page = get(owner, 'admin.php?tab=cron')
check('search_cache' in page or 'اولین نوبت آزاد' in page, 'cron task keeps first free times fresh')

# ---------- SEO ----------
sname = scalar('SELECT name FROM specialties WHERE id=?', [spec])
h = get(client(), f'index.php?specialty_id={spec}&province=0&city=0')[1]
check(f'<title>نوبت پزشک {sname}' in h and 'rel="canonical"' in h and '<meta name="robots"' not in h, 'specialty listing: title, canonical, indexable')
h = get(client(), f'index.php?specialty_id={spec}&province=0&city=0&available=1')[1]
check('noindex,follow' in h, 'filtered listing not indexed')

# ---------- Doctor page booking box and admin pages ----------
status, page = get(guest, f'doctor.php?id={D24}&clinic={C24}')
check('class="book-card"' in page and 'class="bc-fee"' in page and ('کارمزد رزرو' in page or 'رایگان' in page) and 'هزینه ویزیت فقط در مطب' in page, 'doctor page: booking card with the fee stated')
check('data-back-results hidden' in page, 'back-to-results link (shown by script when coming from results)')
status, page = get(owner, 'admin.php?tab=search')
check(status == 200 and clean(page) and 'فیلترها' in page and 'کارت پزشک' in page and 'امتیاز و نظرات' in page, 'admin search settings page')
submit(owner, 'admin.php?tab=search', dict(action='search_cache'))
check(scalar('SELECT COUNT(*) FROM audit_log WHERE action=?', ['search.cache']) >= 1, 'manual recompute logged')

db("UPDATE settings SET value=? WHERE `key`='search_config'", [json.dumps({'per_page': 6})])
(ROOT.parent.parent / 'runtime' / 'search-fixture.json').write_text(json.dumps(dict(d24=D24, c24=C24, city=city, total=len(visible), spec=spec)))
print(f'SUCCESS {checks} search checks')
