"""1.0.0-rc.6 one-click update from the admin panel, on a disposable install only. Builds real packages from
this copy (with release.json), checks that damaged or unsafe packages are rejected without touching the
site, installs a newer test version (backup, rollback copy, .htaccess host blocks kept, config.php and
private/ untouched, database step run in a fresh request), checks maintenance mode, then rolls back so the
install ends exactly as it started."""
from http_helpers import *
import hashlib, io, zipfile, uuid, time

LIVE_VERSION = (ROOT / 'lib' / 'version.php').read_text()
LIVE_HTACCESS = (ROOT / '.htaccess').read_text()
CPANEL = '# php -- BEGIN cPanel-generated handler, do not edit\n# Set the "ea-php83" package as the default "PHP" programming language.\n<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php83 .php .php8 .phtml\n</IfModule>\n# php -- END cPanel-generated handler, do not edit\n'


def php(code, args=[]):
    return subprocess.check_output([PHP] + (['-c', PHPINI] if PHPINI else []) + ['-r', 'require $argv[1];if(config()["database"]!=="nobatyar_test_http")exit(9);' + code, str(ROOT / 'core.php')] + [str(a) for a in args]).decode()


def scalar(sql, args=[]):
    rows = db(sql, args)
    return next(iter(rows[0].values())) if rows else None


def sha(p):
    return hashlib.sha256(Path(p).read_bytes()).hexdigest()


def source_files():
    out = {}
    for p in sorted(ROOT.rglob('*')):
        rel = p.relative_to(ROOT).as_posix()
        if p.is_dir() or '__pycache__' in rel or rel in ('config.php', 'install.lock') or (rel.startswith('private/') and rel not in ('private/.htaccess', 'private/index.php')):
            continue
        out[rel] = p.read_bytes()
    return out


def package(changes=None, extra=None, manifest_edit=None, top='nobatyar-cpanel/', raw=None):
    files = source_files()
    for k, v in (changes or {}).items():
        if v is None:
            files.pop(k, None)
        else:
            files[k] = v.encode() if isinstance(v, str) else v
    manifest = {'app': 'nobatyar', 'version': 'test', 'files': {k: hashlib.sha256(v).hexdigest() for k, v in files.items()}}
    if manifest_edit:
        manifest_edit(manifest)
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, 'w', zipfile.ZIP_DEFLATED) as z:
        for k, v in files.items():
            z.writestr(top + k, v)
        z.writestr(top + 'release.json', json.dumps(manifest))
        for k, v in (extra or {}).items():
            z.writestr(k, v)
        for info, data in (raw or []):
            z.writestr(info, data)
    return buf.getvalue()


def version_php(v, fa, schema):
    return re.sub(r"const SCHEMA_VERSION = \d+;", f'const SCHEMA_VERSION = {schema};', re.sub(r"const APP_VERSION_FA = '[^']+';", f"const APP_VERSION_FA = '{fa}';", re.sub(r"const APP_VERSION = '[^']+';", f"const APP_VERSION = '{v}';", LIVE_VERSION)))


SCHEMA = int(re.search(r'SCHEMA_VERSION = (\d+)', LIVE_VERSION).group(1))
NEW = version_php('1.0.0-rc.99', 'نسخه آزمون ۹۹', SCHEMA)


def upload(c, data, name='nobatyar-cpanel-test.zip'):
    tok = token(c, 'admin.php?tab=dashboard')
    b = uuid.uuid4().hex
    body = b''
    for k, v in (('csrf', tok), ('action', 'update_upload')):
        body += f'--{b}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
    body += f'--{b}\r\nContent-Disposition: form-data; name="package"; filename="{name}"\r\nContent-Type: application/zip\r\n\r\n'.encode() + data + f'\r\n--{b}--\r\n'.encode()
    req = urllib.request.Request(BASE + 'admin.php?tab=update', data=body, headers={'Content-Type': 'multipart/form-data; boundary=' + b})
    try:
        r = c.open(req)
    except urllib.error.HTTPError as e:
        r = e
    return r.status, r.read().decode()


def stage_dirs():
    d = ROOT / 'private' / 'updates'
    return sorted(x.name for x in d.glob('stage-*')) if d.exists() else []


def admin_login(email='admin@example.test', password='Test-password-012345'):
    c = client()
    submit(c, 'admin.php', dict(action='login', email=email, password=password))
    return c


owner = admin_login()
config_hash = sha(ROOT / 'config.php')
key_hash = sha(ROOT / 'private' / 'app.key') if (ROOT / 'private' / 'app.key').exists() else None

