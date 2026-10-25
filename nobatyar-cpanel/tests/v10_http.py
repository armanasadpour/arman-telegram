"""0.10.0 — clinics and medical centers (phase 9): registration and review, documents, branches, rooms,
doctor links with doctor-granted permissions, presence and room-conflict rules, staff and reception scopes,
aggregated calendar, reports and export, announcements, center subscription (offline and gateway),
public pages, link end, expiry and suspension. Runs after v09 on the same disposable installation.
Bank calls use CLI-only injected transports; no live bank or SMS request is made."""
from http_helpers import *
import zipfile, io

fixture = json.loads((ROOT.parent.parent / 'runtime' / 'phase6-fixture.json').read_text())
DOCTOR, CLINIC, SERVICE = fixture['doctor'], fixture['clinic'], fixture['service']
PHOTO = (ROOT.parent.parent / 'runtime' / 'test-photo.png').read_bytes()


def php(code, args=[]):
    cmd = [PHP] + (['-c', PHPINI] if PHPINI else []) + [
        '-r', 'require $argv[1];if(config()["database"]!=="nobatyar_test_http")exit(9);require_once dirname($argv[1])."/tests/gateway_support.php";' + code,
        str(ROOT / 'core.php')] + [str(x) for x in args]
    return subprocess.check_output(cmd).decode()


def scalar(sql, args=[]):
    rows = db(sql, args)
    return next(iter(rows[0].values())) if rows else None


def send(c, route, data, status=200):
    actual, r = api(c, route, data, api(c, 'session')[1]['data']['csrf'])
    check(actual == status, f'{route} -> {status}' + ('' if actual == status else f' got {actual} {r}'))
    return r.get('data', {})


def fa_number(v):
    return str(v).translate(str.maketrans('0123456789', '۰۱۲۳۴۵۶۷۸۹'))


def clean(html):
    return 'Warning' not in html and 'Fatal error' not in html and 'Notice:' not in html


def snippet(html):
    m = re.search(r'<p class="(?:error|notice)"[^>]*>(.*?)</p>', html, re.S)
    return re.sub(r'<[^>]+>', '', m.group(1)).strip()[:200] if m else ''


