// Service worker: makes the app installable, starts it fast from cache, shows an offline screen,
// and turns Web Push wake-ups into notifications. It never sees message content: push payloads
// carry only {t: 'm'|'c', c: conversation id, g: group?}; names come from what the open app shares.
const SCOPE = new URL(self.registration.scope).pathname;           // the app's secret path, e.g. /AbC.../
const ASSETS = 'pm-assets-v1';
const META = 'pm-meta-v1';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil((async () => {
  for (const k of await caches.keys()) if (![ASSETS, META].includes(k)) await caches.delete(k);
  // No clients.claim(): taking over a running page (with its crypto worker) crashed Chromium in tests;
  // the worker controls the app from the next load on, which is all that offline start and install need.
})()));

const OFFLINE = `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no"><title>بدون اتصال</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:IRANSans,Tahoma,sans-serif;background:#f5f3ff;color:#1e1b4b;text-align:center}
.c{padding:32px;max-width:340px}.i{width:88px;height:88px;border-radius:24px;margin:0 auto 18px;background:linear-gradient(135deg,#9b6bff,#5b21b6);display:grid;place-items:center;color:#fff;font-size:40px}
a{display:inline-block;margin-top:14px;background:#7c3aed;color:#fff;border-radius:14px;padding:12px 22px;text-decoration:none}
@media(prefers-color-scheme:dark){body{background:#0f0b1d;color:#ede9fe}}</style></head>
<body><div class="c"><div class="i">⌁</div><h2>اتصال اینترنت برقرار نیست</h2><p>پیام‌های شما امن روی همین دستگاه مانده‌اند. پس از اتصال، برنامه به‌طور خودکار ادامه می‌دهد.</p>
<a href="">تلاش دوباره</a></div><script>addEventListener('online',()=>location.reload())</script></body></html>`;

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin || !url.pathname.startsWith(SCOPE)) return;
  const sub = url.pathname.slice(SCOPE.length);
  if (sub.startsWith('api/')) return;                                   // live data: never cached
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => new Response(OFFLINE, { headers: { 'Content-Type': 'text/html; charset=utf-8' } })));
    return;
  }
  // Versioned static files (?v=<content hash>) never change: cache first.
  if ((sub.startsWith('assets/') && url.searchParams.has('v')) || (sub.startsWith('b/') && sub.includes('/icon'))) {
    e.respondWith((async () => {
      const cache = await caches.open(ASSETS);
      const hit = await cache.match(req);
      if (hit) return hit;
      const res = await fetch(req);
      if (res.ok) cache.put(req, res.clone());
      return res;
    })());
  }
});

// ---------------------------------------------------------------- notifications
async function meta() {
  const r = await (await caches.open(META)).match('meta');
  return r ? r.json() : { names: {}, muted: {}, sound: true, quiet: null, preview: 'none', icon: null, badge: null, unread: {} };
}
async function saveMeta(m) { await (await caches.open(META)).put('meta', new Response(JSON.stringify(m), { headers: { 'Content-Type': 'application/json' } })); }
const inQuiet = (q) => {
  if (!q || q.from === q.to) return false;
  const d = new Date(), n = d.getHours() * 60 + d.getMinutes();
  return q.from < q.to ? n >= q.from && n < q.to : n >= q.from || n < q.to;
};
const fa = (n) => n.toLocaleString('fa-IR');

