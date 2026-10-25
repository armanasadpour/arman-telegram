"""Phase 4: run after previous suites on disposable local nobatyar_test_http only."""
from http_helpers import *
import concurrent.futures, time

def php(code,args=[]):
    cmd=[PHP]+(['-c',PHPINI] if PHPINI else [])+['-r','require $argv[1];if(config()["database"]!=="nobatyar_test_http")exit(9);'+code,str(ROOT/'core.php')]+[str(a) for a in args]
    return subprocess.check_output(cmd).decode()
def scalar(sql,args=[]):return next(iter(db(sql,args)[0].values()))
def action(c,data,expected=200):
    status,r=api(c,'doctor/action',dict(doctor_id=did,**data),api(c,'session')[1]['data']['csrf'])
    check(status==expected, data['action']+' HTTP '+str(expected)+' '+str(r if status!=expected else ''))
    return r

db('DELETE FROM request_limits');db('DELETE FROM auth_rate_limits')
owner=client();uid=login_user(owner,'09127770001');patient=client();pid=login_user(patient,'09127770002');secretary=client();sid=login_user(secretary,'09127770003');other=client();otherid=login_user(other,'09127770004')
city=int(scalar('SELECT id FROM cities LIMIT 1'));province=int(scalar('SELECT province_id FROM cities WHERE id=?',[city]));spec=int(scalar('SELECT id FROM specialties LIMIT 1'))
db("INSERT INTO doctors(name,specialty,specialty_id,city_id,clinic_address,mobile,medical_code,active,review_status,subscription_until) VALUES('سارا احمدی','قلب',?,?,'مطب نمونه','09127770001','7770001',1,'approved','2099-01-01')",[spec,city]);did=int(scalar('SELECT id FROM doctors WHERE mobile=?',['09127770001']));db('INSERT INTO doctor_accounts VALUES(?,?)',[did,uid]);db("INSERT IGNORE INTO user_roles VALUES(?,'doctor')",[uid]);
action(owner,dict(action='clinic',clinic_name='مطب مرکزی',province_id=province,city_id=city,address='خیابان نمونه، پلاک ۲۴',phone='02177770000',active=1,is_primary=1));c1=int(scalar('SELECT id FROM clinics WHERE doctor_id=?',[did]));action(owner,dict(action='clinic',clinic_name='مطب دوم',province_id=province,city_id=city,address='خیابان دوم',phone='',active=1));c2=int(scalar('SELECT MAX(id) FROM clinics WHERE doctor_id=?',[did]));
action(owner,dict(action='service',name='ویزیت عمومی',duration_minutes=20,active=1));sv1=int(scalar('SELECT MAX(id) FROM doctor_services WHERE doctor_id=?',[did]));action(owner,dict(action='service',name='مشاوره تخصصی',duration_minutes=40,active=1));sv2=int(scalar('SELECT MAX(id) FROM doctor_services WHERE doctor_id=?',[did]));
action(owner,dict(action='clinic_settings',clinic_id=c1,default_duration=15,gap_minutes=10,daily_capacity=20));action(owner,dict(action='clinic_settings',clinic_id=c1,default_duration=0,gap_minutes=0,daily_capacity=20),422)
day=php('echo date("Y-m-d",strtotime("+2 days"));');weekday=int(php('echo date("w",strtotime($argv[2]));',[day]));jday=php('echo jdate($argv[2]);',[day]);
action(owner,dict(action='hours',clinic_id=c1,weekday=weekday,opens_at='09:00',closes_at='13:00'));action(owner,dict(action='hours',clinic_id=c2,weekday=weekday,opens_at='10:00',closes_at='11:00'),422);action(owner,dict(action='hours',clinic_id=c2,weekday=weekday,opens_at='14:00',closes_at='17:00'))
def available(clinic=c1,service=sv1,date=day):
    return api(client(),f'availability&clinic={clinic}&service={service}&date={date}')[1]['data']
