"""0.11.0 — notifications and queues over HTTP: notification center and bell, delivery preferences and
consent, push subscription API, visit reminders (thresholds, dedupe, opt-out), waitlist (join, FIFO
batches with cooldown, fulfilment, exit), reception queue numbers, public queue display without personal
data, approximate visit times, delay SMS to all, admin settings and cron. No live SMS or push request."""
from http_helpers import *

fixture = json.loads((ROOT.parent.parent / 'runtime' / 'phase6-fixture.json').read_text())


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


def fa(v):
    return str(v).translate(str.maketrans('0123456789', '۰۱۲۳۴۵۶۷۸۹'))


def clean(html):
    return 'Warning' not in html and 'Fatal error' not in html and 'Notice:' not in html


def snippet(html):
    m = re.search(r'<p class="(?:error|notice)"[^>]*>(.*?)</p>', html, re.S)
    return re.sub(r'<[^>]+>', '', m.group(1)).strip()[:200] if m else ''


def form(c, path, data, expect, label):
    status, html = post(c, path, dict(data, csrf=token(c, 'panel.php')))
    ok = expect in html
    check(ok, label + ('' if ok else ' :: ' + snippet(html)))
    return html


def admin_client():
    c = client()
    submit(c, 'admin.php', dict(action='login', email='admin@example.test', password='Test-password-012345'))
    return c


admin = admin_client()
db('DELETE FROM request_limits')
db("UPDATE settings SET value='0' WHERE `key`='booking_fee_enabled'")
db("UPDATE settings SET value='30' WHERE `key`='max_active_bookings'")
db("UPDATE settings SET value='1' WHERE `key`='sms_enabled'")
for event, tpl in [('booking_reminder', 'Reminder'), ('waitlist_available', 'WaitOpen'), ('visit_time_changed', 'NewTime'), ('delay', 'DelayTemplate')]:
    db("INSERT INTO sms_templates(event,template,enabled) VALUES(?,?,1) ON DUPLICATE KEY UPDATE template=VALUES(template),enabled=1", [event, tpl])

# A dedicated doctor whose clinic is open every day, so the test never depends on the weekday.
city = scalar('SELECT city_id FROM clinics WHERE id=?', [fixture['clinic']])
province = scalar('SELECT province_id FROM cities WHERE id=?', [city])
spec = scalar('SELECT specialty_id FROM doctors WHERE id=?', [fixture['doctor']])
D = int(php("echo register_doctor(['name'=>'دکتر صف آزمون','mobile'=>'09121110001','medical_code'=>'55443322','specialty_id'=>(int)$argv[2],'bio'=>'','clinic_name'=>'مطب صف','province_id'=>(int)$argv[3],'city_id'=>(int)$argv[4],'address'=>'خیابان صف','phone'=>''],true);", [spec, province, city]).strip())
db("UPDATE doctors SET review_status='approved',active=1,subscription_until='2099-01-01' WHERE id=?", [D])
C = scalar('SELECT id FROM clinics WHERE doctor_id=?', [D])
db('UPDATE clinics SET default_duration=15,gap_minutes=0,daily_capacity=200,slot_capacity=1 WHERE id=?', [C])
for w in range(7):
    db("INSERT INTO working_hours(clinic_id,weekday,opens_at,closes_at) VALUES(?,?,'00:00','23:59')", [C, w])
db("INSERT INTO doctor_services(doctor_id,name,duration_minutes,active) VALUES(?,'ویزیت',0,1)", [D])
S = scalar('SELECT id FROM doctor_services WHERE doctor_id=?', [D])
sec = client()
sec_id = login_user(sec, '09127770003')
db("INSERT INTO staff_scopes(user_id,clinic_id,role,can_manage_schedule,can_manage_bookings,can_view_history) VALUES(?,?,'secretary',1,1,1)", [sec_id, C])
db("INSERT IGNORE INTO user_roles(user_id,role) VALUES(?,'secretary')", [sec_id])
pa = client()
pa_id = login_user(pa, '09121110010')
pb = client()
pb_id = login_user(pb, '09121110011')
pc = client()
pc_id = login_user(pc, '09121110012')
for c_, n in [(pa, 'بیمار الف'), (pb, 'بیمار ب'), (pc, 'بیمار ج')]:
    form(c_, 'panel.php?role=patient', dict(action='profile', name=n), 'ذخیره شد', 'patient name saved ' + n)


