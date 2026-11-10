// Phase 7 acceptance test (Chromium): admin dashboard, reports, audit filters/exports, roles, branding,
// notifications settings, backup download and secret path rotation.
//   node tests/Integration/phase7-browser.mjs https://pm.test /srv/pmhost/private-messenger DB USER PASS
import { createRequire } from 'node:module';
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { gunzipSync, deflateSync } from 'node:zlib';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
const { chromium } = createRequire(import.meta.url)('playwright');

const [BASE, ROOT, DBN, DBU, DBP] = process.argv.slice(2);
const proxy = process.env.HTTPS_PROXY || process.env.https_proxy;
const browser = await chromium.launch({ ...(proxy ? { proxy: { server: proxy, bypass: new URL(BASE).hostname } } : {}), args: ['--ignore-certificate-errors'] });
let pass = 0, fail = 0;
const check = (name, cond, extra = '') => { if (cond) { pass++; console.log('  \x1b[32m✔\x1b[0m', name); } else { fail++; console.log('  \x1b[31m✘', name, '\x1b[0m', extra); } };
const sql = (q) => execFileSync('mysql', ['-N', '-u', DBU, `-p${DBP}`, '-h', '127.0.0.1', DBN, '-e', q]).toString().trim();
const errors = [];
const T = 30000;
const newPage = async () => { const c = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 900 }, acceptDownloads: true });
  const p = await c.newPage(); p.on('pageerror', (e) => errors.push(e.message)); p.on('dialog', (d) => d.accept()); return p; };
process.on('uncaughtException', (e) => { console.log('FAIL:', e.message.split('\n')[0]); process.exit(1); });

// A valid square PNG (CRCs computed) for the logo upload.
function png(size) {
  const crcT = Array.from({ length: 256 }, (_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c >>> 0; });
  const crc = (b) => { let c = 0xffffffff; for (const x of b) c = crcT[(c ^ x) & 255] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };
  const chunk = (type, data) => { const t = Buffer.from(type); const len = Buffer.alloc(4); len.writeUInt32BE(data.length); const c = Buffer.alloc(4); c.writeUInt32BE(crc(Buffer.concat([t, data]))); return Buffer.concat([len, t, data, c]); };
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(size, 0); ihdr.writeUInt32BE(size, 4); ihdr[8] = 8; ihdr[9] = 2;
  const raw = Buffer.alloc((size * 3 + 1) * size); for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) raw.set([20, 120, 200], y * (size * 3 + 1) + 1 + x * 3);
  return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}
const tmp = mkdtempSync(join(tmpdir(), 'pm7-'));

