// End-to-end encryption engine (module Web Worker).
// All keys and all plaintext live only here, on the user's device. The server receives public keys and
// ciphertext only. Cryptography: vodozemac (Olm v1 / Double Ratchet, Ed25519) + Web Crypto (AES-256-GCM, SHA-*).
import init, { Account, Session, SigningKey, verify_ed25519 } from './pm_crypto.js';

const API = new URL('../api/v1', self.location.href).pathname;
const OTK_TARGET = 50, OTK_MIN = 20, MAX_TEXT = 10000;
const SEG = 262144, SEGC = SEG + 16;                    // file encryption segments (must match the server)
const enc = new TextEncoder(), dec = new TextDecoder();
const FILE_AAD = enc.encode('pm-file-v1');
const EVENT_TYPES = ['edit', 'delete', 'reaction', 'pin'];
const CALL_ACTIONS = ['offer', 'answer', 'reject', 'hangup', 'cancel', 'busy'];
let callMode = false;

let db, lsk, pk, account, msk, me, deviceId, cursor = 0, ncursor = 0;
let visible = true, syncing = false, stopped = false, timer = null, lastTyping = 0;
const sessions = new Map();          // remote device id -> [Session] (newest first)
const pendingStatus = new Set();     // my message ids awaiting "delivered"
const fileCache = new Map();         // mid -> decrypted Blob (this session only)
const unsentFiles = new Map();       // mid -> File (to retry an interrupted upload in this session)

// ------------------------------------------------------------------ helpers
const b64 = (u8) => { let s = ''; for (let i = 0; i < u8.length; i += 8192) s += String.fromCharCode.apply(null, u8.subarray(i, i + 8192)); return btoa(s).replace(/=+$/, ''); };
const b64dec = (s) => Uint8Array.from(atob(s.padEnd(Math.ceil(s.length / 4) * 4, '=')), (c) => c.charCodeAt(0));
const b64url = (u8) => b64(u8).replace(/\+/g, '-').replace(/\//g, '_');
const concat = (...a) => { const o = new Uint8Array(a.reduce((n, x) => n + x.length, 0)); let i = 0; for (const x of a) { o.set(x, i); i += x.length; } return o; };
const hex = (buf) => [...new Uint8Array(buf)].map((x) => x.toString(16).padStart(2, '0')).join('');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const emit = (event, data) => self.postMessage({ event, data });
const newId = () => b64url(crypto.getRandomValues(new Uint8Array(16)));
const norm = (s) => String(s).toLowerCase().replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/‌/g, ' ');

async function api(path, { method = 'GET', body } = {}) {
  const r = await fetch(API + path, {
    method, credentials: 'same-origin', cache: 'no-store',
    headers: body ? { 'Content-Type': 'application/json', 'X-PM-Request': '1' } : { 'X-PM-Request': '1' },
    body: body ? JSON.stringify(body) : undefined,
  });
  const j = await r.json().catch(() => ({}));
  if (!r.ok) { const e = new Error(j?.error?.code || `http_${r.status}`); e.status = r.status; e.data = j?.error || {}; throw e; }
  return j;
}

// ------------------------------------------------------------------ local encrypted storage (IndexedDB)
function openDb(name) {
  return new Promise((res, rej) => {
    const r = indexedDB.open(name, 3);
    r.onupgradeneeded = (ev) => {
      const d = r.result;
      if (ev.oldVersion < 1) {
        d.createObjectStore('kv'); d.createObjectStore('sessions'); d.createObjectStore('contacts');
        d.createObjectStore('messages', { keyPath: 'mid' }).createIndex('conv', ['cid', 'ts']);
      }
      if (ev.oldVersion < 2) d.createObjectStore('events', { keyPath: 'mid' });
      if (ev.oldVersion < 3) d.createObjectStore('groups', { keyPath: 'cid' });
    };
    r.onsuccess = () => res(r.result);
    r.onerror = () => rej(r.error);
  });
}
const req = (r) => new Promise((res, rej) => { r.onsuccess = () => res(r.result); r.onerror = () => rej(r.error); });
const get = (store, key) => req(db.transaction(store).objectStore(store).get(key));
const getAll = (store) => req(db.transaction(store).objectStore(store).getAll());
/** Atomic multi-store write: ops = [[store, value, key?], ...]; value === undefined deletes. */
function write(ops) {
  return new Promise((res, rej) => {
    const t = db.transaction([...new Set(ops.map((o) => o[0]))], 'readwrite');
    for (const [store, value, key] of ops) {
      const s = t.objectStore(store);
      if (value === undefined) s.delete(key); else if (key === undefined) s.put(value); else s.put(value, key);
    }
    t.oncomplete = () => res(); t.onerror = () => rej(t.error); t.onabort = () => rej(t.error);
  });
}
async function seal(obj) {
  const iv = crypto.getRandomValues(new Uint8Array(12));
  return { iv, ct: new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, lsk, enc.encode(JSON.stringify(obj)))) };
}
async function unseal(box) { return JSON.parse(dec.decode(await crypto.subtle.decrypt({ name: 'AES-GCM', iv: box.iv }, lsk, box.ct))); }