a=available();check(len(a)==8 and a[1]['starts_at'].endswith('09:30:00'),'service duration plus gap grid');check(len(available(service=sv2))==5,'different service duration grid');check(available(date='2100-01-01')==[],'booking horizon limited');
status,r=api(patient,'booking/create',dict(clinic_id=c1,service_id=sv1,starts_at=a[0]['starts_at'],name='بیمار نمونه'),api(patient,'session')[1]['data']['csrf']);check(status==200,'service reservation creates immutable slot');bid=r['data']['id'];oldslot=scalar('SELECT slot_id FROM bookings WHERE id=?',[bid]);
check(all(x['starts_at']!=a[0]['starts_at'] for x in available(service=sv2)),'long service cannot overlap short service');check(len(api(patient,'notifications')[1]['data'])==1,'patient new booking notification');check(len(api(owner,'notifications')[1]['data'])==1,'doctor new booking notification');
# Immutable snapshots and clinic address protection.
action(owner,dict(action='service',id=sv1,name='ویزیت جدید',duration_minutes=25,active=1));check(scalar('SELECT service_name FROM slots WHERE id=?',[oldslot])=='ویزیت عمومی','service edit preserves original booked name');check(scalar('SELECT TIMESTAMPDIFF(MINUTE,starts_at,ends_at) FROM slots WHERE id=?',[oldslot])==20,'service edit preserves original booked duration');
action(owner,dict(action='clinic',id=c1,clinic_name='مطب مرکزی',province_id=province,city_id=city,address='نشانی تغییر یافته',phone='',active=1),422)
action(owner,dict(action='block',clinic_id=c1,starts_at=jday+' 08:00',ends_at=jday+' 13:00',reason='مرخصی پزشک',kind='leave'));block=int(scalar('SELECT MAX(id) FROM schedule_blocks WHERE doctor_id=?',[did]));check(available()==[],'leave closes all new availability');check(scalar('SELECT status FROM bookings WHERE id=?',[bid])=='confirmed','leave preserves confirmed booking');check(scalar('SELECT COUNT(*) FROM notifications WHERE booking_id=?',[bid])==2,'schedule change does not falsely announce booking cancellation');
action(owner,dict(action='block_remove',clinic_id=c1,id=block));
# Secretary least privilege and scoped authorization.
action(owner,dict(action='staff',clinic_id=c1,mobile='09127770003'));action(secretary,dict(action='clinic_settings',clinic_id=c1,default_duration=15,gap_minutes=0,daily_capacity=10),403);action(secretary,dict(action='service',name='خدمت غیرمجاز',duration_minutes=20),403);action(other,dict(action='hours',clinic_id=c1,weekday=weekday,opens_at='18:00',closes_at='19:00'),403)
action(owner,dict(action='staff',clinic_id=c1,mobile='09127770003',can_manage_schedule=1,can_manage_bookings=1));action(secretary,dict(action='block',clinic_id=c1,starts_at=jday+' 15:00',ends_at=jday+' 16:00',reason='آزمایش',kind='closed',all_clinics=1),403);action(secretary,dict(action='clinic_settings',clinic_id=c2,default_duration=15,gap_minutes=0,daily_capacity=20),403)
# No silent cancellation and optimistic revisions.
action(owner,dict(action='booking',id=bid,revision=2,operation='cancelled',reason='درخواست تغییر'),422);action(owner,dict(action='booking',id=bid,revision=2,operation='completed',reason='زودهنگام'),422)
action(secretary,dict(action='booking',id=bid,revision=2,operation='reschedule',reason='تغییر با هماهنگی',patient_contacted=1,target_clinic_id=c2,service_id=sv2,starts_at=jday+' 14:00'),403)
action(owner,dict(action='booking',id=bid,revision=2,operation='reschedule',reason='تغییر با هماهنگی بیمار',patient_contacted=1,target_clinic_id=c2,service_id=sv2,starts_at=jday+' 14:00'));check(scalar('SELECT slot_id FROM bookings WHERE id=?',[bid])!=oldslot,'reschedule creates new immutable slot');check(scalar('SELECT COUNT(*) FROM slots WHERE id=?',[oldslot])==1,'old slot retained after move');check(len(api(patient,'notifications')[1]['data'])==2,'patient reschedule notification');
action(owner,dict(action='booking',id=bid,revision=2,operation='cancelled',reason='نسخه قدیمی',patient_contacted=1),409);action(owner,dict(action='booking',id=bid,revision=3,operation='cancelled',reason='با اطلاع بیمار',patient_contacted=1));check(scalar('SELECT status FROM bookings WHERE id=?',[bid])=='cancelled','cancelled status saved');check(len(api(patient,'notifications')[1]['data'])==3,'cancellation notification stored');check(len(available(c2,sv2))>0,'cancellation releases availability')
# Capacity cannot be exceeded even when intervals are otherwise free.
action(owner,dict(action='clinic_settings',clinic_id=c1,default_duration=15,gap_minutes=10,daily_capacity=1));start=available()[0]['starts_at'];status,r=api(patient,'booking/create',dict(clinic_id=c1,service_id=sv1,starts_at=start,name='بیمار دوم'),api(patient,'session')[1]['data']['csrf']);check(status==200,'first daily-capacity reservation');b2=r['data']['id'];check(available()==[],'daily capacity blocks new availability');action(owner,dict(action='clinic_settings',clinic_id=c1,default_duration=15,gap_minutes=10,daily_capacity=20));
# History authorization, including legacy endpoint.
past=php('echo date("Y-m-d H:i:s",strtotime("-1 day"));');db('UPDATE slots SET starts_at=?,ends_at=DATE_ADD(?,INTERVAL 25 MINUTE) WHERE id=(SELECT slot_id FROM bookings WHERE id=?)',[past,past,b2]);action(owner,dict(action='booking',id=b2,revision=2,operation='completed',reason='مراجعه انجام شد'))
old_day=past[:10];status,r=api(secretary,f'doctor/calendar&doctor={did}&clinic={c1}&from={old_day}&to={day}');check(status==200 and not any(x['id']==b2 for x in r['data']),'secretary cannot read past without history permission');check(not any(x['id']==b2 for x in api(secretary,'bookings&role=secretary')[1]['data']['items']),'legacy API obeys history permission');action(owner,dict(action='staff',clinic_id=c1,mobile='09127770003',can_manage_schedule=1,can_manage_bookings=1,can_view_history=1));check(any(x['id']==b2 for x in api(secretary,f'doctor/calendar&doctor={did}&clinic={c1}&from={old_day}&to={day}&history=1')[1]['data']),'explicit history permission works')
# Real simultaneous PHP processes, different user sessions, same doctor/time.
start=available()[0]['starts_at']
worker='''$u=query('SELECT * FROM users WHERE id=?',[(int)$argv[2]])->fetch();$_SESSION['user_id']=$u['id'];$_SESSION['user_version']=$u['session_version'];$_SESSION['login_at']=time();try{echo reserve_service((int)$argv[3],(int)$argv[4],$argv[5],'بیمار همزمان');}catch(AuthError $e){echo 'REJECT:'.$e->status;}'''
with concurrent.futures.ThreadPoolExecutor(2) as ex:results=list(ex.map(lambda u:php(worker,[u,c1,sv1,start]),[pid,otherid]))
check(sum(x.isdigit() for x in results)==1 and sum(x=='REJECT:409' for x in results)==1,'concurrent reservation exactly one winner')
check(scalar("SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.doctor_id=? AND s.starts_at=? AND b.status='confirmed'",[did,start])==1,'no duplicate confirmed booking after concurrent workers')
# UI routes, CSRF, calendar modes and API range limits.
for tab in ['dashboard','profile','clinics','services','schedule','calendar','bookings','history','staff','notifications','events']:
 status,html=get(owner,f'doctor-panel.php?doctor={did}&tab={tab}');check(status==200 and 'Fatal error' not in html and 'Warning:' not in html and 'در پردازش درخواست خطایی رخ داد' not in html,'doctor UI '+tab)
