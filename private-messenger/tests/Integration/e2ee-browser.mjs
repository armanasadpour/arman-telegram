// Phase 3 acceptance test in a real browser (Chromium via Playwright) against a real server.
//   node tests/Integration/e2ee-browser.mjs https://pm.test /srv/pmhost/private-messenger DB USER PASS
// Target: FRESH (not installed) deployment + EMPTY database. Proves that messages are encrypted on the
// sender's device, decrypted on the recipient's device, and that plaintext never reaches the server.
import { createRequire } from 'node:module';
const { chromium } = createRequire(import.meta.url)('playwright');
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

const [BASE, ROOT, DBN, DBU, DBP] = process.argv.slice(2);
const proxy = process.env.HTTPS_PROXY || process.env.https_proxy;
const browser = await chromium.launch(proxy ? { proxy: { server: proxy, bypass: new URL(BASE).hostname } } : {});
let pass = 0, fail = 0;
const check = (name, cond, extra = '') => { if (cond) { pass++; console.log('  \x1b[32m✔\x1b[0m', name); } else { fail++; console.log('  \x1b[31m✘', name, '\x1b[0m', extra); } };
const ctx = async () => (await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1200, height: 800 } }));
const errors = [];
const page = async (c) => { const p = await c.newPage(); p.on('pageerror', (e) => errors.push(e.message)); return p; };
const sql = (q) => execFileSync('mysql', ['-N', '-u', DBU, `-p${DBP}`, '-h', '127.0.0.1', DBN, '-e', q]).toString().trim();