async function loadLocalKeys() {
  lsk = await get('kv', 'lsk');
  if (!lsk) { // non-extractable: code on this origin can use it but never export it
    lsk = await crypto.subtle.generateKey({ name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
    await write([['kv', lsk, 'lsk']]);
  }
  const wrapped = await get('kv', 'pk');
  if (wrapped) pk = Uint8Array.from(await unseal(wrapped));
  else { pk = crypto.getRandomValues(new Uint8Array(32)); await write([['kv', await seal([...pk]), 'pk']]); }
}

// ------------------------------------------------------------------ identity and device
const signedPrekeys = (json, prefix) => Object.entries(JSON.parse(json)).map(([id, key]) => ({ id, key, sig: account.sign(`${prefix}:${id}:${key}`) }));
const certificate = (user, ik, sk) => JSON.stringify({ v: 1, type: 'pm-device-cert', user, ik, sk, protocols: ['olm-v1'] });
const saveAccount = (extra = []) => write([['kv', account.pickle(pk), 'account'], ...extra]);

async function createIdentity(reset) {
  account = new Account(); msk = new SigningKey();
  account.generate_one_time_keys(OTK_TARGET); account.generate_fallback_key();
  const ik = account.curve25519_key(), sk = account.ed25519_key();
  const r = await api('/identity', { method: 'POST', body: { reset, msk: msk.public_key(), device: {
    identity_key: ik, signing_key: sk, certificate_sig: msk.sign(certificate(me.id, ik, sk)),
    one_time_keys: signedPrekeys(account.one_time_keys(), 'pm-otk-v1'), fallback_key: signedPrekeys(account.fallback_key(), 'pm-fbk-v1')[0] } } });
  account.mark_keys_as_published();
  deviceId = r.device_id;
  sessions.clear();
  const old = await req(db.transaction('sessions').objectStore('sessions').getAllKeys());
  await saveAccount([['kv', await seal([...msk.to_bytes()]), 'msk'], ['kv', deviceId, 'device_id'], ['kv', me.id, 'user'], ...old.map((k) => ['sessions', undefined, k])]);
}
async function bindDevice() {
  const ch = await api('/device/challenge');
  await api('/device/bind', { method: 'POST', body: { device_id: deviceId, signature: account.sign(`pm-bind-v1:${ch.nonce}:${ch.session}`) } });
}
async function replenish(count) {
  if (count >= OTK_MIN) return;
  account.generate_one_time_keys(OTK_TARGET - count);
  await api('/keys/upload', { method: 'POST', body: { one_time_keys: signedPrekeys(account.one_time_keys(), 'pm-otk-v1') } });
  account.mark_keys_as_published();
  await saveAccount();
}

// ------------------------------------------------------------------ contacts (key pinning)
async function contact(userId, refresh = false) {
  let c = await get('contacts', userId);
  if (c && !refresh && Date.now() - c.fetched < 300000) return c;
  const k = await api(`/users/${encodeURIComponent(userId)}/keys`);
  if (!k.msk) { if (c) return c; throw new Error('no_identity'); }
  const devices = {};
  for (const d of k.devices) {
    const cert = certificate(userId, d.identity_key, d.signing_key);
    if (d.certificate === cert && verify_ed25519(k.msk, cert, d.certificate_sig)) devices[d.id] = { ik: d.identity_key, sk: d.signing_key };
  }
  if (userId === me.id) c = { user_id: userId, msk: msk.public_key(), verified: true, devices: k.msk === msk.public_key() ? devices : {}, fetched: Date.now() };
  else if (!c) c = { user_id: userId, msk: k.msk, verified: false, devices, pending_msk: null, pending_devices: null, fetched: Date.now() };
  else if (c.msk === k.msk) {
    if (Object.keys(devices).some((d) => !(d in (c.devices || {})))) emit('device_added', { user_id: userId });
    c = { ...c, devices, pending_msk: null, pending_devices: null, fetched: Date.now() };
  }
  else {
    const isNew = c.pending_msk !== k.msk;
    c = { ...c, pending_msk: k.msk, pending_devices: devices, fetched: Date.now() };
    if (isNew) emit('key_changed', { user_id: userId });
  }
  await write([['contacts', c, userId]]);
  return c;
}
async function acceptKey(userId) {
  const c = await get('contacts', userId);
  if (!c?.pending_msk) return;
  for (const d of Object.keys(c.devices || {})) sessions.delete(d);
  await write([['contacts', { ...c, msk: c.pending_msk, devices: c.pending_devices || {}, verified: false, pending_msk: null, pending_devices: null }, userId],
    ...Object.keys(c.devices || {}).map((d) => ['sessions', undefined, d])]);
}

// ------------------------------------------------------------------ sessions
async function sessionsFor(dev) {
  if (!sessions.has(dev)) { const p = await get('sessions', dev); sessions.set(dev, (p || []).map((x) => Session.from_pickle(x, pk))); }
  return sessions.get(dev);
}
const sessionOps = (devs) => devs.map((d) => ['sessions', (sessions.get(d) || []).slice(0, 5).map((s) => s.pickle(pk)), d]);

// ------------------------------------------------------------------ local messages
// meta (mid, cid, ts, dir, status) stays readable for indexing; everything else is sealed.
const metaOf = (m) => ({ mid: m.mid, cid: m.cid, ts: m.ts, dir: m.dir, status: m.status });
const CONTENT = ['from', 'text', 'flag', 'reply_to', 'edited', 'deleted', 'reactions', 'att', 'pinned', 'fwd', 'progress'];
const contentOf = (m) => Object.fromEntries(CONTENT.filter((k) => m[k] !== undefined).map((k) => [k, m[k]]));
async function putMessage(m, extra = []) { await write([['messages', { ...metaOf(m), box: await seal(contentOf(m)) }], ...extra]); }
async function readMessage(row) { return { ...metaOf(row), ...(await unseal(row.box)) }; }
async function loadMessage(mid) { const r = await get('messages', mid); return r ? readMessage(r) : null; }
async function updateMessage(mid, fn) {
  const m = await loadMessage(mid);
  if (!m) return null;
  fn(m);
  await putMessage(m);
  emit('message', m);
  return m;
}
async function convRows(cid, limit = Infinity, dir = 'prev') {
  const rows = [];
  await new Promise((res, rej) => {
    const r = db.transaction('messages').objectStore('messages').index('conv').openCursor(IDBKeyRange.bound([cid, -Infinity], [cid, Infinity]), dir);
    r.onsuccess = () => { const c = r.result; if (c && rows.length < limit) { rows.push(c.value); c.continue(); } else res(); };
    r.onerror = () => rej(r.error);
  });
  return rows;
}
async function messages(cid, limit = 300) { return Promise.all((await convRows(cid, limit)).reverse().map(readMessage)); }

// ------------------------------------------------------------------ encrypting and delivering payloads
// ---- groups: the roster comes ONLY from end-to-end encrypted state messages of a group admin.
const serverMembers = new Map(); // cid -> { at, list }
async function membersOf(cid, refresh = false) {
  const c = serverMembers.get(cid);
  if (c && !refresh && Date.now() - c.at < 30000) return c.list;
  const { members } = await api(`/groups/${encodeURIComponent(cid)}/members`);
  serverMembers.set(cid, { at: Date.now(), list: members });
  return members;
}
async function targetsFor(cid, peerId, refresh, users = null) {
  const own = await contact(me.id, refresh);
  const t = {};
  if (peerId) {
    const peer = await contact(peerId, refresh);
    if (peer.pending_msk) throw new Error('key_changed');
    Object.assign(t, peer.devices);
    if (!Object.keys(peer.devices).length) throw new Error('peer_has_no_devices');
  } else {
    const g = await get('groups', cid);
    if (!g) throw new Error('group_state_missing');
    const onServer = new Set((await membersOf(cid, refresh)).map((m) => m.id));
    const skipped = [];
    // Intersection: a member the server added WITHOUT an admin's encrypted announcement gets nothing;
    // a member the server removed gets nothing either.
    for (const u of users || g.members) {
      if (u === me.id || !onServer.has(u)) continue;
      let c;
      try { c = await contact(u, refresh); } catch { skipped.push(u); continue; }
      if (c.pending_msk) { skipped.push(u); continue; }
      Object.assign(t, c.devices);
    }
    if (skipped.length) emit('skipped', { cid, users: skipped });
  }
  for (const [d, v] of Object.entries(own.devices)) if (d !== deviceId) t[d] = v;
  return t;
}
async function deliver(cid, peerId, mid, payload, attachmentIds = [], attempt = 0, users = null) {
  const targets = await targetsFor(cid, peerId, attempt > 0, users);
  const need = [];
  for (const d of Object.keys(targets)) if (!(await sessionsFor(d)).length) need.push(d);
  if (need.length) {
    const { keys } = await api('/keys/claim', { method: 'POST', body: { device_ids: need } });
    for (const d of need) {
      const k = keys[d];
      if (!k) continue;
      if (!verify_ed25519(targets[d].sk, `${k.type === 'fallback' ? 'pm-fbk-v1' : 'pm-otk-v1'}:${k.id}:${k.key}`, k.sig)) throw new Error('bad_prekey_signature');
      sessions.set(d, [account.create_outbound_session(targets[d].ik, k.key)]);
    }
  }
  const envelopes = [];
  for (const d of Object.keys(targets)) {
    const s = (await sessionsFor(d))[0];
    if (!s) continue;
    const e = s.encrypt(payload);
    envelopes.push({ device_id: d, type: e.message_type, ciphertext: b64(e.ciphertext) });
  }
  await write(sessionOps(Object.keys(targets)));
  try {
    await api('/messages', { method: 'POST', body: { conversation_id: cid, client_msg_id: mid, protocol_version: 1, envelopes, attachment_ids: attachmentIds } });
  } catch (e) {
    if (e.message === 'devices_mismatch' && attempt < 2) {
      for (const d of e.data.stale || []) sessions.delete(d);
      return deliver(cid, peerId, mid, payload, attachmentIds, attempt + 1, users);
    }
    throw e;
  }
}
const payloadOf = (cid, mid, t, body, ts = Date.now()) => JSON.stringify({ v: 1, t, cid, mid, su: me.id, sd: deviceId, ts, body });

/** Sends a new visible message (text or file). */
async function sendMessage(cid, peerId, t, body, extra = {}, mid = newId()) {
  const ts = Date.now();
  const m = { mid, cid, ts, dir: 'out', status: 'sending', from: me.id, text: body.text || '', reply_to: body.reply_to || null, att: body.att || null, fwd: body.fwd || false, ...extra };
  await putMessage(m); emit('message', m);
  try {
    await deliver(cid, peerId, mid, payloadOf(cid, mid, t, body, ts), body.att ? [body.att.id] : []);
    m.status = 'sent'; m.flag = null; pendingStatus.add(mid);
  } catch (e) { m.status = 'failed'; m.flag = e.message; }
  await putMessage(m); emit('message', m);
  return mid;
}

/** Sends an event that changes an existing message (edit / delete / reaction / pin) and applies it locally. */
async function sendEvent(cid, peerId, t, body) {
  const mid = newId();
  const p = { v: 1, t, cid, mid, su: me.id, sd: deviceId, ts: Date.now(), body };
  if (!(await applyEvent(p))) throw new Error('target_not_found');
  await write([['events', { mid, applied: true }]]);
  await deliver(cid, peerId, mid, JSON.stringify(p));
}

/** Applies an edit/delete/reaction/pin. Returns false when the target message is not (yet) here. */
async function applyEvent(p) {
  const row = await get('messages', p.body?.target);
  if (!row || row.cid !== p.cid) return false;
  const m = await readMessage(row);
  switch (p.t) {
    case 'edit':
      if (m.from !== p.su || m.deleted || m.att || typeof p.body.text !== 'string' || !p.body.text.trim()) return true; // only the author may edit text
      m.text = p.body.text.slice(0, MAX_TEXT); m.edited = p.ts; break;
    case 'delete':
      if (m.from !== p.su) return true; // only the author may delete for everyone
      m.text = ''; m.att = null; m.deleted = true; m.reactions = {}; m.reply_to = null; fileCache.delete(m.mid); break;
    case 'reaction':
      m.reactions = m.reactions || {};
      if (typeof p.body.emoji === 'string' && p.body.emoji) m.reactions[p.su] = [...p.body.emoji].slice(0, 2).join(''); else delete m.reactions[p.su];
      break;
    case 'pin': m.pinned = !!p.body.pinned; break;
    default: return true;
  }
  await putMessage(m); emit('message', m);
  return true;
}
async function applyPendingEvents(targetMid) {
  for (const e of await getAll('events')) {
    if (!e.applied && e.p?.body?.target === targetMid && (await applyEvent(e.p))) await write([['events', { mid: e.mid, applied: true }]]);
  }
}

// ------------------------------------------------------------------ encrypted files (AES-256-GCM, STREAM)
function nonce(prefix, i, last) {
  const n = new Uint8Array(12);
  n.set(prefix, 0); new DataView(n.buffer).setUint32(7, i); n[11] = last ? 1 : 0;
  return n;
}
async function putChunk(id, index, body) {
  const sha = hex(await crypto.subtle.digest('SHA-256', body));
  for (let attempt = 0; ; attempt++) {
    let status = 0;
    try {
      const r = await fetch(`${API}/attachments/${id}/chunks/${index}`, { method: 'POST', credentials: 'same-origin', body,
        headers: { 'Content-Type': 'application/octet-stream', 'X-PM-Request': '1', 'X-Chunk-SHA256': sha } });
      status = r.status;
      if (r.ok) return;
      const j = await r.json().catch(() => ({}));
      if (status >= 400 && status < 500 && status !== 408 && status !== 429) throw Object.assign(new Error(j?.error?.code || `http_${status}`), { fatal: true });
      if (status === 507) throw Object.assign(new Error('insufficient_storage'), { fatal: true });
    } catch (e) { if (e.fatal || attempt >= 7) throw e; }
    // Connection lost or server busy: wait, then ask the server what it already has (the response may have been lost).
    await sleep(Math.min(30000, 1000 * 2 ** attempt));
    try { if ((await api(`/attachments/${id}/status`)).received.includes(index)) return; } catch { /* still offline */ }
  }
}
async function uploadFile(file, cid, mid) {
  const size = file.size, nseg = Math.max(1, Math.ceil(size / SEG)), csize = size + 16 * nseg;
  const keyRaw = crypto.getRandomValues(new Uint8Array(32)), prefix = crypto.getRandomValues(new Uint8Array(7));
  const key = await crypto.subtle.importKey('raw', keyRaw, 'AES-GCM', false, ['encrypt']);
  const up = await api('/attachments', { method: 'POST', body: { conversation_id: cid, size: csize } });
  const per = up.chunk_size / SEGC;
  for (let c = 0; c < up.chunk_count; c++) {
    const parts = [];
    for (let s = c * per; s < Math.min(nseg, (c + 1) * per); s++) {
      const plain = new Uint8Array(await file.slice(s * SEG, Math.min(size, (s + 1) * SEG)).arrayBuffer());
      parts.push(new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv: nonce(prefix, s, s === nseg - 1), additionalData: FILE_AAD }, key, plain)));
    }
    await putChunk(up.id, c, concat(...parts));
    emit('progress', { mid, done: Math.min(csize, (c + 1) * up.chunk_size), total: csize });
  }
  const done = await api(`/attachments/${up.id}/complete`, { method: 'POST', body: {} });
  return { id: up.id, key: b64(keyRaw), prefix: b64(prefix), size, csize, exp: done.expires_at };
}
async function downloadFile(att) {
  const r = await fetch(`${API}/attachments/${encodeURIComponent(att.id)}/blob`, { credentials: 'same-origin', headers: { 'X-PM-Request': '1' } });
  if (r.status === 410) throw new Error('file_expired');
  if (!r.ok) throw new Error('download_failed');
  const key = await crypto.subtle.importKey('raw', b64dec(att.key), 'AES-GCM', false, ['decrypt']);
  const prefix = b64dec(att.prefix), nseg = Math.max(1, Math.ceil(att.size / SEG)), out = [];
  const reader = r.body.getReader();
  let buf = new Uint8Array(0), seg = 0;
  for (;;) {
    const { done, value } = await reader.read();
    if (value) buf = concat(buf, value);
    while (buf.length >= SEGC || (done && buf.length > 0)) {
      if (seg >= nseg) throw new Error('file_corrupt');
      const take = Math.min(SEGC, buf.length);
      // GCM tag + index-bound nonce + last-segment flag: reordering, truncation or extension is detected.
      out.push(new Uint8Array(await crypto.subtle.decrypt({ name: 'AES-GCM', iv: nonce(prefix, seg, seg === nseg - 1), additionalData: FILE_AAD }, key, buf.subarray(0, take))));
      buf = buf.slice(take); seg++;
    }
    if (done) break;
  }
  if (seg !== nseg) throw new Error('file_corrupt');
  return new Blob(out, { type: att.mime || 'application/octet-stream' });
}
async function sendFile(cid, peerId, file, meta, caption, replyTo, mid = newId()) {
  const att = { name: String(meta.name || file.name || 'file').slice(0, 200), mime: String(meta.mime || file.type || 'application/octet-stream').slice(0, 100),
    kind: ['image', 'video', 'audio', 'file'].includes(meta.kind) ? meta.kind : 'file', size: file.size,
    w: meta.w || null, h: meta.h || null, dur: meta.dur || null, thumb: typeof meta.thumb === 'string' && meta.thumb.length < 40000 ? meta.thumb : null, q: meta.q || null };
  const m = { mid, cid, ts: Date.now(), dir: 'out', status: 'uploading', from: me.id, text: caption || '', reply_to: replyTo || null, att, progress: 0 };
  unsentFiles.set(mid, file);
  fileCache.set(mid, file);
  await putMessage(m); emit('message', m);
  try {
    Object.assign(att, await uploadFile(file, cid, mid));
  } catch (e) {
    m.status = 'failed'; m.flag = e.message; await putMessage(m); emit('message', m);
    return mid;
  }
  await write([['messages', undefined, mid]]);
  return sendMessage(cid, peerId, 'file', { att, text: caption || '', reply_to: replyTo || null }, {}, mid);
}

