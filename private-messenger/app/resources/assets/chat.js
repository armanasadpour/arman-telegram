// Chat UI (main thread). Holds no keys: all cryptography happens in e2ee-worker.js.
// Every piece of user content is inserted with textContent / attributes — never as HTML.
const root = document.getElementById('app');
const worker = new Worker(document.body.dataset.worker, { type: 'module' });
const fa = new Intl.DateTimeFormat('fa-IR', { hour: '2-digit', minute: '2-digit' });
const faDay = new Intl.DateTimeFormat('fa-IR-u-ca-persian', { year: 'numeric', month: 'long', day: 'numeric' });
const faNum = new Intl.NumberFormat('fa-IR');
const EMOJI = ['👍', '❤️', '😂', '😮', '😢', '🙏'];
let seq = 0, me = null, convs = [], current = null, refreshTimer = null, readTimer = null, typingTimer = null;
let reply = null, editing = null;
const pending = new Map(), byMid = new Map(), urls = new Map();

function el(tag, attrs = {}, ...children) {
  const e = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'class') e.className = v;
    else if (k === 'text') e.textContent = v;
    else if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
    else e.setAttribute(k, v === true ? '' : v);
  }
  for (const c of children) if (c !== null && c !== undefined && c !== false) e.append(c);
  return e;
}
const call = (cmd, args) => new Promise((resolve, reject) => { const id = ++seq; pending.set(id, { resolve, reject }); worker.postMessage({ id, cmd, args }); });
const size = (n) => n >= 1073741824 ? faNum.format((n / 1073741824).toFixed(1)) + ' گیگابایت' : n >= 1048576 ? faNum.format((n / 1048576).toFixed(1)) + ' مگابایت' : faNum.format(Math.max(1, Math.round(n / 1024))) + ' کیلوبایت';
const ERR = {
  key_changed: 'کلید امنیتی مخاطب تغییر کرده است', file_expired: 'فایل از سرور حذف شده است (بیش از ۷ روز گذشته)', insufficient_storage: 'فضای سرور کافی نیست؛ با مدیر تماس بگیرید',
  file_too_large: 'حجم فایل از سقف تعیین‌شده توسط مدیر بیشتر است', file_missing: 'برای ارسال دوباره، فایل را دوباره انتخاب کنید', attachment_not_found: 'فایل دیگر روی سرور نیست',
  peer_has_no_devices: 'مخاطب هنوز وارد برنامه نشده است', target_not_found: 'پیام پیدا نشد',
};
const errText = (e) => ERR[e] || ERR[e?.message] || 'خطا: ' + (e?.message || e);
function toast(text) {
  const t = el('div', { class: 'c-toast', role: 'status', text });
  document.body.append(t); setTimeout(() => t.remove(), 4000);
}

worker.onmessage = ({ data }) => {
  if (data.id) { const p = pending.get(data.id); pending.delete(data.id); data.ok ? p.resolve(data.result) : p.reject(new Error(data.error)); return; }
  const { event, data: d } = data;
  if (event === 'message') { if (current && d.cid === current.id) { upsertMessage(d); if (d.dir === 'in') scheduleRead(); } scheduleRefresh(); }
  if (event === 'status') { const m = byMid.get(d.mid); if (m) { m.status = d.status; upsertMessage(m, false); } }
  if (event === 'removed') { byMid.delete(d.mid); document.querySelector(`[data-mid="${CSS.escape(d.mid)}"]`)?.remove(); }
  if (event === 'progress') { const b = document.querySelector(`[data-mid="${CSS.escape(d.mid)}"] progress`); if (b) { b.max = d.total; b.value = d.done; } }
  if (event === 'typing' && current?.id === d.cid) showTyping();
  if (event === 'key_changed' && current?.peer?.id === d.user_id) renderBanner();
  if (event === 'conversations_changed') scheduleRefresh();
  if (event === 'state') handleState(d);
};
worker.onerror = () => status('خطا در بارگذاری موتور رمزنگاری. صفحه را دوباره بارگذاری کنید.');

