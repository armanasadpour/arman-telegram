"""0.9.0 — multi-gateway payments over HTTP: admin gateway pages and activation gate, fee rules
(doctor > city > global), booking discount codes, booking payment and receipt, cancellation refunds,
late payment after hold expiry, transactions report and Excel export.
Bank calls use CLI-only injected transports (tests/gateway_support.php); no live bank request is made."""
from http_helpers import *
import zipfile, io

fixture = json.loads((ROOT.parent.parent / 'runtime' / 'phase6-fixture.json').read_text())
DOCTOR, CLINIC, SERVICE = fixture['doctor'], fixture['clinic'], fixture['service']
MERCHANT = '22222222-3333-4444-5555-666666666666'


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


def admin_client():
    c = client()
    submit(c, 'admin.php', dict(action='login', email='admin@example.test', password='Test-password-012345'))
    return c


def admin_post(tab, data, expect):
    status, html = submit(admin, f'admin.php?tab={tab}', data)
    check(status == 200 and expect in html, f'admin {data["action"]}: {expect}' + ('' if expect in html else ' :: ' + re.sub(r'\s+', ' ', html[html.find('<main'):][:600])))
    return html


def fa_number(v):
    return str(v).translate(str.maketrans('0123456789', '۰۱۲۳۴۵۶۷۸۹'))


def clean(html):
    return 'Warning' not in html and 'Fatal error' not in html and 'Notice' not in html


def free_slot():
    """A start time at least two days ahead (so patient cancellation stays allowed), on a day the
    test patient has no occupying booking with this doctor."""
    busy = {str(r['d']) for r in db("SELECT DATE(s.starts_at) d FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.doctor_id=? AND (b.status='confirmed' OR (b.status='held' AND b.expires_at>NOW()))", [DOCTOR])}
    for days in range(3, 60):
        d = php(f'echo date("Y-m-d",strtotime("+{days} days"));')
        if d in busy:
            continue
        avail = api(client(), f'availability&clinic={CLINIC}&service={SERVICE}&date={d}')[1]['data']
        if avail:
            return avail[0]['starts_at']
    raise AssertionError('no free slot')


def hold(c, name='بیمار پرداخت'):
    return send(c, 'booking/hold', dict(clinic_id=CLINIC, service_id=SERVICE, starts_at=free_slot(), name=name))


def booking(i):
    return db('SELECT * FROM bookings WHERE id=?', [i])[0]


LOGIN = """$u=query('SELECT * FROM users WHERE id=?',[(int)$argv[2]])->fetch();$_SESSION['user_id']=$u['id'];$_SESSION['user_version']=$u['session_version'];$_SESSION['login_at']=time();"""
ZP = """zp(function($op,$p)use($argv){if($op==='request'){if($p['amount']!==(int)$argv[4]*10)throw new RuntimeException('amount '.$p['amount']);return ['data'=>['code'=>100,'authority'=>$argv[5]]];}return ['data'=>['code'=>100,'ref_id'=>(int)$argv[6],'card_pan'=>'5022****7777']];})"""
auth_counter = [0]


def pay(uid, bid, toman):
    """Start the booking payment and complete the bank callback; returns the transaction row."""
    auth_counter[0] += 1
    authority = 'A' + str(900 + auth_counter[0]).zfill(35)
    ref = 880000 + auth_counter[0]
    url = php(LOGIN + f'echo start_payment((int)$argv[3],{ZP})["url"];', [uid, bid, toman, authority, ref])
    check(url.endswith('/StartPay/' + authority), f'booking payment redirected to bank ({toman} Toman)')
    return json.loads(php(f'echo json_encode(test_return($argv[5],["Authority"=>$argv[5],"Status"=>"OK"],{ZP}));', [uid, bid, toman, authority, ref]))


admin = admin_client()
# rc.17: VAT also applies to the booking fee; these checks use amounts without VAT (restored at the end).
vat_before = scalar("SELECT value FROM settings WHERE `key`='invoice_vat_percent'")
db("UPDATE settings SET value='0' WHERE `key`='invoice_vat_percent'")
db('DELETE FROM request_limits')
php('test_gateway_off();')
db("UPDATE settings SET value='0' WHERE `key`='booking_fee_enabled'")
max_active = scalar("SELECT value FROM settings WHERE `key`='max_active_bookings'")
db("UPDATE settings SET value='30' WHERE `key`='max_active_bookings'")

