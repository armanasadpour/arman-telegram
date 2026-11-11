// Moderation acceptance test (Chromium): service switches (global / user / group), group & channel admin view
// without content, bans, timed suspension + appeal, abuse reports, the support chat, call push and web cron.
//   node tests/Integration/moderation-browser.mjs https://pm.test /srv/pmhost/private-messenger DB USER PASS
import { createRequire } from 'node:module';
import { readFileSync, appendFileSync, chmodSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import https from 'node:https';
import { decrypt, newSubscriber } from '../tools/webpush-check.mjs';
const { chromium } = createRequire(import.meta.url)('playwright');

const [BASE, ROOT, DBN, DBU, DBP] = process.argv.slice(2);
const proxy = process.env.HTTPS_PROXY || process.env.https_proxy;
const browser = await chromium.launch({ ...(proxy ? { proxy: { server: proxy, bypass: new URL(BASE).hostname } } : {}),
  args: ['--ignore-certificate-errors', '--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream'] });
let pass = 0, fail = 0;
const check = (name, cond, extra = '') => { if (cond) { pass++; console.log('  \x1b[32m✔\x1b[0m', name); } else { fail++; console.log('  \x1b[31m✘', name, '\x1b[0m', extra); } };
const sql = (q) => execFileSync('mysql', ['-N', '-u', DBU, `-p${DBP}`, '-h', '127.0.0.1', DBN, '-e', q]).toString().trim();
const errors = [];
const T = 30000;
const ctx = async () => { const c = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 860 }, permissions: ['microphone', 'camera'] });
  const p = await c.newPage(); p.on('pageerror', (e) => errors.push(e.message)); p.on('dialog', (d) => d.accept()); return p; };
const send = async (p, t) => { await p.fill('.c-compose textarea', t); await p.press('.c-compose textarea', 'Enter'); };
const openChat = (p, name) => p.click(`.c-list .c-item:has-text("${name}")`).then(() => p.waitForSelector('.c-compose', { timeout: T }));
const api = (p, method, path, body) => p.evaluate(async ([m, path, body]) => { const r = await fetch(location.pathname + 'api/v1/' + path, { method: m, headers: { 'Content-Type': 'application/json', 'X-PM-Request': '1' }, body: body ? JSON.stringify(body) : undefined });
  return [r.status, await r.json().catch(() => null)]; }, [method, path, body]);
process.on('uncaughtException', (e) => { console.log('FAIL:', e.message.split('\n').slice(0, 3).join(' ')); process.exit(1); });

// mock push service
const dir = `${ROOT}/..`;
execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', `${dir}/push-key.pem`, '-out', `${dir}/push-ca.pem`, '-days', '1', '-subj', '/CN=push.test', '-addext', 'subjectAltName=DNS:push.test'], { stdio: 'ignore' });
chmodSync(`${dir}/push-ca.pem`, 0o644);
const pushes = [];
const server = https.createServer({ key: readFileSync(`${dir}/push-key.pem`), cert: readFileSync(`${dir}/push-ca.pem`) }, (req, res) => {
  const ch = []; req.on('data', (c) => ch.push(c)); req.on('end', () => { pushes.push({ path: req.url, headers: req.headers, body: Buffer.concat(ch) }); res.writeHead(201); res.end(); });
}).listen(9443, '127.0.0.1');

