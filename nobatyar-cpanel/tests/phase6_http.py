"""Requires disposable phase4 fixture after base + phase4 suites. No live bank call."""
from http_helpers import *
import concurrent.futures
fixture=json.loads((ROOT.parent.parent/'runtime'/'phase4-fixture.json').read_text());did=fixture['doctor'];c1=fixture['clinic'];sv=fixture['service'];day=fixture['day']
def php(code,args=[]):
 return subprocess.check_output([PHP]+(['-c',PHPINI] if PHPINI else [])+['-r','require $argv[1];if(config()["database"]!=="nobatyar_test_http")exit(9);require_once dirname($argv[1])."/tests/gateway_support.php";'+code,str(ROOT/'core.php')]+[str(x) for x in args]).decode()
def scalar(sql,args=[]):return next(iter(db(sql,args)[0].values()))
def send(c,route,data,status=200):
 actual,r=api(c,route,data,api(c,'session')[1]['data']['csrf']);check(actual==status,route+' expected '+str(status)+(' '+str(r) if actual!=status else ''));return r.get('data',{})
def doctor(data,status=200):return send(owner,'doctor/action',dict(doctor_id=did,**data),status)
def avail(date=day):return api(client(),f'availability&clinic={c1}&service={sv}&date={date}')[1]['data']
def hold(c,at,member=0,status=200):return send(c,'booking/hold',dict(clinic_id=c1,service_id=sv,starts_at=at,name='بیمار آزمایشی',family_member_id=member),status)
owner=client();login_user(owner,'09127770001');p1=client();u1=login_user(p1,'09128880001');p2=client();u2=login_user(p2,'09128880002');p3=client();u3=login_user(p3,'09128880003');p4=client();u4=login_user(p4,'09128880004')
db('DELETE FROM request_limits');db("UPDATE settings SET value='0' WHERE `key`='booking_fee_enabled'")
send(p1,'patient/family/save',dict(name='عضو خانواده اول',relationship='فرزند',birth_date='1400/01/01'));family=api(p1,'patient/family')[1]['data'][0]['id'];send(p2,'patient/family/save',dict(id=family,name='دسترسی غیرمجاز',relationship='فرزند'),404);send(p1,'patient/family/save',dict(name='عضو خانواده اول',relationship='فرزند'),422)
doctor(dict(action='clinic_settings',clinic_id=c1,default_duration=30,gap_minutes=5,daily_capacity=20,slot_capacity=3));at=avail()[-1]['starts_at'];h1=hold(p1,at,family);check(h1['status']=='held' and re.match(r'^[1-9][0-9]{11}$',h1['tracking_code']),'hold has a 12-digit tracking number and is not confirmed');check(next(a for a in avail() if a['starts_at']==at)['remaining']==2,'held seat deducted from cohort capacity');hold(p2,at,family,403);h2=hold(p2,at);h3=hold(p3,at);hold(p4,at,status=409);check(not any(a['starts_at']==at for a in avail()),'all three seats occupied including holds');hold(p1,avail()[0]['starts_at'],status=409)
send(p2,'booking/confirm',dict(id=h1['id']),403);send(p1,'booking/confirm',dict(id=h1['id']));send(p1,'booking/confirm',dict(id=h1['id']));check(scalar("SELECT COUNT(*) FROM appointment_events WHERE booking_id=? AND event_type='booking.created'",[h1['id']])==1,'free confirmation replay does not duplicate event')
send(p1,'patient/family/save',dict(id=family,operation='archive'));check(scalar('SELECT patient_name FROM bookings WHERE id=?',[h1['id']])=='عضو خانواده اول','archiving family preserves booking identity');
send(p2,'patient/change',dict(id=h2['id'],revision=h2['revision'],operation='release'));check(any(a['starts_at']==at for a in avail()),'release returns one seat');db('UPDATE bookings SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=?',[h3['id']]);check(next(a for a in avail() if a['starts_at']==at)['remaining']==2,'expired hold frees capacity without cron');send(p3,'booking/confirm',dict(id=h3['id']),409)
# Patient cancellation and move policy, stale revisions, and ownership.
db("UPDATE settings SET value='24' WHERE `key` IN ('patient_cancel_hours','patient_move_hours')");send(p2,'patient/change',dict(id=h1['id'],revision=2,operation='cancelled',reason='غیرمجاز'),403)
new=avail()[0]['starts_at'];send(p1,'patient/change',dict(id=h1['id'],revision=2,operation='reschedule',reason='انتخاب بیمار',clinic_id=c1,service_id=sv,starts_at=new));check(scalar('SELECT s.starts_at FROM slots s JOIN bookings b ON b.slot_id=s.id WHERE b.id=?',[h1['id']])==new,'patient reschedule moves existing booking');send(p1,'patient/change',dict(id=h1['id'],revision=2,operation='cancelled',reason='نسخه قدیمی'),409)
db("UPDATE settings SET value='720' WHERE `key`='patient_cancel_hours'");send(p1,'patient/change',dict(id=h1['id'],revision=3,operation='cancelled',reason='دیرهنگام'),409);db("UPDATE settings SET value='24' WHERE `key`='patient_cancel_hours'");send(p1,'patient/change',dict(id=h1['id'],revision=3,operation='cancelled',reason='با تصمیم بیمار'));check(scalar('SELECT status FROM bookings WHERE id=?',[h1['id']])=='cancelled','patient cancellation persisted')
# New holds fail after a schedule change; existing confirmed bookings remain intact.
at=avail()[0]['starts_at'];h=hold(p4,at);jstart=php('echo jdate($argv[2],true);',[at]);jend=php('echo jdate(date("Y-m-d H:i:s",strtotime($argv[2]." +1 hour")),true);',[at]);doctor(dict(action='block',clinic_id=c1,starts_at=jstart,ends_at=jend,reason='تعطیلی ناگهانی',kind='closed'));block=int(scalar('SELECT MAX(id) FROM schedule_blocks WHERE doctor_id=?',[did]));send(p4,'booking/confirm',dict(id=h['id']),409);send(p4,'patient/change',dict(id=h['id'],revision=1,operation='release'));doctor(dict(action='block_remove',clinic_id=c1,id=block))
# Six distinct processes race for three seats in the exact same cohort.
ids=[]
for n in range(10,16):
 c=client();ids.append(login_user(c,'091288800'+str(n)))