def slots(day):
    return api(client(), f'availability&clinic={C}&service={S}&date={day}')[1]['data']


def book(c, starts_at, name):
    h = send(c, 'booking/hold', dict(clinic_id=C, service_id=S, starts_at=starts_at, name=name))
    send(c, 'booking/confirm', dict(id=h['id']))
    return h['id']


today = php('echo date("Y-m-d");')
# Today and tomorrow together, so the suite also runs late at night (the clinic is open around the clock).
soon = php('echo date("Y-m-d H:i:s",time()+600);')
now_slots = [s for s in slots(today) + slots(php('echo date("Y-m-d",strtotime("+1 day"));')) if s['starts_at'] > soon]
check(len(now_slots) >= 4, 'test clinic has free times in the next hours')

# ---------- Notification center and preferences ----------
status, html = get(pa, 'notifications.php')
check(status == 200 and clean(html) and 'اعلان مرورگر' in html and 'تنظیمات دریافت' in html, 'notification center renders')
check(get(client(), 'notifications.php')[0] == 200 and 'ورود' in get(client(), 'notifications.php')[1], 'notification center requires sign-in')
b1 = book(pa, now_slots[0]['starts_at'], 'بیمار الف')
status, html = get(pa, 'index.php')
check('bell-count' in html, 'header bell shows unread count')
check(api(pa, 'notifications/count')[1]['data']['unread'] >= 1, 'unread count API')
status, html = get(pa, 'notifications.php?category=booking')
check('نوبت قطعی شد' in html and f'receipt.php?id={b1}' in html, 'booking notice listed with link')
post(pa, 'notifications.php', dict(action='read_all', csrf=token(pa, 'notifications.php')))
check(api(pa, 'notifications/count')[1]['data']['unread'] == 0, 'all notifications marked read')
form(pa, 'notifications.php', dict(action='preferences', push_booking='1', push_reminder='1', sms_delay='1', sms_waitlist='1'), 'تنظیمات اعلان ذخیره شد', 'preferences saved')
prefs = db('SELECT * FROM notification_preferences WHERE user_id=?', [pa_id])[0]
check(prefs['sms_reminder'] == 0 and prefs['sms_delay'] == 1 and prefs['sms_consent_at'] is not None, 'SMS opt-out stored with consent time')
# Push subscription API (keys from a simulated browser).
key = api(pa, 'push/key')[1]['data']
check(key['available'] and len(key['key']) == 87, 'public VAPID key exposed for subscription')
ua = json.loads(php("[$pem,$pub]=p256_generate();echo json_encode(['p256dh'=>b64url_encode($pub),'auth'=>b64url_encode(random_bytes(16))]);"))
sub = dict(endpoint='https://push.example.test/send/' + os.urandom(8).hex(), keys=ua)
send(pa, 'push/subscribe', sub, 422)
send(pa, 'push/subscribe', dict(sub, consent=True))
check(scalar('SELECT COUNT(*) FROM push_subscriptions WHERE user_id=? AND active=1', [pa_id]) == 1, 'browser subscription stored')
status, html = get(pa, 'notifications.php')
check('دستگاه فعال' in html, 'active device listed')
check(api(client(), 'push/subscribe', dict(sub, consent=True))[0] in (401, 419), 'subscription requires a session')

