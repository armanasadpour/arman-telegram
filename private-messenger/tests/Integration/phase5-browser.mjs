// Phase 5 acceptance test (Chromium): user search + follow, admin font upload, in-app WebRTC calls.
//   node tests/Integration/phase5-browser.mjs https://pm.test /srv/pmhost/private-messenger DB USER PASS
// Target: FRESH (not installed) deployment + EMPTY database.
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
const { chromium } = createRequire(import.meta.url)('playwright');

const [BASE, ROOT, DBN, DBU, DBP] = process.argv.slice(2);
const proxy = process.env.HTTPS_PROXY || process.env.https_proxy;
const browser = await chromium.launch({ ...(proxy ? { proxy: { server: proxy, bypass: new URL(BASE).hostname } } : {}),
  args: ['--ignore-certificate-errors', '--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream', '--disable-features=WebRtcHideLocalIpsWithMdns'] });
async function appReady(p, timeout = 30000) {
  await p.waitForSelector('.c-side-head h1, .c-rk', { timeout });
  if (await p.$('.c-rk')) { // first device: confirm the recovery key like a user would
    const k = (await p.textContent('.c-rk')).trim();
    await p.fill('input[placeholder="چهار کاراکتر آخر"]', k.slice(-4)); await p.click('text=ذخیره کردم');
    await p.waitForSelector('.c-side-head h1', { timeout });
  }
}
let pass = 0, fail = 0;
const check = (name, cond, extra = '') => { if (cond) { pass++; console.log('  \x1b[32m✔\x1b[0m', name); } else { fail++; console.log('  \x1b[31m✘', name, '\x1b[0m', extra); } };
const sql = (q) => execFileSync('mysql', ['-N', '-u', DBU, `-p${DBP}`, '-h', '127.0.0.1', DBN, '-e', q]).toString().trim();
const errors = [];
const newCtx = () => browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1100, height: 800 }, permissions: ['microphone', 'camera'] });
const newPage = async (c) => { const p = await c.newPage(); p.on('pageerror', (e) => errors.push(e.message)); p.on('dialog', (d) => d.accept()); return p; };
const T = 30000;