// ------------------------------------------------------------------ status screens
function status(text, ...actions) { root.replaceChildren(el('div', { class: 'chat-status' }, el('div', { class: 'panel' }, el('p', { text }), ...actions))); }
const resetButton = () => el('button', { class: 'btn', text: 'ساخت کلیدهای جدید روی این دستگاه', onclick: async () => {
  if (!confirm('با این کار دستگاه قبلی شما از کار می‌افتد و مخاطبان پیام «کلید امنیتی تغییر کرد» می‌بینند. پیام‌های قبلی روی این دستگاه نمایش داده نمی‌شوند. ادامه می‌دهید؟')) return;
  status('در حال ساخت کلیدهای جدید روی همین دستگاه…');
  try { handleState(await call('reset_identity')); } catch (e) { status('خطا: ' + e.message); }
} });
function handleState(s) {
  switch (s.state) {
    case 'ready': me = s.me; renderApp(); return;
    case 'unsupported': return status('این مرورگر امکانات لازم برای رمزنگاری سرتاسری را ندارد. از نسخه جدید Chrome، Firefox یا Safari استفاده کنید.');
    case 'locked': return status('برنامه در یک تب یا پنجره دیگر باز است. برای جلوگیری از خرابی کلیدها فقط یک تب در هر زمان مجاز است. آن تب را ببندید و این صفحه را دوباره بارگذاری کنید.',
      el('button', { class: 'btn secondary', text: 'بارگذاری دوباره', onclick: () => location.reload() }));
    case 'identity_elsewhere': return status('حساب شما قبلاً روی دستگاه یا مرورگر دیگری فعال شده است و کلیدهای رمزنگاری آنجا هستند. اتصال چند دستگاه به یک حساب در مرحله بعد (فاز ۶) اضافه می‌شود. اگر دیگر به آن دستگاه دسترسی ندارید، می‌توانید کلیدهای جدید روی همین دستگاه بسازید.', resetButton());
    case 'revoked': return status('کلیدهای رمزنگاری این دستگاه دیگر معتبر نیستند (هویت روی دستگاه دیگری بازنشانی شده یا مدیر آن را لغو کرده است).', resetButton());
    case 'logged_out': location.reload(); return;
    default: status('وضعیت ناشناخته');
  }
}

// ------------------------------------------------------------------ layout
let listEl, mainEl, searchEl;
function renderApp() {
  listEl = el('div', { class: 'c-list', role: 'list' });
  mainEl = el('main', { class: 'c-main' }, el('div', { class: 'c-empty', text: 'یک گفتگو را انتخاب کنید یا گفتگوی جدیدی شروع کنید.' }));
  searchEl = el('input', { type: 'search', class: 'c-search', placeholder: 'جست‌وجو در پیام‌های همین دستگاه', 'aria-label': 'جست‌وجوی محلی', oninput: onSearch });
  root.replaceChildren(el('div', { class: 'c-app' },
    el('aside', { class: 'c-side' },
      el('div', { class: 'c-side-head' }, el('h1', { text: 'گفتگوها' }), el('button', { class: 'btn sm', text: 'گفتگوی جدید', onclick: openDirectory })),
      el('div', { class: 'c-search-wrap' }, searchEl),
      listEl,
      el('p', { class: 'c-note', text: '🔒 پیام‌ها فقط روی همین دستگاه و به‌صورت رمزنگاری‌شده نگهداری می‌شوند. پاک کردن داده‌های مرورگر یعنی از دست رفتن پیام‌های قبلی این دستگاه.' })),
    mainEl));
  refreshList();
}
function scheduleRefresh() { clearTimeout(refreshTimer); refreshTimer = setTimeout(refreshList, 300); }
const KIND = { image: '📷 تصویر', video: '🎬 ویدیو', audio: '🎤 پیام صوتی', file: '📎 فایل' };
function preview(c) {
  const l = c.last;
  if (!l) return 'رمزنگاری سرتاسری';
  if (l.deleted) return 'پیام حذف شد';
  if (l.flag && l.dir === 'in' && !l.text && !l.att) return '⚠ پیام قابل نمایش نیست';
  return (l.dir === 'out' ? 'شما: ' : '') + (l.att ? KIND[l.att] + (l.text ? ' — ' : '') : '') + (l.text || '').replace(/\s+/g, ' ').slice(0, 60);
}
async function refreshList() {
  if (!listEl || searchEl.value.trim().length >= 2) return;
  convs = await call('conversations');
  listEl.replaceChildren(...convs.filter((c) => c.peer).map((c) => el('button', { class: 'c-item' + (current?.id === c.id ? ' on' : ''), role: 'listitem', onclick: () => openConversation(c) },
    el('span', { class: 'c-av', text: (c.peer.display_name || '?').slice(0, 1) }),
    el('span', { class: 'c-body' }, el('span', { class: 'c-name', text: c.peer.display_name }), el('span', { class: 'c-prev', text: preview(c) })),
    el('span', { class: 'c-side-meta' }, el('span', { class: 'c-time', text: c.last ? fa.format(c.last.ts) : '' }),
      c.unread && current?.id !== c.id ? el('span', { class: 'badge', text: faNum.format(c.unread) }) : null))));
  if (!convs.length) listEl.append(el('p', { class: 'muted small c-hint', text: 'هنوز گفتگویی ندارید. «گفتگوی جدید» را بزنید.' }));
}
let searchTimer;
function onSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(async () => {
    const q = searchEl.value.trim();
    if (q.length < 2) return refreshList();
    const res = await call('search', { q });
    listEl.replaceChildren(el('p', { class: 'muted small c-hint', text: res.length ? `${faNum.format(res.length)} نتیجه (فقط در همین دستگاه؛ چیزی به سرور ارسال نمی‌شود)` : 'نتیجه‌ای یافت نشد.' }),
      ...res.map((r) => { const c = convs.find((x) => x.id === r.cid); return el('button', { class: 'c-item', onclick: () => { searchEl.value = ''; if (c) openConversation(c, r.mid); } },
        el('span', { class: 'c-body' }, el('span', { class: 'c-name', text: c?.peer?.display_name || '' }), el('span', { class: 'c-prev', text: r.text })),
        el('span', { class: 'c-time', text: faDay.format(r.ts) })); }));
  }, 250);
}
async function openDirectory() {
  const users = await call('directory');
  const dlg = dialog('شروع گفتگو', el('div', { class: 'c-dir' }, ...users.map((u) => el('button', { class: 'c-item', disabled: !u.has_identity, onclick: async () => {
    close(dlg);
    const id = await call('open_direct', { user_id: u.id });
    await refreshList();
    const c = convs.find((x) => x.id === id);
    if (c) openConversation(c);
  } }, el('span', { class: 'c-av', text: u.display_name.slice(0, 1) }),
    el('span', { class: 'c-body' }, el('span', { class: 'c-name', text: u.display_name }), el('span', { class: 'c-prev', text: u.has_identity ? u.username : 'هنوز وارد برنامه نشده است' }))))),
  users.length ? null : el('p', { class: 'muted', text: 'کاربر دیگری وجود ندارد.' }));
  dlg.showModal();
}
function dialog(title, ...body) {
  const dlg = el('dialog', { class: 'c-dialog' }, el('h2', { text: title }), ...body, el('button', { class: 'btn secondary block mt', text: 'بستن', onclick: () => close(dlg) }));
  dlg.addEventListener('close', () => dlg.remove());
  document.body.append(dlg);
  return dlg;
}
const close = (dlg) => { dlg.close(); dlg.remove(); };

