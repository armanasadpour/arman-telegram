"""0.8.0 — doctor subscriptions: plans, discount codes, proforma/invoice, shared payment module,
grace period, trial, gifts, plan limits, cron, revenue report and Excel export.
Gateway calls use CLI-only injected transports; no live bank request is made."""
from http_helpers import *
import zipfile, io

fixture = json.loads((ROOT.parent.parent / 'runtime' / 'phase6-fixture.json').read_text())
DOCTOR, CLINIC, SERVICE = fixture['doctor'], fixture['clinic'], fixture['service']


def php(code, args=[]):
    cmd = [PHP] + (['-c', PHPINI] if PHPINI else []) + [
        '-r', 'require $argv[1];if(config()["database"]!=="nobatyar_test_http")exit(9);require_once dirname($argv[1])."/tests/gateway_support.php";' + code,
        str(ROOT / 'core.php')] + [str(x) for x in args]
    return subprocess.check_output(cmd).decode()


def scalar(sql, args=[]):
    return next(iter(db(sql, args)[0].values()))


def admin_client():
    c = client()
    submit(c, 'admin.php', dict(action='login', email='admin@example.test', password='Test-password-012345'))
    return c


def doctor_post(c, data, expect=None):
    status, html = submit(c, f'doctor-panel.php?doctor={DOCTOR}&tab=subscription', data)
    if expect:
        check(expect in html, 'doctor ' + data['action'] + ': ' + expect)
    return html


def days_between(a, b):
    return (datetime.date.fromisoformat(b) - datetime.date.fromisoformat(a)).days


admin = admin_client()
db('DELETE FROM request_limits')
today = php('echo date("Y-m-d");')
owner_mobile = scalar('SELECT u.mobile FROM doctor_accounts a JOIN users u ON u.id=a.user_id WHERE a.doctor_id=?', [DOCTOR])
owner = client()
login_user(owner, owner_mobile)

# ---------- Plans ----------
plans = db("SELECT * FROM subscription_plans WHERE audience='doctor' ORDER BY sort_order")
check([p['duration_days'] for p in plans] == [30, 90, 180, 365] and all(p['active'] == 0 and p['price_toman'] == 0 for p in plans), 'four standard plans seeded inactive without invented prices')
monthly, quarterly = plans[0]['id'], plans[1]['id']
status, html = submit(admin, 'admin.php?tab=subscriptions&view=plans', dict(action='plan_save', id=monthly, name='ماهانه', duration_days=30, price_toman='0', active='1'))
check('حداقل ۱٬۰۰۰' in html, 'plan cannot be activated without a price')
save(admin, 'admin.php?tab=subscriptions&view=plans', dict(action='plan_save', id=monthly, name='ماهانه', duration_days=30, price_toman='۳۰۰٬۰۰۰', features='نوبت‌دهی آنلاین\nپنل منشی', active='1'))
save(admin, 'admin.php?tab=subscriptions&view=plans', dict(action='plan_save', id=quarterly, name='سه‌ماهه', duration_days=90, price_toman='810,000', max_clinics='1', highlight='1', active='1'))
check(scalar('SELECT price_toman FROM subscription_plans WHERE id=?', [monthly]) == 300000, 'price parsed from Persian digits and separators')
status, html = get(owner, f'doctor-panel.php?doctor={DOCTOR}&tab=subscription')
check('۳۰۰٬۰۰۰ تومان' in html and '۸۱۰٬۰۰۰ تومان' in html and 'شش‌ماهه' not in html, 'doctor sees only active plans with prices')

