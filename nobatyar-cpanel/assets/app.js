'use strict';
/*
 * Progressive enhancement for نوبت‌یار. Every page works without JavaScript;
 * these modules only add convenience (live city lists, maps, confirmations, printing).
 */
(() => {
  const api = (route, params = {}) => {
    const query = new URLSearchParams({ route, ...params });
    return fetch('api.php?' + query.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((r) => r.json())
      .then((r) => {
        if (!r.ok) throw new Error(r.error?.message || 'خطا');
        return r.data;
      });
  };
  const debounce = (fn, ms) => {
    let t;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
  };
  const el = (tag, attrs = {}, text = '') => {
    const node = document.createElement(tag);
    Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, v));
    if (text) node.textContent = text;
    return node;
  };

  /* Province → city selects: reload the city list of the chosen province. */
  document.querySelectorAll('[data-geo]').forEach((group) => {
    const province = group.querySelector('select[name=province_id], select[name=province]');
    const city = group.querySelector('select[name=city_id], select[name=city]');
    if (!province || !city) return;
    const optional = group.hasAttribute('data-optional');
    const placeholder = () => (optional ? 'همه' : province.value && province.value !== '0' ? 'انتخاب کنید' : 'ابتدا استان را انتخاب کنید');
    const load = () => {
      const selected = city.dataset.selected || city.value;
      const id = province.value;
      city.replaceChildren(el('option', { value: optional ? '0' : '' }, placeholder()));
      if (!id || id === '0') return;
      city.disabled = true;
      api('geo/cities', { province: id, ...(optional ? { all: 1 } : {}) })
        .then((rows) => {
          rows.forEach((c) => {
            const o = el('option', { value: String(c.id), 'data-province': String(id) }, c.name);
            if (String(c.id) === String(selected)) o.selected = true;
            city.append(o);
          });
        })
        .catch(() => city.append(el('option', { value: '' }, 'دریافت شهرها ممکن نشد')))
        .finally(() => {
          city.disabled = false;
        });
    };
    province.addEventListener('change', () => {
      city.dataset.selected = '';
      load();
    });
    if (province.value && province.value !== '0' && city.options.length <= 1) load();
  });

  /* Instant city suggestions in the city picker and the first-visit prompt. */
  document.querySelectorAll('[data-city-search]').forEach((input) => {
    const list = input.form.querySelector('[data-city-results]');
    const back = input.dataset.back || 'index.php';
    if (!list) return;
    const search = debounce(() => {
      const q = input.value.trim();
      if (!q) {
        list.hidden = true;
        return;
      }
      api('geo/search', { q })
        .then((rows) => {
          list.replaceChildren();
          rows.slice(0, 8).forEach((c) => {
            const li = el('li');
            const a = el('a', { href: 'city.php?select=' + c.id + '&back=' + encodeURIComponent(back) });
            a.append(el('strong', {}, c.name), el('small', {}, c.province));
            li.append(a);
            list.append(li);
          });
          if (!rows.length) list.append(el('li', { class: 'muted' }, 'شهری پیدا نشد'));
          list.hidden = false;
        })
        .catch(() => {
          list.hidden = true;
        });
    }, 220);
    input.addEventListener('input', search);
    input.setAttribute('autocomplete', 'off');
  });

  /* Leaflet is bundled locally and loaded only on pages that contain a map. */
  let leaflet;
  const loadLeaflet = () => {
    if (leaflet) return leaflet;
    leaflet = new Promise((resolve, reject) => {
      const css = el('link', { rel: 'stylesheet', href: 'assets/vendor/leaflet/leaflet.css' });
      const js = el('script', { src: 'assets/vendor/leaflet/leaflet.js' });
      js.onload = () => {
        window.L.Icon.Default.imagePath = 'assets/vendor/leaflet/images/';
        resolve(window.L);
      };
      js.onerror = reject;
      document.head.append(css, js);
    });
    return leaflet;
  };
  // Tile servers (OpenStreetMap among them) refuse requests without a Referer; send the site's origin only.
  const baseMap = (L, node, lat, lng, zoom, tiles, attribution) => {
    const map = L.map(node, { scrollWheelZoom: false }).setView([lat, lng], zoom);
    if (window.ResizeObserver) new ResizeObserver(() => { if (node.clientWidth && node.clientHeight) map.invalidateSize({pan: false}); }).observe(node);
    const layer = L.tileLayer(tiles, { maxZoom: 19, attribution, referrerPolicy: 'strict-origin-when-cross-origin' }).addTo(map);
    let ok = 0;
    let bad = 0;
    layer.on('tileload', () => ok++);
    layer.on('tileerror', () => {
      bad++;
      if (bad >= 4 && !ok && !node.querySelector('.map-fail')) {
        const picker = node.closest('[data-map-picker]');
        const manual = picker && picker.querySelector('input[name=latitude]:not([type=hidden])');
        const msg = el('p', { class: 'map-fail' }, picker
          ? (manual ? 'تصویر نقشه دریافت نشد؛ مختصات را در کادرهای زیر وارد کنید.' : 'تصویر نقشه دریافت نشد؛ اگر در مطب هستید «موقعیت فعلی من» را بزنید یا بعداً از پنل پزشک دوباره امتحان کنید.')
          : 'تصویر نقشه از سرویس نقشه دریافت نشد. مختصات و پیوند «باز کردن در برنامه نقشه» همچنان کار می‌کند.');
        node.append(msg);
      }
    });
    return map;
  };

  document.querySelectorAll('[data-map-picker]').forEach((box) => {
    const canvas = box.querySelector('.map-canvas');
    const latInput = box.querySelector('[name=latitude]');
    const lngInput = box.querySelector('[name=longitude]');
    if (!canvas || !latInput || !lngInput) return;
    loadLeaflet()
      .then((L) => {
        canvas.hidden = false;
        const has = latInput.value && lngInput.value;
        const map = baseMap(L, canvas, has ? +latInput.value : 32.4, has ? +lngInput.value : 53.7, has ? 16 : 5, box.dataset.tiles, box.dataset.attribution);
        let marker = has ? L.marker([+latInput.value, +lngInput.value], { draggable: true }).addTo(map) : null;
        const set = (ll) => {
          latInput.value = ll.lat.toFixed(6);
          lngInput.value = ll.lng.toFixed(6);
        };
        map.on('click', (e) => {
          if (!marker) {
            marker = L.marker(e.latlng, { draggable: true }).addTo(map);
            marker.on('dragend', () => set(marker.getLatLng()));
          } else marker.setLatLng(e.latlng);
          set(e.latlng);
        });
        if (marker) marker.on('dragend', () => set(marker.getLatLng()));
        const place = (ll, zoom) => {
          if (!marker) {
            marker = L.marker(ll, { draggable: true }).addTo(map);
            marker.on('dragend', () => set(marker.getLatLng()));
          } else marker.setLatLng(ll);
          set(marker.getLatLng());
          map.setView(ll, zoom || 17);
        };
        latInput.addEventListener('change', () => {
          const a = parseFloat(latInput.value);
          const b = parseFloat(lngInput.value);
          if (a >= 24 && a <= 40 && b >= 44 && b <= 64) place(L.latLng(a, b), 16);
        });
        lngInput.addEventListener('change', () => latInput.dispatchEvent(new Event('change')));
        // «موقعیت فعلی من»: the browser's location (with the visitor's permission) fills the marker and fields.
        const locate = box.querySelector('[data-locate]');
        const status = box.querySelector('[data-locate-status]');
        if (locate && 'geolocation' in navigator) {
          locate.hidden = false;
          locate.addEventListener('click', () => {
            if (!window.isSecureContext) {
              status.textContent = 'گرفتن موقعیت فقط روی نشانی امن (https) ممکن است.';
              return;
            }
            locate.disabled = true;
            status.textContent = 'در حال دریافت موقعیت…';
            navigator.geolocation.getCurrentPosition(
              (pos) => {
                locate.disabled = false;
                place(L.latLng(pos.coords.latitude, pos.coords.longitude), 17);
                const acc = Math.round(pos.coords.accuracy || 0);
                status.textContent = 'موقعیت ثبت شد' + (acc ? ' (دقت حدود ' + acc.toLocaleString('fa-IR') + ' متر)' : '') + '؛ در صورت نیاز نشانگر را جابه‌جا کنید.';
              },
              (err) => {
                locate.disabled = false;
                status.textContent = err && err.code === 1 ? 'اجازه دسترسی به موقعیت داده نشد؛ از تنظیمات مرورگر اجازه دهید یا روی نقشه کلیک کنید.' : 'موقعیت پیدا نشد؛ دوباره امتحان کنید یا روی نقشه کلیک کنید.';
              },
              { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
            );
          });
        }
      })
      .catch(() => {
        canvas.hidden = true;
      });
  });

  document.querySelectorAll('[data-map-view]').forEach((node) => {
    loadLeaflet()
      .then((L) => {
        const lat = +node.dataset.lat;
        const lng = +node.dataset.lng;
        const map = baseMap(L, node, lat, lng, 16, node.dataset.tiles, node.dataset.attribution);
        L.marker([lat, lng]).addTo(map);
      })
      .catch(() => node.remove());
  });

  /* Small behaviours. */
  document.querySelectorAll('form[data-confirm]').forEach((form) =>
    form.addEventListener('submit', (e) => {
      if (!window.confirm(form.dataset.confirm)) e.preventDefault();
    }),
  );
  document.querySelectorAll('select[data-autosubmit]').forEach((s) => s.addEventListener('change', () => s.form.submit()));
  document.querySelectorAll('[data-print]').forEach((b) => {
    b.hidden = false;
    b.addEventListener('click', () => window.print());
  });
  document.querySelectorAll('[data-level]').forEach((level) => {
    const parent = level.form.querySelector('[data-parent-field]');
    if (!parent) return;
    const sync = () => {
      parent.hidden = level.value !== 'subspecialty';
    };
    level.addEventListener('change', sync);
    sync();
  });

  /* Secretary reception: clinic options follow the chosen doctor. */
  const receptionDoctor = document.querySelector('[data-reception-doctor]');
  const receptionClinic = document.querySelector('[data-reception-clinic]');
  if (receptionDoctor && receptionClinic) {
    const update = () => {
      for (const o of receptionClinic.options) {
        o.hidden = o.dataset.doctor !== receptionDoctor.value;
        o.disabled = o.hidden;
      }
      if (receptionClinic.selectedOptions[0]?.disabled) {
        receptionClinic.value = [...receptionClinic.options].find((o) => !o.disabled)?.value || '';
      }
    };
    receptionDoctor.addEventListener('change', update);
    update();
  }
  const receptionLink = document.querySelector('[data-reception-link]');
  const receptionUrl = document.querySelector('[data-reception-url]');
  if (receptionLink && receptionUrl) receptionUrl.textContent = receptionLink.href;

  /* Center staff form: choosing a role pre-fills its permission set (each box can still be changed). */
  document.querySelectorAll('form[data-role-presets]').forEach((form) => {
    let presets = {};
    try {
      presets = JSON.parse(form.dataset.rolePresets);
    } catch (e) {
      return;
    }
    const select = form.querySelector('[data-role-select]');
    if (!select) return;
    select.addEventListener('change', () => {
      const p = presets[select.value] || {};
      Object.keys(p).forEach((name) => {
        const box = form.querySelector(`input[type=checkbox][name="${name}"]`);
        if (box && !box.disabled) box.checked = Boolean(p[name]);
      });
    });
  });

  if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});

  /* Web Push: subscribe this device after explicit consent; the server stores the subscription. */
  const pushCard = document.querySelector('[data-push-card]');
  if (pushCard) {
    const enable = pushCard.querySelector('[data-push-enable]');
    const disable = pushCard.querySelector('[data-push-disable]');
    const consent = pushCard.querySelector('[data-push-consent]');
    const status = pushCard.querySelector('[data-push-status]');
    const say = (t) => {
      if (status) status.textContent = t;
    };
    const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    const keyBytes = (b64) => {
      const s = atob(b64.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (b64.length % 4)) % 4));
      return Uint8Array.from(s, (c) => c.charCodeAt(0));
    };
    const post = (route, body) =>
      fetch('api.php?route=session', { credentials: 'same-origin' })
        .then((r) => r.json())
        .then((s) =>
          fetch('api.php?route=' + route, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': s.data.csrf },
            body: JSON.stringify(body),
          }),
        )
        .then((r) => r.json().then((j) => (r.ok ? j : Promise.reject(new Error((j.error && j.error.message) || 'خطا')))));
    if (!supported) {
      say('این مرورگر از اعلان پوش پشتیبانی نمی‌کند. در آیفون، ابتدا نوبت‌یار را به صفحه اصلی اضافه کنید.');
    } else if (enable) {
      navigator.serviceWorker.ready
        .then((reg) => reg.pushManager.getSubscription())
        .then((sub) => {
          if (sub && Notification.permission === 'granted') {
            say('اعلان مرورگر روی این دستگاه فعال است.');
            if (disable) disable.hidden = false;
          }
        })
        .catch(() => {});
      consent.addEventListener('change', () => {
        enable.disabled = !consent.checked;
      });
      enable.addEventListener('click', () => {
        enable.disabled = true;
        say('در حال فعال‌سازی…');
        Promise.all([fetch('api.php?route=push/key', { credentials: 'same-origin' }).then((r) => r.json()), Notification.requestPermission()])
          .then(([key, permission]) => {
            if (permission !== 'granted') throw new Error('اجازه نمایش اعلان در مرورگر داده نشد.');
            if (!key.data || !key.data.available) throw new Error('اعلان مرورگر در سامانه فعال نیست.');
            return navigator.serviceWorker.ready.then((reg) => reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(key.data.key) }));
          })
          .then((sub) => post('push/subscribe', Object.assign(sub.toJSON(), { consent: true })))
          .then((r) => {
            say(r.data.message);
            if (disable) disable.hidden = false;
          })
          .catch((e) => {
            say(e.message || 'فعال‌سازی انجام نشد.');
            enable.disabled = !consent.checked;
          });
      });
      if (disable)
        disable.addEventListener('click', () => {
          navigator.serviceWorker.ready
            .then((reg) => reg.pushManager.getSubscription())
            .then((sub) => (sub ? post('push/unsubscribe', { endpoint: sub.endpoint }).then(() => sub.unsubscribe()) : null))
            .then(() => {
              say('اعلان مرورگر این دستگاه غیرفعال شد.');
              disable.hidden = true;
            })
            .catch((e) => say(e.message));
        });
    }
  }
})();