# ---------- Visit reminders ----------
db("DELETE FROM sms_messages WHERE event='booking_reminder'")
db('UPDATE bookings SET confirmed_at=DATE_SUB(NOW(),INTERVAL 2 HOUR) WHERE id=?', [b1])
tomorrow = php('echo date("Y-m-d",strtotime("+1 day"));')
lo, hi = php('echo date("Y-m-d H:i:s",time()+4*3600);'), php('echo date("Y-m-d H:i:s",time()+20*3600);')
late = [s for s in slots(today) + slots(tomorrow) if lo < s['starts_at'] < hi]
check(len(late) > 0, 'a time inside the 24h window exists')
b2 = book(pb, late[0]['starts_at'], 'بیمار ب')
db('UPDATE bookings SET confirmed_at=DATE_SUB(NOW(),INTERVAL 2 HOUR) WHERE id=?', [b2])
b3 = book(pc, now_slots[2]['starts_at'], 'بیمار ج')
sent = int(php('echo send_visit_reminders();'))
check(sent >= 1, 'reminders sent')
check(scalar("SELECT COUNT(*) FROM sms_messages WHERE event='booking_reminder' AND booking_id=?", [b2]) == 1, '24h reminder SMS for booking within a day')
check(scalar("SELECT COUNT(*) FROM sms_messages WHERE event='booking_reminder' AND booking_id=?", [b1]) == 0, 'opted-out patient gets no reminder SMS')
check(scalar("SELECT COUNT(*) FROM notifications WHERE booking_id=? AND category='reminder'", [b1]) == 1, 'opted-out patient still gets in-app reminder')
check(scalar("SELECT COUNT(*) FROM push_messages m JOIN push_subscriptions s ON s.id=m.subscription_id WHERE s.user_id=?", [pa_id]) >= 1, 'reminder queued as browser push')
check(scalar("SELECT COUNT(*) FROM notifications WHERE booking_id=? AND category='reminder'", [b3]) == 0, 'just-confirmed booking gets no reminder')
php('send_visit_reminders();')
check(scalar("SELECT COUNT(*) FROM sms_messages WHERE event='booking_reminder' AND booking_id=?", [b2]) == 1, 'reminder never sent twice')
check(scalar("SELECT COUNT(*) FROM notice_markers WHERE dedupe_key=?", [f'reminder:{b1}:24']) == 1, 'passed larger threshold marked handled')
tokens = json.loads(scalar("SELECT tokens FROM sms_messages WHERE event='booking_reminder' AND booking_id=?", [b2]))
check(tokens['token10'] == 'دکتر صف آزمون' and 'مطب صف' in tokens['token20'], 'reminder tokens carry doctor and clinic')
# rc.20: the reminder SMS is a Pro feature by default; on the free plan the patient still gets the in-app reminder.
rdoc = scalar('SELECT s.doctor_id FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE b.id=?', [b2])
rdoc_until = scalar('SELECT subscription_until FROM doctors WHERE id=?', [rdoc])
db("DELETE FROM sms_messages WHERE event='booking_reminder' AND booking_id=?", [b2])
db("DELETE FROM notifications WHERE booking_id=? AND category='reminder'", [b2])
db("DELETE FROM notice_markers WHERE dedupe_key LIKE ?", [f'reminder:{b2}:%'])
db('UPDATE doctors SET subscription_until=NULL WHERE id=?', [rdoc])
php('send_visit_reminders();')
check(scalar("SELECT COUNT(*) FROM sms_messages WHERE event='booking_reminder' AND booking_id=?", [b2]) == 0 and scalar("SELECT COUNT(*) FROM notifications WHERE booking_id=? AND category='reminder'", [b2]) == 1, 'free plan: in-app reminder only, no reminder SMS (Pro)')
db('UPDATE doctors SET subscription_until=? WHERE id=?', [rdoc_until, rdoc])

