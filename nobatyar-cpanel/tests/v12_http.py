"""0.12.0 — administration over HTTP: dashboard with Jalali ranges and Excel exports, administrator roles
(section and action permissions, masking, session revocation, last-owner protection, own password),
activity log filters and export, support tickets end to end, banners and homepage content, general
settings, platform announcements. No live SMS, payment or push request."""
from http_helpers import *
import zipfile, io

PASSWORD = 'Strong-pass-2026'


def fa(v):
    return str(v).translate(str.maketrans('0123456789', '۰۱۲۳۴۵۶۷۸۹'))


def clean(html):
    return 'Warning:' not in html and 'Fatal error' not in html and 'Notice:' not in html


def snippet(html):
    m = re.search(r'<p class="(?:error|notice)"[^>]*>(.*?)</p>', html, re.S)
    return re.sub(r'<[^>]+>', '', m.group(1)).strip()[:200] if m else ''


def admin_login(email, password):
    c = client()
    status, html = submit(c, 'admin.php', dict(action='login', email=email, password=password))
    return c, html


def act(c, tab, data, expect, label, path=None):
    status, html = post(c, path or f'admin.php?tab={tab}', dict(data, csrf=token(c, f'admin.php?tab={tab}')))
    ok = expect in html
    check(ok, label + ('' if ok else ' :: ' + snippet(html)))
    return html


def raw(c, path):
    try:
        r = c.open(BASE + path)
    except urllib.error.HTTPError as e:
        r = e
    return r.status, r.headers.get('Content-Type', ''), r.read()


owner, _ = admin_login('admin@example.test', 'Test-password-012345')
db("DELETE FROM auth_rate_limits")

# ---------- Dashboard ----------
for q in ['', '&range=today', '&range=7d', '&range=30d', '&range=month', '&range=year', '&from=1404/01/01&to=1405/12/29', '&from=bad&to=worse']:
    status, html = get(owner, 'admin.php?tab=dashboard' + q)
    check(status == 200 and clean(html) and 'class="chart"' in html, 'dashboard renders ' + (q or 'default'))
status, html = get(owner, 'admin.php?tab=dashboard&range=year')
check('درآمد سال جاری' in html and 'روند درآمد' in html and 'رشد کاربران' in html and 'آمار استان‌ها' in html, 'dashboard sections for owner')
check('ماهانه (ماه شمسی)' in html, 'year range uses Jalali months')
check(re.search(r'data-tip="[^"]+"', html) is not None and 'نمایش جدول' in html, 'charts have tooltips and a table view')
patients = db("SELECT COUNT(*) n FROM user_roles WHERE role='patient'")[0]['n']
check(fa(f'{patients:,}'.replace(',', '٬')) in html, 'patient count shown')
status, ctype, body = raw(owner, 'admin.php?tab=dashboard&range=year&export=xlsx')
check(status == 200 and 'spreadsheetml' in ctype and zipfile.ZipFile(io.BytesIO(body)).testzip() is None, 'trend Excel export is a valid workbook')
sheet = zipfile.ZipFile(io.BytesIO(body)).read('xl/worksheets/sheet1.xml').decode()
check('درآمد کارمزد رزرو' in sheet and 'rightToLeft="1"' in sheet, 'trend export has RTL headers')
status, ctype, body = raw(owner, 'admin.php?tab=dashboard&range=year&export=geo')
check(status == 200 and 'استان' in zipfile.ZipFile(io.BytesIO(body)).read('xl/worksheets/sheet1.xml').decode(), 'province Excel export')
check(db("SELECT COUNT(*) n FROM audit_log WHERE action='export.dashboard'")[0]['n'] >= 2, 'exports logged')

# ---------- Administrator accounts and roles ----------
act(owner, 'admins', dict(action='admin_save', name='کوتاه', email='weak@example.test', mobile='09121000001', password='short1', role='support'), 'حداقل ۱۲', 'weak admin password rejected')
act(owner, 'admins', dict(action='admin_save', name='نقش', email='x@example.test', mobile='09121000002', password=PASSWORD, role='superuser'), 'نقش انتخاب‌شده معتبر نیست', 'unknown role rejected')
for role in ['manager', 'finance', 'support', 'content', 'viewer']:
    act(owner, 'admins', dict(action='admin_save', name='مدیر ' + role, email=f'{role}@example.test', mobile='0912100' + str(2000 + ['manager', 'finance', 'support', 'content', 'viewer'].index(role)), password=PASSWORD, role=role), 'مدیر جدید ساخته شد', f'{role} admin created')