(() => {
  // Dashboard charts: one tooltip for every [data-tip] mark, on hover and on keyboard focus.
  const tip = document.querySelector('[data-chart-tip]');
  if (tip) {
    let shownAt = 0;
    const show = (mark, x, y) => {
      shownAt = Date.now();
      tip.textContent = mark.getAttribute('data-tip');
      tip.hidden = false;
      const w = tip.offsetWidth;
      tip.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, x - w / 2)) + 'px';
      tip.style.top = Math.max(8, y - tip.offsetHeight - 12) + 'px';
      document.querySelectorAll('.chart .hit.active').forEach((m) => m.classList.remove('active'));
      mark.classList.add('active');
    };
    const hide = () => {
      tip.hidden = true;
      document.querySelectorAll('.chart .hit.active').forEach((m) => m.classList.remove('active'));
    };
    document.querySelectorAll('.chart [data-tip]').forEach((mark) => {
      mark.addEventListener('pointermove', (e) => show(mark, e.clientX, e.clientY));
      mark.addEventListener('pointerleave', hide);
      mark.addEventListener('focus', () => {
        const r = mark.getBoundingClientRect();
        show(mark, r.left + r.width / 2, r.top);
      });
      mark.addEventListener('blur', hide);
    });
    // Focus scrolls the mark into view; ignore that scroll, hide on later ones.
    window.addEventListener('scroll', () => Date.now() - shownAt > 400 && hide(), { passive: true });
  }
})();