// ---------------------------------------------------------------- install + users
console.log('\n# Setup');
const admin = await page(await ctx());
await admin.goto(`${BASE}/install/`);
await admin.fill('input[name=token]', readFileSync(`${ROOT}/storage/install-token.txt`, 'utf8').trim());
await admin.click('button');
await admin.click('summary');
await admin.check('input[name=db_mode][value=manual]');
await admin.fill('input[name=db_name]', DBN); await admin.fill('input[name=db_user]', DBU); await admin.fill('input[name=db_pass]', DBP);
await admin.fill('input[name=db_host]', '127.0.0.1');
await admin.fill('input[name=admin_password]', 'Admin strong pass 123'); await admin.fill('input[name=admin_password2]', 'Admin strong pass 123');
await admin.click('form button');
const urls = [...new Set((await admin.content()).match(/https:\/\/[^/"<]+\/[A-Za-z0-9]{16}\//g))];
const [ADM, APP] = urls;
check('installed', !!ADM && !!APP);
await admin.goto(ADM);
await admin.fill('input[name=username]', 'admin'); await admin.fill('input[name=password]', 'Admin strong pass 123'); await admin.click('button');
const temp = {};
for (const [u, n] of [['alice', 'آلیس'], ['bob', 'باب']]) {
  await admin.goto(ADM + 'users/new');
  await admin.fill('input[name=username]', u); await admin.fill('input[name=display_name]', n); await admin.check('input[name=activate]');
  await admin.click('main form button');
  temp[u] = (await admin.textContent('.secret')).trim();
}
check('two users created', temp.alice?.length === 16 && temp.bob?.length === 16);

async function login(c, user, pw, newPw) {
  const p = await page(c);
  await p.goto(APP);
  await p.fill('input[name=username]', user); await p.fill('input[name=password]', pw); await p.click('button');
  if (newPw) {
    await p.fill('input[name=current]', pw); await p.fill('input[name=new]', newPw); await p.fill('input[name=confirm]', newPw);
    await p.click('form button');
  }
  return p;
}
const ready = (p) => p.waitForSelector('.c-side-head h1', { timeout: 20000 });

// ---------------------------------------------------------------- keys created on device
console.log('\n# Keys are generated on the device');
const aCtx = await ctx(), bCtx = await ctx();
const alice = await login(aCtx, 'alice', temp.alice, 'Alice strong pass 1');
await ready(alice);
const bob = await login(bCtx, 'bob', temp.bob, 'Bob strong pass 12');
await ready(bob);
check('both users have a master key and one active device on the server', sql("SELECT COUNT(*) FROM user_master_keys WHERE superseded_at IS NULL") === '2'
  && sql("SELECT COUNT(*) FROM devices WHERE status='active'") === '2');
check('50 one-time keys published per device', sql("SELECT MIN(c) FROM (SELECT COUNT(*) c FROM device_public_keys WHERE key_type='one_time' GROUP BY device_id) x") === '50');
const devCols = sql(`SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
  AND table_name IN ('devices','device_public_keys','user_master_keys','key_backups','users') AND column_name REGEXP 'secret|private'`);
check('no column for private keys exists in the key tables', devCols === '0', devCols);
const keyBytes = sql("SELECT MAX(LENGTH(public_key)) FROM device_public_keys") + '/' + sql("SELECT MAX(LENGTH(public_key)) FROM user_master_keys");
check('server stores only 32-byte public keys', keyBytes === '32/32', keyBytes);

// ---------------------------------------------------------------- messaging
console.log('\n# Messaging');
const CANARY1 = 'سلام باب CANARY-ALICE-7f3a', CANARY2 = 'سلام آلیس CANARY-BOB-91c2';
await alice.click('text=گفتگوی جدید');
await alice.fill('dialog input[type=search]', 'باب'); await alice.click('dialog .c-user:has-text("باب") button:text-is("گفتگو")');
await alice.fill('.c-compose textarea', CANARY1);
await alice.press('.c-compose textarea', 'Enter');
await alice.waitForSelector('.c-msg.out .st:text("✓")', { timeout: 15000 });
check('Alice sent (✓)', true);
await bob.waitForSelector('.c-item:has-text("آلیس")', { timeout: 20000 });
await bob.click('.c-item:has-text("آلیس")');
await bob.waitForSelector(`.c-msg.in:has-text("${CANARY1}")`, { timeout: 15000 });
check('Bob decrypted Alice\'s message on his device', true);
await alice.waitForSelector('.c-msg.out .st:text("✓✓")', { timeout: 20000 });
check('Alice sees delivered (✓✓) after Bob\'s device acknowledged', true);
await bob.fill('.c-compose textarea', CANARY2); await bob.press('.c-compose textarea', 'Enter');
await alice.waitForSelector(`.c-msg.in:has-text("${CANARY2}")`, { timeout: 20000 });
check('Alice decrypted Bob\'s reply (ratchet turned)', true);
check('envelopes deleted from server after delivery', sql('SELECT COUNT(*) FROM message_envelopes') === '0');

// ---------------------------------------------------------------- safety numbers
console.log('\n# Identity verification');
const safetyOf = async (p) => { await p.click('.c-lock'); await p.waitForSelector('dialog .c-safety span'); const t = (await p.$$eval('.c-safety span', (s) => s.map((x) => x.textContent))).join(''); await p.click('dialog >> text=بستن'); return t; };
const sa = await safetyOf(alice), sb = await safetyOf(bob);
check('safety numbers are 60 digits and identical on both devices', /^\d{60}$/.test(sa) && sa === sb, `${sa} / ${sb}`);

// ---------------------------------------------------------------- plaintext never on the server
console.log('\n# Server never sees plaintext');
const dump = execFileSync('mysqldump', ['--skip-extended-insert', '--hex-blob', '-u', DBU, `-p${DBP}`, '-h', '127.0.0.1', DBN]).toString();
const dumpRaw = execFileSync('mysqldump', ['--skip-extended-insert', '-u', DBU, `-p${DBP}`, '-h', '127.0.0.1', DBN]).toString('latin1');
check('database dump (text and hex) contains no message plaintext', !dump.includes('CANARY') && !dumpRaw.includes('CANARY') && !dump.includes(Buffer.from('CANARY').toString('hex').toUpperCase()));
const grepFiles = (dir) => { let hit = false; for (const f of readdirSync(dir)) { const p = `${dir}/${f}`; if (statSync(p).isDirectory()) hit ||= grepFiles(p); else if (readFileSync(p).includes('CANARY')) hit = true; } return hit; };
check('server files, logs and storage contain no plaintext', !grepFiles(`${ROOT}/storage`) && !grepFiles(`${ROOT}/config`));
check('web server logs contain no plaintext', !readFileSync('/var/log/apache2/pm-access.log', 'latin1').includes('CANARY') && !readFileSync('/var/log/apache2/pm-error.log', 'latin1').includes('CANARY'));

// ---------------------------------------------------------------- tampering by the server
console.log('\n# Tampering');
await bob.close();
await alice.fill('.c-compose textarea', 'tamper target'); await alice.press('.c-compose textarea', 'Enter');
await alice.waitForSelector('.c-msg.out:has-text("tamper target") .st:text("✓")', { timeout: 15000 });
sql("UPDATE message_envelopes SET ciphertext = CONCAT(LEFT(ciphertext, LENGTH(ciphertext) - 4), 'XXXX')");
const bob2 = await page(bCtx); await bob2.goto(APP); await ready(bob2);
await bob2.click('.c-item:has-text("آلیس")');
await bob2.waitForSelector('.c-sys:has-text("قابل رمزگشایی نبود")', { timeout: 20000 });
check('ciphertext modified on the server → rejected by the recipient (not shown as a message)', !(await bob2.content()).includes('tamper target'));
await alice.fill('.c-compose textarea', 'after tamper ok'); await alice.press('.c-compose textarea', 'Enter');
await bob2.waitForSelector('.c-msg.in:has-text("after tamper ok")', { timeout: 20000 });
check('session continues after a rejected message', true);

// ---------------------------------------------------------------- persistence, one tab, re-login binding
console.log('\n# Local storage and devices');
await alice.reload(); await ready(alice);
await alice.click('.c-item:has-text("باب")');
check('history survives reload (decrypted locally from encrypted IndexedDB)', !!(await alice.waitForSelector(`.c-msg.in:has-text("${CANARY2}")`, { timeout: 10000 })));
const tab2 = await page(aCtx); await tab2.goto(APP);
check('second tab is refused (one ratchet writer at a time)', !!(await tab2.waitForSelector('text=در یک تب یا پنجره دیگر باز است', { timeout: 15000 })));
await tab2.close();
await alice.click('text=خروج');
await alice.fill('input[name=username]', 'alice'); await alice.fill('input[name=password]', 'Alice strong pass 1'); await alice.click('button');
await ready(alice);
check('after logout/login the browser re-binds to its device with a signed challenge', sql("SELECT COUNT(*) FROM devices WHERE status='active'") === '2');
await alice.click('.c-item:has-text("باب")');
await alice.fill('.c-compose textarea', 'after re-login'); await alice.press('.c-compose textarea', 'Enter');
await bob2.waitForSelector('.c-msg.in:has-text("after re-login")', { timeout: 20000 });
check('messaging works after re-login', true);

// ---------------------------------------------------------------- key change warning
console.log('\n# Key change');
const b3 = await login(await ctx(), 'bob', 'Bob strong pass 12');
await b3.waitForSelector('text=قبلاً روی دستگاه یا مرورگر دیگری فعال شده است', { timeout: 20000 });
check('new browser for an existing account: explains instead of silently creating keys', true);
b3.on('dialog', (d) => d.accept());
await b3.click('text=ساخت کلیدهای جدید روی این دستگاه');
await ready(b3);
check('identity reset creates a new master key', sql('SELECT COUNT(*) FROM user_master_keys WHERE superseded_at IS NOT NULL') === '1');
await alice.waitForTimeout(4000);
await alice.reload(); await ready(alice);
await alice.click('.c-item:has-text("باب")');
await alice.waitForSelector('text=کلید امنیتی باب تغییر کرده است', { timeout: 20000 });
check('Alice is warned that Bob\'s key changed and sending is blocked', await alice.isDisabled('.c-compose textarea'));
await alice.click('text=پذیرفتن کلید جدید');
await alice.fill('.c-compose textarea', 'to the new key'); await alice.press('.c-compose textarea', 'Enter');
await b3.click('.c-item:has-text("آلیس")');
await b3.waitForSelector('.c-msg.in:has-text("to the new key")', { timeout: 20000 });
check('after accepting, messages go to the new device only', true);
await bob2.reload();
check('old device of Bob is told its keys are no longer valid', !!(await bob2.waitForSelector('text=دیگر معتبر نیستند', { timeout: 20000 })));

check('no uncaught JavaScript errors', errors.length === 0, errors.join(' | '));
await browser.close();
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