for mode in ['week','month']:
 status,html=get(owner,f'doctor-panel.php?doctor={did}&tab=calendar&view={mode}&date='+urllib.parse.quote(jday));check(status==200 and ('week-grid' if mode=='week' else 'month-grid') in html,'calendar '+mode)
check(get(patient,f'doctor-panel.php?doctor={did}')[0]==403,'patient denied doctor workspace');check(api(patient,'doctor/action',dict(doctor_id=did,action='service',name='بدون توکن',duration_minutes=10))[0]==419,'doctor mutation requires CSRF');check(api(owner,f'doctor/calendar&doctor={did}&from=2026-01-01&to=2027-01-01')[0]==422,'calendar unbounded range denied');
status,html=get(client(),f'doctor.php?id={did}&clinic={c1}&date='+urllib.parse.quote(jday));check(status==200 and 'دکتر سارا احمدی' in html and 'ویزیت جدید' in html,'public profile with services');
check(scalar("SELECT COUNT(*) FROM appointment_events WHERE doctor_id=? AND event_type='booking.reschedule'",[did])==1,'reschedule audit once');check(scalar("SELECT COUNT(*) FROM appointment_events WHERE doctor_id=? AND event_type='doctor.block'",[did])==1,'schedule change audit recorded');
# Revocation is immediate, including notifications with patient details.
action(owner,dict(action='staff_remove',clinic_id=c1,id=sid));check(api(secretary,f'doctor/calendar&doctor={did}&from={old_day}&to={day}')[0]==403,'revoked secretary cannot read calendar');check(api(secretary,'notifications')[1]['data']==[],'revoked secretary cannot read booking notifications')
# Schedule removal and changed defaults never mutate prior confirmed bookings.
confirmed=int(scalar("SELECT b.id FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.doctor_id=? AND b.status='confirmed' LIMIT 1",[did]));snapshot=db('SELECT s.* FROM slots s JOIN bookings b ON b.slot_id=s.id WHERE b.id=?',[confirmed])[0]
hours=int(scalar('SELECT id FROM working_hours WHERE clinic_id=? AND active=1 LIMIT 1',[c1]));action(owner,dict(action='hours_remove',clinic_id=c1,id=hours));check(available()==[],'removing shift stops new reservations');check(db('SELECT s.* FROM slots s JOIN bookings b ON b.slot_id=s.id WHERE b.id=?',[confirmed])[0]==snapshot,'removing shift preserves confirmed slot snapshot');action(owner,dict(action='hours',clinic_id=c1,weekday=weekday,opens_at='09:00',closes_at='13:00'))
action(owner,dict(action='clinic_settings',clinic_id=c1,default_duration=30,gap_minutes=5,daily_capacity=20));check(db('SELECT s.* FROM slots s JOIN bookings b ON b.slot_id=s.id WHERE b.id=?',[confirmed])[0]==snapshot,'changed gap and defaults preserve booked snapshot')
# Subscription limits affect only new reservations.
db('UPDATE doctors SET subscription_until=? WHERE id=?',[day,did]);later=php('echo date("Y-m-d",strtotime($argv[2]." +7 days"));',[day]);check(available(date=later)!=[],'free plan: the end of a subscription does not limit future appointments (rc.20)');db("UPDATE doctors SET subscription_until=DATE_ADD(CURDATE(),INTERVAL 30 DAY) WHERE id=?",[did]);
# Critical profile edits require review but existing clinical records remain accessible.
action(owner,dict(action='profile',name='سارا احمدی جدید',specialty_id=spec,professional_title='پزشک متخصص',education='',bio='',experience_years=12));check(get(client(),f'doctor.php?id={did}')[0]==404,'name change hides public page pending review');check(api(owner,f'doctor/calendar&doctor={did}&from={old_day}&to={day}')[0]==200,'pending review preserves clinical record access');db("UPDATE doctors SET name='سارا احمدی',review_status='approved',active=1 WHERE id=?",[did])

# Store local-only browser test fixture; never package credentials or sessions.
jar=next(h.cookiejar for h in owner.handlers if isinstance(h,urllib.request.HTTPCookieProcessor));session=next(x.value for x in jar if x.name=='PHPSESSID');(ROOT.parent.parent/'runtime'/'phase4-fixture.json').write_text(json.dumps(dict(doctor=did,clinic=c1,service=sv1,date=jday,day=day,session=session)))
import http_helpers
print('SUCCESS',http_helpers.checks,'phase4 HTTP/domain checks')
