"""1.0.0-rc.5 doctor profile and booking steps over HTTP: profile sections and booking card, time picker
(day strip counts equal real availability, partial times, empty day with the next free day), clinic notes
escaped, visit fee as information only, patient details asked once (national-ID modes, encrypted and
masked), patient choice required, family member added, hold → payment step (countdown, invoice without
the visit fee, only usable gateways, no fake success without a gateway), free confirmation → success
receipt, expired hold, admin booking-page settings and gateway logo permissions.
Writes runtime/booking-fixture.json for browser_booking.cjs."""
from http_helpers import *

fx = json.loads((ROOT.parent.parent / 'runtime' / 'phase4-fixture.json').read_text())
DOCTOR, CLINIC, SERVICE = fx['doctor'], fx['clinic'], fx['service']


def php(code, args=[]):
    return subprocess.check_output([PHP] + (['-c', PHPINI] if PHPINI else []) + ['-r', 'require $argv[1];if(config()["database"]!=="nobatyar_test_http")exit(9);require_once dirname($argv[1])."/tests/gateway_support.php";' + code, str(ROOT / 'core.php')] + [str(a) for a in args]).decode()


def scalar(sql, args=[]):
    rows = db(sql, args)
    return next(iter(rows[0].values())) if rows else None


def clean(html):
    return 'Warning:' not in html and 'Fatal error' not in html and 'Notice:' not in html and 'Deprecated:' not in html


def sid(c):
    jar = next(h.cookiejar for h in c.handlers if isinstance(h, urllib.request.HTTPCookieProcessor))
    return next(x.value for x in jar if x.name == 'PHPSESSID')


def admin_login():
    c = client()
    submit(c, 'admin.php', dict(action='login', email='admin@example.test', password='Test-password-012345'))
    return c