act(owner, 'admins', dict(action='admin_save', name='تکراری', email='VIEWER@example.test', mobile='09121009999', password=PASSWORD, role='viewer'), 'قبلاً ثبت شده', 'duplicate admin email rejected')
ids = {r['email'].split('@')[0]: r['id'] for r in db('SELECT id,email FROM admins')}

viewer, html = admin_login('viewer@example.test', PASSWORD)
status, html = get(viewer, 'admin.php')
check(status == 200 and 'رشد کاربران' in html and 'روند درآمد' not in html and 'درآمد امروز' not in html, 'viewer sees dashboard without revenue')
check('tab=doctors"' not in html and 'tab=gateways' not in html and 'tab=admins' not in html, 'viewer navigation shows only allowed sections')
for tab in ['doctors', 'users', 'payments', 'gateways', 'admins', 'audit', 'general', 'support']:
    status, html = get(viewer, f'admin.php?tab={tab}')
    check(status == 403 and 'دسترسی به این بخش ندارید' in html, f'viewer blocked from {tab}')
status, html = post(viewer, 'admin.php?tab=general', dict(csrf=token(viewer, 'admin.php'), action='general_settings', site_name='هک', ticket_max_open='3'))
check('برای نقش شما مجاز نیست' in html and db("SELECT value FROM settings WHERE `key`='site_name'")[0]['value'] != 'هک', 'viewer cannot change settings')
check(db("SELECT COUNT(*) n FROM audit_log WHERE action='admin.denied' AND detail='general_settings'")[0]['n'] == 1, 'denied action logged')
status, ctype, body = raw(viewer, 'admin.php?tab=dashboard&export=xlsx')
check(status == 403 and 'spreadsheetml' not in ctype, 'viewer cannot export')
status, html = post(viewer, 'admin.php', dict(csrf=token(viewer, 'admin.php'), action='gateway_test', gateway='zarinpal'))
check('دسترسی' in html and status in (200, 403), 'viewer cannot start a gateway test payment')

support, _ = admin_login('support@example.test', PASSWORD)
mobile = db("SELECT mobile FROM users WHERE mobile LIKE '09%' ORDER BY id LIMIT 1")[0]['mobile']
status, html = get(support, 'admin.php?tab=users')
check(status == 200 and fa(mobile) not in html and fa(mobile[:4]) + '•••' + fa(mobile[-4:]) in html, 'support role sees masked mobiles')
status, html = get(owner, 'admin.php?tab=users')
check(fa(mobile) in html, 'owner sees full mobiles')
doctor = db("SELECT id,mobile,name,medical_code,specialty_id,bio FROM doctors WHERE mobile IS NOT NULL AND mobile<>'' ORDER BY id LIMIT 1")[0]
status, html = get(support, f'admin.php?tab=doctors&doctor={doctor["id"]}')
check(status == 200 and fa(doctor['mobile']) not in html and f'value="{doctor["mobile"]}"' not in html, 'doctor edit form hides mobile from support role')
act(support, 'doctors', dict(action='edit_doctor', id=doctor['id'], name=doctor['name'], mobile='09120000000', medical_code=doctor['medical_code'] or '12345', specialty_id=doctor['specialty_id'] or '', bio=doctor['bio'] or ''), 'تغییرات ذخیره شد', 'support can edit doctor details', f'admin.php?tab=doctors&doctor={doctor["id"]}')
check(db('SELECT mobile FROM doctors WHERE id=?', [doctor['id']])[0]['mobile'] == doctor['mobile'], 'support role cannot change the doctor mobile')
status, html = get(support, 'admin.php?tab=payments')
check(status == 403, 'support blocked from finance')

finance, _ = admin_login('finance@example.test', PASSWORD)
status, html = get(finance, 'admin.php?tab=payments')
check(status == 200 and clean(html), 'finance opens transactions')
status, ctype, body = raw(finance, 'admin.php?tab=payments&export=xlsx')
check(status == 200 and 'spreadsheetml' in ctype, 'finance exports transactions')
for tab in ['gateways', 'admins', 'doctors']:
    check(get(finance, f'admin.php?tab={tab}')[0] == 403, f'finance blocked from {tab}')

manager, _ = admin_login('manager@example.test', PASSWORD)
check(get(manager, 'admin.php?tab=gateways')[0] == 403 and get(manager, 'admin.php?tab=general')[0] == 200, 'manager: no gateways, has system settings')
status, html = post(manager, 'admin.php?tab=admins', dict(csrf=token(manager, 'admin.php'), action='admin_status', id=ids['viewer'], active='0'))
check('برای نقش شما مجاز نیست' in html, 'manager cannot disable administrators')

