"""Only for a fresh disposable localhost test install. Python standard library.
Set NOBATYAR_TEST=1. Start PHP/MySQL first, create an EMPTY database named
nobatyar_test_http. TEST_PHP and TEST_PHP_INI select the PHP CLI used for assertions.
Never run this against a populated or production site.
"""
import os, urllib.request, urllib.parse, http.cookiejar, re, json, subprocess, datetime
from pathlib import Path
assert os.environ.get('NOBATYAR_TEST')=='1', 'Disposable tests require opt-in'
BASE='http://127.0.0.1:8088/'
ROOT=Path(__file__).resolve().parents[1]
PHP=os.environ.get('TEST_PHP','php')
PHPINI=os.environ.get('TEST_PHP_INI')
checks=0

def db(sql,args=[]):
    code='''$c=require $argv[1];if($c['database']!=='nobatyar_test_http')exit(9);$p=new PDO('mysql:host='.$c['host'].';dbname='.$c['database'].';charset=utf8mb4',$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$p->exec("SET time_zone='+03:30'");$i=json_decode(stream_get_contents(STDIN),true);$s=$p->prepare($i[0]);$s->execute($i[1]);echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));'''
    cmd=[PHP]+(['-c',PHPINI] if PHPINI else [])+['-r',code,str(ROOT/'config.php')]
    return json.loads(subprocess.check_output(cmd,input=json.dumps([sql,args]).encode()))
def client():
    return urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def get(c,path):
    try:r=c.open(BASE+path)
    except urllib.error.HTTPError as e:r=e
    return r.status,r.read().decode()
def post(c,path,data):
    try:r=c.open(BASE+path,urllib.parse.urlencode(data).encode())
    except urllib.error.HTTPError as e:r=e
    return r.status,r.read().decode()
def token(c,path):
    status,html=get(c,path)
    assert status==200,(status,html)
    return re.search(r'name="csrf" value="([^"]+)"',html).group(1)
def submit(c,path,data):return post(c,path,dict(data,csrf=token(c,path)))
def check(ok,label):
    global checks
    assert ok,label
    checks+=1;print('PASS',label,flush=True)
def save(c,path,data):
    status,html=submit(c,path,data)
    check(status==200 and 'تغییرات ذخیره شد.' in html,'save '+data['action'])
    return html
def api(c,route,data=None,csrf=None):
    headers={}
    body=None
    if data is not None:
        body=json.dumps(data).encode();headers['Content-Type']='application/json'
        if csrf:headers['X-CSRF-Token']=csrf
    req=urllib.request.Request(BASE+'api.php?route='+route,data=body,headers=headers)
    try:r=c.open(req)
    except urllib.error.HTTPError as e:r=e
    return r.status,json.loads(r.read().decode())
def login_user(c,phone):
    status,r=api(c,'session');csrf=r['data']['csrf']
    jar=next(h.cookiejar for h in c.handlers if isinstance(h,urllib.request.HTTPCookieProcessor))
    sid=next(x.value for x in jar if x.name=='PHPSESSID')
    code="""$i=json_decode(stream_get_contents(STDIN),true);session_id($i[0]);require $argv[1];$c=config();if($c['database']!=='nobatyar_test_http')exit(9);query('DELETE FROM auth_rate_limits');$plain=null;$r=otp_request($i[1],function($m,$code)use(&$plain){$plain=$code;return ['messageid'=>'TEST-1'];});echo json_encode([$r,$plain]);"""
    cmd=[PHP]+(['-c',PHPINI] if PHPINI else [])+['-r',code,str(ROOT/'core.php')]
    challenge,plain=json.loads(subprocess.check_output(cmd,input=json.dumps([sid,phone]).encode()))
    status,r=api(c,'auth/verify',dict(challenge_id=challenge['challenge_id'],code=plain),csrf)
    if status==200 and r['ok'] and r['data']['status']=='profile_required':
        # New numbers get an account only after a real first and last name (since 1.0.0-rc.3).
        status,r=api(c,'auth/complete',dict(first_name='آزمون',last_name='بیمار',accept_terms=True),r['data']['csrf'])
    check(status==200 and r['ok'],'API OTP login with CLI-only fake transport')
    return r['data']['id']
