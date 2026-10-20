// Requires the disposable v11_http.py fixture. No live push or SMS.
const { chromium } = require('playwright'), fs = require('fs');
(async () => {
  const f = JSON.parse(fs.readFileSync('runtime/queue-fixture.json'));
  let checks = 0;
  const ok = (v, s) => { if (!v) throw Error(s); checks++; console.log('PASS ' + s); };
  const browser = await chromium.launch({ executablePath: process.env.TEST_BROWSER, headless: true, args: ['--no-sandbox'] });
  const base = 'http://127.0.0.1:8088/';
  const errors = [];
  const page = async (session) => {
    const ctx = await browser.newContext();
    if (session) await ctx.addCookies([{ name: 'PHPSESSID', value: session, domain: '127.0.0.1', path: '/' }]);
    const p = await ctx.newPage();
    p.on('pageerror', (e) => errors.push(e.message));
    p.on('dialog', (d) => d.accept());
    return p;
  };
  const patient = await page(f.patient), secretary = await page(f.secretary), guest = await page(null);
  const contained = (p) => p.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
  for (const [w, h, label] of [[1440, 1000, 'desktop'], [820, 1180, 'tablet'], [390, 844, 'mobile']]) {
    for (const [p, url, name] of [
      [patient, 'notifications.php', 'notification center'],
      [patient, 'patient.php', 'patient bookings'],
      [patient, 'patient.php?tab=waitlist', 'patient waitlist'],
      [patient, `doctor.php?id=${f.doctor}&clinic=${f.clinic}&date=${encodeURIComponent(f.day)}`, 'doctor page waitlist box'],
      [secretary, `secretary.php?clinic=${f.clinic}&tab=queue`, 'secretary queue'],
      [guest, `queue.php?t=${f.token}`, 'public queue display'],
    ]) {
      await p.setViewportSize({ width: w, height: h });
      await p.goto(base + url);
      ok(await contained(p), `${label} ${name} responsive`);
      ok(!/Fatal error|Warning:/.test(await p.locator('body').innerText()), `${label} ${name} renders`);
      if (['notification center', 'secretary queue', 'public queue display'].includes(name)) await p.screenshot({ path: `runtime/${name.replace(/ /g, '-')}-${label}.png`, fullPage: true });
    }
  }
  await patient.setViewportSize({ width: 1440, height: 1000 });
  await patient.goto(base + 'notifications.php');
  const enable = patient.locator('[data-push-enable]');
  ok(await enable.isDisabled(), 'push button disabled until consent');
  await patient.locator('[data-push-consent]').check();
  ok(!(await enable.isDisabled()), 'consent enables push button');
  await guest.goto(base + `queue.php?t=${f.token}`);
  const board = await guest.locator('main').innerText();
  ok(!/۰۹۱|09\d{9}|بیمار/.test(board), 'display text has no personal data');
  ok(errors.length === 0, 'no JavaScript exceptions ' + errors.join(' | '));
  await browser.close();
  console.log('SUCCESS ' + checks + ' notification browser checks');
})().catch((e) => { console.error(e); process.exit(1); });