# ---------- Page and permissions ----------
status, html = get(owner, 'admin.php?tab=update')
check(status == 200 and 'به‌روزرسانی سامانه' in html and 'بارگذاری و بررسی بسته' in html and 'enctype="multipart/form-data"' in html, 'update page for the owner with the upload form')
check('ZipArchive' in html and 'حداکثر حجم بارگذاری هاست' in html, 'host checks and upload limit shown')
check('روش دستی' in html, 'manual method explained as a fallback')
db("DELETE FROM admins WHERE email='finance-upd@example.test'")
db("INSERT INTO admins(email,password_hash,role,active) VALUES('finance-upd@example.test',?,'finance',1)", [php("echo password_hash('Finance-pass-12345',PASSWORD_DEFAULT);")])
fin = admin_login('finance-upd@example.test', 'Finance-pass-12345')
status, html = get(fin, 'admin.php?tab=update')
check('بارگذاری و بررسی بسته' not in html, 'other admin roles cannot open the update page')
status, html = get(fin, 'admin.php?tab=dashboard')
check('tab=update' not in html, 'update section hidden from other roles')
status, html = upload(fin, package({'lib/version.php': NEW}))
check(stage_dirs() == [] and scalar("SELECT value FROM settings WHERE `key`='update_pending'") in (None, ''), 'other roles cannot upload a package')

# ---------- Rejected packages: nothing staged, nothing written ----------
def rejected(data, words, label, name='nobatyar-cpanel-test.zip'):
    status, html = upload(owner, data, name)
    check(status == 200 and words in html and 'بسته آماده نصب است' not in html and stage_dirs() == [] and sha(ROOT / 'lib' / 'version.php') == hashlib.sha256(LIVE_VERSION.encode()).hexdigest(), 'rejected: ' + label)
    return html

rejected(b'not a zip at all', 'معتبر نیست', 'not a ZIP file')
rejected(package({'lib/version.php': NEW}), 'فقط فایل ZIP', 'wrong file extension', name='package.rar')
buf = io.BytesIO()
with zipfile.ZipFile(buf, 'w') as z:
    z.writestr('nobatyar-cpanel/index.php', '<?php echo 1;')
rejected(buf.getvalue(), 'release.json', 'ZIP without release.json')
rejected(package({'lib/version.php': NEW}, extra={'nobatyar-cpanel/../evil.php': '<?php echo "x";'}), 'مسیر غیرمجاز', 'path traversal entry')
check(not (ROOT.parent / 'evil.php').exists(), 'no file written outside the site')
rejected(package({'lib/version.php': NEW}, extra={'nobatyar-cpanel/assets/extra.php': '<?php'}), 'خارج از فهرست', 'file not listed in release.json')
rejected(package({'lib/version.php': NEW}, manifest_edit=lambda m: m['files'].__setitem__('faq.php', '0' * 64)), 'یکسان نیست', 'file altered after release.json (hash mismatch)')
rejected(package({'lib/version.php': NEW}, manifest_edit=lambda m: m['files'].__setitem__('missing.php', '0' * 64)), 'ناقص', 'listed file missing from the ZIP')
rejected(package({'lib/version.php': NEW, 'config.php': '<?php return [];'}), 'config.php', 'package carrying config.php')
rejected(package({'lib/version.php': version_php('0.9.0', 'قدیمی', SCHEMA)}), 'قدیمی‌تر', 'older version (downgrade)')
rejected(package({'lib/version.php': version_php('1.0.0-rc.99', 'آزمون', SCHEMA - 1)}), 'پایین‌تر', 'lower database step')
link = zipfile.ZipInfo('nobatyar-cpanel/assets/link.txt')
link.create_system = 3
link.external_attr = (0o120777 << 16)
rejected(package({'lib/version.php': NEW}, raw=[(link, '/etc/passwd')], manifest_edit=lambda m: m['files'].__setitem__('assets/link.txt', hashlib.sha256(b'/etc/passwd').hexdigest())), 'symlink', 'symbolic link entry')
check(scalar("SELECT COUNT(*) FROM audit_log WHERE action='update.rejected'") >= 9, 'rejections recorded in the activity log')

# ---------- Valid package: preview ----------
(ROOT / '.htaccess').write_text(LIVE_HTACCESS.rstrip() + '\n\n' + CPANEL)
new_htaccess = LIVE_HTACCESS.rstrip() + '\n# rc.99 test rule\n'
pkg = package({'lib/version.php': NEW, 'assets/update-marker.txt': 'marker rc.99', '.htaccess': new_htaccess})
status, html = upload(owner, pkg)
check('سالم است' in html and 'بسته آماده نصب است' in html and 'نسخه آزمون ۹۹' in html, 'valid package checked and previewed')
check(len(stage_dirs()) == 1 and sha(ROOT / 'lib' / 'version.php') == hashlib.sha256(LIVE_VERSION.encode()).hexdigest(), 'staged only; the site is not changed yet')
check('assets/update-marker.txt' in html and 'lib/version.php' in html and 'tests/v18_update.py' not in html.split('فهرست فایل‌ها')[1].split('</details>')[0], 'file list: new and changed files only')
check('config.php، پوشه private' in html and 'نصب به‌روزرسانی' in html, 'safety notes and install button')

# Wrong password: nothing happens.
status, html = submit(owner, 'admin.php?tab=update', dict(action='update_apply', password='wrong-password'))
check('رمز عبور شما اشتباه است' in html and 'rc.99' not in (ROOT / 'lib' / 'version.php').read_text() and not (ROOT / 'private' / 'updating.flag').exists(), 'wrong password: no change')

