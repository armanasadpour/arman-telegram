"""0.7.0 — geography, specialties, doctor status, SMS/OTP hardening, cron.
Runs after the reception suite on the same disposable installation. No live SMS or bank call."""
from http_helpers import *

fixture = json.loads((ROOT.parent.parent / 'runtime' / 'phase6-fixture.json').read_text())
DOCTOR, CLINIC = fixture['doctor'], fixture['clinic']


def php(code, args=[]):
    cmd = [PHP] + (['-c', PHPINI] if PHPINI else []) + [
        '-r', 'require $argv[1];if(config()["database"]!=="nobatyar_test_http")exit(9);' + code,
        str(ROOT / 'core.php')] + [str(x) for x in args]
    return subprocess.check_output(cmd).decode()


def scalar(sql, args=[]):
    return next(iter(db(sql, args)[0].values()))


def send(c, route, data, status=200):
    actual, r = api(c, route, data, api(c, 'session')[1]['data']['csrf'])
    check(actual == status, f'{route} -> {status}' + ('' if actual == status else f' got {actual} {r}'))
    return r.get('data', {})


def admin_client():
    c = client()
    submit(c, 'admin.php', dict(action='login', email='admin@example.test', password='Test-password-012345'))
    return c


admin = admin_client()
db('DELETE FROM request_limits')

# ---------- Geography ----------
hormozgan = scalar("SELECT id FROM provinces WHERE name='هرمزگان'")
bastak = scalar("SELECT id FROM cities WHERE name='بستک' AND province_id=?", [hormozgan])
status, r = api(client(), f'geo/cities&province={hormozgan}')
names = [c['name'] for c in r['data']]
check(status == 200 and 'بندرعباس' in names and names[0] == 'بندرعباس', 'province cities API lists capital first')
status, r = api(client(), 'geo/search&q=' + urllib.parse.quote('بستك'))
check(any(c['id'] == bastak for c in r['data']), 'search normalises Arabic kaf (بستك → بستک)')
status, r = api(client(), f'geo/nearby&city={bastak}')
check(status == 200 and len(r['data']) > 0 and all(c['distance_km'] <= 60 for c in r['data']), 'nearby cities within radius')

visitor = client()
status, html = get(visitor, 'index.php')
check('شهر خود را انتخاب کنید' in html, 'first visit shows city picker')
status, html = get(visitor, f'city.php?select={bastak}')
check('شهر بستک انتخاب شد' in html or 'پزشکان بستک' in html, 'city picker selection')
status, html = get(visitor, 'index.php')
check('شهر خود را انتخاب کنید' not in html and 'city-chip' in html and '<span>بستک</span>' in html, 'chosen city kept and shown in header')
status, html = get(visitor, 'index.php?scope=all')
check('سراسر کشور' in html, 'nationwide scope available')
status, html = get(visitor, 'index.php?scope=nearby')
check(status == 200 and 'شهرهای اطراف' in html, 'nearby scope and nearby list')
csrf = api(visitor, 'session')[1]['data']['csrf']
status, r = api(visitor, 'geo/select', dict(city_id=999999), csrf)
check(status == 422, 'invalid city rejected')

# Province / city ordering and deactivation.
first, second = [r['id'] for r in db('SELECT id FROM provinces ORDER BY sort_order,name LIMIT 2')]
save(admin, 'admin.php?tab=provinces', dict(action='move', type='provinces', id=second, direction='up'))
check(db('SELECT id FROM provinces ORDER BY sort_order,name LIMIT 1')[0]['id'] == second, 'province moved up')
save(admin, 'admin.php?tab=provinces', dict(action='move', type='provinces', id=second, direction='down'))
check(db('SELECT id FROM provinces ORDER BY sort_order,name LIMIT 1')[0]['id'] == first, 'province order restored')
city_rows = db('SELECT id FROM cities WHERE province_id=? ORDER BY sort_order,name LIMIT 2', [hormozgan])
save(admin, 'admin.php?tab=cities&province=' + str(hormozgan), dict(action='move', type='cities', id=city_rows[1]['id'], direction='up'))
check(db('SELECT id FROM cities WHERE province_id=? ORDER BY sort_order,name LIMIT 1', [hormozgan])[0]['id'] == city_rows[1]['id'], 'city moved within province')
status, html = get(admin, f'admin.php?tab=cities&province={hormozgan}')
check('بستک' in html and 'مرکز استان' in html, 'admin city list per province')
save(admin, 'admin.php?tab=cities', dict(action='geo_settings', nearby_radius_km=80, map_tile_url='https://tile.openstreetmap.org/{z}/{x}/{y}.png', map_attribution='© OpenStreetMap'))
check(scalar("SELECT value FROM settings WHERE `key`='nearby_radius_km'") == '80', 'nearby radius configurable')
status, html = submit(admin, 'admin.php?tab=cities', dict(action='geo_settings', nearby_radius_km=80, map_tile_url='javascript:alert(1)', map_attribution='x'))
check('HTTPS' in html, 'unsafe tile URL rejected')

