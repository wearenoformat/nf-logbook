#!/usr/bin/env node
// Cross-checks the JavaScript reference implementation (sign.mjs, chain.mjs)
// against the shared fixtures in tests/fixtures/. Exit code 1 on any mismatch.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { canonicalQuery, stringToSign, sign, sha256Hex, signingPath } from './sign.mjs';
import { canonicalJson, hashEvent, verifyChain, GENESIS } from './chain.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', 'tests', 'fixtures');
const load = (f) => JSON.parse(readFileSync(join(root, f), 'utf8'));
let failures = 0;
let passes = 0;
const check = (name, actual, expected) => {
  if (JSON.stringify(actual) === JSON.stringify(expected)) { passes++; return; }
  failures++;
  console.error(`FAIL ${name}\n  expected: ${JSON.stringify(expected)}\n  actual:   ${JSON.stringify(actual)}`);
};

const signing = load('signing-vectors.json');
for (const v of signing.vectors) {
  const cq = canonicalQuery(v.raw_query);
  check(`${v.name}: signing_path`, signingPath(v.url), v.signing_path);
  check(`${v.name}: canonical_query`, cq, v.expected.canonical_query);
  check(`${v.name}: body_sha256`, sha256Hex(v.body), v.expected.body_sha256);
  const sts = stringToSign({ method: v.method, path: v.signing_path, canonicalQuery: cq, timestamp: v.timestamp, nonce: v.nonce, body: v.body });
  check(`${v.name}: string_to_sign`, sts, v.expected.string_to_sign);
  check(`${v.name}: signature`, sign(signing.secret, sts), v.expected.signature);
}

// Custom REST prefix (rest_url_prefix filter): the ?rest_route= form signs "/" + prefix + route.
check('signing_path custom prefix', signingPath('https://example.com/?rest_route=/noformat-logger/v1/events', 'api'), '/api/noformat-logger/v1/events');
check('signing_path pretty path ignores prefix', signingPath('https://example.com/api/noformat-logger/v1/events?a=1', 'api'), '/api/noformat-logger/v1/events');

const hashes = load('hash-vectors.json');
for (const c of hashes.canonicalization) check(`canonical ${c.name}`, canonicalJson(c.input), c.canonical);
let prev = GENESIS;
for (const e of hashes.chain) {
  check(`chain #${e.id} prev_hash`, e.prev_hash, prev);
  const { canonical_payload: payload, ...event } = e;
  check(`chain #${e.id} hash`, hashEvent(prev, event), e.hash);
  prev = e.hash;
}
const plain = hashes.chain.map(({ canonical_payload, ...e }) => e);
check('chain verifies', verifyChain(plain, GENESIS).ok, true);
for (const t of hashes.tampered) {
  let events = plain.map((e) => structuredClone(e));
  if (t.mutate) events[t.mutate.index][t.mutate.field] = t.mutate.value;
  if (t.delete_index !== undefined) events.splice(t.delete_index, 1);
  const r = verifyChain(events, GENESIS);
  check(`tampered ${t.name}`, r.break ? { id: r.break.id, reason: r.break.reason } : null, t.expected_break);
}

console.log(`check-vectors: ${passes} passed, ${failures} failed`);
process.exit(failures ? 1 : 0);
