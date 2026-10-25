"""1.0.0-rc.17 over HTTP: Settings → «درآمد و مالیات». Subscription sales off: no subscription menu or card in
the doctor and center panels, the page and purchases refused, doctors and centers without a valid
subscription still listed and bookable, admin expiry widgets hidden. VAT percent added to the patient booking
fee: checkout line and total, bank amount, booking and transaction VAT shares, receipt with the seller, revenue
report without VAT. Everything is restored at the end. Run after v26/v27 (uses the phase 6 and center fixtures).
Bank calls use CLI-only injected transports; no live bank request is made."""
from http_helpers import *

fx = json.loads((ROOT.parent.parent / 'runtime' / 'phase6-fixture.json').read_text())
cfx = json.loads((ROOT.parent.parent / 'runtime' / 'center-fixture.json').read_text())
afx = json.loads((ROOT.parent.parent / 'runtime' / 'admin-fixture.json').read_text())
DOCTOR, CLINIC, SERVICE = fx['doctor'], fx['clinic'], fx['service']


def php(code, args=[]):
    cmd = [PHP] + (['-c', PHPINI] if PHPINI else []) + [
        '-r', 'require $argv[1];if(config()["database"]!=="nobatyar_test_http")exit(9);require_once dirname($argv[1])."/tests/gateway_support.php";' + code,
        str(ROOT / 'core.php')] + [str(x) for x in args]
    return subprocess.check_output(cmd).decode()


def scalar(sql, args=[]):
    rows = db(sql, args)
    return next(iter(rows[0].values())) if rows else None


def with_session(sid):
    c = client()
    jar = next(h.cookiejar for h in c.handlers if isinstance(h, urllib.request.HTTPCookieProcessor))
    jar.set_cookie(http.cookiejar.Cookie(0, 'PHPSESSID', sid, None, False, '127.0.0.1', False, False, '/', True, False, None, False, None, None, {}))
    return c


def fa(v):
    return str(v).translate(str.maketrans('0123456789', '۰۱۲۳۴۵۶۷۸۹'))


def free_slot():
    busy = {str(r['d']) for r in db("SELECT DATE(s.starts_at) d FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.doctor_id=? AND (b.status='confirmed' OR (b.status='held' AND b.expires_at>NOW()))", [DOCTOR])}
    for days in range(3, 60):
        d = php(f'echo date("Y-m-d",strtotime("+{days} days"));')
        if d in busy:
            continue
        avail = api(client(), f'availability&clinic={CLINIC}&service={SERVICE}&date={d}')[1]['data']
        if avail:
            return avail[0]['starts_at']
    raise AssertionError('no free slot')


LOGIN = """$u=query('SELECT * FROM users WHERE id=?',[(int)$argv[2]])->fetch();$_SESSION['user_id']=$u['id'];$_SESSION['user_version']=$u['session_version'];$_SESSION['login_at']=time();"""
ZP = """zp(function($op,$p)use($argv){if($op==='request'){if($p['amount']!==(int)$argv[4]*10)throw new RuntimeException('amount '.$p['amount']);return ['data'=>['code'=>100,'authority'=>$argv[5]]];}return ['data'=>['code'=>100,'ref_id'=>(int)$argv[6],'card_pan'=>'5022****7777']];})"""

admin = with_session(afx['owner'])
saved = {r['key']: r['value'] for r in db("SELECT `key`,value FROM settings WHERE `key` IN ('subscriptions_enabled','invoice_vat_percent','invoice_seller_name','invoice_seller_economic_code','booking_fee_enabled')")}
until_before = scalar('SELECT subscription_until FROM doctors WHERE id=?', [DOCTOR])
center_until = scalar('SELECT subscription_until FROM centers WHERE id=?', [cfx['center']])
doctor_mobile = scalar('SELECT u.mobile FROM doctor_accounts a JOIN users u ON u.id=a.user_id WHERE a.doctor_id=?', [DOCTOR])
rule_before = db("SELECT fee_toman,active,note FROM booking_fee_rules WHERE scope='doctor' AND target_id=?", [DOCTOR])
gateways_before = db('SELECT * FROM payment_gateways')
gw_settings = {r['key']: r['value'] for r in db("SELECT `key`,value FROM settings WHERE `key` IN ('payment_default_gateway','payment_fallback_gateway')")}
db('DELETE FROM request_limits')

