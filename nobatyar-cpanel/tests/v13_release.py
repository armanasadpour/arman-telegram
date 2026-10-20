"""1.0.0 release checks over HTTP: security headers and CSP, no inline script on any page, session cookie
flags, locked installer, friendly error page with logged reference, PWA files (service worker version,
offline page, precached assets, install guide, bottom navigation), security page, database backup,
download, tamper detection and restore, and one complete patient journey from first sign-in to a paid,
confirmed booking with receipt. Bank calls use the CLI-only fake transport; no live payment or SMS."""
from http_helpers import *
import gzip, io

fixture = json.loads((ROOT.parent.parent / 'runtime' / 'phase6-fixture.json').read_text())
DOCTOR, CLINIC, SERVICE = fixture['doctor'], fixture['clinic'], fixture['service']
VERSION = re.search(r"APP_VERSION = '([^']+)'", (ROOT / 'lib' / 'version.php').read_text()).group(1)


def php(code, args=[]):
    cmd = [PHP] + (['-c', PHPINI] if PHPINI else []) + [
        '-r', 'require $argv[1];if(config()["database"]!=="nobatyar_test_http")exit(9);require_once dirname($argv[1])."/tests/gateway_support.php";' + code,
        str(ROOT / 'core.php')] + [str(x) for x in args]
    return subprocess.check_output(cmd).decode()


def scalar(sql, args=[]):
    rows = db(sql, args)
    return next(iter(rows[0].values())) if rows else None


def raw(c, path, data=None):
    try:
        r = c.open(BASE + path, urllib.parse.urlencode(data).encode() if data is not None else None)
    except urllib.error.HTTPError as e:
        r = e
    return r.status, r.headers, r.read()


def send(c, route, data, status=200):
    actual, r = api(c, route, data, api(c, 'session')[1]['data']['csrf'])
    check(actual == status, f'{route} -> {status}' + ('' if actual == status else f' got {actual} {r}'))
    return r.get('data', {})


def admin_login(email='admin@example.test', password='Test-password-012345'):
    c = client()
    submit(c, 'admin.php', dict(action='login', email=email, password=password))
    return c


def snippet(html):
    m = re.search(r'<p class="(?:error|notice)"[^>]*>(.*?)</p>', html, re.S)
    return re.sub(r'<[^>]+>', '', m.group(1)).strip()[:200] if m else ''


db('DELETE FROM request_limits')
db('DELETE FROM auth_rate_limits')
owner = admin_login()
patient = client()
login_user(patient, '09127775501')

# ---------- Headers, CSP and inline scripts ----------
status, headers, body = raw(client(), 'index.php')
csp = headers.get('Content-Security-Policy', '')
check("script-src 'self'" in csp and "'unsafe-inline'" not in csp.split('script-src')[1].split(';')[0], 'CSP allows scripts only from this site')
check("frame-ancestors 'self'" in csp and "object-src 'none'" in csp and "base-uri 'self'" in csp, 'CSP blocks framing, plugins and base hijack')
check(headers.get('X-Content-Type-Options') == 'nosniff' and headers.get('X-Frame-Options') == 'SAMEORIGIN' and headers.get('Cross-Origin-Opener-Policy') == 'same-origin', 'hardening headers present')
check('no-store' in headers.get('Cache-Control', ''), 'pages are not stored by browsers or proxies')
cookie = headers.get('Set-Cookie', '') or raw(client(), 'login.php')[1].get('Set-Cookie', '')
check('HttpOnly' in cookie and 'SameSite=Lax' in cookie, 'session cookie is HttpOnly and SameSite')
pages = [(client(), p) for p in ['index.php', 'centers.php', 'city.php', 'login.php', 'register.php', 'center-register.php', 'app-install.php', f'doctor.php?id={DOCTOR}']]
pages += [(patient, p) for p in ['patient.php', 'notifications.php', 'support.php', 'panel.php']]
pages += [(owner, f'admin.php?tab={t}') for t in ['dashboard', 'users', 'doctors', 'payments', 'gateways', 'sms', 'security', 'content', 'audit']]
for c, p in pages:
    status, headers, body = raw(c, p)
    html = body.decode()
    inline = re.findall(r'<script(?![^>]*\b(?:src=|type="application/ld\+json"))[^>]*>', html)
    handlers = re.findall(r'\son(?:click|load|error|submit|change|input|mouseover)\s*=', html, re.I)
    check(status == 200 and not inline and not handlers and 'javascript:' not in html, f'{p}: no inline script or handler')

# ---------- Installer locked, friendly errors ----------
status, headers, body = raw(client(), 'install.php')
check(status == 403 and 'قبلاً نصب شده' in body.decode(), 'installer refuses after installation')
boom = ROOT / '_test_boom.php'
boom.write_text("<?php require __DIR__.'/core.php'; throw new LogicException('boom password=hunter2 at layer');")
try:
    status, headers, body = raw(client(), '_test_boom.php')
