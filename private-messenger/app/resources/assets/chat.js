// Chat UI (main thread). Holds no keys: all cryptography happens in e2ee-worker.js.
// Every piece of user content is inserted with textContent — never as HTML.
const root = document.getElementById('app');
const worker = new Worker(document.body.dataset.worker, { type: 'module' });
const fa = new Intl.DateTimeFormat('fa-IR', { hour: '2-digit', minute: '2-digit' });
const faDay = new Intl.DateTimeFormat('fa-IR-u-ca-persian', { year: 'numeric', month: 'long', day: 'numeric' });
let seq = 0, me = null, convs = [], current = null, refreshTimer = null;
const pending = new Map();

function el(tag, attrs = {}, ...children) {
  const e = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'class') e.className = v;
    else if (k === 'text') e.textContent = v;
    else if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
    else e.setAttribute(k, v === true ? '' : v);
  }
  for (const c of children) if (c !== null && c !== undefined) e.append(c);
  return e;
}
const call = (cmd, args) => new Promise((resolve, reject) => { const id = ++seq; pending.set(id, { resolve, reject }); worker.postMessage({ id, cmd, args }); });

worker.onmessage = ({ data }) => {
  if (data.id) {
    const p = pending.get(data.id); pending.delete(data.id);
    data.ok ? p.resolve(data.result) : p.reject(new Error(data.error));
    return;
  }
  const { event, data: d } = data;
  if (event === 'message') { if (current && d.cid === current.id) upsertMessage(d); scheduleRefresh(); }
  if (event === 'status') { const b = document.querySelector(`[data-mid="${CSS.escape(d.mid)}"] .st`); if (b) b.textContent = statusIcon(d.status); }
  if (event === 'removed') document.querySelector(`[data-mid="${CSS.escape(d.mid)}"]`)?.remove();
  if (event === 'key_changed' && current?.peer?.id === d.user_id) renderBanner();
  if (event === 'conversations_changed') scheduleRefresh();
  if (event === 'state') handleState(d);
};
worker.onerror = () => status('خطا در بارگذاری موتور رمزنگاری. صفحه را دوباره بارگذاری کنید.');

// ------------------------------------------------------------------ status screens
function status(text, ...actions) {
  root.replaceChildren(el('div', { class: 'chat-status' }, el('div', { class: 'panel' }, el('p', { text }), ...actions)));
}
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

// ------------------------------------------------------------------ main layout
let listEl, mainEl;
function renderApp() {
  listEl = el('div', { class: 'c-list', role: 'list' });
  mainEl = el('main', { class: 'c-main' }, el('div', { class: 'c-empty', text: 'یک گفتگو را انتخاب کنید یا گفتگوی جدیدی شروع کنید.' }));
  root.replaceChildren(el('div', { class: 'c-app' + (current ? ' show-chat' : '') },
    el('aside', { class: 'c-side' },
      el('div', { class: 'c-side-head' }, el('h1', { text: 'گفتگوها' }), el('button', { class: 'btn sm', text: 'گفتگوی جدید', onclick: openDirectory })),
      listEl,
      el('p', { class: 'c-note', text: '🔒 پیام‌ها فقط روی همین دستگاه و به‌صورت رمزنگاری‌شده نگهداری می‌شوند. پاک کردن داده‌های مرورگر یعنی از دست رفتن پیام‌های قبلی این دستگاه.' })),
    mainEl));
  refreshList();
}

function scheduleRefresh() { clearTimeout(refreshTimer); refreshTimer = setTimeout(refreshList, 300); }

function preview(c) {
  if (!c.last) return 'رمزنگاری سرتاسری';
  if (c.last.flag && c.last.dir === 'in' && !c.last.text) return '⚠ پیام قابل نمایش نیست';
  return (c.last.dir === 'out' ? 'شما: ' : '') + c.last.text.replace(/\s+/g, ' ').slice(0, 60);
}

