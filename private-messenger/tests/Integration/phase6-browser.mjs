// Phase 6 acceptance test (Chromium): recovery key, linking a second device, restoring a third device,
// revoking a device, multi-device delivery and calls, and end-to-end encrypted groups.
//   node tests/Integration/phase6-browser.mjs https://pm.test /srv/pmhost/private-messenger DB USER PASS
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
const { chromium } = createRequire(import.meta.url)('playwright');

const [BASE, ROOT, DBN, DBU, DBP] = process.argv.slice(2);
const proxy = process.env.HTTPS_PROXY || process.env.https_proxy;
const browser = await chromium.launch({ ...(proxy ? { proxy: { server: proxy, bypass: new URL(BASE).hostname } } : {}),
  args: ['--ignore-certificate-errors', '--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream', '--disable-features=WebRtcHideLocalIpsWithMdns'] });
let pass = 0, fail = 0;
const check = (name, cond, extra = '') => { if (cond) { pass++; console.log('  \x1b[32m✔\x1b[0m', name); } else { fail++; console.log('  \x1b[31m✘', name, '\x1b[0m', extra); } };
const sql = (q) => execFileSync('mysql', ['-N', '-u', DBU, `-p${DBP}`, '-h', '127.0.0.1', DBN, '-e', q]).toString().trim();
const errors = [];
const newPage = async () => { const c = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1200, height: 820 }, permissions: ['microphone', 'camera'] });
  const p = await c.newPage(); p.on('pageerror', (e) => errors.push(e.message)); p.on('dialog', (d) => d.accept()); return p; };
const T = 30000;
// Devices now live under Settings; the list returns to «chats» underneath the (modal) dialog.
const openDevices = async (p) => { await p.click('.c-tab[data-tab=settings]'); await p.click('.c-devbtn'); await p.waitForSelector('dialog', { timeout: T });
  await p.evaluate(() => document.querySelector('.c-tab[data-tab=chats]').click()); };
const pages = {};
process.on('uncaughtException', async (e) => { console.log('FAIL:', e.message.split('\n')[0]);
  for (const [n, p] of Object.entries(pages)) { try { console.log(`--- ${n}:`, (await p.evaluate(() => document.body.innerText)).slice(-700)); } catch {} }
  console.log('errors:', errors.join(' | ')); process.exit(1); });
const send = async (p, t) => { await p.fill('.c-compose textarea', t); await p.press('.c-compose textarea', 'Enter'); };
const openChat = (p, name) => p.click(`.c-list .c-item:has-text("${name}")`).then(() => p.waitForSelector('.c-compose textarea', { timeout: T }));

// ---------------------------------------------------------------- setup
console.log('\n# Setup');
const admin = await newPage();
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
const temp = {}, pw = {};
for (const [u, n] of [['alice', 'آلیس'], ['bob', 'باب'], ['carol', 'کارول'], ['dave', 'داوود'], ['erin', 'ارین']]) {
  await admin.goto(ADM + 'users/new');
  await admin.fill('input[name=username]', u); await admin.fill('input[name=display_name]', n); await admin.check('input[name=activate]');
  await admin.click('main form button');
  temp[u] = (await admin.textContent('.secret')).trim(); pw[u] = u[0].toUpperCase() + u.slice(1) + ' strong pass 1';
}
sql(`INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES ('calls.stun', '""', NOW(3)) ON DUPLICATE KEY UPDATE setting_value = '""'`);
async function firstLogin(user) {
  const p = await newPage();
  await p.goto(APP);
  await p.fill('input[name=username]', user); await p.fill('input[name=password]', temp[user]); await p.click('button');
  await p.fill('input[name=current]', temp[user]); await p.fill('input[name=new]', pw[user]); await p.fill('input[name=confirm]', pw[user]); await p.click('form button');
  return p;
}
async function login(user) {
  const p = await newPage();
  await p.goto(APP);
  await p.fill('input[name=username]', user); await p.fill('input[name=password]', pw[user]); await p.click('button');
  return p;
}
async function confirmRecovery(p) {
  await p.waitForSelector('.c-rk', { timeout: T });
  const key = (await p.textContent('.c-rk')).trim();
  await p.fill('input[placeholder="چهار کاراکتر آخر"]', key.slice(-4)); await p.click('text=ذخیره کردم');
  await p.waitForSelector('.c-side-head h1', { timeout: T });
  return key;
}