def setting_set(k, v):
    db("INSERT INTO settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [k, v])


def first_free():
    return json.loads(php(f"$o=null;for($i=0;$i<14&&!$o;$i++){{$a=availability({CLINIC},{SERVICE},date('Y-m-d',strtotime('+'.$i.' day')));if($a)$o=$a[0]['starts_at'];}}echo json_encode($o);"))


db('DELETE FROM request_limits')
db('DELETE FROM auth_rate_limits')
db("UPDATE settings SET value='30' WHERE `key`='max_active_bookings'")
db('DELETE FROM booking_fee_rules WHERE scope=? AND target_id=?', ['doctor', DOCTOR])
db("UPDATE booking_fee_rules SET active=0 WHERE scope='city'")
setting_set('booking_fee_enabled', '0')
setting_set('booking_fee', '0')
setting_set('invoice_vat_percent', '0')  # rc.17: VAT on the booking fee is covered by v28_revenue.py
setting_set('patient_id_mode', 'required')
setting_set('booking_profile_fields', '1')
setting_set('booking_days', '14')
guest = client()

# ---------- Profile ----------
status, page = get(guest, f'doctor.php?id={DOCTOR}')
check(status == 200 and clean(page), 'profile page renders')
for anchor in ['#about', '#services', '#clinics', '#reviews', '#faq']:
    check(f'href="{anchor}"' in page and f'id="{anchor[1:]}"' in page, 'profile tab with its section: ' + anchor)
check('class="book-card"' in page and 'دریافت نوبت حضوری' in page and 'مشاهده نوبت‌های آزاد' in page, 'booking card with the free-times button')
check('class="book-bar"' in page, 'mobile booking bar')
check('data-share' in page, 'share button (shown by script)')
check('"@type":"Physician"' in page.replace(' ', ''), 'Physician structured data')
check('<script>' not in page, 'no inline script')

# Visit fee: information only (paid at the clinic).
db('UPDATE doctor_services SET visit_fee_toman=350000 WHERE id=?', [SERVICE])
status, page = get(guest, f'doctor.php?id={DOCTOR}')
check('ویزیت ۳۵۰٬۰۰۰ تومان (در مطب)' in page and 'هزینه ویزیت فقط در مطب پرداخت می‌شود' in page, 'visit fee shown as information, paid at the clinic')

# Clinic notes and documents written by the doctor; HTML is escaped.
php("clinic_set_notes((int)$argv[2],['notes'=>\"پذیرش از ساعت ۸ صبح.\\n<script>alert(1)</script>\",'required_docs'=>'دفترچه بیمه']);", [CLINIC])
check(scalar('SELECT required_docs FROM clinics WHERE id=?', [CLINIC]) == 'دفترچه بیمه', 'clinic documents saved')
try:
    php("clinic_set_notes((int)$argv[2],['notes'=>str_repeat('ا',2001)]);", [CLINIC])
    check(False, 'over-long clinic notes rejected')
except subprocess.CalledProcessError:
    check(True, 'over-long clinic notes rejected')
status, page = get(guest, f'doctor.php?id={DOCTOR}')
check('دفترچه بیمه' in page, 'documents to bring shown in the profile questions')

# ---------- Time picker ----------
status, tp = get(guest, f'doctor.php?id={DOCTOR}&clinic={CLINIC}&times=1')
check(status == 200 and clean(tp) and 'data-times-page' in tp and 'noindex' in tp, 'time picker renders (not indexed)')
check('class="tp-notes" data-clamp' in tp and 'پذیرش از ساعت' in tp and '&lt;script&gt;' in tp and '<script>alert' not in tp, 'clinic notes on the time picker, escaped')
cards = re.findall(r'class="day-card state-(\w+)[^"]*" href="[^"]*" data-day-link data-date="([\d-]+)"[^>]*>.*?<small class="dc-count">([^<]*)</small>', tp, re.S)
check(len(cards) == 14, 'day strip has the configured 14 days')
real = json.loads(php(f"$o=[];for($i=0;$i<14;$i++){{$d=date('Y-m-d',strtotime('+'.$i.' day'));$o[$d]=count(availability({CLINIC},{SERVICE},$d));}}echo json_encode($o);"))
ok = True
for state, day, label in cards:
    n = real[day]
    if state == 'free':
        ok = ok and n > 0 and label == php('echo fa_number($argv[2]);', [n]) + ' نوبت'
    else:
        ok = ok and n == 0
check(ok, 'every day card shows the real number of free times')
check(cards[0][1] == php("echo date('Y-m-d');").strip() and 'is-today' in tp, 'first card is today')
free_day = next((d for s, d, l in cards if s == 'free'), None)
empty_day = next((d for s, d, l in cards if s != 'free' and d > php("echo date('Y-m-d');").strip()), None)
check(free_day is not None, 'test clinic has a free day')
jfree = php(f"echo jdate('{free_day}');").strip()
status, part = get(guest, f'doctor.php?id={DOCTOR}&clinic={CLINIC}&service={SERVICE}&date=' + urllib.parse.quote(jfree) + '&partial=times')
check(status == 200 and part.lstrip().startswith('<div class="tp-times"') and '<html' not in part and part.count('class="time-option"') == real[free_day], 'partial times: only the chosen day, one link per free time')
check('data-auth-required' in part, 'guest time links ask to sign in')
if empty_day:
    jempty = php(f"echo jdate('{empty_day}');").strip()
    status, html = get(guest, f'doctor.php?id={DOCTOR}&clinic={CLINIC}&service={SERVICE}&date=' + urllib.parse.quote(jempty))
    nxt = next((d for s, d, l in cards if s == 'free' and d > empty_day), None)
    check('برای این روز نوبت آزادی وجود ندارد.' in html, 'empty day message')
    if nxt:
        check('برو به اولین نوبت آزاد بعدی' in html and f'data-date="{nxt}"' in html.split('class="tp-empty"')[1], 'next free day button points to the next free day')
setting_set('booking_days', '7')
status, html = get(guest, f'doctor.php?id={DOCTOR}&clinic={CLINIC}&times=1')
check(html.count('class="day-card') == 7, 'strip length follows the admin setting')
setting_set('booking_days', '99')
status, html = get(guest, f'doctor.php?id={DOCTOR}&clinic={CLINIC}&times=1')
check(html.count('class="day-card') == 14, 'invalid strip length falls back to 14 days')
setting_set('booking_days', '14')

# ---------- Patient step ----------
p = client()
uid = login_user(p, '09127771701')
db('UPDATE users SET birth_year=NULL,birth_date=NULL,insurance=NULL,gender=NULL,city_id=NULL,national_id_enc=NULL,id_type=NULL WHERE id=?', [uid])
at = first_free()
book = f'service-book.php?clinic={CLINIC}&service={SERVICE}&move=0&at=' + urllib.parse.quote(at)
# Since rc.11 the national ID is asked of every patient by default; "optional" is the only other mode.
setting_set('patient_id_mode', 'optional')
status, html = get(p, book)
check(status == 200 and clean(html) and 'class="stepper"' in html and 'step-current' in html, 'patient step with the stepper')
check('تکمیل اطلاعات' in html and 'name="birth_y"' in html and 'name="insurance"' in html and 'name="national_id"' in html and 'data-who-form' not in html, 'missing details asked first (national ID optional in this mode)')
check('id="pay-form"' not in html, 'no payment before choosing the patient')
city = int(scalar('SELECT id FROM cities LIMIT 1'))
province = int(scalar('SELECT province_id FROM cities WHERE id=?', [city]))
prof = dict(action='profile', first_name='مریم', last_name='رضایی', birth_d='۱', birth_m='۶', birth_y='۱۳۶۵', gender='female', insurance='tamin', province_id=province, city_id=city)
status, html = submit(p, book, dict(prof, birth_y='۱۲۰۰'))
check('تاریخ تولد معتبر نیست' in html and scalar('SELECT birth_year FROM users WHERE id=?', [uid]) is None, 'invalid birth year rejected with a clear message')
status, html = submit(p, book, dict(prof, gender='x'))
check('جنسیت را انتخاب کنید' in html, 'gender required')
status, html = submit(p, book, prof)
row = db('SELECT name,birth_year,birth_date,gender,city_id FROM users WHERE id=?', [uid])[0]
check(row == dict(name='مریم رضایی', birth_year=1365, birth_date='1986-08-23', gender='female', city_id=city), 'profile saved once (1 Shahrivar 1365)')
check('data-who-form' in html and 'تکمیل اطلاعات' not in html and 'مریم رضایی' in html, 'next visit goes straight to the patient choice')

# National ID: required (default), checksum, encryption and masking.
setting_set('patient_id_mode', 'required')
status, html = get(p, book)
check('تکمیل اطلاعات' in html and 'name="national_id"' in html and 'name="foreign"' in html, 'required national ID asked (with the foreign-national option)')
status, html = submit(p, book, dict(prof, national_id='0012345678'))
check('کد ملی معتبر نیست' in html and scalar('SELECT national_id_enc FROM users WHERE id=?', [uid]) is None, 'invalid national ID checksum rejected')
status, html = submit(p, book, dict(prof, national_id='۰۰۱۲۳۴۵۶۷۹'))
enc = scalar('SELECT national_id_enc FROM users WHERE id=?', [uid])
check(enc and '0012345679' not in enc and scalar('SELECT id_type FROM users WHERE id=?', [uid]) == 'national', 'valid national ID stored encrypted, never in plain text')
check(php('echo decrypt_secret($argv[2]);', [enc]) == '0012345679', 'encrypted value readable only by the application key')
check('0012345679' not in html and '۰۰۱۲۳۴۵۶۷۹' not in html and '۵۶۷۹' in html, 'only the last four digits are shown')
status, html = submit(p, book, dict(prof, national_id='', foreign=''))
check(scalar('SELECT national_id_enc FROM users WHERE id=?', [uid]) == enc, 'empty field keeps the saved identity')
status, html = submit(p, book, dict(prof, national_id='AB12', foreign='1'))
check('۶ تا ۲۰ حرف' in html, 'short passport number rejected')

# Patient choice is required; family member added (with every detail) and pre-selected.
status, html = submit(p, book, dict(action='reserve'))
check('مشخص کنید نوبت برای چه کسی است' in html and scalar("SELECT COUNT(*) FROM bookings WHERE patient_user_id=? AND status='held'", [uid]) == 0, 'no hold without choosing the patient')
check('name="family_member_id" value="0"' in html and 'checked' not in html.split('data-who-form')[1].split('</form>')[0].split('family_member_id" value="0"')[1][:30], 'nothing pre-selected')
status, html = submit(p, book, dict(action='family', name='سارا رضایی', relationship='فرزند', birth_d='12', birth_m='4', birth_y='1395', gender='female', insurance='salamat', national_id='1234567891'))
fm = scalar('SELECT id FROM family_members WHERE user_id=? AND name=?', [uid, 'سارا رضایی'])
check(fm and scalar('SELECT gender FROM family_members WHERE id=?', [fm]) == 'female' and str(scalar('SELECT birth_date FROM family_members WHERE id=?', [fm])) == '2016-07-02', 'family member added with gender and birth date')
check(f'name="family_member_id" value="{fm}" checked' in html, 'new family member pre-selected')
status, html = submit(p, book, dict(action='family', name='', relationship='فرزند'))
check(status == 200 and 'alert-error' in html, 'family form validates the name')

# ---------- Payment step: fee charged; only usable gateways ----------
db("INSERT INTO booking_fee_rules(scope,target_id,fee_toman,active,note) VALUES('doctor',?,5000,1,'v17') ON DUPLICATE KEY UPDATE fee_toman=5000,active=1", [DOCTOR])
php("test_gateway_off();test_gateway('zarinpal',['merchant_id'=>'11111111-1111-1111-1111-111111111111']);")
status, html = submit(p, book, dict(action='reserve', family_member_id=fm))
bid = scalar("SELECT id FROM bookings WHERE patient_user_id=? AND status='held' ORDER BY id DESC LIMIT 1", [uid])
check(bid is not None and scalar('SELECT family_member_id FROM bookings WHERE id=?', [bid]) == fm, 'hold created for the chosen family member')
status, co = get(p, f'checkout.php?id={bid}')
check(status == 200 and clean(co) and 'data-hold-bar' in co and 'data-expires=' in co, 'payment step with the hold countdown')
check(co.count('step-done') == 2 and 'step-current' in co, 'stepper on the last step')
check(co.count('name="gateway"') == 1 and 'value="zarinpal" checked' in co and 'پرداخت و ثبت نوبت' in co, 'only the usable gateway is offered, pre-selected')
bill = co.split('class="bk-card bill-card"')[1].split('</section>')[0]
check('۳۵۰٬۰۰۰ تومان — پرداخت در مطب' in bill, 'visit fee shown as paid at the clinic')
check('مبلغ قابل پرداخت آنلاین' in bill and '۵٬۰۰۰ تومان' in bill.split('inv-total')[1] and '۳۵۵' not in co, 'online total is the booking fee only (visit fee never added)')
check('مالیات' not in bill, 'no tax line without a legal tax setting')
check('مبلغ پرداختی فقط بابت خدمات رزرو نوبت' in co, 'payment note shown')
check('قوانین لغو نوبت و بازگشت کارمزد' in co, 'cancellation rules before paying')
check('کد تخفیف دارید؟' in co and 'فقط روی کارمزد رزرو اعمال' in co, 'discount box applies to the booking fee only')
php("test_gateway_off();")
status, co = get(p, f'checkout.php?id={bid}')
check('درگاه پرداخت فعالی وجود ندارد' in co and 'name="gateway"' not in co and re.search(r'form="pay-form" disabled', co), 'no usable gateway: no gateway listed, pay button disabled')
status, html = submit(p, f'checkout.php?id={bid}', dict(action='pay', gateway='zarinpal'))
check(scalar('SELECT status FROM bookings WHERE id=?', [bid]) == 'held' and scalar("SELECT COUNT(*) FROM payment_transactions WHERE purpose='booking' AND reference_id=? AND status IN ('paid','verified')", [bid]) == 0, 'paying without a usable gateway never marks anything paid')

# Expired hold.
db('UPDATE bookings SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=?', [bid])
status, co = get(p, f'checkout.php?id={bid}')
check('مهلت این رزرو تمام شده است' in co and 'data-pay-bar' not in co and 'انتخاب دوباره زمان' in co, 'expired hold: no payment, choose the time again')

# ---------- Free booking → success receipt ----------
db("UPDATE booking_fee_rules SET active=0 WHERE scope='doctor' AND target_id=?", [DOCTOR])
at = first_free()
book = f'service-book.php?clinic={CLINIC}&service={SERVICE}&move=0&at=' + urllib.parse.quote(at)
status, html = submit(p, book, dict(action='reserve', family_member_id=0))
bid2 = scalar("SELECT id FROM bookings WHERE patient_user_id=? AND status='held' AND expires_at>NOW() ORDER BY id DESC LIMIT 1", [uid])
status, co = get(p, f'checkout.php?id={bid2}')
check('ثبت رایگان نوبت' in co and 'name="gateway"' not in co and 'کد تخفیف دارید؟' not in co, 'free booking: no gateway, free confirm button')
status, rc = submit(p, f'checkout.php?id={bid2}', dict(action='confirm'))
check(scalar('SELECT status FROM bookings WHERE id=?', [bid2]) == 'confirmed', 'free booking confirmed')
check('نوبت شما با موفقیت ثبت شد' in rc and 'success-mark' in rc and 'class="receipt"' in rc, 'success page after confirming')
code = scalar('SELECT tracking_code FROM bookings WHERE id=?', [bid2])
check(code in rc and 'مریم رضایی' in rc and 'مشاهده نوبت‌های من' in rc and 'data-print' in rc and 'data-share' in rc and 'بازگشت به صفحه اصلی' in rc, 'receipt: tracking code, patient and the four actions')
check('<script>' not in rc, 'receipt has no inline script')
db("UPDATE bookings SET confirmed_at=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE id=?", [bid2])
status, rc = get(p, f'receipt.php?id={bid2}')
check(status == 200 and 'class="receipt"' in rc and 'success-hero' not in rc, 'later visits show the plain receipt')
status, rc = get(client(), f'receipt.php?id={bid2}')
check(code not in rc, 'receipt is private to its owner')

# Taken time re-checked.
status, html = get(p, f'service-book.php?clinic={CLINIC}&service={SERVICE}&move=0&at=' + urllib.parse.quote(at))
check('این زمان دیگر آزاد نیست' in html, 'a confirmed time is no longer offered')

# Sensitive pages are not cached.
req = urllib.request.Request(BASE + f'checkout.php?id={bid2}')
r = p.open(req)
check('no-store' in (r.headers.get('Cache-Control') or ''), 'payment step not cached')

# ---------- Admin ----------
admin = admin_login()
status, html = get(admin, 'admin.php?tab=booking')
check(status == 200 and clean(html) and 'صفحه انتخاب زمان و اطلاعات مراجعه‌کننده' in html, 'admin booking page settings')
status, html = submit(admin, 'admin.php?tab=booking', dict(action='booking_page', booking_days='21', patient_id_mode='optional', booking_profile_fields='1', booking_payment_note='متن آزمایشی پرداخت'))
check(scalar("SELECT value FROM settings WHERE `key`='booking_days'") == '21' and scalar("SELECT value FROM settings WHERE `key`='patient_id_mode'") == 'optional', 'booking page settings saved')
check(scalar("SELECT COUNT(*) FROM audit_log WHERE action='booking.page'") >= 1, 'booking page change audited')
status, html = submit(admin, 'admin.php?tab=booking', dict(action='booking_page', booking_days='5', patient_id_mode='optional'))
check(scalar("SELECT value FROM settings WHERE `key`='booking_days'") == '21', 'invalid strip length rejected')
status, html = submit(admin, 'admin.php?tab=booking', dict(action='booking_page', booking_days='14', patient_id_mode='all'))
check(scalar("SELECT value FROM settings WHERE `key`='patient_id_mode'") == 'optional', 'invalid national-ID mode rejected')
check(php('echo booking_payment_note();') == 'متن آزمایشی پرداخت', 'custom payment note used on the payment step')
status, html = get(admin, 'admin.php?tab=gateways')
check('بارگذاری نشان' in html, 'gateway logo upload form')
status, html = submit(admin, 'admin.php?tab=gateways', dict(action='gateway_logo', gateway='nope', remove='1'))
check('درگاه نامعتبر' in html, 'unknown gateway rejected')
setting_set('booking_days', '14')
setting_set('patient_id_mode', 'required')
setting_set('booking_payment_note', '')
php("clinic_set_notes((int)$argv[2],['notes'=>\"پذیرش از ساعت ۸ صبح است. لطفاً ده دقیقه زودتر حضور داشته باشید و دفترچه بیمه همراه بیاورید.\\nبرای ویزیت کودکان، حضور یکی از والدین لازم است. پارکینگ عمومی در خیابان مجاور قرار دارد.\\nدر صورت تأخیر بیش از ۱۵ دقیقه، نوبت به بیمار بعدی داده می‌شود.\\nنتایج آزمایش‌های قبلی را همراه داشته باشید.\"]);", [CLINIC])

(ROOT.parent.parent / 'runtime' / 'booking-fixture.json').write_text(json.dumps(dict(doctor=DOCTOR, clinic=CLINIC, service=SERVICE, session=sid(p), booking=bid2, member=fm, free_day=free_day, empty_day=empty_day)))
print('SUCCESS', checks, 'rc.5 booking checks')