// ------------------------------------------------------------------ receiving
const validStr = (x, max) => typeof x === 'string' && x.length <= max;
function validPayload(p, env) {
  // The server cannot move a message to another conversation or impersonate a sender: all bound inside the ciphertext.
  if (p?.v !== 1 || p.cid !== env.conversation_id || p.su !== env.sender_user_id || p.sd !== env.sender_device_id || p.mid !== env.message_id) return false;
  const b = p.body || {};
  switch (p.t) {
    case 'text': return validStr(b.text, MAX_TEXT) && (b.reply_to == null || validStr(b.reply_to, 30));
    case 'file': return validStr(b.text ?? '', MAX_TEXT) && validStr(b.att?.id, 64) && validStr(b.att?.key, 64) && validStr(b.att?.prefix, 16) && Number.isSafeInteger(b.att?.size);
    case 'read': return Array.isArray(b.mids) && b.mids.length <= 200 && b.mids.every((x) => validStr(x, 30));
    case 'group_state': return validStr(b.name, 100) && Number.isSafeInteger(b.version) && b.version > 0 && validStr(b.owner, 26)
      && Array.isArray(b.members) && b.members.length <= 64 && b.members.every((x) => validStr(x, 26))
      && Array.isArray(b.admins) && b.admins.every((x) => b.members.includes(x));
    case 'call': return validStr(b.call_id, 26) && CALL_ACTIONS.includes(b.action) && (b.sdp == null || validStr(b.sdp, 30000)) && typeof (b.video ?? false) === 'boolean';
    default: return EVENT_TYPES.includes(p.t) && validStr(b.target, 30);
  }
}
async function decryptFrom(env, allowNew) {
  let c, dev, flag = null;
  try {
    c = await contact(env.sender_user_id);
    dev = c.devices?.[env.sender_device_id];
    if (!dev) {
      c = await contact(env.sender_user_id, true);
      dev = c.devices?.[env.sender_device_id];
      if (!dev && c.pending_devices?.[env.sender_device_id]) { dev = c.pending_devices[env.sender_device_id]; flag = 'new_key'; }
    }
  } catch { /* unknown sender */ }
  if (!dev) return { error: 'untrusted_device' };
  const ct = b64dec(env.ciphertext);
  const list = await sessionsFor(env.sender_device_id);
  let plaintext = null, accountChanged = false;
  try {
    if (env.type === 0) {
      const s = list.find((x) => x.matches_pre_key_message(ct));
      if (s) plaintext = s.decrypt(0, ct);
      else if (allowNew) {
        const r = account.create_inbound_session(dev.ik, ct); // fails unless sent by the certified identity key
        plaintext = r.plaintext; list.unshift(r.take_session()); accountChanged = true;
      }
    } else {
      for (let i = 0; i < list.length && plaintext === null; i++) {
        try { plaintext = list[i].decrypt(1, ct); if (i) list.unshift(...list.splice(i, 1)); } catch { /* next session */ }
      }
    }
  } catch { /* undecryptable */ }
  if (plaintext === null) return { error: 'decrypt_failed' };
  sessions.set(env.sender_device_id, list);
  const ops = sessionOps([env.sender_device_id]);
  if (accountChanged) ops.push(['kv', account.pickle(pk), 'account']);
  let p = null;
  try { p = JSON.parse(plaintext); } catch { /* invalid */ }
  return { p, ops, flag };
}

