// Noformat Logger request signing (CONTRACT §3, v1). Zero dependencies, Node 18+.
//
//   string_to_sign = "v1\n" METHOD "\n" PATH "\n" CANONICAL_QUERY "\n" TIMESTAMP "\n" NONCE "\n" sha256_hex(BODY)
//   X-NF-Signature: v1=<hex HMAC-SHA256(secret, string_to_sign)>
//
// The HMAC key is the secret string exactly as configured (its UTF-8 bytes).
// Exported so tests and the Controlroom collector can reuse it.

import { createHash, createHmac, randomBytes } from 'node:crypto';

const UNRESERVED = /[A-Za-z0-9\-._~]/;

/** Percent-decode to bytes ('+' is a space), tolerating malformed escapes like PHP rawurldecode(). */
export function percentDecode(input) {
  const s = input.replace(/\+/g, ' ');
  const bytes = [];
  for (let i = 0; i < s.length; i++) {
    const c = s[i];
    if (c === '%' && /^[0-9A-Fa-f]{2}$/.test(s.slice(i + 1, i + 3))) {
      bytes.push(parseInt(s.slice(i + 1, i + 3), 16));
      i += 2;
    } else {
      bytes.push(...Buffer.from(c, 'utf8'));
    }
  }
  return Buffer.from(bytes);
}

/** RFC 3986 encoding of raw bytes (same as PHP rawurlencode()). */
export function rfc3986(bytes) {
  let out = '';
  for (const b of Buffer.isBuffer(bytes) ? bytes : Buffer.from(String(bytes), 'utf8')) {
    const ch = String.fromCharCode(b);
    out += b < 0x80 && UNRESERVED.test(ch) ? ch : '%' + b.toString(16).toUpperCase().padStart(2, '0');
  }
  return out;
}

/**
 * Canonical query: decode pairs, drop rest_route, re-encode (RFC 3986),
 * sort by encoded key then encoded value (byte order), join with '&'.
 * Accepts a raw query string (with or without '?') or a URLSearchParams.
 */
export function canonicalQuery(query) {
  const raw = query instanceof URLSearchParams ? query.toString() : String(query ?? '').replace(/^\?/, '');
  const pairs = [];
  for (const part of raw.split('&')) {
    if (part === '') continue;
    const eq = part.indexOf('=');
    const k = eq === -1 ? part : part.slice(0, eq);
    const v = eq === -1 ? '' : part.slice(eq + 1);
    const key = percentDecode(k);
    if (key.toString('utf8') === 'rest_route') continue;
    pairs.push([rfc3986(key), rfc3986(percentDecode(v))]);
  }
  const cmp = (a, b) => (a < b ? -1 : a > b ? 1 : 0);
  pairs.sort((a, b) => cmp(a[0], b[0]) || cmp(a[1], b[1]));
  return pairs.map(([k, v]) => `${k}=${v}`).join('&');
}

export function sha256Hex(data) {
  return createHash('sha256').update(data ?? '').digest('hex');
}

export function stringToSign({ method, path, canonicalQuery: cq, timestamp, nonce, body = '' }) {
  return ['v1', String(method).toUpperCase(), path, cq, String(timestamp), nonce, sha256Hex(body)].join('\n');
}

export function sign(secret, sts) {
  return 'v1=' + createHmac('sha256', secret).update(sts).digest('hex');
}

/**
 * The path to sign for a URL. For the ?rest_route= form the signed path is
 * "/" + REST prefix + route (prefix "wp-json" unless the site changed it with
 * the rest_url_prefix filter; the manifest reports it as `rest_prefix`);
 * otherwise the URL path as sent.
 */
export function signingPath(url, restPrefix = 'wp-json') {
  const u = url instanceof URL ? url : new URL(url);
  const route = u.searchParams.get('rest_route');
  const prefix = '/' + String(restPrefix || 'wp-json').replace(/^\/+|\/+$/g, '');
  return route !== null ? prefix + route : u.pathname;
}

/** Build the four X-NF-* headers for a request. */
export function signRequest({ method = 'GET', url, keyId, secret, body = '', timestamp, nonce, restPrefix = 'wp-json' }) {
  const u = url instanceof URL ? url : new URL(url);
  const ts = timestamp ?? Math.floor(Date.now() / 1000);
  const n = nonce ?? randomBytes(16).toString('hex');
  const sts = stringToSign({
    method,
    path: signingPath(u, restPrefix),
    canonicalQuery: canonicalQuery(u.search),
    timestamp: ts,
    nonce: n,
    body,
  });
  return {
    headers: {
      'X-NF-Key': keyId,
      'X-NF-Timestamp': String(ts),
      'X-NF-Nonce': n,
      'X-NF-Signature': sign(secret, sts),
    },
    stringToSign: sts,
  };
}
