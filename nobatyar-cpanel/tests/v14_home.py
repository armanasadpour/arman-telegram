"""1.0.0-rc.2 homepage over HTTP: landing sections from real data, draft/preview/publish of the layout,
section order and visibility, card counts, images, featured doctors, homepage specialties, FAQ with live
settings, health articles (draft, scheduled, published, unsafe markup), site pages, footer settings,
search suggestions, empty city state, only valid doctors shown, friendly URLs, sitemap and robots."""
from http_helpers import *

fixture = json.loads((ROOT.parent.parent / 'runtime' / 'phase6-fixture.json').read_text())
DOCTOR = fixture['doctor']
PNG = (ROOT.parent.parent / 'runtime' / 'test-photo.png').read_bytes()


def admin_login():
    c = client()
    submit(c, 'admin.php', dict(action='login', email='admin@example.test', password='Test-password-012345'))
    return c


def snippet(html):
    m = re.search(r'<p class="(?:error|notice)"[^>]*>(.*?)</p>', html, re.S)
    return re.sub(r'<[^>]+>', '', m.group(1)).strip()[:200] if m else ''


def act(c, path, data, expect, label, files=None):
    if files:
        boundary = '----nobatyarhome'
        fields = dict(data, csrf=token(c, path))
        body = b''
        for k, v in fields.items():
            for item in (v if isinstance(v, list) else [v]):
                body += f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{item}\r\n'.encode()
        for k, (name, content) in files.items():
            body += f'--{boundary}\r\nContent-Disposition: form-data; name="{k}"; filename="{name}"\r\nContent-Type: image/png\r\n\r\n'.encode() + content + b'\r\n'
        body += f'--{boundary}--\r\n'.encode()
        req = urllib.request.Request(BASE + path, body, {'Content-Type': f'multipart/form-data; boundary={boundary}'})
        try:
            r = c.open(req)
        except urllib.error.HTTPError as e:
            r = e
        html = r.read().decode()
    else:
        pairs = [(k, x) for k, v in dict(data, csrf=token(c, path)).items() for x in (v if isinstance(v, list) else [v])]
        try:
            r = c.open(BASE + path, urllib.parse.urlencode(pairs).encode())
        except urllib.error.HTTPError as e:
            r = e
        html = r.read().decode()
    ok = expect in html
    check(ok, label + ('' if ok else ' :: ' + snippet(html)))
    return html


def clean(html):
    return 'Warning:' not in html and 'Fatal error' not in html and 'Notice:' not in html


owner = admin_login()
db('DELETE FROM request_limits')
# Earlier suites edit the hero through the layout setting; this suite starts from the built-in layout.
db("DELETE FROM settings WHERE `key` IN ('home_layout','home_layout_draft')")
guest = client()
city = db('SELECT cl.city_id id,c.name FROM clinics cl JOIN cities c ON c.id=cl.city_id WHERE cl.doctor_id=? ORDER BY cl.is_primary DESC LIMIT 1', [DOCTOR])[0]
doctor_name = db('SELECT name FROM doctors WHERE id=?', [DOCTOR])[0]['name']

# ---------- Landing with real data ----------
status, html = get(guest, f'index.php?city={city["id"]}')
check(status == 200 and clean(html) and 'class="hero"' in html and 'نوبت پزشکت رو راحت و سریع بگیر' in html, 'landing renders with default hero')
for marker in ['service-cards', 'h-specialties', 'h-available', 'h-promo', 'h-steps', 'h-faq', 'h-why']:
    check(marker in html, f'landing section present: {marker}')
