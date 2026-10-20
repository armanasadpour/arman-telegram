'use strict';
/*
 * Doctor profile and booking steps: free times of another day without reloading, sticky tab highlight,
 * field validation with Persian messages, patient choice, hold countdown on the payment step,
 * share / copy on the receipt. Every page also works without this file.
 */
(() => {
  const toFa = (s) => String(s).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);
  const toEn = (s) => String(s).replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const toast = (text) => {
    let t = document.querySelector('.toast');
    if (!t) {
      t = document.createElement('p');
      t.className = 'toast';
      t.setAttribute('role', 'status');
      document.body.appendChild(t);
    }
    t.textContent = text;
    t.classList.add('show');
    clearTimeout(t._h);
    t._h = setTimeout(() => t.classList.remove('show'), 2400);
  };

  /* ---------- Time picker: another day in place ---------- */
  const page = document.querySelector('[data-times-page]');
  if (page) {
    const strip = page.querySelector('[data-day-strip]');
    const select = (date) => {
      strip?.querySelectorAll('.day-card').forEach((c) => {
        const on = c.dataset.date === date;
        c.classList.toggle('is-selected', on);
        if (on) {
          c.setAttribute('aria-current', 'date');
          c.scrollIntoView({ inline: 'center', block: 'nearest', behavior: reduce ? 'auto' : 'smooth' });
        } else c.removeAttribute('aria-current');
      });
    };
    const current = strip?.querySelector('.is-selected');
    if (current) current.scrollIntoView({ inline: 'center', block: 'nearest' });
    let pending = null;
    page.addEventListener('click', async (e) => {
      const a = e.target.closest('a[data-day-link]');
      if (!a || e.ctrlKey || e.metaKey || e.button !== 0) return;
      e.preventDefault();
      const region = page.querySelector('[data-times-region]');
      select(a.dataset.date);
      region.setAttribute('aria-busy', 'true');
      region.classList.add('is-loading');
      if (pending) pending.abort();
      pending = new AbortController();
      try {
        const u = new URL(a.href, document.baseURI);
        u.searchParams.set('partial', 'times');
        const res = await fetch(u, { credentials: 'same-origin', signal: pending.signal });
        if (!res.ok) throw new Error();
        const box = document.createElement('div');
        box.innerHTML = await res.text();
        const fresh = box.querySelector('[data-times-region]');
        if (!fresh) throw new Error();
        region.replaceWith(fresh);
        history.replaceState(null, '', a.href);
        fresh.querySelector('h2')?.setAttribute('tabindex', '-1');
        if (e.detail === 0) fresh.querySelector('h2')?.focus();
      } catch (err) {
        if (err.name !== 'AbortError') location.assign(a.href);
      }
    });
  }

  /* ---------- Long clinic notes: first lines with «ادامه» ---------- */
  document.querySelectorAll('[data-clamp]').forEach((box) => {
    const body = box.querySelector('[data-clamp-body]');
    if (!body || body.scrollHeight <= 110) return;
    box.classList.add('clamped');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'clamp-toggle';
    btn.setAttribute('aria-expanded', 'false');
    btn.textContent = 'ادامه';
    btn.addEventListener('click', () => {
      const open = box.classList.toggle('clamped') === false;
      btn.setAttribute('aria-expanded', String(open));
      btn.textContent = open ? 'بستن' : 'ادامه';
    });
    box.appendChild(btn);
  });

  /* ---------- Profile tabs follow the scroll ---------- */
  const tabs = document.querySelector('[data-dp-tabs]');
  if (tabs && 'IntersectionObserver' in window) {
    const links = [...tabs.querySelectorAll('a')];
    const sections = links.map((a) => document.querySelector(a.getAttribute('href'))).filter(Boolean);
    const io = new IntersectionObserver(
      (entries) => {
        entries.forEach((en) => {
          if (!en.isIntersecting) return;
          links.forEach((a) => {
            const on = a.getAttribute('href') === '#' + en.target.id;
            a.classList.toggle('current', on);
            if (on) a.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'auto' });
          });
        });
      },
      { rootMargin: '-45% 0px -50% 0px' },
    );
    sections.forEach((s) => io.observe(s));
  }

  /* ---------- Share and copy ---------- */
  document.querySelectorAll('[data-share]').forEach((b) => {
    b.hidden = false;
    b.addEventListener('click', async () => {
      const data = { title: b.dataset.shareTitle || document.title, text: b.dataset.shareText || '', url: b.dataset.shareUrl || '' };
      if (!data.url) delete data.url;
      try {
        if (navigator.share) {
          await navigator.share(data);
          return;
        }
        await navigator.clipboard.writeText([data.text, data.url].filter(Boolean).join('\n'));
        toast('متن در حافظه کپی شد.');
      } catch (e) {
        if (e && e.name !== 'AbortError') toast('اشتراک‌گذاری ممکن نشد.');
      }
    });
  });
  document.querySelectorAll('[data-copy]').forEach((b) => {
    const src = b.parentElement.querySelector('[data-copy-text]');
    if (!src || !navigator.clipboard) return;
    b.hidden = false;
    b.addEventListener('click', () => navigator.clipboard.writeText(src.dataset.copyText).then(() => toast('کد رهگیری کپی شد.'), () => toast('کپی ممکن نشد.')));
  });

  /* ---------- Open a collapsed section from a link ---------- */
  document.querySelectorAll('[data-open-details]').forEach((a) =>
    a.addEventListener('click', (e) => {
      const d = document.getElementById(a.dataset.openDetails);
      if (!d) return;
      e.preventDefault();
      d.open = true;
      d.scrollIntoView({ block: 'start', behavior: reduce ? 'auto' : 'smooth' });
      d.querySelector('input')?.focus({ preventScroll: true });
    }),
  );

  /* ---------- Patient choice ---------- */
  document.querySelectorAll('[data-who-form]').forEach((form) => {
    const btn = form.querySelector('[data-who-continue]');
    const sync = () => {
      btn.disabled = !form.querySelector('input[name=family_member_id]:checked');
    };
    form.addEventListener('change', sync);
    sync();
  });

  /* ---------- Field validation with clear Persian messages ---------- */
  const nationalOk = (v) => {
    if (!/^\d{10}$/.test(v) || /^(\d)\1{9}$/.test(v)) return false;
    let s = 0;
    for (let i = 0; i < 9; i++) s += +v[i] * (10 - i);
    const r = s % 11;
    return (r < 2 && +v[9] === r) || (r >= 2 && +v[9] === 11 - r);
  };
  const yearNow = (() => {
    try {
      return +toEn(new Intl.DateTimeFormat('fa-IR-u-ca-persian', { year: 'numeric' }).format(new Date())).replace(/\D/g, '');
    } catch (e) {
      return 1405;
    }
  })();
  const message = (el) => {
    const v = toEn(el.value || '').trim();
    const form = el.form;
    if (el.type === 'radio') {
      return el.required && !form.querySelector(`input[name="${el.name}"]:checked`) ? 'یک گزینه را انتخاب کنید.' : '';
    }
    const jd = el.closest('[data-jdate]');
    if (jd) {
      // Day, month and year are checked together so the three selects show one message.
      const [d, m, y] = ['birth_d', 'birth_m', 'birth_y'].map((n) => +(jd.querySelector(`[name=${n}]`)?.value || 0));
      if (!d && !m && !y) return el.required ? 'روز، ماه و سال تولد را انتخاب کنید.' : '';
      if (!d || !m || !y) return 'روز، ماه و سال تولد را کامل انتخاب کنید.';
      if (m >= 7 && d === 31) return 'ماه‌های مهر تا اسفند ۳۱ روز ندارند.';
      return '';
    }
    if (el.required && v === '') return el.tagName === 'SELECT' ? 'یک گزینه را انتخاب کنید.' : 'این مورد را وارد کنید.';
    if (v === '') return '';
    if (el.name === 'first_name' || el.name === 'last_name') {
      return /^[\p{L}\p{M}][\p{L}\p{M} ‌'-]{1,49}$/u.test(v) ? '' : 'فقط حروف، دست‌کم دو حرف.';
    }
    if (el.name === 'birth_year') {
      return /^\d{4}$/.test(v) && +v >= yearNow - 120 && +v <= yearNow ? '' : 'سال تولد را شمسی و چهار رقمی وارد کنید (مثلاً ۱۳۶۵).';
    }
    if (el.name === 'national_id') {
      const foreign = form.querySelector('[name=foreign]')?.checked;
      const x = v.replace(/[\s-]/g, '').toUpperCase();
      if (foreign) return /^[A-Z0-9]{6,20}$/.test(x) ? '' : 'شماره گذرنامه یا شناسه اتباع: ۶ تا ۲۰ حرف لاتین یا رقم.';
      return nationalOk(x) ? '' : 'کد ملی معتبر نیست؛ ۱۰ رقم بدون خط تیره.';
    }
    if (el.name === 'birth_date') return /^1[34]\d\d\/\d{1,2}\/\d{1,2}$/.test(v) ? '' : 'تاریخ را مثل ۱۳۹۵/۰۴/۱۲ وارد کنید.';
    if (el.name === 'name') return v.length >= 3 ? '' : 'نام کامل را وارد کنید.';
    return '';
  };
  const show = (el, text) => {
    const box = el.closest('.field') || el.closest('label') || el.parentElement;
    box.classList.toggle('invalid', !!text);
    let m = box.querySelector(':scope > .field-msg');
    if (text && !m) {
      m = document.createElement('small');
      m.className = 'field-msg';
      m.setAttribute('role', 'alert');
      box.appendChild(m);
    }
    if (m) m.textContent = text;
    if (el.type !== 'radio') el.setAttribute('aria-invalid', text ? 'true' : 'false');
  };
  // Jalali birth date: months 7–12 have no day 31.
  document.querySelectorAll('[data-jdate]').forEach((jd) => {
    const day = jd.querySelector('[name=birth_d]');
    const month = jd.querySelector('[name=birth_m]');
    if (!day || !month) return;
    const sync = () => {
      const o31 = day.querySelector('option[value="31"]');
      if (o31) o31.disabled = +month.value >= 7;
      if (+month.value >= 7 && day.value === '31') day.value = '30';
    };
    month.addEventListener('change', sync);
    sync();
  });
  document.querySelectorAll('form[data-validate]').forEach((form) => {
    const fields = () => [...form.querySelectorAll('input:not([type=hidden]):not([type=checkbox]),select')];
    form.addEventListener('submit', (e) => {
      let first = null;
      fields().forEach((el) => {
        const t = message(el);
        show(el, t);
        if (t && !first) first = el;
      });
      if (first) {
        e.preventDefault();
        first.focus();
      }
    });
    form.addEventListener('input', (e) => {
      if (e.target.closest('.invalid')) show(e.target, message(e.target));
    });
    form.addEventListener('change', (e) => {
      if (e.target.closest('[data-jdate]')) {
        const jd = e.target.closest('[data-jdate]');
        const sel = jd.querySelector('[name=birth_y]');
        if (jd.classList.contains('invalid') || [...jd.querySelectorAll('select')].every((x) => x.value)) show(sel, message(sel));
      } else if (e.target.type === 'radio' || e.target.tagName === 'SELECT') show(e.target, message(e.target));
    });
  });

  /* ---------- Payment step: time left on the held appointment ---------- */
  const bar = document.querySelector('[data-hold-bar]');
  if (bar) {
    const out = bar.querySelector('[data-hold-left]');
    const skew = Date.now() / 1000 - +bar.dataset.now;
    const end = +bar.dataset.expires + skew;
    const pay = document.querySelector('[data-pay-bar] button');
    const tick = () => {
      const left = Math.max(0, Math.round(end - Date.now() / 1000));
      out.textContent = toFa(String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0'));
      bar.classList.toggle('urgent', left <= 60);
      if (left <= 0) {
        clearInterval(timer);
        bar.classList.add('expired');
        bar.querySelector('span').textContent = 'مهلت رزرو موقت تمام شد؛ صفحه به‌روز می‌شود.';
        if (pay) pay.disabled = true;
        setTimeout(() => location.reload(), 2500);
      }
    };
    const timer = setInterval(tick, 1000);
    tick();
  }
})();