async function refreshList() {
  if (!listEl) return;
  convs = await call('conversations');
  listEl.replaceChildren(...convs.filter((c) => c.peer).map((c) => el('button', { class: 'c-item' + (current?.id === c.id ? ' on' : ''), role: 'listitem', onclick: () => openConversation(c) },
    el('span', { class: 'c-av', text: (c.peer.display_name || '?').slice(0, 1) }),
    el('span', { class: 'c-body' },
      el('span', { class: 'c-name', text: c.peer.display_name }),
      el('span', { class: 'c-prev', text: preview(c) })),
    el('span', { class: 'c-time', text: c.last ? fa.format(c.last.ts) : '' }))));
  if (!convs.length) listEl.append(el('p', { class: 'muted small c-hint', text: 'هنوز گفتگویی ندارید. «گفتگوی جدید» را بزنید.' }));
}

async function openDirectory() {
  const users = await call('directory');
  const dlg = el('dialog', { class: 'c-dialog' },
    el('h2', { text: 'شروع گفتگو' }),
    el('div', { class: 'c-dir' }, ...users.map((u) => el('button', { class: 'c-item', disabled: !u.has_identity, onclick: async () => {
      dlg.close(); dlg.remove();
      const id = await call('open_direct', { user_id: u.id });
      await refreshList();
      const c = convs.find((x) => x.id === id);
      if (c) openConversation(c);
    } }, el('span', { class: 'c-av', text: u.display_name.slice(0, 1) }),
      el('span', { class: 'c-body' }, el('span', { class: 'c-name', text: u.display_name }),
        el('span', { class: 'c-prev', text: u.has_identity ? u.username : 'هنوز وارد برنامه نشده است' }))))),
    users.length ? null : el('p', { class: 'muted', text: 'کاربر دیگری وجود ندارد.' }),
    el('button', { class: 'btn secondary block', text: 'بستن', onclick: () => { dlg.close(); dlg.remove(); } }));
  document.body.append(dlg);
  dlg.showModal();
}

// ------------------------------------------------------------------ conversation
let msgsEl, bannerEl, composer, sendBtn;
async function openConversation(c) {
  current = c;
  root.querySelector('.c-app')?.classList.add('show-chat');
  msgsEl = el('div', { class: 'c-msgs', 'aria-live': 'polite' });
  bannerEl = el('div');
  composer = el('textarea', { rows: '1', placeholder: 'پیام', 'aria-label': 'متن پیام', maxlength: '10000',
    onkeydown: (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doSend(); } } });
  sendBtn = el('button', { class: 'btn', text: 'ارسال', onclick: doSend });
  mainEl.replaceChildren(
    el('div', { class: 'c-head' },
      el('button', { class: 'btn secondary sm c-back', text: '→', 'aria-label': 'بازگشت', onclick: () => { current = null; root.querySelector('.c-app').classList.remove('show-chat'); refreshList(); } }),
      el('div', { class: 'c-head-name' }, el('b', { text: c.peer.display_name }),
        el('button', { class: 'c-lock', text: '🔒 رمزنگاری سرتاسری — شماره امنیتی', onclick: showSafety }))),
    bannerEl, msgsEl,
    el('div', { class: 'c-compose' }, composer, sendBtn));
  for (const b of listEl.querySelectorAll('.c-item')) b.classList.remove('on');
  const list = await call('messages', { cid: c.id });
  let lastDay = '';
  for (const m of list) {
    const day = faDay.format(m.ts);
    if (day !== lastDay) { msgsEl.append(el('div', { class: 'c-day', text: day })); lastDay = day; }
    upsertMessage(m, false);
  }
  msgsEl.scrollTop = msgsEl.scrollHeight;
  await renderBanner();
  composer.focus();
  refreshList();
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

const statusIcon = (s) => ({ sending: '⏳', sent: '✓', delivered: '✓✓', failed: '⚠' })[s] || '';
const FLAGS = {
  untrusted_device: 'پیامی از دستگاهی که با کلید امنیتی فرستنده تأیید نشده بود دریافت و رد شد.',
  decrypt_failed: 'این پیام قابل رمزگشایی نبود.',
  invalid_payload: 'پیام نامعتبر دریافت و رد شد.',
};