# ---------- Discount codes ----------
save(admin, 'admin.php?tab=subscriptions&view=codes', dict(action='discount_save', code='welcome20', kind='percent', value='20', scope='subscription', max_uses='', per_owner_limit='1', active='1'))
save(admin, 'admin.php?tab=subscriptions&view=codes', dict(action='discount_save', code='FREE-ONE', kind='percent', value='100', scope='subscription', max_uses='1', per_owner_limit='1', active='1'))
other_doctor = scalar('SELECT MIN(id) FROM doctors WHERE id<>?', [DOCTOR])
save(admin, 'admin.php?tab=subscriptions&view=codes', dict(action='discount_save', code='ONLY-OTHER', kind='fixed', value='50000', scope='subscription', doctor_id=other_doctor, per_owner_limit='1', active='1'))
save(admin, 'admin.php?tab=subscriptions&view=codes', dict(action='discount_save', code='EXPIRED', kind='fixed', value='50000', scope='subscription', ends_at='1400/01/01', per_owner_limit='1', active='1'))
check(scalar("SELECT code FROM discount_codes WHERE code='WELCOME20'") == 'WELCOME20', 'codes normalised to upper case')
save(admin, 'admin.php?tab=subscriptions&view=settings', dict(action='subscription_settings', subscription_grace_days=3, subscription_warning_thresholds='7,3,1', subscription_trial_days=14, proforma_valid_hours=48, invoice_vat_percent='10', invoice_seller_name='شرکت نمونه نوبت‌یار', invoice_seller_national_id='۱۴۰۰۰۰۰۰۰۰۰', invoice_seller_economic_code='', invoice_seller_phone='', invoice_seller_address='بندرعباس'))

# Doctor with 10 days left.
db("UPDATE doctors SET subscription_until=DATE_ADD(CURDATE(),INTERVAL 9 DAY) WHERE id=?", [DOCTOR])
until0 = php(f'echo query("SELECT subscription_until FROM doctors WHERE id={DOCTOR}")->fetchColumn();')
doctor_post(owner, dict(action='sub_order', plan_id=monthly, kind='renew', discount_code='ONLY-OTHER'), 'اختصاصی پزشک دیگری')
doctor_post(owner, dict(action='sub_order', plan_id=monthly, kind='renew', discount_code='expired'), 'معتبر نیست')
doctor_post(owner, dict(action='sub_order', plan_id=monthly, kind='renew', discount_code='welcome20'), 'پیش‌فاکتور صادر شد')
o = db("SELECT * FROM subscription_orders WHERE doctor_id=? AND status='proforma'", [DOCTOR])[0]
check(o['base_toman'] == 300000 and o['discount_toman'] == 60000 and o['vat_toman'] == 24000 and o['payable_toman'] == 264000, 'proforma: 20% discount then 10% VAT')
check(o['period_start'] == php(f'echo date("Y-m-d",strtotime("{until0} +1 day"));'), 'renewal starts the day after the current end (no lost days)')
check(scalar("SELECT status FROM discount_redemptions WHERE order_id=?", [o['id']]) == 'reserved', 'discount reserved on proforma')
status, html = get(owner, f'subscription-invoice.php?id={o["id"]}')
check('پیش‌فاکتور' in html and '۲۶۴٬۰۰۰ تومان' in html and 'شرکت نمونه نوبت‌یار' in html, 'printable proforma with seller')
outsider = client()
login_user(outsider, '09127000999')
check(get(outsider, f'subscription-invoice.php?id={o["id"]}')[0] == 403, 'other users cannot see the proforma')

# A second proforma replaces the first and frees its discount.
doctor_post(owner, dict(action='sub_order', plan_id=monthly, kind='renew', discount_code='welcome20'), 'پیش‌فاکتور صادر شد')
check(scalar('SELECT status FROM subscription_orders WHERE id=?', [o['id']]) == 'cancelled' and scalar('SELECT status FROM discount_redemptions WHERE order_id=?', [o['id']]) == 'released', 'old proforma cancelled and code released')
o = db("SELECT * FROM subscription_orders WHERE doctor_id=? AND status='proforma'", [DOCTOR])[0]

# No gateway configured: no fake success.
doctor_post(owner, dict(action='sub_checkout', order_id=o['id']), 'درگاه پرداخت فعالی تنظیم نشده')
check(scalar('SELECT status FROM subscription_orders WHERE id=?', [o['id']]) == 'proforma', 'order stays unpaid without a gateway')

# Gateway configured (CLI transport only).
php("test_gateway('zarinpal',['merchant_id'=>'11111111-1111-1111-1111-111111111111']);")
AUTH = 'A' + 'B' * 35
counter = [0]
def next_auth():
    counter[0] += 1
    return 'A' + str(counter[0]).zfill(35)