# ---------- Waitlist ----------
db('UPDATE clinics SET daily_capacity=1 WHERE id=?', [C])
target = php('echo date("Y-m-d",strtotime("+3 days"));')
jt = php(f'echo jdate("{target}");')
first = slots(target)[0]['starts_at']
bw = book(pa, first, 'بیمار الف')
check(slots(target) == [], 'day full after one booking (daily capacity 1)')
status, html = get(pb, f'doctor.php?id={D}&clinic={C}&date={urllib.parse.quote(jt)}')
check('خبرم کن' in html and 'ثبت در صف انتظار' in html, 'doctor page offers the waitlist when full')
status, html = get(client(), f'doctor.php?id={D}&clinic={C}&date={urllib.parse.quote(jt)}')
check('ورود و ثبت در صف انتظار' in html, 'guests are asked to sign in')
too_long = php(f'echo jdate(date("Y-m-d",strtotime("{target} +30 days")));')
form(pb, f'doctor.php?id={D}&clinic={C}', dict(action='waitlist', date_from=jt, date_to=too_long, family_member_id='0'), 'حداکثر', 'waitlist range limited')
form(pb, f'doctor.php?id={D}&clinic={C}', dict(action='waitlist', date_from=jt, date_to=jt, family_member_id='0'), 'در صف انتظار ثبت شدید', 'patient B joins waitlist')
form(pb, f'doctor.php?id={D}&clinic={C}', dict(action='waitlist', date_from=jt, date_to=jt, family_member_id='0'), 'قبلاً', 'duplicate waitlist entry rejected')
form(pc, f'doctor.php?id={D}&clinic={C}', dict(action='waitlist', date_from=jt, date_to=jt, family_member_id='0'), 'در صف انتظار ثبت شدید', 'patient C joins after B')
WB = scalar("SELECT id FROM waitlist_entries WHERE user_id=? AND status='waiting'", [pb_id])
WC = scalar("SELECT id FROM waitlist_entries WHERE user_id=? AND status='waiting'", [pc_id])
r = json.loads(php('echo json_encode(waitlist_process());'))
check(r['notified'] == 0, 'nobody notified while the day is full')
db("UPDATE settings SET value='1' WHERE `key`='waitlist_batch'")
send(pa, 'patient/change', dict(id=bw, revision=scalar('SELECT revision FROM bookings WHERE id=?', [bw]), operation='cancelled', reason='انصراف'))
import time
time.sleep(1)
check(scalar('SELECT notify_count FROM waitlist_entries WHERE id=?', [WB]) == 1 and scalar('SELECT notify_count FROM waitlist_entries WHERE id=?', [WC]) == 0, 'cancellation immediately offers the time to the earliest entry (batch of 1)')
note = db("SELECT * FROM notifications WHERE user_id=? AND category='waitlist' ORDER BY id DESC LIMIT 1", [pb_id])[0]
check('دکتر صف آزمون' in note['title'] and f'doctor.php?id={D}' in note['url'], 'waitlist notice links to the doctor page')
check(scalar("SELECT COUNT(*) FROM sms_messages WHERE event='waitlist_available' AND user_id=?", [pb_id]) == 1, 'waitlist SMS queued')
r = json.loads(php('echo json_encode(waitlist_process());'))
check(r['notified'] == 1 and scalar('SELECT notify_count FROM waitlist_entries WHERE id=?', [WC]) == 1 and scalar('SELECT notify_count FROM waitlist_entries WHERE id=?', [WB]) == 1, 'cooldown passes the next run to the following entry')
r = json.loads(php('echo json_encode(waitlist_process());'))
check(r['notified'] == 0, 'both in cooldown: no repeated messages')
book(pb, slots(target)[0]['starts_at'], 'بیمار ب')
r = json.loads(php('echo json_encode(waitlist_process());'))
check(scalar('SELECT status FROM waitlist_entries WHERE id=?', [WB]) == 'booked', 'entry fulfilled after booking')
status, html = get(pc, 'patient.php?tab=waitlist')
check(status == 200 and clean(html) and 'دکتر صف آزمون' in html and 'آخرین اطلاع‌رسانی' in html, 'patient sees waitlist entry')
form(pc, 'patient.php?tab=waitlist', dict(action='waitlist_cancel', id=WC), 'تغییرات ثبت شد', 'patient leaves waitlist')
check(scalar('SELECT status FROM waitlist_entries WHERE id=?', [WC]) == 'cancelled', 'entry cancelled')
db('UPDATE waitlist_entries SET date_to=DATE_SUB(CURDATE(),INTERVAL 1 DAY) WHERE id=?', [WC])
db("UPDATE waitlist_entries SET status='waiting' WHERE id=?", [WC])
php('waitlist_process();')
check(scalar('SELECT status FROM waitlist_entries WHERE id=?', [WC]) == 'expired', 'past entries expire')
db('UPDATE clinics SET daily_capacity=200 WHERE id=?', [C])