async function receive(env) {
  if ((await get('messages', env.message_id)) || (await get('events', env.message_id))) return; // duplicate delivery
  const base = { mid: env.message_id, cid: env.conversation_id, ts: Date.parse(env.created_at.replace(' ', 'T') + 'Z') || Date.now(), dir: 'in', status: 'received', from: env.sender_user_id };
  const fail = async (flag, ops = []) => { const m = { ...base, text: '', flag }; await putMessage(m, ops); emit('message', m); };
  const r = await decryptFrom(env, true);
  if (r.error) return fail(r.error);
  const { p, ops, flag } = r;
  if (!p || !validPayload(p, env)) return fail('invalid_payload', ops);
  if (p.t === 'text' || p.t === 'file') {
    const mine = p.su === me.id; // sent from another of my devices
    let rflag = flag;
    if (!mine) { const g = await get('groups', p.cid); if (g && !g.members.includes(p.su)) rflag = 'not_in_roster'; }
    const m = { ...base, dir: mine ? 'out' : 'in', status: mine ? 'sent' : 'received', ts: mine ? p.ts : base.ts, text: p.body.text || '', reply_to: p.body.reply_to || null,
      flag: rflag, fwd: p.body.fwd === true, att: p.t === 'file' ? sanitizeAtt(p.body.att) : null };
    await putMessage(m, ops); emit('message', m);
    await applyPendingEvents(m.mid);
    return;
  }
  if (p.t === 'group_state') {
    await write([...ops, ['events', { mid: p.mid, applied: true }]]);
    await applyGroupState(p.cid, p.su, p.body, p.ts);
    return;
  }
  if (p.t === 'call') {
    await write([...ops, ['events', { mid: p.mid, applied: true }]]);
    // Offers older than 90 s are stale (e.g. delivered after the device was offline): never ring for them.
    if (Date.now() - p.ts < 90000 || p.body.action !== 'offer') emit('call', { cid: p.cid, from: p.su, ts: p.ts, ...p.body });
    return;
  }
  await write([...ops, ['events', { mid: p.mid, applied: false, p }]]);
  if (p.t === 'read') {
    for (const mid of p.body.mids) {
      const row = await get('messages', mid);
      if (row && row.cid === p.cid && p.su === me.id && row.dir === 'in' && row.status === 'received') { // read on another of my devices
        row.status = 'read'; await write([['messages', row]]); emit('conversations_changed', {});
      } else if (row && row.cid === p.cid && row.dir === 'out' && row.status !== 'read' && p.su !== me.id) {
        row.status = 'read'; pendingStatus.delete(mid);
        await write([['messages', row]]); emit('status', { mid, status: 'read' });
      }
    }
    await write([['events', { mid: p.mid, applied: true }]]);
  } else if (await applyEvent(p)) {
    await write([['events', { mid: p.mid, applied: true }]]);
  }
}
function sanitizeAtt(a) {
  return { id: a.id, key: a.key, prefix: a.prefix, size: a.size, csize: a.csize, exp: validStr(a.exp, 30) ? a.exp : null,
    name: validStr(a.name, 200) ? a.name : 'file', mime: validStr(a.mime, 100) ? a.mime : 'application/octet-stream',
    kind: ['image', 'video', 'audio', 'file'].includes(a.kind) ? a.kind : 'file', w: Number(a.w) || null, h: Number(a.h) || null, dur: Number(a.dur) || null,
    thumb: validStr(a.thumb, 40000) && /^data:image\/(jpeg|webp|png);base64,[A-Za-z0-9+/=]+$/.test(a.thumb) ? a.thumb : null, q: validStr(a.q, 10) ? a.q : null };
}