/* PWA and feedback: install button, offline notice, busy state on submit (prevents double submission). */
(() => {
  // Install: the button appears only when the browser offers installation, so it is never inert.
  let deferred = null;
  const buttons = () => document.querySelectorAll('[data-install]');
  const installed = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  document.querySelectorAll('[data-installed]').forEach((n) => (n.hidden = !installed));
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferred = e;
    buttons().forEach((b) => (b.hidden = false));
  });
  buttons().forEach((b) =>
    b.addEventListener('click', () => {
      if (!deferred) return;
      deferred.prompt();
      deferred.userChoice.finally(() => {
        deferred = null;
        buttons().forEach((x) => (x.hidden = true));
      });
    }),
  );
  window.addEventListener('appinstalled', () => document.querySelectorAll('[data-installed]').forEach((n) => (n.hidden = false)));

  // Connection status.
  const net = document.querySelector('[data-net-status]');
  const syncNet = () => {
    if (net) net.hidden = navigator.onLine;
  };
  window.addEventListener('online', syncNet);
  window.addEventListener('offline', syncNet);
  syncNet();

  // Busy state: runs after per-form handlers (e.g. confirmation), only when the submit really proceeds.
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (e.defaultPrevented || !(form instanceof HTMLFormElement) || form.hasAttribute('data-no-busy')) return;
    if (form.dataset.busy === '1') {
      e.preventDefault();
      return;
    }
    form.dataset.busy = '1';
    form.setAttribute('aria-busy', 'true');
    const button = e.submitter || form.querySelector('button:not([type=button])');
    document.documentElement.classList.add('is-navigating');
    // Disabling synchronously would drop the clicked button's name/value from the submission.
    setTimeout(() => {
      if (button) {
        button.classList.add('is-loading');
        button.disabled = true;
      }
    }, 0);
    // Downloads (Excel, backups) do not leave the page: release the form after a moment.
    setTimeout(() => release(form), 8000);
  });
  const release = (form) => {
    delete form.dataset.busy;
    form.removeAttribute('aria-busy');
    form.querySelectorAll('button.is-loading').forEach((b) => {
      b.classList.remove('is-loading');
      b.disabled = false;
    });
    document.documentElement.classList.remove('is-navigating');
  };
  // Returning with the back button restores a page from cache with its busy state; clear it.
  window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-busy]').forEach(release);
    document.documentElement.classList.remove('is-navigating');
  });
  document.addEventListener('click', (e) => {
    const a = e.target.closest && e.target.closest('a[href]');
    if (!a || a.target || a.hasAttribute('download') || e.ctrlKey || e.metaKey || e.shiftKey) return;
    const url = new URL(a.href, location.href);
    if (url.origin !== location.origin || url.hash || /export=|download=/.test(url.search) || a.getAttribute('href').startsWith('#')) return;
    document.documentElement.classList.add('is-navigating');
  });
})();

