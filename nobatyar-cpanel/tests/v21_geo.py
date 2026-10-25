"""1.0.0-rc.9 over HTTP: city picker with province and city fields (works without JavaScript), and bulk
activate/deactivate of provinces and cities in the admin panel (with the province's cities, owner and
catalog roles only, nothing deleted). Restores every changed row at the end."""
from http_helpers import *


def scalar(sql, args=[]):
    rows = db(sql, args)
    return next(iter(rows[0].values())) if rows else None


def clean(html):
    return 'Warning:' not in html and 'Fatal error' not in html and 'Notice:' not in html


def admin_login(email='admin@example.test', password='Test-password-012345'):
    c = client()
    submit(c, 'admin.php', dict(action='login', email=email, password=password))
    return c


# ---------- City picker ----------
prov = db("SELECT p.id,p.name FROM provinces p WHERE p.active=1 AND (SELECT COUNT(*) FROM cities c WHERE c.province_id=p.id AND c.active=1)>=3 ORDER BY p.id LIMIT 1")[0]
cities = db('SELECT id,name FROM cities WHERE province_id=? AND active=1 ORDER BY sort_order,name', [prov['id']])
g = client()
status, html = get(g, 'city.php')
check(status == 200 and clean(html) and 'name="province"' in html and 'name="city"' in html and 'تأیید شهر' in html, 'city picker: province field, city field, confirm button')
check('class="chip-row"' not in html.split('cp-search')[0], 'no long list of province chips')
status, html = get(g, f'city.php?province={prov["id"]}&city=')
check(all(f'value="{c["id"]}"' in html for c in cities[:3]) and html.count('<option') >= len(cities), 'without JavaScript: the chosen province fills the city field')
pick = cities[1]
req = urllib.request.Request(BASE + f'city.php?province={prov["id"]}&city={pick["id"]}')
r = g.open(req)
html = r.read().decode()
check('index.php?city=' + str(pick['id']) in r.url and pick['name'] in html, 'choosing a city selects it and returns to the home page')
status, html = get(g, 'city.php')
check(f'value="{pick["id"]}" selected' in html and f'value="{prov["id"]}" selected' in html, 'next visit: current province and city pre-selected')
status, html = get(g, 'city.php?select=' + str(cities[2]['id']))
check(status == 200, 'old «select» links still work')
status, html = get(g, 'city.php?q=' + urllib.parse.quote(pick['name']))
check(pick['name'] in html and 'cp-search" open' in html, 'name search kept (collapsed under the fields)')

# ---------- Bulk activate / deactivate ----------
owner = admin_login()
status, html = get(owner, 'admin.php?tab=provinces')
check('id="geo-bulk"' in html and 'data-row-check' in html and 'شهرهای این استان‌ها هم' in html, 'provinces: checkboxes and bulk bar')
status, html = get(owner, f'admin.php?tab=cities&province={prov["id"]}')
check('id="geo-bulk"' in html and html.count('data-row-check') == db('SELECT COUNT(*) n FROM cities WHERE province_id=?', [prov['id']])[0]['n'], 'cities: one checkbox per city')

two = [cities[-1]['id'], cities[-2]['id']]
status, html = submit(owner, f'admin.php?tab=cities&province={prov["id"]}', {'action': 'geo_bulk', 'type': 'cities', 'state': '0', 'ids[]': two[0]})
data = urllib.parse.urlencode([('csrf', token(owner, 'admin.php?tab=cities')), ('action', 'geo_bulk'), ('type', 'cities'), ('state', '0'), ('ids[]', two[0]), ('ids[]', two[1])]).encode()
html = owner.open(BASE + f'admin.php?tab=cities&province={prov["id"]}', data).read().decode()
check('۲ شهر غیرفعال شد' in html and all(scalar('SELECT active FROM cities WHERE id=?', [i]) == 0 for i in two), 'two cities turned off at once')
status, html = get(client(), f'city.php?province={prov["id"]}&city=')
check(all(f'value="{i}"' not in html for i in two), 'inactive cities leave the public picker')
check(scalar("SELECT COUNT(*) FROM audit_log WHERE action='geo.bulk'") >= 1, 'bulk change in the activity log')
data = urllib.parse.urlencode([('csrf', token(owner, 'admin.php?tab=cities')), ('action', 'geo_bulk'), ('type', 'cities'), ('state', '1'), ('ids[]', two[0]), ('ids[]', two[1])]).encode()
html = owner.open(BASE + 'admin.php?tab=cities', data).read().decode()
check(all(scalar('SELECT active FROM cities WHERE id=?', [i]) == 1 for i in two), 'and back on')