// ---------------------------------------------------------------- setup
console.log('\n# Setup');
const admin = await ctx();
await admin.goto(`${BASE}/install/`);
await admin.fill('input[name=token]', readFileSync(`${ROOT}/storage/install-token.txt`, 'utf8').trim()); await admin.click('button');
await admin.click('summary'); await admin.check('input[name=db_mode][value=manual]');
await admin.fill('input[name=db_name]', DBN); await admin.fill('input[name=db_user]', DBU); await admin.fill('input[name=db_pass]', DBP); await admin.fill('input[name=db_host]', '127.0.0.1');
await admin.fill('input[name=admin_password]', 'Admin strong pass 123'); await admin.fill('input[name=admin_password2]', 'Admin strong pass 123');
await admin.click('form button');
await admin.waitForSelector('text=نصب کامل شد', { timeout: 60000 });
const [ADM, APP] = [...new Set((await admin.content()).match(/https:\/\/[^/"<]+\/[A-Za-z0-9]{16}\//g))];
appendFileSync(`${ROOT}/config/.env`, `PUSH_EXTRA_HOSTS="push.test"\nPUSH_CAINFO="${dir}/push-ca.pem"\n`);
await admin.goto(ADM); await admin.fill('input[name=username]', 'admin'); await admin.fill('input[name=password]', 'Admin strong pass 123'); await admin.click('button');
const temp = {}, uid = {};
for (const [u, n] of [['alice', 'آلیس'], ['bob', 'باب'], ['carol', 'کارول']]) {
  await admin.goto(ADM + 'users/new'); await admin.fill('input[name=username]', u); await admin.fill('input[name=display_name]', n); await admin.check('input[name=activate]');
  await admin.click('main form button'); temp[u] = (await admin.textContent('.secret')).trim(); uid[u] = sql(`SELECT id FROM users WHERE username = '${u}'`);
}
async function first(u) {
  const p = await ctx(); await p.goto(APP);
  await p.fill('input[name=username]', u); await p.fill('input[name=password]', temp[u]); await p.click('button');
  await p.fill('input[name=current]', temp[u]); await p.fill('input[name=new]', 'Strong pass 12345'); await p.fill('input[name=confirm]', 'Strong pass 12345'); await p.click('form button');
  await p.waitForSelector('.c-rk', { timeout: T });
  await p.fill('input[placeholder="چهار کاراکتر آخر"]', (await p.textContent('.c-rk')).trim().slice(-4)); await p.click('text=ذخیره کردم');
  await p.waitForSelector('.c-side-head h1', { timeout: T });
  return p;
}
const alice = await first('alice'), bob = await first('bob'), carol = await first('carol');
await alice.click('[aria-label="گفتگوی جدید"]'); await alice.fill('dialog input[type=search]', 'باب'); await alice.click('dialog .c-user:has-text("باب") button:text-is("گفتگو")');
await send(alice, 'سلام باب');
await bob.waitForSelector('.c-item:has-text("آلیس")', { timeout: T });
await alice.click('[aria-label="گفتگوی جدید"]'); await alice.click('dialog >> text=گروه جدید');
await alice.fill('dialog input[placeholder="نام گروه"]', 'گروه MOD-CANARY');
await alice.fill('dialog input[type=search]', 'باب'); await alice.click('dialog .c-user:has-text("باب") >> text=افزودن');
await alice.click('dialog >> text=ساخت گروه'); await alice.waitForSelector('.c-head-name:has-text("MOD-CANARY")', { timeout: T });
await send(alice, 'پیام گروه GMOD-1');
await bob.waitForSelector('.c-item:has-text("MOD-CANARY")', { timeout: T });
const gid = sql("SELECT id FROM conversations WHERE type = 'group'"), gpub = sql("SELECT public_id FROM conversations WHERE type = 'group'");
check('setup: direct chat and a group', !!gid);

// ---------------------------------------------------------------- service switches
console.log('\n# Service switches');
await admin.goto(ADM + 'services');
await admin.check('main form input[name="off[]"][value=image]'); await admin.check('main form input[name="off[]"][value=create_channel]');
await admin.click('main form button:text("ذخیره")');
check('admin disables «send photo» and «create channel» for everyone', sql("SELECT GROUP_CONCAT(service ORDER BY service) FROM service_policies WHERE scope = 'global'") === 'create_channel,image');
await bob.reload(); await bob.waitForSelector('.c-side-head h1', { timeout: T });
await openChat(bob, 'آلیس'); await bob.click('.c-attach');
const tiles = await bob.locator('.c-sheet .tile').allTextContents();
check('app: gallery offers videos only, the notice explains', tiles.join(',').includes('گالری') && (await bob.textContent('.c-sheet')).includes('غیرفعال'));
await bob.mouse.click(5, 5);
const conv = sql(`SELECT c.public_id FROM conversations c WHERE c.type = 'direct'`);
const forgedImg = await api(bob, 'POST', 'messages', { conversation_id: conv, client_msg_id: 'BBBBBBBBBBBBBBBBBBBBBB', protocol_version: 1, envelopes: [{ device_id: 'x', type: 1, ciphertext: 'AA' }], kind: 'image', notify: true });
check('server refuses a message declared as image (403 service_disabled)', forgedImg[0] === 403 && forgedImg[1].error.service === 'image', JSON.stringify(forgedImg));
await bob.click('[aria-label="گفتگوی جدید"]');
check('«new channel» button hidden, «new group» still there', !(await bob.isVisible('dialog >> text=کانال جدید')) && await bob.isVisible('dialog >> text=گروه جدید'));
await bob.click('dialog >> text=بستن');
check('server refuses channel creation', (await api(bob, 'POST', 'groups', { members: [sql("SELECT public_id FROM users WHERE username='alice'")], channel: true }))[0] === 403);
await admin.goto(ADM + 'services'); await admin.uncheck('main form input[name="off[]"][value=image]'); await admin.uncheck('main form input[name="off[]"][value=create_channel]');
await admin.click('main form button:text("ذخیره")');
// per-user: bob may not make voice calls (for 1 day)
await admin.goto(ADM + 'users/' + uid.bob);
await admin.check('form[action$="/services"] input[value=voice_call]'); await admin.selectOption('form[action$="/services"] select[name=duration]', '1d');
await admin.click('form[action$="/services"] button');
await bob.reload(); await bob.waitForSelector('.c-side-head h1', { timeout: T }); await openChat(bob, 'آلیس');
check('per-user switch: bob has no voice-call button, still a video-call button', !(await bob.$('[aria-label="تماس صوتی"]')) && !!(await bob.$('[aria-label="تماس تصویری"]')));
check('server refuses bob\'s voice call; alice cannot voice-call him either', (await api(bob, 'POST', 'calls', { conversation_id: conv }))[0] === 403 && (await api(alice, 'POST', 'calls', { conversation_id: conv }))[0] === 403);
check('timed: the switch has an end time', sql(`SELECT until_at IS NOT NULL FROM service_policies WHERE scope = 'user' AND scope_id = ${uid.bob}`) === '1');
// per-group: no messages in this group
await admin.goto(ADM + 'conversations/' + gid);
await admin.check('form[action$="/services"] input[value=group_message]'); await admin.click('form[action$="/services"] button');
await bob.click('[aria-label="بازگشت"]').catch(() => {}); await openChat(bob, 'MOD-CANARY');
check('per-group switch: composer replaced by an explanation', (await bob.textContent('.c-compose')).includes('غیرفعال'));
await admin.uncheck('form[action$="/services"] input[value=group_message]'); await admin.click('form[action$="/services"] button');

// ---------------------------------------------------------------- groups & channels admin view, ban
console.log('\n# Groups and channels in the admin panel');
await admin.goto(ADM + 'conversations');
const listText = await admin.textContent('main');
check('admin lists groups with creator, members and counts', listText.includes('@alice') && listText.includes('گروه'));
await admin.goto(ADM + 'conversations/' + gid);
const detail = await admin.content();
check('detail page shows members and metadata, never the name or messages', detail.includes('@bob') && !detail.includes('MOD-CANARY') && !detail.includes('GMOD-1'));
await admin.selectOption('form[action$="/ban"] select[name=duration]', '1d'); await admin.fill('form[action$="/ban"] input[name=reason]', 'تبلیغات');
await admin.click('form[action$="/ban"] button');
check('group banned with a reason and end time', sql(`SELECT CONCAT(status, '|', ban_reason IS NOT NULL, '|', banned_until IS NOT NULL) FROM conversations WHERE id = ${gid}`) === 'banned|1|1');
await bob.reload(); await bob.waitForSelector('.c-side-head h1', { timeout: T });
check('members see «banned» in the list', await bob.isVisible('.c-item:has-text("MOD-CANARY") .tag.fail'));
await openChat(bob, 'MOD-CANARY');
check('…and a banned bar instead of the composer', (await bob.textContent('.c-compose.ro')).includes('مسدود'));
const gsend = await api(alice, 'POST', 'messages', { conversation_id: gpub, client_msg_id: 'CCCCCCCCCCCCCCCCCCCCCC', protocol_version: 1, envelopes: [{ device_id: 'x', type: 1, ciphertext: 'AA' }], kind: 'text' });
check('server refuses messages to a banned group', gsend[1]?.error?.code === 'conversation_banned', JSON.stringify(gsend));
await admin.click('form[action$="/unban"] button');
check('unban', sql(`SELECT status FROM conversations WHERE id = ${gid}`) === 'active');

// ---------------------------------------------------------------- suspension + appeal
console.log('\n# Timed suspension and appeal');
await admin.goto(ADM + 'users/' + uid.bob);
await admin.selectOption('form[action$="/status"].grid2 select[name=status]', 'suspended');
await admin.selectOption('form[action$="/status"].grid2 select[name=duration]', '7d');
await admin.fill('form[action$="/status"].grid2 input[name=reason]', 'ارسال پیام‌های ناخواسته');
await admin.click('form[action$="/status"].grid2 button');
check('bob suspended for 7 days with a reason; his sessions end', sql(`SELECT CONCAT(status, '|', status_until IS NOT NULL) FROM users WHERE id = ${uid.bob}`) === 'suspended|1');
const b2 = await ctx(); await b2.goto(APP);
await b2.fill('input[name=username]', 'bob'); await b2.fill('input[name=password]', 'Strong pass 12345'); await b2.click('button');
await b2.waitForSelector('text=حساب شما تعلیق شده است', { timeout: T });
check('login shows why and until when', (await b2.textContent('main')).includes('ارسال پیام‌های ناخواسته'));
const wrong = await ctx(); await wrong.goto(APP);
await wrong.fill('input[name=username]', 'bob'); await wrong.fill('input[name=password]', 'wrong password!'); await wrong.click('button');
check('with a wrong password nothing about the suspension is revealed', !(await wrong.textContent('main')).includes('تعلیق'));
await b2.fill('textarea[name=message]', 'اشتباه شده؛ لطفاً حساب من را بررسی کنید.');
await b2.click('text=ارسال درخواست بازبینی');
await b2.waitForSelector('text=درخواست بازبینی شما ثبت شد', { timeout: T });
check('appeal stored', sql(`SELECT COUNT(*) FROM user_appeals WHERE user_id = ${uid.bob} AND status = 'open'`) === '1');
await admin.goto(ADM);
check('dashboard warns about the open appeal', (await admin.textContent('main')).includes('درخواست بازبینی'));
await admin.goto(ADM + 'appeals');
await admin.fill('input[name=reply]', 'بررسی شد، حساب فعال شد.'); await admin.click('text=پذیرفتن و فعال‌سازی حساب');
check('accepting the appeal re-activates the account', sql(`SELECT status FROM users WHERE id = ${uid.bob}`) === 'active');
await b2.goto(APP); await b2.fill('input[name=username]', 'bob'); await b2.fill('input[name=password]', 'Strong pass 12345'); await b2.click('button');
await b2.waitForSelector('text=حساب شما روی دستگاه دیگری فعال است', { timeout: T });
check('bob can sign in again', true);
await b2.context().close();
sql(`UPDATE users SET status = 'blocked', status_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE), status_reason = 'x' WHERE id = ${uid.bob}`);
const b3 = await ctx(); await b3.goto(APP); await b3.fill('input[name=username]', 'bob'); await b3.fill('input[name=password]', 'Strong pass 12345'); await b3.click('button');
await b3.waitForSelector('text=حساب شما روی دستگاه دیگری فعال است', { timeout: T });
check('an expired ban lifts itself at the next sign-in', sql(`SELECT status FROM users WHERE id = ${uid.bob}`) === 'active');
await b3.context().close();
await bob.goto(APP); await bob.fill('input[name=username]', 'bob'); await bob.fill('input[name=password]', 'Strong pass 12345'); await bob.click('button');
await bob.waitForSelector('.c-side-head h1', { timeout: T });

// ---------------------------------------------------------------- abuse reports
console.log('\n# Abuse reports');
await openChat(alice, 'باب');
await alice.click('.c-head-who'); await alice.click('dialog >> text=⚑ گزارش تخلف این کاربر');
await alice.check('dialog input[value=abuse]'); await alice.fill('dialog textarea', 'REPORT-NOTE توهین کرد');
await alice.click('dialog >> text=ارسال گزارش');
await alice.waitForSelector('.c-toast:has-text("گزارش شما ثبت شد")', { timeout: T });
await openChat(alice, 'MOD-CANARY'); await alice.click('.c-lock'); await alice.click('dialog >> text=⚑ گزارش تخلف این گروه'); await alice.click('dialog >> text=ارسال گزارش');
await admin.goto(ADM + 'abuse');
const ab = await admin.textContent('main');
check('admin sees both reports (user + group) with reason and note', ab.includes('REPORT-NOTE') && ab.includes('توهین') && ab.includes('گروه #'));
await admin.locator('tr:has-text("REPORT-NOTE") button:text("بررسی شد")').click();
check('report marked reviewed', sql("SELECT status FROM abuse_reports WHERE details LIKE 'REPORT-NOTE%'") === 'reviewed');

// ---------------------------------------------------------------- support chat
console.log('\n# Support chat');
await admin.goto(ADM + 'support');
await admin.check('input[name=enabled]'); await admin.fill('input[name=name]', 'پشتیبانی پیام‌رسان'); await admin.fill('input[name=agents]', 'carol');
await admin.click('main form button');
await alice.reload(); await alice.waitForSelector('.c-side-head h1', { timeout: T });
await alice.waitForSelector('.c-list .c-item:has-text("پشتیبانی پیام‌رسان")', { timeout: T });
const firstItem = await alice.locator('.c-list .c-item').first().innerText();
check('every user has the support chat pinned first, with a verified badge', firstItem.includes('پشتیبانی پیام‌رسان') && await alice.isVisible('.c-list .c-item >> nth=0 >> .verified'));
await openChat(alice, 'پشتیبانی پیام‌رسان');
check('support chat: verified name in the header, no call buttons', await alice.isVisible('.c-head-name .verified') && !(await alice.$('[aria-label="تماس صوتی"]')));
await send(alice, 'سلام پشتیبانی SUP-CANARY کمک لازم دارم');
await carol.reload(); await carol.waitForSelector('.c-side-head h1', { timeout: T });
await carol.waitForSelector('.c-list .c-item:has-text("پشتیبانی: آلیس")', { timeout: T });
await openChat(carol, 'پشتیبانی: آلیس');
await carol.waitForSelector('.c-msg.in:has-text("SUP-CANARY")', { timeout: T });
check('the agent receives it as «پشتیبانی: آلیس» (decrypted on the agent\'s device)', true);
await send(carol, 'سلام، در خدمتیم SUP-REPLY');
await alice.waitForSelector('.c-msg.in:has-text("SUP-REPLY")', { timeout: T });
check('the user sees the reply without the agent\'s name', !(await alice.textContent('.c-msgs')).includes('کارول') && !(await alice.$('.c-msg.in .c-sender')));
check('agents see a «پشتیبانی» filter', await carol.isVisible('.chip[data-f=support]'));
check('server holds no support text', !execFileSync('mysqldump', ['-u', DBU, `-p${DBP}`, '-h', '127.0.0.1', DBN], { maxBuffer: 1 << 28 }).toString('latin1').includes('SUP-CANARY'));

// ---------------------------------------------------------------- call notifications for closed apps
console.log('\n# Call notifications (app closed or frozen)');
const sub = newSubscriber();
check('bob subscribes', (await api(bob, 'POST', 'push', { endpoint: 'https://push.test:9443/sub/bob', keys: { p256dh: sub.p256dh, auth: sub.auth } }))[0] === 201);
await openChat(alice, 'باب');
const p0 = pushes.length;
await alice.click('[aria-label="تماس تصویری"]');
for (let i = 0; i < 20 && pushes.length === p0; i++) await alice.waitForTimeout(500);
const cp = pushes.length > p0 ? JSON.parse(decrypt(sub.jwk, Buffer.from(sub.auth, 'base64url'), pushes[pushes.length - 1].body)) : {};
check('incoming VIDEO call → push even though bob\'s app counts as online (a frozen background tab cannot ring)', cp.t === 'c' && cp.v === 1, JSON.stringify(cp));
await bob.waitForSelector('.cl-overlay', { timeout: T });
await alice.click('.cl-overlay [aria-label="پایان تماس"]');
for (let i = 0; i < 20 && !pushes.slice(p0).some((x) => JSON.parse(decrypt(sub.jwk, Buffer.from(sub.auth, 'base64url'), x.body)).t === 'x'); i++) await alice.waitForTimeout(500);
check('caller hangs up before an answer → «missed call» push replaces the ringing notification',
  pushes.slice(p0).some((x) => JSON.parse(decrypt(sub.jwk, Buffer.from(sub.auth, 'base64url'), x.body)).t === 'x'));

// ---------------------------------------------------------------- web cron
console.log('\n# Cron');
await admin.goto(ADM + 'system');
const cronUrl = (await admin.locator('.secret').filter({ hasText: '/cron/' }).last().textContent()).trim();
check('System page explains both cron methods and shows the secret cron URL', (await admin.textContent('main')).includes('Cron Jobs') && /\/cron\/[a-f0-9]{32}$/.test(cronUrl), cronUrl);
const cr = await admin.request.get(cronUrl);
check('the cron URL runs maintenance (recorded as a real cron run)', (await cr.text()).trim() === 'ok' && sql("SELECT COUNT(*) FROM cron_runs WHERE trigger_source = 'cron'") === '1');
check('a wrong cron token → 404', (await admin.request.get(cronUrl.replace(/.$/, (c) => c === '0' ? '1' : '0'))).status() === 404);
await admin.reload();
check('System page now reports the last real cron run', (await admin.textContent('main')).includes('آخرین اجرای واقعی'));

check('no uncaught JavaScript errors', errors.length === 0, errors.join(' | '));
server.close();
await browser.close();
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