# ---------- Admin pages ----------
for tab in ['gateways', 'payments', 'settings', 'booking']:
    status, html = get(admin, f'admin.php?tab={tab}')
    check(status == 200 and clean(html), f'admin page {tab} renders')
status, html = get(admin, 'admin.php?tab=gateways')
for label in ['زرین‌پال', 'سامان کیش', 'سداد', 'به‌پرداخت ملت', 'آسان پرداخت']:
    check(label in html, f'gateway listed: {label}')
check('پرداخت آزمایشی</button>' not in html and 'فعال‌سازی</button>' not in html, 'no test or enable button before credentials')
check(get(client(), 'admin.php?tab=payments&export=xlsx')[1].find('ورود مدیرکل') >= 0, 'export requires administrator')

# ---------- Activation gate over HTTP ----------
admin_post('settings', dict(action='settings', fee='3000', enabled='1'), 'درگاه تأییدشده')
admin_post('gateways', dict(action='gateway_save', gateway='zarinpal', merchant_id=MERCHANT), 'اطلاعات درگاه ذخیره شد')
status, html = get(admin, 'admin.php?tab=gateways')
check(MERCHANT not in html and 'ذخیره شده' in html and 'در انتظار پرداخت آزمایشی' in html, 'secret masked; gateway waits for test payment')
check(MERCHANT not in scalar("SELECT config FROM payment_gateways WHERE name='zarinpal'"), 'merchant encrypted in database')
admin_post('gateways', dict(action='gateway_enable', gateway='zarinpal', enabled='1'), 'پرداخت واقعی')
admin_post('gateways', dict(action='gateway_save', gateway='sep', terminal_id='12'), 'معتبر نیست')
admin_post('gateways', dict(action='gateway_save', gateway='zarinpal', merchant_id='', sandbox='1'), 'development')
check(scalar("SELECT enabled FROM payment_gateways WHERE name='zarinpal'") == 0, 'gateway stays disabled')

# The admin's real test payment (bank simulated through the CLI transport) verifies the credentials.
test_tx = json.loads(php("$_SESSION['admin_id']=1;$_SESSION['admin_login_at']=time();$r=gateway_test_start('zarinpal',zp(fn($o,$p)=>['data'=>['code'=>100,'authority'=>'A'.str_repeat('3',35)]]));$t=payment_return($r['tx'],['Authority'=>'A'.str_repeat('3',35),'Status'=>'OK'],zp(fn($o,$p)=>['data'=>['code'=>100,'ref_id'=>4040404]]));echo json_encode($t);"))
check(test_tx['status'] == 'verified' and test_tx['purpose'] == 'gateway_test', 'admin test payment verified')
status, html = get(client(), f'pay-return.php?tx={test_tx["id"]}')
check('اتصال درگاه تأیید شد' in html and 'tab=gateways' in html, 'test payment return page links to gateways')
admin_post('gateways', dict(action='gateway_enable', gateway='zarinpal', enabled='1'), 'تغییرات ذخیره شد')
admin_post('gateways', dict(action='gateway_roles', default='zarinpal', fallback=''), 'تغییرات ذخیره شد')
status, html = get(admin, 'admin.php?tab=gateways')
check('۴۰۴۰۴۰۴' in html and 'غیرفعال‌سازی' in html, 'gateway page shows verification reference and disable action')

# ---------- Global fee, discount and booking payment ----------
admin_post('settings', dict(action='settings', fee='۳٬۰۰۰', enabled='1', refund_on_cancel='1'), 'تغییرات ذخیره شد')
admin_post('settings', dict(action='settings', fee='500', enabled='1', refund_on_cancel='1'), '۱٬۰۰۰')
patient = client()
uid = login_user(patient, '09127770001')
h = hold(patient)
b = booking(h['id'])
check(b['fee_toman'] == 3000 and b['fee_source'] == 'global' and b['status'] == 'held', 'hold uses global fee snapshot')
status, html = get(patient, f'checkout.php?id={h["id"]}')
check(status == 200 and clean(html) and '۳٬۰۰۰' in html and 'کد تخفیف' in html and 'هزینه ویزیت' in html, 'checkout shows fee breakdown and discount form')
send(patient, 'booking/confirm', dict(id=h['id']), 409)

