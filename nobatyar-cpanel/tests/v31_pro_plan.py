"""1.0.0-rc.20 over HTTP: free plan + Pro. Default feature catalog; registration with the two-column plan
picker; free doctor listed and bookable without a subscription, with free limits (1 clinic, 1 service, no
secretary) enforced on the server and soft locks in the panel; Pro (trial on approval for doctors who picked
Pro) with blue tick, search boost, unlimited clinics/services, secretaries, daily Excel, report and profile
views, QR page, announcement, end-of-day SMS; Pro expiry keeps data and locks extras without deleting them;
renewal unlocks; admin catalog page; subscription sales off = everything for everyone.
Restores the legacy catalog used by the older suites at the end. Run after v12_http.py."""
from http_helpers import *

afx = json.loads((ROOT.parent.parent / 'runtime' / 'admin-fixture.json').read_text())
cfg_path = ROOT / 'config.php'
cfg_before = cfg_path.read_text()
legacy_catalog = db("SELECT value FROM settings WHERE `key`='pro_features'")
saved = {r['key']: r['value'] for r in db("SELECT `key`,value FROM settings WHERE `key` IN ('subscription_trial_enabled','subscription_trial_days','subscriptions_enabled','daily_summary_hour','sms_dev_mode')")}
city = db('SELECT c.id,c.province_id FROM cities c JOIN provinces p ON p.id=c.province_id WHERE c.active=1 AND p.active=1 ORDER BY c.id LIMIT 1')[0]
spec = db('SELECT id FROM specialties WHERE active=1 AND deleted_at IS NULL ORDER BY id LIMIT 1')
MOB = {'pro': '09127700801', 'free': '09127700802', 'sec': '09127700803', 'guest': '09127700804'}


