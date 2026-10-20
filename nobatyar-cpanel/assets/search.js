'use strict';
/*
 * Doctor search page: filter and sort bottom sheets on phones, results updated in place (chips, filters,
 * sorting, "show more") with the address bar kept in sync, skeleton while loading, map view and
 * nearest-clinic sorting with the visitor's consent. Every control is also a plain link or form.
 */
(() => {
  const page = document.querySelector('[data-search-page]');
  if (!page) return;
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const phone = window.matchMedia('(max-width: 920px)');
  const body = page.querySelector('[data-results-body]');
  const toolbar = page.querySelector('[data-search-toolbar]');
  const backdrop = page.querySelector('[data-search-backdrop]');
  const filterSlot = page.querySelector('[data-filter-slot]');
  const sheets = { filters: page.querySelector('[data-sheet=filters]'), sort: page.querySelector('[data-sheet=sort]') };
  const toFa = (s) => String(s).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);
  toolbar.hidden = false;
  page.classList.add('js-search');
  requestAnimationFrame(() => requestAnimationFrame(() => page.classList.add('sheets-ready')));
  page.querySelectorAll('[data-geo-sort]').forEach((b) => (b.hidden = !('geolocation' in navigator)));

  /* ---------- Sheets ---------- */
  let openSheet = null;
  let lastFocus = null;
  let savedY = 0;
  const lock = (on) => {
    if (on) {
      savedY = window.scrollY;
      document.documentElement.classList.add('scroll-locked');
      document.body.style.top = -savedY + 'px';
    } else {
      document.documentElement.classList.remove('scroll-locked');
      document.body.style.top = '';
      window.scrollTo(0, savedY);
    }
  };
  const show = (name) => {
    const s = sheets[name];
    if (!s || (name === 'filters' && !phone.matches)) {
      if (name === 'filters') page.querySelector('#filter-panel input, #filter-panel select')?.focus();
      return;
    }
    lastFocus = document.activeElement;
    openSheet = s;
    s.hidden = false;
    backdrop.hidden = false;
    s.getBoundingClientRect();
    requestAnimationFrame(() => {
      s.classList.add('is-open');
      backdrop.classList.add('is-open');
    });
    lock(true);
    setTimeout(() => s.querySelector('.sheet-x')?.focus(), reduce ? 0 : 80);
  };
  const hide = () => {
    const s = openSheet;
    if (!s) return;
    openSheet = null;
    s.classList.remove('is-open');
    backdrop.classList.remove('is-open');
    const done = () => {
      if (s.classList.contains('is-open')) return;
      if (s !== sheets.filters) s.hidden = true;
      backdrop.hidden = true;
    };
    reduce ? done() : setTimeout(done, 280);
    lock(false);
    lastFocus?.focus?.();
  };
  page.querySelectorAll('[data-sheet-open]').forEach((b) => b.addEventListener('click', () => show(b.dataset.sheetOpen)));
  page.addEventListener('click', (e) => {
    if (e.target.closest('[data-sheet-close]')) hide();
  });
  backdrop.addEventListener('click', hide);
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && openSheet) hide();
  });
  phone.addEventListener('change', () => openSheet && hide());

  /* ---------- Results in place ---------- */
  const skeleton = () => {
    const wrap = document.createElement('div');
    wrap.className = 'result-list skeleton-list';
    wrap.setAttribute('aria-hidden', 'true');
    for (let i = 0; i < 3; i++) {
      const c = document.createElement('div');
      c.className = 'result-card skeleton-card';
      c.innerHTML = '<span class="sk sk-photo"></span><span class="sk sk-line w60"></span><span class="sk sk-line w40"></span><span class="sk sk-line w80"></span><span class="sk sk-btn"></span>';
      wrap.appendChild(c);
    }
    return wrap;
  };
  const partialUrl = (url, mode) => {
    const u = new URL(url, document.baseURI);
    u.searchParams.set('partial', mode);
    return u.href;
  };
  let pending = null;
  const sync = (doc) => {
    const meta = body.querySelector('[data-active-count]');
    const n = meta ? +meta.dataset.activeCount : 0;
    const badge = page.querySelector('[data-filter-count]');
    badge.hidden = n === 0;
    badge.textContent = toFa(n);
    const tpl = doc && doc.querySelector('template[data-filter-template]');
    if (tpl) {
      filterSlot.replaceChildren(tpl.content.cloneNode(true));
      page.dataset.mapQuery = tpl.dataset.mapQuery || '';
      bindForm();
    }
    page.querySelectorAll('.sort-link, .sort-option').forEach((a) => {
      if (a.tagName !== 'A') return;
      const want = new URL(a.href, document.baseURI).searchParams.get('sort') || '';
      const now = new URL(location.href).searchParams.get('sort') || '';
      a.classList.toggle('current', want === now);
    });
    if (mapOn) loadMarkers();
  };
  const load = async (url, push = true) => {
    if (pending) pending.abort();
    pending = new AbortController();
    hide();
    body.setAttribute('aria-busy', 'true');
    const list = body.querySelector('[data-result-list]');
    if (list) list.replaceWith(skeleton());
    else body.appendChild(skeleton());
    try {
      const res = await fetch(partialUrl(url, '1'), { credentials: 'same-origin', signal: pending.signal, headers: { Accept: 'text/html' } });
      if (!res.ok) throw new Error(String(res.status));
      const html = await res.text();
      const doc = new DOMParser().parseFromString('<div>' + html + '</div>', 'text/html');
      const tpl = doc.querySelector('template[data-filter-template]');
      if (tpl) tpl.remove();
      body.innerHTML = doc.body.firstChild.innerHTML;
      if (push) history.pushState({ search: true }, '', url);
      sync(tpl ? { querySelector: () => tpl } : null);
      body.removeAttribute('aria-busy');
      const top = page.querySelector('.results-main').getBoundingClientRect().top + window.scrollY - 140;
      if (window.scrollY > top) window.scrollTo({ top, behavior: reduce ? 'auto' : 'smooth' });
      remember();
    } catch (e) {
      if (e.name === 'AbortError') return;
      location.assign(url);
    }
  };
  const remember = () => {
    try {
      sessionStorage.setItem('nobatyar-results', location.href);
    } catch (e) {}
  };
  remember();
  page.addEventListener('click', (e) => {
    const a = e.target.closest('a[data-search-link]');
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
    e.preventDefault();
    load(a.href);
  });
  window.addEventListener('popstate', () => load(location.href, false));

  /* "Show more": next page appended under the current cards. */
  page.addEventListener('click', async (e) => {
    const a = e.target.closest('a[data-load-more]');
    if (!a || e.ctrlKey || e.metaKey) return;
    e.preventDefault();
    const wrap = a.closest('[data-load-more-wrap]');
    a.classList.add('is-loading');
    a.setAttribute('aria-busy', 'true');
    try {
      const res = await fetch(partialUrl(a.href, 'more'), { credentials: 'same-origin' });
      if (!res.ok) throw new Error();
      const tmp = document.createElement('div');
      tmp.innerHTML = await res.text();
      const first = tmp.querySelector('[data-result-card]');
      wrap.replaceWith(...tmp.childNodes);
      history.replaceState({ search: true }, '', a.href);
      remember();
      first?.querySelector('h3 a')?.focus({ preventScroll: true });
    } catch (err) {
      location.assign(a.href);
    }
  });

  /* Filter form: results update without leaving the page. */
  const bindForm = () => {
    const form = page.querySelector('[data-filter-form]');
    if (!form || form.dataset.bound) return;
    form.dataset.bound = '1';
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const params = new URLSearchParams();
      for (const [k, v] of new FormData(form)) if (String(v).trim() !== '') params.append(k, v);
      load('index.php?' + params.toString());
    });
    if (!phone.matches) {
      /* On wide screens choices apply at once; typed fields wait for Enter. */
      form.addEventListener('change', (e) => {
        if (e.target.matches('input[type=checkbox], input[type=radio]')) form.requestSubmit();
      });
    }
  };
  bindForm();

  /* Nearest clinic: asks the browser for the position only after the visitor chooses this sort. */
  page.querySelectorAll('[data-geo-sort]').forEach((b) =>
    b.addEventListener('click', () => {
      b.classList.add('is-loading');
      navigator.geolocation.getCurrentPosition(
        (pos) => {
          b.classList.remove('is-loading');
          const u = new URL(location.href);
          ['page', 'partial'].forEach((k) => u.searchParams.delete(k));
          u.searchParams.set('view', 'doctors');
          u.searchParams.set('sort', 'distance');
          u.searchParams.set('lat', pos.coords.latitude.toFixed(3));
          u.searchParams.set('lng', pos.coords.longitude.toFixed(3));
          load('index.php' + u.search);
        },
        () => {
          b.classList.remove('is-loading');
          hide();
          const note = document.createElement('p');
          note.className = 'notice';
          note.setAttribute('role', 'alert');
          note.textContent = 'اجازه دسترسی به موقعیت داده نشد؛ نتایج با ترتیب قبلی نمایش داده می‌شود.';
          body.prepend(note);
        },
        { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 },
      );
    }),
  );

  /* ---------- Map ---------- */
  const mapBox = page.querySelector('[data-results-map]');
  const mapToggle = page.querySelector('[data-map-toggle]');
  let mapOn = false;
  let map = null;
  let layer = null;
  let leaflet = null;
  const loadLeaflet = () =>
    (leaflet ||= new Promise((resolve, reject) => {
      if (window.L) return resolve(window.L);
      const css = document.createElement('link');
      css.rel = 'stylesheet';
      css.href = 'assets/vendor/leaflet/leaflet.css';
      const js = document.createElement('script');
      js.src = 'assets/vendor/leaflet/leaflet.js';
      js.onload = () => {
        window.L.Icon.Default.imagePath = 'assets/vendor/leaflet/images/';
        resolve(window.L);
      };
      js.onerror = reject;
      document.head.append(css, js);
    }));
  const note = (text) => {
    const n = mapBox.querySelector('[data-map-note]');
    n.textContent = text;
  };
  const card = (m) => {
    const box = document.createElement('div');
    box.className = 'map-pop';
    const h = document.createElement('strong');
    h.textContent = m.doctor;
    const sp = document.createElement('span');
    sp.textContent = m.specialty;
    const cl = document.createElement('small');
    cl.textContent = m.clinic;
    box.append(h, sp, cl);
    if (m.first) {
      const f = document.createElement('em');
      f.textContent = 'اولین نوبت: ' + m.first;
      box.appendChild(f);
    }
    const a = document.createElement('a');
    a.href = m.url;
    a.className = 'button';
    a.textContent = 'دریافت نوبت';
    box.appendChild(a);
    return box;
  };
  const loadMarkers = async () => {
    try {
      const L = await loadLeaflet();
      if (!map) {
        map = L.map(mapBox.querySelector('[data-map-canvas]'), { scrollWheelZoom: true }).setView([32.4, 53.7], 5);
        L.tileLayer(page.dataset.tiles, { maxZoom: 19, attribution: page.dataset.attribution, referrerPolicy: 'strict-origin-when-cross-origin' }).addTo(map);
      }
      const res = await fetch('api.php?route=search/map&' + (page.dataset.mapQuery || ''), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const json = await res.json();
      if (!json.ok) throw new Error(json.error?.message || '');
      if (layer) layer.remove();
      layer = L.featureGroup(json.data.map((m) => L.marker([m.lat, m.lng], { title: m.doctor }).bindPopup(card(m))));
      layer.addTo(map);
      map.invalidateSize();
      if (json.data.length) map.fitBounds(layer.getBounds().pad(0.2), { maxZoom: 15 });
      note(json.data.length ? toFa(json.data.length) + ' مطب روی نقشه؛ روی هر نشانگر بزنید.' : 'برای این نتایج مطبی با موقعیت ثبت‌شده روی نقشه نیست؛ فهرست را ببینید.');
    } catch (e) {
      note('نقشه در دسترس نیست؛ فهرست نتایج همچنان قابل استفاده است.');
    }
  };
  if (mapToggle && mapBox) {
    mapToggle.addEventListener('click', () => {
      mapOn = !mapOn;
      mapToggle.setAttribute('aria-pressed', String(mapOn));
      mapToggle.querySelector('[data-map-label]').textContent = mapOn ? 'نمایش فهرست' : 'نمایش روی نقشه';
      mapBox.hidden = !mapOn;
      page.classList.toggle('map-mode', mapOn);
      if (mapOn) loadMarkers();
    });
  }
})();