status, html = submit(admin, 'admin.php?tab=subscriptions&view=codes', dict(action='discount_save', code='NOBAT50', title='نیم‌بها', scope='booking', kind='percent', value='50', per_owner_limit='1', active='1'))
check('تغییرات ذخیره شد' in html, 'booking discount code created')
status, html = submit(patient, f'checkout.php?id={h["id"]}', dict(action='discount', code='nobat50'))
b = booking(h['id'])
check('کد تخفیف اعمال شد' in html and b['fee_toman'] == 1500 and b['discount_toman'] == 1500, 'discount applied to booking fee')
check('۱٬۵۰۰' in html and 'تخفیف' in html, 'checkout shows discount and payable')
status, html = submit(patient, f'checkout.php?id={h["id"]}', dict(action='discount', code='NOBAT50'))
check('قبلاً' in html, 'second code on same booking rejected')
check(scalar("SELECT status FROM discount_redemptions WHERE order_type='booking' AND order_id=?", [h['id']]) == 'reserved', 'redemption reserved until payment')

t = pay(uid, h['id'], 1500)
check(t['status'] == 'verified' and booking(h['id'])['status'] == 'confirmed', 'verified payment confirms booking')
check(scalar("SELECT status FROM discount_redemptions WHERE order_type='booking' AND order_id=?", [h['id']]) == 'used', 'discount marked used after payment')
check(scalar('SELECT COUNT(*) FROM payments WHERE booking_id=?', [h['id']]) == 1, 'payment row recorded once')
status, html = get(patient, f'pay-return.php?tx={t["id"]}')
check('پرداخت موفق بود' in html and 'نوبت شما قطعی شد' in html and f'receipt.php?id={h["id"]}' in html, 'return page confirms booking and links receipt')
status, html = get(patient, f'receipt.php?id={h["id"]}')
check('زرین‌پال' in html and fa_number(t['ref_num']) in html and 'تخفیف' in html, 'receipt shows gateway, bank reference and discount')
check(clean(html) and 'موفق' in html, 'receipt shows payment state')
other = client()
login_user(other, '09127770002')
check(get(other, f'checkout.php?id={h["id"]}')[0] == 403, 'another patient cannot open checkout')
check('پرداخت انجام نشد' in get(client(), 'pay-return.php?tx=99999999')[1], 'unknown transaction handled')

# ---------- Cancellation refund (policy on) ----------
b = booking(h['id'])
send(patient, 'patient/change', dict(id=h['id'], revision=b['revision'], operation='cancelled', reason='انصراف بیمار'))
check(scalar("SELECT status FROM payment_transactions WHERE id=?", [t['id']]) == 'refund_due', 'cancelled paid booking marks transaction for refund')
refund = db("SELECT * FROM refunds WHERE tx_id=?", [t['id']])[0]
check(refund['status'] == 'pending' and refund['amount_toman'] == 1500 and 'انصراف بیمار' in refund['reason'], 'refund case opened with reason and amount')
status, html = get(admin, 'admin.php?tab=payments')
check('موارد در انتظار استرداد' in html and '۱٬۵۰۰' in html, 'pending refund listed for admin')
admin_post('payments', dict(action='refund_handle', id=refund['id'], status='done', method='card', reference=''), 'شماره پیگیری')
admin_post('payments', dict(action='refund_handle', id=refund['id'], status='done', method='card', reference='۷۷۸۸۹۹۰۰'), 'تغییرات ذخیره شد')
check(scalar('SELECT status FROM refunds WHERE id=?', [refund['id']]) == 'done' and scalar('SELECT bank_reference FROM refunds WHERE id=?', [refund['id']]) == '77889900', 'refund closed with normalized reference')
check(scalar('SELECT status FROM payment_transactions WHERE id=?', [t['id']]) == 'refunded', 'transaction marked refunded')
admin_post('payments', dict(action='refund_handle', id=refund['id'], status='rejected', note='تکراری است'), 'رسیدگی شده')

