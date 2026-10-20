// 1.0.0-rc.15: the administration panel installs as its own app. Chrome's own installability check (DevTools
// protocol) on admin.php (signed out and signed in) and on the public site: separate manifest ids, start pages,
// names and icons, no installability errors (test contexts are incognito; that one code is ignored). Requires the disposable v12_http.py fixture.
const { chromium } = require('playwright'), fs = require('fs');
(async () => {
  const f = JSON.parse(fs.readFileSync('runtime/admin-fixture.json'));
  let checks = 0;
  const ok = (v, s) => { if (!v) throw Error(s); checks++; console.log('PASS ' + s); };
  const browser = await chromium.launch({ executablePath: process.env.TEST_BROWSER, headless: true, args: ['--no-sandbox'] });
  const base = 'http://127.0.0.1:8088/';
  const inspect = async (path, session) => {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
    if (session) await ctx.addCookies([{ name: 'PHPSESSID', value: session, domain: '127.0.0.1', path: '/' }]);
    const p = await ctx.newPage();
    await p.goto(base + path, { waitUntil: 'load' });
    await p.evaluate(() => navigator.serviceWorker && navigator.serviceWorker.ready).catch(() => {});
    const cdp = await ctx.newCDPSession(p);
    const app = await cdp.send('Page.getAppManifest');
    const inst = await cdp.send('Page.getInstallabilityErrors');
    const manifest = app.data ? JSON.parse(app.data) : {};
    const apple = await p.locator('link[rel=apple-touch-icon]').getAttribute('href');
    const title = await p.locator('meta[name=apple-mobile-web-app-title]').getAttribute('content');
    await ctx.close();
    return { url: app.url, manifest, errors: (app.errors || []).map((e) => e.message), install: inst.installabilityErrors.map((e) => e.errorId).filter((id) => id !== 'in-incognito'), apple, title };
  };

  const site = await inspect('index.php');
  ok(site.url.endsWith('/manifest.webmanifest') && site.manifest.id === './index.php', 'public site keeps its manifest');
  ok(site.install.length === 0 && site.errors.length === 0, 'public site installable: ' + site.install.join(','));

  for (const [label, session] of [['signed out (login page)', null], ['signed in', f.owner]]) {
    const a = await inspect('admin.php', session);
    ok(a.url.endsWith('/admin.webmanifest'), `admin ${label}: own manifest`);
    ok(a.manifest.id === './admin.php' && a.manifest.start_url === './admin.php', `admin ${label}: own app id and start page (not index.php)`);
    ok(a.manifest.name === 'مدیریت نوبت‌یار' && a.manifest.icons.every((i) => i.src.includes('admin-')), `admin ${label}: own name and icons`);
    ok(a.errors.length === 0 && a.install.length === 0, `admin ${label}: installable (${a.install.join(',') || 'no errors'})`);
    ok(a.apple === 'assets/icons/admin-apple-touch-icon.png' && a.title.startsWith('مدیریت'), `admin ${label}: iPhone home-screen icon and name`);
  }
  for (const icon of ['admin-192.png', 'admin-512.png', 'admin-maskable-512.png', 'admin-apple-touch-icon.png']) {
    const ctx = await browser.newContext();
    const r = await ctx.request.get(base + 'assets/icons/' + icon);
    ok(r.status() === 200 && (r.headers()['content-type'] || '').includes('image/png'), 'icon served: ' + icon);
    await ctx.close();
  }
  await browser.close();
  console.log('SUCCESS ' + checks + ' admin app install checks');
})().catch((e) => { console.error(e); process.exit(1); });