async function receiveEphemeral(env) {
  if (env.type !== 1) return; // typing signals only ride on established sessions
  const r = await decryptFrom({ ...env, message_id: null }, false);
  if (r.error || !r.p) return;
  await write(r.ops);
  const p = r.p;
  if (p.v === 1 && p.t === 'typing' && p.cid === env.conversation_id && p.su === env.sender_user_id && p.sd === env.sender_device_id && Date.now() - p.ts < 30000) {
    emit('typing', { cid: p.cid, user: p.su });
  }
}

// ------------------------------------------------------------------ groups
async function sysMessage(cid, text) {
  const m = { mid: 'sys-' + newId(), cid, ts: Date.now(), dir: 'sys', status: 'sys', from: '', text };
  await putMessage(m); emit('message', m);
}
/**
 * Accepts a roster only from an admin of the CURRENT roster with a higher version. For a group we know
 * nothing about yet (just created / we were just added), the first state must come from one of its admins.
 */
async function applyGroupState(cid, from, st, ts) {
  const local = await get('groups', cid);
  if (local ? !(local.admins.includes(from) && st.version > local.version) : !st.admins.includes(from)) return false;
  const next = { cid, name: st.name, version: st.version, members: [...new Set(st.members)], admins: [...new Set(st.admins)], owner: st.owner, updated: ts };
  await write([['groups', next]]);
  serverMembers.delete(cid);
  const mine = from === me.id, fa = (n) => n.toLocaleString('fa-IR');
  if (!local) await sysMessage(cid, mine ? `گروه «${st.name}» را ساختید.` : `شما به گروه «${st.name}» اضافه شدید.`);
  else {
    const added = next.members.filter((x) => !local.members.includes(x)).length, removed = local.members.filter((x) => !next.members.includes(x)).length;
    if (local.name !== next.name) await sysMessage(cid, mine ? `نام گروه را به «${next.name}» تغییر دادید.` : `یک مدیر نام گروه را به «${next.name}» تغییر داد.`);
    if (added) await sysMessage(cid, mine ? `${fa(added)} عضو جدید اضافه کردید.` : `یک مدیر ${fa(added)} عضو جدید اضافه کرد.`);
    if (removed) await sysMessage(cid, !next.members.includes(me.id) ? 'شما از گروه حذف شدید.' : mine ? `${fa(removed)} عضو را از گروه حذف کردید.` : `${fa(removed)} عضو از گروه حذف شد.`);
    if (next.admins.length !== local.admins.length) await sysMessage(cid, 'مدیران گروه تغییر کردند.');
  }
  emit('group_changed', { cid });
  return true;
}
/** Admin publishes a new roster version to every member in it (and my other devices). */
async function publishGroupState(cid, mutate) {
  const g = await get('groups', cid);
  if (!g || !g.admins.includes(me.id)) throw new Error('not_group_admin');
  const next = mutate({ ...g, members: [...g.members], admins: [...g.admins] });
  next.version = g.version + 1;
  const body = { name: next.name, version: next.version, members: next.members, admins: next.admins.filter((a) => next.members.includes(a)), owner: next.owner };
  const mid = newId();
  await write([['events', { mid, applied: true }]]);
  await applyGroupState(cid, me.id, body, Date.now());
  serverMembers.delete(cid);
  await deliver(cid, null, mid, payloadOf(cid, mid, 'group_state', body), [], 0, body.members);
}

// ------------------------------------------------------------------ recovery key (Crockford base32, 256 bits)
const B32 = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
function b32enc(u8) { let bits = 0, val = 0, out = ''; for (const x of u8) { val = (val << 8) | x; bits += 8; while (bits >= 5) { out += B32[(val >>> (bits - 5)) & 31]; bits -= 5; } } if (bits) out += B32[(val << (5 - bits)) & 31]; return out; }
function b32dec(s) {
  s = s.toUpperCase().replace(/[\s-]/g, '').replace(/[IL]/g, '1').replace(/O/g, '0').replace(/U/g, 'V');
  let bits = 0, val = 0; const out = [];
  for (const ch of s) { const i = B32.indexOf(ch); if (i < 0) throw new Error('invalid_recovery_key'); val = (val << 5) | i; bits += 5; if (bits >= 8) { out.push((val >>> (bits - 8)) & 255); bits -= 8; } }
  if (out.length !== 32) throw new Error('invalid_recovery_key');
  return new Uint8Array(out);
}
async function backupKey(rk, userId) {
  const base = await crypto.subtle.importKey('raw', rk, 'HKDF', false, ['deriveKey']);
  return crypto.subtle.deriveKey({ name: 'HKDF', hash: 'SHA-256', salt: enc.encode(userId), info: enc.encode('pm/key-backup/v1') }, base, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
}
async function createRecovery() {
  const rk = crypto.getRandomValues(new Uint8Array(32));
  const key = await backupKey(rk, me.id);
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const ct = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv, additionalData: enc.encode(`pm-backup-v1|${me.id}|${msk.public_key()}`) }, key,
    enc.encode(JSON.stringify({ v: 1, msk: b64(Uint8Array.from(msk.to_bytes())) }))));
  await api('/me/key-backup', { method: 'POST', body: { algorithm: 'pm-backup-v1/hkdf-aes256gcm', ciphertext: btoa(String.fromCharCode(...concat(iv, ct))) } });
  return b32enc(rk).match(/.{1,4}/g).join('-');
}
/** Registers this browser as ANOTHER device of the existing identity (master key restored or received). */
async function joinIdentity(registerFn) {
  account = new Account();
  account.generate_one_time_keys(OTK_TARGET); account.generate_fallback_key();
  const ik = account.curve25519_key(), sk = account.ed25519_key();
  const device = { identity_key: ik, signing_key: sk, certificate_sig: msk.sign(certificate(me.id, ik, sk)),
    one_time_keys: signedPrekeys(account.one_time_keys(), 'pm-otk-v1'), fallback_key: signedPrekeys(account.fallback_key(), 'pm-fbk-v1')[0] };
  deviceId = await registerFn(device);
  account.mark_keys_as_published();
  sessions.clear();
  await saveAccount([['kv', await seal([...msk.to_bytes()]), 'msk'], ['kv', deviceId, 'device_id'], ['kv', me.id, 'user'], ['kv', 0, 'cursor'], ['kv', 0, 'ncursor']]);
  cursor = 0; ncursor = 0;
}
async function restoreFromRecovery(code) {
  const rk = b32dec(code);
  const boot = await api('/bootstrap');
  let b;
  try { b = await api('/me/key-backup'); } catch { throw new Error('no_backup'); }
  const raw = Uint8Array.from(atob(b.ciphertext), (c) => c.charCodeAt(0));
  let data;
  try {
    data = JSON.parse(dec.decode(await crypto.subtle.decrypt({ name: 'AES-GCM', iv: raw.subarray(0, 12), additionalData: enc.encode(`pm-backup-v1|${me.id}|${boot.identity.msk}`) },
      await backupKey(rk, me.id), raw.subarray(12))));
  } catch { throw new Error('wrong_recovery_key'); }
  msk = SigningKey.from_bytes(b64dec(data.msk));
  if (msk.public_key() !== boot.identity.msk) throw new Error('wrong_recovery_key');
  await joinIdentity(async (device) => (await api('/devices/add', { method: 'POST', body: { device } })).device_id);
}