# Role change and disabling sign the account out everywhere.
act(owner, 'admins', dict(action='admin_save', id=ids['support'], name='پشتیبان', role='content'), 'ذخیره شد', 'owner changes support role', f'admin.php?tab=admins&admin={ids["support"]}')
status, html = get(support, 'admin.php?tab=dashboard')
check('ورود مدیرکل' in html or 'name="password"' in html, 'role change revokes existing admin session')
act(owner, 'admins', dict(action='admin_status', id=ids['viewer'], active='0'), 'غیرفعال شد', 'owner disables viewer')
check('name="password"' in get(viewer, 'admin.php')[1], 'disabled admin session ended')
c, html = admin_login('viewer@example.test', PASSWORD)
check('غیرفعال است' in html, 'disabled admin cannot sign in')
act(owner, 'admins', dict(action='admin_status', id=ids['admin'], active='0'), 'خودتان', 'owner cannot disable own account')
act(owner, 'admins', dict(action='admin_save', id=ids['admin'], name='مالک', role='manager'), 'حداقل یک مالک', 'last owner cannot be demoted', f'admin.php?tab=admins&admin={ids["admin"]}')
act(owner, 'admins', dict(action='admin_reset_password', id=ids['finance'], password='New-finance-pass-1'), 'رمز عبور تغییر کرد', 'owner resets finance password', f'admin.php?tab=admins&admin={ids["finance"]}')
check('name="password"' in get(finance, 'admin.php')[1], 'password reset ends old sessions')
finance, html = admin_login('finance@example.test', 'New-finance-pass-1')
check('داشبورد' in get(finance, 'admin.php')[1], 'finance signs in with new password')
act(finance, 'account', dict(action='admin_password', current='wrong-password-1', password='Another-pass-2026'), 'رمز عبور فعلی اشتباه', 'own password change needs the current password')
act(finance, 'account', dict(action='admin_password', current='New-finance-pass-1', password='Another-pass-2026'), 'رمز عبور شما تغییر کرد', 'admin changes own password')
check('داشبورد' in get(finance, 'admin.php')[1], 'own session survives own password change')

# Upgrade page accepts only owners.
c = client()
status, html = post(c, 'upgrade.php', dict(csrf=token(c, 'upgrade.php'), email='manager@example.test', password=PASSWORD, backup='1'))
check('مالک سامانه' in html, 'upgrade restricted to owner accounts')

# ---------- Activity log ----------
status, html = get(owner, 'admin.php?tab=audit&actor=admins&action_prefix=admin.')
check(status == 200 and 'ساخت حساب مدیر' in html and 'غیرفعال‌سازی حساب مدیر' in html, 'activity log filtered to admin account events')
status, html = get(owner, f'admin.php?tab=audit&actor={ids["viewer"]}')
check('تلاش برای دسترسی غیرمجاز مدیر' in html, 'activity log filtered by administrator')
check('127.0.0.1' in html, 'owner sees request IP')
status, ctype, body = raw(owner, 'admin.php?tab=audit&action_prefix=admin.&export=xlsx')
check('spreadsheetml' in ctype and 'ساخت حساب مدیر' in zipfile.ZipFile(io.BytesIO(body)).read('xl/worksheets/sheet1.xml').decode(), 'activity log Excel export')
check(db("SELECT COUNT(*) n FROM audit_log WHERE action='admin.create' AND ip='127.0.0.1'")[0]['n'] == 5, 'audit rows keep IP')

# ---------- Support tickets ----------
user = client()
uid = login_user(user, '09127771201')
other = client()
login_user(other, '09127771202')
status, html = get(client(), 'support.php')
check('login.php' in html or 'ورود' in html, 'support page requires sign-in')
status, html = post(user, 'support.php', dict(csrf=token(user, 'support.php'), action='ticket_new', category='payment', subject='پرداخت', body='کوتاه'))
check('بین ۱۰ تا ۳۰۰۰' in html, 'too-short ticket rejected')
status, html = post(user, 'support.php', dict(csrf=token(user, 'support.php'), action='ticket_new', category='payment', subject='کسر وجه بدون رزرو', body='مبلغ کارمزد کسر شد ولی نوبت ثبت نشد. کد رهگیری ۱۲۳۴'))
tid = db('SELECT id FROM support_tickets WHERE user_id=? ORDER BY id DESC LIMIT 1', [uid])[0]['id']
check('تیکت ثبت شد' in html and 'کسر وجه بدون رزرو' in html, 'user opens a ticket')
check(get(other, f'support.php?id={tid}')[0] == 404, 'another user cannot read the ticket')
status, html = post(other, 'support.php', dict(csrf=token(other, 'support.php'), action='ticket_reply', id=tid, body='تلاش برای پاسخ به تیکت دیگران'))
check(db('SELECT COUNT(*) n FROM support_messages WHERE ticket_id=?', [tid])[0]['n'] == 1, 'another user cannot reply')
for i in range(2):
    post(user, 'support.php', dict(csrf=token(user, 'support.php'), action='ticket_new', category='general', subject=f'موضوع آزمون {i}', body='متن آزمایشی برای سقف تیکت باز'))
