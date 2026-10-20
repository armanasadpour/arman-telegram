/*
 * Jalali (Persian) date picker for every field marked data-jdate (date) or data-jdate="time" (date and time).
 * The field stays a plain text input in the same «۱۴۰۵/۰۷/۲۱» or «۱۴۰۵/۰۷/۲۱ ۱۴:۳۰» format the server parses,
 * so typing still works; the calendar opens on focus or tap and writes the chosen value back.
 * No dependencies (styles: .jdp in style.css, as the CSP allows no inline <style>); works for fields added later. Calendar arithmetic: jalaali-js (MIT).
 */
(() => {
  const div = (a, b) => ~~(a / b);
  const mod = (a, b) => a - ~~(a / b) * b;
  const BREAKS = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];
  const jalCal = (jy) => {
    const gy = jy + 621;
    let leapJ = -14, jp = BREAKS[0], jump = 0, n, i;
    for (i = 1; i < BREAKS.length; i++) {
      const jm = BREAKS[i];
      jump = jm - jp;
      if (jy < jm) break;
      leapJ += div(jump, 33) * 8 + div(mod(jump, 33), 4);
      jp = jm;
    }
    n = jy - jp;
    leapJ += div(n, 33) * 8 + div(mod(n, 33) + 3, 4);
    if (mod(jump, 33) === 4 && jump - n === 4) leapJ += 1;
    const leapG = div(gy, 4) - div((div(gy, 100) + 1) * 3, 4) - 150;
    if (jump - n < 6) n = n - jump + div(jump + 4, 33) * 33;
    let leap = mod(mod(n + 1, 33) - 1, 4);
    if (leap === -1) leap = 4;
    return { leap, gy, march: 20 + leapJ - leapG };
  };
  const g2d = (gy, gm, gd) => {
    const d = div((gy + div(gm - 8, 6) + 100100) * 1461, 4) + div(153 * mod(gm + 9, 12) + 2, 5) + gd - 34840408;
    return d - div(div(gy + 100100 + div(gm - 8, 6), 100) * 3, 4) + 752;
  };
  const d2g = (jdn) => {
    let j = 4 * jdn + 139361631;
    j += div(div(4 * jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
    const i = div(mod(j, 1461), 4) * 5 + 308;
    const gm = mod(div(i, 153), 12) + 1;
    return { gy: div(j, 1461) - 100100 + div(8 - gm, 6), gm, gd: div(mod(i, 153), 5) + 1 };
  };
  const j2d = (jy, jm, jd) => g2d(jalCal(jy).gy, 3, jalCal(jy).march) + (jm - 1) * 31 - div(jm, 7) * (jm - 7) + jd - 1;
  const d2j = (jdn) => {
    const gy = d2g(jdn).gy;
    let jy = gy - 621;
    const r = jalCal(jy);
    let k = jdn - g2d(gy, 3, r.march);
    if (k >= 0) {
      if (k <= 185) return { jy, jm: 1 + div(k, 31), jd: mod(k, 31) + 1 };
      k -= 186;
    } else {
      jy -= 1;
      k += 179;
      if (r.leap === 1) k += 1;
    }
    return { jy, jm: 7 + div(k, 30), jd: mod(k, 30) + 1 };
  };
  const monthLength = (jy, jm) => (jm <= 6 ? 31 : jm <= 11 ? 30 : jalCal(jy).leap === 0 ? 30 : 29);
  // Saturday = 0 … Friday = 6
  const weekday = (jy, jm, jd) => (j2d(jy, jm, jd) + 2) % 7;

  const MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
  const DAYS = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];
  const fa = (s) => String(s).replace(/[0-9]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);
  const en = (s) => String(s).replace(/[۰-۹]/g, (d) => '۰۱۲۳۴۵۶۷۸۹'.indexOf(d)).replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
  const pad = (n) => String(n).padStart(2, '0');
  const today = () => {
    const t = new Date();
    return d2j(g2d(t.getFullYear(), t.getMonth() + 1, t.getDate()));
  };
  const parse = (value) => {
    const m = en(value || '').trim().match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})(?:\s+(\d{1,2}):(\d{2}))?/);
    if (!m) return null;
    const v = { jy: +m[1], jm: +m[2], jd: +m[3], h: m[4] != null ? +m[4] : null, i: m[5] != null ? +m[5] : null };
    return v.jm >= 1 && v.jm <= 12 && v.jd >= 1 && v.jd <= monthLength(v.jy, v.jm) ? v : null;
  };


  let box = null, input = null, view = null, picked = null, withTime = false;

  const close = () => {
    if (box) box.hidden = true;
    input = null;
  };
  const write = (v) => {
    if (!input) return;
    let s = v ? `${v.jy}/${pad(v.jm)}/${pad(v.jd)}` : '';
    if (v && withTime) s += ` ${pad(v.h ?? 9)}:${pad(v.i ?? 0)}`;
    input.value = fa(s);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  };
  const place = () => {
    const r = input.getBoundingClientRect();
    const w = box.offsetWidth, h = box.offsetHeight;
    let left = r.right + window.scrollX - w;
    left = Math.max(window.scrollX + 8, Math.min(left, window.scrollX + document.documentElement.clientWidth - w - 8));
    let top = r.bottom + window.scrollY + 6;
    if (r.bottom + h + 6 > window.innerHeight && r.top - h - 6 > 0) top = r.top + window.scrollY - h - 6;
    box.style.left = left + 'px';
    box.style.top = top + 'px';
  };
  const option = (value, label, sel) => `<option value="${value}"${sel ? ' selected' : ''}>${label}</option>`;
  const render = () => {
    const t = today();
    const len = monthLength(view.jy, view.jm);
    const first = weekday(view.jy, view.jm, 1);
    let years = '';
    for (let y = Math.min(view.jy, t.jy) - 100; y <= Math.max(view.jy, t.jy) + 10; y++) years += option(y, fa(y), y === view.jy);
    let html = `<div class="jdp-head">
      <button type="button" class="jdp-nav" data-jdp="prev" aria-label="ماه قبل">›</button>
      <select data-jdp="month" aria-label="ماه">${MONTHS.map((m, i) => option(i + 1, m, i + 1 === view.jm)).join('')}</select>
      <select data-jdp="year" aria-label="سال">${years}</select>
      <button type="button" class="jdp-nav" data-jdp="next" aria-label="ماه بعد">‹</button>
    </div><div class="jdp-grid">${DAYS.map((d) => `<span class="jdp-wd">${d}</span>`).join('')}`;
    for (let i = 0; i < first; i++) html += '<span></span>';
    for (let d = 1; d <= len; d++) {
      const cls = ['jdp-day'];
      if ((first + d - 1) % 7 === 6) cls.push('fri');
      if (t.jy === view.jy && t.jm === view.jm && t.jd === d) cls.push('today');
      if (picked && picked.jy === view.jy && picked.jm === view.jm && picked.jd === d) cls.push('sel');
      html += `<button type="button" class="${cls.join(' ')}" data-jdp-day="${d}" aria-label="${fa(d)} ${MONTHS[view.jm - 1]} ${fa(view.jy)}">${fa(d)}</button>`;
    }
    html += '</div>';
    if (withTime) {
      const h = picked?.h ?? 9, mi = picked?.i ?? 0;
      let hours = '', mins = '';
      for (let i = 0; i < 24; i++) hours += option(i, fa(pad(i)), i === h);
      for (let i = 0; i < 60; i += 5) mins += option(i, fa(pad(i)), i === mi);
      if (mi % 5) mins += option(mi, fa(pad(mi)), true);
      html += `<div class="jdp-time"><select data-jdp="hour" aria-label="ساعت">${hours}</select><b>:</b><select data-jdp="minute" aria-label="دقیقه">${mins}</select></div>`;
    }
    html += `<div class="jdp-foot"><span><button type="button" data-jdp="today">امروز</button>${input.required ? '' : ' <button type="button" data-jdp="clear">پاک کردن</button>'}</span>
      ${withTime ? '<button type="button" class="jdp-ok" data-jdp="ok">تأیید</button>' : '<button type="button" data-jdp="close">بستن</button>'}</div>`;
    box.innerHTML = html;
    place();
  };
  const build = () => {
    box = document.createElement('div');
    box.className = 'jdp';
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-label', 'انتخاب تاریخ شمسی');
    box.hidden = true;
    document.body.appendChild(box);
    box.addEventListener('mousedown', (e) => {
      if (!e.target.closest('select')) e.preventDefault(); // keep focus on the field
    });
    box.addEventListener('click', (e) => {
      // Rendering replaces the clicked button; keep the page-level «outside click» check from seeing it.
      e.stopPropagation();
      const day = e.target.closest('[data-jdp-day]');
      const act = e.target.closest('[data-jdp]')?.dataset.jdp;
      if (day) {
        picked = { ...(picked || {}), jy: view.jy, jm: view.jm, jd: +day.dataset.jdpDay };
        if (withTime) {
          write(picked);
          render();
        } else {
          write(picked);
          close();
        }
      } else if (act === 'prev' || act === 'next') {
        view.jm += act === 'next' ? 1 : -1;
        if (view.jm > 12) (view.jm = 1), view.jy++;
        if (view.jm < 1) (view.jm = 12), view.jy--;
        render();
      } else if (act === 'today') {
        picked = { ...(picked || {}), ...today() };
        write(picked);
        if (withTime) {
          view = { jy: picked.jy, jm: picked.jm };
          render();
        } else close();
      } else if (act === 'clear') {
        picked = null;
        write(null);
        close();
      } else if (act === 'close' || act === 'ok') {
        if (act === 'ok' && picked) write(picked);
        close();
      }
    });
    box.addEventListener('change', (e) => {
      const act = e.target.dataset.jdp;
      if (act === 'month' || act === 'year') {
        view[act === 'month' ? 'jm' : 'jy'] = +e.target.value;
        render();
      } else if (act === 'hour' || act === 'minute') {
        picked = picked || { ...today() };
        picked[act === 'hour' ? 'h' : 'i'] = +e.target.value;
        write(picked);
      }
    });
  };
  const open = (el) => {
    if (input === el && !box.hidden) return;
    input = el;
    withTime = el.dataset.jdate === 'time';
    picked = parse(el.value);
    const base = picked || today();
    view = { jy: base.jy, jm: base.jm };
    box.hidden = false;
    render();
  };

  const ready = () => {
    build();
    // On touch screens the keyboard would cover the calendar; there the calendar is the way to enter a date.
    const touch = window.matchMedia?.('(pointer: coarse)').matches;
    document.querySelectorAll('input[data-jdate]').forEach((el) => {
      el.setAttribute('autocomplete', 'off');
      if (touch) el.setAttribute('inputmode', 'none');
      if (!el.placeholder) el.placeholder = el.dataset.jdate === 'time' ? '۱۴۰۵/۰۷/۲۱ ۱۴:۳۰' : '۱۴۰۵/۰۷/۲۱';
    });
    document.addEventListener('focusin', (e) => {
      if (e.target.matches?.('input[data-jdate]')) open(e.target);
      else if (input && !box.contains(e.target)) close();
    });
    document.addEventListener('click', (e) => {
      if (e.target.matches?.('input[data-jdate]')) open(e.target);
      else if (input && !box.contains(e.target)) close();
    });
    document.addEventListener('input', (e) => {
      if (e.target === input && e.isTrusted) {
        const v = parse(input.value);
        if (v) {
          picked = v;
          view = { jy: v.jy, jm: v.jm };
          render();
        }
      }
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && input) close();
      if (e.key === 'Tab' && input && !box.contains(e.target)) close();
    });
    window.addEventListener('resize', () => input && place());
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
  else ready();

  window.jdatePicker = { d2j, j2d, d2g, g2d, monthLength };
})();