/* Homepage: search suggestions (combobox), sticky header shadow, rail arrows; admin drag-and-drop ordering. */
(() => {
  // Suggestions: doctors, specialties and centers while typing. Arrow keys move, Enter opens, Esc closes.
  document.querySelectorAll('form[data-suggest]').forEach((form) => {
    const input = form.querySelector('[data-suggest-input]');
    const box = form.querySelector('[data-suggest-list]');
    if (!input || !box) return;
    let items = [], active = -1, timer, seq = 0;
    const close = () => {
      box.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      active = -1;
    };
    const mark = (i) => {
      items.forEach((a, j) => a.setAttribute('aria-selected', String(j === i)));
      active = i;
      if (items[i]) input.setAttribute('aria-activedescendant', items[i].id);
    };
    const show = (data) => {
      box.replaceChildren();
      items = [];
      const groups = [['doctors', 'پزشکان'], ['specialties', 'تخصص‌ها'], ['centers', 'مراکز درمانی']];
      groups.forEach(([key, label]) => {
        if (!data[key] || !data[key].length) return;
        const g = document.createElement('div');
        g.className = 'suggest-group';
        g.setAttribute('role', 'presentation');
        g.textContent = label;
        box.append(g);
        data[key].forEach((r) => {
          const a = document.createElement('a');
          a.href = r.url;
          a.id = 'sg-' + items.length;
          a.setAttribute('role', 'option');
          const strong = document.createElement('strong');
          strong.textContent = r.name;
          const small = document.createElement('small');
          small.textContent = r.meta;
          a.append(strong, small);
          box.append(a);
          items.push(a);
        });
      });
      if (!items.length) {
        const empty = document.createElement('p');
        empty.className = 'suggest-empty';
        empty.textContent = 'موردی پیدا نشد؛ برای جستجوی کامل Enter را بزنید.';
        box.append(empty);
      }
      box.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    };
    input.addEventListener('input', () => {
      clearTimeout(timer);
      const q = input.value.trim();
      if (q.length < 2) return close();
      box.replaceChildren(...[1, 2, 3].map(() => Object.assign(document.createElement('span'), { className: 'suggest-skeleton skeleton' })));
      box.hidden = false;
      timer = setTimeout(() => {
        const my = ++seq;
        fetch('api.php?' + new URLSearchParams({ route: 'search/suggest', q }), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then((r) => r.json())
          .then((r) => {
            if (my === seq && r.ok) show(r.data);
          })
          .catch(() => {
            if (my !== seq) return;
            box.replaceChildren(Object.assign(document.createElement('p'), { className: 'suggest-empty', textContent: 'پیشنهادها دریافت نشد؛ Enter را بزنید.' }));
          });
      }, 220);
    });
    input.addEventListener('keydown', (e) => {
      if (box.hidden) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        if (!items.length) return;
        mark((active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length);
      } else if (e.key === 'Enter' && items[active]) {
        e.preventDefault();
        location.href = items[active].href;
      } else if (e.key === 'Escape') {
        close();
      }
    });
    document.addEventListener('click', (e) => {
      if (!form.contains(e.target)) close();
    });
  });

  // Sticky header: a hairline shadow once the page scrolls.
  const header = document.querySelector('[data-sticky-header]');
  if (header) {
    const onScroll = () => header.classList.toggle('scrolled', window.scrollY > 8);
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  // Rails: previous/next buttons appear only when the rail overflows.
  document.querySelectorAll('[data-rail]').forEach((rail) => {
    const wrap = document.createElement('div');
    wrap.className = 'rail-wrap';
    rail.parentNode.insertBefore(wrap, rail);
    wrap.append(rail);
    const mk = (dir, label) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'rail-btn ' + dir;
      b.setAttribute('aria-label', label);
      b.innerHTML = '<svg class="icon" aria-hidden="true"><use href="assets/icons.svg#chevron-' + (dir === 'prev' ? 'right' : 'left') + '"></use></svg>';
      b.addEventListener('click', () => rail.scrollBy({ left: (dir === 'prev' ? 1 : -1) * rail.clientWidth * 0.8, behavior: 'smooth' }));
      wrap.append(b);
      return b;
    };
    const prev = mk('prev', 'قبلی'), next = mk('next', 'بعدی');
    const sync = () => {
      const max = rail.scrollWidth - rail.clientWidth;
      const pos = Math.abs(rail.scrollLeft);
      prev.hidden = max < 4 || pos < 4;
      next.hidden = max < 4 || pos > max - 4;
    };
    rail.addEventListener('scroll', sync, { passive: true });
    window.addEventListener('resize', sync);
    sync();
  });

  // Admin ordering lists: drag and drop, or the up/down buttons for keyboard users. Hidden inputs keep the order.
  document.querySelectorAll('[data-sortable]').forEach((list) => {
    let dragged = null;
    const changed = () => list.dispatchEvent(new Event('change', { bubbles: true }));
    list.querySelectorAll('[data-sort-item]').forEach((item) => {
      item.draggable = true;
      item.addEventListener('dragstart', (e) => {
        dragged = item;
        item.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', '');
      });
      item.addEventListener('dragend', () => {
        item.classList.remove('dragging');
        dragged = null;
        changed();
      });
      item.addEventListener('dragover', (e) => {
        if (!dragged || dragged === item) return;
        e.preventDefault();
        const r = item.getBoundingClientRect();
        item.parentNode.insertBefore(dragged, e.clientY > r.top + r.height / 2 ? item.nextSibling : item);
      });
      item.querySelectorAll('[data-move]').forEach((b) =>
        b.addEventListener('click', () => {
          const sib = b.dataset.move === 'up' ? item.previousElementSibling : item.nextElementSibling;
          if (!sib) return;
          if (b.dataset.move === 'up') item.parentNode.insertBefore(item, sib);
          else item.parentNode.insertBefore(sib, item);
          b.focus();
          changed();
        }),
      );
    });
  });
})();

/* Back to the last search results (filters kept in the address) and the phone header back button. */
(() => {
  let last = null;
  try {
    last = sessionStorage.getItem('nobatyar-results');
  } catch (e) {}
  document.querySelectorAll('[data-back-results]').forEach((a) => {
    try {
      if (!last || new URL(last).origin !== location.origin) return;
    } catch (e) {
      return;
    }
    a.href = last;
    a.hidden = false;
  });
  document.querySelectorAll('[data-history-back]').forEach((a) =>
    a.addEventListener('click', (e) => {
      let same = false;
      try {
        same = document.referrer && new URL(document.referrer).origin === location.origin;
      } catch (err) {}
      if (same && history.length > 1) {
        e.preventDefault();
        history.back();
      }
    }),
  );
})();

/* Tables become cards on phones: each cell gets its column title as a label (CSS shows it under 640px). */
(() => {
  document.querySelectorAll('.table-wrap table:not(.no-stack)').forEach((t) => {
    const rows = [...t.rows];
    const head = rows.find((r) => r.cells.length && [...r.cells].every((c) => c.tagName === 'TH'));
    if (!head) return;
    const labels = [];
    [...head.cells].forEach((c) => {
      for (let i = 0; i < (c.colSpan || 1); i++) labels.push(c.textContent.trim().replace(/\s+/g, ' '));
    });
    let simple = true;
    rows.forEach((r) => {
      if (r === head || r.parentElement.tagName === 'THEAD') {
        r.classList.add('th-row');
        return;
      }
      let col = 0;
      [...r.cells].forEach((c) => {
        if (c.colSpan > 1) simple = simple && r.cells.length === 1;
        if (!c.hasAttribute('data-label')) c.setAttribute('data-label', c.colSpan > 1 ? '' : labels[col] || '');
        col += c.colSpan || 1;
      });
    });
    if (simple) t.classList.add('stackable');
  });
})();

/* Doctor schedule: days on/off, extra shifts, copy the first day's hours, live «≈ N نوبت» per day. */
(() => {
  const box = document.querySelector('[data-schedule]');
  if (!box) return;
  const fa = (n) => String(n).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);
  const step = +box.dataset.step || 15;
  const gap = +box.dataset.gap || 0;
  const seats = +box.dataset.seats || 1;
  const daily = +box.dataset.daily || 999;
  const mins = (t) => (/^\d\d:\d\d$/.test(t) ? +t.slice(0, 2) * 60 + +t.slice(3) : null);
  const days = [...box.querySelectorAll('[data-day]')];
  const tools = box.querySelector('[data-week-tools]');
  if (tools) tools.hidden = false;
  const refresh = (day) => {
    const on = day.querySelector('[data-day-on]').checked;
    day.classList.toggle('on', on);
    const shifts = [...day.querySelectorAll('[data-shift]')];
    const hidden = shifts.filter((s) => s.hidden);
    const add = day.querySelector('[data-shift-add]');
    if (add) add.hidden = !on || hidden.length === 0;
    const len = +(day.querySelector('[data-day-len]')?.value || 0);
    const dayStep = len ? Math.max(5, len + gap) : step;
    let n = 0;
    shifts.forEach((s) => {
      if (s.hidden) return;
      const [a, b] = [...s.querySelectorAll('select')].map((i) => mins(i.value));
      if (a !== null && b !== null && b > a) n += Math.floor((b - a) / dayStep);
    });
    const out = day.querySelector('[data-count]');
    if (out) out.textContent = on ? '≈ ' + fa(Math.min(n * seats, daily)) + ' نوبت' : '';
  };
  days.forEach((day) => {
    day.querySelectorAll('[data-shift][data-empty]').forEach((s) => (s.hidden = true));
    day.querySelectorAll('[data-shift-remove]').forEach((b) => (b.hidden = false));
    day.addEventListener('change', (e) => {
      if (e.target.matches('[data-day-on]') && e.target.checked) {
        const first = day.querySelector('[data-shift] select');
        if (first && !first.value) {
          const inputs = day.querySelectorAll('[data-shift] select');
          inputs[0].value = '09:00';
          inputs[1].value = '13:00';
        }
      }
      refresh(day);
    });
    day.addEventListener('input', () => refresh(day));
    day.addEventListener('click', (e) => {
      const add = e.target.closest('[data-shift-add]');
      const rm = e.target.closest('[data-shift-remove]');
      if (add) {
        const next = day.querySelector('[data-shift][hidden]');
        if (next) {
          next.hidden = false;
          const [a, b] = next.querySelectorAll('select');
          if (!a.value) a.value = '16:00';
          if (!b.value) b.value = '20:00';
          a.focus();
        }
      } else if (rm) {
        const s = rm.closest('[data-shift]');
        s.querySelectorAll('select').forEach((i) => (i.value = ''));
        s.hidden = true;
      } else return;
      refresh(day);
    });
    refresh(day);
  });
  box.querySelector('[data-copy-week]')?.addEventListener('click', () => {
    const on = days.filter((d) => d.querySelector('[data-day-on]').checked);
    if (!on.length) return;
    const src = [...on[0].querySelectorAll('[data-shift]')].map((s) => (s.hidden ? null : [...s.querySelectorAll('select')].map((i) => i.value)));
    on.slice(1).forEach((day) => {
      [...day.querySelectorAll('[data-shift]')].forEach((s, i) => {
        const v = src[i];
        const inputs = s.querySelectorAll('select');
        s.hidden = !v;
        inputs[0].value = v ? v[0] : '';
        inputs[1].value = v ? v[1] : '';
      });
      refresh(day);
    });
  });
  box.querySelector('[data-copy-len]')?.addEventListener('click', () => {
    const on = days.filter((d) => d.querySelector('[data-day-on]').checked);
    if (!on.length) return;
    const src = on[0].querySelector('[data-day-len]')?.value ?? '';
    on.slice(1).forEach((day) => {
      const len = day.querySelector('[data-day-len]');
      if (len) len.value = src;
      refresh(day);
    });
  });
  const partial = box.querySelector('[data-partial]');
  const times = box.querySelector('[data-partial-times]');
  if (partial && times) {
    const sync = () => (times.hidden = !partial.checked);
    partial.addEventListener('change', sync);
    sync();
  }
})();