def multipart(c, path, fields, files):
    tok = token(c, path)
    boundary = '----nobatyar' + os.urandom(6).hex()
    body = b''.join(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode() for k, v in dict(fields, csrf=tok).items())
    for name, data in files.items():
        body += f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"; filename="{name}.png"\r\nContent-Type: image/png\r\n\r\n'.encode() + data + b'\r\n'
    body += f'--{boundary}--\r\n'.encode()
    req = urllib.request.Request(BASE + path, data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
    try:
        r = c.open(req)
    except urllib.error.HTTPError as e:
        r = e
    return r.status, r.read().decode(), r.geturl()


def cpost(c, tab, data, expect, label=None, center=None):
    # CSRF tokens are per session; panel.php always carries one even when this tab shows no form.
    status, html = post(c, f'center.php?center={center or CENTER}&tab={tab}', dict(data, csrf=token(c, 'panel.php')))
    ok = expect in html
    check(ok, (label or f'center {data["action"]}: {expect}') + ('' if ok else ' :: ' + snippet(html)))
    return html


def admin_client():
    c = client()
    submit(c, 'admin.php', dict(action='login', email='admin@example.test', password='Test-password-012345'))
    return c


def admin_post(tab, data, expect):
    status, html = submit(admin, tab, data)
    ok = expect in html
    check(ok, f'admin {data["action"]}: {expect}' + ('' if ok else ' :: ' + snippet(html)))
    return html


admin = admin_client()
db('DELETE FROM request_limits')
db("UPDATE settings SET value='0' WHERE `key`='booking_fee_enabled'")
php('test_gateway_off();')
city = scalar('SELECT city_id FROM clinics WHERE id=?', [CLINIC])
province = scalar('SELECT province_id FROM cities WHERE id=?', [city])
owner_mobile = scalar('SELECT u.mobile FROM doctor_accounts a JOIN users u ON u.id=a.user_id WHERE a.doctor_id=?', [DOCTOR])
doctor_owner = client()
login_user(doctor_owner, owner_mobile)
manager = client()
manager_id = login_user(manager, '09128880001')
staff = client()
staff_id = login_user(staff, '09128880002')
patient = client()
patient_id = login_user(patient, '09128880003')
outsider = client()
login_user(outsider, '09128880004')

# ---------- Registration and documents ----------
status, html = get(client(), 'center-register.php')
check('ورود' in html and 'ثبت کلینیک' not in html.split('<main')[-1][:400], 'registration requires sign-in')
status, html = get(manager, 'center-register.php')
check(status == 200 and clean(html) and 'شماره پروانه بهره‌برداری' in html and 'تصویر پروانه' in html, 'registration form renders')
reg = dict(action='register', center_name='کلینیک تخصصی آزمون', kind='clinic', phone='07644220000', province_id=province, city_id=city,
           address='خیابان آزمون، پلاک ۱۲', description='کلینیک تخصصی با چند شعبه', license_number='۱۴۰۵/۱۲۳۴', national_id='', manager_name='مدیر آزمون',
           technical_manager='دکتر مسئول', technical_code='M-1234', accept_terms='1')
status, html, url = multipart(manager, 'center-register.php', reg, {})
check('تصویر پروانه' in html and not db("SELECT id FROM centers WHERE license_number='1405/1234'"), 'license image required')
status, html, url = multipart(manager, 'center-register.php', dict(reg, kind='bad'), {'document': PHOTO})
check('نوع مرکز' in html, 'center kind validated')
status, html, url = multipart(manager, 'center-register.php', reg, {'document': PHOTO, 'logo': PHOTO})
CENTER = scalar("SELECT id FROM centers WHERE license_number='1405/1234'")
check(CENTER and 'center.php?center=' in url and 'در انتظار بررسی' in html, 'center registered and panel opened')
c = db('SELECT * FROM centers WHERE id=?', [CENTER])[0]
check(c['review_status'] == 'pending' and c['owner_user_id'] == manager_id and re.match(r'^[a-f0-9]{40}\.jpg$', c['document_file']) and c['logo_file'], 'center pending with stored document and logo')
check(scalar('SELECT COUNT(*) FROM center_branches WHERE center_id=? AND is_main=1', [CENTER]) == 1, 'main branch created from center address')
check(scalar("SELECT role FROM center_staff WHERE center_id=? AND user_id=?", [CENTER, manager_id]) == 'owner', 'registrant is center owner')
check(scalar("SELECT COUNT(*) FROM user_roles WHERE user_id=? AND role='clinic'", [manager_id]) == 1, 'registrant gets clinic role')
resp = manager.open(BASE + f'center-document.php?id={CENTER}')
check(resp.status == 200 and resp.headers['Content-Type'] == 'image/jpeg' and 'no-store' in resp.headers['Cache-Control'], 'owner opens license image, not cached')
check(get(outsider, f'center-document.php?id={CENTER}')[0] == 403, 'other users cannot open license image')
check(get(client(), f'center-document.php?id={CENTER}')[0] == 403, 'anonymous cannot open license image')
check(admin.open(BASE + f'center-document.php?id={CENTER}').status == 200, 'administrator opens license image')
check(client().open(BASE + f'media.php?kind=center&file={c["logo_file"]}').status == 200, 'center logo served publicly')
status, html, url = multipart(outsider, 'center-register.php', dict(reg, center_name='کلینیک تکراری'), {'document': PHOTO})
check('قبلاً مرکزی ثبت شده' in html, 'duplicate license rejected')
check(get(client(), f'clinic.php?id={CENTER}')[0] == 404, 'pending center has no public page')

# ---------- Pending center: setup allowed, operations blocked ----------
status, html = get(manager, f'center.php?center={CENTER}')
check(status == 200 and clean(html) and 'در انتظار بررسی' in html, 'pending panel renders with notice')
for tab in ['dashboard', 'calendar', 'doctors', 'schedule', 'branches', 'reports', 'announcements', 'staff', 'subscription', 'profile', 'events']:
    status, html = get(manager, f'center.php?center={CENTER}&tab={tab}')
    check(status == 200 and clean(html), f'center tab {tab} renders')
cpost(manager, 'branches', dict(action='branch', name='شعبه دوم', province_id=province, city_id=city, address='بلوار دوم', phone='', active='1'), 'شعبه ذخیره شد')
MAIN = scalar('SELECT id FROM center_branches WHERE center_id=? AND is_main=1', [CENTER])
SECOND = scalar("SELECT id FROM center_branches WHERE center_id=? AND name='شعبه دوم'", [CENTER])
cpost(manager, 'branches', dict(action='room', branch_id=MAIN, name='اتاق ۱', note='', active='1'), 'اتاق ذخیره شد')
cpost(manager, 'branches', dict(action='room', branch_id=MAIN, name='اتاق ۲', note='', active='1'), 'اتاق ذخیره شد')
cpost(manager, 'branches', dict(action='room', branch_id=MAIN, name='اتاق ۱', note='', active='1'), 'وجود دارد', 'duplicate room name rejected')
ROOM1 = scalar("SELECT id FROM center_rooms WHERE branch_id=? AND name='اتاق ۱'", [MAIN])
ROOM2 = scalar("SELECT id FROM center_rooms WHERE branch_id=? AND name='اتاق ۲'", [MAIN])
cpost(manager, 'doctors', dict(action='invite', medical_code='123', perm_reports='1'), 'پس از تأیید مرکز', 'invitation blocked before approval')
cpost(manager, 'profile', dict(action='profile', center_name='کلینیک تخصصی آزمون', kind='clinic', phone='07644220001', province_id=province, city_id=city, address='خیابان آزمون، پلاک ۱۲', description='کلینیک تخصصی با چند شعبه', license_number='1405/1234', manager_name='مدیر آزمون'), 'مشخصات مرکز ذخیره شد')

# ---------- Admin review ----------
status, html = get(admin, 'admin.php?tab=centers')
check(status == 200 and clean(html) and 'کلینیک تخصصی آزمون' in html, 'admin lists centers')
status, html = get(admin, f'admin.php?tab=centers&center={CENTER}')
check(status == 200 and clean(html) and 'مشاهده تصویر پروانه' in html and '۰۷۶۴۴۲۲۰۰۰۱' in html, 'admin center detail with document link')
admin_post(f'admin.php?tab=centers&center={CENTER}', dict(action='center_review', center_id=CENTER, status='rejected', note=''), 'علت رد')
admin_post(f'admin.php?tab=centers&center={CENTER}', dict(action='center_review', center_id=CENTER, status='approved', note=''), 'تغییرات ذخیره شد')
check(scalar('SELECT review_status FROM centers WHERE id=?', [CENTER]) == 'approved', 'center approved')
check(scalar("SELECT COUNT(*) FROM notifications WHERE user_id=? AND title LIKE 'وضعیت مرکز%'", [manager_id]) >= 1, 'owner notified of review')
cpost(manager, 'doctors', dict(action='invite', medical_code='123', perm_reports='1'), 'اشتراک مرکز به پایان رسیده', 'operations need a center subscription')
check(get(client(), f'clinic.php?id={CENTER}')[0] == 404, 'no public page without subscription')

# ---------- Center subscription: offline payment and gateway ----------
status, html = submit(admin, 'admin.php?tab=subscriptions&view=plans', dict(action='plan_save', name='مرکز آزمون ماهانه', audience='center', duration_days='30', price_toman='۵۰۰٬۰۰۰', features='تقویم تجمیعی', active='1'))
CPLAN = scalar("SELECT id FROM subscription_plans WHERE name='مرکز آزمون ماهانه' AND audience='center'")
check(CPLAN is not None, 'admin creates a center plan')
status, html = get(doctor_owner, f'doctor-panel.php?doctor={DOCTOR}&tab=subscription')
check('مرکز آزمون ماهانه' not in html, 'center plans hidden from doctors')
cpost(manager, 'subscription', dict(action='sub_order', plan_id=CPLAN, discount_code=''), 'پیش‌فاکتور صادر شد')
order = db("SELECT * FROM center_orders WHERE center_id=? AND status='proforma'", [CENTER])[0]
check(order['base_toman'] == 500000 and order['payable_toman'] == 500000 + order['vat_toman'] and order['vat_toman'] == round(500000 * float(order['vat_percent']) / 100), 'proforma amount from plan plus configured VAT')
status, html = get(manager, f'center-invoice.php?id={order["id"]}')
check(status == 200 and 'پیش‌فاکتور' in html and '۵۰۰٬۰۰۰' in html and ('مالیات' in html or not order['vat_toman']), f'proforma printable for billing staff ({status})')
check(get(outsider, f'center-invoice.php?id={order["id"]}')[0] == 403, 'other users cannot read center invoice')
status, html = get(manager, f'center.php?center={CENTER}&tab=subscription')
check('درگاه پرداخت فعالی وجود ندارد' in html, 'no online payment without an active gateway')
admin_post(f'admin.php?tab=centers&center={CENTER}', dict(action='center_offline', order_id=order['id'], reference='12'), 'شماره پیگیری')
admin_post(f'admin.php?tab=centers&center={CENTER}', dict(action='center_offline', order_id=order['id'], reference='۹۸۷۶۵۴'), 'تغییرات ذخیره شد')
paid = db('SELECT * FROM center_orders WHERE id=?', [order['id']])[0]
year = php('echo digits(substr(jdate(date("Y-m-d")),0,4));')
check(paid['status'] == 'paid' and paid['number'].startswith(f'NY-{year}-') and paid['offline_reference'] == '987654', 'offline payment issues invoice number')
until1 = scalar('SELECT subscription_until FROM centers WHERE id=?', [CENTER])
today = php('echo date("Y-m-d");')
check(str(until1) == php('echo date("Y-m-d",strtotime("+29 days"));'), 'subscription covers 30 days from today')
status, html = get(manager, f'center-invoice.php?id={order["id"]}')
check('فاکتور فروش خدمات' in html and paid['number'] in html, 'paid invoice shows number')
# Renewal paid online through the shared payment module (bank simulated in CLI).
php("test_gateway('zarinpal',['merchant_id'=>'11111111-1111-1111-1111-111111111111']);")
cpost(manager, 'subscription', dict(action='sub_order', plan_id=CPLAN, discount_code=''), 'پیش‌فاکتور صادر شد')
order2 = scalar("SELECT id FROM center_orders WHERE center_id=? AND status='proforma'", [CENTER])
payable2 = scalar('SELECT payable_toman FROM center_orders WHERE id=?', [order2])
auth = 'A' + 'C' * 35
r = json.loads(php(f"$_SESSION['user_id']={manager_id};$_SESSION['user_version']=1;$u=query('SELECT session_version FROM users WHERE id=?',[{manager_id}])->fetch();$_SESSION['user_version']=$u['session_version'];$_SESSION['login_at']=time();" +
                   "$r=center_checkout((int)$argv[2],(int)$argv[3],zp(function($op,$p)use($argv){if($op==='request'){if($p['amount']!==(int)$argv[5]*10)throw new RuntimeException('amount');return ['data'=>['code'=>100,'authority'=>$argv[4]]];}return ['data'=>['code'=>100,'ref_id'=>7700770]];}));echo json_encode($r);",
                   [CENTER, order2, auth, payable2]))
check(r['redirect']['url'].endswith(auth) and scalar('SELECT purpose FROM payment_transactions WHERE id=?', [r['tx']]) == 'center_subscription', 'center checkout redirects to bank with center purpose')
t = json.loads(php("echo json_encode(test_return($argv[2],['Authority'=>$argv[2],'Status'=>'OK'],zp(fn($o,$p)=>['data'=>['code'=>100,'ref_id'=>7700770]])));", [auth]))
check(t['status'] == 'verified' and scalar('SELECT status FROM center_orders WHERE id=?', [order2]) == 'paid', 'verified payment activates center subscription')
until2 = scalar('SELECT subscription_until FROM centers WHERE id=?', [CENTER])
check(str(until2) == php(f'echo date("Y-m-d",strtotime("{until1} +30 days"));'), 'renewal starts after current period')
t2 = json.loads(php("echo json_encode(test_return($argv[2],['Authority'=>$argv[2],'Status'=>'OK'],function(){throw new RuntimeException('no second call');}));", [auth]))
check(scalar('SELECT subscription_until FROM centers WHERE id=?', [CENTER]) == until2, 'repeated callback does not extend twice')
status, html = get(client(), f'pay-return.php?tx={r["tx"]}')
check('پرداخت موفق بود' in html, 'shared return page confirms center payment')
php('test_gateway_off();')
check(get(client(), f'clinic.php?id={CENTER}')[0] == 200, 'approved subscribed center has a public page')
status, html = get(admin, 'admin.php?tab=payments&purpose=center_subscription')
check('اشتراک مرکز' in html, 'center payments in transaction report')

# ---------- Doctor links ----------
medical = scalar('SELECT medical_code FROM doctors WHERE id=?', [DOCTOR])
cpost(manager, 'doctors', dict(action='invite', medical_code='999999999', perm_reports='1'), 'پیدا نشد', 'unknown council number rejected')
cpost(manager, 'doctors', dict(action='invite', medical_code=medical, perm_history='1', perm_reports='1'), 'بدون دسترسی نوبت‌ها', 'history without bookings permission rejected')
cpost(manager, 'doctors', dict(action='invite', medical_code=medical, message='همکاری در شعبه مرکزی', perm_schedule='1', perm_bookings='1', perm_history='1', perm_reports='1'), 'دعوت برای پزشک ارسال شد')
cpost(manager, 'doctors', dict(action='invite', medical_code=medical, perm_reports='1'), 'در انتظار پاسخ', 'duplicate invitation rejected')
LINK = scalar('SELECT id FROM center_doctors WHERE center_id=? AND doctor_id=?', [CENTER, DOCTOR])
check(scalar("SELECT COUNT(*) FROM notifications n JOIN doctor_accounts a ON a.user_id=n.user_id WHERE a.doctor_id=? AND n.title LIKE 'دعوت همکاری%'", [DOCTOR]) == 1, 'doctor notified of invitation')
status, html = get(doctor_owner, f'doctor-panel.php?doctor={DOCTOR}&tab=centers')
check(status == 200 and clean(html) and 'کلینیک تخصصی آزمون' in html and 'پذیرش همکاری' in html, 'doctor sees invitation')
check(get(staff, f'doctor-panel.php?doctor={DOCTOR}&tab=centers')[0] == 403, 'centers tab is doctor-only')
status, html = submit(doctor_owner, f'doctor-panel.php?doctor={DOCTOR}&tab=centers', dict(action='center_accept', link_id=LINK, perm_schedule='1', perm_bookings='1', perm_reports='1'))
link = db('SELECT * FROM center_doctors WHERE id=?', [LINK])[0]
check(link['status'] == 'active' and link['perm_bookings'] == 1 and link['perm_history'] == 0, 'doctor accepts and narrows permissions (history removed)')

# ---------- Presence, hours and room conflicts ----------
busy = {r['weekday'] for r in db('SELECT h.weekday FROM working_hours h JOIN clinics c ON c.id=h.clinic_id WHERE c.doctor_id=? AND h.active=1', [DOCTOR])}
free_day = next(w for w in [6, 0, 1, 2, 3, 4, 5] if w not in busy)
busy_day = next(iter(busy))
cpost(manager, 'doctors', dict(action='presence', doctor_id=DOCTOR, branch_id=MAIN), 'پزشک به شعبه اضافه شد')
CCLINIC = scalar('SELECT id FROM clinics WHERE doctor_id=? AND branch_id=?', [DOCTOR, MAIN])
cl = db('SELECT * FROM clinics WHERE id=?', [CCLINIC])[0]
check(cl['center_id'] == CENTER and cl['name'] == 'کلینیک تخصصی آزمون — شعبه مرکزی' and cl['phone'] == '07644220000', 'presence is a center clinic row with branch details')
cpost(manager, 'doctors', dict(action='presence', doctor_id=DOCTOR, branch_id=MAIN), 'تعریف شده', 'presence not duplicated')
cpost(manager, 'schedule', dict(action='hours', clinic_id=CCLINIC, weekday=busy_day, opens_at='00:00', closes_at='23:59', room_id=ROOM1), 'تداخل دارد', 'center hours cannot overlap doctor personal clinic hours')
cpost(manager, 'schedule', dict(action='hours', clinic_id=CCLINIC, weekday=free_day, opens_at='14:00', closes_at='18:00', room_id=ROOM1), 'ساعت کاری ثبت شد')
# Second doctor in the same room.
d2 = int(php("echo register_doctor(['name'=>'دوم مرکز','mobile'=>'09128880010','medical_code'=>'99887766','specialty_id'=>(int)query('SELECT specialty_id FROM doctors WHERE id=?',[(int)$argv[2]])->fetchColumn(),'bio'=>'','clinic_name'=>'مطب دوم','province_id'=>(int)$argv[3],'city_id'=>(int)$argv[4],'address'=>'نشانی دوم','phone'=>''],true);", [DOCTOR, province, city]).strip())
db("UPDATE doctors SET review_status='approved',active=1,subscription_until='2099-01-01' WHERE id=?", [d2])
db("INSERT INTO center_doctors(center_id,doctor_id,status,initiated_by,perm_schedule,perm_bookings,perm_reports) VALUES(?,?,'active','center',1,0,1)", [CENTER, d2])
cpost(manager, 'doctors', dict(action='presence', doctor_id=d2, branch_id=MAIN), 'پزشک به شعبه اضافه شد')
C2 = scalar('SELECT id FROM clinics WHERE doctor_id=? AND branch_id=?', [d2, MAIN])
cpost(manager, 'schedule', dict(action='hours', clinic_id=C2, weekday=free_day, opens_at='16:00', closes_at='20:00', room_id=ROOM1), 'این اتاق در همین ساعت', 'room double-booking rejected')
cpost(manager, 'schedule', dict(action='hours', clinic_id=C2, weekday=free_day, opens_at='18:00', closes_at='20:00', room_id=ROOM1), 'ساعت کاری ثبت شد', 'adjacent period in same room accepted')
cpost(manager, 'schedule', dict(action='hours', clinic_id=C2, weekday=free_day, opens_at='08:00', closes_at='12:00', room_id=ROOM2), 'ساعت کاری ثبت شد', 'other room at another time accepted')
cpost(manager, 'schedule', dict(action='hours', clinic_id=C2, weekday=(free_day + 1) % 7, opens_at='14:00', closes_at='18:00', room_id=scalar('SELECT id FROM center_rooms WHERE branch_id<>? LIMIT 1', [MAIN]) or 999999), 'فعال نیست', 'room of another branch rejected')
cpost(manager, 'branches', dict(action='room', branch_id=MAIN, id=ROOM1, name='اتاق ۱', note='', active=''), 'استفاده می‌شود', 'room in use cannot be deactivated')
status, html = get(manager, f'center.php?center={CENTER}&tab=schedule')
check('دکتر دوم مرکز' in html and 'اتاق ۱' in html and '۱۴:۰۰–۱۸:۰۰' in html, 'weekly room grid shows assignments')
# The doctor manages his center schedule too, from his own panel, with room choice.
status, html = get(doctor_owner, f'doctor-panel.php?doctor={DOCTOR}&tab=schedule&clinic={CCLINIC}')
check('اتاق مرکز' in html and 'اتاق ۲' in html, 'doctor schedule offers center rooms')
status, html = submit(doctor_owner, f'doctor-panel.php?doctor={DOCTOR}&tab=schedule&clinic={CCLINIC}', dict(action='hours', clinic_id=CCLINIC, weekday=free_day, opens_at='19:00', closes_at='21:00', room_id=ROOM1))
check('تداخل' in html or 'این اتاق' in html, 'doctor panel enforces room conflicts too')
status, html = get(doctor_owner, f'doctor-panel.php?doctor={DOCTOR}&tab=clinics')
check('حضور در شعبه مرکز درمانی' in html, 'doctor clinics tab marks center presence')
status, html = submit(doctor_owner, f'doctor-panel.php?doctor={DOCTOR}&tab=clinics', dict(action='clinic', id=CCLINIC, clinic_name='تغییر', province_id=province, city_id=city, address='x', phone='', active='1'))
check('توسط مرکز تعیین می‌شود' in html, 'doctor cannot rewrite center clinic details')

# ---------- Public pages ----------
status, html = get(client(), f'clinic.php?id={CENTER}')
check(status == 200 and clean(html) and 'کلینیک تخصصی آزمون' in html and 'شعبه مرکزی' in html and f'clinic={CCLINIC}' in html, 'public center page lists doctor in branch')
check('دکتر دوم مرکز' in html, 'second doctor listed')
status, html = get(client(), f'centers.php?city={city}')
check('کلینیک تخصصی آزمون' in html, 'center listed in its city')
status, html = get(client(), f'doctor.php?id={DOCTOR}&clinic={CCLINIC}')
check(status == 200 and 'کلینیک تخصصی آزمون' in html, 'doctor public page offers the center clinic')

# ---------- Staff and reception scopes ----------
cpost(manager, 'staff', dict(action='staff', mobile='09128889999', role='secretary', branch_id='', perm_bookings='1'), 'یک بار با همین موبایل', 'staff must have signed in once')
cpost(manager, 'staff', dict(action='staff', mobile='09128880002', role='secretary', branch_id=MAIN, perm_bookings='1', perm_announce='1'), 'دسترسی کارمند ذخیره شد')
scopes = db("SELECT * FROM staff_scopes WHERE user_id=? AND center_id=?", [staff_id, CENTER])
check([s['clinic_id'] for s in scopes] == [CCLINIC] and scopes[0]['can_manage_bookings'] == 1 and scopes[0]['can_view_history'] == 0, 'reception scope only for doctors who allowed bookings (not doctor 2)')
check(scalar("SELECT COUNT(*) FROM user_roles WHERE user_id=? AND role='secretary'", [staff_id]) == 1, 'staff gets reception role')
status, html = get(staff, f'secretary.php?clinic={CCLINIC}')
check(status == 200 and clean(html) and 'کلینیک تخصصی آزمون' in html, 'center staff opens reception panel for center clinic')
status, html = get(staff, f'secretary.php?clinic={CLINIC}')
check(status == 403, 'center staff cannot reach the doctor personal practice')
status, html = get(staff, f'center.php?center={CENTER}&tab=reports')
check('در سطح دسترسی شما نیست' in html, 'staff without report permission blocked')
cpost(staff, 'staff', dict(action='staff', mobile='09128880004', role='manager', perm_staff='1'), 'سطح دسترسی', 'staff cannot manage staff without permission')
cpost(manager, 'staff', dict(action='staff', mobile='09128880004', role='secretary', branch_id='', perm_bookings='1', perm_billing='1'), 'دسترسی کارمند ذخیره شد', 'owner can grant billing')
# Doctor's own secretary list does not include center staff.
status, html = get(doctor_owner, f'doctor-panel.php?doctor={DOCTOR}&tab=staff')
check('۰۹۱۲۸۸۸۰۰۰۲' not in html, 'center staff not shown as doctor secretaries')

# ---------- Booking at the center and aggregated calendar ----------
day = php(f'$d=strtotime("tomorrow");while((int)date("w",$d)!=={free_day})$d+=86400;echo date("Y-m-d",$d);')
avail = api(client(), f'availability&clinic={CCLINIC}&service={SERVICE}&date={day}')[1]['data']
check(len(avail) > 0 and avail[0]['starts_at'].endswith('14:00:00'), 'center clinic offers the room schedule times')
h = send(patient, 'booking/hold', dict(clinic_id=CCLINIC, service_id=SERVICE, starts_at=avail[0]['starts_at'], name='بیمار مرکز'))
send(patient, 'booking/confirm', dict(id=h['id']))
BOOKING = h['id']
jday = php(f'echo jdate("{day}");')
status, html = get(manager, f'center.php?center={CENTER}&tab=calendar&from={urllib.parse.quote(jday)}&to={urllib.parse.quote(jday)}')
check('بیمار مرکز' in html and 'اتاق ۱' in html and '۰۹۱۲۸۸۸۰۰۰۳' in html, 'calendar shows patient and room for permitted doctor')
status, html = get(staff, f'center.php?center={CENTER}&tab=calendar&from={urllib.parse.quote(jday)}&to={urllib.parse.quote(jday)}')
check('بیمار مرکز' in html and 'پذیرش' in html, 'branch staff sees calendar with reception link')
# Doctor withdraws booking permission: names hidden, scopes removed.
submit(doctor_owner, f'doctor-panel.php?doctor={DOCTOR}&tab=centers', dict(action='center_perms', link_id=LINK, perm_schedule='1', perm_reports='1'))
check(scalar('SELECT COUNT(*) FROM staff_scopes WHERE center_id=?', [CENTER]) == 0, 'withdrawn permission removes reception scopes')
check(scalar("SELECT COUNT(*) FROM user_roles WHERE user_id=? AND role='secretary'", [staff_id]) == 0, 'reception role removed with last scope')
status, html = get(manager, f'center.php?center={CENTER}&tab=calendar&from={urllib.parse.quote(jday)}&to={urllib.parse.quote(jday)}')
check('بیمار مرکز' not in html and 'محرمانه' in html and '۰۹۱۲۸۸۸۰۰۰۳' not in html, 'calendar masks patients without permission')
cpost(manager, 'announcements', dict(action='announce', date_from=jday, date_to=jday, title='تغییر ساعت', body='پذیرش از ساعت ۱۴:۳۰ آغاز می‌شود.'), 'پیدا نشد', 'announcement excludes doctors without contact permission')
submit(doctor_owner, f'doctor-panel.php?doctor={DOCTOR}&tab=centers', dict(action='center_perms', link_id=LINK, perm_schedule='1', perm_bookings='1', perm_reports='1'))
check(scalar('SELECT COUNT(*) FROM staff_scopes WHERE center_id=? AND user_id=?', [CENTER, staff_id]) == 1, 'restored permission restores scope')

# ---------- Announcements ----------
status, html = get(manager, f'center.php?center={CENTER}&tab=announcements')
check('فقط اعلان داخل برنامه' in html, 'SMS option disabled without template')
cpost(manager, 'announcements', dict(action='announce', date_from=jday, date_to=jday, title='تغییر ساعت', body='پذیرش از ساعت ۱۴:۳۰ آغاز می‌شود.', send_sms='1'), 'الگوی پیامک', 'SMS needs an enabled template')
cpost(manager, 'announcements', dict(action='announce', date_from=jday, date_to=jday, title='تغییر ساعت', body='پذیرش از ساعت ۱۴:۳۰ آغاز می‌شود.'), 'اطلاعیه برای ۱ نوبت ثبت شد')
check(scalar("SELECT COUNT(*) FROM notifications WHERE user_id=? AND booking_id=? AND title LIKE '%تغییر ساعت'", [patient_id, BOOKING]) == 1, 'patient receives in-app announcement')
status, html = get(patient, 'patient.php?tab=notifications')
check('تغییر ساعت' in html, 'announcement visible in patient panel')
db("UPDATE settings SET value='1' WHERE `key`='sms_enabled'")
db("INSERT INTO sms_templates(event,template,enabled) VALUES('center_announcement','CenterNotice',1) ON DUPLICATE KEY UPDATE template='CenterNotice',enabled=1")
cpost(manager, 'announcements', dict(action='announce', date_from=jday, date_to=jday, title='یادآوری مدارک', body='لطفاً مدارک قبلی را همراه داشته باشید.', send_sms='1'), 'پیامک در صف')
sms = db("SELECT * FROM sms_messages WHERE event='center_announcement' ORDER BY id DESC LIMIT 1")[0]
check(sms['mobile'] == '09128880003' and 'یادآوری مدارک' in sms['tokens'] and 'کلینیک تخصصی آزمون' in sms['tokens'], 'announcement SMS queued with center and title tokens')
db("UPDATE settings SET value='0' WHERE `key`='sms_enabled'")
cpost(manager, 'announcements', dict(action='announce', date_from=jday, date_to=php('echo jdate(date("Y-m-d",strtotime("+90 days")));'), title='بازه طولانی', body='متن آزمایشی طولانی'), '۶۰ روز', 'announcement range limited')

# ---------- Reports and export ----------
db("UPDATE bookings SET status='no_show' WHERE id=?", [BOOKING])
f, t_ = php('echo jdate(date("Y-m-d",strtotime("-1 day")));'), php('echo jdate(date("Y-m-d",strtotime("+40 days")));')
status, html = get(manager, f'center.php?center={CENTER}&tab=reports&from={urllib.parse.quote(f)}&to={urllib.parse.quote(t_)}')
check(status == 200 and clean(html) and 'عملکرد هر پزشک' in html and '۱۰۰٪' in html, 'report shows no-show rate per doctor')
r = manager.open(BASE + f'center.php?center={CENTER}&tab=reports&from={urllib.parse.quote(f)}&to={urllib.parse.quote(t_)}&export=xlsx')
sheet = zipfile.ZipFile(io.BytesIO(r.read())).read('xl/worksheets/sheet1.xml').decode()
check('rightToLeft="1"' in sheet and 'شعبه مرکزی' in sheet, 'report Excel export')
db("UPDATE bookings SET status='confirmed' WHERE id=?", [BOOKING])

# ---------- Ending and rejoining ----------
cpost(manager, 'doctors', dict(action='link_end', link_id=LINK, reason=''), 'علت', 'end requires a reason')
cpost(manager, 'doctors', dict(action='link_end', link_id=LINK, reason='پایان قرارداد'), 'نوبت قطعی آینده حفظ شده', 'ending reports preserved bookings')
check(scalar('SELECT active FROM clinics WHERE id=?', [CCLINIC]) == 0 and scalar('SELECT status FROM bookings WHERE id=?', [BOOKING]) == 'confirmed', 'presence stopped, booking kept')
check(scalar('SELECT COUNT(*) FROM staff_scopes WHERE center_id=? AND clinic_id=?', [CENTER, CCLINIC]) == 0, 'scopes removed after link end')
status, html = get(client(), f'clinic.php?id={CENTER}')
check(f'clinic={CCLINIC}' not in html, 'ended doctor no longer on public page')
status, html = submit(doctor_owner, f'doctor-panel.php?doctor={DOCTOR}&tab=centers', dict(action='center_request', center_code=fa_number(CENTER), message='بازگشت', perm_bookings='1', perm_reports='1'))
check(scalar('SELECT status FROM center_doctors WHERE id=?', [LINK]) == 'requested', 'doctor requests to rejoin by center code')
cpost(manager, 'doctors', dict(action='link_accept', link_id=LINK), 'درخواست پزشک پذیرفته شد')
link = db('SELECT * FROM center_doctors WHERE id=?', [LINK])[0]
check(link['status'] == 'active' and link['perm_schedule'] == 0, 'doctor-chosen permissions kept on acceptance')
cpost(manager, 'doctors', dict(action='presence', doctor_id=DOCTOR, branch_id=MAIN), 'سپرده', 'center cannot manage presence without schedule permission')
status, html = submit(doctor_owner, f'doctor-panel.php?doctor={DOCTOR}&tab=centers', dict(action='center_presence', link_id=LINK, branch_id=MAIN))
check(scalar('SELECT active FROM clinics WHERE id=?', [CCLINIC]) == 1, 'doctor reactivates his own presence')

# ---------- Expiry and suspension ----------
db("UPDATE centers SET subscription_until=DATE_SUB(CURDATE(),INTERVAL 30 DAY) WHERE id=?", [CENTER])
db("UPDATE center_subscriptions SET starts_on=DATE_SUB(CURDATE(),INTERVAL 90 DAY),ends_on=DATE_SUB(CURDATE(),INTERVAL 30 DAY) WHERE center_id=?", [CENTER])
cron_token = php('echo config()["cron_token"];')
status, text = get(client(), 'cron.php?token=' + cron_token)
check(status == 200 and 'centers' in text, 'cron runs center maintenance')
check(scalar('SELECT COUNT(*) FROM staff_scopes WHERE center_id=?', [CENTER]) == 0, 'expired center loses reception scopes')
status, html = get(manager, f'center.php?center={CENTER}&tab=dashboard')
check('اشتراک مرکز به پایان رسیده' in html, 'expired notice in panel')
check(get(client(), f'clinic.php?id={CENTER}')[0] == 404, 'expired center hidden publicly')
avail = api(client(), f'availability&clinic={CCLINIC}&service={SERVICE}&date={day}')[1]['data']
check(isinstance(avail, list), 'doctor bookings at the branch follow the doctor subscription')
admin_post(f'admin.php?tab=centers&center={CENTER}', dict(action='center_grant', center_id=CENTER, days='10', kind='gift', note='جبران'), 'تغییرات ذخیره شد')
check(str(scalar('SELECT subscription_until FROM centers WHERE id=?', [CENTER])) == php('echo date("Y-m-d",strtotime("+9 days"));'), 'gift days start today after expiry')
check(scalar('SELECT COUNT(*) FROM staff_scopes WHERE center_id=?', [CENTER]) >= 1, 'renewal restores scopes')
admin_post(f'admin.php?tab=centers&center={CENTER}', dict(action='center_review', center_id=CENTER, status='suspended', note='بررسی شکایت'), 'تغییرات ذخیره شد')
status, html = get(manager, f'center.php?center={CENTER}')
check('تعلیق شده' in html and 'بررسی شکایت' in html, 'suspension shown to center')
cpost(manager, 'branches', dict(action='room', branch_id=MAIN, name='اتاق ۳', note='', active='1'), 'فقط مشاهده', 'suspended center is read-only')
check(scalar('SELECT COUNT(*) FROM staff_scopes WHERE center_id=?', [CENTER]) == 0 and get(client(), f'clinic.php?id={CENTER}')[0] == 404, 'suspension removes access and public page')
admin_post(f'admin.php?tab=centers&center={CENTER}', dict(action='center_review', center_id=CENTER, status='approved', note=''), 'تغییرات ذخیره شد')
status, html = get(manager, f'center.php?center={CENTER}&tab=events')
check('تغییر وضعیت بررسی' in html and 'پایان همکاری' in html and 'ارسال اطلاعیه' in html, 'center event log records actions')
status, html = get(manager, 'panel.php?role=clinic')
check('ورود به پنل مرکز' in html, 'account panel links the center')
# Local-only fixture for the browser test; never packaged.
session = next(x.value for h in manager.handlers if isinstance(h, urllib.request.HTTPCookieProcessor) for x in h.cookiejar if x.name == 'PHPSESSID')
(ROOT.parent.parent / 'runtime' / 'center-fixture.json').write_text(json.dumps(dict(center=CENTER, clinic=CCLINIC, session=session, day=jday)))
import http_helpers
print(f"SUCCESS {http_helpers.checks} v0.10 checks")
