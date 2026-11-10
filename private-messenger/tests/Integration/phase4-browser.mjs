// Phase 4 acceptance test (Chromium) against a real server: message features, encrypted media and files,
// resumable upload, retention.  node tests/Integration/phase4-browser.mjs https://pm.test /srv/pmhost/private-messenger DB USER PASS
// Target: FRESH (not installed) deployment + EMPTY database.
import { createRequire } from 'node:module';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
const { chromium } = createRequire(import.meta.url)('playwright');

const [BASE, ROOT, DBN, DBU, DBP] = process.argv.slice(2);
const proxy = process.env.HTTPS_PROXY || process.env.https_proxy;
const browser = await chromium.launch({ ...(proxy ? { proxy: { server: proxy, bypass: new URL(BASE).hostname } } : {}),
  args: ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'] });
let pass = 0, fail = 0;
const check = (name, cond, extra = '') => { if (cond) { pass++; console.log('  \x1b[32m✔\x1b[0m', name); } else { fail++; console.log('  \x1b[31m✘', name, '\x1b[0m', extra); } };
const sha = (b) => createHash('sha256').update(b).digest('hex');
const sql = (q) => execFileSync('mysql', ['-N', '-u', DBU, `-p${DBP}`, '-h', '127.0.0.1', DBN, '-e', q]).toString().trim();
const errors = [];
const newCtx = (vp = { width: 1200, height: 860 }) => browser.newContext({ ignoreHTTPSErrors: true, viewport: vp, permissions: ['microphone'] });
const newPage = async (c) => { const p = await c.newPage(); p.on('pageerror', (e) => errors.push(e.message)); p.on('dialog', (d) => d.accept()); return p; };
const T = 25000;

// ---------------------------------------------------------------- setup
console.log('\n# Setup');
const admin = await newPage(await newCtx());
await admin.goto(`${BASE}/install/`);
await admin.fill('input[name=token]', readFileSync(`${ROOT}/storage/install-token.txt`, 'utf8').trim()); await admin.click('button');
await admin.click('summary'); await admin.check('input[name=db_mode][value=manual]');
await admin.fill('input[name=db_name]', DBN); await admin.fill('input[name=db_user]', DBU); await admin.fill('input[name=db_pass]', DBP); await admin.fill('input[name=db_host]', '127.0.0.1');
await admin.fill('input[name=admin_password]', 'Admin strong pass 123'); await admin.fill('input[name=admin_password2]', 'Admin strong pass 123');
await admin.click('form button');
const [ADM, APP] = [...new Set((await admin.content()).match(/https:\/\/[^/"<]+\/[A-Za-z0-9]{16}\//g))];
await admin.goto(ADM);
await admin.fill('input[name=username]', 'admin'); await admin.fill('input[name=password]', 'Admin strong pass 123'); await admin.click('button');
const temp = {};
for (const [u, n] of [['alice', 'آلیس'], ['bob', 'باب'], ['carol', 'کارول']]) {
  await admin.goto(ADM + 'users/new');
  await admin.fill('input[name=username]', u); await admin.fill('input[name=display_name]', n); await admin.check('input[name=activate]');
  await admin.click('main form button');
  temp[u] = (await admin.textContent('.secret')).trim();
}
async function login(user, pw) {
  const ctx = await newCtx(); const p = await newPage(ctx);
  await p.goto(APP);
  await p.fill('input[name=username]', user); await p.fill('input[name=password]', temp[user]); await p.click('button');
  await p.fill('input[name=current]', temp[user]); await p.fill('input[name=new]', pw); await p.fill('input[name=confirm]', pw); await p.click('form button');
  await p.waitForSelector('.c-side-head h1', { timeout: T });
  return { p, ctx };
}
const { p: alice, ctx: aCtx } = await login('alice', 'Alice strong pass 1');
const { p: bob } = await login('bob', 'Bob strong pass 12');
const { p: carol } = await login('carol', 'Carol strong pass 1');
async function openWith(p, name) {
  if (!(await p.$(`.c-item:has-text("${name}")`))) { await p.click('text=گفتگوی جدید'); await p.click(`dialog .c-item:has-text("${name}")`); }
  else await p.click(`.c-list .c-item:has-text("${name}")`);
  await p.waitForSelector('.c-compose textarea', { timeout: T });
}
const send = async (p, text) => { await p.fill('.c-compose textarea', text); await p.press('.c-compose textarea', 'Enter'); };
const msgOf = (p, text) => p.locator('.c-msg', { hasText: text }).last();
const menuOn = async (p, text, label) => { await msgOf(p, text).locator('.c-more').click(); await p.click(`.c-menu >> text=${label}`); };
await openWith(alice, 'باب');
await send(alice, 'اولین پیام آلیس');
await bob.waitForSelector('.c-item:has-text("آلیس")', { timeout: T });
await openWith(bob, 'آلیس');
await bob.waitForSelector('.c-msg.in:has-text("اولین پیام آلیس")', { timeout: T });
check('setup: three users, alice ↔ bob conversation', true);

// ---------------------------------------------------------------- message features
console.log('\n# Message features (all inside end-to-end encryption)');
await alice.waitForSelector('.c-msg.out:has-text("اولین پیام آلیس") .st.read', { timeout: T });
check('read receipt: Alice sees blue ✓✓ after Bob opened the chat', true);
await bob.type('.c-compose textarea', 'در حال تایپ', { delay: 30 });
await alice.waitForSelector('.c-typing:text("در حال نوشتن…")', { timeout: T });
check('typing indicator reaches Alice (encrypted, only to online devices)', true);
await bob.fill('.c-compose textarea', '');
await menuOn(bob, 'اولین پیام آلیس', '↩ پاسخ');
await send(bob, 'پاسخ باب');
await alice.waitForSelector('.c-msg.in:has-text("پاسخ باب") .c-quote:has-text("اولین پیام آلیس")', { timeout: T });
check('reply shows the quoted message on the other side', true);
await msgOf(alice, 'پاسخ باب').locator('.c-more').click(); await alice.click('.c-menu .c-emoji >> text=👍');
await bob.waitForSelector('.c-msg.out:has-text("پاسخ باب") .c-reacts:has-text("👍")', { timeout: T });
check('reaction 👍 arrives', true);
await send(alice, 'متن قبل از ویرایش');
await bob.waitForSelector('.c-msg.in:has-text("متن قبل از ویرایش")', { timeout: T });
await menuOn(alice, 'متن قبل از ویرایش', '✏ ویرایش');
await alice.fill('.c-compose textarea', 'متن بعد از ویرایش'); await alice.press('.c-compose textarea', 'Enter');
await bob.waitForSelector('.c-msg.in:has-text("متن بعد از ویرایش"):has-text("ویرایش‌شده")', { timeout: T });
await bob.waitForTimeout(500);
const bobText = await bob.evaluate(() => document.body.innerText);
check('edit propagates and is marked «ویرایش‌شده»', !bobText.includes('متن قبل از ویرایش'), bobText.split('\n').filter((l) => l.includes('قبل از ویرایش')).join(' | '));
await send(alice, 'این حذف می‌شود');
await bob.waitForSelector('.c-msg.in:has-text("این حذف می‌شود")', { timeout: T });
await menuOn(alice, 'این حذف می‌شود', '🗑 حذف برای همه');
await bob.waitForFunction(() => !document.body.innerText.includes('این حذف می‌شود'), null, { timeout: T });
check('delete for everyone removes the text on the recipient device', (await bob.locator('.c-msg.in.deleted').count()) >= 1);
const bobMenuForeign = async () => { await msgOf(bob, 'متن بعد از ویرایش').locator('.c-more').click(); const t = await bob.textContent('.c-menu'); await bob.keyboard.press('Escape'); await bob.mouse.click(5, 5); return t; };
const fm = await bobMenuForeign();
check('only the author is offered edit / delete-for-everyone', !fm.includes('ویرایش') && !fm.includes('حذف برای همه') && fm.includes('حذف برای من'));
await menuOn(bob, 'متن بعد از ویرایش', '📌 سنجاق');
await alice.waitForSelector('.c-pinbar:has-text("متن بعد از ویرایش")', { timeout: T });
check('pin shows the pinned bar on both sides', !!(await bob.$('.c-pinbar')));
await alice.fill('.c-search', 'ویرایش');
await alice.waitForSelector('.c-list .c-item:has-text("متن بعد از ویرایش")', { timeout: T });
check('local search finds the message (runs on the device only)', true);
await alice.fill('.c-search', '');

// ---------------------------------------------------------------- images
console.log('\n# Images (encrypted before upload)');
const png = Buffer.from((await alice.evaluate(() => {
  const c = document.createElement('canvas'); c.width = 3000; c.height = 2000; const g = c.getContext('2d');
  for (let i = 0; i < 60; i++) { g.fillStyle = `hsl(${i * 6},70%,50%)`; g.fillRect(i * 50, 0, 50, 2000); }
  g.fillStyle = '#000'; g.font = '200px sans-serif'; g.fillText('IMG-CANARY', 300, 1000);
  return c.toDataURL('image/png');
})).split(',')[1], 'base64');
const sendFiles = async (p, files, quality) => {
  await p.setInputFiles('.c-compose input[type=file]', files);
  await p.waitForSelector('dialog .c-senditem', { timeout: T });
  if (quality) await p.selectOption('dialog select', quality);
  await p.click('dialog button:text-is("ارسال")');
};
await sendFiles(alice, [{ name: 'photo.png', mimeType: 'image/png', buffer: png }], 'hd');
try { await bob.waitForSelector('.c-msg.in img.c-thumb', { timeout: 60000 }); }
catch (e) { console.log('ALICE LAST:', await alice.evaluate(() => [...document.querySelectorAll('.c-msg.out')].slice(-2).map((m) => m.innerText).join(' || ')), 'ERR:', errors); throw e; }
check('HD image: thumbnail arrives inside the encrypted message', true);
await bob.locator('.c-msg.in .c-media').last().click();
await bob.waitForSelector('dialog img.c-view', { timeout: T });
const hdW = await bob.$eval('dialog img.c-view', (i) => new Promise((r) => (i.complete ? r(i.naturalWidth) : (i.onload = () => r(i.naturalWidth)))));
check('HD image decrypted on Bob\'s device, resized to 2560 px', hdW === 2560, String(hdW));
await bob.click('dialog >> text=بستن');
await sendFiles(alice, [{ name: 'original.png', mimeType: 'image/png', buffer: png }], 'original');
await bob.waitForFunction(() => document.querySelectorAll('.c-msg.in img.c-thumb').length >= 2, null, { timeout: 60000 });
await bob.locator('.c-msg.in .c-media').last().click();
await bob.waitForSelector('dialog img.c-view', { timeout: T });
const [dl] = await Promise.all([bob.waitForEvent('download'), bob.click('dialog >> text=ذخیره روی دستگاه')]);
const got = readFileSync(await dl.path());
check('Original image: downloaded bytes are identical to what Alice selected', sha(got) === sha(png), `${got.length} vs ${png.length}`);
await bob.click('dialog >> text=بستن');

// ---------------------------------------------------------------- large file, resumable upload
console.log('\n# Large file with interrupted connection');
const block = Buffer.from('FILE-CANARY-plaintext-'.repeat(1000));
const big = Buffer.concat([...Array(Math.ceil(15 * 1048576 / block.length))].map(() => block)).subarray(0, 15 * 1048576 + 12345);
let chunk1 = 0;
await aCtx.route(/\/attachments\/[0-9a-f]+\/chunks\/1$/, async (route) => { chunk1++; if (chunk1 === 1) await route.abort('connectionreset'); else await route.continue(); });
await sendFiles(alice, [{ name: 'report.bin', mimeType: 'application/octet-stream', buffer: big }]);
await alice.waitForSelector('.c-msg.out:has-text("report.bin") .st:text("✓")', { timeout: 120000 });
check('upload resumed after the connection dropped mid-transfer', chunk1 >= 2, `chunk-1 attempts: ${chunk1}`);
check('file uploaded in several chunks', Number(sql("SELECT MAX(chunk_count) FROM attachments")) >= 3);
await bob.waitForSelector('.c-msg.in:has-text("report.bin")', { timeout: T });
const [dl2] = await Promise.all([bob.waitForEvent('download', { timeout: 60000 }), bob.locator('.c-msg.in .c-file', { hasText: 'report.bin' }).click()]);
const got2 = readFileSync(await dl2.path());
check('15 MB file decrypted by Bob is byte-identical', sha(got2) === sha(big), `${got2.length}`);
const stored = readdirSync(`${ROOT}/storage/attachments`, { recursive: true }).map((f) => `${ROOT}/storage/attachments/${f}`).filter((f) => statSync(f).isFile());
check('server stores ciphertext only (no plaintext in any stored file)', stored.length >= 3 && stored.every((f) => !readFileSync(f).includes('CANARY')));
check('stored size = plaintext + 16 bytes per 256 KiB segment', stored.some((f) => statSync(f).size === big.length + 16 * Math.ceil(big.length / 262144)));
check('files are stored outside the web root', !stored.some((f) => f.includes('public_html')));

// ---------------------------------------------------------------- voice and video
console.log('\n# Voice and video');
await alice.click('.c-compose [aria-label="ضبط پیام صوتی"]');
await alice.waitForTimeout(2200);
await alice.click('.c-compose .c-icon.rec');
await bob.waitForSelector('.c-msg.in button:has-text("پخش پیام صوتی")', { timeout: 60000 });
await bob.locator('.c-msg.in button:has-text("پخش پیام صوتی")').last().click();
await bob.waitForSelector('.c-msg.in audio', { timeout: T });
const dur = await bob.$eval('.c-msg.in audio', (a) => new Promise((r) => { if (a.readyState > 0) r(a.duration); else a.onloadedmetadata = () => r(a.duration); }));
check('voice message recorded, encrypted, decrypted and playable', dur > 0.5 || dur === Infinity, String(dur));
const webm = Buffer.from(await alice.evaluate(async () => {
  const c = document.createElement('canvas'); c.width = 320; c.height = 240; const g = c.getContext('2d');
  const rec = new MediaRecorder(c.captureStream(15), { mimeType: 'video/webm' }); const parts = []; rec.ondataavailable = (e) => parts.push(e.data);
  rec.start(); let i = 0; const t = setInterval(() => { g.fillStyle = `hsl(${(i += 20) % 360},80%,50%)`; g.fillRect(0, 0, 320, 240); }, 60);
  await new Promise((r) => setTimeout(r, 1500)); clearInterval(t); rec.stop(); await new Promise((r) => (rec.onstop = r));
  return [...new Uint8Array(await new Blob(parts).arrayBuffer())];
}));
await sendFiles(alice, [{ name: 'clip.webm', mimeType: 'video/webm', buffer: webm }]);
await bob.waitForSelector('.c-msg.in .c-play', { timeout: 60000 });
await bob.locator('.c-msg.in .c-media:has(.c-play)').last().click();
await bob.waitForSelector('dialog video.c-view', { timeout: T });
check('video sent in original quality, decrypted and opened in the player', true);
await bob.click('dialog >> text=بستن');

// ---------------------------------------------------------------- forwarding and access control
console.log('\n# Forwarding and access control');
await openWith(bob, 'کارول');
await openWith(bob, 'آلیس');
await menuOn(bob, 'report.bin', '↪ بازارسال');
await bob.click('dialog .c-item:has-text("کارول")');
await carol.waitForSelector('.c-item:has-text("باب")', { timeout: T });
await openWith(carol, 'باب');
await carol.waitForSelector('.c-msg.in:has-text("بازارسال‌شده"):has-text("report.bin")', { timeout: T });
const [dl3] = await Promise.all([carol.waitForEvent('download', { timeout: 60000 }), carol.locator('.c-msg.in .c-file', { hasText: 'report.bin' }).click()]);
check('forwarded file: Carol decrypts it (no re-upload; server grants her conversation access)', sha(readFileSync(await dl3.path())) === sha(big));
const attId = sql("SELECT public_id FROM attachments WHERE chunk_count >= 3 LIMIT 1");
const outsider = await carol.evaluate(async (id) => (await fetch(`../api/v1/attachments/${id}/blob`.replace('../', location.pathname), { credentials: 'same-origin' })).status, attId);
check('direct blob access works for granted members only (Carol: granted)', outsider === 200, String(outsider));
const img = sql("SELECT public_id FROM attachments WHERE chunk_count = 1 ORDER BY id LIMIT 1");
const denied = await carol.evaluate(async (id) => (await fetch(location.pathname + `api/v1/attachments/${id}/blob`, { credentials: 'same-origin' })).status, img);
check('Carol cannot download Alice→Bob files that were never sent to her', denied === 404, String(denied));

// ---------------------------------------------------------------- retention
console.log('\n# Retention (7 days)');
const key = sql(`SELECT storage_key FROM attachments WHERE public_id = '${img}'`);
const imgPath = `${ROOT}/storage/attachments/${key.slice(0, 2)}/${key.slice(2, 4)}/${key}`;
const otherFiles = readdirSync(`${ROOT}/storage/attachments`, { recursive: true }).filter((f) => statSync(`${ROOT}/storage/attachments/${f}`).isFile()).length;
sql(`UPDATE attachments SET expires_at = NOW(3) - INTERVAL 1 MINUTE WHERE public_id = '${img}'`);
const expired = await bob.evaluate(async (id) => (await fetch(location.pathname + `api/v1/attachments/${id}/blob`, { credentials: 'same-origin' })).status, img);
check('expired file is unavailable immediately (410), before physical deletion', expired === 410, String(expired));
await admin.goto(ADM + 'storage');
await admin.click('main form button');
await admin.waitForSelector('text=پاک‌سازی انجام شد', { timeout: T });
const after = readdirSync(`${ROOT}/storage/attachments`, { recursive: true }).filter((f) => statSync(`${ROOT}/storage/attachments/${f}`).isFile()).length;
let imgExists = true; try { statSync(imgPath); } catch { imgExists = false; }
check('admin «run cleanup now»: the expired ciphertext is deleted from disk, other files kept', sql(`SELECT upload_status FROM attachments WHERE public_id = '${img}'`) === 'deleted'
  && !imgExists && after === otherFiles - 1, `${otherFiles} → ${after}`);
check('storage page shows usage without any content', (await admin.textContent('main')).includes('فایل‌های روی سرور'));

// ---------------------------------------------------------------- plaintext scan
console.log('\n# Server never sees plaintext');
const dump = execFileSync('mysqldump', ['--skip-extended-insert', '-u', DBU, `-p${DBP}`, '-h', '127.0.0.1', DBN], { maxBuffer: 1 << 28 }).toString('latin1');
const words = ['FILE-CANARY', 'IMG-CANARY', 'اولین پیام آلیس', 'متن بعد از ویرایش', 'پاسخ باب', 'report.bin', 'photo.png', 'clip.webm'];
check('database dump contains no message text, reaction, file name or file content', words.every((w) => !dump.includes(w) && !dump.includes(Buffer.from(w).toString('latin1'))));
check('no uncaught JavaScript errors', errors.length === 0, errors.join(' | '));
await browser.close();
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