function upsertMessage(m, scroll = true) {
  const sel = `[data-mid="${CSS.escape(m.mid)}"]`;
  const old = msgsEl.querySelector(sel);
  let node;
  if (m.dir === 'in' && FLAGS[m.flag]) {
    node = el('div', { class: 'c-sys', 'data-mid': m.mid, text: '⚠ ' + FLAGS[m.flag] });
  } else {
    node = el('div', { class: 'c-msg ' + (m.dir === 'out' ? 'out' : 'in'), 'data-mid': m.mid },
      el('div', { class: 'c-text', text: m.text }),
      m.flag === 'new_key' ? el('div', { class: 'c-flag', text: 'از کلید امنیتی جدید و تأییدنشده فرستنده' }) : null,
      el('div', { class: 'c-meta' }, el('span', { text: fa.format(m.ts) }), m.dir === 'out' ? el('span', { class: 'st', text: statusIcon(m.status) }) : null),
      m.status === 'failed' ? el('div', { class: 'c-flag' }, el('span', { text: m.flag === 'key_changed' ? 'ارسال نشد: کلید مخاطب تغییر کرده است. ' : 'ارسال نشد. ' }),
        el('button', { class: 'btn sm secondary', text: 'تلاش دوباره', onclick: () => call('retry', { mid: m.mid, peer: current.peer.id }) })) : null);
  }
  if (old) old.replaceWith(node); else msgsEl.append(node);
  if (scroll) msgsEl.scrollTop = msgsEl.scrollHeight;
}

async function doSend() {
  const text = composer.value;
  if (!text.trim() || !current) return;
  composer.value = '';
  await call('send', { cid: current.id, peer: current.peer.id, text });
}

async function showSafety() {
  const s = await call('safety', { user_id: current.peer.id });
  const state = el('p', { class: 'small', text: s.verified ? '✔ شما این مخاطب را تأیید کرده‌اید.' : 'هنوز تأیید نشده است.' });
  const dlg = el('dialog', { class: 'c-dialog' },
    el('h2', { text: `شماره امنیتی شما و ${current.peer.display_name}` }),
    el('p', { class: 'small muted', text: 'اگر این ۶۰ رقم روی گوشی مخاطب هم دقیقاً همین باشد، پیام‌ها مستقیماً بین شما دو نفر رمزنگاری می‌شوند و هیچ‌کس (حتی سرور) در میان نیست. مقایسه را حضوری یا تلفنی انجام دهید.' }),
    el('div', { class: 'c-safety' }, ...s.number.map((g) => el('span', { text: g }))),
    state,
    s.pending ? el('p', { class: 'alert warn small', text: 'کلید این مخاطب تغییر کرده و هنوز پذیرفته نشده است؛ این شماره مربوط به کلید قبلی است.' }) : null,
    el('div', { class: 'row' },
      s.verified || s.pending ? null : el('button', { class: 'btn', text: 'اعداد یکسان است — تأیید', onclick: async () => { await call('verify', { user_id: current.peer.id }); state.textContent = '✔ شما این مخاطب را تأیید کرده‌اید.'; } }),
      el('button', { class: 'btn secondary', text: 'بستن', onclick: () => { dlg.close(); dlg.remove(); } })));
  document.body.append(dlg);
  dlg.showModal();
}

// Ask the browser not to evict this site's storage (keys and history live there).
navigator.storage?.persist?.().catch(() => {});
document.addEventListener('visibilitychange', () => call('visibility', { visible: document.visibilityState === 'visible' }));
call('start').then(handleState).catch((e) => status('خطا در راه‌اندازی رمزنگاری: ' + e.message, el('button', { class: 'btn', text: 'بارگذاری دوباره', onclick: () => location.reload() })));