request = f'''$_SESSION['user_id']=null;$r=subscription_checkout((int)$argv[2],{DOCTOR},zp(function($m,$p)use($argv){{if($m!=='request'||$p['amount']!==2640000||$p['currency']!=='IRR'||!str_contains($p['callback_url'],'pay-return.php?tx='))throw new RuntimeException('bad request');return ['data'=>['code'=>100,'authority'=>$argv[3]]];}}));echo json_encode($r);'''
def checkout(order, amount=None):
    code = request if amount is None else request.replace('2640000', amount)
    auth = next_auth()
    return json.loads(php(code, [order, auth])), auth
r, AUTH = checkout(o['id'])
check(r['redirect']['url'].endswith('/StartPay/' + AUTH) and r['redirect']['method'] == 'GET', 'checkout redirects to Zarinpal StartPay (amount in Rial)')
tx = r['tx']
check(scalar('SELECT status FROM payment_transactions WHERE id=?', [tx]) == 'redirected', 'transaction waiting at bank')
status, html = submit(owner, f'doctor-panel.php?doctor={DOCTOR}&tab=subscription', dict(action='sub_cancel', order_id=o['id']))
check('در جریان' in html, 'proforma with a payment in progress cannot be cancelled')

verify_ok = '''$t=payment_return((int)$argv[2],['Authority'=>$argv[3],'Status'=>'OK'],zp(function($m,$p){if($m!=='verify')throw new RuntimeException('x');return ['data'=>['code'=>100,'ref_id'=>987654321,'card_pan'=>'6037****1234']];}));echo $t['status'];'''
check(php(verify_ok, [tx, 'WRONG']) == 'failed', 'mismatched authority never verifies')
# Fresh transaction for the same order after a failed attempt.
r, AUTH = checkout(o['id'])
tx = r['tx']
check(php(verify_ok, [tx, AUTH]) == 'verified', 'verified payment')
paid = db('SELECT * FROM subscription_orders WHERE id=?', [o['id']])[0]
year = php('echo digits(substr(jdate(date("Y-m-d")),0,4));')
check(paid['status'] == 'paid' and paid['number'] == f'NY-{year}-000001' and paid['payment_tx_id'] == tx, 'invoice number assigned after payment')
until1 = scalar('SELECT subscription_until FROM doctors WHERE id=?', [DOCTOR])
check(days_between(until0, until1) == 30, 'subscription extended by exactly 30 days from previous end')
check(php(verify_ok, [tx, AUTH]) == 'verified' and scalar('SELECT subscription_until FROM doctors WHERE id=?', [DOCTOR]) == until1, 'repeated callback does not extend twice')
check(scalar('SELECT status FROM discount_redemptions WHERE order_id=?', [o['id']]) == 'used', 'discount marked used')
check(scalar("SELECT COUNT(*) FROM subscriptions WHERE order_id=? AND kind='paid'", [o['id']]) == 1, 'paid period recorded once')
status, html = get(owner, f'subscription-invoice.php?id={o["id"]}')
check('فاکتور فروش خدمات' in html and '۹۸۷۶۵۴۳۲۱' in html, 'invoice shows bank reference')
status, html = get(owner, f'doctor-panel.php?doctor={DOCTOR}&tab=subscription')
check(f'NY-{year}-000001' in html and 'خرید آنلاین' in html, 'doctor history shows invoice and purchase')

# Per-owner limit: the same doctor cannot reuse WELCOME20.
doctor_post(owner, dict(action='sub_order', plan_id=monthly, kind='renew', discount_code='WELCOME20'), 'سقف استفاده شما')

# Cancelled at bank and failed verification.
doctor_post(owner, dict(action='sub_order', plan_id=monthly, kind='renew'), 'پیش‌فاکتور صادر شد')
o2 = scalar("SELECT id FROM subscription_orders WHERE doctor_id=? AND status='proforma'", [DOCTOR])
r, AUTH = checkout(o2, '3300000'); tx2 = r['tx']
status, html = get(client(), f'pay-return.php?tx={tx2}&Authority={AUTH}&Status=NOK')
check(status == 200 and 'پرداخت انجام نشد' in html and scalar('SELECT status FROM payment_transactions WHERE id=?', [tx2]) == 'cancelled', 'user cancellation at bank handled over HTTP')
r, AUTH = checkout(o2, '3300000'); tx3 = r['tx']
fail = '''$t=payment_return((int)$argv[2],['Authority'=>$argv[3],'Status'=>'OK'],zp(fn($m,$p)=>['data'=>['code'=>-51]]));echo $t['status'];'''
check(php(fail, [tx3, AUTH]) == 'failed' and scalar('SELECT status FROM subscription_orders WHERE id=?', [o2]) == 'proforma', 'failed verification keeps proforma unpaid')
# Paid after the proforma was cancelled: money flagged for refund, nothing activated.
r, AUTH = checkout(o2, '3300000'); tx4 = r['tx']
db("UPDATE subscription_orders SET status='cancelled' WHERE id=?", [o2])
check(php(verify_ok.replace('987654321', '111222333'), [tx4, AUTH]) == 'refund_due', 'late payment for a closed order marked refund_due')
check(scalar('SELECT subscription_until FROM doctors WHERE id=?', [DOCTOR]) == until1, 'refund_due does not extend subscription')
status, html = get(client(), f'pay-return.php?tx={tx4}')
check('بازگشت وجه' in html, 'refund message on return page')