finally:
    boom.unlink()
html = body.decode()
ref = re.search(r'کد پیگیری خطا: <bdi dir="ltr">([A-F0-9]{8})</bdi>', html)
check(status == 500 and ref and 'LogicException' not in html and 'hunter2' not in html and '.php' not in re.sub(r'href="[^"]+"', '', html), 'uncaught error shows a reference without details')
logs = ''.join(p.read_text() for p in (ROOT / 'private' / 'logs').glob('error-*.log'))
check(ref.group(1) in logs and 'LogicException' in logs, 'error is logged with the same reference')

# ---------- PWA ----------
sw = (ROOT / 'sw.js').read_text()
check(f"const VERSION = '{VERSION}';" in sw, 'service worker version matches the release')
check(f'style.css?v={VERSION}' in (ROOT / 'offline.html').read_text(), 'offline page uses the release stylesheet')
pre = re.findall(r"^  '([^']+)'", sw.split('const PRECACHE = [')[1].split('];')[0], re.M)
pre = [p.replace("' + VERSION", '').replace('?v=', '?v=' + VERSION) if '?v=' in p else p for p in pre]
check(len(pre) >= 10 and all(not p.endswith('.php') for p in pre), 'precache list holds only static files')
for p in ['offline.html', 'manifest.webmanifest', 'assets/icons.svg', 'assets/fonts/regular.woff2', 'assets/icons/icon-192.png']:
    check(raw(client(), p)[0] == 200, f'precached file served: {p}')
manifest = json.loads(raw(client(), 'manifest.webmanifest')[2])
check(manifest['dir'] == 'rtl' and manifest['lang'] == 'fa' and manifest['display'] == 'standalone' and any(i.get('purpose') == 'maskable' for i in manifest['icons']) and len(manifest['shortcuts']) == 3, 'manifest is RTL, installable, with maskable icon and shortcuts')
status, headers, body = raw(client(), 'app-install.php')
html = body.decode()
check('data-install hidden' in html and 'Add to Home Screen' in html and 'اندروید' in html, 'install guide with browser-driven install button')
check('class="bottom-nav"' in html and 'href="login.php" data-auth-open' in html, 'guests get the phone bottom navigation (account entry opens sign-in)')
html = raw(patient, 'patient.php')[2].decode()
check('class="bottom-nav"' in html and 'href="notifications.php"' in html and 'aria-current="page"' in html.split('class="bottom-nav"')[1], 'signed-in bottom navigation marks the current page')
check('class="bottom-nav"' not in raw(owner, 'admin.php')[2].decode(), 'admin panel keeps its own navigation')
check('data-net-status hidden' in html, 'offline notice placeholder present')

# ---------- Security page and backups ----------
status, headers, body = raw(owner, 'admin.php?tab=security')
html = body.decode()
check(status == 200 and 'بررسی امنیتی هاست' in html and 'کلید رمزنگاری' in html and 'پشتیبان دیتابیس' in html, 'security page lists host checks and backups')
secret = php('echo secret_get("kavenegar_api_key") ?? "";').strip()
check(not secret or secret not in html, 'security page never prints stored secrets')
status, html = submit(owner, 'admin.php?tab=security', dict(action='backup_create'))
check('پشتیبان ساخته شد' in html, 'owner creates a backup')
name = re.search(r'(nobatyar-\d{8}-\d{6}-manual-[a-f0-9]{8}\.sql\.gz)', html).group(1)
status, headers, body = raw(owner, f'admin.php?tab=security&download={name}')
dump = gzip.decompress(body).decode()
check(status == 200 and dump.startswith('-- nobatyar-backup 1 schema=') and dump.rstrip().endswith('-- end') and 'INSERT INTO `users`' in dump, 'backup downloads as complete gzip SQL')
check('no-store' in headers.get('Cache-Control', '') and 'attachment' in headers.get('Content-Disposition', ''), 'backup download is not cached')
check(all(l == '' or l.startswith('-- ') or l.endswith(';') for l in dump.split('\n')), 'one complete statement per line')
check(raw(client(), f'admin.php?tab=security&download={name}')[2].decode().count('name="password"') >= 1, 'guests cannot download backups')
db("INSERT INTO admins(email,password_hash,name,role) VALUES('ops@example.test',?,'مدیر اجرایی','manager')", [php("echo password_hash('Ops-password-2026',PASSWORD_DEFAULT);")])
manager = admin_login('ops@example.test', 'Ops-password-2026')
status, headers, body = raw(manager, 'admin.php?tab=security')
check(status == 200 and 'ساخت پشتیبان اکنون' not in body.decode(), 'manager sees checks but no backup controls')
status, headers, body = raw(manager, f'admin.php?tab=security&download={name}')
check(status == 403 and not body.startswith(b'\x1f\x8b'), 'manager cannot download a backup')
status, html = submit(manager, 'admin.php?tab=security', dict(action='backup_create'))
check('مجاز نیست' in html, 'manager cannot create a backup')

