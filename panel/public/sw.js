/* Panel service worker: makes the panel installable and keeps static files fast.
   Pages and API calls always go to the network (account data must be live); offline shows a short notice. */
const CACHE = 'panel-static-v1';
const OFFLINE = '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
  + '<title>آفلاین</title><body style="font-family:system-ui,Tahoma;display:grid;place-items:center;min-height:100vh;margin:0;background:#f5f7fb;color:#1f2937">'
  + '<div style="text-align:center;padding:24px"><div style="font-size:48px">📡</div><h2>اتصال اینترنت برقرار نیست</h2><p>پس از وصل شدن دوباره، صفحه را تازه کنید.</p>'
  + '<button onclick="location.reload()" style="padding:10px 18px;border:0;border-radius:12px;background:#4f46e5;color:#fff;font-size:16px">تلاش دوباره</button></div></body></html>';

self.addEventListener('install', (e) => { self.skipWaiting(); });
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;
  if (/^\/(assets|fonts|pwa)\//.test(url.pathname)) {
    e.respondWith(caches.open(CACHE).then((c) => c.match(req).then((hit) => hit || fetch(req).then((res) => { if (res.ok) c.put(req, res.clone()); return res; }))));
    return;
  }
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => new Response(OFFLINE, { headers: { 'Content-Type': 'text/html; charset=utf-8' } })));
  }
});
// Tapping a panel notification opens (or focuses) the notifications page.
self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || '/notices';
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
    for (const c of list) { if ('focus' in c) { c.navigate(url); return c.focus(); } }
    return self.clients.openWindow(url);
  }));
});
