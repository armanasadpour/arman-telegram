"""1.0.0-rc.19 over HTTP: doctor registration form — optional profile photo uploaded with the request
(checked before anything is saved), «مشخصات مطب» heading, map location without latitude/longitude boxes
(coordinates are hidden fields filled by the map; the admin panel keeps the boxes). Run after v12_http.py."""
from http_helpers import *
import uuid

city = db('SELECT c.id,c.province_id FROM cities c JOIN provinces p ON p.id=c.province_id WHERE c.active=1 AND p.active=1 ORDER BY c.id LIMIT 1')[0]
spec = db('SELECT id FROM specialties WHERE active=1 AND deleted_at IS NULL ORDER BY id LIMIT 1')
MOB = ['09127700601', '09127700602']
db('DELETE FROM request_limits')
for m in MOB:
    for t in ['doctor_accounts a JOIN doctors d ON d.id=a.doctor_id', 'clinics a JOIN doctors d ON d.id=a.doctor_id', 'appointment_events a JOIN doctors d ON d.id=a.doctor_id']:
        db(f'DELETE a FROM {t} WHERE d.mobile=?', [m])
    db('DELETE FROM doctors WHERE mobile=?', [m])
afx = json.loads((ROOT.parent.parent / 'runtime' / 'admin-fixture.json').read_text())
PNG = (ROOT.parent.parent / 'runtime' / 'test-photo.png').read_bytes() if (ROOT.parent.parent / 'runtime' / 'test-photo.png').exists() else None
if PNG is None:
    PNG = bytes.fromhex('89504e470d0a1a0a0000000d4948445200000001000000010806000000'
                        '1f15c4890000000d49444154789c6360f8cf00000301010018dd8db40000000049454e44ae426082')


def multipart(c, path, fields, files):
    boundary = uuid.uuid4().hex
    body = b''
    for k, v in fields.items():
        body += f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
    for k, (name, data, ctype) in files.items():
        body += f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"; filename="{name}"\r\nContent-Type: {ctype}\r\n\r\n'.encode() + data + b'\r\n'
    body += f'--{boundary}--\r\n'.encode()
    req = urllib.request.Request(BASE + path, body, {'Content-Type': 'multipart/form-data; boundary=' + boundary})
    try:
        r = c.open(req)
    except urllib.error.HTTPError as e:
        r = e
    return r.status, r.read().decode()


def form(mobile, csrf, **over):
    data = dict(csrf=csrf, name='عکس rc19', medical_code=str(int(mobile[-6:]) + 600000), specialty_id=spec[0]['id'] if spec else '',
                specialty_other='' if spec else 'عمومی', gender='', bio='', clinic_name='مطب rc19', province_id=city['province_id'],
                city_id=city['id'], district='', address='خیابان آزمون پلاک ۹', phone='', latitude='27.183210', longitude='56.266610',
                notes='', website='', consent='1')
    data.update(over)
    return data


# ---------- Form ----------
c = client()
login_user(c, MOB[0])
html = get(c, 'register.php')[1]
check('enctype="multipart/form-data"' in html and 'name="photo"' in html and 'accept="image/jpeg,image/png"' in html, 'registration form: optional profile photo')
check('مطب شما کجاست؟' in html and 'مطب اول' not in html, 'clinic section heading (no «مطب اول»)')
check('عرض جغرافیایی' not in html and 'طول جغرافیایی' not in html and '<input type="hidden" name="latitude"' in html and 'data-map-picker' in html and 'موقعیت فعلی من' in html, 'no latitude/longitude boxes; map and «موقعیت فعلی من» kept')

# ---------- Bad photo: nothing saved ----------
st, html = multipart(c, 'register.php', form(MOB[0], token(c, 'register.php')), {'photo': ('cv.png', b'not an image at all', 'image/png')})
check('فرمت یا ابعاد تصویر مجاز نیست' in html and not db('SELECT id FROM doctors WHERE mobile=?', [MOB[0]]), 'invalid photo reported on the form; no request saved')

# ---------- With photo ----------
st, html = multipart(c, 'register.php', form(MOB[0], token(c, 'register.php')), {'photo': ('me.png', PNG, 'image/png')})
d = db('SELECT id,photo_file FROM doctors WHERE mobile=?', [MOB[0]])
check('درخواست شما ثبت شد' in html and d and d[0]['photo_file'], 'registered with the profile photo')
check((ROOT / 'private' / 'photos' / d[0]['photo_file']).is_file(), 'photo stored (re-encoded) in the private folder')
cl = db('SELECT latitude,longitude FROM clinics WHERE doctor_id=?', [d[0]['id']])[0]
check(abs(float(cl['latitude']) - 27.18321) < 0.0001 and abs(float(cl['longitude']) - 56.26661) < 0.0001, 'map position saved from the hidden fields')

# ---------- Without photo ----------
c2 = client()
login_user(c2, MOB[1])
st, html = multipart(c2, 'register.php', form(MOB[1], token(c2, 'register.php'), latitude='', longitude=''), {})
d2 = db('SELECT id,photo_file FROM doctors WHERE mobile=?', [MOB[1]])
check('درخواست شما ثبت شد' in html and d2 and not d2[0]['photo_file'], 'photo is optional')

# ---------- Doctor panel and admin ----------
html = get(c, 'doctor-panel.php?tab=clinics')[1]
check('عرض جغرافیایی' not in html and '<input type="hidden" name="latitude"' in html, 'doctor panel clinic form: no coordinate boxes')
owner = client()
jar = next(h.cookiejar for h in owner.handlers if isinstance(h, urllib.request.HTTPCookieProcessor))
jar.set_cookie(http.cookiejar.Cookie(0, 'PHPSESSID', afx['owner'], None, False, '127.0.0.1', False, False, '/', True, False, None, False, None, None, {}))
html = get(owner, f'admin.php?tab=doctors&doctor={d[0]["id"]}')[1]
check('عرض جغرافیایی' in html, 'admin panel keeps the coordinate boxes (fallback when the map does not load)')

db('DELETE FROM request_limits')
print('SUCCESS', checks, 'rc.19 registration photo checks')
