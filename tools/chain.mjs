// Noformat Logbook hash chain (CONTRACT §4). Zero dependencies, Node 18+.
//
// Canonical JSON: object keys sorted by byte order recursively; lists keep order;
// empty arrays and empty objects both encode as []; strings as JSON.stringify
// (unescaped slashes/unicode); integers as decimal; no whitespace.
// hash = sha256_hex(prev_hash + canonical(event without id, hash, prev_hash, count, site, producer)).

import { createHash } from 'node:crypto';

export const GENESIS = '0'.repeat(64);
export const EXCLUDED = ['id', 'hash', 'prev_hash', 'count', 'site', 'producer'];

const byteCompare = (a, b) => Buffer.compare(Buffer.from(a, 'utf8'), Buffer.from(b, 'utf8'));

export function canonicalJson(value) {
  if (value === null || value === undefined) return 'null';
  if (typeof value === 'boolean') return value ? 'true' : 'false';
  if (typeof value === 'number') return Number.isInteger(value) ? String(value) : JSON.stringify(String(value));
  if (typeof value === 'string') return JSON.stringify(value);
  if (Array.isArray(value)) return value.length === 0 ? '[]' : '[' + value.map(canonicalJson).join(',') + ']';
  if (typeof value === 'object') {
    const keys = Object.keys(value);
    if (keys.length === 0) return '[]';
    keys.sort(byteCompare);
    return '{' + keys.map((k) => JSON.stringify(k) + ':' + canonicalJson(value[k])).join(',') + '}';
  }
  return 'null';
}

export function payload(event) {
  const copy = { ...event };
  for (const k of EXCLUDED) delete copy[k];
  return copy;
}

export function hashEvent(prevHash, event) {
  return createHash('sha256').update(prevHash + canonicalJson(payload(event))).digest('hex');
}

/** Verify an id-ascending run. expectedPrev: hash the first prev_hash must equal (null = trust it). */
export function verifyChain(events, expectedPrev = null) {
  let last = expectedPrev;
  let checked = 0;
  for (const event of events) {
    if (last !== null && event.prev_hash !== last) {
      return { ok: false, checked, break: { id: event.id, reason: 'prev_hash_mismatch', expected: last, actual: event.prev_hash } };
    }
    const computed = hashEvent(event.prev_hash, event);
    if (computed !== event.hash) {
      return { ok: false, checked, break: { id: event.id, reason: 'hash_mismatch', expected: computed, actual: event.hash } };
    }
    last = event.hash;
    checked++;
  }
  return { ok: true, checked, last_hash: last, break: null };
}