// ------------------------------------------------------------------ conversation
let msgsEl, bannerEl, pinEl, typingEl, composer, sendBtn, replyBar, fileInput;
async function openConversation(c, focusMid = null) {
  current = c; reply = null; editing = null; byMid.clear();
  for (const u of urls.values()) URL.revokeObjectURL(u); urls.clear();
  root.querySelector('.c-app')?.classList.add('show-chat');
  msgsEl = el('div', { class: 'c-msgs', 'aria-live': 'polite' });
  bannerEl = el('div'); pinEl = el('div'); replyBar = el('div', { class: 'c-replybar' });
  typingEl = el('span', { class: 'c-typing' });
  composer = el('textarea', { rows: '1', placeholder: 'پیام', 'aria-label': 'متن پیام', maxlength: '10000',
    onkeydown: (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doSend(); } else if (e.key === 'Escape') clearReply(); },
    oninput: () => { if (composer.value.trim()) call('typing', { cid: c.id }).catch(() => {}); } });
  fileInput = el('input', { type: 'file', multiple: true, hidden: true, onchange: () => { const fs = [...fileInput.files]; fileInput.value = ''; if (fs.length) sendDialog(fs); } });
  sendBtn = el('button', { class: 'btn', text: 'ارسال', onclick: doSend });
  mainEl.replaceChildren(
    el('div', { class: 'c-head' },
      el('button', { class: 'btn secondary sm c-back', text: '→', 'aria-label': 'بازگشت', onclick: () => { current = null; root.querySelector('.c-app').classList.remove('show-chat'); refreshList(); } }),
      el('div', { class: 'c-head-name' }, el('b', { text: c.peer.display_name }), typingEl,
        el('button', { class: 'c-lock', text: '🔒 رمزنگاری سرتاسری — شماره امنیتی', onclick: showSafety })),
      el('button', { class: 'btn secondary sm', text: 'رسانه‌ها', onclick: showMedia })),
    bannerEl, pinEl, msgsEl, replyBar,
    el('div', { class: 'c-compose' },
      el('button', { class: 'btn secondary c-icon', text: '📎', 'aria-label': 'پیوست فایل یا تصویر', title: 'پیوست', onclick: () => fileInput.click() }),
      fileInput, composer, recorderButton(), sendBtn));
  const list = await call('messages', { cid: c.id });
  let lastDay = '';
  for (const m of list) {
    const day = faDay.format(m.ts);
    if (day !== lastDay) { msgsEl.append(el('div', { class: 'c-day', text: day })); lastDay = day; }
    upsertMessage(m, false);
  }
  renderPinned();
  const target = focusMid && msgsEl.querySelector(`[data-mid="${CSS.escape(focusMid)}"]`);
  if (target) { target.scrollIntoView({ block: 'center' }); target.classList.add('flash'); } else msgsEl.scrollTop = msgsEl.scrollHeight;
  await renderBanner();
  composer.focus();
  scheduleRead();
  refreshList();
}
function scheduleRead() {
  clearTimeout(readTimer);
  readTimer = setTimeout(() => { if (current && document.visibilityState === 'visible') call('mark_read', { cid: current.id, peer: current.peer.id }).then((n) => n && scheduleRefresh()).catch(() => {}); }, 800);
}
function showTyping() {
  typingEl.textContent = 'در حال نوشتن…';
  clearTimeout(typingTimer); typingTimer = setTimeout(() => { typingEl.textContent = ''; }, 6000);
}
async function renderBanner() {
  if (!current) return;
  const st = await call('contact_state', { user_id: current.peer.id }).catch(() => ({ pending: false }));
  const blocked = !!st.pending;
  composer.disabled = blocked; sendBtn.disabled = blocked;
  bannerEl.replaceChildren(...(blocked ? [el('div', { class: 'alert warn c-banner' },
    el('span', { text: `کلید امنیتی ${current.peer.display_name} تغییر کرده است (ورود از دستگاه جدید یا بازنشانی حساب). تا تأیید شما پیامی برای او ارسال نمی‌شود. در صورت امکان شماره امنیتی را حضوری مقایسه کنید.` }),
    el('button', { class: 'btn sm', text: 'پذیرفتن کلید جدید', onclick: async () => { await call('accept_key', { user_id: current.peer.id }); renderBanner(); } }))] : []));
}
function renderPinned() {
  const pinned = [...byMid.values()].filter((m) => m.pinned && !m.deleted).sort((a, b) => b.ts - a.ts)[0];
  pinEl.replaceChildren(...(pinned ? [el('button', { class: 'c-pinbar', onclick: () => msgsEl.querySelector(`[data-mid="${CSS.escape(pinned.mid)}"]`)?.scrollIntoView({ block: 'center' }) },
    el('b', { text: '📌 سنجاق‌شده: ' }), el('span', { text: summary(pinned) }))] : []));
}
const summary = (m) => m.deleted ? 'پیام حذف شد' : (m.att ? KIND[m.att.kind] + ' ' : '') + (m.text || m.att?.name || '').slice(0, 80);