check(doctor_name in html and 'مشاهده نوبت‌ها' in html and 'اولین نوبت' in html, 'doctor with free time listed with first slot')
check('h-articles' not in html and 'h-featured' not in html, 'empty sections (no articles, no featured doctors) are hidden')
check('<script type="application/ld+json">' in html and '"SearchAction"' in html, 'structured data for site search')
check('rel="canonical"' in html and 'og:title' in html, 'canonical and Open Graph tags')
check('کارمزد خدمات رزرو اینترنتی در حال حاضر' in html and '{booking_fee}' not in html, 'FAQ shows the live booking fee')
# rc.20: a lapsed subscription only ends Pro features; the doctor stays listed on the free plan.
db("UPDATE doctors SET subscription_until=DATE_SUB(CURDATE(),INTERVAL 60 DAY) WHERE id=?", [DOCTOR])
status, html = get(guest, f'index.php?city={city["id"]}')
check(doctor_name in html, 'doctor with expired subscription still listed (free plan)')
# Other suites may leave further doctors in this city; the empty state is checked with an empty city below.
check(('فعلاً در' in html and 'جستجو در سراسر کشور' in html) or 'h-available' in html, 'city section shows other doctors or the empty state')
db("UPDATE doctors SET subscription_until=DATE_ADD(CURDATE(),INTERVAL 300 DAY) WHERE id=?", [DOCTOR])
empty = db("SELECT c.id FROM cities c WHERE c.active=1 AND NOT EXISTS(SELECT 1 FROM clinics cl WHERE cl.city_id=c.id) LIMIT 1")[0]['id']
status, html = get(client(), f'index.php?city={empty}')
# 1.0.12: «همه پزشکان» falls back to doctors of the whole country; the city's own section stays empty.
available = html.split('aria-labelledby="h-available"', 1)[-1].split('</section>', 1)[0]
check(status == 200 and 'فعلاً در' in available and 'جستجو در سراسر کشور' in available and doctor_name not in available, 'city without doctors shows the empty state with a wider search')

# ---------- Search suggestions ----------
part = doctor_name.split()[-1]
status, r = api(guest, 'search/suggest&q=' + urllib.parse.quote(part))
check(status == 200 and any(d['name'] == doctor_name for d in r['data']['doctors']), 'suggestions include matching doctor')
check(all(not d['url'].startswith('http') for d in r['data']['doctors']), 'suggestion links stay on the site')
status, r = api(guest, 'search/suggest&q=' + urllib.parse.quote('ق'))
check(status == 200 and r['data'] == {'doctors': [], 'specialties': [], 'centers': []}, 'one-letter query returns nothing')
db("UPDATE doctors SET review_status='suspended' WHERE id=?", [DOCTOR])
status, r = api(guest, 'search/suggest&q=' + urllib.parse.quote(part))
check(not any(d['name'] == doctor_name for d in r['data']['doctors']), 'suspended doctor never suggested')
db("UPDATE doctors SET review_status='approved' WHERE id=?", [DOCTOR])

# ---------- Layout draft, preview, publish ----------
status, html = get(owner, 'admin.php?tab=home')
check(status == 200 and clean(html) and 'data-sortable' in html and 'ترتیب و نمایش بخش‌ها' in html, 'homepage manager opens')
order = ['faq', 'hero', 'services', 'banners', 'specialties', 'available', 'promo', 'featured', 'newest', 'steps', 'centers', 'why', 'articles']
sections = {}
for k in order:
    sections[f's[{k}][enabled]'] = '1'
    sections[f's[{k}][title]'] = ''
    sections[f's[{k}][subtitle]'] = ''
sections.pop('s[why][enabled]')
sections['s[hero][title]'] = 'نوبت آسان در هرمزگان'
sections['s[hero][subtitle]'] = 'زیرعنوان آزمایشی'
sections['s[faq][title]'] = 'پرسش‌های شما'
sections['s[available][desktop]'] = '3'
sections['s[available][mobile]'] = '9'
sections['s[promo][title]'] = 'پزشک هستید؟'
data = dict(action='home_draft', **sections, promo_label='عضویت پزشکان', promo_url='javascript:alert(1)', promo_theme='navy')
data['order[]'] = order
act(owner, 'admin.php?tab=home', data, 'پیوند باید', 'unsafe promo link rejected')
data['promo_url'] = 'register.php'
data['hero_chips[]'] = ['0', str(db('SELECT specialty_id FROM doctors WHERE id=?', [DOCTOR])[0]['specialty_id'])]
act(owner, 'admin.php?tab=home', data, 'پیش‌نویس ذخیره شد', 'layout saved as draft', files={'img_hero': ('hero.png', PNG)})
status, html = get(guest, 'index.php')
check('نوبت آسان در هرمزگان' not in html and 'نوبت پزشکت رو راحت و سریع بگیر' in html, 'draft not visible to visitors')
status, html = get(guest, 'index.php?preview=1')
check('preview-bar' not in html and 'نوبت آسان در هرمزگان' not in html, 'preview parameter ignored for visitors')
status, html = get(owner, 'index.php?preview=1')
check('preview-bar' in html and 'نوبت آسان در هرمزگان' in html and 'media.php?kind=home' in html, 'admin preview shows draft with uploaded image')
check(html.index('h-faq') < html.index('hero-title'), 'preview follows the new order (FAQ first)')
check('h-why' not in html and 'theme-navy' in html and 'عضویت پزشکان' in html, 'disabled section hidden; promo theme and label applied')
avail = html.split('id="h-available"')[1].split('</section>')[0] if 'id="h-available"' in html else ''
check(avail.count('class="doc-card') <= 3, 'desktop card count respected')
act(owner, 'admin.php?tab=home', dict(action='home_publish'), 'صفحه اصلی منتشر شد', 'draft published')
status, html = get(guest, 'index.php')
check('نوبت آسان در هرمزگان' in html and html.index('h-faq') < html.index('hero-title'), 'published layout visible to visitors')
saved = json.loads(db("SELECT value FROM settings WHERE `key`='home_layout'")[0]['value'])
check(saved['sections']['available']['mobile'] == 3, 'mobile count capped at desktop count')
check(db("SELECT value FROM settings WHERE `key`='home_layout_draft'")[0]['value'] == '', 'draft cleared after publishing')
data['s[hero][title]'] = 'عنوان دور ریختنی'
act(owner, 'admin.php?tab=home', data, 'پیش‌نویس ذخیره شد', 'second draft saved')
act(owner, 'admin.php?tab=home', dict(action='home_discard'), 'کنار گذاشته شد', 'draft discarded')
check('عنوان دور ریختنی' not in get(owner, 'index.php?preview=1')[1], 'discarded draft gone from preview')
# Restore the default order for the rest of the tests.
data['order[]'] = ['hero'] + [k for k in order if k != 'hero']
data['s[why][enabled]'] = '1'
data['s[available][desktop]'] = '8'
data['s[hero][title]'] = 'نوبت پزشکت رو راحت و سریع بگیر'
act(owner, 'admin.php?tab=home', data, 'پیش‌نویس ذخیره شد', 'default order drafted')
act(owner, 'admin.php?tab=home', dict(action='home_publish'), 'منتشر شد', 'default order published')