// ------------------------------------------------------------------ linking a new device
async function linkCode(id, ik, sk) {
  const h = new Uint8Array(await crypto.subtle.digest('SHA-256', enc.encode(`pm-link-v1|${id}|${ik}|${sk}`)));
  const n = (h[0] * 2 ** 32 + h[1] * 2 ** 24 + h[2] * 2 ** 16 + h[3] * 2 ** 8 + h[4]) % 1e8;
  return String(n).padStart(8, '0').replace(/(\d{4})(\d{4})/, '$1 $2');
}
async function linkStart() {
  const acc = new Account();
  acc.generate_one_time_keys(1);
  const ik = acc.curve25519_key(), sk = acc.ed25519_key();
  account = acc;
  const otks = signedPrekeys(acc.one_time_keys(), 'pm-otk-v1');
  const { id } = await api('/device-links', { method: 'POST', body: { device: { identity_key: ik, signing_key: sk, one_time_keys: otks } } });
  acc.mark_keys_as_published();
  await write([['kv', { id, account: acc.pickle(pk) }, 'pending_link']]);
  return { id, code: await linkCode(id, ik, sk) };
}
async function linkPoll() {
  const pend = await get('kv', 'pending_link');
  if (!pend) return { status: 'none' };
  const st = await api(`/device-links/${pend.id}`);
  if (st.status !== 'approved') { if (st.status !== 'waiting') await write([['kv', undefined, 'pending_link']]); return { status: st.status }; }
  account = Account.from_pickle(pend.account, pk);
  const boot = await api('/bootstrap');
  const r = account.create_inbound_session(st.approver_identity_key, Uint8Array.from(atob(st.payload), (c) => c.charCodeAt(0)));
  const data = JSON.parse(r.plaintext);
  const m = SigningKey.from_bytes(b64dec(data.msk));
  if (data.t !== 'link' || data.link !== pend.id || m.public_key() !== boot.identity.msk) throw new Error('link_invalid');
  // The approving device must itself be certified by this master key.
  const mine = await api(`/users/${encodeURIComponent(me.id)}/keys`);
  const ap = mine.devices.find((d) => d.id === st.approver_device_id);
  if (!ap || ap.identity_key !== st.approver_identity_key || !verify_ed25519(m.public_key(), certificate(me.id, ap.identity_key, ap.signing_key), ap.certificate_sig)) throw new Error('link_invalid');
  msk = m; deviceId = st.device_id;
  sessions.clear(); sessions.set(ap.id, [r.take_session()]);
  account.generate_fallback_key();
  await api('/keys/upload', { method: 'POST', body: { one_time_keys: [], fallback_key: signedPrekeys(account.fallback_key(), 'pm-fbk-v1')[0] } });
  account.mark_keys_as_published();
  await saveAccount([['kv', await seal([...msk.to_bytes()]), 'msk'], ['kv', deviceId, 'device_id'], ['kv', me.id, 'user'], ['kv', 0, 'cursor'], ['kv', 0, 'ncursor'],
    ['kv', undefined, 'pending_link'], ...sessionOps([ap.id])]);
  cursor = 0; ncursor = 0;
  return { status: 'approved', ...(await ready('')) };
}
async function linkRequests() {
  const { requests } = await api('/device-links');
  for (const r of requests) r.code = await linkCode(r.id, r.identity_key, r.signing_key);
  return requests;
}
async function linkApprove(id) {
  const r = (await api('/device-links')).requests.find((x) => x.id === id);
  if (!r?.otk || !verify_ed25519(r.signing_key, `pm-otk-v1:${r.otk.id}:${r.otk.key}`, r.otk.sig)) throw new Error('link_not_found');
  const s = account.create_outbound_session(r.identity_key, r.otk.key);
  const e = s.encrypt(JSON.stringify({ v: 1, t: 'link', link: id, msk: b64(Uint8Array.from(msk.to_bytes())) }));
  await api(`/device-links/${id}/approve`, { method: 'POST', body: { certificate_sig: msk.sign(certificate(me.id, r.identity_key, r.signing_key)), payload_type: e.message_type, payload: b64(e.ciphertext) } });
  sessions.set(r.device_id, [s]);
  await write(sessionOps([r.device_id]));
  await contact(me.id, true);
}

// ------------------------------------------------------------------ sync loop
async function syncOnce() {
  if (syncing || stopped) return;
  syncing = true;
  try {
    const r = await api(`/sync?cursor=${cursor}&ncursor=${ncursor}`);
    for (const e of r.ephemeral || []) { try { await receiveEphemeral(e); } catch { /* ignore */ } }
    const acks = [];
    for (const env of r.envelopes) { try { await receive(env); } catch (e) { console.warn('receive failed', e); } acks.push(env.id); }
    if (acks.length) await api('/sync/ack', { method: 'POST', body: { ids: acks } });
    for (const n of r.notifications) {
      if ((n.type === 'contact_identity_changed' || n.type === 'contact_device_added') && n.subject_user_id) { try { await contact(n.subject_user_id, true); } catch { /* gone */ } }
      if (n.type === 'admin_reset') emit('state', { state: 'revoked' });
      if (n.type === 'device_link_request') emit('link_request', {});
      if (n.type === 'group_changed') { serverMembers.clear(); emit('conversations_changed', {}); }
      if (n.type === 'device_added' || n.type === 'device_revoked') { try { await contact(me.id, true); } catch { /* ignore */ } }
    }
    cursor = r.cursor; ncursor = r.ncursor;
    await write([['kv', cursor, 'cursor'], ['kv', ncursor, 'ncursor']]);
    if (pendingStatus.size) {
      const { status } = await api('/messages/status', { method: 'POST', body: { ids: [...pendingStatus].slice(0, 100) } });
      for (const [mid, st] of Object.entries(status)) {
        if (st !== 'delivered') continue;
        pendingStatus.delete(mid);
        const row = await get('messages', mid);
        if (row && row.status === 'sent') { row.status = 'delivered'; await write([['messages', row]]); emit('status', { mid, status: 'delivered' }); }
      }
    }
    await replenish(r.otk_count);
    if (acks.length) emit('conversations_changed', {});
  } catch (e) {
    if (e.status === 401) { emit('state', { state: 'logged_out' }); stopped = true; }
    else if (e.status === 410) { emit('state', { state: 'revoked' }); stopped = true; }
  } finally { syncing = false; }
}
function schedule() {
  clearTimeout(timer);
  if (!stopped) timer = setTimeout(async () => { await syncOnce(); schedule(); }, callMode ? 1000 : visible ? 2500 : 15000);
}