# ---------- Specialties ----------
save(admin, 'admin.php?tab=specialties', dict(action='taxonomy', type='specialties', name='قلب و عروق', level='specialty', icon='heart', active='1'))
parent = scalar("SELECT id FROM specialties WHERE name='قلب و عروق'")
status, html = submit(admin, 'admin.php?tab=specialties', dict(action='taxonomy', type='specialties', name='اینترونشنال', level='subspecialty', icon='heart', active='1'))
check('تخصص مادر' in html, 'subspecialty requires a parent')
save(admin, 'admin.php?tab=specialties', dict(action='taxonomy', type='specialties', name='اینترونشنال', level='subspecialty', parent_id=parent, icon='heart', active='1'))
child = db("SELECT * FROM specialties WHERE name='اینترونشنال'")[0]
check(child['parent_id'] == parent and child['level'] == 'subspecialty' and child['icon'] == 'heart', 'subspecialty saved with parent and icon')
status, html = submit(admin, 'admin.php?tab=specialties', dict(action='taxonomy', type='specialties', name='بد', level='specialty', icon='../../x', active='1'))
check('آیکن' in html, 'unknown icon rejected')
status, html = submit(admin, 'admin.php?tab=specialties', dict(action='delete_taxonomy', type='specialties', id=parent))
check('زیرمجموعه' in html, 'parent with children cannot be archived')
status, html = submit(admin, 'admin.php?tab=specialties', dict(action='delete_taxonomy', type='specialties', id=child['id']))
check('بایگانی شد' in html and db('SELECT deleted_at FROM specialties WHERE id=?', [child['id']])[0]['deleted_at'] is not None, 'logical delete keeps the row')
status, html = get(admin, 'admin.php?tab=specialties')
check('بایگانی‌شده' in html, 'archived list shown')
save(admin, 'admin.php?tab=specialties', dict(action='restore_specialty', id=child['id']))
check(db('SELECT deleted_at FROM specialties WHERE id=?', [child['id']])[0]['deleted_at'] is None, 'archived specialty restored')
used = scalar('SELECT specialty_id FROM doctors WHERE id=?', [DOCTOR])
status, html = submit(admin, 'admin.php?tab=specialties', dict(action='delete_taxonomy', type='specialties', id=used))
check('پزشک با این تخصص' in html, 'specialty in use cannot be archived')