# ---------- Reception queue ----------
qb = [b1, b3]
for i, b in enumerate(qb):
    send(sec, 'reception/change', dict(id=b, revision=scalar('SELECT revision FROM bookings WHERE id=?', [b]), operation='arrived', reason='حضور'))
check([scalar('SELECT queue_number FROM bookings WHERE id=?', [b]) for b in qb] == [1, 2], 'arrivals get sequential numbers')
send(sec, 'reception/change', dict(id=b1, revision=scalar('SELECT revision FROM bookings WHERE id=?', [b1]), operation='arrived', reason='تکرار'), 409)
check(scalar('SELECT queue_number FROM bookings WHERE id=?', [b1]) == 1, 'number not reissued')
status, html = get(pc, 'patient.php')
check('شماره نوبت شما ۲' in html and 'نفر جلوتر' in html, 'patient sees own number and people ahead')
status, html = get(sec, f'secretary.php?clinic={C}&tab=queue')
check(status == 200 and clean(html) and 'فراخوان نفر بعدی' in html and 'queue.php?t=' in html, 'secretary queue tab')
token1 = re.search(r'queue\.php\?t=([a-f0-9]{32})', html).group(1)
form(sec, f'secretary.php?clinic={C}&tab=queue', dict(action='call_next'), 'شماره ۱ به اتاق پزشک فراخوانده شد', 'call next')
check(scalar('SELECT attendance_state FROM bookings WHERE id=?', [b1]) == 'in_visit' and scalar('SELECT current_number FROM clinic_queues WHERE clinic_id=?', [C]) == 1, 'called booking in visit and current number set')
status, html = get(client(), f'queue.php?t={token1}')
board = html.split('<main')[-1]
check(status == 200 and '۱' in board and 'بیمار الف' not in board and 'بیمار ج' not in board and '0912' not in board and '۰۹۱۲' not in board, 'public display shows numbers without names or phones')
check('دکتر صف آزمون' in board and 'شماره‌های منتظر (۱)' in board, 'display shows doctor and waiting count')
status, html = get(pa, 'patient.php')
check('نوبت شماست' in html, 'called patient told to go in')
form(sec, f'secretary.php?clinic={C}&tab=queue', dict(action='queue_token'), 'نشانی جدید', 'display address renewed')
check(get(client(), f'queue.php?t={token1}')[0] == 404, 'old display address stops working')
check(get(client(), 'queue.php?t=../../config')[0] == 404, 'invalid display token rejected')

# ---------- Approximate time and delay ----------
db("DELETE FROM sms_messages WHERE event IN ('visit_time_changed','delay')")
# Patient B already has the 24h-window booking; one booking per doctor per day, so use another day.
s4 = next(x for x in now_slots[3:] if x['starts_at'][:10] != late[0]['starts_at'][:10] and x['starts_at'][11:16] < '23:00')
b4 = book(pb, s4['starts_at'], 'بیمار ب')
eta = php(f'echo date("H:i",strtotime("{s4["starts_at"]}")+40*60);')
form(sec, f'secretary.php?clinic={C}&tab=queue', dict(action='estimate', id=b4, estimated_time='99:99', reason='x'), 'به شکل', 'invalid approximate time rejected')
form(sec, f'secretary.php?clinic={C}&tab=queue', dict(action='estimate', id=b4, estimated_time=eta, reason='طولانی شدن ویزیت قبلی'), 'زمان تقریبی ثبت', 'approximate time set')
check(str(scalar('SELECT estimated_at FROM bookings WHERE id=?', [b4])).endswith(eta + ':00'), 'estimate stored')
check(scalar("SELECT COUNT(*) FROM notifications WHERE booking_id=? AND user_id=? AND category='delay'", [b4, pb_id]) == 1, 'patient notified in delay category')
check(scalar("SELECT COUNT(*) FROM sms_messages WHERE event='visit_time_changed' AND booking_id=?", [b4]) == 1, 'approximate time SMS queued')
status, html = get(pb, 'patient.php')
check('زمان تقریبی مراجعه' in html and fa(eta) in html, 'patient panel shows approximate time')
status, html = get(pb, f'receipt.php?id={b4}')
check(fa(eta) in html, 'receipt shows approximate time')
end = php('echo jdate(date("Y-m-d H:i:s",time()+86000),true);')
start = php('echo jdate(date("Y-m-d H:i:s",time()+60),true);')
# 1.0.27: only the platform administrator messages patients; the secretary delay broadcast is gone.
send(sec, 'reception/delay', dict(clinic_id=C, starts_at=start, ends_at=end, minutes=20, reason='جلسه اضطراری', send_sms=1), 404)
check(scalar("SELECT COUNT(*) FROM sms_messages WHERE event='delay' AND booking_id=?", [b2]) == 0, 'secretary cannot send delay SMS to patients')
status, html = get(sec, f'secretary.php?clinic={C}&tab=notifications')
check(status == 200 and clean(html), 'secretary notifications tab')