# ---------- Featured doctors and specialties ----------
act(owner, 'admin.php?tab=home&view=featured', dict(action='home_featured', doctor_id=DOCTOR, on='1', return_view='featured'), 'به منتخب‌ها اضافه شد', 'doctor featured')
status, html = get(guest, f'index.php?city={city["id"]}')
check('h-featured' in html and 'پزشکان منتخب شهر' in html, 'featured section appears with a featured doctor')
pending = db("SELECT id FROM doctors WHERE review_status<>'approved' LIMIT 1")
if pending:
    act(owner, 'admin.php?tab=home&view=featured', dict(action='home_featured', doctor_id=pending[0]['id'], on='1', return_view='featured'), 'فقط پزشک تأییدشده', 'unapproved doctor cannot be featured')
spec = db('SELECT specialty_id FROM doctors WHERE id=?', [DOCTOR])[0]['specialty_id']
others = [r['id'] for r in db("SELECT id FROM specialties WHERE deleted_at IS NULL AND level IN ('general','specialty','allied') ORDER BY sort_order")]
act(owner, 'admin.php?tab=home&view=specialties', {'action': 'home_specialties', 'return_view': 'specialties', 'order[]': [str(i) for i in others], 'show[]': [str(i) for i in others if i != spec]}, 'ذخیره شد', 'homepage specialties saved')
status, html = get(guest, 'index.php')
spec_name = db('SELECT name FROM specialties WHERE id=?', [spec])[0]['name']
grid = html.split('h-specialties')[1].split('</section>')[0] if 'h-specialties' in html else ''
check(f'specialty_id={spec}"' not in grid, 'hidden specialty removed from homepage grid')
act(owner, 'admin.php?tab=home&view=specialties', {'action': 'home_specialties', 'return_view': 'specialties', 'order[]': [str(i) for i in others], 'show[]': [str(i) for i in others]}, 'ذخیره شد', 'all specialties shown again')