def setting(k, v):
    db('INSERT INTO settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [k, v])


def php(code):
    return subprocess.check_output([PHP] + (['-c', PHPINI] if PHPINI else []) + ['-r', 'require $argv[1];require_once dirname($argv[1])."/lib/jobs.php";' + code, str(ROOT / 'core.php')]).decode()


def with_session(sid):
    c = client()
    jar = next(h.cookiejar for h in c.handlers if isinstance(h, urllib.request.HTTPCookieProcessor))
    jar.set_cookie(http.cookiejar.Cookie(0, 'PHPSESSID', sid, None, False, '127.0.0.1', False, False, '/', True, False, None, False, None, None, {}))
    return c


def name_h1(html):
    m = re.search(r'<h1 id="dp-name">.*?</h1>', html, re.S)
    return m.group(0) if m else ''


def raw(c, path):
    try:
        r = c.open(BASE + path)
    except urllib.error.HTTPError as e:
        r = e
    return r.status, r.headers, r.read()


for m in MOB.values():
    db('DELETE b FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN doctors d ON d.id=s.doctor_id WHERE d.mobile=?', [m])
    db('DELETE s FROM slots s JOIN doctors d ON d.id=s.doctor_id WHERE d.mobile=?', [m])
    db('DELETE v FROM doctor_profile_views v JOIN doctors d ON d.id=v.doctor_id WHERE d.mobile=?', [m])
    db('DELETE sc FROM staff_scopes sc JOIN clinics c ON c.id=sc.clinic_id JOIN doctors d ON d.id=c.doctor_id WHERE d.mobile=?', [m])
    for t in ['doctor_accounts a JOIN doctors d ON d.id=a.doctor_id', 'clinics a JOIN doctors d ON d.id=a.doctor_id', 'doctor_services a JOIN doctors d ON d.id=a.doctor_id', 'appointment_events a JOIN doctors d ON d.id=a.doctor_id', 'subscriptions a JOIN doctors d ON d.id=a.doctor_id']:
        db(f'DELETE a FROM {t} WHERE d.mobile=?', [m])
    db('DELETE FROM doctors WHERE mobile=?', [m])
for m in MOB.values():
    db('DELETE n FROM notifications n JOIN users u ON u.id=n.user_id WHERE u.mobile=?', [m])
    db('DELETE s FROM staff_scopes s JOIN users u ON u.id=s.user_id WHERE u.mobile=?', [m])
    db("DELETE FROM sms_messages WHERE event='doctor_daily_summary' AND mobile=?", [m])
db("DELETE b FROM bookings b WHERE b.tracking_code LIKE '88000000%'")
db('DELETE FROM request_limits')
db("DELETE FROM settings WHERE `key`='pro_features'")  # default catalog
setting('subscriptions_enabled', '1')
setting('subscription_trial_enabled', '1')
setting('subscription_trial_days', '14')
admin = with_session(afx['owner'])

try:
    # ---------- Registration: two-column plan picker ----------
    clients = {}
    ids = {}
    for kind in ('pro', 'free'):
        c = client()
        login_user(c, MOB[kind])
        clients[kind] = c
        html = get(c, 'register.php')[1]
        if kind == 'pro':
            check(html.count('class="plan-col') == 2 and 'name="plan_choice" value="free"' in html and 'name="plan_choice" value="pro"' in html, 'registration: Free and Pro side by side')
            check('تیک آبی کنار نام پزشک' in html and 'دریافت اکسل نوبت‌های روز' in html and 'بدون منشی' in html and '۱ مطب' in html, 'plan columns built from the catalog (Pro items, free limits)')
        data = dict(csrf=token(c, 'register.php'), plan_choice=kind, name=f'{kind} آزمونپلن', medical_code=str(int(MOB[kind][-6:]) + 700000),
                    specialty_id=spec[0]['id'] if spec else '', specialty_other='' if spec else 'عمومی', gender='', bio='', clinic_name=f'مطب {kind}',
                    province_id=city['province_id'], city_id=city['id'], district='', address='خیابان آزمون پلاک ۱۲', phone='', latitude='', longitude='',
                    notes='', website='', consent='1')
        html = post(c, 'register.php', data)[1]
        d = db('SELECT id,plan_choice FROM doctors WHERE mobile=?', [MOB[kind]])[0]
        ids[kind] = d['id']
        check(d['plan_choice'] == kind, f'plan choice saved ({kind})')
    PRO, FREE = ids['pro'], ids['free']
    for kind, did in ids.items():
        submit(admin, f'admin.php?tab=doctors&doctor={did}', dict(action='review', id=did, status='approved', active='1', review_note=''))
    check(db('SELECT subscription_until FROM doctors WHERE id=?', [PRO])[0]['subscription_until'] is not None, 'doctor who picked Pro gets the trial on approval')
    check(db('SELECT subscription_until FROM doctors WHERE id=?', [FREE])[0]['subscription_until'] is None, 'free doctor: no subscription')
    check(db("SELECT COUNT(*) n FROM notifications n JOIN doctor_accounts a ON a.user_id=n.user_id WHERE a.doctor_id=? AND n.title LIKE '%آزمایشی پرو%'", [PRO])[0]['n'] == 1, 'trial notice sent to the doctor')
    pro, free = clients['pro'], clients['free']

    # ---------- Free doctor: listed, limits on the server, soft locks ----------
    status, html = get(client(), f'doctor.php?id={FREE}')
    check(status == 200 and name_h1(html) and 'pro-tick' not in name_h1(html), 'free doctor listed without a subscription; no blue tick')
    path = f'doctor-panel.php?doctor={FREE}&tab=services'
    html = submit(free, path, dict(action='service', name='ویزیت عمومی', duration_minutes=0, active='1'))[1]
    check(db('SELECT COUNT(*) n FROM doctor_services WHERE doctor_id=?', [FREE])[0]['n'] == 1, 'free: first service added')
    html = get(free, path)[1]
    check('pro-upsell' in html and 'name="name" value=""' not in html.split('افزودن خدمت</h2>')[1][:800], 'free: «add service» replaced by the Pro card at the limit')
    html = submit(free, path, dict(action='service', name='مشاوره', duration_minutes=0, active='1'))[1]
    check('نسخه رایگان' in html and db('SELECT COUNT(*) n FROM doctor_services WHERE doctor_id=?', [FREE])[0]['n'] == 1, 'free: second service refused on the server')
    html = submit(free, f'doctor-panel.php?doctor={FREE}&tab=clinics', dict(action='clinic', clinic_name='مطب دوم', province_id=city['province_id'], city_id=city['id'], address='نشانی دوم', phone='', active='1'))[1]
    check(db('SELECT COUNT(*) n FROM clinics WHERE doctor_id=?', [FREE])[0]['n'] == 1, 'free: second clinic refused')
    sec = client()
    login_user(sec, MOB['sec'])
    fclinic = db('SELECT id FROM clinics WHERE doctor_id=?', [FREE])[0]['id']
    html = get(free, f'doctor-panel.php?doctor={FREE}&tab=staff')[1]
    check('pro-upsell' in html and 'name="mobile"' not in html.split('doctor-content')[1], 'free: secretary form replaced by the Pro card')
    html = submit(free, f'doctor-panel.php?doctor={FREE}&tab=profile', dict(action='staff', clinic_id=fclinic, mobile=MOB['sec']))[1]
    check(not db("SELECT 1 FROM staff_scopes WHERE clinic_id=? AND role='secretary'", [fclinic]), 'free: secretary refused on the server')
    html = get(free, f'doctor-panel.php?doctor={FREE}')[1]
    check('pro-lock' in html and 'export=day' not in html and 'plan-chip is-free' in html, 'free dashboard: Excel locked, welcome box shows «رایگان»')
    status, h, body = raw(free, f'doctor-panel.php?doctor={FREE}&export=day')
    check(status == 403 and 'ویژه اشتراک پرو' in body.decode(), 'free: daily Excel refused on the server')
    for tab, word in (('report', 'گزارش عملکرد و آمار بازدید پروفایل'), ('promote', 'لینک اختصاصی، QR و پوستر')):
        html = get(free, f'doctor-panel.php?doctor={FREE}&tab={tab}')[1]
        check('pro-upsell' in html and word in html and 'data-qr' not in html, f'free: {tab} shows the Pro card')
    html = submit(free, f'doctor-panel.php?doctor={FREE}&tab=promote', dict(action='notice_save', notice_text='مطب پنجشنبه تعطیل است'))[1]
    check(db('SELECT notice_text FROM doctors WHERE id=?', [FREE])[0]['notice_text'] is None, 'free: announcement refused')
    html = get(free, f'doctor-panel.php?doctor={FREE}&tab=subscription')[1]
    check(html.count('class="plan-col') == 2 and 'نسخه فعلی شما' in html, 'Pro page: comparison with the current plan marked')

    # ---------- Pro doctor (trial) ----------
    status, html = get(client(), f'doctor.php?id={PRO}')
    check('pro-tick' in name_h1(html), 'Pro: blue tick next to the name')
    path = f'doctor-panel.php?doctor={PRO}&tab=services'
    for name in ('ویزیت عمومی', 'مشاوره', 'نوار قلب'):
        submit(pro, path, dict(action='service', name=name, duration_minutes=0, active='1'))
    check(db('SELECT COUNT(*) n FROM doctor_services WHERE doctor_id=?', [PRO])[0]['n'] == 3, 'Pro: unlimited services')
    submit(pro, f'doctor-panel.php?doctor={PRO}&tab=clinics', dict(action='clinic', clinic_name='مطب دوم پرو', province_id=city['province_id'], city_id=city['id'], address='نشانی دوم', phone='', active='1'))
    check(db('SELECT COUNT(*) n FROM clinics WHERE doctor_id=?', [PRO])[0]['n'] == 2, 'Pro: second clinic')
    pclinic = db('SELECT id FROM clinics WHERE doctor_id=? AND is_primary=1', [PRO])[0]['id']
    submit(pro, f'doctor-panel.php?doctor={PRO}&tab=staff', dict(action='staff', clinic_id=pclinic, mobile=MOB['sec']))
    check(db("SELECT 1 FROM staff_scopes WHERE clinic_id=? AND role='secretary'", [pclinic]), 'Pro: secretary added')
    status, html = get(sec, f'secretary.php?clinic={pclinic}')
    check(status == 200, 'Pro: secretary works')
    status, h, body = raw(pro, f'doctor-panel.php?doctor={PRO}&export=day')
    check(status == 200 and 'spreadsheetml' in h.get('Content-Type', ''), 'Pro: daily Excel')
    for _ in range(2):
        get(client(), f'doctor.php?id={PRO}')
    html = get(pro, f'doctor-panel.php?doctor={PRO}&tab=report')[1]
    check('بازدید پروفایل' in html and 'pro-upsell' not in html and db('SELECT views FROM doctor_profile_views WHERE doctor_id=? AND day=CURDATE()', [PRO])[0]['views'] >= 3, 'Pro: report with profile views (one per visitor and day)')
    html = get(pro, f'doctor-panel.php?doctor={PRO}&tab=promote')[1]
    check('data-qr="' in html and 'doctor' in html.split('data-qr="')[1][:200], 'Pro: booking link and QR')
    html = submit(pro, f'doctor-panel.php?doctor={PRO}&tab=promote', dict(action='notice_save', notice_text='مطب پنجشنبه تعطیل است', notice_until=''))[1]
    check('مطب پنجشنبه تعطیل است' in get(client(), f'doctor.php?id={PRO}')[1], 'Pro: announcement on the doctor page')

    # Search: Pro first among doctors on the same availability level.
    status, html = get(client(), 'index.php?' + urllib.parse.urlencode({'scope': 'all', 'view': 'doctors', 'q': 'آزمونپلن'}))
    order = [int(m) for m in re.findall(r'doctor\.php\?id=(\d+)&amp;clinic=', html)]
    order = [i for i in dict.fromkeys(order) if i in (PRO, FREE)]
    check(order[:2] == [PRO, FREE], 'search: Pro ranked above free on the same level')

    # End-of-day SMS: Pro with something to report, once a day; free never.
    tomorrow = php('echo date("Y-m-d",strtotime("+1 day"));')
    for did, cl in ((PRO, pclinic), (FREE, fclinic)):
        db("INSERT INTO slots(doctor_id,clinic_id,starts_at,ends_at,service_name,source,capacity) VALUES(?,?,?,?,'ویزیت','online',1)", [did, cl, tomorrow + ' 09:30:00', tomorrow + ' 09:45:00'])
        sid = db('SELECT MAX(id) id FROM slots WHERE doctor_id=?', [did])[0]['id']
        db("INSERT INTO bookings(slot_id,patient_name,phone,tracking_code,status,confirmed_at) VALUES(?,'بیمار آزمون','09127700899',?,'confirmed',NOW())", [sid, str(880000000000 + did)])
    setting('daily_summary_hour', '0')
    setting('sms_dev_mode', '1')
    cfg_path.write_text(cfg_before.replace("'environment' => 'production'", "'environment' => 'development'"))  # SMS test mode: queued, never sent
    db("DELETE FROM notice_markers WHERE dedupe_key LIKE 'daily-summary:%'")
    out = php('echo cron_daily_summary();')
    pm = db('SELECT mobile FROM doctors WHERE id=?', [PRO])[0]['mobile']
    msgs = db("SELECT tokens FROM sms_messages WHERE event='doctor_daily_summary' AND mobile=?", [pm])
    check(len(msgs) == 1 and '۰۹:۳۰' in msgs[0]['tokens'] and not db("SELECT 1 FROM sms_messages WHERE event='doctor_daily_summary' AND mobile=?", [MOB['free']]), 'end-of-day SMS: Pro doctor with tomorrow\'s bookings, free doctor not')
    php('echo cron_daily_summary();')
    check(len(db("SELECT 1 FROM sms_messages WHERE event='doctor_daily_summary' AND mobile=?", [pm])) == 1, 'end-of-day SMS: once a day')
    submit(pro, f'doctor-panel.php?doctor={PRO}&tab=subscription', dict(action='pro_prefs'))
    check(db('SELECT daily_summary_off FROM doctors WHERE id=?', [PRO])[0]['daily_summary_off'] == 1, 'doctor can turn the end-of-day SMS off')
    cfg_path.write_text(cfg_before)

    # ---------- Pro ends: data kept, extras locked, no deletion ----------
    db('UPDATE doctors SET subscription_until=DATE_SUB(CURDATE(),INTERVAL 30 DAY) WHERE id=?', [PRO])
    php(f'entitlement_sync({PRO});')
    locked_c = db('SELECT COUNT(*) n FROM clinics WHERE doctor_id=? AND plan_locked=1', [PRO])[0]['n']
    locked_s = db('SELECT COUNT(*) n FROM doctor_services WHERE doctor_id=? AND plan_locked=1', [PRO])[0]['n']
    check(locked_c == 1 and locked_s == 2 and db('SELECT COUNT(*) n FROM clinics WHERE doctor_id=?', [PRO])[0]['n'] == 2, 'Pro expired: second clinic and extra services kept but locked')
    status, html = get(client(), f'doctor.php?id={PRO}')
    check(status == 200 and 'pro-tick' not in name_h1(html) and 'مطب دوم پرو' not in html and 'مطب پنجشنبه تعطیل است' not in html, 'Pro expired: listed on the free plan, no tick, locked clinic and announcement hidden')
    check(db("SELECT status FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.doctor_id=? ", [PRO])[0]['status'] == 'confirmed', 'confirmed bookings untouched')
    status, html = get(sec, f'secretary.php?clinic={pclinic}')
    check('اشتراک پرو پزشک' in html, 'Pro expired: secretary access paused with a clear message')
    html = get(pro, f'doctor-panel.php?doctor={PRO}&tab=clinics')[1]
    check('بدون رزرو جدید تا فعال‌سازی پرو' in html, 'panel marks the locked clinic')
    db('UPDATE doctors SET subscription_until=DATE_ADD(CURDATE(),INTERVAL 30 DAY) WHERE id=?', [PRO])
    php(f'entitlement_sync({PRO});')
    check(db('SELECT COUNT(*) n FROM clinics WHERE doctor_id=? AND plan_locked=1', [PRO])[0]['n'] == 0 and db('SELECT COUNT(*) n FROM doctor_services WHERE doctor_id=? AND plan_locked=1', [PRO])[0]['n'] == 0, 'renewal unlocks everything')
    check(get(sec, f'secretary.php?clinic={pclinic}')[0] == 200, 'renewal: secretary works again')

    # ---------- Admin catalog ----------
    html = get(admin, 'admin.php?tab=subscriptions&view=pro')[1]
    check('امکانات نسخه پرو پزشکان' in html and 'name="pro[day_export]"' in html and 'name="limit[clinics]"' in html and 'همیشه رایگان' in html and 'name="daily_summary_hour"' in html, 'admin: feature checklist, free limits, SMS hour')
    form = {'action': 'pro_catalog', 'daily_summary_hour': '21', 'limit[clinics]': '1', 'limit[services]': '1', 'limit[secretaries]': '0'}
    for k in ['clinics', 'services', 'secretaries', 'verified_badge', 'search_boost', 'daily_summary_sms', 'patient_reminder_sms', 'monthly_report', 'waitlist', 'doctor_notice', 'booking_qr']:
        form[f'pro[{k}]'] = '1'  # day_export left unticked → free for everyone
    html = submit(admin, 'admin.php?tab=subscriptions&view=pro', form)[1]
    check('امکانات پرو ذخیره شد' in html, 'admin: catalog saved')
    status, h, body = raw(free, f'doctor-panel.php?doctor={FREE}&export=day')
    check(status == 200, 'unticked feature becomes free for everyone')
    html = submit(admin, 'admin.php?tab=subscriptions&view=pro', dict(form, **{'limit[clinics]': '0'}))[1]
    check('دست‌کم یک مطب' in html, 'free plan needs at least one clinic and one service')
    st, html = submit(admin, 'admin.php?tab=subscriptions&view=plans', dict(action='plan_save', name='پرو ماهانه آزمون', duration_days='30', price_toman='۲۹۰٬۰۰۰', features='', max_clinics='', max_secretaries='', max_services='۵', active='1', sort_order='1', audience='doctor'))
    check(db("SELECT max_services FROM subscription_plans WHERE name='پرو ماهانه آزمون'")[0]['max_services'] == 5, 'plan: services limit saved')
    login_html = get(clients['free'], f'doctor-panel.php?doctor={FREE}&tab=subscription')[1]
    check('۲۹۰٬۰۰۰' in login_html or '290' in login_html or 'پرو ماهانه آزمون' in login_html, 'Pro page lists the plan with its price')

    # ---------- Subscription sales off: everything for everyone ----------
    setting('subscriptions_enabled', '0')
    submit(free, path.replace(str(PRO), str(FREE)), dict(action='service', name='مشاوره', duration_minutes=0, active='1'))
    check(db('SELECT COUNT(*) n FROM doctor_services WHERE doctor_id=?', [FREE])[0]['n'] == 2, 'sales off: no limits')
    check('pro-upsell' not in get(free, f'doctor-panel.php?doctor={FREE}&tab=report')[1], 'sales off: Pro features open to every doctor')
    check('class="plan-col' not in get(client(), 'register.php')[1], 'sales off: no plan picker at registration')
    setting('subscriptions_enabled', '1')
    db("DELETE FROM settings WHERE `key`='pro_features'")
    php('entitlement_sync_all();')
    # Fixture for tests/browser_pro.cjs: a signed-in user without a doctor profile, and the free doctor.
    g = client()
    login_user(g, MOB['guest'])
    # A clinic inside a medical center's branch belongs to the center and never counts toward, or gets locked
    # by, the free doctor's own plan (same rule as adding a clinic).
    center = db("SELECT id FROM centers ORDER BY id LIMIT 1")
    if center:
        own = db('SELECT city_id FROM clinics WHERE doctor_id=? ORDER BY id LIMIT 1', [FREE])[0]
        db("INSERT INTO clinics(doctor_id,name,city_id,address,phone,is_primary,center_id,active) VALUES(?,'شعبه مرکز rc20',?,'نشانی',' ',0,?,1)", [FREE, own['city_id'], center[0]['id']])
        cc = db("SELECT id FROM clinics WHERE doctor_id=? AND name='شعبه مرکز rc20'", [FREE])[0]['id']
        php(f'entitlement_sync({FREE});')
        check(db('SELECT plan_locked FROM clinics WHERE id=?', [cc])[0]['plan_locked'] == 0, 'center branch clinic is not locked by the free plan')
        db('DELETE FROM clinics WHERE id=?', [cc])
    sid = lambda c: next(x.value for h in c.handlers if isinstance(h, urllib.request.HTTPCookieProcessor) for x in h.cookiejar if x.name == 'PHPSESSID')
    (ROOT.parent.parent / 'runtime' / 'pro-fixture.json').write_text(json.dumps(dict(guest=sid(g), free=sid(free), free_doctor=FREE, pro=sid(pro), pro_doctor=PRO)))
finally:
    cfg_path.write_text(cfg_before)
    for k, v in saved.items():
        setting(k, v)
    for k in ('subscription_trial_enabled', 'subscriptions_enabled', 'sms_dev_mode'):
        if k not in saved:
            setting(k, '1' if k == 'subscriptions_enabled' else '0')
    if legacy_catalog:
        setting('pro_features', legacy_catalog[0]['value'])
    php('entitlement_sync_all();')
    db("DELETE FROM subscription_plans WHERE name='پرو ماهانه آزمون'")
    db('DELETE FROM request_limits')

print('SUCCESS', checks, 'rc.20 free/Pro checks')
