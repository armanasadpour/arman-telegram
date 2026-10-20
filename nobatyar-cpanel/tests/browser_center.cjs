// Requires the disposable v10_http.py fixture. No live messaging or payment.
const { chromium } = require('playwright'), fs = require('fs');
(async () => {
  const f = JSON.parse(fs.readFileSync('runtime/center-fixture.json'));
  let checks = 0;
  const ok = (v, s) => { if (!v) throw Error(s); checks++; console.log('PASS ' + s); };
  const browser = await chromium.launch({ executablePath: process.env.TEST_BROWSER, headless: true, args: ['--no-sandbox'] });
  const ctx = await browser.newContext();
  await ctx.addCookies([{ name: 'PHPSESSID', value: f.session, domain: '127.0.0.1', path: '/' }]);
  const p = await ctx.newPage();
  const errors = [];
  p.on('pageerror', (e) => errors.push(e.message));
  p.on('dialog', (d) => d.accept());
  const base = 'http://127.0.0.1:8088/';
  const tabs = ['dashboard', 'calendar', 'doctors', 'schedule', 'branches', 'reports', 'announcements', 'staff', 'subscription', 'profile', 'events'];
  const contained = () => p.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
  for (const [width, height, label] of [[1440, 1000, 'desktop'], [820, 1180, 'tablet'], [390, 844, 'mobile']]) {
    await p.setViewportSize({ width, height });
    for (const tab of tabs) {
      await p.goto(base + `center.php?center=${f.center}&tab=${tab}` + (tab === 'calendar' ? '&from=' + encodeURIComponent(f.day) + '&to=' + encodeURIComponent(f.day) : ''));
      await p.evaluate(() => document.fonts.ready);
      ok(await contained(), `${label} center ${tab} responsive`);
      ok(!/Fatal error|Warning:/.test(await p.locator('body').innerText()), `${label} center ${tab} renders`);
      if (['dashboard', 'schedule', 'calendar'].includes(tab)) await p.screenshot({ path: `runtime/center-${tab}-${label}.png`, fullPage: true });
    }
    for (const page of [`clinic.php?id=${f.center}`, 'centers.php', 'center-register.php']) {
      await p.goto(base + page);
      ok(await contained(), `${label} ${page.split('?')[0]} responsive`);
      if (page.startsWith('clinic')) await p.screenshot({ path: `runtime/clinic-public-${label}.png`, fullPage: true });
    }
  }
  await p.setViewportSize({ width: 1440, height: 1000 });
  await p.goto(base + `center.php?center=${f.center}&tab=staff`);
  const form = p.locator('form[data-role-presets]');
  await form.locator('[data-role-select]').selectOption('reporter');
  ok(await form.locator('input[name=perm_reports]').isChecked() && !(await form.locator('input[name=perm_bookings]').isChecked()), 'role preset fills permissions');
  await form.locator('[data-role-select]').selectOption('secretary');
  ok(await form.locator('input[name=perm_bookings]').isChecked() && !(await form.locator('input[name=perm_reports]').isChecked()), 'switching role updates permissions');
  await p.goto(base + `center.php?center=${f.center}&tab=branches`);
  ok((await p.locator('details.branch-card').count()) >= 2, 'branches listed as collapsible cards');
  ok(errors.length === 0, 'no JavaScript exceptions ' + errors.join(' | '));
  await browser.close();
  console.log('SUCCESS ' + checks + ' center browser checks');
})().catch((e) => { console.error(e); process.exit(1); });