# ---------- FAQ ----------
act(owner, 'admin.php?tab=home&view=faq', dict(action='faq_save', question='ساعت کاری پشتیبانی؟', answer='مهلت لغو {cancel_hours} ساعت است.', active='1', return_view='faq'), 'سوال ذخیره شد', 'FAQ added')
fid = db("SELECT id FROM faqs WHERE question='ساعت کاری پشتیبانی؟'")[0]['id']
ids = [r['id'] for r in db('SELECT id FROM faqs ORDER BY sort_order')]
act(owner, 'admin.php?tab=home&view=faq', {'action': 'faq_order', 'return_view': 'faq', 'order[]': [str(fid)] + [str(i) for i in ids if i != fid]}, 'ترتیب سوالات ذخیره شد', 'FAQ reordered')
status, html = get(guest, 'index.php')
check('ساعت کاری پشتیبانی؟' in html and '{cancel_hours}' not in html and 'مهلت لغو' in html, 'first FAQ shown with live cancel hours')
act(owner, 'admin.php?tab=home&view=faq', dict(action='faq_save', id=fid, question='<b>سوال</b>', answer='<script>alert(1)</script> متن', active='1', return_view='faq'), 'سوال ذخیره شد', 'FAQ with markup saved')
status, html = get(guest, 'index.php')
check('<script>alert(1)</script>' not in html and '&lt;script&gt;' in html and '&lt;b&gt;' in html, 'FAQ text escaped')
act(owner, 'admin.php?tab=home&view=faq', dict(action='faq_delete', id=fid, return_view='faq'), 'سوال حذف شد', 'FAQ deleted')

# ---------- Articles ----------
act(owner, 'admin.php?tab=articles&edit=0', dict(action='article_save', title='مراقبت از قلب در تابستان', slug='', summary='نکته‌های ساده برای روزهای گرم', body='## نوشیدن آب\nروزانه آب کافی بنوشید.\n\n- پیاده‌روی صبحگاهی\n- پرهیز از آفتاب ظهر\n\n<script>alert(2)</script>', specialty_id=spec, status='draft', published_at=''), 'پیش‌نویس مقاله ذخیره شد', 'article draft saved', files={'cover': ('c.png', PNG)})
art = db("SELECT * FROM articles WHERE title='مراقبت از قلب در تابستان'")[0]
check(art['slug'] == 'مراقبت-از-قلب-در-تابستان' and art['cover_file'], 'Persian slug and cover stored')
check(get(guest, 'article.php?slug=' + urllib.parse.quote(art['slug']))[0] == 404, 'draft article hidden from visitors')
status, html = get(owner, 'article.php?slug=' + urllib.parse.quote(art['slug']))
check(status == 200 and 'پیش‌نمایش پیش‌نویس' in html, 'admin previews the draft')
act(owner, f'admin.php?tab=articles&edit={art["id"]}', dict(action='article_save', id=art['id'], title=art['title'], slug=art['slug'], summary=art['summary'], body=art['body'], specialty_id=spec, status='published', published_at=''), 'مقاله منتشر شد', 'article published')
status, html = get(guest, 'article.php?slug=' + urllib.parse.quote(art['slug']))
check(status == 200 and '<h2>نوشیدن آب</h2>' in html and '<li>پیاده‌روی صبحگاهی</li>' in html, 'article formatting rendered')
check('<script>alert(2)</script>' not in html and '&lt;script&gt;alert(2)&lt;/script&gt;' in html, 'article markup escaped')
check('"@type":"Article"' in html and 'og:image' in html and 'media.php?kind=article' in html, 'article structured data and cover')
check('جایگزین معاینه و تشخیص پزشک نیست' in html, 'medical disclaimer shown')
status, html = get(guest, 'index.php')
check('h-articles' in html and art['title'] in html, 'published article on homepage')
status, html = get(guest, 'articles.php')
check(art['title'] in html and 'دقیقه مطالعه' in html, 'articles list')
act(owner, 'admin.php?tab=articles&edit=0', dict(action='article_save', title='مقاله آینده', body='متن مقاله‌ای که بعداً منتشر می‌شود و هنوز نباید دیده شود.', status='published', published_at='۱۴۹۹/۰۱/۰۱'), 'مقاله منتشر شد', 'scheduled article saved')
check('مقاله آینده' not in get(guest, 'articles.php')[1], 'scheduled article hidden until its date')
act(owner, 'admin.php?tab=articles&edit=0', dict(action='article_save', title=art['title'], body='متن دوم با همان عنوان برای بررسی نامک یکتا.', status='draft'), 'پیش‌نویس', 'same-title article saved')
check(len({r['slug'] for r in db('SELECT slug FROM articles WHERE title=?', [art['title']])}) == 2, 'slugs stay unique')