/* Admin bulk selection (provinces, cities): «همه», live count, confirmation before turning many off. */
(() => {
  document.querySelectorAll('form[data-bulk]').forEach((form) => {
    const boxes = () => [...document.querySelectorAll(`input[data-row-check][form="${form.id}"]`)];
    const all = form.querySelector('[data-check-all]');
    const count = form.querySelector('[data-bulk-count]');
    const fa = (n) => String(n).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);
    const sync = () => {
      const list = boxes();
      const n = list.filter((b) => b.checked).length;
      if (count) count.textContent = n ? fa(n) + ' مورد انتخاب شده' : 'موردی انتخاب نشده';
      if (all) {
        all.checked = n > 0 && n === list.length;
        all.indeterminate = n > 0 && n < list.length;
      }
      form.querySelectorAll('button[name=state]').forEach((b) => (b.disabled = n === 0));
      list.forEach((b) => b.closest('tr')?.classList.toggle('row-selected', b.checked));
    };
    all?.addEventListener('change', () => {
      boxes().forEach((b) => (b.checked = all.checked));
      sync();
    });
    document.addEventListener('change', (e) => {
      if (e.target.matches(`input[data-row-check][form="${form.id}"]`)) sync();
    });
    form.addEventListener('click', (e) => {
      const b = e.target.closest('button[name=state][value="0"]');
      if (b && !window.confirm('موارد انتخاب‌شده غیرفعال شوند؟ پزشکان و مطب‌های آن‌ها از صفحه‌های عمومی پنهان می‌شوند (حذف نمی‌شوند).')) e.preventDefault();
    });
    sync();
  });
})();