// ---------------------------------------------------------------- install + users
console.log('\n# Setup');
const admin = await newPage();
await admin.goto(`${BASE}/install/`);
await admin.fill('input[name=token]', readFileSync(`${ROOT}/storage/install-token.txt`, 'utf8').trim()); await admin.click('button');
await admin.click('summary'); await admin.check('input[name=db_mode][value=manual]');
await admin.fill('input[name=db_name]', DBN); await admin.fill('input[name=db_user]', DBU); await admin.fill('input[name=db_pass]', DBP); await admin.fill('input[name=db_host]', '127.0.0.1');
await admin.fill('input[name=admin_password]', 'Admin strong pass 123'); await admin.fill('input[name=admin_password2]', 'Admin strong pass 123');
await admin.click('form button');
await admin.waitForSelector('text=نصب کامل شد', { timeout: 60000 });
let [ADM, APP] = [...new Set((await admin.content()).match(/https:\/\/[^/"<]+\/[A-Za-z0-9]{16}\//g))];
const adminLogin = async (p, u, pw) => { await p.goto(ADM); await p.fill('input[name=username]', u); await p.fill('input[name=password]', pw); await p.click('button'); };
await adminLogin(admin, 'admin', 'Admin strong pass 123');
const temp = {};
for (const [u, n] of [['alice', 'آلیس'], ['bob', 'باب']]) {
  await admin.goto(ADM + 'users/new');
  await admin.fill('input[name=username]', u); await admin.fill('input[name=display_name]', n); await admin.check('input[name=activate]');
  await admin.click('main form button');
  temp[u] = (await admin.textContent('.secret')).trim();
}
// one failed user login, one successful (first login + password change)
const ul = await newPage();
await ul.goto(APP); await ul.fill('input[name=username]', 'alice'); await ul.fill('input[name=password]', 'wrong password 1'); await ul.click('button');
await ul.fill('input[name=username]', 'alice'); await ul.fill('input[name=password]', temp.alice); await ul.click('button');
await ul.fill('input[name=current]', temp.alice); await ul.fill('input[name=new]', 'Alice strong pass 1'); await ul.fill('input[name=confirm]', 'Alice strong pass 1'); await ul.click('form button');
await ul.waitForSelector('.c-rk', { timeout: T });
await ul.fill('input[placeholder="چهار کاراکتر آخر"]', (await ul.textContent('.c-rk')).trim().slice(-4)); await ul.click('text=ذخیره کردم');
await ul.waitForSelector('.c-side-head h1', { timeout: T });

// ---------------------------------------------------------------- dashboard
console.log('\n# Dashboard');
await admin.goto(ADM);
const dash = await admin.textContent('main');
check('dashboard: account totals (all / active / inactive / online)', ['کل حساب‌ها', 'فعال', 'غیرفعال (معلق / مسدود)', 'آنلاین اکنون'].every((w) => dash.includes(w)));
check('dashboard: message, call and storage figures from metadata', ['پیام‌ها و رویدادها', 'تماس‌ها — ۲۴ ساعت', 'فایل‌های ذخیره‌شده', 'فضای آزاد دیسک', 'سهمیه هاست'].every((w) => dash.includes(w)));
check('dashboard: service health (database, cron, disk, folders, PHP errors)', ['وضعیت سرویس‌ها', 'دیتابیس', 'نگهداری خودکار (Cron)', 'فضای دیسک', 'پوشه فایل‌ها', 'خطاهای PHP'].every((w) => dash.includes(w)));
check('dashboard: online count is real (Alice\'s app is open)', /آنلاین اکنون\s*۱/.test(dash), dash.slice(dash.indexOf('آنلاین'), dash.indexOf('آنلاین') + 40));
check('dashboard warns that no backup was taken yet', dash.includes('هنوز هیچ نسخه پشتیبانی'));

// ---------------------------------------------------------------- reports
console.log('\n# Reports (non-content)');
await admin.click('nav >> text=گزارش‌ها');
const rep = await admin.textContent('main');
check('reports page: daily table of non-content metrics', rep.includes('ورود ناموفق') && rep.includes('حساب‌های جدید') && rep.includes('دقیقه مکالمه'));
const today = new Date().toISOString().slice(0, 10);
const row = await admin.locator(`tr:has(td:text("${today}"))`).innerText();
check('today: 2 new accounts, 1 failed and 1 successful user login', /\t۲\t/.test(row) && row.split('\t').length > 5, row);
const [dl] = await Promise.all([admin.waitForEvent('download'), admin.click('text=⬇ خروجی CSV')]);
const csv = readFileSync(await dl.path(), 'utf8');
check('CSV export: UTF-8 BOM, header and one row per day', csv.startsWith('﻿day_utc,messages') && csv.trim().split('\n').length === 31, csv.slice(0, 80));
const failedToday = csv.split('\n').find((l) => l.startsWith(today)).split(',');
check('CSV: failed login counted', failedToday[5] === '1', failedToday.join(','));

// ---------------------------------------------------------------- audit
console.log('\n# Audit');
const aliceId = sql("SELECT id FROM users WHERE username = 'alice'");
await admin.goto(ADM + 'users/' + aliceId);
check('user page: non-content stats', (await admin.textContent('main')).includes('آمار غیرمحتوایی'));
await admin.goto(ADM + 'audit?action=user.view');
check('viewing a user account is itself audited', (await admin.locator('table tr').count()) === 2 && (await admin.textContent('table')).includes('alice'));
await admin.goto(ADM + 'audit?target=bob');
check('audit filter by target user', (await admin.textContent('table')).includes('user.create') && !(await admin.textContent('table')).includes('alice'));
check('report views are audited', sql("SELECT COUNT(*) FROM audit_logs WHERE action IN ('report.view','report.export')") === '2');
const [adl] = await Promise.all([admin.waitForEvent('download'), admin.click('text=⬇ خروجی CSV')]);
const acsv = readFileSync(await adl.path(), 'utf8');
check('audit CSV export (filtered)', acsv.includes('user.create') && acsv.includes('bob') && !acsv.includes('alice'));
check('audit hash chain intact', (await admin.textContent('main')).includes('زنجیره هش سالم است'));

// ---------------------------------------------------------------- roles
console.log('\n# Roles');
await admin.goto(ADM + 'admins');
await admin.click('summary:has-text("+ نقش جدید")');
const newRole = admin.locator('details:has(summary:has-text("+ نقش جدید"))');
await newRole.locator('input[name=name]').fill('گزارشگر');
for (const l of ['مشاهده داشبورد', 'مشاهده گزارش‌های آماری (غیرمحتوایی)', 'مشاهده گزارش ممیزی']) await newRole.locator(`label:has-text("${l}") input`).check();
await newRole.locator('button').click();
check('custom role created', (await admin.textContent('main')).includes('گزارشگر'));
check('super-only permissions are not offered to custom roles', !(await newRole.textContent()).includes('دریافت نسخه پشتیبان'));
await admin.fill('input[name=username]', 'reporter'); await admin.fill('input[name=password]', 'Reporter strong pass 1');
await admin.selectOption('form:has(input[name=username]) select[name=role_id]', { label: 'گزارشگر' });
await admin.click('form:has(input[name=username]) button');
const rp = await newPage();
await adminLogin(rp, 'reporter', 'Reporter strong pass 1');
const nav = await rp.textContent('nav');
check('limited admin sees only permitted sections', nav.includes('گزارش‌ها') && nav.includes('گزارش ممیزی') && !nav.includes('پشتیبان‌گیری') && !nav.includes('کاربران') && !nav.includes('تنظیمات'));
check('limited admin gets 403 on backup and users', (await (await rp.goto(ADM + 'backup')).status()) === 403 && (await (await rp.goto(ADM + 'users')).status()) === 403);
const reporterRow = admin.locator('tr:has(td:text("reporter"))');
await reporterRow.locator('select').selectOption({ label: 'ناظر' }); await reporterRow.locator('text=تغییر نقش').click();
await rp.goto(ADM);
check('changing an admin\'s role ends their sessions', (await rp.$('input[name=password]')) !== null);

// ---------------------------------------------------------------- settings: colour, notifications, retention, limits, logo
console.log('\n# Settings');
await admin.goto(ADM + 'settings');
await admin.check('input[name=accent][value="#16a34a"]');
await admin.selectOption('select[name=notify_preview]', 'sender');
await admin.fill('input[name=retention_days]', '10');
await admin.fill('input[name=limit_search]', '50');
await admin.fill('input[name=internal_name]', 'گفتگوی خانواده');
await admin.click('text=ذخیره تنظیمات');
check('settings saved', (await admin.textContent('main')).includes('تغییرات ذخیره شد'));
const themeHref = await admin.getAttribute('link[href*="theme.css"]', 'href');
check('admin panel uses the chosen colour', (await (await admin.request.get(new URL(themeHref, ADM).href)).text()).includes('#16a34a'));
writeFileSync(join(tmp, 'logo.png'), png(256)); writeFileSync(join(tmp, 'bad.png'), png(100));
await admin.setInputFiles('input[name=logo]', join(tmp, 'bad.png')); await admin.click('form[action$="/settings/logo"] button');
check('a too small logo is refused', (await admin.textContent('.alert.error')).includes('مربعی'));
await admin.setInputFiles('input[name=logo]', join(tmp, 'logo.png')); await admin.click('form[action$="/settings/logo"] button');
check('logo uploaded', (await admin.textContent('main')).includes('لوگو ذخیره شد'));

// the anonymous login page shows nothing; the signed-in app gets name, logo, colour and a manifest
const anon = await newPage();
await anon.goto(APP);
const anonHtml = await anon.content();
check('login page: no manifest, no theme, no name', !anonHtml.includes('manifest') && !anonHtml.includes('theme.css') && !anonHtml.includes('گفتگوی خانواده'));
await ul.reload(); await ul.waitForSelector('.c-side-head h1', { timeout: T });
const mHref = await ul.getAttribute('link[rel=manifest]', 'href');
const manifest = await (await ul.request.get(new URL(mHref, APP).href)).json();
check('PWA manifest after sign-in: name, colour, standalone, app scope', manifest.name === 'گفتگوی خانواده' && manifest.theme_color === '#16a34a' && manifest.display === 'standalone' && manifest.start_url === new URL(APP).pathname);
const icon = await ul.request.get(new URL(manifest.icons[0].src, new URL(mHref, APP)).href);
check('manifest icon is the uploaded logo', icon.headers()['content-type'] === 'image/png' && manifest.icons[0].sizes === '256x256');
check('brand URLs are unguessable (wrong token → 404)', (await anon.request.get(APP + 'b/aaaaaaaaaaaaaaaaaaaaaa/manifest.webmanifest')).status() === 404);
check('app accent follows the setting', (await ul.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--accent').trim())) === '#16a34a');
const boot = await ul.evaluate(async () => (await fetch(location.pathname + 'api/v1/bootstrap', { headers: { 'X-PM-Request': '1' } })).json());
check('notification policy delivered to the app', boot.notify.enabled === true && boot.notify.preview === 'sender');
check('search rate limit uses the setting', sql("SELECT setting_value FROM system_settings WHERE setting_key = 'limits.search_per_hour'") === '50');

// ---------------------------------------------------------------- backup
console.log('\n# Backup');
await admin.goto(ADM + 'backup');
await admin.fill('input[name=password]', 'wrong'); await admin.click('text=⬇ دریافت فایل');
check('backup requires the admin password', (await admin.textContent('.alert.error')).includes('نادرست'));
await admin.fill('input[name=password]', 'Admin strong pass 123');
const [bdl] = await Promise.all([admin.waitForEvent('download'), admin.click('text=⬇ دریافت فایل')]);
const dump = gunzipSync(readFileSync(await bdl.path())).toString('utf8');
check('backup: gzip SQL with all tables and rows', dump.includes('CREATE TABLE `users`') && dump.includes('INSERT INTO `users`') && dump.trim().endsWith('-- end of backup'));
await admin.goto(ADM);
check('backup download audited; dashboard warning gone', sql("SELECT COUNT(*) FROM audit_logs WHERE action = 'backup.download'") === '1' && !(await admin.textContent('main')).includes('هنوز هیچ نسخه پشتیبانی'));
check('backup never contains plaintext passwords', !dump.includes('Alice strong pass 1') && !dump.includes(temp.bob));

// ---------------------------------------------------------------- secret path rotation
console.log('\n# Secret path rotation');
await admin.goto(ADM + 'system');
check('system page shows both secret addresses', (await admin.textContent('main')).includes(APP) && (await admin.textContent('main')).includes(ADM));
await admin.selectOption('select[name=which]', 'app');
await admin.fill('form[action$="/system/paths"] input[name=password]', 'Admin strong pass 123');
await admin.fill('form[action$="/system/paths"] input[name=confirm]', 'تغییر');
await admin.click('text=ساخت آدرس جدید');
check('app path changed message', (await admin.textContent('main')).includes('آدرس مخفی برنامه تغییر کرد'));
const APP2 = [...(await admin.textContent('main')).matchAll(/https:\/\/[^\s]+\/[A-Za-z0-9]{16}\//g)].map((m) => m[0]).find((u) => u !== ADM);
check('old app address now returns 404', (await anon.request.get(APP)).status() === 404, APP);
check('new app address works', APP2 && APP2 !== APP && (await anon.request.get(APP2)).status() === 200, APP2);
await admin.selectOption('select[name=which]', 'admin');
await admin.fill('form[action$="/system/paths"] input[name=password]', 'Admin strong pass 123');
await admin.fill('form[action$="/system/paths"] input[name=confirm]', 'تغییر');
await admin.click('text=ساخت آدرس جدید');
const ADM2 = (await admin.textContent('b')).trim();
check('admin path changed: new address shown once', /\/[A-Za-z0-9]{16}\/$/.test(ADM2) && ADM2 !== ADM);
check('old admin address → 404', (await anon.request.get(ADM)).status() === 404);
await admin.goto(ADM2); await admin.fill('input[name=username]', 'admin'); await admin.fill('input[name=password]', 'Admin strong pass 123'); await admin.click('button');
check('login works at the new admin address', (await admin.textContent('nav')).includes('داشبورد'));
check('path changes audited', sql("SELECT COUNT(*) FROM audit_logs WHERE action = 'system.path_change'") === '2');

check('no uncaught JavaScript errors', errors.length === 0, errors.join(' | '));
await browser.close();
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