# ---------- Site pages and footer ----------
status, html = get(guest, 'page.php?slug=terms')
check(status == 404, 'unpublished terms page hidden')
check('page.php?slug=terms' not in get(guest, 'index.php')[1], 'unpublished page not linked in footer')
act(owner, 'admin.php?tab=home&view=pages&page=terms', dict(action='page_save', slug='terms', title='قوانین و مقررات', body='## پذیرش\nاستفاده از سامانه به معنی پذیرش این قوانین است.', published='1', return_view='pages'), 'صفحه ذخیره شد', 'terms published')
status, html = get(guest, 'page.php?slug=terms')
check(status == 200 and '<h2>پذیرش</h2>' in html, 'terms page shown')
check('page.php?slug=terms' in get(guest, 'index.php')[1], 'published page linked in footer')
check(get(guest, 'page.php?slug=../config')[0] == 404, 'page slug validated')
act(owner, 'admin.php?tab=home&view=footer', {'action': 'footer_save', 'return_view': 'footer', 'footer_about': 'نوبت‌یار هرمزگان', 'support_address': 'بندرعباس', 'social[instagram]': 'http://insecure.example'}, 'https://', 'insecure social link rejected')
act(owner, 'admin.php?tab=home&view=footer', {'action': 'footer_save', 'return_view': 'footer', 'footer_about': 'نوبت‌یار هرمزگان', 'support_address': 'بندرعباس', 'social[instagram]': 'https://instagram.com/nobatyar', 'badge_title[0]': 'نماد آزمایشی', 'badge_url[0]': 'https://example.com/verify'}, 'تنظیمات پانویس ذخیره شد', 'footer saved with badge', files={'badge_0': ('b.png', PNG)})
status, html = get(guest, 'index.php')
check('https://instagram.com/nobatyar' in html and 'بندرعباس' in html and 'trust-badges' in html and 'https://example.com/verify' in html, 'footer shows social link, address and badge')

# ---------- Content role can manage, others cannot ----------
db("INSERT INTO admins(email,password_hash,name,role) VALUES('editor@example.test',?,'ویراستار','content')", [db("SELECT password_hash FROM admins WHERE email='admin@example.test'")[0]['password_hash']])
editor = client()
submit(editor, 'admin.php', dict(action='login', email='editor@example.test', password='Test-password-012345'))
check(get(editor, 'admin.php?tab=home')[0] == 200 and get(editor, 'admin.php?tab=articles')[0] == 200, 'content role opens homepage manager and articles')
db("INSERT INTO admins(email,password_hash,name,role) VALUES('finance2@example.test',?,'مالی','finance')", [db("SELECT password_hash FROM admins WHERE email='admin@example.test'")[0]['password_hash']])
fin = client()
submit(fin, 'admin.php', dict(action='login', email='finance2@example.test', password='Test-password-012345'))
check(get(fin, 'admin.php?tab=home')[0] == 403, 'finance role blocked from homepage manager')
status, html = post(fin, 'admin.php?tab=home', dict(csrf=token(fin, 'admin.php'), action='home_publish'))
check('مجاز نیست' in html, 'finance role cannot publish the homepage')
check('preview-bar' not in get(fin, 'index.php?preview=1')[1], 'finance role gets no preview')

# ---------- Friendly URLs, sitemap, robots ----------
pretty = client()
pretty.addheaders = [('X-Nobatyar-Pretty', '1')]
status, html = get(pretty, 'index.php')
check(f'doctor/{DOCTOR}/' in html and 'article/' in html, 'friendly links generated when rewriting is available')
status, html = get(client(), f'doctor/{DOCTOR}/{urllib.parse.quote(doctor_name)}')
check(status == 200 and doctor_name in html and '<base href=' in html, 'doctor page served from friendly URL with base href')
status, html = get(client(), 'article/' + urllib.parse.quote(art['slug']))
check(status == 200 and art['title'] in html, 'article served from friendly URL')
status, html = get(client(), f'city/{city["id"]}/x')
check(status == 200 and 'class="hero"' in html, 'city friendly URL opens the city homepage')
status, html = get(client(), f'specialty/{spec}/x')
check(status == 200 and 'results-page' in html, 'specialty friendly URL lists doctors')
status, html = get(client(), 'page/terms')
check(status == 200 and 'قوانین و مقررات' in html, 'page friendly URL')
status, body = get(client(), 'sitemap.php')
check(status == 200 and '<urlset' in body and f'doctor.php?id={DOCTOR}' in body and 'article.php?slug=' in body and 'page.php?slug=terms' in body, 'sitemap lists doctors, articles and published pages')
check('page.php?slug=privacy' not in body, 'sitemap skips unpublished pages')
status, body = get(client(), 'robots.txt')
check(status == 200 and 'Disallow: /admin.php' in body and 'Sitemap: http' in body, 'robots.txt served')
status, html = get(guest, 'specialties.php')
check(status == 200 and clean(html) and 'spec-card' in html, 'specialties page')

import http_helpers
print(f"SUCCESS {http_helpers.checks} homepage checks")
