#!/usr/bin/env node
// Reference client for the Noformat Logbook pull API. Zero dependencies, Node 18+.
//
//   node tools/fetch-events.mjs --site https://example.com --key nfk_… --secret … \
//        [--endpoint manifest|events|event|summary|health|verify|marker] [--after N] [--limit N] [--verify]
//
// Options:
//   --site URL           Site home URL (required). Env: NFLOG_SITE
//   --key ID             Key id. Env: NFLOG_KEY_ID
//   --secret SECRET      Secret. Env: NFLOG_SECRET
//   --endpoint NAME      Default: events
//   --after N --limit N  Cursor pagination for events (limit ≤ 500)
//   --id N               For --endpoint event
//   --window 24h         For --endpoint summary
//   --from N --to N      For --endpoint verify
//   --label TEXT         For --endpoint marker (POST)
//   --query k=v          Extra query parameter (repeatable), e.g. --query min_severity=warning
//   --all                events: follow has_more until the end
//   --verify             events: recompute the hash chain locally and report breaks (implies --all)
//   --rest-route         Use /?rest_route=/noformat-logger/v1/... instead of /<prefix>/...
//   --rest-prefix P      REST URL prefix (default wp-json; "auto" discovers it from the site's
//                        public Link header). The manifest reports it as rest_prefix.
//   --bad-signature      Send a deliberately wrong signature (tests)
//   --expect-status N    Exit 0 only if the HTTP status equals N
// Prints JSON: { status, headers, body } (or the verification report with --verify).

import { signRequest } from './sign.mjs';
import { verifyChain } from './chain.mjs';

function parseArgs(argv) {
  const out = { query: [] };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (!a.startsWith('--')) continue;
    const name = a.slice(2);
    const flags = ['verify', 'all', 'rest-route', 'bad-signature'];
    if (flags.includes(name)) { out[name] = true; continue; }
    const value = argv[++i];
    if (name === 'query') out.query.push(value); else out[name] = value;
  }
  return out;
}

const args = parseArgs(process.argv.slice(2));
const site = (args.site ?? process.env.NFLOG_SITE ?? '').replace(/\/+$/, '');
const keyId = args.key ?? process.env.NFLOG_KEY_ID;
const secret = args.secret ?? process.env.NFLOG_SECRET;
const endpoint = args.endpoint ?? 'events';
let restPrefix = String(args['rest-prefix'] ?? 'wp-json').replace(/^\/+|\/+$/g, '');
if (!site || !keyId || !secret) {
  console.error('Usage: fetch-events.mjs --site URL --key ID --secret SECRET [--endpoint …]');
  process.exit(2);
}

function buildUrl(route, params) {
  const base = args['rest-route']
    ? new URL(site + '/')
    : new URL(site + '/' + restPrefix + '/noformat-logger/v1' + route);
  if (args['rest-route']) base.searchParams.set('rest_route', '/noformat-logger/v1' + route);
  for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '') base.searchParams.set(k, String(v));
  for (const q of args.query) {
    const eq = q.indexOf('=');
    base.searchParams.append(eq === -1 ? q : q.slice(0, eq), eq === -1 ? '' : q.slice(eq + 1));
  }
  return base;
}

async function call(method, route, params = {}, body = '') {
  const url = buildUrl(route, params);
  const { headers } = signRequest({ method, url, keyId, secret, body, restPrefix });
  if (args['bad-signature']) headers['X-NF-Signature'] = 'v1=' + '0'.repeat(64);
  if (body) headers['Content-Type'] = 'application/json';
  const res = await fetch(url, { method, headers, body: body || undefined });
  const text = await res.text();
  let json;
  try { json = JSON.parse(text); } catch { json = { raw: text.slice(0, 2000) }; }
  const picked = {};
  for (const h of ['cache-control', 'x-nf-chain-head', 'x-nf-schema', 'content-type']) {
    if (res.headers.has(h)) picked[h] = res.headers.get(h);
  }
  return { status: res.status, headers: picked, body: json };
}

/** Discover the REST prefix from the public `Link: <…/prefix/>; rel="https://api.w.org/"` header. */
async function discoverPrefix() {
  const res = await fetch(site + '/', { method: 'HEAD', redirect: 'manual' });
  const link = res.headers.get('link') ?? '';
  const m = link.match(/<([^>]+)>;\s*rel="https:\/\/api\.w\.org\/"/);
  if (!m) return 'wp-json';
  const path = new URL(m[1]).pathname.replace(/^\/+|\/+$/g, '');
  const base = new URL(site + '/').pathname.replace(/^\/+|\/+$/g, '');
  return base && path.startsWith(base + '/') ? path.slice(base.length + 1) : path || 'wp-json';
}

async function main() {
  if (restPrefix === 'auto') restPrefix = await discoverPrefix();
  let result;
  switch (endpoint) {
    case 'manifest':
    case 'health':
      result = await call('GET', '/' + endpoint);
      if (endpoint === 'manifest' && result.status === 200 && result.body.rest_prefix && result.body.rest_prefix !== restPrefix) {
        console.error(`note: the site's REST prefix is "${result.body.rest_prefix}"; pass --rest-prefix ${result.body.rest_prefix}`);
      }
      break;
    case 'summary':
      result = await call('GET', '/summary', { window: args.window ?? '24h' });
      break;
    case 'verify':
      result = await call('GET', '/verify', { from: args.from, to: args.to });
      break;
    case 'event':
      result = await call('GET', '/events/' + Number(args.id ?? 0));
      break;
    case 'marker':
      result = await call('POST', '/marker', {}, JSON.stringify({ label: args.label ?? 'marker', ref: args.ref }));
      break;
    case 'events': {
      const all = args.all || args.verify;
      let after = Number(args.after ?? 0);
      const limit = Number(args.limit ?? 100);
      const events = [];
      let page;
      do {
        page = await call('GET', '/events', { after, limit });
        if (page.status !== 200) break;
        events.push(...page.body.events);
        after = page.body.next_after;
      } while (all && page.body.has_more);
      if (!args.verify) {
        result = page.status === 200 && all ? { ...page, body: { ...page.body, events } } : page;
        break;
      }
      if (page.status !== 200) { result = page; break; }
      const report = verifyChain(events, null);
      const head = page.headers['x-nf-chain-head'] ?? '';
      const [headId, headHash] = head.split(':');
      const last = events[events.length - 1];
      report.head = head;
      report.head_ok = last ? Number(headId) === last.id && headHash === last.hash : null;
      if (report.head_ok === false) report.ok = false;
      result = { status: 200, headers: page.headers, body: { verification: report, events: events.length } };
      break;
    }
    default:
      console.error('Unknown endpoint ' + endpoint);
      process.exit(2);
  }
  console.log(JSON.stringify(result, null, 2));
  if (args['expect-status']) process.exit(result.status === Number(args['expect-status']) ? 0 : 1);
  if (result.status >= 400) process.exit(1);
  if (args.verify && !result.body.verification?.ok) process.exit(1);
}

main().catch((e) => { console.error(e?.stack ?? String(e)); process.exit(1); });