// ------------------------------------------------------------------ messages
const statusIcon = (s) => ({ sending: '⏳', uploading: '⏳', sent: '✓', delivered: '✓✓', read: '✓✓', failed: '⚠' })[s] || '';
const FLAGS = {
  untrusted_device: 'پیامی از دستگاهی که با کلید امنیتی فرستنده تأیید نشده بود دریافت و رد شد.',
  decrypt_failed: 'این پیام قابل رمزگشایی نبود.',
  invalid_payload: 'پیام نامعتبر دریافت و رد شد.',
};
function upsertMessage(m, scroll = true) {
  byMid.set(m.mid, m);
  const old = msgsEl.querySelector(`[data-mid="${CSS.escape(m.mid)}"]`);
  let node;
  if (m.dir === 'in' && FLAGS[m.flag]) node = el('div', { class: 'c-sys', 'data-mid': m.mid, text: '⚠ ' + FLAGS[m.flag] });
  else {
    const target = m.reply_to ? byMid.get(m.reply_to) : null;
    const reacts = Object.entries(m.reactions || {});
    node = el('div', { class: 'c-msg ' + (m.dir === 'out' ? 'out' : 'in') + (m.deleted ? ' deleted' : ''), 'data-mid': m.mid },
      m.fwd ? el('div', { class: 'c-flag', text: '↪ بازارسال‌شده' }) : null,
      target ? el('button', { class: 'c-quote', onclick: () => msgsEl.querySelector(`[data-mid="${CSS.escape(target.mid)}"]`)?.scrollIntoView({ block: 'center' }) },
        el('b', { text: target.from === me.id ? 'شما' : current.peer.display_name }), el('span', { text: summary(target) })) : null,
      m.deleted ? el('div', { class: 'c-text muted', text: '🚫 این پیام حذف شد' }) : null,
      !m.deleted && m.att ? attachmentView(m) : null,
      !m.deleted && m.text ? el('div', { class: 'c-text', text: m.text }) : null,
      m.flag === 'new_key' ? el('div', { class: 'c-flag', text: 'از کلید امنیتی جدید و تأییدنشده فرستنده' }) : null,
      reacts.length ? el('div', { class: 'c-reacts' }, ...reacts.map(([u, e]) => el('span', { title: u === me.id ? 'شما' : current.peer.display_name, text: e }))) : null,
      el('div', { class: 'c-meta' },
        m.pinned ? el('span', { text: '📌' }) : null,
        m.edited ? el('span', { text: 'ویرایش‌شده' }) : null,
        el('span', { text: fa.format(m.ts) }),
        m.dir === 'out' ? el('span', { class: 'st' + (m.status === 'read' ? ' read' : ''), title: { read: 'خوانده‌شده', delivered: 'تحویل‌شده', sent: 'ارسال‌شده' }[m.status] || '', text: statusIcon(m.status) }) : null,
        m.deleted || m.status === 'uploading' || m.status === 'sending' ? null : el('button', { class: 'c-more', 'aria-label': 'گزینه‌های پیام', text: '⋯', onclick: (e) => menu(m, e.currentTarget) })),
      m.status === 'failed' ? el('div', { class: 'c-flag' }, el('span', { text: 'ارسال نشد: ' + errText(m.flag) + ' ' }),
        el('button', { class: 'btn sm secondary', text: 'تلاش دوباره', onclick: () => call('retry', { mid: m.mid, peer: current.peer.id }).catch((e) => toast(errText(e))) })) : null);
  }
  if (old) old.replaceWith(node); else msgsEl.append(node);
  for (const x of byMid.values()) if (x.reply_to === m.mid && x.mid !== m.mid) { const n = msgsEl.querySelector(`[data-mid="${CSS.escape(x.mid)}"] .c-quote span`); if (n) n.textContent = summary(m); }
  if (scroll && !old) msgsEl.scrollTop = msgsEl.scrollHeight;
  if (old && pinEl) renderPinned();
}