// ---------------------------------------------------------------- recovery key
console.log('\n# Recovery key');
const a1 = await firstLogin('alice');
await a1.waitForSelector('.c-rk', { timeout: T });
const RK = (await a1.textContent('.c-rk')).trim();
check('recovery key shown on first use: 52 characters (256 bits), grouped', /^([0-9A-Z]{4}-){12}[0-9A-Z]{4}$/.test(RK), RK);
await a1.fill('input[placeholder="چهار کاراکتر آخر"]', 'ZZZZ'); await a1.click('text=ذخیره کردم');
check('wrong confirmation is refused', (await a1.textContent('[role=alert]')).includes('درست نیست'));
await a1.fill('input[placeholder="چهار کاراکتر آخر"]', RK.slice(-4)); await a1.click('text=ذخیره کردم');
await a1.waitForSelector('.c-side-head h1', { timeout: T });
check('confirmed → app opens', true);
check('server stores only an encrypted backup (no master key, no recovery key)',
  sql("SELECT COUNT(*) FROM key_backups") === '1' && !sql("SELECT HEX(ciphertext) FROM key_backups").includes(Buffer.from(RK).toString('hex').toUpperCase()));
pages.a1 = a1;
const bob = await firstLogin('bob'); pages.bob = bob; await confirmRecovery(bob);
const carol = await firstLogin('carol'); await confirmRecovery(carol);
const dave = await firstLogin('dave'); await confirmRecovery(dave);
await bob.click('[aria-label="گفتگوی جدید"]'); await bob.fill('dialog input[type=search]', 'آلیس'); await bob.click('dialog .c-user:has-text("آلیس") button:text-is("گفتگو")');
await send(bob, 'سلام آلیس از باب');
await a1.waitForSelector('.c-item:has-text("باب")', { timeout: T });

// ---------------------------------------------------------------- linking a second device
console.log('\n# Linking a second device');
const a2 = await login('alice'); pages.a2 = a2;
await a2.waitForSelector('text=حساب شما روی دستگاه دیگری فعال است', { timeout: T });
await a2.click('text=۱. اتصال با دستگاه قبلی');
await a2.waitForSelector('.c-code', { timeout: T });
const code2 = (await a2.textContent('.c-code')).trim();
check('new device shows an 8-digit verification code', /^\d{4} \d{4}$/.test(code2), code2);
await a1.waitForSelector('.c-toast:has-text("درخواست اتصال دستگاه جدید")', { timeout: T });
await openDevices(a1);
await a1.waitForSelector('dialog .c-code', { timeout: T });
const code1 = (await a1.textContent('dialog .c-code')).trim();
check('the existing device shows the SAME code (derived from the new device\'s keys)', code1 === code2, `${code1} / ${code2}`);
await a1.click('dialog >> text=کد یکسان است — تأیید');
await a2.waitForSelector('.c-side-head h1', { timeout: T });
check('second device linked: opens the app without a new identity', sql("SELECT COUNT(*) FROM user_master_keys mk JOIN users u ON u.id = mk.user_id WHERE u.username = 'alice'") === '1'
  && sql("SELECT COUNT(*) FROM devices d JOIN users u ON u.id = d.user_id WHERE u.username = 'alice' AND d.status = 'active'") === '2');
await openChat(bob, 'آلیس');
await send(bob, 'پیام برای هر دو دستگاه آلیس');
await a1.click('.c-list .c-item:has-text("باب")');
await a1.waitForSelector('.c-msg.in:has-text("پیام برای هر دو دستگاه آلیس")', { timeout: T });
await a2.waitForSelector('.c-item:has-text("باب")', { timeout: T }); await openChat(a2, 'باب');
await a2.waitForSelector('.c-msg.in:has-text("پیام برای هر دو دستگاه آلیس")', { timeout: T });
check('a message from Bob reaches BOTH of Alice\'s devices', true);
check('no key-change warning for Bob (same identity, new device)', !(await bob.$('text=پذیرفتن کلید جدید')));
await send(a2, 'پاسخ از دستگاه دوم');
await bob.waitForSelector('.c-msg.in:has-text("پاسخ از دستگاه دوم")', { timeout: T });
await a1.waitForSelector('.c-msg.out:has-text("پاسخ از دستگاه دوم")', { timeout: T });
check('a message sent on device 2 reaches Bob and appears as «sent» on device 1', true);