// ------------------------------------------------------------------ safety number (Signal numeric fingerprint)
async function fingerprintPart(keyB64, id) {
  const key = b64dec(keyB64);
  let h = concat(new Uint8Array([0, 0]), key, enc.encode(id));
  for (let i = 0; i < 5200; i++) h = new Uint8Array(await crypto.subtle.digest('SHA-512', concat(h, key)));
  let s = '';
  for (let i = 0; i < 30; i += 5) s += String((h[i] * 2 ** 32 + h[i + 1] * 2 ** 24 + h[i + 2] * 2 ** 16 + h[i + 3] * 2 ** 8 + h[i + 4]) % 100000).padStart(5, '0');
  return s;
}
async function safety(userId) {
  const c = await contact(userId);
  const parts = [await fingerprintPart(msk.public_key(), me.id), await fingerprintPart(c.msk, userId)].sort();
  return { number: (parts[0] + parts[1]).match(/.{5}/g), verified: !!c.verified, pending: !!c.pending_msk };
}

// ------------------------------------------------------------------ startup
async function start() {
  if (!self.indexedDB || !self.crypto?.subtle || !self.WebAssembly || !navigator.locks) return { state: 'unsupported' };
  await init();
  const boot = await api('/bootstrap');
  me = boot.me; notifyCfg = boot.notify || notifyCfg;
  // One tab at a time: two tabs advancing the same ratchet would corrupt it.
  const got = await new Promise((resolve) => {
    navigator.locks.request(`pm-e2ee-${me.id}`, { ifAvailable: true }, (lock) => {
      if (!lock) { resolve(false); return undefined; }
      resolve(true);
      return new Promise(() => {}); // held for the lifetime of this worker
    });
  });
  if (!got) return { state: 'locked' };
  db = await openDb(`pm-e2ee-${me.id}`);
  await loadLocalKeys();
  const saved = await get('kv', 'account');
  if (saved) {
    account = Account.from_pickle(saved, pk);
    msk = SigningKey.from_bytes(Uint8Array.from(await unseal(await get('kv', 'msk'))));
    deviceId = await get('kv', 'device_id');
    cursor = (await get('kv', 'cursor')) || 0; ncursor = (await get('kv', 'ncursor')) || 0;
    if (boot.identity.msk !== msk.public_key()) return { state: 'revoked', me };
    if (boot.device?.id !== deviceId) { try { await bindDevice(); } catch { return { state: 'revoked', me }; } }
  } else if (boot.identity.msk) {
    const pend = await get('kv', 'pending_link');
    if (pend) { account = Account.from_pickle(pend.account, pk); return { state: 'link_waiting', me, id: pend.id, code: await linkCode(pend.id, account.curve25519_key(), account.ed25519_key()) }; }
    return { state: 'identity_elsewhere', me };
  } else {
    await createIdentity(false);
  }
  return ready(boot.name);
}
let notifyCfg = { enabled: true, preview: 'none' };
async function ready(name) {
  const st = await api('/bootstrap');
  await replenish(st.device?.otk_count ?? OTK_TARGET);
  for (const row of await getAll('messages')) {
    if (row.dir === 'out' && row.status === 'sent') pendingStatus.add(row.mid);
    if (row.status === 'uploading' || row.status === 'sending') { row.status = 'failed'; await write([['messages', row]]); } // interrupted by a reload
  }
  stopped = false;
  syncOnce().then(schedule);
  let needsRecovery = false;
  try { await api('/me/key-backup'); } catch (e) { needsRecovery = e.status === 404; }
  return { state: 'ready', me, device_id: deviceId, name, needs_recovery: needsRecovery, notify: notifyCfg };
}

async function conversations() {
  const { conversations: list } = await api('/conversations');
  for (const c of list) {
    const rows = await convRows(c.id, 30);
    const last = rows.length ? await readMessage(rows[0]) : null;
    c.last = last ? { text: last.deleted ? '' : last.text, att: last.att ? last.att.kind : null, deleted: !!last.deleted, flag: last.flag, ts: last.ts, dir: last.dir } : null;
    c.unread = rows.filter((r) => r.dir === 'in' && r.status === 'received').length;
    if (c.type === 'group') { const g = await get('groups', c.id); c.name = g?.name || 'گروه'; c.is_admin = !!g?.admins.includes(me.id); c.has_state = !!g; }
  }
  return list;
}