try:
    # ---------- Settings panel ----------
    html = get(admin, 'admin.php?tab=settings')[1]
    check('درآمد و مالیات' in html and 'name="subscriptions_enabled"' in get(admin, 'admin.php?tab=subscriptions&view=settings')[1] and 'name="invoice_vat_percent"' in html and 'id="revenue"' in html, 'settings: revenue and tax panel')
    check(re.search(r'name="subscriptions_enabled"[^>]*checked', html) is not None, 'subscription sales on by default')
    html = submit(admin, 'admin.php?tab=settings', dict(action='revenue_settings', invoice_vat_percent='۴۰'))[1]
    check('بین ۰ تا ۳۰' in html, 'VAT over 30% rejected')
    html = submit(admin, 'admin.php?tab=settings', dict(action='revenue_settings', invoice_vat_percent='۹', invoice_seller_name='شرکت نوبت‌یار نمونه', invoice_seller_economic_code='411111111111'))[1]
    check('فروش اشتراک غیرفعال شد' in html, 'subscription sales turned off')
    check(scalar("SELECT value FROM settings WHERE `key`='subscriptions_enabled'") == '0' and scalar("SELECT value FROM settings WHERE `key`='invoice_vat_percent'") == '9', 'switch and VAT saved')
    check(scalar("SELECT COUNT(*) FROM audit_log WHERE action='settings.revenue'") >= 1, 'change recorded in the activity log')

    # ---------- Doctor without a valid subscription is still listed and bookable ----------
    db('UPDATE doctors SET subscription_until=DATE_SUB(CURDATE(),INTERVAL 40 DAY) WHERE id=?', [DOCTOR])
    status, html = get(client(), f'doctor.php?id={DOCTOR}')
    check(status == 200 and 'data-book' in html or 'دریافت نوبت' in html, 'expired subscription: doctor page still public')
    start = free_slot()
    check(bool(start), 'expired subscription: online times still offered')

    # ---------- Doctor panel: no subscription menu, page or purchase ----------
    d = client()
    login_user(d, doctor_mobile)
    html = get(d, f'doctor-panel.php?doctor={DOCTOR}')[1]
    check('tab=subscription' not in html and 'subscription-card' not in html and 'اشتراک و فاکتورها' not in html, 'doctor panel: no subscription menu or card')
    status, html = get(d, f'doctor-panel.php?doctor={DOCTOR}&tab=subscription')
    check(status == 404 and 'صفحه پیدا نشد' in html, 'doctor panel: subscription page gone')
    plan = scalar("SELECT id FROM subscription_plans WHERE active=1 ORDER BY id LIMIT 1")
    if plan:
        orders = scalar('SELECT COUNT(*) FROM subscription_orders WHERE doctor_id=?', [DOCTOR])
        submit(d, f'doctor-panel.php?doctor={DOCTOR}&tab=dashboard', dict(action='sub_order', plan_id=plan, kind='new'))
        check(scalar('SELECT COUNT(*) FROM subscription_orders WHERE doctor_id=?', [DOCTOR]) == orders, 'doctor cannot buy a subscription while sales are off')

    # ---------- Center panel ----------
    db('UPDATE centers SET subscription_until=DATE_SUB(CURDATE(),INTERVAL 40 DAY) WHERE id=?', [cfx['center']])
    status, html = get(with_session(cfx['session']), f'center.php?center={cfx["center"]}')
    if status == 200:
        check('tab=subscription' not in html and 'اشتراک مرکز' not in html, 'center panel: no subscription menu or card')
    status, html = get(client(), f'clinic.php?id={cfx["center"]}')
    check(status == 200, 'expired center subscription: center page still public')

    # ---------- Admin ----------
    html = get(admin, 'admin.php?tab=dashboard')[1]
    check('اشتراک‌های نزدیک انقضا' not in html and 'اشتراک نزدیک انقضا' not in html, 'admin dashboard: expiry widgets hidden')
    html = get(admin, 'admin.php?tab=subscriptions')[1]
    check('فروش اشتراک غیرفعال است' in html, 'admin subscriptions page explains the switch')
    check('cron' and 'غیرفعال' in php('require_once dirname($argv[1])."/lib/jobs.php";echo cron_subscription_warnings();'), 'no expiry reminders while sales are off')

    # ---------- VAT on the booking fee ----------
    db("INSERT INTO booking_fee_rules(scope,target_id,fee_toman,active,note) VALUES('doctor',?,5000,1,'v28') ON DUPLICATE KEY UPDATE fee_toman=5000,active=1", [DOCTOR])
    php("test_gateway_off();test_gateway('zarinpal',['merchant_id'=>'11111111-1111-1111-1111-111111111111']);")
    p = client()
    login_user(p, '09127700401')
    uid = scalar('SELECT id FROM users WHERE mobile=?', ['09127700401'])
    db("UPDATE users SET name='بیمار مالیات',gender='female',birth_date='1990-01-01',insurance='tamin',id_type='foreign' WHERE id=?", [uid])
    csrf = api(p, 'session')[1]['data']['csrf']
    st, r = api(p, 'booking/hold', dict(clinic_id=CLINIC, service_id=SERVICE, starts_at=free_slot(), name='بیمار مالیات'), csrf)
    check(st == 200, 'hold with an expired subscription while sales are off')
    bid = r['data']['id']
    co = get(p, f'checkout.php?id={bid}')[1]
    bill = co.split('inv-title', 1)[1]
    check('مالیات بر ارزش افزوده (۹٪)' in bill and '+ ۴۵۰ تومان' in bill and '۵٬۴۵۰ تومان' in bill.split('inv-total')[1], 'checkout: VAT line and total with VAT')
    check('(با مالیات)' in co, 'pay bar shows the amount with VAT')
    authority, ref = 'A' + '28' * 17 + '2', 771001
    url = php(LOGIN + f'echo start_payment((int)$argv[3],{ZP})["url"];', [uid, bid, 5450, authority, ref])
    check(url.endswith('/StartPay/' + authority), 'bank asked for fee + VAT (54,500 Rial)')
    b = db('SELECT * FROM bookings WHERE id=?', [bid])[0]
    check(int(b['vat_toman']) == 450 and float(b['vat_percent']) == 9.0 and int(b['fee_toman']) == 5000, 'VAT fixed on the booking')
    tx = json.loads(php(f'echo json_encode(test_return($argv[5],["Authority"=>$argv[5],"Status"=>"OK"],{ZP}));', [uid, bid, 5450, authority, ref]))
    check(tx['status'] == 'verified' and int(tx['amount_toman']) == 5450 and int(tx['vat_toman']) == 450, 'payment verified; VAT share stored on the transaction')
    check(scalar('SELECT status FROM bookings WHERE id=?', [bid]) == 'confirmed', 'booking confirmed after paying fee + VAT')
    rc = get(p, f'receipt.php?id={bid}')[1]
    check('مالیات بر ارزش افزوده (۹٪)' in rc and '۴۵۰ تومان' in rc and '۵٬۴۵۰ تومان' in rc and 'شرکت نوبت‌یار نمونه' in rc and fa('411111111111') in rc, 'receipt: VAT, total and seller')
    rev = json.loads(php('echo json_encode(payment_revenue(date("Y-m-d"),date("Y-m-d")));'))
    row = next(x for x in rev['by_purpose'] if x['purpose'] == 'booking')
    check(int(row['vat']) >= 450 and int(row['gross']) + int(row['vat']) == int(scalar("SELECT SUM(amount_toman) FROM payment_transactions WHERE purpose='booking' AND status IN ('verified','refund_due') AND test_mode=0 AND DATE(verified_at)=CURDATE()")), 'revenue report: VAT reported separately, not as income')
    html = get(admin, 'admin.php?tab=payments')[1]
    check('دریافتی (بدون مالیات)' in html, 'payments report column without VAT')

    # ---------- Back on: subscription rules return ----------
    html = submit(admin, 'admin.php?tab=settings', dict(action='revenue_settings', subscriptions_enabled='1', invoice_vat_percent='0'))[1]
    check('فروش اشتراک فعال شد' in html, 'subscription sales turned back on')
    status, html = get(d, f'doctor-panel.php?doctor={DOCTOR}&tab=subscription')
    check(status == 200 and 'tab=subscription' in get(d, f'doctor-panel.php?doctor={DOCTOR}')[1], 'doctor panel: subscription menu back')
    status, html = get(client(), f'doctor.php?id={DOCTOR}')
    check(status == 200, 'expired subscription keeps the doctor listed (free plan, rc.20)')
    co = get(p, f'checkout.php?id={bid}')[1]
    check('مالیات بر ارزش افزوده' not in co, 'VAT 0: no VAT line')