function attachmentView(m) {
  const a = m.att;
  const left = a.exp ? Math.ceil((Date.parse(a.exp.replace(' ', 'T') + 'Z') - Date.now()) / 86400000) : null;
  const expiry = a.id ? el('div', { class: 'c-exp', text: left === null ? 'نگهداری دائمی روی سرور' : left > 0 ? `تا ${faNum.format(left)} روز دیگر روی سرور` : 'از سرور حذف شده است' }) : null;
  const progress = m.status === 'uploading' ? el('progress', { max: a.size || 1, value: 0 }) : null;
  const box = el('div', { class: 'c-att' });
  const open = async () => {
    box.classList.add('busy');
    try {
      const blob = await call('file', { mid: m.mid });
      let url = urls.get(m.mid);
      if (!url) { url = URL.createObjectURL(blob); urls.set(m.mid, url); }
      return url;
    } catch (e) { toast(errText(e)); return null; } finally { box.classList.remove('busy'); }
  };
  const save = async () => { const url = await open(); if (url) el('a', { href: url, download: a.name || 'file' }).click(); };
  if (a.kind === 'image' || a.kind === 'video') {
    const thumb = a.thumb ? el('img', { src: a.thumb, alt: a.name, class: 'c-thumb' }) : el('div', { class: 'c-thumb c-ph', text: KIND[a.kind] });
    box.append(el('button', { class: 'c-media', 'aria-label': a.kind === 'video' ? 'پخش ویدیو' : 'نمایش تصویر', onclick: async () => {
      const url = await open(); if (!url) return;
      const dlg = dialog(a.name, a.kind === 'video' ? el('video', { src: url, controls: true, autoplay: true, class: 'c-view' }) : el('img', { src: url, alt: a.name, class: 'c-view' }),
        el('a', { class: 'btn block mt', href: url, download: a.name, text: 'ذخیره روی دستگاه' }));
      dlg.showModal();
    } }, thumb, a.kind === 'video' ? el('span', { class: 'c-play', text: '▶' }) : null),
    el('div', { class: 'c-att-info', text: `${a.q === 'hd' ? 'HD' : a.q === 'original' ? 'اصلی' : ''} ${size(a.size)}${a.dur ? ' — ' + dur(a.dur) : ''}`.trim() }));
  } else if (a.kind === 'audio') {
    const slot = el('div');
    slot.append(el('button', { class: 'btn sm', text: '▶ پخش پیام صوتی' + (a.dur ? ' (' + dur(a.dur) + ')' : ''), onclick: async () => {
      const url = await open(); if (url) slot.replaceChildren(el('audio', { src: url, controls: true, autoplay: true }));
    } }));
    box.append(slot);
  } else {
    box.append(el('button', { class: 'c-file', onclick: save }, el('span', { class: 'c-file-ico', text: '📄' }),
      el('span', { class: 'c-body' }, el('span', { class: 'c-name', text: a.name }), el('span', { class: 'c-prev', text: size(a.size) }))));
  }
  box.append(progress || '', expiry || '');
  return box;
}
const dur = (s) => { s = Math.round(s); return faNum.format(Math.floor(s / 60)) + ':' + String(s % 60).padStart(2, '0').replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]); };