# Specialty image upload: re-encoded PNG stored outside the web folder and served by media.php.
photo = (ROOT.parent.parent / 'runtime' / 'test-photo.png').read_bytes()
boundary = '----nobatyar' + os.urandom(6).hex()
tok = token(admin, f'admin.php?tab=specialties&edit={parent}')
fields = dict(csrf=tok, action='taxonomy', type='specialties', id=str(parent), name='قلب و عروق', level='specialty', icon='heart', active='1')
body = b''.join(f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode() for k, v in fields.items())
body += f'--{boundary}\r\nContent-Disposition: form-data; name="image"; filename="a.png"\r\nContent-Type: image/png\r\n\r\n'.encode() + photo + f'\r\n--{boundary}--\r\n'.encode()
req = urllib.request.Request(BASE + 'admin.php?tab=specialties', data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
admin.open(req).read()
image = scalar('SELECT image_file FROM specialties WHERE id=?', [parent])
check(bool(re.match(r'^[a-f0-9]{32}\.png$', image or '')), 'specialty image stored with random name')
resp = client().open(BASE + f'media.php?kind=specialty&file={image}')
check(resp.status == 200 and resp.headers['Content-Type'] == 'image/png' and resp.read()[:4] == b'\x89PNG', 'specialty image served as PNG')
check(get(client(), 'media.php?kind=specialty&file=../../config.php')[0] == 404, 'media path traversal blocked')

# Public specialty list only includes specialties with visible doctors in the city.
status, r = api(client(), 'specialties')
ids = [s['id'] for s in r['data']]
check(used in ids and parent not in ids, 'only specialties with active doctors are listed')

# ---------- Doctor status, medical code, admin filters ----------
mc = scalar('SELECT medical_code FROM doctors WHERE id=?', [DOCTOR])
data = dict(name='پزشک تکراری', mobile='09125550001', medical_code='00' + mc, specialty_id=used, bio='', clinic_name='مطب', province_id=hormozgan, city_id=bastak, address='آدرس', phone='')
status, html = submit(admin, 'admin.php?tab=doctors', dict(data, action='create_doctor'))
check('قبلاً برای پزشک دیگری ثبت شده' in html, 'medical code duplicate detected despite leading zeros')
status, html = submit(admin, 'admin.php?tab=doctors', dict(data, action='create_doctor', medical_code='12'))
check('بین ۳ تا ۱۰ رقم' in html, 'medical code length validated')
status, html = get(admin, f'admin.php?tab=doctors&status=approved&specialty={used}&province={hormozgan}')
check(status == 200 and 'نتایج' in html, 'admin doctor filters')
status, html = get(admin, 'admin.php?tab=doctors&q=' + urllib.parse.quote(mc))
check('پروفایل و مطب‌ها' in html, 'admin search by medical code')

confirmed_before = scalar("SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.doctor_id=? AND b.status='confirmed'", [DOCTOR])
path = f'admin.php?tab=doctors&doctor={DOCTOR}'
status, html = submit(admin, path, dict(action='review', id=DOCTOR, status='suspended'))
check('علت' in html, 'suspension requires a reason')
save(admin, path, dict(action='review', id=DOCTOR, status='suspended', review_note='تعلیق آزمایشی'))
check(get(client(), f'doctor.php?id={DOCTOR}')[0] == 404, 'suspended doctor hidden publicly')
check(scalar("SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.doctor_id=? AND b.status='confirmed'", [DOCTOR]) == confirmed_before, 'suspension keeps confirmed bookings')
check(scalar('SELECT status_changed_at FROM doctors WHERE id=?', [DOCTOR]) is not None, 'status change time recorded')
save(admin, path, dict(action='review', id=DOCTOR, status='approved', active='1', review_note=''))
check(get(client(), f'doctor.php?id={DOCTOR}')[0] == 200, 'doctor visible again after approval')

# Clinic map position and public map links.
cl = db('SELECT c.*,ci.province_id FROM clinics c JOIN cities ci ON ci.id=c.city_id WHERE c.id=?', [CLINIC])[0]
save(admin, path, dict(action='clinic', id=CLINIC, doctor_id=DOCTOR, clinic_name=cl['name'], province_id=cl['province_id'], city_id=cl['city_id'], address=cl['address'], phone=cl['phone'], active='1', latitude='27.0832', longitude='54.2470'))
status, html = get(client(), f'doctor.php?id={DOCTOR}&clinic={CLINIC}')
check('data-map-view' in html and 'google.com/maps/search' in html, 'clinic map and navigation links shown')

# ---------- SMS settings, encryption, templates ----------
save(admin, 'admin.php?tab=sms', dict(action='sms_settings', api_key='Abc123Def456Ghi789Jkl0', template='loginTpl', daily_limit=500, otp_ttl_seconds=240, otp_resend_seconds=90, otp_max_attempts=4, otp_phone_hourly=5, otp_ip_15min=50, otp_ip_distinct_hourly=5, sms_max_attempts=3, enabled='1'))
stored = scalar("SELECT value FROM settings WHERE `key`='secret.kavenegar_api_key'")
check('Abc123Def456Ghi789Jkl0' not in stored and stored.startswith(('s1:', 'o1:')), 'API key encrypted in database')
check(php("echo secret_get('kavenegar_api_key');") == 'Abc123Def456Ghi789Jkl0', 'API key decrypts with app key')
status, html = get(admin, 'admin.php?tab=sms')
check('Abc123Def456Ghi789Jkl0' not in html and 'Jkl0' in html, 'admin sees only key hint')
save(admin, 'admin.php?tab=sms', dict(action='sms_settings', api_key='', template='loginTpl', daily_limit=500, otp_ttl_seconds=240, otp_resend_seconds=90, otp_max_attempts=4, otp_phone_hourly=5, otp_ip_15min=50, otp_ip_distinct_hourly=5, sms_max_attempts=3, enabled='1', dev_mode='1'))
check(php("echo secret_get('kavenegar_api_key');") == 'Abc123Def456Ghi789Jkl0', 'empty key field keeps stored key')
check(scalar("SELECT value FROM settings WHERE `key`='sms_dev_mode'") == '0', 'test mode cannot be enabled in production')
tpl = {f'template[{e}]': f'{e.replace("_", "")}Tpl' for e in ['login', 'booking_confirmed', 'booking_cancelled', 'booking_rescheduled', 'delay', 'subscription_expiring']}
tpl.update({f'enabled[{e}]': '1' for e in ['login', 'booking_confirmed', 'booking_cancelled', 'booking_rescheduled', 'delay', 'subscription_expiring']})
save(admin, 'admin.php?tab=sms', dict(action='sms_templates', **tpl))
check(scalar("SELECT COUNT(*) FROM sms_templates WHERE enabled=1") == 6, 'all event templates saved')
status, html = submit(admin, 'admin.php?tab=sms', dict(action='sms_templates', **{**tpl, 'template[login]': 'bad name'}))
check('انگلیسی' in html, 'invalid template name rejected')

# OTP uses the configured lifetime; the code is never written to the SMS log.
db('DELETE FROM auth_rate_limits')
out = json.loads(php('''$plain=null;$r=otp_request('09126660001',function($m,$c)use(&$plain){$plain=$c;return ['messageid'=>'OTP-LOG'];});echo json_encode([$r,$plain]);'''))
check(out[0]['expires_in'] == 240 and out[0]['resend_after'] == 90, 'OTP lifetime and resend from settings')
log = db("SELECT * FROM sms_messages WHERE provider_id='OTP-LOG'")[0]
check(log['event'] == 'login' and log['status'] == 'sent' and log['tokens'] is None, 'OTP logged without the code')
# Many unverified numbers from one network are blocked.
db('DELETE FROM auth_rate_limits')
blocked = php('''$n=0;for($i=10;$i<20;$i++){try{otp_request('091266600'.$i,fn($m,$c)=>['messageid'=>'X'.$i]);}catch(AuthError $e){if($e->status===429){echo $i;break;}}query('DELETE FROM auth_rate_limits');}''')
check(blocked != '' and int(blocked) <= 16, 'number enumeration from one IP blocked')
db("UPDATE otp_challenges SET ip_hash=NULL")

# Test SMS (CLI transport), account credit parsing, queue dedupe and retry.
out = php('''$_SESSION['admin_id']=1;$_SESSION['admin_login_at']=time();$r=sms_send_now('booking_confirmed','09126660002',sms_events()['booking_confirmed']['sample'],fn($p)=>['messageid'=>'T-'.$p['template'].'-'.$p['token10']]);echo $r['messageid'];''')
check(out.startswith('T-bookingconfirmedTpl-') and 'دکتر' in out, 'event SMS uses its template and Persian tokens')
out = json.loads(php('''echo json_encode(kavenegar_account(fn()=>['http'=>200,'body'=>json_encode(['return'=>['status'=>200],'entries'=>['remaincredit'=>125000,'expiredate'=>1893456000,'type'=>'master']]),'failed'=>false,'sent'=>true]));'''))
check(out['remaincredit'] == 125000, 'credit parsed from account/info')
check(php('''try{kavenegar_account(fn()=>['http'=>403,'body'=>json_encode(['return'=>['status'=>403]]),'failed'=>false,'sent'=>true]);}catch(AuthError $e){echo $e->getMessage();}''').find('کلید') >= 0, 'invalid key reported clearly')
status, html = get(admin, 'admin.php?tab=sms')
check('۱۲۵٬۰۰۰' in html, 'cached credit shown in admin')
first = php("var_export(sms_enqueue('booking_cancelled','09126660003',['token'=>'1405/07/30','token2'=>'10:00','token10'=>'دکتر'],'dedupe-test'));")
second = php("var_export(sms_enqueue('booking_cancelled','09126660003',['token'=>'1405/07/30','token2'=>'10:00','token10'=>'دکتر'],'dedupe-test'));")
check(first == 'true' and second == 'false', 'duplicate SMS event not queued twice')
php('''sms_dispatch(10,function($p){throw new RuntimeException('down');});''')
row = db("SELECT * FROM sms_messages WHERE dedupe_key='dedupe-test'")[0]
check(row['status'] == 'queued' and row['attempts'] == 1 and row['error'], 'failed send re-queued with back-off')
db("UPDATE sms_messages SET next_attempt_at=NOW() WHERE dedupe_key='dedupe-test'")
php('''sms_dispatch(10,fn($p)=>['messageid'=>'RETRY-OK']);''')
row = db("SELECT * FROM sms_messages WHERE dedupe_key='dedupe-test'")[0]
check(row['status'] == 'sent' and row['provider_id'] == 'RETRY-OK' and row['attempts'] == 2, 'retry succeeds and is logged')
check('دکتر' in row['tokens'] and ' ' not in json.loads(row['tokens'])['token'], 'tokens stored for transactional SMS, spaces removed from token1')

# Booking confirmation queues the patient SMS exactly once.
patient = client()
login_user(patient, '09126660010')
avail = api(client(), f'availability&clinic={CLINIC}&service={fixture["service"]}&date={fixture["day"]}')[1]['data']
if not avail:
    later = php('echo date("Y-m-d",strtotime("+9 days"));')
    avail = api(client(), f'availability&clinic={CLINIC}&service={fixture["service"]}&date={later}')[1]['data']
h = send(patient, 'booking/hold', dict(clinic_id=CLINIC, service_id=fixture['service'], starts_at=avail[-1]['starts_at'], name='بیمار پیامک'))
send(patient, 'booking/confirm', dict(id=h['id']))
send(patient, 'booking/confirm', dict(id=h['id']))
check(scalar("SELECT COUNT(*) FROM sms_messages WHERE booking_id=? AND event='booking_confirmed'", [h['id']]) == 1, 'confirmation SMS queued once')

# ---------- Cron ----------
check(get(client(), 'cron.php')[0] == 403, 'cron URL requires token')
check(get(client(), 'cron.php?token=wrong')[0] == 403, 'wrong cron token rejected')
cron_token = php('echo config()["cron_token"];')
status, text = get(client(), 'cron.php?token=' + cron_token)
check(status == 200 and 'sms_dispatch' in text and 'cleanup' in text, 'cron runs all tasks via URL')
status, html = get(admin, 'admin.php?tab=cron')
check('cron.php' in html and 'موفق' in html, 'admin cron page shows command and task status')
out = subprocess.check_output([PHP, str(ROOT / 'cron.php')]).decode()
check('expire_holds' in out, 'cron runs from CLI (cPanel php command)')
db("UPDATE doctors SET subscription_until=DATE_ADD(CURDATE(),INTERVAL 3 DAY) WHERE id=?", [DOCTOR])
db("UPDATE doctors SET mobile='09126660020' WHERE id=? AND mobile IS NULL", [DOCTOR])
php('require dirname($argv[1])."/lib/jobs.php";run_cron(["subscription_warnings"]);run_cron(["subscription_warnings"]);')
check(scalar("SELECT COUNT(*) FROM sms_messages WHERE event='subscription_expiring'") == 1, 'subscription warning sent once per period')
db("UPDATE doctors SET subscription_until='2099-01-01' WHERE id=?", [DOCTOR])

# ---------- Sessions ----------
u = client()
login_user(u, '09126660030')
other = client()
login_user(other, '09126660030')
send(u, 'auth/logout-all', {})
check(api(other, 'bookings')[0] == 401, 'logout from all devices revokes other sessions')

# Every admin section and public page renders.
some_user = scalar('SELECT MIN(id) FROM users')
for page in ['dashboard', 'doctors', f'doctors&doctor={DOCTOR}', 'specialties', f'specialties&edit={parent}', 'provinces',
             f'provinces&edit={hormozgan}', f'cities&q=' + urllib.parse.quote('بند'), 'users', f'users&user={some_user}',
             'settings', 'booking', 'sms', 'sms&status=sent', 'cron', 'audit']:
    status, html = get(admin, 'admin.php?tab=' + page)
    check(status == 200 and 'admin-shell' in html and 'Warning' not in html and 'Fatal' not in html, 'admin renders ' + page.split('&')[0])
for page in ['index.php', 'city.php', 'city.php?q=' + urllib.parse.quote('بندر'), f'city.php?province={hormozgan}', 'login.php']:
    status, html = get(client(), page)
    check(status == 200 and 'Warning' not in html, 'public renders ' + page)

print(f'SUCCESS {checks} v0.7 checks')
