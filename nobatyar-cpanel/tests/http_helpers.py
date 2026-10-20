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
def submit(c,path,data):
    # Since rc.13 an administrator password alone signs in only while SMS is off. Suites written before that
    # test the panel, not the sign-in: their admin login runs with SMS briefly off (v25_admin_otp.py covers
    # the two-step sign-in itself and does not use this helper for it).
    if path.split('?')[0]=='admin.php' and data.get('action')=='login':
        keep=db("SELECT `key`,value FROM settings WHERE `key` IN ('sms_enabled','sms_dev_mode')")
        db("UPDATE settings SET value='0' WHERE `key` IN ('sms_enabled','sms_dev_mode')")
        try:
            return post(c,path,dict(data,csrf=token(c,path)))
        finally:
            for r in keep:
                db('UPDATE settings SET value=? WHERE `key`=?',[r['value'],r['key']])
    return post(c,path,dict(data,csrf=token(c,path)))
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