function menu(m, anchor) {
  document.querySelector('.c-menu')?.remove();
  const act = (label, fn) => el('button', { text: label, onclick: async () => { box.remove(); try { await fn(); } catch (e) { toast(errText(e)); } } });
  const own = m.from === me.id;
  const box = el('div', { class: 'c-menu', role: 'menu' },
    el('div', { class: 'c-emoji' }, ...EMOJI.map((e) => el('button', { text: e, 'aria-label': 'واکنش ' + e, onclick: async () => {
      box.remove(); const mine = m.reactions?.[me.id] === e;
      try { await call('react', { cid: current.id, peer: current.peer.id, mid: m.mid, emoji: mine ? '' : e }); } catch (x) { toast(errText(x)); }
    } }))),
    act('↩ پاسخ', () => setReply(m)),
    m.text ? act('📋 کپی متن', () => navigator.clipboard.writeText(m.text)) : null,
    own && !m.att && m.text ? act('✏ ویرایش', () => setEdit(m)) : null,
    act(m.pinned ? '📌 برداشتن سنجاق' : '📌 سنجاق', () => call('pin', { cid: current.id, peer: current.peer.id, mid: m.mid, pinned: !m.pinned })),
    act('↪ بازارسال', () => forwardDialog(m)),
    act('🗑 حذف برای من', () => call('delete_local', { mid: m.mid })),
    own ? act('🗑 حذف برای همه', () => confirm('این پیام برای هر دو طرف حذف شود؟') && call('delete_all', { cid: current.id, peer: current.peer.id, mid: m.mid })) : null);
  document.body.append(box);
  const r = anchor.getBoundingClientRect();
  box.style.top = Math.min(window.innerHeight - box.offsetHeight - 8, r.bottom + 4) + 'px';
  box.style.left = Math.max(8, Math.min(window.innerWidth - box.offsetWidth - 8, r.left - box.offsetWidth / 2)) + 'px';
  setTimeout(() => document.addEventListener('click', function off(e) { if (!box.contains(e.target)) { box.remove(); document.removeEventListener('click', off); } }), 0);
}
function setReply(m) { editing = null; reply = m; replyBar.replaceChildren(el('span', { text: '↩ پاسخ به: ' + summary(m) }), el('button', { class: 'btn sm secondary', text: '✕', 'aria-label': 'لغو', onclick: clearReply })); composer.focus(); }
function setEdit(m) { reply = null; editing = m; composer.value = m.text; replyBar.replaceChildren(el('span', { text: '✏ ویرایش پیام' }), el('button', { class: 'btn sm secondary', text: '✕', 'aria-label': 'لغو', onclick: () => { clearReply(); composer.value = ''; } })); composer.focus(); }
function clearReply() { reply = null; editing = null; replyBar.replaceChildren(); }
async function forwardDialog(m) {
  const dlg = dialog('بازارسال به…', el('div', { class: 'c-dir' }, ...convs.filter((c) => c.peer).map((c) => el('button', { class: 'c-item', onclick: async () => {
    close(dlg);
    try { await call('forward', { mid: m.mid, cid: c.id, peer: c.peer.id }); toast('بازارسال شد به ' + c.peer.display_name); } catch (e) { toast(errText(e)); }
  } }, el('span', { class: 'c-av', text: c.peer.display_name.slice(0, 1) }), el('span', { class: 'c-name', text: c.peer.display_name })))));
  dlg.showModal();
}
async function doSend() {
  const text = composer.value;
  if (!text.trim() || !current) return;
  composer.value = '';
  try {
    if (editing) await call('edit', { cid: current.id, peer: current.peer.id, mid: editing.mid, text });
    else await call('send', { cid: current.id, peer: current.peer.id, text, reply_to: reply?.mid || null });
  } catch (e) { toast(errText(e)); }
  clearReply();
}