at=avail()[-1]['starts_at'];worker='''$u=query('SELECT * FROM users WHERE id=?',[(int)$argv[2]])->fetch();$_SESSION['user_id']=$u['id'];$_SESSION['user_version']=$u['session_version'];$_SESSION['login_at']=time();try{echo hold_booking(['clinic_id'=>(int)$argv[3],'service_id'=>(int)$argv[4],'starts_at'=>$argv[5],'name'=>'رزرو همزمان']);}catch(AuthError $e){echo 'REJECT:'.$e->status;}'''
with concurrent.futures.ThreadPoolExecutor(6) as ex:result=list(ex.map(lambda uid:php(worker,[uid,c1,sv,at]),ids))
check(sum(x.isdigit() for x in result)==3 and result.count('REJECT:409')==3,'six parallel workers exactly three seats acquired');check(scalar("SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.clinic_id=? AND s.starts_at=? AND b.status='held' AND b.expires_at>NOW()",[c1,at])==3,'database cohort capacity never exceeded')
# Distinct start times compete for the last daily seat.
db("UPDATE bookings b JOIN slots s ON s.id=b.slot_id SET b.status='expired' WHERE s.doctor_id=? AND b.status='held'",[did]);day2=php('echo date("Y-m-d",strtotime($argv[2]." +7 days"));',[day]);doctor(dict(action='clinic_settings',clinic_id=c1,default_duration=30,gap_minutes=5,daily_capacity=1,slot_capacity=3));choices=avail(day2)
with concurrent.futures.ThreadPoolExecutor(2) as ex:result=list(ex.map(lambda args:php(worker,args),[[ids[0],c1,sv,choices[0]['starts_at']],[ids[1],c1,sv,choices[1]['starts_at']]]))
check(sum(x.isdigit() for x in result)==1 and result.count('REJECT:409')==1,'daily capacity serialized across distinct times');doctor(dict(action='clinic_settings',clinic_id=c1,default_duration=30,gap_minutes=5,daily_capacity=20,slot_capacity=3))
# Account active limit also includes confirmed bookings.
db("UPDATE settings SET value='1' WHERE `key`='max_active_bookings'");h=hold(p2,avail()[0]['starts_at']);send(p2,'booking/confirm',dict(id=h['id']));hold(p2,avail(day2)[-1]['starts_at'],status=409);db("UPDATE settings SET value='5' WHERE `key`='max_active_bookings'")
# Real gateway adapter with CLI-only transport responses; never a live charge.
php("test_gateway('zarinpal',['merchant_id'=>'11111111-1111-1111-1111-111111111111']);")
db("UPDATE settings SET value='1' WHERE `key`='booking_fee_enabled'");db("UPDATE settings SET value='1000' WHERE `key`='booking_fee'");h=hold(p3,avail()[1]['starts_at']);send(p3,'booking/confirm',dict(id=h['id']),409)
login='''$u=query('SELECT * FROM users WHERE id=?',[(int)$argv[2]])->fetch();$_SESSION['user_id']=$u['id'];$_SESSION['user_version']=$u['session_version'];$_SESSION['login_at']=time();'''
authority='A'+'1'*35
request=login+'''echo start_payment((int)$argv[3],zp(function($method,$data){if($method!=='request'||$data['amount']!==10000||$data['currency']!=='IRR')throw new RuntimeException('incorrect amount');return ['data'=>['code'=>100,'authority'=>$GLOBALS['argv'][4]]];}))['url'];'''
url=php(request,[u3,h['id'],authority]);check(url.endswith(authority),'payment request amount fixed in rial and authority stored');db("UPDATE settings SET value='5000' WHERE `key`='booking_fee'")
verify='''echo json_encode(test_return($argv[2],['Authority'=>$argv[2],'Status'=>'OK'],zp(function($method,$data){if($data['amount']!==10000)throw new RuntimeException('changed amount');return ['data'=>['code'=>100,'ref_id'=>6010001]];})));'''
r=json.loads(php(verify,[authority]));check(r['status']=='verified' and scalar('SELECT status FROM bookings WHERE id=?',[h['id']])=='confirmed','server verification confirms original fee snapshot');r=json.loads(php("echo json_encode(test_return($argv[2],['Authority'=>$argv[2],'Status'=>'OK'],function(){throw new RuntimeException('must not call twice');}));",[authority]));check(r['status']=='verified' and scalar('SELECT COUNT(*) FROM payments WHERE booking_id=?',[h['id']])==1,'duplicate callback creates one payment only')
# Paid after expiry: do not resurrect capacity; record refund requirement.
db("UPDATE settings SET value='1000' WHERE `key`='booking_fee'");h=hold(p4,avail()[2]['starts_at']);authority2='A'+'2'*35;php(request,[u4,h['id'],authority2]);db('UPDATE bookings SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE id=?',[h['id']]);r=json.loads(php("echo json_encode(test_return($argv[2],['Authority'=>$argv[2],'Status'=>'OK'],zp(fn()=>['data'=>['code'=>101,'ref_id'=>6010002]])));",[authority2]));check(r['status']=='refund_due' and scalar('SELECT status FROM bookings WHERE id=?',[h['id']])=='expired','late verified payment records refund instead of booking');
db("UPDATE settings SET value='0' WHERE `key`='booking_fee_enabled'");php("test_gateway_off();")
# Search and personal UI / receipt privacy.
status,r=api(client(),f'doctors/search&q='+urllib.parse.quote('مطب مرکزی')+'&sort=first&days=14');check(status==200 and any(x['clinic_id']==c1 and x['first_available'] for x in r['data']['items']),'search clinic and sort first available');check(api(client(),'doctors/search&sort=bad')[0]==422,'invalid sort rejected')
for tab in ['upcoming','past','held','family','notifications']:
 status,html=get(p1,'patient.php?tab='+tab);check(status==200 and 'Fatal error' not in html and 'Warning:' not in html,'patient tab '+tab)
check(get(p1,'receipt.php?id='+str(h['id']))[0]==403,'receipt cannot be read by another patient');check(get(p4,'receipt.php?id='+str(h['id']))[0]==200,'owner reads receipt and refund state');check(get(client(),'payment-callback.php?Status=OK&Authority=A'+'9'*35)[0]==200,'forged payment callback produces handled error');check(scalar("SELECT COUNT(*) FROM bookings WHERE status='confirmed'")>0,'forged callback preserves data')
import http_helpers
fixture['patient_session']=next(x.value for h in p1.handlers if isinstance(h,urllib.request.HTTPCookieProcessor) for x in h.cookiejar if x.name=='PHPSESSID');(ROOT.parent.parent/'runtime'/'phase6-fixture.json').write_text(json.dumps(fixture))
print('SUCCESS',http_helpers.checks,'phase6 checks')