status, html = post(user, 'support.php', dict(csrf=token(user, 'support.php'), action='ticket_new', category='general', subject='تیکت چهارم', body='متن آزمایشی برای سقف تیکت باز'))
check('تیکت‌های باز قبلی' in html, 'open-ticket limit enforced')
status, html = get(owner, 'admin.php?tab=support')
check(f'ticket={tid}' in html and 'کسر وجه بدون رزرو' in html, 'ticket listed for support team')
status, html = get(owner, f'admin.php?tab=support&q={tid}')
check(f'ticket={tid}' in html, 'ticket found by number')
act(owner, 'support', dict(action='ticket_reply', ticket_id=tid, body='بررسی شد؛ مبلغ تا ۷۲ ساعت به کارت شما برمی‌گردد.'), 'پاسخ ثبت شد', 'support replies', f'admin.php?tab=support&ticket={tid}')
check(db('SELECT status,last_reply_by FROM support_tickets WHERE id=?', [tid])[0] == {'status': 'answered', 'last_reply_by': 'admin'}, 'ticket answered')
note = db("SELECT url FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 1", [uid])[0]
check(note['url'] == f'support.php?id={tid}', 'user notified with ticket link')
status, html = get(user, f'support.php?id={tid}')
check('۷۲ ساعت' in html and 'پشتیبانی' in html, 'user reads the reply')
act(owner, 'support', dict(action='ticket_update', ticket_id=tid, status='open', priority='high', assigned_admin_id=ids['manager']), 'تیکت به‌روز شد', 'ticket priority and assignee', f'admin.php?tab=support&ticket={tid}')
act(owner, 'support', dict(action='ticket_update', ticket_id=tid, status='nope', priority='high'), 'معتبر نیست', 'invalid ticket status rejected', f'admin.php?tab=support&ticket={tid}')
status, html = post(user, 'support.php', dict(csrf=token(user, f'support.php?id={tid}'), action='ticket_close', id=tid))
check(db('SELECT status FROM support_tickets WHERE id=?', [tid])[0]['status'] == 'closed', 'user closes the ticket')
post(user, 'support.php', dict(csrf=token(user, f'support.php?id={tid}'), action='ticket_reply', id=tid, body='دوباره مشکل پیش آمد، لطفاً بررسی کنید.'))
check(db('SELECT status FROM support_tickets WHERE id=?', [tid])[0]['status'] == 'open', 'user reply reopens a closed ticket')
status, html = get(user, 'support.php?id=99999999')
check(status == 404, 'missing ticket 404')