// ---------------------------------------------------------------- restore a third device with the recovery key
console.log('\n# Restore with the recovery key');
const a3 = await login('alice'); pages.a3 = a3;
await a3.waitForSelector('text=۲. بازیابی با کلید بازیابی', { timeout: T });
await a3.click('text=۲. بازیابی با کلید بازیابی');
await a3.fill('textarea', 'ABCD-'.repeat(12) + 'ABCD'); await a3.click('button:text-is("بازیابی")');
await a3.waitForSelector('[role=alert]:has-text("کلید بازیابی درست نیست")', { timeout: T });
check('a wrong recovery key is refused', true);
await a3.fill('textarea', RK.toLowerCase().replace(/-/g, ' ')); await a3.click('button:text-is("بازیابی")');
await a3.waitForSelector('.c-side-head h1', { timeout: T });
check('the right key (any case, spaces instead of dashes) restores the identity on device 3', sql("SELECT COUNT(*) FROM devices d JOIN users u ON u.id = d.user_id WHERE u.username = 'alice' AND d.status = 'active'") === '3');
await send(bob, 'سلام به هر سه');
await a3.waitForSelector('.c-item:has-text("باب")', { timeout: T }); await openChat(a3, 'باب');
await a3.waitForSelector('.c-msg.in:has-text("سلام به هر سه")', { timeout: T });
check('the restored device receives new messages', true);
await openDevices(a1); await a1.waitForSelector('dialog .c-item', { timeout: T });
check('devices dialog lists 3 devices', (await a1.locator('dialog .c-item').count()) === 3);
await a1.locator('dialog .c-item').last().locator('text=خروج').click();
await a1.waitForSelector('.c-toast:has-text("دستگاه جدا شد")', { timeout: T });
await a3.reload();
await a3.waitForSelector('text=دیگر معتبر نیستند', { timeout: T });
check('revoked device is locked out', sql("SELECT COUNT(*) FROM devices d JOIN users u ON u.id = d.user_id WHERE u.username = 'alice' AND d.status = 'active'") === '2');

// ---------------------------------------------------------------- multi-device call
console.log('\n# Call rings on all devices, stops on the others when answered');
await bob.click('[aria-label="تماس صوتی"]');
await Promise.all([a1.waitForSelector('.cl-overlay >> text=تماس ورودی', { timeout: T }), a2.waitForSelector('.cl-overlay >> text=تماس ورودی', { timeout: T })]);
check('both of Alice\'s devices ring', true);
await a1.click('.cl-overlay [aria-label="پاسخ"]');
await a2.waitForSelector('.c-toast:has-text("روی دستگاه دیگرتان پاسخ داده شد")', { timeout: T });
await bob.waitForSelector('.cl-status:has-text("متصل")', { timeout: T });
check('answered on device 1 → device 2 stops ringing; call connected', !(await a2.$('.cl-overlay')));
await bob.click('.cl-overlay [aria-label="پایان تماس"]');
await a1.waitForFunction(() => !document.querySelector('.cl-overlay'), null, { timeout: T });