# ---------- Install ----------
backups_before = len(list((ROOT / 'private' / 'backups').glob('*preupdate*'))) if (ROOT / 'private' / 'backups').exists() else 0
tok = token(owner, 'admin.php?tab=update')
req = urllib.request.Request(BASE + 'admin.php?tab=update', data=urllib.parse.urlencode(dict(csrf=tok, action='update_apply', password='Test-password-012345')).encode())
r = owner.open(req)
html = r.read().decode()
check('upgrade.php?finish=' in r.url and 'به‌روزرسانی انجام شد' in html, 'install, then the database step in a fresh request')
check('نسخه آزمون ۹۹' in html, 'success page reports the new version (new code loaded)')
check("APP_VERSION = '1.0.0-rc.99'" in (ROOT / 'lib' / 'version.php').read_text() and (ROOT / 'assets' / 'update-marker.txt').read_text() == 'marker rc.99', 'new and changed files installed')
check(sha(ROOT / 'config.php') == config_hash and (key_hash is None or sha(ROOT / 'private' / 'app.key') == key_hash), 'config.php and private/app.key untouched')
ht = (ROOT / '.htaccess').read_text()
check('# rc.99 test rule' in ht and 'cPanel-generated handler' in ht and ht.count('BEGIN cPanel-generated handler') == 1, '.htaccess replaced while the host PHP handler block is kept')
check(not (ROOT / 'private' / 'updating.flag').exists() and stage_dirs() == [], 'maintenance ended and the staged package removed')
check(len(list((ROOT / 'private' / 'backups').glob('*preupdate*'))) == backups_before + 1, 'database backup taken before the update')
rb = list((ROOT / 'private' / 'updates').glob('rollback-*'))
check(len(rb) >= 1 and (rb[-1] / 'files' / 'lib' / 'version.php').read_text() == LIVE_VERSION, 'previous files saved for rollback')
check(not list(ROOT.rglob('.nobatyar-*.tmp')), 'no temporary files left behind')
check(scalar("SELECT COUNT(*) FROM audit_log WHERE action IN ('update.apply','update.finish')") >= 2, 'install recorded in the activity log')
status, html = get(client(), 'index.php')
check(status == 200 and 'Fatal' not in html, 'public site works after the update')
status, html = get(owner, r.url.replace(BASE, ''))
check('نامعتبر یا منقضی' in html, 'finish link works only once')

# ---------- Maintenance mode ----------
flag = ROOT / 'private' / 'updating.flag'
flag.touch()
status, html = get(client(), 'index.php')
check(status == 503 and 'در حال به‌روزرسانی' in html, 'visitors see the maintenance page during an update')
status, body = get(client(), 'api.php?route=session')
check(status == 503 and json.loads(body)['ok'] is False, 'API answers 503 during an update')
status, html = get(owner, 'admin.php?tab=update')
check(status == 200 and 'به‌روزرسانی سامانه' in html, 'administrators keep working')
os.utime(flag, (time.time() - 3600, time.time() - 3600))
status, html = get(client(), 'index.php')
check(status == 200, 'a forgotten flag older than 30 minutes is ignored')
flag.unlink()

# ---------- Rollback ----------
status, html = get(owner, 'admin.php?tab=update')
check('بازگرداندن نسخه قبلی' in html and '1.0.0-rc.99' in html and 'سابقه به‌روزرسانی' in html, 'rollback offered with the history')
status, html = submit(owner, 'admin.php?tab=update', dict(action='update_rollback', password='nope'))
check("rc.99" in (ROOT / 'lib' / 'version.php').read_text(), 'rollback needs the owner password')
status, html = submit(owner, 'admin.php?tab=update', dict(action='update_rollback', password='Test-password-012345'))
check((ROOT / 'lib' / 'version.php').read_text() == LIVE_VERSION and not (ROOT / 'assets' / 'update-marker.txt').exists(), 'rollback restores the previous files and removes new ones')
check((ROOT / '.htaccess').read_text() == LIVE_HTACCESS.rstrip() + '\n\n' + CPANEL, '.htaccess restored as it was')
check(scalar("SELECT COUNT(*) FROM audit_log WHERE action='update.rollback'") == 1, 'rollback recorded')
status, html = get(client(), 'index.php')
check(status == 200, 'site works after the rollback')

# ---------- Cancel a staged package ----------
status, html = upload(owner, pkg)
check(len(stage_dirs()) == 1, 'package staged again')
status, html = submit(owner, 'admin.php?tab=update', dict(action='update_cancel'))
check(stage_dirs() == [] and 'بارگذاری و بررسی بسته' in html, 'cancel removes the staged package')
(ROOT / '.htaccess').write_text(LIVE_HTACCESS)
db("DELETE FROM admins WHERE email='finance-upd@example.test'")
print('SUCCESS', checks, 'rc.6 update checks')
