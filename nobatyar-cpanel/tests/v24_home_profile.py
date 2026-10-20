"""1.0.0-rc.12 over HTTP: province and city chosen inside the home search card (the city applies at once and
returns to the home page); doctor profile shows three squares — rating, reviews, successful appointments —
and no clinic count. Run after phase4_http.py (uses its fixture)."""
from http_helpers import *

f = json.loads((ROOT.parent.parent / 'runtime' / 'phase4-fixture.json').read_text())

# ---------- Home: province and city in the search card ----------
c = client()
status, html = get(c, 'index.php')
card = html.split('class="hero-box"', 1)[1].split('class="hero-search"', 1)[0] if 'class="hero-box"' in html else ''
check(status == 200 and 'class="hero-loc" action="city.php"' in card and 'name="province"' in card and 'name="city"' in card and 'data-autosubmit' in card and 'data-geo' in card, 'search card: province and city selects (auto-apply)')
check('class="hero-city"' not in html and 'name="back" value="index.php"' in card, 'no separate city page link; choosing returns to the home page')
city = db("SELECT c.id,c.province_id,c.name FROM cities c JOIN provinces p ON p.id=c.province_id WHERE c.active=1 AND p.active=1 ORDER BY c.id LIMIT 1")[0]
req = urllib.request.Request(BASE + f"city.php?back=index.php&province={city['province_id']}&city={city['id']}")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


jar = next(h.cookiejar for h in c.handlers if isinstance(h, urllib.request.HTTPCookieProcessor))
nr = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(jar), NoRedirect())
try:
    r = nr.open(req)
except urllib.error.HTTPError as e:
    r = e
check(r.status in (302, 303) and r.headers.get('Location', '').endswith(f"index.php?city={city['id']}") and 'nobatyar_city=' in r.headers.get('Set-Cookie', ''), 'choosing a city saves it and goes straight back to the home page')
jar.extract_cookies(r, req)
status, html = get(c, 'index.php')
card = html.split('class="hero-box"', 1)[1].split('class="hero-search"', 1)[0]
check(re.search(rf'<option value="{city["province_id"]}" selected>', card) is not None and re.search(rf'<option value="{city["id"]}" selected>', card) is not None, 'next visit: current province and city pre-selected')
check('class="hero"' in html and 'results-list' not in html, 'home page (not the results list) after choosing')

# ---------- Doctor profile: three squares ----------
status, html = get(client(), f"doctor.php?id={f['doctor']}")
stats = html.split('class="dp-stats"', 1)[1].split('</div>', 1)[0]
labels = re.findall(r'<small>([^<]+)</small>', stats)
check(len(re.findall(r'class="dp-stat ', stats)) == 3, 'three squares')
check(labels[0].startswith('امتیاز') and labels[1] == 'نظر' and labels[2] == 'نوبت موفق', 'rating, reviews, successful appointments')
check('مطب' not in stats, 'no clinic count')
done = db("SELECT COUNT(*) n FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE b.status='completed' AND s.doctor_id=?", [f['doctor']])[0]['n']
reviews = db("SELECT COUNT(*) n FROM reviews WHERE doctor_id=? AND status='approved'", [f['doctor']])[0]['n']
fa = lambda v: str(v).translate(str.maketrans('0123456789', '۰۱۲۳۴۵۶۷۸۹'))
nums = re.findall(r'<b>([^<]+)</b>', stats)
check(nums[1] == fa(reviews) and nums[2] == fa(done), 'review and successful-appointment counts from real data')

print('SUCCESS', checks, 'rc.12 home and profile checks')