before = {r['id']: r['active'] for r in db('SELECT id,active FROM cities WHERE province_id=?', [prov['id']])}
data = urllib.parse.urlencode([('csrf', token(owner, 'admin.php?tab=provinces')), ('action', 'geo_bulk'), ('type', 'provinces'), ('state', '0'), ('cascade', '1'), ('ids[]', prov['id'])]).encode()
html = owner.open(BASE + 'admin.php?tab=provinces', data).read().decode()
check(scalar('SELECT active FROM provinces WHERE id=?', [prov['id']]) == 0 and scalar('SELECT COUNT(*) FROM cities WHERE province_id=? AND active=1', [prov['id']]) == 0 and 'همراه' in html, 'province off together with all its cities')
status, html = get(client(), 'city.php')
# Only the province list counts: a city option elsewhere on the page may share the same number.
provinces = html.split('name="province"', 1)[-1].split('</select>', 1)[0]
check(f'value="{prov["id"]}"' not in provinces, 'inactive province leaves the picker')
data = urllib.parse.urlencode([('csrf', token(owner, 'admin.php?tab=provinces')), ('action', 'geo_bulk'), ('type', 'provinces'), ('state', '1'), ('ids[]', prov['id'])]).encode()
owner.open(BASE + 'admin.php?tab=provinces', data).read()
check(scalar('SELECT active FROM provinces WHERE id=?', [prov['id']]) == 1 and scalar('SELECT COUNT(*) FROM cities WHERE province_id=? AND active=1', [prov['id']]) == 0, 'province back on; cities untouched without «شهرهای این استان‌ها هم»')
for cid, active in before.items():
    db('UPDATE cities SET active=? WHERE id=?', [active, cid])
check(scalar('SELECT COUNT(*) FROM cities WHERE province_id=? AND active=1', [prov['id']]) == sum(before.values()), 'cities restored')

status, html = submit(owner, 'admin.php?tab=cities', dict(action='geo_bulk', type='cities', state='0'))
check('تیک بزنید' in html, 'nothing ticked: clear message')
status, html = submit(owner, 'admin.php?tab=cities', {'action': 'geo_bulk', 'type': 'users', 'state': '0', 'ids[]': '1'})
check('نامعتبر' in html, 'only provinces or cities')
db("DELETE FROM admins WHERE email='finance-geo@example.test'")
db("INSERT INTO admins(email,password_hash,role,active) VALUES('finance-geo@example.test',?,'finance',1)", [subprocess.check_output([PHP, '-r', "echo password_hash('Finance-pass-12345',PASSWORD_DEFAULT);"]).decode()])
fin = admin_login('finance-geo@example.test', 'Finance-pass-12345')
status, html = post(fin, 'admin.php?tab=cities', {'csrf': token(fin, 'admin.php?tab=dashboard'), 'action': 'geo_bulk', 'type': 'cities', 'state': '0', 'ids[]': cities[0]['id']})
check(scalar('SELECT active FROM cities WHERE id=?', [cities[0]['id']]) == 1, 'roles without the catalog permission cannot change places')
db("DELETE FROM admins WHERE email='finance-geo@example.test'")

sid = lambda c: next(x.value for h in c.handlers if isinstance(h, urllib.request.HTTPCookieProcessor) for x in h.cookiejar if x.name == 'PHPSESSID')
(ROOT.parent.parent / 'runtime' / 'geo-fixture.json').write_text(json.dumps(dict(province=prov['id'], city=cities[0]['id'], city_name=cities[0]['name'], owner=sid(owner))))
print('SUCCESS', checks, 'rc.9 geo checks')