/* 1.0.0-rc.11: «اتباع خارجی هستم» — ticked, no national ID is needed; the input becomes an optional passport number. */
(() => {
  document.querySelectorAll('[data-nid]').forEach((box) => {
    const tick = box.querySelector('[data-nid-foreign]');
    const input = box.querySelector('input[name=national_id]');
    const label = box.querySelector('[data-nid-label]');
    if (!tick || !input || !label) return;
    const sync = () => {
      const foreign = tick.checked;
      label.innerHTML = foreign ? label.dataset.foreignText : label.dataset.nidText;
      input.required = !foreign && input.hasAttribute('data-nid-required');
      input.inputMode = foreign ? 'text' : 'numeric';
      input.placeholder = foreign ? 'در صورت تمایل' : input.dataset.nidPlaceholder;
      box.classList.toggle('is-foreign', foreign);
      const field = input.closest('.field');
      if (field && foreign && input.value.trim() === '') {
        field.classList.remove('invalid');
        const msg = field.querySelector(':scope > .field-msg');
        if (msg) msg.textContent = '';
        input.setAttribute('aria-invalid', 'false');
      }
    };
    tick.addEventListener('change', sync);
    sync();
  });

  /* rc.20: booking link QR code (Pro «لینک و QR»), drawn locally — the library is loaded only where needed. */
  const qrNodes = document.querySelectorAll('[data-qr]');
  if (qrNodes.length) {
    const js = document.createElement('script');
    js.src = 'assets/vendor/qrcode.js';
    js.onload = () => {
      qrNodes.forEach((node) => {
        const q = window.qrcode(0, 'M');
        q.addData(node.dataset.qr);
        q.make();
        node.innerHTML = q.createSvgTag({ cellSize: +(node.dataset.cell || 6), margin: 2, scalable: true });
        const svg = node.querySelector('svg');
        if (svg) {
          svg.setAttribute('role', 'img');
          svg.setAttribute('aria-label', 'QR نوبت‌دهی');
        }
      });
    };
    document.head.append(js);
  }
  document.querySelectorAll('[data-print-page]').forEach((b) => {
    b.hidden = false;
    b.addEventListener('click', () => window.print());
  });
  document.querySelectorAll('[data-copy-link]').forEach((b) => {
    b.hidden = false;
    b.addEventListener('click', () => {
      const v = b.dataset.copyLink;
      (navigator.clipboard ? navigator.clipboard.writeText(v) : Promise.reject()).then(
        () => { b.textContent = 'کپی شد'; },
        () => { window.prompt('لینک را کپی کنید:', v); },
      );
    });
  });
})();