marker = 'بنر پس از پشتیبان'
db("INSERT INTO site_banners(title,active,sort_order) VALUES(?,1,99)", [marker])
status, html = submit(owner, 'admin.php?tab=security', dict(action='backup_restore', name=name, password='wrong-password', confirm='بازیابی'))
check('رمز عبور مالک' in html and scalar('SELECT COUNT(*) FROM site_banners WHERE title=?', [marker]) == 1, 'restore needs the owner password')
status, html = submit(owner, 'admin.php?tab=security', dict(action='backup_restore', name=name, password='Test-password-012345', confirm='بله'))
check('«بازیابی»' in html, 'restore needs the typed confirmation')

# Tampered and foreign files are refused before any data changes.
bad = gzip.compress((dump.replace('-- end', 'GRANT ALL ON *.* TO evil;\n-- end')).encode())
boundary = '----nobatyar' + 'x' * 8


def upload(c, filename, content):
    parts = [f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n' for k, v in dict(csrf=token(c, 'admin.php?tab=security'), action='backup_upload').items()]
    payload = ''.join(parts).encode() + f'--{boundary}\r\nContent-Disposition: form-data; name="backup"; filename="{filename}"\r\nContent-Type: application/gzip\r\n\r\n'.encode() + content + f'\r\n--{boundary}--\r\n'.encode()
    req = urllib.request.Request(BASE + 'admin.php?tab=security', payload, {'Content-Type': f'multipart/form-data; boundary={boundary}'})
    try:
        r = c.open(req)
    except urllib.error.HTTPError as e:
        r = e
    return r.read().decode()


html = upload(owner, 'notes.sql.gz', gzip.compress(b'DROP DATABASE nobatyar;\n'))
check('پشتیبان نوبت‌یار نیست' in html, 'foreign file rejected on upload')
html = upload(owner, 'evil.sql.gz', bad)
check('بارگذاری شد' in html, 'well-formed upload stored')
evil = re.search(r'(nobatyar-\d{8}-\d{6}-uploaded-[a-f0-9]{8}\.sql\.gz)', html).group(1)
status, html = submit(owner, 'admin.php?tab=security', dict(action='backup_restore', name=evil, password='Test-password-012345', confirm='بازیابی'))
check('دستکاری شده' in html and scalar('SELECT COUNT(*) FROM site_banners WHERE title=?', [marker]) == 1, 'tampered backup refused without changing data')
check(scalar("SELECT COUNT(*) FROM admins WHERE email='ops@example.test'") == 1, 'data intact after refused restore')

status, html = submit(owner, 'admin.php?tab=security', dict(action='backup_restore', name=name, password='Test-password-012345', confirm='بازیابی'))
check('بازیابی انجام شد' in html, 'owner restores the backup')
check(scalar('SELECT COUNT(*) FROM site_banners WHERE title=?', [marker]) == 0 and scalar("SELECT COUNT(*) FROM admins WHERE email='ops@example.test'") == 0, 'database returned to the backup state')
check('name="password"' in raw(owner, 'admin.php?tab=dashboard')[2].decode(), 'administrators sign in again after restore')
check('id' not in api(patient, 'session')[1]['data'], 'users sign in again after restore')
owner = admin_login()
html = raw(owner, 'admin.php?tab=security')[2].decode()
check('prerestore' in html and 'پیش از بازیابی' in html, 'safety backup made before restore')
check(scalar("SELECT COUNT(*) FROM audit_log WHERE action='backup.restore'") >= 1, 'restore recorded in the activity log')
php("query(\"UPDATE settings SET value='1' WHERE `key`='backup_auto'\");")
out = php('require_once dirname($argv[1])."/lib/jobs.php"; echo backup_auto(); echo "|"; echo backup_auto();')
check(out.startswith('پشتیبان ساخته شد') and out.endswith('قبلاً ساخته شده است.'), 'automatic backup runs once a day')
php("query(\"UPDATE settings SET value='0' WHERE `key`='backup_auto'\");")

# ---------- Complete patient journey ----------
db('DELETE FROM request_limits')
php("test_gateway('zarinpal',['merchant_id'=>'11111111-1111-1111-1111-111111111111']);")
db("UPDATE settings SET value='1' WHERE `key`='booking_fee_enabled'")
db("INSERT INTO settings(`key`,value) VALUES('booking_fee','4000') ON DUPLICATE KEY UPDATE value='4000'")
db("DELETE FROM booking_fee_rules")
journey = client()
mobile = '09127775599'
check(not db('SELECT id FROM users WHERE mobile=?', [mobile]), 'journey starts with a new mobile number')
uid = login_user(journey, mobile)
check(scalar("SELECT COUNT(*) FROM user_roles WHERE user_id=? AND role='patient'", [uid]) == 1, 'first sign-in creates a patient account')
doctor_name = scalar('SELECT name FROM doctors WHERE id=?', [DOCTOR])
status, html = get(journey, 'index.php?scope=all&q=' + urllib.parse.quote(doctor_name.split()[-1]))
check(f'doctor.php?id={DOCTOR}' in html, 'patient finds the doctor in search')
status, html = get(journey, f'doctor.php?id={DOCTOR}')
check(status == 200 and doctor_name in html, 'doctor page opens')
slot = None
for days in range(3, 60):
    d = php(f'echo date("Y-m-d",strtotime("+{days} days"));')
    avail = api(client(), f'availability&clinic={CLINIC}&service={SERVICE}&date={d}')[1]['data']
    if avail:
        slot = avail[-1]['starts_at']
        break
h = send(journey, 'booking/hold', dict(clinic_id=CLINIC, service_id=SERVICE, starts_at=slot, name='بیمار مسیر کامل'))
b = db('SELECT * FROM bookings WHERE id=?', [h['id']])[0]
check(b['status'] == 'held' and int(b['fee_toman']) == 4000, 'hold reserves the time with the booking fee')
status, html = get(journey, f'checkout.php?id={h["id"]}')
check('۴٬۰۰۰' in html and 'پرداخت در مطب' in html and 'پرداخت و ثبت نوبت' in html, 'checkout shows the fee and that the visit fee is paid at the clinic')
LOGIN = """$u=query('SELECT * FROM users WHERE id=?',[(int)$argv[2]])->fetch();$_SESSION['user_id']=$u['id'];$_SESSION['user_version']=$u['session_version'];$_SESSION['login_at']=time();"""
ZP = """zp(function($op,$p)use($argv){if($op==='request'){if($p['amount']!==40000)throw new RuntimeException('amount '.$p['amount']);return ['data'=>['code'=>100,'authority'=>$argv[4]]];}return ['data'=>['code'=>100,'ref_id'=>987654,'card_pan'=>'6037****1234']];})"""
authority = 'A' + '7' * 35
url = php(LOGIN + f'echo start_payment((int)$argv[3],{ZP})["url"];', [uid, h['id'], authority])
check(url.endswith('/StartPay/' + authority), 'payment goes to the bank for 40,000 Rial')
t = json.loads(php(f'echo json_encode(test_return($argv[4],["Authority"=>$argv[4],"Status"=>"OK"],{ZP}));', [uid, h['id'], authority]))
check(t['status'] == 'verified' and db('SELECT status FROM bookings WHERE id=?', [h['id']])[0]['status'] == 'confirmed', 'verified payment confirms the booking')
status, html = get(journey, f'pay-return.php?tx={t["id"]}')
check('پرداخت موفق بود' in html and 'نوبت شما قطعی شد' in html, 'return page confirms')
status, html = get(journey, f'receipt.php?id={h["id"]}')
check('۹۸۷۶۵۴' in html and 'بیمار مسیر کامل' in html, 'receipt shows bank reference and patient')
status, html = get(journey, 'patient.php')
check('بیمار مسیر کامل' in html or doctor_name in html, 'booking listed in the patient panel')
check(scalar("SELECT COUNT(*) FROM notifications WHERE user_id=?", [uid]) >= 1, 'patient notified in the app')
check(scalar("SELECT COUNT(*) FROM sms_messages WHERE booking_id=? AND event='booking_confirmed'", [h['id']]) <= 1, 'at most one confirmation SMS queued')
replay = json.loads(php(f'echo json_encode(test_return($argv[4],["Authority"=>$argv[4],"Status"=>"OK"],{ZP}));', [uid, h['id'], authority]))
check(replay['id'] == t['id'] and scalar('SELECT COUNT(*) FROM payments WHERE booking_id=?', [h['id']]) == 1, 'repeated bank callback does not pay twice')

# The helper fixture for the browser release test.
sid = lambda c: next(x.value for hd in c.handlers if isinstance(hd, urllib.request.HTTPCookieProcessor) for x in hd.cookiejar if x.name == 'PHPSESSID')
(ROOT.parent.parent / 'runtime' / 'release-fixture.json').write_text(json.dumps(dict(patient=sid(journey), booking=h['id'], doctor=DOCTOR)))
import http_helpers
print(f"SUCCESS {http_helpers.checks} release checks")