self.addEventListener('push', (e) => e.waitUntil((async () => {
  let p = {};
  try { p = e.data ? e.data.json() : {}; } catch { /* empty push */ }
  const m = await meta();
  const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
  const visible = wins.some((w) => w.visibilityState === 'visible');
  const cid = typeof p.c === 'string' ? p.c : '';
  if (p.t === 't') { // test sent from «Settings → Notifications»
    await self.registration.showNotification('اعلان آزمایشی ✓', { body: 'اعلان وقتی برنامه بسته است روی این دستگاه کار می‌کند.', tag: 'push-test', icon: m.icon || undefined, badge: m.badge || undefined });
    return;
  }
  if (p.t === 'a' || p.t === 'x') { // answered on another device / the caller gave up
    for (const n of await self.registration.getNotifications({ tag: 'call-' + cid })) n.close();
    if (p.t === 'x' && !visible && !(m.muted[cid] && m.muted[cid] > Date.now())) {
      await self.registration.showNotification('📞 تماس ازدست‌رفته', { body: m.preview === 'sender' && m.names[cid] ? m.names[cid] : 'برای دیدن، برنامه را باز کنید',
        tag: 'missed-' + cid, icon: m.icon || undefined, badge: m.badge || undefined, data: { c: cid }, silent: true });
    } else if (p.t === 'a') {
      await self.registration.showNotification('تماس روی دستگاه دیگرتان پاسخ داده شد', { tag: 'call-' + cid, silent: true, icon: m.icon || undefined, badge: m.badge || undefined, data: { c: cid } });
      setTimeout(async () => { for (const n of await self.registration.getNotifications({ tag: 'call-' + cid })) n.close(); }, 4000);
    }
    return;
  }
  if (p.t === 'z') { for (const n of await self.registration.getNotifications({ tag: 'call-' + cid })) n.close(); return; }
  if (p.t === 'c') {
    if (visible) return; // the open app is ringing already
    const k = typeof p.k === 'string' ? p.k : '';
    if (p.r && k && rejected.has(k)) return; // declined here a moment ago: ignore the remaining «keep ringing» pushes
    // The caller's app re-sends this push every few seconds while it waits: each one replaces the notification
    // (same tag, renotify) and plays the notification sound + vibration again — the web's version of a ringtone.
    await self.registration.showNotification(p.v ? '🎥 تماس تصویری ورودی' : '📞 تماس ورودی', {
      body: (m.preview === 'sender' && m.names[cid] ? m.names[cid] : 'یک مخاطب') + ' در حال تماس با شماست…',
      tag: 'call-' + cid, renotify: true, requireInteraction: true, silent: false,
      vibrate: [800, 400, 800, 400, 800, 400, 800], timestamp: Date.now(),
      icon: m.icon || undefined, badge: m.badge || undefined, data: { c: cid, call: true, k, v: !!p.v },
      actions: [{ action: 'answer', title: p.v ? '🎥 پاسخ' : '📞 پاسخ' }, { action: 'reject', title: '✕ رد تماس' }],
    });
    return;
  }
  if (visible || (m.muted[cid] && m.muted[cid] > Date.now())) {
    // Must still be user-visible for some browsers; a muted chat shows a silent, quickly replaced summary.
    if (!visible) await self.registration.showNotification('پیام جدید', { tag: 'muted', silent: true, icon: m.icon || undefined, badge: m.badge || undefined, data: { c: cid } });
    return;
  }
  m.unread[cid] = (m.unread[cid] || 0) + 1;
  await saveMeta(m);
  const total = Object.values(m.unread).reduce((a, b) => a + b, 0);
  try { if (navigator.setAppBadge) await navigator.setAppBadge(total); } catch { /* unsupported */ }
  const who = m.preview === 'sender' ? m.names[cid] : null;
  const n = m.unread[cid];
  await self.registration.showNotification(who || 'پیام جدید', {
    body: who ? (n > 1 ? `${fa(n)} پیام جدید` : 'پیام جدید') : (n > 1 ? `${fa(n)} پیام جدید در یک گفتگو` : 'برای خواندن، برنامه را باز کنید'),
    tag: 'msg-' + cid, renotify: true, silent: !m.sound || inQuiet(m.quiet),
    icon: m.icon || undefined, badge: m.badge || undefined, timestamp: Date.now(), data: { c: cid },
  });
})()));

const rejected = new Set(); // call ids declined from a notification (this worker's lifetime)
self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const cid = e.notification.data?.c || '';
  const k = e.notification.data?.k || '';
  if (e.action === 'reject' && k) { // decline without opening the app; the caller's app learns it on its next ring check
    rejected.add(k);
    e.waitUntil(fetch(SCOPE + 'api/v1/calls/' + encodeURIComponent(k) + '/status', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-PM-Request': '1' }, body: JSON.stringify({ status: 'rejected', reason: 'hangup' }) }).catch(() => {}));
    return;
  }
  const hash = cid ? '#c=' + encodeURIComponent(cid) + (k ? (e.action === 'answer' ? '&answer=' : '&call=') + encodeURIComponent(k) : '') : '';
  e.waitUntil((async () => {
    if (k) {
      const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
      const w = wins.find((x) => new URL(x.url).pathname.startsWith(SCOPE));
      if (w) { await w.focus(); w.postMessage({ type: 'open', c: cid, answer: e.action === 'answer' ? k : null }); return; }
      await self.clients.openWindow(SCOPE + hash);
      return;
    }
    const m = await meta(); delete m.unread[cid]; await saveMeta(m);
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const w = wins.find((x) => new URL(x.url).pathname.startsWith(SCOPE));
    if (w) { await w.focus(); w.postMessage({ type: 'open', c: cid }); return; }
    await self.clients.openWindow(SCOPE + (cid ? '#c=' + encodeURIComponent(cid) : ''));
  })());
});

// The open app shares only what notifications need, and clears counters when a chat is read.
self.addEventListener('message', (e) => e.waitUntil((async () => {
  const d = e.data || {};
  const m = await meta();
  if (d.type === 'meta') {
    Object.assign(m, { names: d.names || {}, muted: d.muted || {}, sound: d.sound !== false, quiet: d.quiet || null, preview: d.preview || 'none', icon: d.icon || null, badge: d.badge || null });
  } else if (d.type === 'read') {
    delete m.unread[d.c];
    for (const n of await self.registration.getNotifications({ tag: 'msg-' + d.c })) n.close();
  } else if (d.type === 'clear') {
    m.unread = {};
    for (const n of await self.registration.getNotifications()) n.close();
  } else if (d.type === 'forget') {
    await caches.delete(META); await caches.delete(ASSETS); return;
  }
  await saveMeta(m); // the open page keeps the app badge up to date itself
})()));