// rc.22: focus the plan nearest the center after a rail swipe.
document.querySelectorAll('.plan-rail').forEach(rail => {
  let timer;
  rail.addEventListener('scroll', () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
      const box = rail.getBoundingClientRect(), center = (box.left + box.right) / 2;
      const cards = Array.from(rail.querySelectorAll('.plan-card'));
      const nearest = cards.sort((a,b) => Math.abs((a.getBoundingClientRect().left+a.getBoundingClientRect().right)/2-center)-Math.abs((b.getBoundingClientRect().left+b.getBoundingClientRect().right)/2-center))[0];
      if (nearest) { const radio = nearest.querySelector('input[type=radio]'); if(radio) radio.checked = true; }
    }, 180);
  }, {passive:true});
});

/*
 * 1.0.9 panels (doctor, center, patient): own install banner with a close button, and the notification
 * permission asked on the first tap. Android/desktop Chrome get the browser's install prompt; iPhone gets
 * the Safari «Add to Home Screen» hint; other Android browsers the menu hint. Closing hides it for 14 days.
 */
(() => {
  const banner = document.querySelector('[data-pwa-banner]');
  if (!banner) return;
  const store = {
    get: (k) => {
      try {
        return localStorage.getItem(k);
      } catch (e) {
        return null;
      }
    },
    set: (k, v) => {
      try {
        localStorage.setItem(k, v);
      } catch (e) {}
    },
  };
  const within = (k, days) => Date.now() - (+store.get(k) || 0) < days * 864e5;
  const root = document.documentElement;
  const ua = navigator.userAgent;
  const ios = /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const android = /android/i.test(ua);
  const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  const dismissKey = 'pwa-dismissed-' + banner.dataset.pwaBanner;
  const show = (mode) => {
    banner.dataset.mode = mode;
    banner.hidden = false;
    root.style.setProperty('--pwa-h', banner.offsetHeight + 12 + 'px');
  };
  const hide = () => {
    banner.hidden = true;
    root.style.removeProperty('--pwa-h');
  };

  if (!standalone && !within(dismissKey, 14)) {
    let deferred = null;
    window.addEventListener('beforeinstallprompt', (e) => {
      e.preventDefault();
      deferred = e;
      show('prompt');
    });
    if (ios) setTimeout(() => show('ios'), 1200);
    else if (android) setTimeout(() => deferred || show('menu'), 4000);
    banner.querySelector('[data-pwa-install]').addEventListener('click', () => {
      if (!deferred) return;
      deferred.prompt();
      deferred.userChoice
        .then((c) => c.outcome === 'accepted' && hide())
        .finally(() => (deferred = null));
    });
    banner.querySelector('[data-pwa-close]').addEventListener('click', () => {
      store.set(dismissKey, String(Date.now()));
      hide();
    });
    window.addEventListener('appinstalled', hide);
  }

  // Notifications: asked on the first plain tap (a user gesture, which iOS and Chrome require), at most
  // every 3 days while undecided. Taps on links and submit buttons are left alone so they never get lost.
  const pushable = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  if (!pushable || Notification.permission !== 'default' || within('push-asked', 3)) return;
  const keyBytes = (b64) => Uint8Array.from(atob(b64.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (b64.length % 4)) % 4)), (c) => c.charCodeAt(0));
  const subscribe = () =>
    fetch('api.php?route=push/key', { credentials: 'same-origin' })
      .then((r) => r.json())
      .then((key) => {
        if (!key.data || !key.data.available) return null;
        return navigator.serviceWorker.ready
          .then((reg) => reg.pushManager.getSubscription().then((s) => s || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(key.data.key) })))
          .then((sub) =>
            fetch('api.php?route=session', { credentials: 'same-origin' })
              .then((r) => r.json())
              .then((s) =>
                fetch('api.php?route=push/subscribe', {
                  method: 'POST',
                  credentials: 'same-origin',
                  headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': s.data.csrf },
                  body: JSON.stringify(Object.assign(sub.toJSON(), { consent: true })),
                }),
              ),
          );
      });
  const ask = (e) => {
    // The location request (patients without a city) goes first; notifications wait for a later tap.
    if (e.permissionTaken || window.geoAskPending || e.target.closest('a[href], button[type=submit], form button:not([type]), [data-pwa-banner]')) return;
    e.permissionTaken = true;
    document.removeEventListener('click', ask, true);
    store.set('push-asked', String(Date.now()));
    Notification.requestPermission()
      .then((p) => p === 'granted' && subscribe())
      .catch(() => {});
  };
  document.addEventListener('click', ask, true);
})();