# ---------- Fee rules: doctor > city > global ----------
city = scalar('SELECT city_id FROM clinics WHERE id=?', [CLINIC])
province = scalar('SELECT province_id FROM cities WHERE id=?', [city])
admin_post('settings', dict(action='fee_rule_save', scope='city', province_id=province, city_id=city, fee_toman='0', note='شهر رایگان', active='1'), 'تغییرات ذخیره شد')
h2 = hold(patient, 'بیمار شهر رایگان')
b = booking(h2['id'])
check(b['fee_toman'] == 0 and b['fee_source'] == 'city', 'city rule makes booking free')
status, html = get(patient, f'checkout.php?id={h2["id"]}')
check('ثبت رایگان نوبت' in html and 'name="gateway"' not in html and 'کد تخفیف' not in html, 'free checkout without payment form')
send(patient, 'booking/confirm', dict(id=h2['id']))
check(booking(h2['id'])['status'] == 'confirmed', 'free booking confirmed')
admin_post('settings', dict(action='fee_rule_save', scope='doctor', target_id=DOCTOR, fee_toman='2000', active='1'), 'تغییرات ذخیره شد')
h3 = hold(patient, 'بیمار قاعده پزشک')
check(booking(h3['id'])['fee_toman'] == 2000 and booking(h3['id'])['fee_source'] == 'doctor', 'doctor rule overrides city rule')
admin_post('settings', dict(action='fee_rule_save', scope='doctor', target_id=DOCTOR, fee_toman='2500', active='1'), 'تغییرات ذخیره شد')
check(scalar("SELECT COUNT(*) FROM booking_fee_rules WHERE scope='doctor' AND target_id=?", [DOCTOR]) == 1 and scalar("SELECT fee_toman FROM booking_fee_rules WHERE scope='doctor' AND target_id=?", [DOCTOR]) == 2500, 'saving a rule twice updates it')
admin_post('settings', dict(action='fee_rule_save', scope='doctor', target_id=DOCTOR, fee_toman='800', active='1'), '۱٬۰۰۰')
check(booking(h3['id'])['fee_toman'] == 2000, 'existing hold keeps its fee snapshot')
status, html = get(admin, 'admin.php?tab=settings')
check('شهر رایگان' in html and 'رایگان' in html and '۲٬۵۰۰' in html, 'rules listed for admin')

# Fixed discount leaving under 1,000 Toman becomes a free booking.
submit(admin, 'admin.php?tab=subscriptions&view=codes', dict(action='discount_save', code='MINUS1500', scope='booking', kind='fixed', value='1500', per_owner_limit='5', active='1'))
status, html = submit(patient, f'checkout.php?id={h3["id"]}', dict(action='discount', code='MINUS1500'))
b = booking(h3['id'])
check(b['fee_toman'] == 0 and b['discount_toman'] == 2000, 'remainder under gateway minimum becomes free')
send(patient, 'booking/confirm', dict(id=h3['id']))
check(booking(h3['id'])['status'] == 'confirmed' and scalar("SELECT status FROM discount_redemptions WHERE order_type='booking' AND order_id=?", [h3['id']]) == 'used', 'free confirmation consumes discount')

# Expired hold releases its reserved discount.
h4 = hold(patient, 'بیمار منقضی')
submit(patient, f'checkout.php?id={h4["id"]}', dict(action='discount', code='MINUS1500'))
db('UPDATE bookings SET expires_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=?', [h4['id']])
php('expire_holds();')
check(booking(h4['id'])['status'] == 'expired' and scalar("SELECT status FROM discount_redemptions WHERE order_type='booking' AND order_id=?", [h4['id']]) == 'released', 'expired hold releases discount')

# Released hold also releases discount.
h5 = hold(patient, 'بیمار انصراف')
submit(patient, f'checkout.php?id={h5["id"]}', dict(action='discount', code='MINUS1500'))
submit(patient, f'checkout.php?id={h5["id"]}', dict(action='release', revision=booking(h5['id'])['revision']))
check(booking(h5['id'])['status'] == 'cancelled' and scalar("SELECT status FROM discount_redemptions WHERE order_type='booking' AND order_id=?", [h5['id']]) == 'released', 'released hold releases discount')