# ---------- Admin and cron ----------
status, html = get(admin, 'admin.php?tab=notifications')
check(status == 200 and clean(html) and 'کلید عمومی' in html and 'یادآوری' in html, 'admin notification settings page')
status, html = submit(admin, 'admin.php?tab=notifications', dict(action='notify_settings', reminder_hours='۴۸,۲۴,۲,۱,۳', reminder_sms_enabled='1', push_enabled='1', push_contact='ops@example.test', waitlist_enabled='1', waitlist_max_days='14', waitlist_cooldown_minutes='120', waitlist_batch='3', waitlist_max_entries='5'))
check('حداکثر چهار' in html, 'at most four reminder times')
status, html = submit(admin, 'admin.php?tab=notifications', dict(action='notify_settings', reminder_hours='۴۸,۲', reminder_sms_enabled='1', push_enabled='1', push_contact='ops@example.test', waitlist_enabled='1', waitlist_max_days='14', waitlist_cooldown_minutes='120', waitlist_batch='3', waitlist_max_entries='5'))
check(scalar("SELECT value FROM settings WHERE `key`='reminder_hours'") == '48,2' and scalar("SELECT value FROM settings WHERE `key`='push_contact'") == 'ops@example.test', 'reminder hours and VAPID contact saved')
db("UPDATE settings SET value='24,2' WHERE `key`='reminder_hours'")
status, html = get(admin, 'admin.php?tab=sms')
check('یادآوری پیش از نوبت' in html and 'زمان آزاد برای صف انتظار' in html and 'تغییر زمان تقریبی مراجعه' in html, 'new SMS templates manageable in admin')
cron_token = php('echo config()["cron_token"];')
status, text = get(client(), 'cron.php?token=' + cron_token)
check(status == 200 and 'reminders' in text and 'waitlist' in text and 'push_dispatch' in text, 'cron runs reminders, waitlist and push')
status, html = get(admin, 'admin.php?tab=cron')
check('یادآوری پیش از نوبت' in html and 'ارسال اعلان‌های مرورگر' in html, 'cron page lists new tasks')
db("UPDATE settings SET value='0' WHERE `key`='sms_enabled'")
db("UPDATE settings SET value='5' WHERE `key`='max_active_bookings'")
sid = lambda c: next(x.value for h in c.handlers if isinstance(h, urllib.request.HTTPCookieProcessor) for x in h.cookiejar if x.name == 'PHPSESSID')
(ROOT.parent.parent / 'runtime' / 'queue-fixture.json').write_text(json.dumps(dict(clinic=C, doctor=D, token=scalar('SELECT queue_token FROM clinics WHERE id=?', [C]), patient=sid(pb), secretary=sid(sec), day=jt)))
import http_helpers
print(f'SUCCESS {http_helpers.checks} v0.11 checks')