// ---------------------------------------------------------------- setup
console.log('\n# Setup');
const admin = await newPage(await newCtx());
await admin.goto(`${BASE}/install/`);
await admin.fill('input[name=token]', readFileSync(`${ROOT}/storage/install-token.txt`, 'utf8').trim()); await admin.click('button');
await admin.click('summary'); await admin.check('input[name=db_mode][value=manual]');
await admin.fill('input[name=db_name]', DBN); await admin.fill('input[name=db_user]', DBU); await admin.fill('input[name=db_pass]', DBP); await admin.fill('input[name=db_host]', '127.0.0.1');
await admin.fill('input[name=admin_password]', 'Admin strong pass 123'); await admin.fill('input[name=admin_password2]', 'Admin strong pass 123');
await admin.click('form button');
await admin.waitForSelector('text=نصب کامل شد', { timeout: 60000 });
const [ADM, APP] = [...new Set((await admin.content()).match(/https:\/\/[^/"<]+\/[A-Za-z0-9]{16}\//g))];
await admin.goto(ADM);
await admin.fill('input[name=username]', 'admin'); await admin.fill('input[name=password]', 'Admin strong pass 123'); await admin.click('button');
const temp = {};
for (const [u, n] of [['alice', 'آلیس'], ['bob', 'باب'], ['carol', 'کارول'], ['dave', 'داوود']]) {
  await admin.goto(ADM + 'users/new');
  await admin.fill('input[name=username]', u); await admin.fill('input[name=display_name]', n); await admin.check('input[name=activate]');
  await admin.click('main form button');
  temp[u] = (await admin.textContent('.secret')).trim();
}
sql(`INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES ('calls.stun', '""', NOW(3)) ON DUPLICATE KEY UPDATE setting_value = '""'`); // offline test network
async function login(user, pw) {
  const p = await newPage(await newCtx());
  await p.goto(APP);
  await p.fill('input[name=username]', user); await p.fill('input[name=password]', temp[user]); await p.click('button');
  await p.fill('input[name=current]', temp[user]); await p.fill('input[name=new]', pw); await p.fill('input[name=confirm]', pw); await p.click('form button');
  await appReady(p);
  return p;
}
const alice = await login('alice', 'Alice strong pass 1');
const bob = await login('bob', 'Bob strong pass 12');
const carol = await login('carol', 'Carol strong pass 1');
check('setup', true);

// ---------------------------------------------------------------- search & follow
console.log('\n# Finding users: search + follow (no list of all members)');
await alice.click('[aria-label="گفتگوی جدید"]');
await alice.waitForSelector('dialog .small.muted:has-text("هنوز کسی را دنبال نکرده‌اید")', { timeout: T });
const SHOTS0 = process.env.SHOTS;
check('new-chat dialog does not list members by default', (await alice.locator('dialog .c-user').count()) === 0);
await alice.fill('dialog input[type=search]', 'با');
await alice.waitForTimeout(700);
check('fewer than 3 characters: no search results', (await alice.locator('dialog .c-user').count()) === 0);
await alice.fill('dialog input[type=search]', 'باب');
await alice.waitForSelector('dialog .c-user:has-text("باب")', { timeout: T });
check('search by 3+ letters of the name finds exactly the matching user', (await alice.locator('dialog .c-user').count()) === 1);
await alice.fill('dialog input[type=search]', 'bob');
await alice.waitForSelector('dialog .c-user:has-text("@bob")', { timeout: T });
check('search by username works too', true);
await alice.click('dialog .c-user:has-text("باب") >> text=+ دنبال کردن');
await alice.waitForSelector('dialog .c-user:has-text("باب") >> text=✓ دنبال‌شده', { timeout: T });
if (SHOTS0) await alice.screenshot({ path: `${SHOTS0}/search-follow.png` });
await alice.fill('dialog input[type=search]', '');
await alice.waitForSelector('dialog .small.muted:has-text("مخاطبین شما")', { timeout: T });
check('followed user appears in «مخاطبین»; others (carol, dave) do not', (await alice.locator('dialog .c-user').count()) === 1 && !(await alice.textContent('dialog')).includes('کارول'));
const apiStatus = await alice.evaluate(async () => [(await fetch(location.pathname + 'api/v1/users/search?q=ab')).status, (await fetch(location.pathname + 'api/v1/directory')).status]);
check('API: short query rejected (400), old «list everyone» endpoint gone (404)', apiStatus[0] === 400 && apiStatus[1] === 404, String(apiStatus));
await alice.click('dialog .c-user:has-text("باب") button:text-is("گفتگو")');
await alice.waitForSelector('.c-compose textarea', { timeout: T });
await alice.fill('.c-compose textarea', 'سلام باب'); await alice.press('.c-compose textarea', 'Enter');
await bob.waitForSelector('.c-item:has-text("آلیس")', { timeout: T }); await bob.click('.c-list .c-item:has-text("آلیس")');
await bob.waitForSelector('.c-msg.in:has-text("سلام باب")', { timeout: T });
check('chat started from search works', true);

// ---------------------------------------------------------------- font upload
console.log('\n# Admin font upload');
await admin.goto(ADM + 'fonts');
await admin.setInputFiles('input[name=font_Regular]', { name: 'fake.woff2', mimeType: 'font/woff2', buffer: Buffer.from('<?php echo 1; ?>') });
await admin.click('form[enctype] button');
check('non-WOFF2 file (e.g. PHP disguised as .woff2) rejected', (await admin.textContent('main')).includes('معتبر نیست'));
const woff2 = Buffer.concat([Buffer.from('wOF2'), randomBytes(5000)]);
await admin.setInputFiles('input[name=font_Regular]', { name: 'IRANSansWeb.woff2', mimeType: 'font/woff2', buffer: woff2 });
await admin.setInputFiles('input[name=font_Bold]', { name: 'IRANSansWeb_Bold.woff2', mimeType: 'font/woff2', buffer: woff2 });
await admin.click('form[enctype] button');
await admin.waitForSelector('text=فونت ذخیره شد', { timeout: T });
check('admin uploads Regular + Bold → shown as installed', (await admin.locator('.tag.ok').count()) === 2);
const served = await alice.evaluate(async () => { const r = await fetch(location.pathname + 'assets/fonts/IRANSans-Regular.woff2'); return [r.status, r.headers.get('content-type'), (await r.arrayBuffer()).byteLength]; });
check('uploaded font is served under the secret path as font/woff2', served[0] === 200 && served[1] === 'font/woff2' && served[2] === woff2.length, String(served));
check('font upload is audited', sql("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.font_upload'") === '1');

// ---------------------------------------------------------------- calls
console.log('\n# In-app video call (WebRTC)');
await alice.click('[aria-label="تماس تصویری"]');
await bob.waitForSelector('.cl-overlay >> text=تماس ورودی', { timeout: T });
check('Bob sees the incoming video call (signalled through the encrypted channel)', true);
const SHOTS = process.env.SHOTS;
if (SHOTS) await bob.screenshot({ path: `${SHOTS}/call-incoming.png` });
await bob.click('.cl-overlay [aria-label="پاسخ"]');
await Promise.all([alice.waitForSelector('.cl-status:has-text("متصل")', { timeout: T }), bob.waitForSelector('.cl-status:has-text("متصل")', { timeout: T })]);
check('both sides connected', true);
await alice.waitForFunction(() => document.querySelector('.cl-remote')?.videoWidth > 0, null, { timeout: T });
await bob.waitForFunction(() => document.querySelector('.cl-remote')?.videoWidth > 0, null, { timeout: T });
check('video frames flow in both directions', true);
await alice.waitForSelector('.cl-info:has-text("DTLS-SRTP")', { timeout: T });
check('connection info shown (DTLS-SRTP, direct or relay)', true, await alice.textContent('.cl-info'));
if (SHOTS) await alice.screenshot({ path: `${SHOTS}/call-connected.png` });
await alice.click('[aria-label="قطع میکروفون"]');
check('mute toggles the microphone', !!(await alice.$('[aria-label="وصل میکروفون"]')));
await alice.click('[aria-label="خاموش کردن دوربین"]');
check('camera can be turned off', !!(await alice.$('[aria-label="روشن کردن دوربین"]')));
console.log('\n# Busy');
await carol.click('[aria-label="گفتگوی جدید"]'); await carol.fill('dialog input[type=search]', 'باب');
await carol.click('dialog .c-user:has-text("باب") button:text-is("گفتگو")');
await carol.waitForSelector('.c-compose textarea', { timeout: T });
await carol.click('[aria-label="تماس صوتی"]');
await carol.waitForSelector('.c-toast:has-text("در حال مکالمه دیگری است")', { timeout: T });
check('a third person calling gets «busy»; the running call is not disturbed', (await bob.textContent('.cl-status')).includes('متصل'));
await alice.waitForTimeout(2200);
await bob.click('.cl-overlay [aria-label="پایان تماس"]');
await alice.waitForSelector('.c-toast:has-text("تماس پایان یافت")', { timeout: T });
check('hang-up ends the call on both sides', !(await alice.$('.cl-overlay')) && !(await bob.$('.cl-overlay')));
const ended = sql("SELECT CONCAT(status, ':', duration_seconds) FROM calls WHERE status = 'ended' ORDER BY id LIMIT 1");
check('server recorded only metadata: status + duration', /^ended:[2-9]\d*$|^ended:\d{2,}$/.test(ended), ended);

console.log('\n# Reject and missed');
await alice.click('[aria-label="تماس صوتی"]');
await bob.waitForSelector('.cl-overlay >> text=تماس ورودی', { timeout: T });
await bob.click('.cl-overlay [aria-label="رد تماس"]');
await alice.waitForSelector('.c-toast:has-text("تماس را رد کرد")', { timeout: T });
check('reject: caller is told, both overlays closed', !(await alice.$('.cl-overlay')) && !(await bob.$('.cl-overlay')));
await alice.click('[aria-label="تماس صوتی"]');
await bob.waitForSelector('.cl-overlay >> text=تماس ورودی', { timeout: T });
await alice.click('.cl-overlay [aria-label="پایان تماس"]');
await bob.waitForSelector('.c-toast:has-text("تماس ازدست‌رفته")', { timeout: T });
check('caller hangs up before answer → «missed call» for Bob', !(await bob.$('.cl-overlay')));
check('statuses: ended, rejected, cancelled (+ carol\'s busy attempt)', sql("SELECT GROUP_CONCAT(status ORDER BY id) FROM calls") === 'ended,cancelled,rejected,cancelled', sql("SELECT GROUP_CONCAT(status ORDER BY id) FROM calls"));
await bob.click('.c-tab[data-tab=calls]');
await bob.waitForSelector('.c-list .c-call', { timeout: T });
check('call history lists the calls', (await bob.locator('.c-list .c-call').count()) === 4);
await bob.click('.c-tab[data-tab=chats]');
await admin.goto(ADM);
check('admin dashboard shows call statistics (counts only)', (await admin.textContent('main')).includes('تماس‌ها — ۲۴ ساعت'));

// ---------------------------------------------------------------- signalling never in plaintext
console.log('\n# Server never sees call signalling');
const dump = execFileSync('mysqldump', ['--skip-extended-insert', '-u', DBU, `-p${DBP}`, '-h', '127.0.0.1', DBN], { maxBuffer: 1 << 28 }).toString('latin1');
check('no SDP, DTLS fingerprint or ICE candidate in the database', !/a=fingerprint|a=candidate|typ host|v=0\\r\\no=/.test(dump) && !dump.includes('a=ice-ufrag'));
check('no uncaught JavaScript errors', errors.length === 0, errors.join(' | '));
await browser.close();
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