# Free checkout with a 100% code, then the code is exhausted.
doctor_post(owner, dict(action='sub_order', plan_id=monthly, kind='renew', discount_code='FREE-ONE'), 'پیش‌فاکتور صادر شد')
o3 = db("SELECT * FROM subscription_orders WHERE doctor_id=? AND status='proforma'", [DOCTOR])[0]
check(o3['payable_toman'] == 0, '100% discount gives zero payable')
doctor_post(owner, dict(action='sub_checkout', order_id=o3['id']), 'بدون نیاز به پرداخت')
check(scalar('SELECT payment_method FROM subscription_orders WHERE id=?', [o3['id']]) == 'free' and scalar("SELECT COUNT(*) FROM payment_transactions WHERE reference_id=? AND purpose='subscription'", [o3['id']]) == 0, 'free activation without a bank transaction')
until2 = scalar('SELECT subscription_until FROM doctors WHERE id=?', [DOCTOR])
check(days_between(until1, until2) == 30, 'free period appended after paid one')
save(admin, 'admin.php?tab=subscriptions&view=codes', dict(action='discount_save', code='FREE-ONE', kind='percent', value='100', scope='subscription', max_uses='1', per_owner_limit='5', active='1', id=scalar("SELECT id FROM discount_codes WHERE code='FREE-ONE'")))
doctor_post(owner, dict(action='sub_order', plan_id=monthly, kind='renew', discount_code='FREE-ONE'), 'ظرفیت استفاده')

# Offline payment recorded by the administrator.
status, html = submit(admin, 'admin.php?tab=subscriptions&view=orders', dict(action='sub_admin_order', doctor_id=DOCTOR, plan_id=quarterly, kind='renew'))
check('صادر شد' in html, 'admin issues proforma for doctor')
o4 = scalar("SELECT id FROM subscription_orders WHERE doctor_id=? AND status='proforma'", [DOCTOR])
status, html = submit(admin, 'admin.php?tab=subscriptions&view=orders', dict(action='sub_offline', order_id=o4, reference='1'))
check('شماره پیگیری' in html, 'offline payment needs a real reference')
save(admin, 'admin.php?tab=subscriptions&view=orders', dict(action='sub_offline', order_id=o4, reference='واریز-12345'))
check(scalar('SELECT payment_method FROM subscription_orders WHERE id=?', [o4]) == 'offline' and scalar('SELECT number FROM subscription_orders WHERE id=?', [o4]) == f'NY-{year}-000003', 'offline payment invoiced with next number')
until3 = scalar('SELECT subscription_until FROM doctors WHERE id=?', [DOCTOR])
check(days_between(until2, until3) == 90, 'quarterly appended')

