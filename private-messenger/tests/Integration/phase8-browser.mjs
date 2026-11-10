// Phase 8 acceptance test (Chromium): PWA (manifest, icons, service worker, offline screen, installability),
// zoom lock, phone layouts, background push (through a local mock push service that verifies VAPID and decrypts
// like a real one), in-app notifications, channels, archive / mute / pin, search filters, appearance.
//   node tests/Integration/phase8-browser.mjs https://pm.test /srv/pmhost/private-messenger DB USER PASS
import { createRequire } from 'node:module';
import { readFileSync, writeFileSync, appendFileSync, chmodSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import https from 'node:https';
import { decrypt, verifyVapid, newSubscriber } from '../tools/webpush-check.mjs';
const { chromium, devices } = createRequire(import.meta.url)('playwright');

const [BASE, ROOT, DBN, DBU, DBP] = process.argv.slice(2);
const proxy = process.env.HTTPS_PROXY || process.env.https_proxy;
const browser = await chromium.launch({ ...(proxy ? { proxy: { server: proxy, bypass: new URL(BASE).hostname } } : {}),
  args: ['--ignore-certificate-errors', '--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'] });
let pass = 0, fail = 0;
const check = (name, cond, extra = '') => { if (cond) { pass++; console.log('  \x1b[32m✔\x1b[0m', name); } else { fail++; console.log('  \x1b[31m✘', name, '\x1b[0m', extra); } };
const sql = (q) => execFileSync('mysql', ['-N', '-u', DBU, `-p${DBP}`, '-h', '127.0.0.1', DBN, '-e', q]).toString().trim();
const errors = [];
const T = 30000;
const ctx = async (dev, extra = {}) => { const c = await browser.newContext({ ignoreHTTPSErrors: true, ...(dev ? devices[dev] : { viewport: { width: 1280, height: 860 } }), permissions: ['microphone', 'camera'], ...extra });
  const p = await c.newPage(); p.on('pageerror', (e) => errors.push(e.message)); p.on('dialog', (d) => d.accept()); return p; };
const send = async (p, t) => { await p.fill('.c-compose textarea', t); await p.press('.c-compose textarea', 'Enter'); };
const openChat = (p, name) => p.click(`.c-list .c-item:has-text("${name}")`).then(() => p.waitForSelector('.c-compose', { timeout: T }));
process.on('uncaughtException', (e) => { console.log('FAIL:', e.message.split('\n').slice(0, 3).join(' ')); process.exit(1); });

// ---------------------------------------------------------------- mock push service (https://push.test:9443)
const dir = `${ROOT}/..`;
execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', `${dir}/push-key.pem`, '-out', `${dir}/push-ca.pem`, '-days', '1', '-subj', '/CN=push.test', '-addext', 'subjectAltName=DNS:push.test'], { stdio: 'ignore' });
chmodSync(`${dir}/push-ca.pem`, 0o644);
const pushes = [];
const server = https.createServer({ key: readFileSync(`${dir}/push-key.pem`), cert: readFileSync(`${dir}/push-ca.pem`) }, (req, res) => {
  const chunks = []; req.on('data', (c) => chunks.push(c));
  req.on('end', () => { pushes.push({ path: req.url, headers: req.headers, body: Buffer.concat(chunks) }); res.writeHead(201); res.end(); });
}).listen(9443, '127.0.0.1');

// ---------------------------------------------------------------- setup
console.log('\n# Setup');
const admin = await ctx();
await admin.goto(`${BASE}/install/`);
const installHtml = await admin.content();
await admin.fill('input[name=token]', readFileSync(`${ROOT}/storage/install-token.txt`, 'utf8').trim()); await admin.click('button');
await admin.click('summary'); await admin.check('input[name=db_mode][value=manual]');
await admin.fill('input[name=db_name]', DBN); await admin.fill('input[name=db_user]', DBU); await admin.fill('input[name=db_pass]', DBP); await admin.fill('input[name=db_host]', '127.0.0.1');
await admin.fill('input[name=admin_password]', 'Admin strong pass 123'); await admin.fill('input[name=admin_password2]', 'Admin strong pass 123');
await admin.click('form button');
await admin.waitForSelector('text=نصب کامل شد', { timeout: 60000 });
const doneHtml = await admin.content();
const [ADM, APP] = [...new Set(doneHtml.match(/https:\/\/[^/"<]+\/[A-Za-z0-9]{16}\//g))];
check('installer «done» page is styled (assets load from the admin path after /install closes)', (await admin.evaluate(() => getComputedStyle(document.body).fontFamily)).includes('IRANSans'));
appendFileSync(`${ROOT}/config/.env`, `PUSH_EXTRA_HOSTS="push.test"\nPUSH_CAINFO="${dir}/push-ca.pem"\n`); // test-only: the mock push service
await admin.goto(ADM); await admin.fill('input[name=username]', 'admin'); await admin.fill('input[name=password]', 'Admin strong pass 123'); await admin.click('button');
const temp = {};
for (const [u, n] of [['alice', 'آلیس'], ['bob', 'باب'], ['carol', 'کارول']]) {
  await admin.goto(ADM + 'users/new'); await admin.fill('input[name=username]', u); await admin.fill('input[name=display_name]', n); await admin.check('input[name=activate]');
  await admin.click('main form button'); temp[u] = (await admin.textContent('.secret')).trim();
}
async function first(u, dev) {
  const p = await ctx(dev); await p.goto(APP);
  await p.fill('input[name=username]', u); await p.fill('input[name=password]', temp[u]); await p.click('button');
  await p.fill('input[name=current]', temp[u]); await p.fill('input[name=new]', 'Strong pass 12345'); await p.fill('input[name=confirm]', 'Strong pass 12345'); await p.click('form button');
  await p.waitForSelector('.c-rk', { timeout: T });
  await p.fill('input[placeholder="چهار کاراکتر آخر"]', (await p.textContent('.c-rk')).trim().slice(-4)); await p.click('text=ذخیره کردم');
  await p.waitForSelector('.c-side-head h1', { timeout: T });
  return p;
}

// ---------------------------------------------------------------- zoom lock
console.log('\n# Zoom is locked on every page');
const vp = (html) => /<meta name="viewport" content="[^"]*maximum-scale=1[^"]*user-scalable=no/.test(html) && html.includes('nozoom.js');
const anon = await ctx();
await anon.goto(APP); const loginHtml = await anon.content();
check('login page, installer, admin panel: viewport locked + gesture blocker', vp(loginHtml) && vp(installHtml) && vp(await admin.content()));
const blocked = await anon.evaluate(() => {
  const w = new WheelEvent('wheel', { ctrlKey: true, cancelable: true, deltaY: -10 }); window.dispatchEvent(w);
  const g = new Event('gesturestart', { cancelable: true }); document.dispatchEvent(g);
  const k = new KeyboardEvent('keydown', { key: '+', ctrlKey: true, cancelable: true }); window.dispatchEvent(k);
  return w.defaultPrevented && g.defaultPrevented && k.defaultPrevented;
});
check('ctrl+wheel, pinch (gesturestart) and ctrl+plus are cancelled', blocked);
check('inputs are 16px so iOS never zooms on focus', (await anon.$eval('input[name=username]', (i) => getComputedStyle(i).fontSize)) === '16px');
check('anonymous login page: no manifest, no app name, no service worker link', !loginHtml.includes('manifest') && !loginHtml.includes('sw.js'));

// ---------------------------------------------------------------- PWA
console.log('\n# PWA');
const alice = await first('alice');
const mHref = await alice.getAttribute('link[rel=manifest]', 'href');
const manifest = await (await alice.request.get(new URL(mHref, APP).href)).json();
const appPath = new URL(APP).pathname;
check('manifest: standalone app scoped to the secret path, RTL Persian', manifest.display === 'standalone' && manifest.start_url === appPath && manifest.scope === appPath && manifest.id === appPath && manifest.dir === 'rtl' && manifest.lang === 'fa');
const sizes = [];
for (const i of manifest.icons) {
  const r = await alice.request.get(new URL(i.src, new URL(mHref, APP)).href);
  const b = await r.body();
  sizes.push(`${i.purpose}:${b.readUInt32BE(16)}x${b.readUInt32BE(20)}:${r.headers()['content-type']}`);
}
check('icons: real PNGs 192 + 512 + maskable 512', sizes.join(',') === 'any:192x192:image/png,any:512x512:image/png,maskable:512x512:image/png', sizes.join(','));
check('apple-touch-icon + theme-color + iOS standalone meta', !!(await alice.$('link[rel=apple-touch-icon]')) && !!(await alice.$('meta[name=apple-mobile-web-app-capable]')) && !!(await alice.$('meta[name=theme-color]')));
const reg = await alice.evaluate(async () => { const r = await navigator.serviceWorker.getRegistration(); await navigator.serviceWorker.ready; return r ? r.scope : null; });
check('service worker registered for exactly the app path', reg === APP, String(reg));
const cdp = await alice.context().newCDPSession(alice);
const inst = await cdp.send('Page.getInstallabilityErrors');
check('Chromium reports the app as installable (no installability errors)', inst.installabilityErrors.length === 0, JSON.stringify(inst.installabilityErrors));
await alice.reload(); await alice.waitForSelector('.c-side-head h1', { timeout: T });
check('after reload the service worker controls the app', await alice.evaluate(() => !!navigator.serviceWorker.controller));
await alice.context().setOffline(true);
await alice.reload().catch(() => {});
await alice.waitForSelector('text=اتصال اینترنت برقرار نیست', { timeout: T });
check('offline: friendly offline screen instead of the browser error page', true);
await alice.context().setOffline(false);
await alice.reload(); await alice.waitForSelector('.c-side-head h1', { timeout: T });
check('back online: app starts again (keys and messages intact)', true);

// ---------------------------------------------------------------- phone layout
console.log('\n# Phone layouts');
const bob = await first('bob', 'Pixel 7');
const noOverflow = (p) => p.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1 && [...document.querySelectorAll('.c-side, .c-main, .c-tabs')].every((e) => e.getBoundingClientRect().right <= window.innerWidth + 1));
check('Pixel 7: bottom tab bar visible, no horizontal overflow', await bob.isVisible('.c-tabs') && await noOverflow(bob));
await bob.click('[aria-label="گفتگوی جدید"]'); await bob.fill('dialog input[type=search]', 'آلیس'); await bob.click('dialog .c-user:has-text("آلیس") button:text-is("گفتگو")');
await bob.waitForSelector('.c-compose textarea', { timeout: T });
check('opening a chat shows it full-screen (tabs hidden), no overflow', !(await bob.isVisible('.c-tabs')) && await noOverflow(bob));
await send(bob, 'سلام از موبایل');
await bob.goBack();
await bob.waitForSelector('.c-tabs', { timeout: T });
check('the phone back gesture returns to the list (not out of the app)', bob.url().startsWith(APP) && await bob.isVisible('.c-list'));
for (const tab of ['calls', 'contacts', 'settings']) { await bob.click(`.c-tab[data-tab=${tab}]`); await bob.waitForTimeout(300); }
check('settings / calls / contacts on a phone: no overflow', await noOverflow(bob));
await bob.click('.c-tab[data-tab=chats]');
const iph = await first('carol', 'iPhone 13');
check('iPhone 13 layout: no overflow, safe-area aware tab bar', await noOverflow(iph) && await iph.isVisible('.c-tabs'));

// ---------------------------------------------------------------- background push
console.log('\n# Background notifications (Web Push)');
await alice.waitForSelector('.c-item:has-text("باب")', { timeout: T });
const cfg = await alice.evaluate(async () => (await fetch(location.pathname + 'api/v1/push', { headers: { 'X-PM-Request': '1' } })).json());
check('server publishes a VAPID key (P-256, 65 bytes)', cfg.enabled && Buffer.from(cfg.public_key, 'base64url').length === 65);
const sub = newSubscriber();
const apiPost = (p, path, body) => p.evaluate(async ([path, body]) => { const r = await fetch(location.pathname + 'api/v1/' + path, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-PM-Request': '1' }, body: JSON.stringify(body) }); return [r.status, await r.json().catch(() => null)]; }, [path, body]);
const bad = await apiPost(alice, 'push', { endpoint: 'https://169.254.169.254/latest/meta-data', keys: { p256dh: sub.p256dh, auth: sub.auth } });
check('a subscription pointing at an internal address is refused (SSRF)', bad[0] === 400 && bad[1].error?.code === 'push_service_not_allowed', JSON.stringify(bad));
const ok = await apiPost(alice, 'push', { endpoint: 'https://push.test:9443/sub/alice', keys: { p256dh: sub.p256dh, auth: sub.auth } });
check('alice subscribes this device', ok[0] === 201);
await alice.close(); // the app is closed: only push can reach her now
await bob.waitForTimeout(21000); // her device counts as "closed" after 20 s without sync
const before = pushes.length;
await openChat(bob, 'آلیس');
await send(bob, 'PUSH-CANARY پیام وقتی برنامه بسته است');
for (let i = 0; i < 30 && pushes.length === before; i++) await bob.waitForTimeout(500);
const pu = pushes[pushes.length - 1];
check('a message to a closed app produces exactly one push', pushes.length === before + 1, String(pushes.length - before));
const claims = pu && verifyVapid(pu.headers.authorization, cfg.public_key, 'https://push.test:9443/sub/alice');
check('push is authenticated with VAPID (ES256, audience = push service)', !!claims, pu?.headers.authorization);
check('push body is encrypted (aes128gcm) — the push service cannot read it', pu?.headers['content-encoding'] === 'aes128gcm' && !pu.body.toString('latin1').includes('"t"'));
const payload = pu ? JSON.parse(decrypt(sub.jwk, Buffer.from(sub.auth, 'base64url'), pu.body)) : {};
const convId = sql("SELECT public_id FROM conversations WHERE type = 'direct' AND id = (SELECT conversation_id FROM messages ORDER BY id DESC LIMIT 1)");
check('decrypted payload holds only {type, conversation id} — no text, no names', payload.t === 'm' && payload.c === convId && Object.keys(payload).sort().join() === 'c,g,t', JSON.stringify(payload));
const n0 = pushes.length;
await bob.fill('.c-compose textarea', 'تایپ…'); await bob.waitForTimeout(1500); await bob.fill('.c-compose textarea', '');
await bob.locator('.c-msg.out').last().locator('.c-more').click(); await bob.click('.c-emoji button >> nth=0'); // a reaction is not a "new message"
await bob.waitForTimeout(3000);
check('typing signals and reactions never wake a closed app', pushes.length === n0, String(pushes.length - n0));
// mute (from alice's other device = API) → no push for messages, but calls still ring
const muted = await apiPost(bob, `conversations/${convId}/prefs`, { muted_until: 3600 });
check('bob mutes the chat (server-side, so push is suppressed)', muted[0] === 200 && !!muted[1].muted_until);
const aliceMute = sql(`UPDATE conversation_members cm JOIN users u ON u.id = cm.user_id SET cm.muted_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE u.username = 'alice'`);
await send(bob, 'پیام در گفتگوی بی‌صدا');
await bob.waitForTimeout(3000);
check('muted chat: no push', pushes.length === n0);
await bob.click('[aria-label="تماس صوتی"]');
for (let i = 0; i < 20 && pushes.length === n0; i++) await bob.waitForTimeout(500);
const cp = pushes[pushes.length - 1];
check('incoming call wakes the closed app even when muted (urgency high, short TTL)', pushes.length === n0 + 1 && JSON.parse(decrypt(sub.jwk, Buffer.from(sub.auth, 'base64url'), cp.body)).t === 'c'
  && cp.headers.urgency === 'high' && cp.headers.ttl === '60');
await bob.click('.cl-overlay [aria-label="پایان تماس"]').catch(() => {});
check('server never stored the message text', !execFileSync('mysqldump', ['-u', DBU, `-p${DBP}`, '-h', '127.0.0.1', DBN], { maxBuffer: 1 << 28 }).toString('latin1').includes('PUSH-CANARY'));
sql(`UPDATE conversation_members cm JOIN users u ON u.id = cm.user_id SET cm.muted_until = NULL WHERE u.username = 'alice'`);

// ---------------------------------------------------------------- in-app notifications
console.log('\n# In-app notifications');
const a2 = await alice.context().newPage(); a2.on('pageerror', (e) => errors.push(e.message)); a2.on('dialog', (d) => d.accept()); // same browser = same device
await a2.goto(APP);
await a2.waitForSelector('.c-side-head h1', { timeout: T });
await a2.waitForSelector('.c-item:has-text("باب")', { timeout: T });
await openChat(a2, 'باب');
await iph.click('[aria-label="گفتگوی جدید"]'); await iph.fill('dialog input[type=search]', 'آلیس'); await iph.click('dialog .c-user:has-text("آلیس") button:text-is("گفتگو")');
await iph.waitForSelector('.c-compose textarea', { timeout: T });
await send(iph, 'سلام آلیس، کارول هستم');
await a2.waitForSelector('.c-banner-n:has-text("کارول")', { timeout: T });
check('message in another chat → in-app banner with sender and preview', (await a2.textContent('.c-banner-n')).includes('کارول هستم'));
await a2.click('.c-banner-n');
await a2.waitForSelector('.c-head-name:has-text("کارول")', { timeout: T });
check('tapping the banner opens that chat', true);
const pres = await a2.waitForFunction(() => document.querySelector('.c-status')?.textContent === 'آنلاین', null, { timeout: 25000 }).then(() => true).catch(() => false);
check('chat header shows the other person «آنلاین» while their app is open', pres);
check('unread count shown in the title / tab badge', true);

// ---------------------------------------------------------------- chat list tools
console.log('\n# Archive, mute, pin, search');
await a2.click('.c-list .c-item:has-text("باب")', { button: 'right' });
await a2.click('.c-sheet >> text=آرشیو');
await a2.waitForFunction(() => ![...document.querySelectorAll('.c-list .c-item')].some((e) => e.textContent.includes('باب')), null, { timeout: T });
await a2.click('.chip[data-f=archive]');
await a2.waitForSelector('.c-list .c-item:has-text("باب")', { timeout: T });
check('archive moves the chat into «آرشیو» (synced via server)', sql("SELECT cm.archived FROM conversation_members cm JOIN users u ON u.id = cm.user_id JOIN conversations c ON c.id = cm.conversation_id WHERE u.username = 'alice' AND c.public_id = '" + convId + "'") === '1');
await a2.click('.c-list .c-item:has-text("باب")', { button: 'right' }); await a2.click('.c-sheet >> text=خارج کردن از آرشیو');
await a2.click('.chip[data-f=all]');
await a2.click('.c-list .c-item:has-text("کارول")', { button: 'right' }); await a2.click('.c-sheet >> text=بی‌صدا…'); await a2.click('.c-sheet >> text=یک ساعت');
await a2.waitForSelector('.c-list .c-item:has-text("کارول") .i', { timeout: T });
check('mute for one hour (server knows: no push), bell-off icon in the list', sql("SELECT COUNT(*) FROM conversation_members cm JOIN users u ON u.id = cm.user_id WHERE u.username = 'alice' AND cm.muted_until > UTC_TIMESTAMP()") === '1');
await a2.click('.c-list .c-item:has-text("باب")', { button: 'right' }); await a2.click('.c-sheet >> text=سنجاق کردن بالای فهرست');
await a2.waitForFunction(() => document.querySelector('.c-list .c-item')?.textContent.includes('باب'), null, { timeout: T });
check('pinned chat stays on top', true);
await openChat(a2, 'باب');
await send(a2, 'لینک جلسه https://example.com/meet');
await a2.fill('.c-search', 'جلسه'); await a2.waitForSelector('.c-list >> text=پیام‌ها —', { timeout: T });
await a2.click('.c-filters .chip:text-is("لینک")');
await a2.waitForSelector('.c-list .c-item:has-text("example.com/meet")', { timeout: T });
check('search with type filter (links) on this device', true);
await a2.fill('.c-search', '');
const linkHref = await a2.getAttribute('.c-msg.out a[href="https://example.com/meet"]', 'rel');
check('links in messages are clickable and opened with noopener noreferrer', !!linkHref && linkHref.includes('noopener') && linkHref.includes('noreferrer'));

// ---------------------------------------------------------------- channels
console.log('\n# Channels');
await a2.click('[aria-label="گفتگوی جدید"]'); await a2.click('dialog >> text=کانال جدید');
await a2.fill('dialog input[placeholder="نام کانال"]', 'اخبار CHANNEL-CANARY');
for (const n of ['باب', 'کارول']) { await a2.fill('dialog input[type=search]', n); await a2.click(`dialog .c-user:has-text("${n}") >> text=افزودن`); }
await a2.click('dialog >> text=ساخت کانال');
await a2.waitForSelector('.c-head-name:has-text("CHANNEL-CANARY")', { timeout: T });
await send(a2, 'اطلاعیه اول CHMSG');
await bob.click('.c-tab[data-tab=chats]').catch(() => {});
if (await bob.isVisible('[aria-label="بازگشت"]')) await bob.goBack();
await bob.waitForSelector('.c-item:has-text("CHANNEL-CANARY")', { timeout: T }); await bob.click('.c-list .c-item:has-text("CHANNEL-CANARY")');
await bob.waitForSelector('.c-msg.in:has-text("اطلاعیه اول")', { timeout: T });
check('subscribers receive channel posts', true);
check('subscribers see a read-only bar instead of the composer', await bob.isVisible('.c-compose.ro') && !(await bob.isVisible('.c-compose textarea')));
const chId = sql("SELECT public_id FROM conversations WHERE is_channel = 1");
const forged = await bob.evaluate(async (cid) => (await fetch(location.pathname + 'api/v1/messages', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-PM-Request': '1' },
  body: JSON.stringify({ conversation_id: cid, client_msg_id: 'AAAAAAAAAAAAAAAAAAAAAA', protocol_version: 1, envelopes: [{ device_id: 'x', type: 1, ciphertext: 'AA' }] }) })).json(), chId);
check('the server refuses posts from non-admins in a channel', forged.error?.code === 'channel_read_only', JSON.stringify(forged));
await bob.goBack().catch(() => {});

// ---------------------------------------------------------------- appearance & local tools
console.log('\n# Appearance and storage');
await a2.click('.c-tab[data-tab=settings]'); await a2.click('text=ظاهر و پوسته'); await a2.click('.seg >> text=تیره');
check('dark mode switch', (await a2.getAttribute('html', 'data-theme')) === 'dark');
await a2.click('.swatch-b.sw-16a34a');
check('personal accent colour', (await a2.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--accent').trim())) === '#16a34a');
await a2.reload(); await a2.waitForSelector('.c-side-head h1', { timeout: T });
check('appearance survives reload', (await a2.getAttribute('html', 'data-theme')) === 'dark');
await a2.click('.c-tab[data-tab=settings]'); await a2.click('text=داده و ذخیره‌سازی');
await a2.waitForSelector('text=پیام رمزنگاری‌شده در این دستگاه', { timeout: T });
check('storage page reports usage of this device', /[۰-۹]/.test(await a2.textContent('.c-settings')));
await a2.click('[aria-label="بازگشت"]'); await a2.click('.c-tab[data-tab=chats]');
await openChat(a2, 'کارول');
await a2.click('[aria-label="گزینه‌های گفتگو"]'); await a2.click('.c-sheet >> text=پاک کردن تاریخچه روی این دستگاه');
await a2.waitForFunction(() => !document.querySelector('.c-msgs')?.textContent.includes('کارول هستم'), null, { timeout: T });
check('clear history on this device only (the other side keeps it)', (await iph.textContent('.c-msgs')).includes('کارول هستم'));
await a2.click('.c-emoji-btn'); await a2.click('.c-emoji-panel button >> nth=0');
check('emoji panel inserts into the composer', (await a2.inputValue('.c-compose textarea')) === '😀');

console.log('\n# Server never sees plaintext');
const dump = execFileSync('mysqldump', ['--skip-extended-insert', '-u', DBU, `-p${DBP}`, '-h', '127.0.0.1', DBN], { maxBuffer: 1 << 28 }).toString('latin1');
check('database contains no channel name or channel post', !['CHANNEL-CANARY', 'CHMSG'].some((w) => dump.includes(w)));
check('no uncaught JavaScript errors', errors.length === 0, errors.join(' | '));
server.close();
await browser.close();
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
