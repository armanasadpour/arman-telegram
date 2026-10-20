// Search page in a real browser: phone toolbar and bottom sheets, filters applied without reloading
// (address bar updated, back button restores), chip removal, skeleton while loading, "show more",
// sorting sheet, nearest-clinic sort with granted / denied location, map view with markers, desktop
// sidebar and instant filters, back-to-results link on the doctor page, no overflow, no CSP violations.
// Needs v16_search.py (runtime/search-fixture.json).
const { chromium } = require('playwright'), fs = require('fs');
const f = JSON.parse(fs.readFileSync('runtime/search-fixture.json'));
(async () => {
  let checks = 0;
  const ok = (v, s) => { if (!v) throw Error(s); checks++; console.log('PASS ' + s); };
  const browser = await browser0();
  async function browser0() { return chromium.launch({ executablePath: process.env.TEST_BROWSER, headless: true, args: ['--no-sandbox'] }); }
  const base = 'http://127.0.0.1:8088/';
  const errors = [], csp = [];
  const watch = (p) => {
    p.on('pageerror', (e) => errors.push(e.message));
    p.on('console', (m) => { if (/Content Security Policy|Refused to/i.test(m.text())) csp.push(m.text()); });
  };
  const fa = (n) => String(n).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);
  try {
    /* ---------- Phone ---------- */
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, geolocation: { latitude: 27.19, longitude: 56.28 }, permissions: ['geolocation'] });
    const p = await ctx.newPage();
    watch(p);
    await p.goto(base + 'index.php?view=doctors&scope=all');
    await p.evaluate(() => (window.__noReload = 1));
    ok(await p.locator('[data-search-toolbar]').isVisible(), 'phone: filter / sort / map bar under the header');
    ok(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'phone: no horizontal scroll');
    ok(!(await p.locator('#filter-panel').evaluate((e) => e.getBoundingClientRect().top < innerHeight && getComputedStyle(e).visibility === 'visible')), 'filters start closed on phones');
    ok((await p.locator('[data-result-card]').count()) === 6, 'first six doctors listed');
    ok((await p.locator('.header-back').isVisible()) && (await p.locator('.header-search').isVisible()), 'phone header: back and search icons');
    await p.click('[data-sheet-open=filters]');
    await p.waitForTimeout(400);
    const box = await p.locator('#filter-panel').boundingBox();
    ok(Math.abs(box.y + box.height - 844) < 3, 'filters open as a bottom sheet');
    ok(await p.evaluate(() => document.documentElement.classList.contains('scroll-locked')), 'page scroll locked under the sheet');
    await p.locator('#filter-panel .seg label', { hasText: 'خانم' }).click();
    await p.locator('#filter-panel .filter-actions button').click();
    await p.waitForFunction(() => location.search.includes('gender=female'));
    await p.waitForSelector('[data-result-card]');
    ok(await p.evaluate(() => window.__noReload === 1), 'filters applied without reloading the page');
    ok((await p.locator('[data-result-card]').count()) === 1 && (await p.locator('[data-result-card] h3').innerText()).includes('صف آزمون'), 'results updated to the matching doctor');
    ok((await p.locator('[data-filter-count]').innerText()) === '۱', 'filter button shows one active filter');
    ok(await p.locator('.active-chips a', { hasText: 'پزشک خانم' }).isVisible(), 'active filter chip shown');
    ok(!(await p.evaluate(() => document.documentElement.classList.contains('scroll-locked'))), 'sheet closed after applying');
    await p.route('**/index.php?*partial=1*', async (route) => { await new Promise((r) => setTimeout(r, 500)); await route.continue(); });
    await p.locator('.active-chips a', { hasText: 'پزشک خانم' }).click();
    await p.waitForSelector('.skeleton-card');
    ok(true, 'skeleton cards while loading');
    await p.waitForSelector('.skeleton-card', { state: 'detached' });
    ok(!(await p.evaluate(() => location.search.includes('gender'))) && (await p.locator('[data-result-card]').count()) === 6, 'chip removed the filter in place');
    ok(await p.locator('[data-filter-count]').isHidden(), 'filter count cleared');
    ok(!(await p.locator('#filter-panel input[name=gender][value=female]').isChecked()), 'filter form follows the results');
    await p.unroute('**/index.php?*partial=1*');
    await p.goBack();
    await p.waitForFunction(() => location.search.includes('gender=female'));
    await p.waitForFunction(() => document.querySelectorAll('[data-result-card]').length === 1);
    ok(await p.evaluate(() => window.__noReload === 1), 'back button restores the previous filters in place');
    await p.goto(base + 'index.php?view=doctors&scope=all');
    await p.evaluate(() => (window.__noReload = 2));
    const more = p.locator('[data-load-more]');
    ok(await more.isVisible(), '«show more» button');
    await more.click();
    await p.waitForFunction(() => document.querySelectorAll('[data-result-card]').length > 6);
    const n2 = await p.locator('[data-result-card]').count();
    // rc.20: free doctors registered by later suites are listed too, so the total grows after v16's fixture.
    ok(n2 > 6 && n2 <= 12 && p.url().includes('page=2'), 'next doctors appended and address updated');
    ok((await p.locator('.results-pages').isHidden()), 'page numbers hidden when «show more» works');
    await p.click('[data-sheet-open=sort]');
    await p.waitForTimeout(400);
    ok(await p.locator('#sort-sheet').isVisible(), 'sort sheet opens');
    await p.locator('#sort-sheet a', { hasText: 'جدیدترین پزشکان' }).click();
    await p.waitForFunction(() => location.search.includes('sort=newest'));
    ok(await p.evaluate(() => window.__noReload === 2), 'sorting applied in place');
    await p.click('[data-sheet-open=sort]');
    await p.waitForTimeout(400);
    await p.locator('#sort-sheet [data-geo-sort]').click();
    await p.waitForFunction(() => location.search.includes('sort=distance'));
    await p.waitForSelector('[data-result-card]');
    ok(p.url().includes('lat=27.19') && (await p.locator('[data-result-card] h3').first().innerText()).includes('صف آزمون'), 'nearest clinic first after the visitor allows location');
    await p.click('[data-map-toggle]');
    await p.waitForSelector('.leaflet-marker-icon', { timeout: 8000 });
    ok(await p.locator('[data-results-map]').isVisible() && (await p.locator('.results-main').isHidden()), 'map view replaces the list on phones');
    await p.locator('.leaflet-marker-icon').first().click();
    await p.waitForSelector('.map-pop');
    ok((await p.locator('.map-pop a').getAttribute('href')).includes('doctor'), 'marker popup links to booking');
    await p.click('[data-map-toggle]');
    ok(await p.locator('.results-main').isVisible(), 'back to the list');
    const denied = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    // Simulates the visitor refusing the browser's location prompt.
    await denied.addInitScript(() => {
      navigator.geolocation.getCurrentPosition = (ok, fail) => setTimeout(() => fail({ code: 1, message: 'denied' }), 50);
    });
    const pd = await denied.newPage();
    watch(pd);
    await pd.goto(base + 'index.php?view=doctors&scope=all');
    await pd.click('[data-sheet-open=sort]');
    await pd.waitForTimeout(400);
    await pd.locator('#sort-sheet [data-geo-sort]').click();
    await pd.waitForSelector('.results-body .notice', { timeout: 15000 });
    ok((await pd.locator('.results-body .notice').innerText()).includes('اجازه'), 'location refused: clear message, results kept');
    await denied.close();
    /* Doctor page keeps the way back to these results. */
    await p.goto(base + 'index.php?view=doctors&scope=all&gender=female');
    await p.locator('[data-result-card] .rc-actions a.button:not(.secondary)').first().click();
    await p.waitForURL(/doctor\.php/);
    ok(await p.locator('[data-back-results]').isVisible(), 'doctor page offers «back to results»');
    ok((await p.locator('[data-back-results]').getAttribute('href')).includes('gender=female'), 'and keeps the filters');
    ok(await p.locator('.book-card .bc-fee').isVisible(), 'booking card states the fee');

    /* ---------- Desktop ---------- */
    const d = await (await browser.newContext({ viewport: { width: 1440, height: 1000 } })).newPage();
    watch(d);
    await d.goto(base + 'index.php?view=doctors&scope=all');
    ok(await d.locator('#filter-panel').isVisible() && (await d.locator('[data-search-toolbar]').isHidden()), 'desktop: filter column on the right, no phone bar');
    const fb = await d.locator('#filter-panel').boundingBox();
    const rb = await d.locator('.results-main').boundingBox();
    ok(fb.x > rb.x, 'filter column sits on the right in RTL');
    await d.evaluate(() => (window.__noReload = 1));
    await d.locator('#filter-panel .seg label', { hasText: 'خانم' }).click();
    await d.waitForFunction(() => location.search.includes('gender=female'));
    await d.waitForFunction(() => document.querySelectorAll('[data-result-card]').length === 1);
    ok(await d.evaluate(() => window.__noReload === 1), 'desktop: choices apply at once without reloading');
    ok(await d.locator('.sort-inline').isVisible(), 'desktop: sorting links above the results');
    ok(await d.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'desktop: no horizontal scroll');

    ok(errors.length === 0, 'no script errors ' + errors.join(' | '));
    ok(csp.length === 0, 'no Content-Security-Policy violations ' + csp.join(' | '));
  } finally {
    await browser.close();
  }
  console.log(`SUCCESS ${checks} search page browser checks`);
})().catch((e) => { console.error(e); process.exit(1); });