admin=client();anon=client()
status,html=submit(admin,'install.php',dict(host='127.0.0.1',database='nobatyar_test_http',username='root',password='',email='admin@example.test',admin_password='Test-password-012345'))
check(status==200 and 'ورود مدیرکل' in html,'fresh HTTP installation')
check(get(admin,'install.php')[0]==403,'installer locked after config')
# rc.20: suites written before the free/Pro model keep the old semantics — every feature free, and clinics,
# services and secretaries limited only by a Pro plan's own limits. tests/v31_pro_plan.py checks the default
# catalog (Pro-only features and free-plan limits) and restores this afterwards.
db("INSERT INTO settings(`key`,value) VALUES('pro_features',?) ON DUPLICATE KEY UPDATE value=VALUES(value)",[json.dumps({'pro':{'clinics':True,'services':True,'secretaries':True},'limits':{'clinics':None,'services':None,'secretaries':None}})])
check(post(admin,'admin.php',dict(action='login'))[0]==419,'CSRF required')
status,html=submit(admin,'admin.php',dict(action='login',email='admin@example.test',password='Test-password-012345'))
check('مدیریت نوبت‌یار' in html,'admin login')
# 0.7.0 ships the full official province/city list; the admin still creates and edits entries.
check(len(db('SELECT id FROM provinces'))==31 and len(db('SELECT id FROM cities'))>=1250,'fresh install seeds 31 provinces and all cities')
save(admin,'admin.php?tab=provinces',dict(action='taxonomy',type='provinces',name='استان آزمایشی',active='1'))
test_province=db("SELECT id FROM provinces WHERE name='استان آزمایشی'")[0]['id']
save(admin,'admin.php?tab=cities',dict(action='taxonomy',type='cities',name='شهر آزمایشی',province_id=test_province,active='1',latitude='27.0',longitude='54.0'))
check(db("SELECT latitude FROM cities WHERE name='شهر آزمایشی'")[0]['latitude'] is not None,'admin-created city with map position')
status,html=submit(admin,'admin.php?tab=cities',dict(action='taxonomy',type='cities',name='خارج',province_id=test_province,active='1',latitude='48.85',longitude='2.35'))
check('خارج از محدوده ایران' in html,'coordinates outside Iran rejected')
p1=db("SELECT id FROM provinces WHERE name='هرمزگان'")[0]['id'];p2=db("SELECT id FROM provinces WHERE name='فارس'")[0]['id']
c1=db("SELECT id FROM cities WHERE name='بستک' AND province_id=?",[p1])[0]['id'];c2=db("SELECT id FROM cities WHERE name='لار' AND province_id=?",[p2])[0]['id']
province_count=len(db('SELECT id FROM provinces'))
save(admin,'admin.php?tab=specialties',dict(action='taxonomy',type='specialties',name='قلب',active='1'))
sp=db('SELECT id FROM specialties')[0]['id']
data=dict(name='پزشک آزمایشی <script>alert(1)</script>',mobile='۰۹۱۲۰۰۰۰۰۰۱',medical_code='۱۲۳۴۵',specialty_id=sp,bio='معرفی آزمایشی',clinic_name='مطب بستک',province_id=p1,city_id=c1,address='آدرس بستک',phone='۰۷۶۴۴۴۴۴۴۴۴',consent='1')
patient_id=login_user(anon,'09120000001')
status,html=submit(anon,'register.php',dict(data,city_id=c2))
check('شهر انتخاب‌شده' in html,'cross province city rejected')
status,html=submit(anon,'register.php',data)
check('درخواست شما ثبت شد' in html,'public registration')
d=db('SELECT * FROM doctors')[0];did=d['id']
check(d['mobile']=='09120000001' and d['review_status']=='pending' and d['active']==0,'digits normalized and registration pending')
check(db('SELECT COUNT(*) n FROM clinics')[0]['n']==1,'initial clinic created atomically')
check('پزشک آزمایشی' not in get(anon,'index.php?province=0&city=0')[1],'pending doctor hidden')
status,html=submit(anon,'register.php',data)
# 1.0.0-rc.14: a registered doctor sees the request status instead of a second form.
check('در انتظار بررسی مدیر' in html and 'name="medical_code"' not in html and db('SELECT COUNT(*) n FROM doctors')[0]['n']==1,'duplicate registration rejected')
path=f'admin.php?tab=doctors&doctor={did}'
save(admin,path,dict(action='review',id=did,status='approved',active='1',review_note='بررسی نمونه'))
# rc.20: an approved doctor is listed on the free plan (a subscription only unlocks Pro features).
check('پزشک آزمایشی' in get(anon,'index.php?province=0&city=0')[1],'approved doctor listed without a subscription (free plan)')
save(admin,path,dict(action='subscription',id=did,until='۱۴۷۷/۱۰/۱۲'))
html=get(anon,'index.php?province=0&city=0')[1]
check('پزشک آزمایشی' in html and '<script>alert(1)</script>' not in html,'approved subscribed doctor visible with escaped name')
save(admin,path,dict(action='clinic',doctor_id=did,clinic_name='مطب لار',province_id=p2,city_id=c2,address='آدرس لار',phone='07133333333',active='1',is_primary='1'))
clinics=db('SELECT * FROM clinics ORDER BY id');cl1,cl2=[r['id'] for r in clinics]
check(sum(r['is_primary'] for r in clinics)==1,'exactly one primary clinic')
check(db('SELECT city_id FROM doctors')[0]['city_id']==c2,'legacy primary fields synchronized')
check('مطب لار' in get(anon,f'index.php?province={p2}&city={c2}')[1] and 'مطب بستک' not in get(anon,'index.php')[1],'city filter includes matching clinic only and persists')
future='۱۴۰۶/۰۱/۱۵ ۱۰:۰۰'
save(admin,path,dict(action='slot',doctor_id=did,clinic_id=cl1,starts_at=future,capacity=2))
status,html=submit(admin,path,dict(action='slot',doctor_id=did,clinic_id=cl2,starts_at=future,capacity=2))
check('تکراری' in html,'same doctor simultaneous slots prohibited')
slot=db('SELECT id FROM slots')[0]['id']
check('آدرس بستک' in get(anon,f'book.php?slot={slot}')[1],'booking displays selected clinic address')
status,html=submit(anon,f'book.php?slot={slot}',dict(slot=slot,name='بیمار آزمایشی',phone='۰۹۱۲۲۲۲۲۲۲۲'))
check('نوبت ثبت شد' in html and len(db('SELECT * FROM bookings'))==1,'booking succeeds')
status,html=submit(anon,f'book.php?slot={slot}',dict(slot=slot,name='بیمار آزمایشی',phone='09122222222'))
check('برای این بیمار' in html and len(db('SELECT * FROM bookings'))==1,'duplicate booking prohibited')
status,html=submit(admin,path,dict(action='clinic',id=cl1,doctor_id=did,clinic_name='مطب بستک',province_id=p1,city_id=c1,address='نشانی تغییر یافته',phone='07644444444',active='1'))
check('رزرو ثبت‌شده دارد' in html and db('SELECT address FROM clinics WHERE id=?',[cl1])[0]['address']=='آدرس بستک','booked address protected')
status,html=submit(admin,path,dict(action='delete_clinic',id=cl1,doctor_id=did))
check('این اطلاعات تکراری' in html and len(db('SELECT * FROM clinics'))==2,'clinic with slots cannot delete')
status,html=submit(admin,'admin.php?tab=provinces',dict(action='delete_taxonomy',id=p1,type='provinces'))
check('این اطلاعات تکراری' in html and len(db('SELECT * FROM provinces'))==province_count,'referenced province cannot delete via UI')
status,html=submit(anon,'admin.php',dict(action='review',id=did,status='approved',active='1'))
check('ابتدا وارد شوید' in html,'anonymous mutation blocked')
save(admin,'admin.php?tab=specialties',dict(action='taxonomy',type='specialties',id=sp,name='قلب'))
check(get(anon,f'book.php?slot={slot}')[0]==404,'inactive specialty blocks direct booking')
save(admin,'admin.php?tab=specialties',dict(action='taxonomy',type='specialties',id=sp,name='قلب',active='1'))
save(admin,'admin.php?tab=provinces',dict(action='taxonomy',type='provinces',id=p1,name='هرمزگان'))
check(get(anon,f'book.php?slot={slot}')[0]==404,'inactive province blocks direct booking')
save(admin,'admin.php?tab=provinces',dict(action='taxonomy',type='provinces',id=p1,name='هرمزگان',active='1'))
other_patient=client();other_patient_id=login_user(other_patient,'09122222223')
status,html=submit(other_patient,f'book.php?slot={slot}',dict(slot=slot,name='بیمار دوم',phone='09122222223'))
check('نوبت ثبت شد' in html,'remaining capacity bookable')
third_patient=client();login_user(third_patient,'09122222224')
status,html=submit(third_patient,f'book.php?slot={slot}',dict(slot=slot,name='بیمار سوم',phone='09122222224'))
check('ظرفیت' in html and len(db('SELECT * FROM bookings'))==2,'overbooking rejected')
save(admin,path,dict(action='clinic',id=cl1,doctor_id=did,clinic_name='مطب بستک',province_id=p1,city_id=c1,address='آدرس بستک',phone='07644444444'))
check(get(anon,f'book.php?slot={slot}')[0]==404,'inactive clinic blocks direct booking')
save(admin,path,dict(action='clinic',id=cl1,doctor_id=did,clinic_name='مطب بستک',province_id=p1,city_id=c1,address='آدرس بستک',phone='07644444444',active='1'))
status,html=submit(admin,path,dict(action='clinic',id=cl2,doctor_id=did,clinic_name='مطب لار',province_id=p2,city_id=c2,address='آدرس لار',phone='07133333333'))
check('ابتدا مطب فعال دیگری' in html,'primary clinic cannot be deactivated without replacement')
status,html=submit(admin,path,dict(action='clinic',id=cl1,doctor_id=999999,clinic_name='مطب بستک',province_id=p1,city_id=c1,address='آدرس بستک',phone='07644444444',active='1'))
check('پزشک پیدا نشد' in html,'clinic update rejects wrong owner')
status,html=submit(admin,'admin.php?tab=settings' ,dict(action='settings',fee='10000',enabled='1'))
check('کارمزد مثبت' in html,'positive fee remains blocked')
save(admin,path,dict(action='review',id=did,status='rejected',review_note='رد نمونه'))
check(get(anon,f'doctor.php?id={did}')[0]==404,'rejected doctor hidden by direct URL')
status,html=submit(admin,'upgrade.php',dict(action='upgrade',backup='1'))
check('ارتقا کامل شد' in html and len(db('SELECT * FROM clinics'))==2,'fresh schema upgrade is no-op')
# RBAC and privacy tests: no privilege from query parameters or body fields.
status,r=api(anon,'bookings&role=patient');check(status==200 and r['data']['total']==1,'patient sees only own booking')
status,r=api(other_patient,'bookings&role=doctor');check(status==403,'patient cannot select doctor API role')
check(get(other_patient,'panel.php?role=secretary')[0]==403,'patient cannot access secretary panel')
status,r=api(other_patient,'profile',dict(name='نام تازه',role='doctor'),api(other_patient,'session')[1]['data']['csrf']);check(status==200 and 'doctor' not in api(other_patient,'session')[1]['data']['user']['roles'],'profile update cannot promote role')
status,r=api(other_patient,'profile',dict(name='نام نامعتبر'));check(status==419,'JSON API requires CSRF')
status,r=api(client(),'bookings');check(status==401,'anonymous API denied')
check(db('SELECT phone FROM bookings WHERE patient_user_id=?',[patient_id])[0]['phone']=='09120000001','booking ignores spoofed phone')
# Re-enable doctor for clinical scopes; create a second clinic with a booked slot.
save(admin,path,dict(action='review',id=did,status='approved',active='1'))
second_slot=db('SELECT MAX(id) id FROM slots')[0]['id']+1
db('INSERT INTO slots(id,doctor_id,clinic_id,starts_at,capacity) VALUES(?,?,?,?,?)',[second_slot,did,cl2,'2027-04-05 11:00:00',1])
db('INSERT INTO bookings(slot_id,patient_name,phone,tracking_code,patient_user_id) VALUES(?,?,?,?,?)',[second_slot,'بیمار مطب دوم','09122222223','OTHER-CLINIC',other_patient_id])
secretary=client();sec_id=login_user(secretary,'09123333333')
userpath=f'admin.php?tab=users&user={sec_id}'
save(admin,userpath,dict(action='grant_scope',user_id=sec_id,role='secretary',clinic_id=cl1))
status,r=api(secretary,'bookings&role=secretary');check(status==200 and r['data']['total']==2 and all(b['clinic']=='مطب بستک' for b in r['data']['items']),'secretary limited to assigned clinic')
status,r=api(secretary,f'bookings&role=secretary&clinic={cl2}');check(status==403,'secretary cannot request another clinic')
check(get(secretary,'panel.php?role=secretary')[0]==200,'secretary panel functional')
save(admin,userpath,dict(action='grant_scope',user_id=sec_id,role='clinic',clinic_id=cl2))
status,r=api(secretary,'bookings&role=clinic');check(status==200 and r['data']['total']==1 and r['data']['items'][0]['tracking_code']=='OTHER-CLINIC','clinic role separately scoped')
check(get(secretary,'panel.php?role=clinic')[0]==200,'clinic panel functional')
status,r=api(anon,'bookings&role=doctor');check(status==200 and r['data']['total']==3,'doctor sees own clinics')
save(admin,userpath,dict(action='revoke_scope',user_id=sec_id,role='secretary',clinic_id=cl1))
check(api(secretary,'bookings&role=secretary')[0]==403,'scope revocation effective immediately')
save(admin,userpath,dict(action='user_status',user_id=sec_id))
check(api(secretary,'bookings&role=clinic')[0]==401,'account suspension revokes live session')
status,r=api(client(),'session');check('csrf' in r['data'],'API session bootstrap')
status,r=api(other_patient,'auth/request',dict(mobile='09121111111'),api(other_patient,'session')[1]['data']['csrf']);check(status==503,'unconfigured live SMS fails closed')
status,html=submit(admin,'admin.php?tab=sms',dict(action='sms_settings',template='otpTemplate',daily_limit=500))
check('تغییرات ذخیره شد' in html,'SMS settings saved without live sends')
status,r=api(other_patient,'auth/logout',{},api(other_patient,'session')[1]['data']['csrf']);check(status==200 and api(other_patient,'bookings')[0]==401,'API logout revokes session')
status,html=submit(admin,'admin.php',dict(action='logout'))
check('ورود مدیرکل' in html,'POST logout')
# Actual phase-1 HTTP upgrade, with original credentials and booking fixtures.
legacy_code="""$c=require $argv[1];if($c['database']!=='nobatyar_test_http')exit(9);$p=new PDO('mysql:host=127.0.0.1;dbname=nobatyar_test_http;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$p->exec('SET FOREIGN_KEY_CHECKS=0');foreach($p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t)$p->exec('DROP TABLE `'.$t.'`');$p->exec('SET FOREIGN_KEY_CHECKS=1');foreach(explode(";\n",file_get_contents($argv[2])) as $q)if(trim($q)!=='')$p->exec($q);$q=$p->prepare('INSERT INTO admins(email,password_hash) VALUES(?,?)');$q->execute(['admin@example.test',password_hash('Test-password-012345',PASSWORD_DEFAULT)]);$p->exec("INSERT INTO cities(id,province,name) VALUES(1,'هرمزگان','بستک')");$p->exec("INSERT INTO doctors(id,name,specialty,city_id,clinic_address,subscription_until) VALUES(1,'پزشک قدیمی','قلب',1,'آدرس قدیمی','2099-01-01')");$p->exec("INSERT INTO slots(id,doctor_id,starts_at) VALUES(1,1,'2090-01-01 10:00:00')");$p->exec("INSERT INTO bookings(slot_id,patient_name,phone,tracking_code) VALUES(1,'بیمار نمونه','09120000001','KEEP-CODE')");"""
cmd=[PHP]+(['-c',PHPINI] if PHPINI else [])+['-r',legacy_code,str(ROOT/'config.php'),str(ROOT/'migrations/001.sql')]
subprocess.check_call(cmd)
legacy=client()
check(get(legacy,'index.php')[0]==503,'phase 1 detected before upgrade')
status,html=submit(legacy,'upgrade.php',dict(email='admin@example.test',password='wrong',backup='1'))
check('اطلاعات ورود مدیر اشتباه است' in html,'upgrade requires original admin password')
status,html=submit(legacy,'upgrade.php',dict(email='admin@example.test',password='Test-password-012345',backup='1'))
check('ارتقا کامل شد' in html,'actual phase 1 HTTP upgrade succeeds')
row=db('SELECT tracking_code,legacy_tracking_code FROM bookings')[0];check(row['legacy_tracking_code']=='KEEP-CODE' and re.match(r'^[1-9][0-9]{11}$',row['tracking_code']),'HTTP upgrade keeps the old tracking code and gives a 12-digit number')
check('پزشک قدیمی' in get(legacy,'index.php')[1],'upgraded doctor remains publicly visible')
check('ورود یا ثبت‌نام' in get(client(),'book.php?slot=1')[1],'legacy booking now requires verified login');legacy_patient=client();login_user(legacy_patient,'09120000001');check('آدرس قدیمی' in get(legacy_patient,'book.php?slot=1')[1],'legacy slot displays migrated address');check(api(legacy_patient,'bookings')[1]['data']['total']==0,'legacy unverified booking not auto claimed')
status,html=submit(legacy,'upgrade.php',dict(backup='1'))
check('ارتقا کامل شد' in html and len(db('SELECT * FROM clinics'))==1,'repeated HTTP upgrade preserves one clinic')
print(f'SUCCESS {checks} HTTP checks')