// ------------------------------------------------------------------ files, images, video, voice
async function bitmapThumb(src, w, h, max = 320) {
  const s = Math.min(1, max / Math.max(w, h));
  const c = el('canvas'); c.width = Math.max(1, Math.round(w * s)); c.height = Math.max(1, Math.round(h * s));
  c.getContext('2d').drawImage(src, 0, 0, c.width, c.height);
  for (const q of [0.6, 0.45, 0.3]) { const d = c.toDataURL('image/jpeg', q); if (d.length < 38000) return d; }
  return null;
}
async function imageMeta(file) {
  const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
  const meta = { kind: 'image', w: bmp.width, h: bmp.height, thumb: await bitmapThumb(bmp, bmp.width, bmp.height) };
  return { bmp, meta };
}
/** HD: re-encoded on this device (long edge ≤ 2560 px, JPEG 0.9). Also strips EXIF/location data. */
async function toHD(file, bmp) {
  const s = Math.min(1, 2560 / Math.max(bmp.width, bmp.height));
  const c = el('canvas'); c.width = Math.round(bmp.width * s); c.height = Math.round(bmp.height * s);
  c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
  const blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.9));
  return { file: new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }), w: c.width, h: c.height };
}
function videoMeta(file) {
  return new Promise((resolve) => {
    const v = el('video', { muted: true, preload: 'metadata', playsinline: true });
    const url = URL.createObjectURL(file);
    const done = (meta) => { URL.revokeObjectURL(url); resolve(meta); };
    const t = setTimeout(() => done({ kind: 'video' }), 8000);
    v.onloadedmetadata = () => { v.currentTime = Math.min(1, (v.duration || 0) / 2); };
    v.onseeked = async () => { clearTimeout(t); done({ kind: 'video', w: v.videoWidth, h: v.videoHeight, dur: v.duration, thumb: await bitmapThumb(v, v.videoWidth, v.videoHeight) }); };
    v.onerror = () => { clearTimeout(t); done({ kind: 'video' }); };
    v.src = url;
  });
}
async function sendDialog(files) {
  const items = [];
  for (const f of files) {
    const kind = f.type.startsWith('image/') && f.type !== 'image/svg+xml' ? 'image' : f.type.startsWith('video/') ? 'video' : f.type.startsWith('audio/') ? 'audio' : 'file';
    let meta = { kind }, bmp = null;
    try { if (kind === 'image') ({ bmp, meta } = await imageMeta(f)); else if (kind === 'video') meta = await videoMeta(f); } catch { meta = { kind: 'file' }; }
    items.push({ f, meta, bmp });
  }
  const hasImg = items.some((i) => i.meta.kind === 'image');
  const quality = el('select', { 'aria-label': 'کیفیت تصویر' }, el('option', { value: 'hd', text: 'HD (حجم کمتر، حذف اطلاعات مکان)' }), el('option', { value: 'original', text: 'اصلی (بدون کاهش کیفیت؛ ممکن است اطلاعات مکان EXIF داشته باشد)' }));
  const caption = el('input', { type: 'text', placeholder: 'توضیح (اختیاری)', maxlength: '2000' });
  const dlg = dialog(`ارسال ${faNum.format(items.length)} مورد`,
    el('div', { class: 'c-sendlist' }, ...items.map((i) => el('div', { class: 'c-senditem' },
      i.meta.thumb ? el('img', { src: i.meta.thumb, alt: '' }) : el('span', { class: 'c-file-ico', text: i.meta.kind === 'video' ? '🎬' : i.meta.kind === 'audio' ? '🎵' : '📄' }),
      el('span', { class: 'c-body' }, el('span', { class: 'c-name', text: i.f.name }), el('span', { class: 'c-prev', text: size(i.f.size) }))))),
    hasImg ? el('label', { class: 'field' }, el('span', { text: 'کیفیت تصویر' }), quality) : null,
    items.some((i) => i.meta.kind === 'video') ? el('p', { class: 'small muted', text: 'ویدیو با کیفیت اصلی ارسال می‌شود. (انتخاب کیفیت کمتر در این نسخه وجود ندارد.)' }) : null,
    el('label', { class: 'field' }, el('span', { text: 'توضیح' }), caption),
    el('p', { class: 'small muted', text: 'فایل‌ها پیش از خروج از این دستگاه رمزنگاری می‌شوند و ۷ روز روی سرور می‌مانند.' }),
    el('button', { class: 'btn block', text: 'ارسال', onclick: async () => {
      close(dlg);
      const cid = current.id, peer = current.peer.id;
      for (const [n, i] of items.entries()) {
        let file = i.f, meta = { ...i.meta, name: i.f.name, mime: i.f.type };
        if (meta.kind === 'image') {
          meta.q = quality.value;
          if (quality.value === 'hd') { const hd = await toHD(i.f, i.bmp); file = hd.file; meta = { ...meta, w: hd.w, h: hd.h, name: hd.file.name, mime: 'image/jpeg' }; }
        }
        call('send_file', { cid, peer, file, meta, caption: n === 0 ? caption.value : '', reply_to: n === 0 ? reply?.mid || null : null }).catch((e) => toast(errText(e)));
      }
      clearReply();
    } }));
  dlg.showModal();
}
function recorderButton() {
  if (!window.MediaRecorder || !navigator.mediaDevices?.getUserMedia) return null;
  let rec = null, chunks = [], started = 0, tick = null;
  const btn = el('button', { class: 'btn secondary c-icon', text: '🎤', 'aria-label': 'ضبط پیام صوتی', title: 'ضبط پیام صوتی', onclick: async () => {
    if (rec) { rec.stop(); return; }
    let stream;
    try { stream = await navigator.mediaDevices.getUserMedia({ audio: true }); } catch { toast('دسترسی به میکروفون داده نشد.'); return; }
    const type = ['audio/webm;codecs=opus', 'audio/mp4', 'audio/ogg;codecs=opus'].find((t) => MediaRecorder.isTypeSupported(t)) || '';
    rec = new MediaRecorder(stream, type ? { mimeType: type } : {});
    chunks = []; started = Date.now();
    rec.ondataavailable = (e) => e.data.size && chunks.push(e.data);
    rec.onstop = () => {
      stream.getTracks().forEach((t) => t.stop()); clearInterval(tick);
      btn.textContent = '🎤'; btn.classList.remove('rec');
      const secs = (Date.now() - started) / 1000; rec = null;
      if (secs < 0.7 || !current) return;
      const mime = (chunks[0]?.type || type || 'audio/webm').split(';')[0];
      const file = new File(chunks, 'voice.' + (mime.includes('mp4') ? 'm4a' : mime.includes('ogg') ? 'ogg' : 'webm'), { type: mime });
      call('send_file', { cid: current.id, peer: current.peer.id, file, meta: { kind: 'audio', dur: secs, name: file.name, mime } }).catch((e) => toast(errText(e)));
    };
    rec.start();
    btn.classList.add('rec');
    tick = setInterval(() => { btn.textContent = '⏹ ' + dur((Date.now() - started) / 1000); }, 500);
  } });
  return btn;
}
async function showMedia() {
  const items = await call('media', { cid: current.id });
  const dlg = dialog('رسانه‌ها و فایل‌های این گفتگو', items.length ? el('div', { class: 'c-dir' }, ...items.reverse().map((m) => el('button', { class: 'c-item', onclick: () => {
    close(dlg); const n = msgsEl.querySelector(`[data-mid="${CSS.escape(m.mid)}"]`); n?.scrollIntoView({ block: 'center' }); n?.classList.add('flash');
  } }, m.att.thumb ? el('img', { src: m.att.thumb, alt: '', class: 'c-mini' }) : el('span', { class: 'c-file-ico', text: KIND[m.att.kind].slice(0, 2) }),
    el('span', { class: 'c-body' }, el('span', { class: 'c-name', text: m.att.name }), el('span', { class: 'c-prev', text: size(m.att.size) + ' — ' + faDay.format(m.ts) }))))) : el('p', { class: 'muted', text: 'هنوز فایلی در این گفتگو نیست.' }));
  dlg.showModal();
}
async function showSafety() {
  const s = await call('safety', { user_id: current.peer.id });
  const state = el('p', { class: 'small', text: s.verified ? '✔ شما این مخاطب را تأیید کرده‌اید.' : 'هنوز تأیید نشده است.' });
  const dlg = dialog(`شماره امنیتی شما و ${current.peer.display_name}`,
    el('p', { class: 'small muted', text: 'اگر این ۶۰ رقم روی گوشی مخاطب هم دقیقاً همین باشد، پیام‌ها مستقیماً بین شما دو نفر رمزنگاری می‌شوند و هیچ‌کس (حتی سرور) در میان نیست. مقایسه را حضوری یا تلفنی انجام دهید.' }),
    el('div', { class: 'c-safety' }, ...s.number.map((g) => el('span', { text: g }))),
    state,
    s.pending ? el('p', { class: 'alert warn small', text: 'کلید این مخاطب تغییر کرده و هنوز پذیرفته نشده است؛ این شماره مربوط به کلید قبلی است.' }) : null,
    s.verified || s.pending ? null : el('button', { class: 'btn', text: 'اعداد یکسان است — تأیید', onclick: async () => { await call('verify', { user_id: current.peer.id }); state.textContent = '✔ شما این مخاطب را تأیید کرده‌اید.'; } }));
  dlg.showModal();
}

navigator.storage?.persist?.().catch(() => {});
document.addEventListener('visibilitychange', () => { const v = document.visibilityState === 'visible'; call('visibility', { visible: v }); if (v) scheduleRead(); });
call('start').then(handleState).catch((e) => status('خطا در راه‌اندازی رمزنگاری: ' + e.message, el('button', { class: 'btn', text: 'بارگذاری دوباره', onclick: () => location.reload() })));