finally:
    db('UPDATE doctors SET subscription_until=? WHERE id=?', [until_before, DOCTOR])
    php(f'entitlement_sync({DOCTOR});')
    db('UPDATE centers SET subscription_until=? WHERE id=?', [center_until, cfx['center']])
    if rule_before:
        r0 = rule_before[0]
        db("UPDATE booking_fee_rules SET fee_toman=?,active=?,note=? WHERE scope='doctor' AND target_id=?", [r0['fee_toman'], r0['active'], r0['note'], DOCTOR])
    else:
        db("DELETE FROM booking_fee_rules WHERE scope='doctor' AND target_id=?", [DOCTOR])
    for k in ['subscriptions_enabled', 'invoice_vat_percent', 'invoice_seller_name', 'invoice_seller_economic_code']:
        db('INSERT INTO settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [k, saved.get(k, '1' if k == 'subscriptions_enabled' else ('0' if k == 'invoice_vat_percent' else ''))])
    for g in gateways_before:
        cols = [k for k in g if k not in ('name', 'id')]
        db('UPDATE payment_gateways SET ' + ','.join(f'`{k}`=?' for k in cols) + ' WHERE name=?', [g[k] for k in cols] + [g['name']])
    for k in ['payment_default_gateway', 'payment_fallback_gateway']:
        db('INSERT INTO settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [k, gw_settings.get(k, '')])
    db('DELETE FROM request_limits')

print('SUCCESS', checks, 'rc.17 revenue and VAT checks')
