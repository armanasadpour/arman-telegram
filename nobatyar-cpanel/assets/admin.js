/* Nobatyar administration shell — 1.0.0-rc.10.
   Phone menu drawer, section finder (Ctrl/⌘+K), dashboard chart tabs without reload, number count-up.
   Every feature has a no-JavaScript fallback: #admin-menu target, plain links, server-rendered numbers. */
(() => {
  const shell = document.querySelector('.admin-shell');
  if (!shell) return;
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- Menu drawer (phones and small tablets) ---------- */
  const sidebar = document.getElementById('admin-menu');
  const openers = document.querySelectorAll('[data-menu-open]');
  let lastFocus = null;
  const setOpen = (open) => {
    shell.classList.toggle('menu-open', open);
    openers.forEach((b) => b.setAttribute('aria-expanded', open ? 'true' : 'false'));
    document.documentElement.style.overflow = open ? 'hidden' : '';
    if (open) {
      lastFocus = document.activeElement;
      const current = sidebar.querySelector('.admin-nav a.current') || sidebar.querySelector('a');
      if (current) {
        current.focus({ preventScroll: true });
        current.scrollIntoView({ block: 'center' });
      }
    } else if (lastFocus) {
      lastFocus.focus({ preventScroll: true });
    }
  };
  openers.forEach((b) => b.addEventListener('click', (e) => { e.preventDefault(); setOpen(true); }));
  document.querySelectorAll('[data-menu-close]').forEach((b) => b.addEventListener('click', (e) => { e.preventDefault(); setOpen(false); }));
  if (location.hash === '#admin-menu') history.replaceState(null, '', location.pathname + location.search);
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && shell.classList.contains('menu-open')) setOpen(false);
  });
  window.matchMedia('(min-width: 921px)').addEventListener('change', (m) => m.matches && shell.classList.contains('menu-open') && setOpen(false));

  /* ---------- Section finder ---------- */
  const finder = document.querySelector('[data-finder]');
  if (finder) {
    const input = finder.querySelector('[data-finder-input]');
    const list = finder.querySelector('[data-finder-list]');
    const norm = (s) => s.replace(/[‌‏\s]+/g, ' ').replace(/ي/g, 'ی').replace(/ك/g, 'ک').trim().toLowerCase();
    const items = [...document.querySelectorAll('.admin-sidebar .admin-nav a')].map((a) => ({
      href: a.getAttribute('href'),
      label: a.querySelector('span') ? a.querySelector('span').textContent : a.textContent,
      group: (a.getAttribute('data-label') || '').replace(a.querySelector('span') ? a.querySelector('span').textContent : '', '').trim(),
      icon: a.querySelector('svg') ? a.querySelector('svg').outerHTML : '',
      key: norm(a.getAttribute('data-label') || a.textContent),
    }));
    let results = [];
    let active = 0;
    const render = () => {
      const q = norm(input.value);
      results = q ? items.filter((i) => q.split(' ').every((w) => i.key.includes(w))) : items.slice(0, 8);
      active = 0;
      list.innerHTML = '';
      if (!results.length) {
        const li = document.createElement('li');
        li.className = 'ad-empty';
        li.textContent = 'بخشی با این نام پیدا نشد.';
        list.appendChild(li);
      }
      results.forEach((r, i) => {
        const li = document.createElement('li');
        const a = document.createElement('a');
        a.href = r.href;
        a.setAttribute('role', 'option');
        a.innerHTML = r.icon;
        const t = document.createElement('span');
        t.textContent = r.label;
        const g = document.createElement('small');
        g.textContent = r.group;
        a.append(t, g);
        if (i === 0) a.classList.add('active');
        li.appendChild(a);
        list.appendChild(li);
      });
      list.hidden = false;
    };
    const move = (d) => {
      const links = list.querySelectorAll('a');
      if (!links.length) return;
      links[active].classList.remove('active');
      active = (active + d + links.length) % links.length;
      links[active].classList.add('active');
      links[active].scrollIntoView({ block: 'nearest' });
    };
    input.addEventListener('input', render);
    input.addEventListener('focus', render);
    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
      else if (e.key === 'Enter') {
        const a = list.querySelectorAll('a')[active];
        if (a) { e.preventDefault(); location.href = a.href; }
      } else if (e.key === 'Escape') { list.hidden = true; input.blur(); }
    });
    document.addEventListener('click', (e) => { if (!finder.contains(e.target)) list.hidden = true; });
    document.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K' || e.key === 'گ')) {
        e.preventDefault();
        input.focus();
        input.select();
      }
    });
    finder.hidden = false;
  }

  /* ---------- Range popover: close on outside click / Escape ---------- */
  document.querySelectorAll('.ad-pop').forEach((d) => {
    document.addEventListener('click', (e) => { if (d.open && !d.contains(e.target)) d.open = false; });
    d.addEventListener('keydown', (e) => { if (e.key === 'Escape') { d.open = false; d.querySelector('summary').focus(); } });
  });

  /* ---------- Dashboard chart tabs ---------- */
  const tabs = document.querySelectorAll('.ad-tabs [data-pane]');
  tabs.forEach((tab) => tab.addEventListener('click', (e) => {
    e.preventDefault();
    const key = tab.getAttribute('data-pane');
    tabs.forEach((t) => {
      const on = t === tab;
      t.classList.toggle('current', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      const pane = document.getElementById(t.getAttribute('aria-controls'));
      if (pane) pane.hidden = !on;
    });
    const url = new URL(location.href);
    url.searchParams.set('chart', key);
    history.replaceState(null, '', url);
    // Keep the chosen chart when the range changes.
    document.querySelectorAll('.ad-seg a').forEach((a) => {
      const u = new URL(a.href, location.href);
      u.searchParams.set('chart', key);
      a.href = u.pathname.split('/').pop() + u.search;
    });
  }));
  const tablist = document.querySelector('.ad-tabs');
  if (tablist) {
    tablist.addEventListener('keydown', (e) => {
      if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
      const list = [...tabs];
      const i = list.indexOf(document.activeElement);
      if (i < 0) return;
      // RTL: the left arrow moves to the next tab.
      const next = list[(i + (e.key === 'ArrowLeft' ? 1 : -1) + list.length) % list.length];
      next.focus();
      next.click();
    });
  }

  /* ---------- Count-up for the headline numbers ---------- */
  if (!reduce && 'IntersectionObserver' in window) {
    const fmt = new Intl.NumberFormat('fa-IR', { useGrouping: true, maximumFractionDigits: 0 });
    const run = (el) => {
      const target = parseInt(el.getAttribute('data-count'), 10);
      const final = el.textContent;
      if (!(target > 0)) return;
      const start = performance.now();
      const dur = 1000;
      const step = (now) => {
        const p = Math.min(1, (now - start) / dur);
        const eased = 1 - Math.pow(1 - p, 3);
        el.textContent = p < 1 ? fmt.format(Math.round(target * eased)).replace(/,/g, '٬') : final;
        if (p < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    };
    const io = new IntersectionObserver((entries) => entries.forEach((en) => {
      if (en.isIntersecting) { io.unobserve(en.target); run(en.target); }
    }), { threshold: 0.4 });
    document.querySelectorAll('[data-count]').forEach((el) => io.observe(el));
  }
})();

/* 1.0.29: «فقط یک شهر» shows (and requires) the province and city; the counts on the cards follow the chosen city. */
(() => {
  document.querySelectorAll('[data-audience-picker]').forEach((picker) => {
    const city = picker.querySelector('[data-audience-city]');
    const select = city && city.querySelector('select[name=city_id]');
    if (!city || !select) return;
    const fa = (n) => new Intl.NumberFormat('fa').format(n);
    const counts = (id) => fetch(picker.dataset.countsUrl + '&city=' + encodeURIComponent(id || 0), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((r) => (r.ok ? r.json() : null))
      .then((data) => {
        if (!data || !data.counts) return;
        picker.querySelectorAll('[data-audience-count]').forEach((el) => {
          const n = data.counts[el.dataset.audienceCount];
          if (n !== undefined) el.textContent = fa(n) + ' نفر';
        });
      })
      .catch(() => {});
    let last = '0';
    const sync = () => {
      const on = !!picker.querySelector('input[name=by_city][value="1"]:checked');
      city.hidden = !on;
      city.querySelectorAll('select').forEach((s) => { s.required = on; });
      const id = on && select.value ? select.value : '0';
      if (id !== last) { last = id; counts(id); }
    };
    picker.addEventListener('change', sync);
    sync();
  });
})();

/* 1.0.28: «new doctor» popup on the dashboard. Checks on load and every 45 seconds; «متوجه شدم» or opening a
   request marks them seen for this administrator, the close button only hides them until the next new sign-up. */
(() => {
  const box = document.querySelector('[data-new-doctors]');
  if (!box || !window.fetch) return;
  const list = box.querySelector('[data-nd-list]');
  const baseTitle = document.title;
  let shown = 0;
  let latest = 0;
  const closed = () => { try { return Number(sessionStorage.getItem('nd-closed') || 0); } catch (e) { return 0; } };
  const fa = (n) => new Intl.NumberFormat('fa').format(n);
  const node = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text) n.textContent = text; return n; };
  const hide = () => { box.hidden = true; document.body.classList.remove('nd-open'); document.title = baseTitle; };
  const seen = () => {
    const body = new FormData();
    body.append('csrf', box.dataset.csrf);
    body.append('action', 'new_doctors_seen');
    body.append('upto', String(latest));
    return fetch('admin.php', { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } }).catch(() => {});
  };
  const show = (rows) => {
    latest = rows[0].id;
    list.replaceChildren(...rows.map((d) => {
      const li = node('li');
      const a = node('a', 'nd-item');
      a.href = d.url;
      a.append(node('span', 'nd-avatar', d.name.replace(/^دکتر\s+/, '').trim().charAt(0) || '؟'));
      const text = node('span', 'nd-text');
      text.append(node('strong', '', d.name), node('small', '', [d.specialty, d.place].filter(Boolean).join(' · ')));
      a.append(text, node('time', 'nd-time', d.when));
      a.addEventListener('click', (e) => { e.preventDefault(); seen().finally(() => { location.href = d.url; }); });
      li.append(a);
      return li;
    }));
    box.querySelector('[data-nd-count]').textContent = fa(rows.length);
    box.querySelector('[data-nd-title]').textContent = rows.length > 1 ? fa(rows.length) + ' پزشک جدید ثبت‌نام کردند' : 'پزشک جدید ثبت‌نام کرد';
    box.hidden = false;
    document.body.classList.add('nd-open');
    document.title = '(' + fa(rows.length) + ') پزشک جدید — ' + baseTitle;
    // A tab in the background also gets a system notification when the administrator allowed them.
    if (shown && document.hidden && 'Notification' in window && Notification.permission === 'granted') {
      try { new Notification('پزشک جدید ثبت‌نام کرد', { body: rows[0].name + (rows[0].specialty ? ' — ' + rows[0].specialty : ''), icon: 'assets/icon.svg', tag: 'new-doctor-' + latest }); } catch (e) {}
    }
    shown = latest;
  };
  const check = () => fetch('admin.php?tab=dashboard&new_doctors=1', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then((r) => (r.ok ? r.json() : null))
    .then((data) => {
      const rows = (data && data.doctors) || [];
      if (!rows.length) { if (!box.hidden) hide(); return; }
      if (rows[0].id === shown && !box.hidden) return;
      if (rows[0].id <= closed()) return;
      show(rows);
    })
    .catch(() => {});
  box.querySelector('[data-nd-close]').addEventListener('click', () => { try { sessionStorage.setItem('nd-closed', String(latest)); } catch (e) {} hide(); });
  box.addEventListener('click', (e) => { if (e.target === box) box.querySelector('[data-nd-close]').click(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !box.hidden) box.querySelector('[data-nd-close]').click(); });
  box.querySelector('[data-nd-seen]').addEventListener('click', () => { seen(); hide(); });
  box.querySelector('[data-nd-review]').addEventListener('click', (e) => { e.preventDefault(); const href = e.currentTarget.href; seen().finally(() => { location.href = href; }); });
  check();
  setInterval(check, 45000);
})();