// ---------------------------------------------------------------- groups
console.log('\n# End-to-end encrypted groups');
const GNAME = 'تیم GROUP-CANARY';
await a1.click('[aria-label="گفتگوی جدید"]'); await a1.click('dialog >> text=گروه جدید');
await a1.fill('dialog input[placeholder="نام گروه"]', GNAME);
for (const n of ['باب', 'کارول']) { await a1.fill('dialog input[type=search]', n); await a1.click(`dialog .c-user:has-text("${n}") >> text=افزودن`); }
await a1.click('dialog >> text=ساخت گروه');
await a1.waitForSelector(`.c-head-name:has-text("${GNAME}")`, { timeout: T });
check('group created', true);
await send(a1, 'سلام تیم GMSG-1');
for (const p of [bob, carol]) {
  await p.waitForSelector(`.c-item:has-text("${GNAME}")`, { timeout: T }); await openChat(p, GNAME);
  await p.waitForSelector('.c-msg.in:has-text("سلام تیم GMSG-1")', { timeout: T });
}
check('members get the group name (from the encrypted roster) and the message', true);
check('sender name shown on group messages', (await carol.textContent('.c-msg.in:has-text("GMSG-1") .c-sender')) === 'آلیس');
await send(carol, 'پاسخ کارول GMSG-2');
await bob.waitForSelector('.c-msg.in:has-text("GMSG-2") .c-sender:text("کارول")', { timeout: T });
await a1.waitForSelector('.c-msg.in:has-text("GMSG-2")', { timeout: T });
await a2.waitForSelector(`.c-item:has-text("${GNAME}")`, { timeout: T });
check('a member\'s reply reaches everyone (and Alice\'s second device)', true);
const gid = sql("SELECT public_id FROM conversations WHERE type = 'group'");
const daveId = sql("SELECT public_id FROM users WHERE username = 'dave'");
const notAdmin = await bob.evaluate(async ([g, u]) => (await fetch(location.pathname + `api/v1/groups/${g}/members`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-PM-Request': '1' }, body: JSON.stringify({ user_id: u }) })).status, [gid, daveId]);
check('a non-admin cannot add members (403)', notAdmin === 403, String(notAdmin));
await a1.click('.c-lock'); await a1.waitForSelector('dialog .c-dir', { timeout: T });
await a1.click('dialog summary:has-text("افزودن عضو")'); await a1.fill('dialog details input[type=search]', 'داوود');
await a1.click('dialog details .c-user:has-text("داوود") >> text=افزودن');
await a1.waitForSelector('.c-toast:has-text("اضافه شد")', { timeout: T }); await a1.click('dialog >> text=بستن');
await dave.waitForSelector(`.c-item:has-text("${GNAME}")`, { timeout: T }); await openChat(dave, GNAME);
await dave.waitForSelector('.c-sys:has-text("اضافه شدید")', { timeout: T });
check('new member gets the group (name from the admin\'s encrypted roster)', true);
await send(a1, 'پیام پس از ورود داوود GMSG-3');
await dave.waitForSelector('.c-msg.in:has-text("GMSG-3")', { timeout: T });
check('new member reads new messages but NOT earlier ones', !(await dave.textContent('.c-msgs')).includes('GMSG-1'));
await a1.click('.c-lock'); await a1.waitForSelector('dialog .c-dir', { timeout: T });
await a1.locator('dialog .c-item', { hasText: 'کارول' }).locator('text=حذف').click();
await a1.waitForSelector('dialog[open] .c-dir:not(:has-text("کارول"))', { timeout: T }); await a1.click('dialog[open] >> text=بستن');
check('removal is announced in the group', (await a1.textContent('.c-msgs')).includes('عضو را از گروه حذف کردید'));
await send(a1, 'بعد از حذف کارول GMSG-4');
await bob.waitForSelector('.c-msg.in:has-text("GMSG-4")', { timeout: T });
await carol.waitForTimeout(6000);
check('removed member does NOT receive later messages', !(await carol.textContent('body')).includes('GMSG-4'));
const carolDev = sql("SELECT d.id FROM devices d JOIN users u ON u.id = d.user_id WHERE u.username = 'carol' AND d.status = 'active'");
const m4 = sql("SELECT MAX(id) FROM messages");
check('…and the server holds no envelope for her (never encrypted for her)', sql(`SELECT COUNT(*) FROM message_envelopes WHERE message_id = ${m4} AND recipient_device_id = ${carolDev}`) === '0');
// a member added by the SERVER only (no encrypted announcement by an admin)
const erin = await firstLogin('erin'); await confirmRecovery(erin);
const erinDev = sql("SELECT d.id FROM devices d JOIN users u ON u.id = d.user_id WHERE u.username = 'erin' AND d.status = 'active'");
await erin.close();
sql(`INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) SELECT c.id, u.id, 'member', NOW(3) FROM conversations c, users u WHERE c.type = 'group' AND u.username = 'erin'`);
await a1.waitForTimeout(31000); // member cache expires
await send(a1, 'پیام پنجم GMSG-5');
await bob.waitForSelector('.c-msg.in:has-text("GMSG-5")', { timeout: T });
const m5 = sql("SELECT MAX(id) FROM messages");
check('a member the server adds secretly receives NOTHING (clients only trust the encrypted roster)', sql(`SELECT COUNT(*) FROM message_envelopes WHERE message_id = ${m5} AND recipient_device_id = ${erinDev}`) === '0');
await a1.click('.c-lock'); await a1.waitForSelector('dialog .c-dir', { timeout: T });
check('…and the group info warns about her', (await a1.textContent('dialog')).includes('عضویت او توسط هیچ مدیری'));
await a1.click('dialog >> text=بستن');

console.log('\n# Server never sees plaintext');
const dump = execFileSync('mysqldump', ['--skip-extended-insert', '-u', DBU, `-p${DBP}`, '-h', '127.0.0.1', DBN], { maxBuffer: 1 << 28 }).toString('latin1');
check('database contains no group name, no group or direct message text', !['GROUP-CANARY', 'GMSG-', 'پیام برای هر دو', 'سلام به هر سه'].some((w) => dump.includes(w) || dump.includes(Buffer.from(w).toString('latin1'))));
check('no uncaught JavaScript errors', errors.length === 0, errors.join(' | '));
await browser.close();
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