const commands = {
  start,
  async reset_identity() { if (!db) { db = await openDb(`pm-e2ee-${me.id}`); await loadLocalKeys(); } await createIdentity(true); cursor = 0; ncursor = 0; return ready(''); },
  conversations,
  recovery_create: () => createRecovery(),
  async recovery_restore({ code }) {
    if (!db) { db = await openDb(`pm-e2ee-${me.id}`); await loadLocalKeys(); }
    await restoreFromRecovery(code);
    return ready('');
  },
  link_start: () => linkStart(),
  link_poll: () => linkPoll(),
  async link_cancel() { await write([['kv', undefined, 'pending_link']]); },
  link_requests: () => linkRequests(),
  link_approve: ({ id }) => linkApprove(id),
  async link_reject({ id }) { await api(`/device-links/${id}/reject`, { method: 'POST', body: {} }); },
  async my_devices() { return (await api('/me/devices')).devices; },
  async revoke_device({ id }) { await api(`/devices/${encodeURIComponent(id)}/revoke`, { method: 'POST', body: {} }); await contact(me.id, true); },
  async create_group({ name, members }) {
    const { id } = await api('/groups', { method: 'POST', body: { members } });
    const body = { name: String(name).trim().slice(0, 100) || 'گروه', version: 1, members: [me.id, ...members], admins: [me.id], owner: me.id };
    const mid = newId();
    await write([['events', { mid, applied: true }]]);
    await applyGroupState(id, me.id, body, Date.now());
    await deliver(id, null, mid, payloadOf(id, mid, 'group_state', body), [], 0, body.members);
    return id;
  },
  async group_info({ cid }) {
    const g = await get('groups', cid);
    const members = await membersOf(cid, true);
    for (const m of members) {
      m.in_roster = !!g?.members.includes(m.id);
      try { const c = m.id === me.id ? null : await contact(m.id); m.key_changed = !!c?.pending_msk; } catch { m.key_changed = false; }
    }
    return { name: g?.name || 'گروه', admins: g?.admins || [], owner: g?.owner, members, me: me.id, is_admin: !!g?.admins.includes(me.id) };
  },
  async group_add({ cid, user_id }) {
    await api(`/groups/${cid}/members`, { method: 'POST', body: { user_id } });
    await publishGroupState(cid, (g) => { if (!g.members.includes(user_id)) g.members.push(user_id); return g; });
  },
  async group_remove({ cid, user_id }) {
    await publishGroupState(cid, (g) => { g.members = g.members.filter((x) => x !== user_id); g.admins = g.admins.filter((x) => x !== user_id); return g; });
    await api(`/groups/${cid}/members/remove`, { method: 'POST', body: { user_id } });
  },
  async group_admin({ cid, user_id, admin }) {
    await api(`/groups/${cid}/role`, { method: 'POST', body: { user_id, role: admin ? 'admin' : 'member' } });
    await publishGroupState(cid, (g) => { g.admins = admin ? [...new Set([...g.admins, user_id])] : g.admins.filter((x) => x !== user_id); return g; });
  },
  group_rename: ({ cid, name }) => publishGroupState(cid, (g) => ({ ...g, name: String(name).trim().slice(0, 100) || g.name })),
  async group_leave({ cid }) {
    const g = await get('groups', cid);
    if (g?.admins.includes(me.id) && g.admins.length > 1) { try { await publishGroupState(cid, (x) => { x.members = x.members.filter((y) => y !== me.id); x.admins = x.admins.filter((y) => y !== me.id); return x; }); } catch { /* best effort */ } }
    await api(`/groups/${cid}/leave`, { method: 'POST', body: {} });
    await sysMessage(cid, 'شما گروه را ترک کردید.');
  },
  async search_users({ q }) { return (await api('/users/search?q=' + encodeURIComponent(q))).users; },
  async contacts() { return (await api('/contacts')).users; },
  async follow({ user_id, on }) { await api(on ? '/contacts' : '/contacts/remove', { method: 'POST', body: { user_id } }); },
  async call_ice() { return api('/calls/ice'); },
  async call_start({ cid }) { return (await api('/calls', { method: 'POST', body: { conversation_id: cid } })).id; },
  async call_status({ id, status, reason }) { try { return (await api(`/calls/${encodeURIComponent(id)}/status`, { method: 'POST', body: { status, reason } })).status; } catch { return null; } },
  async call_history() { return (await api('/calls')).calls; },
  /** Sends an end-to-end encrypted call signal (offer / answer / hangup …). Not stored as a chat message. */
  async call_signal({ cid, peer, body }) {
    const mid = newId();
    await write([['events', { mid, applied: true }]]);
    await deliver(cid, peer, mid, payloadOf(cid, mid, 'call', body));
  },
  call_mode({ active }) { callMode = !!active; if (callMode) { syncOnce(); schedule(); } else schedule(); },
  async open_direct({ user_id }) { return (await api('/conversations', { method: 'POST', body: { user_id } })).id; },
  messages: ({ cid }) => messages(cid),
  send: ({ cid, peer, text, reply_to }) => sendMessage(cid, peer, 'text', { text: String(text).slice(0, MAX_TEXT), reply_to: reply_to || null }),
  send_file: ({ cid, peer, file, meta, caption, reply_to }) => sendFile(cid, peer, file, meta || {}, caption, reply_to),
  async retry({ mid, peer }) {
    const m = await loadMessage(mid);
    if (!m || m.status !== 'failed') return;
    if (m.att && !m.att.id) { // upload never finished: needs the File from this session
      const f = unsentFiles.get(mid);
      if (!f) throw new Error('file_missing');
      await write([['messages', undefined, mid]]); emit('removed', { mid });
      return sendFile(m.cid, peer, f, m.att, m.text, m.reply_to);
    }
    await write([['messages', undefined, mid]]); emit('removed', { mid });
    return m.att ? sendMessage(m.cid, peer, 'file', { att: m.att, text: m.text, reply_to: m.reply_to }) : sendMessage(m.cid, peer, 'text', { text: m.text, reply_to: m.reply_to });
  },
  edit: ({ cid, peer, mid, text }) => sendEvent(cid, peer, 'edit', { target: mid, text: String(text).slice(0, MAX_TEXT) }),
  delete_all: ({ cid, peer, mid }) => sendEvent(cid, peer, 'delete', { target: mid }),
  async delete_local({ mid }) { await write([['messages', undefined, mid]]); fileCache.delete(mid); emit('removed', { mid }); },
  react: ({ cid, peer, mid, emoji }) => sendEvent(cid, peer, 'reaction', { target: mid, emoji: emoji || '' }),
  pin: ({ cid, peer, mid, pinned }) => sendEvent(cid, peer, 'pin', { target: mid, pinned: !!pinned }),
  async forward({ mid, cid, peer }) {
    const m = await loadMessage(mid);
    if (!m || m.deleted) throw new Error('not_found');
    return m.att ? sendMessage(cid, peer, 'file', { att: m.att, text: m.text, fwd: true }, { fwd: true }) : sendMessage(cid, peer, 'text', { text: m.text, fwd: true }, { fwd: true });
  },
  async mark_read({ cid, peer }) {
    if (!peer) { // groups: read state is kept locally (no receipts to everyone)
      const rows = (await convRows(cid, 500)).filter((r) => r.dir === 'in' && r.status === 'received');
      for (const r of rows) r.status = 'read';
      if (rows.length) await write(rows.map((r) => ['messages', r]));
      return rows.length;
    }
    const rows = (await convRows(cid, 500)).filter((r) => r.dir === 'in' && r.status === 'received');
    if (!rows.length) return 0;
    for (const r of rows) r.status = 'read';
    await write(rows.map((r) => ['messages', r]));
    const real = [];
    for (const r of rows) { const c = await unseal(r.box); if (!c.flag || c.flag === 'new_key') real.push(r.mid); }
    for (let i = 0; i < real.length; i += 200) {
      const mid = newId();
      await write([['events', { mid, applied: true }]]);
      try { await deliver(cid, peer, mid, payloadOf(cid, mid, 'read', { mids: real.slice(i, i + 200) })); } catch { /* best effort */ }
    }
    return rows.length;
  },
  async typing({ cid }) {
    if (Date.now() - lastTyping < 4000) return;
    lastTyping = Date.now();
    const { devices } = await api(`/conversations/${encodeURIComponent(cid)}/presence`);
    const envelopes = [];
    for (const d of devices) {
      const s = (await sessionsFor(d))[0];
      if (!s) continue;
      const e = s.encrypt(payloadOf(cid, null, 'typing', {}));
      if (e.message_type === 1) envelopes.push({ device_id: d, type: 1, ciphertext: b64(e.ciphertext) });
    }
    await write(sessionOps(devices));
    if (envelopes.length) await api('/ephemeral', { method: 'POST', body: { conversation_id: cid, channel: 'typing', envelopes } });
  },
  async file({ mid }) {
    if (fileCache.has(mid)) return fileCache.get(mid);
    const m = await loadMessage(mid);
    if (!m?.att?.id) throw new Error('not_found');
    const blob = await downloadFile(m.att);
    fileCache.set(mid, blob);
    return blob;
  },
  async media({ cid }) { return (await messages(cid, 2000)).filter((m) => m.att && !m.deleted); },
  async search({ q }) {
    const needle = norm(q).trim();
    if (needle.length < 2) return [];
    const out = [];
    for (const row of await getAll('messages')) {
      const m = await readMessage(row);
      if (!m.deleted && (norm(m.text || '').includes(needle) || norm(m.att?.name || '').includes(needle))) out.push({ mid: m.mid, cid: m.cid, ts: m.ts, dir: m.dir, text: m.text || m.att?.name || '' });
    }
    return out.sort((a, b) => b.ts - a.ts).slice(0, 50);
  },
  async contact_state({ user_id }) { const c = await contact(user_id); return { verified: !!c.verified, pending: !!c.pending_msk }; },
  safety: ({ user_id }) => safety(user_id),
  async verify({ user_id }) { const c = await get('contacts', user_id); if (c && !c.pending_msk) await write([['contacts', { ...c, verified: true }, user_id]]); },
  accept_key: ({ user_id }) => acceptKey(user_id),
  visibility({ visible: v }) { visible = v; if (v) { syncOnce(); schedule(); } },
  sync_now: () => syncOnce(),
};

self.onmessage = async ({ data }) => {
  const { id, cmd, args } = data;
  try { self.postMessage({ id, ok: true, result: await commands[cmd](args || {}) }); }
  catch (e) { self.postMessage({ id, ok: false, error: e?.message || String(e) }); }
};