# Plan change now: credit for unused paid days of the current period.
db("DELETE FROM subscriptions WHERE doctor_id=? AND starts_on>CURDATE() AND (order_id IS NULL OR order_id<>?)", [DOCTOR, o['id']])
paid_order = db('SELECT * FROM subscription_orders WHERE id=?', [o['id']])[0]
db("UPDATE subscriptions SET starts_on=DATE_SUB(CURDATE(),INTERVAL 10 DAY),ends_on=DATE_ADD(CURDATE(),INTERVAL 19 DAY) WHERE order_id=?", [o['id']])
db("UPDATE doctors SET subscription_until=DATE_ADD(CURDATE(),INTERVAL 19 DAY) WHERE id=?", [DOCTOR])
doctor_post(owner, dict(action='sub_order', plan_id=quarterly, kind='change_now'), 'پیش‌فاکتور صادر شد')
o5 = db("SELECT * FROM subscription_orders WHERE doctor_id=? AND status='proforma'", [DOCTOR])[0]
expected_credit = (paid_order['payable_toman'] - paid_order['vat_toman']) * 19 // 30
check(o5['credit_toman'] == expected_credit and o5['period_start'] == today, 'change_now credits unused paid days and starts today')
save(admin, 'admin.php?tab=subscriptions&view=orders', dict(action='sub_offline', order_id=o5['id'], reference='واریز-67890'))
check(scalar('SELECT subscription_until FROM doctors WHERE id=?', [DOCTOR]) == php('echo date("Y-m-d",strtotime("+89 days"));'), 'new plan period replaces the old one')
check(scalar('SELECT ends_on FROM subscriptions WHERE order_id=?', [o['id']]) == php('echo date("Y-m-d",strtotime("-1 day"));'), 'old period closed yesterday, history kept')

# Plan limit: quarterly allows one active clinic.
cl = db('SELECT ci.province_id,c.city_id FROM clinics c JOIN cities ci ON ci.id=c.city_id WHERE c.id=?', [CLINIC])[0]
csrf = api(owner, 'session')[1]['data']['csrf']
st, r = api(owner, 'doctor/action', dict(doctor_id=DOCTOR, action='clinic', clinic_name='مطب سوم', province_id=cl['province_id'], city_id=cl['city_id'], address='آدرس', phone='', active=1), csrf)
check(st in (403, 409) and 'پلن فعلی' in r['error']['message'], 'plan clinic limit enforced')

# Grace period and expiry.
db("UPDATE doctors SET subscription_until=DATE_SUB(CURDATE(),INTERVAL 1 DAY) WHERE id=?", [DOCTOR])
check(get(client(), f'doctor.php?id={DOCTOR}')[0] == 200, 'doctor bookable during grace period')
status, html = get(owner, f'doctor-panel.php?doctor={DOCTOR}')
check('دوره فرجه' in html, 'doctor dashboard shows grace state')
# rc.20: after the grace period the doctor drops to the free plan — still listed and bookable (no
# subscription needed); only Pro features and limits change (covered by v31_pro_plan.py).
db("UPDATE doctors SET subscription_until=DATE_SUB(CURDATE(),INTERVAL 5 DAY) WHERE id=?", [DOCTOR])
check(get(client(), f'doctor.php?id={DOCTOR}')[0] == 200, 'expired after grace: free plan, still listed')
status, html = get(owner, f'doctor-panel.php?doctor={DOCTOR}')
check('plan-chip is-free' in html and 'خرید اشتراک پرو' in html, 'doctor dashboard shows the free plan after Pro ends (rc.21 welcome box)')
confirmed = scalar("SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.doctor_id=? AND b.status='confirmed'", [DOCTOR])
status, html = get(owner, f'doctor-panel.php?doctor={DOCTOR}&tab=bookings')
check(status == 200 and confirmed > 0, 'expired doctor keeps panel access and confirmed bookings')

# Gift days (admin) from today when expired.
save(admin, 'admin.php?tab=subscriptions', dict(action='sub_grant', doctor_id=DOCTOR, days=10, kind='gift', note='جبران قطعی'))
check(scalar('SELECT subscription_until FROM doctors WHERE id=?', [DOCTOR]) == php('echo date("Y-m-d",strtotime("+9 days"));'), 'gift days start today after expiry')
check(scalar("SELECT note FROM subscriptions WHERE doctor_id=? AND kind='gift' ORDER BY id DESC LIMIT 1", [DOCTOR]) == 'جبران قطعی', 'gift note stored')

