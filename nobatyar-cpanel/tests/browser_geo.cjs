// 1.0.0-rc.9 in a real browser (after v21_geo.py): province and city fields (cities load on province change,
// choosing a city returns home with it), admin bulk selection (all, count, confirmation).
const { chromium } = require('playwright');
const fs = require('fs');
(async () => {
  const f = JSON.parse(fs.readFileSync('runtime/geo-fixture.json'));
  let checks = 0;
  const ok = (v, s) => {
    if (!v) throw Error(s);
    console.log('PASS ' + s);
    checks++;
  };
  const base = 'http://127.0.0.1:8088/';
  const browser = await chromium.launch({ executablePath: process.env.TEST_BROWSER, headless: true, args: ['--no-sandbox'] });
  const errors = [];
  for (const w of [320, 390, 1440]) {
    const p = await (await browser.newContext({ viewport: { width: w, height: 860 }, serviceWorkers: 'block' })).newPage();
    p.on('pageerror', (e) => errors.push(e.message));
    await p.goto(base + 'city.php');
    ok(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${w}px city picker: no horizontal scroll`);
    await p.selectOption('[data-city-form] select[name=province]', String(f.province));
    await p.waitForFunction(() => document.querySelector('[data-city-form] select[name=city]').options.length > 2);
    ok(true, 'cities of the province load into the city field');
    if (w === 390) await p.screenshot({ path: 'runtime/rc9-city-390.png' });
    await Promise.all([p.waitForNavigation(), p.selectOption('[data-city-form] select[name=city]', String(f.city))]);
    ok(p.url().includes('city=' + f.city), 'choosing the city applies it right away');
    ok((await p.locator('.site-header').innerText()).includes(f.city_name) || (await p.locator('body').innerText()).includes(f.city_name), 'chosen city shown');
    await p.context().close();
  }
  const ctx = await browser.newContext({ viewport: { width: 390, height: 860 }, serviceWorkers: 'block' });
  await ctx.addCookies([{ name: 'PHPSESSID', value: f.owner, domain: '127.0.0.1', path: '/' }]);
  const a = await ctx.newPage();
  a.on('pageerror', (e) => errors.push(e.message));
  await a.goto(base + 'admin.php?tab=cities&province=' + f.province);
  ok(await a.locator('#geo-bulk button[name=state]').first().isDisabled(), 'bulk buttons wait for a selection');
  await a.locator('[data-check-all]').check();
  const n = await a.locator('input[data-row-check]').count();
  ok((await a.locator('[data-bulk-count]').innerText()).includes('انتخاب شده') && (await a.locator('input[data-row-check]:checked').count()) === n, '«همه» ticks every city and shows the count');
  await a.locator('input[data-row-check]').first().uncheck();
  ok(await a.locator('[data-check-all]').evaluate((e) => e.indeterminate), 'partial selection shown on «همه»');
  let asked = false;
  a.once('dialog', (d) => {
    asked = true;
    d.dismiss();
  });
  await a.locator('#geo-bulk button[name=state][value="0"]').click();
  await a.waitForTimeout(300);
  ok(asked && a.url().includes('tab=cities'), 'turning many off asks first (cancel keeps them on)');
  ok(await a.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'admin cities: no horizontal scroll on phones');
  ok(errors.length === 0, 'no JavaScript errors ' + errors.join(' | '));
  await browser.close();
  console.log('SUCCESS ' + checks + ' rc.9 geo browser checks');
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
