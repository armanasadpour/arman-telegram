// Nobatyar service worker.
// Caching policy (privacy first):
//   - Only static, public files under assets/ plus offline.html and the manifest are cached.
//   - Pages (*.php), the API and anything with personal, medical or financial data are never cached:
//     they always come from the network. When the network is down, a page request shows offline.html.
// VERSION must match APP_VERSION (checked by the tests); a new version replaces the old cache.
const VERSION = '1.0.8';
const CACHE = 'nobatyar-static-' + VERSION;
const PRECACHE = [
  'offline.html',
  'manifest.webmanifest',
  'admin.webmanifest',
  'assets/style.css?v=' + VERSION,
  'assets/app.js?v=' + VERSION,
  'assets/jdate.js?v=' + VERSION,
  'assets/shell.js?v=' + VERSION,
  'assets/search.js?v=' + VERSION,
  'assets/booking.js?v=' + VERSION,
  'assets/admin.css?v=' + VERSION,
  'assets/admin.js?v=' + VERSION,
  'assets/icons.svg',
  'assets/icon.svg',
  'assets/icons/icon-192.png',
  'assets/icons/badge-72.png',
  'assets/fonts/regular.woff2',
  'assets/fonts/medium.woff2',
  'assets/fonts/bold.woff2',
  'assets/fonts/light.woff2',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(CACHE)
      .then((cache) => cache.addAll(PRECACHE))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith('nobatyar-') && k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

const isStatic = (url) =>
  url.origin === self.location.origin &&
  (url.pathname.includes('/assets/') || url.pathname.endsWith('/offline.html') || url.pathname.endsWith('/manifest.webmanifest') || url.pathname.endsWith('/admin.webmanifest'));

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (isStatic(url)) {
    // Cache first, refreshed in the background (versioned URLs change on every release).
    event.respondWith(
      caches.open(CACHE).then((cache) =>
        cache.match(req).then((hit) => {
          const net = fetch(req)
            .then((res) => {
              if (res.ok && res.type === 'basic') cache.put(req, res.clone());
              return res;
            })
            .catch(() => hit);
          return hit || net;
        }),
      ),
    );
    return;
  }
  if (req.mode === 'navigate') {
    // Network only; never stored. Offline fallback page when there is no connection.
    event.respondWith(fetch(req).catch(() => caches.match('offline.html')));
  }
  // Everything else (API, media, receipts) goes straight to the network without the worker.
});

self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = { title: 'نوبت‌یار', body: event.data ? event.data.text() : '' };
  }
  const title = data.title || 'نوبت‌یار';
  event.waitUntil(
    self.registration.showNotification(title, {
      body: data.body || '',
      tag: data.tag || undefined,
      dir: 'rtl',
      lang: 'fa',
      icon: 'assets/icons/icon-192.png',
      badge: 'assets/icons/badge-72.png',
      data: { url: data.url || 'notifications.php' },
    }),
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  // Only same-origin relative links are opened.
  const target = new URL(event.notification.data && event.notification.data.url ? event.notification.data.url : 'notifications.php', self.registration.scope);
  if (target.origin !== self.location.origin) return;
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      for (const c of list) {
        if (c.url === target.href && 'focus' in c) return c.focus();
      }
      return self.clients.openWindow(target.href);
    }),
  );
});

self.addEventListener('pushsubscriptionchange', (event) => {
  // The browser rotated the subscription; the page re-subscribes on the next visit to the notification center.
  event.waitUntil(Promise.resolve());
});
