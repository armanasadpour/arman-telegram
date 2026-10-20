// Requires the disposable v12_http.py fixture. Administration pages at desktop, tablet and phone widths.
const { chromium } = require('playwright'), fs = require('fs');
(async () => {
  const f = JSON.parse(fs.readFileSync('runtime/admin-fixture.json'));
  let checks = 0;
  const ok = (v, s) => { if (!v) throw Error(s); checks++; console.log('PASS ' + s); };
  const browser = await chromium.launch({ executablePath: process.env.TEST_BROWSER, headless: true, args: ['--no-sandbox'] });
  const base = 'http://127.0.0.1:8088/';
  const errors = [];
  const page = async (session) => {
    const ctx = await browser.newContext();
    await ctx.addCookies([{ name: 'PHPSESSID', value: session, domain: '127.0.0.1', path: '/' }]);
    const p = await ctx.newPage();
    p.on('pageerror', (e) => errors.push(e.message));
    p.on('dialog', (d) => d.dismiss());
    return p;
  };
  const owner = await page(f.owner), user = await page(f.user);
  const contained = (p) => p.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
  const tabs = ['dashboard', 'users', 'admins', 'doctors', 'centers', 'specialties', 'subscriptions', 'payments', 'gateways', 'support', 'announcements', 'notifications', 'sms', 'content', 'general', 'cron', 'audit', 'account'];
  for (const [w, h, label] of [[1440, 1000, 'desktop'], [820, 1180, 'tablet'], [390, 844, 'mobile']]) {
    await owner.setViewportSize({ width: w, height: h });
    await user.setViewportSize({ width: w, height: h });
    for (const tab of tabs) {
      await owner.goto(base + 'admin.php?tab=' + tab + (tab === 'dashboard' ? '&range=year' : ''));
      ok(await contained(owner), `${label} admin ${tab} responsive`);
      ok(!/Fatal error|Warning:/.test(await owner.locator('body').innerText()), `${label} admin ${tab} renders`);
      if (['dashboard', 'support', 'admins'].includes(tab)) await owner.screenshot({ path: `runtime/admin-${tab}-${label}.png`, fullPage: true });
    }
    await owner.goto(base + `admin.php?tab=support&ticket=${f.ticket}`);
    ok(await contained(owner), `${label} ticket conversation responsive`);
    for (const url of ['support.php', `support.php?id=${f.ticket}`, 'index.php']) {
      await user.goto(base + url);
      ok(await contained(user), `${label} ${url} responsive`);
    }
  }
  await owner.setViewportSize({ width: 1440, height: 1000 });
  await owner.goto(base + 'admin.php?tab=dashboard&range=30d');
  const mark = owner.locator('.ad-pane:not([hidden]) .chart .hit').last();
  await mark.scrollIntoViewIfNeeded();
  await owner.waitForTimeout(300);
  await mark.hover();
  const tip = owner.locator('[data-chart-tip]');
  ok(await tip.isVisible() && /—/.test(await tip.innerText()), 'chart tooltip on hover');
  await owner.mouse.move(5, 5);
  await owner.locator('.ad-pane:not([hidden]) .chart .hit').first().focus();
  await owner.waitForTimeout(100);
  if (!(await owner.locator('[data-chart-tip]').isVisible())) await owner.locator('.ad-pane:not([hidden]) .chart .hit').first().focus();
  ok(await tip.isVisible(), 'chart tooltip on keyboard focus');
  await owner.locator('.chart-table summary').first().click();
  ok(await owner.locator('.chart-table table').first().isVisible(), 'chart data table opens');
  await owner.setViewportSize({ width: 390, height: 844 });
  await owner.goto(base + 'admin.php?tab=dashboard');
  // 1.0.0-rc.10: on phones the admin menu is a drawer opened from the top bar.
  ok(!(await owner.locator('.admin-nav a.current').isVisible()), 'mobile admin menu starts closed');
  await owner.click('[data-menu-open]');
  await owner.waitForTimeout(400);
  ok(await owner.locator('.admin-nav a.current').isVisible() && await contained(owner), 'mobile admin menu opens as a drawer');
  await owner.keyboard.press('Escape');
  // Wait for the 0.28s slide-out to finish rather than a fixed pause (slow machines took longer than 400ms).
  await owner.locator('.admin-nav a.current').waitFor({ state: 'hidden', timeout: 3000 }).catch(() => {});
  ok(!(await owner.locator('.admin-nav a.current').isVisible()), 'mobile admin menu closes with Escape');
  await owner.setViewportSize({ width: 1440, height: 1000 });
  await owner.goto(base + 'admin.php?tab=dashboard&range=year');
  await owner.click('.ad-tabs [data-pane=users]');
  ok(await owner.locator('#pane-users').isVisible() && !(await owner.locator('#pane-revenue').isVisible()) && owner.url().includes('chart=users'), 'dashboard chart tabs switch without reload');
  await owner.keyboard.press('Control+k');
  await owner.keyboard.type('پیامک');
  await Promise.all([owner.waitForURL(/tab=sms/), owner.keyboard.press('Enter')]);
  ok(true, 'section finder opens a section with Ctrl+K and Enter');
  await owner.setViewportSize({ width: 390, height: 844 });
  await owner.goto(base + 'admin.php?tab=announcements');
  await owner.selectOption('select[name=audience]', 'patients');
  await owner.fill('input[name=title]', 'لغو می‌شود');
  await owner.fill('textarea[name=body]', 'این اطلاعیه با رد تأیید ارسال نمی‌شود.');
  await owner.click('form[data-confirm] button');
  ok(!(await owner.content()).includes('لغو می‌شود</strong>'), 'announcement needs confirmation');
  ok(errors.length === 0, 'no JavaScript exceptions ' + errors.join(' | '));
  await browser.close();
  console.log('SUCCESS ' + checks + ' admin browser checks');
})().catch((e) => { console.error(e); process.exit(1); });