/*
 * 1.0.10 patients: with no city chosen yet, the first plain tap asks for the location and picks the nearest
 * province and city (geo/locate). Asked at most once a week while undecided; a refusal changes nothing.
 * Only one permission is asked per tap, so it never stacks with the notification request.
 */
(() => {
  if (!document.querySelector('[data-geo-auto]') || !('geolocation' in navigator) || !window.isSecureContext) return;
  let last = 0;
  try {
    last = +localStorage.getItem('geo-asked') || 0;
  } catch (e) {}
  if (Date.now() - last < 7 * 864e5) return;
  const say = (text) => {
    const t = document.createElement('p');
    t.className = 'toast show';
    t.setAttribute('role', 'status');
    t.textContent = text;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 4500);
  };
  const ask = (e) => {
    if (e.permissionTaken || e.target.closest('a[href], button[type=submit], form button:not([type]), [data-pwa-banner]')) return;
    e.permissionTaken = true;
    window.geoAskPending = false;
    document.removeEventListener('click', ask, true);
    try {
      localStorage.setItem('geo-asked', String(Date.now()));
    } catch (err) {}
    navigator.geolocation.getCurrentPosition(
      (pos) =>
        fetch('api.php?route=session', { credentials: 'same-origin' })
          .then((r) => r.json())
          .then((s) =>
            fetch('api.php?route=geo/locate', {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': s.data.csrf },
              body: JSON.stringify({ lat: +pos.coords.latitude.toFixed(4), lng: +pos.coords.longitude.toFixed(4) }),
            }),
          )
          .then((r) => r.json().then((j) => (r.ok ? j.data : Promise.reject(j))))
          .then((c) => {
            document.querySelectorAll('.city-chip span').forEach((n) => (n.textContent = c.name));
            say('شهر شما: ' + c.name + '، استان ' + c.province + ' — از «انتخاب شهر» قابل تغییر است.');
          })
          .catch(() => {}),
      () => {},
      { enableHighAccuracy: false, timeout: 15000, maximumAge: 600000 },
    );
  };
  window.geoAskPending = true;
  document.addEventListener('click', ask, true);
})();

/* 1.0.13 homepage: «بیشتر» opens every specialty in a bottom sheet (native <dialog>; Esc and backdrop close it). */
(() => {
  const sheet = document.querySelector('[data-spec-sheet]');
  const more = document.querySelector('[data-spec-more]');
  if (!sheet || !more || typeof sheet.showModal !== 'function') return;
  more.addEventListener('click', () => sheet.showModal());
  sheet.querySelector('[data-spec-close]').addEventListener('click', () => sheet.close());
  sheet.addEventListener('click', (e) => {
    if (e.target === sheet) sheet.close();
  });
  sheet.addEventListener('close', () => more.focus());
})();
