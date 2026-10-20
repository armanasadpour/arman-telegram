"""1.0.0-rc.18 over HTTP: administrators are no longer signed out by the patient/doctor sign-in in the same
browser (and the other way round), sessions are kept in the application's own folder for 12 hours with a
cookie that survives closing the browser or installed app, and an out-of-date form token returns to the same
page with a message instead of a bare error page. Run after v12_http.py (owner fixture)."""
from http_helpers import *

OWNER_EMAIL, OWNER_PASS = 'admin@example.test', 'Test-password-012345'
db('DELETE FROM request_limits')
db('DELETE FROM auth_rate_limits')


def admin_in(c):
    return 'class="admin-shell"' in get(c, 'admin.php')[1]


def user_in(c):
    status, html = get(c, 'panel.php')
    return status == 200 and 'name="action" value="logout"' in html


# ---------- Cookie and storage ----------
req = urllib.request.Request(BASE + 'index.php')
r = urllib.request.build_opener(urllib.request.ProxyHandler({})).open(req)
cookie = r.headers.get('Set-Cookie', '')
check('PHPSESSID=' in cookie and ('expires=' in cookie.lower() or 'max-age=' in cookie.lower()), 'session cookie survives closing the browser (12-hour expiry)')
check('HttpOnly' in cookie and 'SameSite=Lax' in cookie, 'session cookie stays HttpOnly and SameSite')
sessions = ROOT / 'private' / 'sessions'
check(sessions.is_dir() and any(p.name.startswith('sess_') for p in sessions.iterdir()), 'sessions stored in the application folder (private/sessions)')
check((ROOT / 'private' / '.htaccess').read_text().strip() == 'Require all denied', 'session folder is not served')

# ---------- Admin first, then patient/doctor sign-in and sign-out in the same browser ----------
c = client()
submit(c, 'admin.php', dict(action='login', email=OWNER_EMAIL, password=OWNER_PASS))
check(admin_in(c), 'administrator signed in')
login_user(c, '09127700501')
check(admin_in(c) and user_in(c), 'patient sign-in in the same browser keeps the administrator signed in')
html = submit(c, 'admin.php?tab=dashboard', dict(action='dashboard_noop'))[1]
check('class="admin-shell"' in html, 'admin pages still work after the patient sign-in (fresh form token)')
submit(c, 'panel.php', dict(action='logout'))
check(not user_in(c) and admin_in(c), 'patient sign-out keeps the administrator signed in')

# ---------- Admin sign-out keeps the patient ----------
login_user(c, '09127700501')
submit(c, 'admin.php', dict(action='logout'))
check(not admin_in(c) and user_in(c), 'administrator sign-out keeps the patient signed in')

# ---------- Patient first, then admin ----------
d = client()
login_user(d, '09127700502')
submit(d, 'admin.php', dict(action='login', email=OWNER_EMAIL, password=OWNER_PASS))
check(admin_in(d) and user_in(d), 'administrator sign-in keeps the patient signed in')

# ---------- Out-of-date form token ----------
tok = token(d, 'admin.php?tab=users')
db('DELETE FROM request_limits')
login_user(d, '09127700502')  # a sign-in in another tab gives the session a new token
req = urllib.request.Request(BASE + 'admin.php?tab=users', urllib.parse.urlencode(dict(csrf=tok, action='user_status', user_id=1)).encode(), headers={'Referer': BASE + 'admin.php?tab=users&page=2'})


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k):
        return None


jar = next(h.cookiejar for h in d.handlers if isinstance(h, urllib.request.HTTPCookieProcessor))
nr = urllib.request.build_opener(urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(jar), NoRedirect())
try:
    resp = nr.open(req)
except urllib.error.HTTPError as e:
    resp = e
check(resp.status == 303 and resp.headers.get('Location') == 'admin.php?tab=users&page=2', 'stale form returns to the same page')
html = get(d, 'admin.php?tab=users&page=2')[1]
check('مهلت این فرم تمام شده بود' in html and admin_in(d), 'message shown; administrator still signed in')
status, body = post(client(), 'admin.php', dict(csrf='forged', action='logout'))
check(status == 419, 'request without a page of this site is still refused (419)')
other = urllib.request.Request(BASE + 'admin.php', urllib.parse.urlencode(dict(csrf='forged', action='logout')).encode(), headers={'Referer': 'https://evil.example/admin.php'})
try:
    resp = nr.open(other)
except urllib.error.HTTPError as e:
    resp = e
check(resp.status == 419, 'a form posted from another site is refused, not redirected')

# ---------- Clean-up by the scheduled job, never during page requests ----------
import os, time as _t
old, fresh = sessions / 'sess_rc18oldtest', sessions / 'sess_rc18freshtest'
old.write_text('x'); fresh.write_text('x')
os.utime(old, (_t.time() - 50000, _t.time() - 50000))
out = subprocess.check_output([PHP] + (['-c', PHPINI] if PHPINI else []) + ['-r', 'require $argv[1];require_once dirname($argv[1])."/lib/jobs.php";echo session_files_cleanup();', str(ROOT / 'core.php')]).decode()
check(not old.exists() and fresh.exists() and out.strip() == '1', 'cron removes sessions untouched for 13 hours, keeps recent ones')
fresh.unlink()
check(admin_in(d), 'signed-in administrator unaffected by the clean-up')

db('DELETE FROM request_limits')
db('DELETE FROM auth_rate_limits')
print('SUCCESS', checks, 'rc.18 session checks')