# ---------- Late payment after expiry ----------
h6 = hold(patient, 'بیمار پرداخت دیرهنگام')
auth_counter[0] += 1
authority = 'A' + str(900 + auth_counter[0]).zfill(35)
php(LOGIN + f'start_payment((int)$argv[3],{ZP});', [uid, h6['id'], 2500, authority, 991])
db('UPDATE bookings SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=?', [h6['id']])
late = json.loads(php(f'echo json_encode(test_return($argv[5],["Authority"=>$argv[5],"Status"=>"OK"],{ZP}));', [uid, h6['id'], 2500, authority, 991]))
check(late['status'] == 'refund_due' and booking(h6['id'])['status'] == 'expired', 'payment after expiry never resurrects the hold')
check(scalar("SELECT COUNT(*) FROM refunds WHERE tx_id=? AND status='pending'", [late['id']]) == 1, 'late payment opens a refund case')
status, html = get(patient, f'pay-return.php?tx={late["id"]}')
check('سفارش تکمیل نشد' in html and 'بازگشت وجه' in html, 'patient sees refund message')

# ---------- Refund policy off ----------
db("DELETE FROM booking_fee_rules")
admin_post('settings', dict(action='settings', fee='2000', enabled='1'), 'تغییرات ذخیره شد')
check(scalar("SELECT value FROM settings WHERE `key`='booking_fee_refund_on_cancel'") == '0', 'refund policy can be turned off')
h7 = hold(patient, 'بیمار بدون استرداد')
t7 = pay(uid, h7['id'], 2000)
send(patient, 'patient/change', dict(id=h7['id'], revision=booking(h7['id'])['revision'], operation='cancelled', reason='بدون استرداد'))
check(scalar('SELECT status FROM payment_transactions WHERE id=?', [t7['id']]) == 'verified' and scalar('SELECT COUNT(*) FROM refunds WHERE tx_id=?', [t7['id']]) == 0, 'no refund case when policy is off')
status, html = get(patient, f'receipt.php?id={h7["id"]}')
check(clean(html), 'receipt of cancelled paid booking renders')

# Doctor-side cancellation with policy on.
admin_post('settings', dict(action='settings', fee='2000', enabled='1', refund_on_cancel='1'), 'تغییرات ذخیره شد')
h8 = hold(patient, 'بیمار لغو پزشک')
t8 = pay(uid, h8['id'], 2000)
php("$_SESSION['admin_id']=null;booking_refund_on_cancel((int)$argv[2],'لغو توسط پزشک');", [h8['id']])
check(scalar('SELECT status FROM refunds WHERE tx_id=?', [t8['id']]) == 'pending', 'staff cancellation path opens refund')

# ---------- Transactions report and export ----------
status, html = get(admin, 'admin.php?tab=payments&status=refund_due')
check(status == 200 and clean(html) and 'نیازمند بازگشت وجه' in html, 'status filter lists refund-due transactions')
status, html = get(admin, 'admin.php?tab=payments&purpose=booking&gateway=zarinpal')
check('کارمزد رزرو' in html and 'درآمد خالص' in html, 'purpose/gateway filters and revenue cards')
status, html = get(admin, 'admin.php?tab=payments&q=' + str(t7['ref_num']))
check('تراکنش‌ها (۱)' in html and fa_number(t7['ref_num']) in html, 'search by bank reference')
opener = admin
r = opener.open(BASE + 'admin.php?tab=payments&export=xlsx')
data = r.read()
z = zipfile.ZipFile(io.BytesIO(data))
sheet = z.read('xl/worksheets/sheet1.xml').decode()
check('rightToLeft="1"' in sheet and str(t7['ref_num']) in sheet and 'زرین‌پال' in sheet, 'payments Excel export is RTL with references')

# ---------- Patient API and fallback when gateways disappear ----------
h9 = hold(patient, 'بیمار درگاه غیرفعال')
php('test_gateway_off();')
send(patient, 'booking/payment', dict(id=h9['id']), 503)
status, html = get(patient, f'checkout.php?id={h9["id"]}')
check('درگاه پرداخت فعالی وجود ندارد' in html, 'checkout explains no active gateway')
status, html = get(admin, 'admin.php?tab=settings')
check('هیچ درگاه تأییدشده‌ای فعال نیست' in html, 'fee page warns when no gateway is active')

db("UPDATE settings SET value='0' WHERE `key`='booking_fee_enabled'")
db("UPDATE settings SET value=? WHERE `key`='max_active_bookings'", [max_active])
db("UPDATE discount_codes SET active=0 WHERE code IN ('NOBAT50','MINUS1500')")
db("UPDATE settings SET value=? WHERE `key`='invoice_vat_percent'", [vat_before or '0'])
import http_helpers
print(f"SUCCESS {http_helpers.checks} v0.9 checks")
