// Independent RFC 8291 / RFC 8292 implementation used to verify the PHP sender.
//   decrypt <ua_private_jwk_json> <auth_b64url> <body_b64>  → prints the plaintext
//   verify  <vapid_header> <server_public_b64url> <endpoint>  → prints "ok <aud> <sub>" or "bad"
import crypto from 'node:crypto';
const b64u = (s) => Buffer.from(s.replace(/-/g, '+').replace(/_/g, '/'), 'base64');
const pubFromRaw = (raw) => crypto.createPublicKey({ format: 'jwk', key: { kty: 'EC', crv: 'P-256', x: raw.subarray(1, 33).toString('base64url'), y: raw.subarray(33, 65).toString('base64url') } });
export function decrypt(uaPrivJwk, auth, body) {
  const ua = crypto.createPrivateKey({ format: 'jwk', key: uaPrivJwk });
  const uaPub = Buffer.concat([Buffer.from([4]), Buffer.from(uaPrivJwk.x, 'base64url'), Buffer.from(uaPrivJwk.y, 'base64url')]);
  const salt = body.subarray(0, 16), idlen = body[20], asPub = body.subarray(21, 21 + idlen), ct = body.subarray(21 + idlen);
  const ecdh = crypto.diffieHellman({ privateKey: ua, publicKey: pubFromRaw(asPub) });
  const ikm = Buffer.from(crypto.hkdfSync('sha256', ecdh, auth, Buffer.concat([Buffer.from('WebPush: info\0'), uaPub, asPub]), 32));
  const cek = Buffer.from(crypto.hkdfSync('sha256', ikm, salt, Buffer.from('Content-Encoding: aes128gcm\0'), 16));
  const nonce = Buffer.from(crypto.hkdfSync('sha256', ikm, salt, Buffer.from('Content-Encoding: nonce\0'), 12));
  const d = crypto.createDecipheriv('aes-128-gcm', cek, nonce); d.setAuthTag(ct.subarray(ct.length - 16));
  const pt = Buffer.concat([d.update(ct.subarray(0, ct.length - 16)), d.final()]);
  if (pt[pt.length - 1] !== 2) throw new Error('bad padding delimiter');
  return pt.subarray(0, pt.length - 1).toString();
}
export function verifyVapid(header, serverPub, endpoint) {
  const m = /^vapid t=([^,]+), k=(.+)$/.exec(header);
  if (!m || m[2] !== serverPub) return null;
  const [h, p, s] = m[1].split('.');
  const ok = crypto.verify('sha256', Buffer.from(h + '.' + p), { key: pubFromRaw(b64u(serverPub)), dsaEncoding: 'ieee-p1363' }, b64u(s));
  const claims = JSON.parse(b64u(p).toString());
  const u = new URL(endpoint);
  return ok && claims.aud === u.origin && claims.exp > Date.now() / 1000 && claims.exp < Date.now() / 1000 + 86400 ? claims : null;
}
export function newSubscriber() {
  const { privateKey } = crypto.generateKeyPairSync('ec', { namedCurve: 'P-256' });
  const jwk = privateKey.export({ format: 'jwk' });
  const pub = Buffer.concat([Buffer.from([4]), Buffer.from(jwk.x, 'base64url'), Buffer.from(jwk.y, 'base64url')]);
  return { jwk, p256dh: pub.toString('base64url'), auth: crypto.randomBytes(16).toString('base64url') };
}
if (process.argv[1]?.endsWith('webpush-check.mjs')) {
  const [cmd, ...a] = process.argv.slice(2);
  try {
    if (cmd === 'subscriber') console.log(JSON.stringify(newSubscriber()));
    if (cmd === 'decrypt') console.log(decrypt(JSON.parse(a[0]), b64u(a[1]), Buffer.from(a[2], 'base64')));
    if (cmd === 'verify') { const c = verifyVapid(a[0], a[1], a[2]); console.log(c ? `ok ${c.aud} ${c.sub}` : 'bad'); }
  } catch (e) { console.log('error ' + e.message); }
}