# Trial: only once, only for doctors without purchases.
save(admin, 'admin.php?tab=subscriptions&view=settings', dict(action='subscription_settings', subscription_grace_days=3, subscription_warning_thresholds='7,3,1', subscription_trial_enabled='1', subscription_trial_days=14, proforma_valid_hours=48, invoice_vat_percent='10', invoice_seller_name='شرکت نمونه نوبت‌یار'))
trial_doctor = scalar("SELECT id FROM doctors WHERE id<>? AND review_status='approved' ORDER BY id LIMIT 1", [DOCTOR])
db("UPDATE doctors SET subscription_until=NULL,trial_used_at=NULL WHERE id=?", [trial_doctor])
db("DELETE FROM subscriptions WHERE doctor_id=?", [trial_doctor])
out = php('''$d=query('SELECT * FROM doctors WHERE id=?',[(int)$argv[2]])->fetch();echo subscription_status($d)['trial_available']?'yes':'no';subscription_start_trial((int)$argv[2]);try{subscription_start_trial((int)$argv[2]);echo 'twice';}catch(AuthError $e){echo '-once';}''', [trial_doctor])
check(out == 'yes-once', 'trial available once')
check(scalar('SELECT subscription_until FROM doctors WHERE id=?', [trial_doctor]) == php('echo date("Y-m-d",strtotime("+13 days"));'), 'trial grants configured days')
check(php('''$d=query('SELECT * FROM doctors WHERE id=?',[%d])->fetch();echo subscription_status($d)['trial_available']?'yes':'no';''' % DOCTOR) == 'no', 'paying doctor has no trial')

# Cron: stale proformas expire and release codes; warnings by threshold.
doctor_post(owner, dict(action='sub_order', plan_id=monthly, kind='renew'), 'پیش‌فاکتور صادر شد')
stale = scalar("SELECT id FROM subscription_orders WHERE doctor_id=? AND status='proforma'", [DOCTOR])
db('UPDATE subscription_orders SET expires_at=DATE_SUB(NOW(),INTERVAL 1 HOUR) WHERE id=?', [stale])
php('require dirname($argv[1])."/lib/jobs.php";run_cron(["subscription_proformas"]);')
check(scalar('SELECT status FROM subscription_orders WHERE id=?', [stale]) == 'expired', 'cron expires stale proformas')
db("UPDATE doctors SET subscription_until=DATE_ADD(CURDATE(),INTERVAL 2 DAY) WHERE id=?", [DOCTOR])
db("DELETE FROM notice_markers")
php('require dirname($argv[1])."/lib/jobs.php";run_cron(["subscription_warnings"]);run_cron(["subscription_warnings"]);')
check(scalar("SELECT COUNT(*) FROM notice_markers WHERE dedupe_key LIKE ?", [f'subscription_expiring:{DOCTOR}:%:3']) == 1, 'closest threshold (3 days) notified once')
check(scalar("SELECT COUNT(*) FROM notifications WHERE title='اشتراک نزدیک به پایان است'") >= 1, 'in-app warning created')

# Revenue report and Excel export.
status, html = get(admin, 'admin.php?tab=subscriptions&view=report')
check('درآمد اشتراک' in html and 'خروجی اکسل' in html, 'revenue report renders')
resp = admin.open(BASE + 'admin.php?' + urllib.parse.urlencode({'tab': 'subscriptions', 'view': 'report', 'export': 'xlsx', 'from': '1400/01/01', 'to': '1499/12/29'}))
data = resp.read()
check(resp.headers['Content-Type'].startswith('application/vnd.openxmlformats') and data[:2] == b'PK', 'xlsx download')
sheet = zipfile.ZipFile(io.BytesIO(data)).read('xl/worksheets/sheet1.xml').decode()
check(f'NY-{year}-000001' in sheet and 'rightToLeft="1"' in sheet and '<v>264000</v>' in sheet, 'xlsx contains invoices, RTL sheet and numeric amounts')
for view in ['overview', 'plans', 'codes', 'orders', 'report', 'settings']:
    status, html = get(admin, 'admin.php?tab=subscriptions&view=' + view)
    check(status == 200 and 'Warning' not in html, 'admin subscriptions view ' + view)

php("test_gateway_off();")
db("UPDATE doctors SET subscription_until='2099-01-01' WHERE id=?", [DOCTOR])
# rc.20: plan limits now also close existing clinics/services above the limit; later suites use this
# doctor's second clinic, so the plans tested here get no limits again before re-checking the locks.
db("UPDATE subscription_plans SET max_clinics=NULL,max_secretaries=NULL,max_services=NULL WHERE audience='doctor' OR audience IS NULL")
php(f"entitlement_sync({DOCTOR});")  # direct date change → re-check plan locks like the real paths do
print(f'SUCCESS {checks} v0.8 checks')