# ---------- Banners, homepage content, general settings ----------
contentc, _ = admin_login('support@example.test', PASSWORD)  # now in the content role
act(contentc, 'content', dict(action='home_content', home_notice='سامانه جمعه ساعت ۲ بامداد به‌روزرسانی می‌شود.'), 'تغییرات ذخیره شد', 'content role saves the homepage notice')
# Since 1.0.0-rc.2 the hero texts live in the homepage layout (tests/v14_home.py covers draft and publishing).
db("INSERT INTO settings(`key`,value) VALUES('home_layout',?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [json.dumps({'sections': {'hero': {'title': 'نوبت آنلاین در هرمزگان', 'subtitle': 'بدون صف و تماس تلفنی'}}}, ensure_ascii=False)])
act(contentc, 'content', dict(action='banner_save', title='بنر جاوااسکریپت', link_url='javascript:alert(1)', active='1'), 'پیوند باید', 'banner rejects unsafe link')
act(contentc, 'content', dict(action='banner_save', title='مراکز درمانی تازه', subtitle='کلینیک‌های شهر خود را ببینید', link_url='centers.php', link_label='مشاهده مراکز', active='1'), 'بنر ذخیره شد', 'content role creates a banner')
act(contentc, 'content', dict(action='banner_save', title='بنر آینده', active='1', starts_on='1499/01/01'), 'بنر ذخیره شد', 'scheduled banner created')
act(contentc, 'content', dict(action='banner_save', title='بنر بازه غلط', active='1', starts_on='1405/05/10', ends_on='1405/05/01'), 'بعد از تاریخ شروع', 'banner end before start rejected')
status, html = get(client(), 'index.php')
check('نوبت آنلاین در هرمزگان' in html and 'بدون صف و تماس تلفنی' in html and 'ساعت ۲ بامداد' in html, 'homepage shows edited hero and notice')
check('مراکز درمانی تازه' in html and 'href="centers.php"' in html and 'بنر آینده' not in html, 'homepage shows active banner, hides scheduled one')
first = db("SELECT id FROM site_banners ORDER BY sort_order LIMIT 1")[0]['id']
act(contentc, 'content', dict(action='banner_move', id=first, direction='down'), 'تغییرات ذخیره شد', 'banner reordered')
check(db("SELECT id FROM site_banners ORDER BY sort_order LIMIT 1")[0]['id'] != first, 'banner order changed')
act(contentc, 'content', dict(action='banner_delete', id=first), 'تغییرات ذخیره شد', 'banner deleted')
check(get(contentc, 'admin.php?tab=general')[0] == 403, 'content role has no system settings')
act(owner, 'general', dict(action='general_settings', site_name='نوبت‌یار هرمزگان', support_phone='076-33334444', support_email='bad', ticket_max_open='3'), 'ایمیل پشتیبانی معتبر نیست', 'invalid support email rejected')
act(owner, 'general', dict(action='general_settings', site_name='نوبت‌یار هرمزگان', support_phone='076-33334444', support_email='help@example.test', support_hours='شنبه تا چهارشنبه ۹ تا ۱۷', footer_text='پاسخ‌گویی در ساعات اداری', ticket_max_open='3'), 'تغییرات ذخیره شد', 'general settings saved')
status, html = get(client(), 'centers.php')
check('<title>' in html and 'نوبت‌یار هرمزگان</title>' in html and 'help@example.test' in html and fa('076-33334444') in html, 'site name and support contacts on public pages')
db("UPDATE settings SET value='نوبت‌یار' WHERE `key`='site_name'")

# ---------- Announcements ----------
act(owner, 'announcements', dict(action='announce_create', audience='everyone', title='x', body='y'), 'گروه گیرندگان', 'unknown audience rejected')
patients_active = db("SELECT COUNT(*) n FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role='patient' WHERE u.active=1")[0]['n']
act(owner, 'announcements', dict(action='announce_create', audience='patients', title='ساعات کاری نوروز', body='در تعطیلات نوروز پشتیبانی تا ساعت ۱۴ پاسخ می‌دهد.', url='support.php'), 'اطلاعیه برای', 'announcement sent to patients')
check(db("SELECT COUNT(*) n FROM notifications WHERE title='ساعات کاری نوروز' AND category='announcement'")[0]['n'] == patients_active, 'every active patient got the announcement once')
doctors_only = db("SELECT COUNT(*) n FROM notifications n JOIN user_roles r ON r.user_id=n.user_id AND r.role='patient' WHERE n.title='ساعات کاری نوروز'")[0]['n']
check(doctors_only == patients_active, 'announcement went only to the patient audience')
check(db("SELECT COUNT(*) n FROM sms_messages WHERE created_at>=DATE_SUB(NOW(),INTERVAL 1 MINUTE) AND event LIKE 'announ%'")[0]['n'] == 0, 'announcements never send SMS')
status, html = get(owner, 'admin.php?tab=announcements')
check('ساعات کاری نوروز' in html and 'ارسال شد' in html, 'announcement history listed')
status, html = get(user, 'notifications.php?category=announcement')
check('ساعات کاری نوروز' in html, 'patient sees announcement in notification center')

# ---------- Specialties and services overview, all sections render for the owner ----------
for tab in ['dashboard', 'users', 'admins', 'doctors', 'centers', 'specialties', 'booking', 'provinces', 'cities', 'subscriptions', 'settings', 'payments', 'gateways', 'support', 'announcements', 'notifications', 'sms', 'content', 'general', 'cron', 'audit', 'account']:
    status, html = get(owner, f'admin.php?tab={tab}')
    check(status == 200 and clean(html), f'owner opens {tab}')
check('خدمات تعریف‌شده پزشکان' in get(owner, 'admin.php?tab=specialties')[1], 'services overview present')

# Local-only fixture for the browser test; never packaged.
sid = lambda c: next(x.value for h in c.handlers if isinstance(h, urllib.request.HTTPCookieProcessor) for x in h.cookiejar if x.name == 'PHPSESSID')
(ROOT.parent.parent / 'runtime' / 'admin-fixture.json').write_text(json.dumps(dict(user=sid(user), ticket=tid, owner=sid(owner))))
import http_helpers
print(f"SUCCESS {http_helpers.checks} v0.12 checks")
