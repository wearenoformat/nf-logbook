# Pull API (`noformat-logger/v1`)

Read-only HTTPS API for the Noformat Controlroom (CONTRACT §3). Every route
requires a signed request; WordPress cookies and Application Passwords never
grant access. The namespace is not listed in the public REST index.

Base URL: `https://<site>/wp-json/noformat-logger/v1` (or
`https://<site>/?rest_route=/noformat-logger/v1/...` on sites without pretty permalinks).

## Endpoints

| Method and path | Parameters | Response |
| --- | --- | --- |
| `GET /manifest` | | producer, schema versions, site, `rest_prefix`, clock, retention, buffer, storage stats, `chain_head`, `ack_cursor`, `last_served_id`, `last_pull`, failure counter, module status, key status |
| `GET /events` | `after` (default 0), `limit` (1–500, default 100), `min_severity`, `category` (comma list), `since`, `until` (unix seconds or ISO-8601 UTC), `ack`, `fields=compact` | `{ events: [...], next_after, has_more, chain_head }`, ascending by `id`; `fields=compact` omits `context` |
| `GET /events/{id}` | | `{ event }` |
| `GET /summary` | `window` (`15m`, `24h`, `7d`; max `90d`, default `24h`) | counts per severity and category (`events`, `occurrences`) plus the top 20 fingerprints, most severe first |
| `GET /health` | | health snapshot (versions, updates, debug flags, cron, integrity, config audit, Site Health; shape in [EVENTS.md](EVENTS.md#health-snapshot)) |
| `GET /verify` | `from`, `to` | `{ ok, checked, first_id, last_id, last_hash, break, anchor, head_ok, truncated, next_from, chain_head }` (≤ 50 000 rows per call) |
| `POST /marker` | JSON body `{ "label": "Release 2.3", "ref": "v2.3.0" }` | `201 { id, hash }`; stores a `deploy.marker` event |

Events follow CONTRACT §1 exactly. `ack=<id>` is accepted on `GET /events`
only (other endpoints ignore it): it records the highest id the collector has
durably stored, after the response is built, monotonically (never lowered, never
past the chain head). Prune never deletes past it unless rows are older than
`hard_max_days`, or the table exceeds `max_rows` (that rule removes acknowledged
rows and leading debug/info rows only, never an unacknowledged row of severity
notice or higher).

All parameters are single values; an array (`category[]=…`) is a 400. They are
validated only after authentication.

### Counts

`count` is the number of occurrences aggregated into an event (fingerprinted
events, see EVENTS.md "Aggregation windows"). It is not hashed. While the
event's window is open the count can still grow, and responses always show the
live value. Once `GET /events` has returned an event (the site remembers the
highest id served as `last_served_id`) or `ack` covers it, its window is
closed: further occurrences start a **new event** with its own id instead of
raising the count of one the collector already stored. A collector therefore
never has to re-read old events to see new occurrences. (An occurrence counted
in the instant between reading and closing may still be added to a served
event; `/events/{id}` shows it.)

Response headers on every response of the namespace (including errors):

```
Cache-Control: no-store, max-age=0
X-NF-Schema: 1
X-NF-Chain-Head: <id>:<sha256 hex>      (authenticated responses only)
```

Unauthenticated responses (401) never carry `X-NF-Chain-Head`.

## Errors

| Status | `code` | When |
| --- | --- | --- |
| 401 | `nflog_unauthorized` | Any authentication failure: missing or malformed headers, unknown key, bad signature, timestamp skew > 300 s, reused nonce, plain HTTP, IP not on the allowlist. The body is always `{"code":"nflog_unauthorized","message":"Unauthorized"}`; the reason is only recorded in the site's own `api.auth_failed` security event. |
| 429 | `nflog_rate_limited` | More than `rate_limit_per_minute` (120) signed requests per key per minute. |
| 400 | `nflog_bad_request` | Invalid parameter (only after successful authentication). |
| 404 | `nflog_not_found` | Unknown event id. |
| 503 | `nflog_storage_unavailable` | Tables missing or the database failed; the failure counter in the manifest increases. |

## Request signing (v1)

```
X-NF-Key:       nfk_<16 base62>
X-NF-Timestamp: <unix seconds>
X-NF-Nonce:     <32 lowercase hex chars, random per request>
X-NF-Signature: v1=<lowercase hex HMAC-SHA256(secret, string_to_sign)>

string_to_sign = "v1" "\n" METHOD "\n" PATH "\n" CANONICAL_QUERY "\n" TIMESTAMP "\n" NONCE "\n" sha256_hex(BODY)
```

- **Secret**: the HMAC key is the secret string exactly as configured (its ASCII
  bytes), not the base64url-decoded bytes.
- **METHOD**: upper case.
- **PATH**: the request path without host and query, as sent, e.g.
  `/wp-json/noformat-logger/v1/events` (on a subdirectory install
  `/blog/wp-json/…`). For the `?rest_route=` form sign `"/" + prefix + rest_route`,
  e.g. `/wp-json/noformat-logger/v1/events`, where prefix is the site's REST
  prefix (`wp-json` unless changed with the `rest_url_prefix` filter; the
  manifest reports it as `rest_prefix`, `tools/fetch-events.mjs --rest-prefix`).
  The server accepts either the literal request path or
  `"/" + rest_get_url_prefix() + route`.
- **CANONICAL_QUERY**: split the raw query on `&`, split each pair at the first
  `=` (a key without `=` has an empty value), replace `+` with a space,
  percent-decode, drop `rest_route`, re-encode key and value with RFC 3986
  (`A-Z a-z 0-9 - . _ ~` unescaped, uppercase hex; PHP `rawurlencode`), sort by
  encoded key then encoded value (byte order), join `key=value` with `&`. Empty
  string when there are no parameters.
- **BODY**: raw request body bytes; `GET` has an empty body
  (`e3b0c442…b855`).
- The server rejects timestamps more than **300 s** from its clock, nonces seen in
  the last **600 s** (per key), plain HTTP unless `NOFORMAT_LOGGER_ALLOW_HTTP`, and
  compares signatures in constant time. Unknown keys and bad signatures are
  indistinguishable to the caller.

### Worked example

This is vector `events_basic` in [`tests/fixtures/signing-vectors.json`](../tests/fixtures/signing-vectors.json):

```
key id    nfk_7Hs9Qk2LmP4xT8vZ
secret    q3J8xV2mN5bL9cT1wR7yU4iO0pA6sD3fG8hJ2kL5zX1
request   GET https://example.com/wp-json/noformat-logger/v1/events?limit=10&after=5
timestamp 1761660191
nonce     0123456789abcdef0123456789abcdef
```

Canonical query: `after=5&limit=10`

String to sign (6 newlines, no trailing newline):

```
v1
GET
/wp-json/noformat-logger/v1/events
after=5&limit=10
1761660191
0123456789abcdef0123456789abcdef
e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
```

Headers:

```
X-NF-Key: nfk_7Hs9Qk2LmP4xT8vZ
X-NF-Timestamp: 1761660191
X-NF-Nonce: 0123456789abcdef0123456789abcdef
X-NF-Signature: v1=846f1081d7ee76e6ea70c4c0e2231bf4549c8ac2402ef32a3d48ff8db5421c7c
```

Reference implementations: `Noformat\Logger\Rest\Authenticator` (PHP) and
`tools/sign.mjs` (JavaScript). Both are checked against the same fixtures
(`composer test`, `node tools/check-vectors.mjs`).

## Hash chain

Each stored event has `prev_hash` (the previous event's `hash`; the first event
uses 64 zeros) and

```
hash = sha256_hex( prev_hash + canonical_json(payload) )
payload = the event in contract shape without id, hash, prev_hash, count, site, producer
```

`count` is excluded because aggregation increments it after storage; `site` and
`producer` are envelope fields (the site URL and plugin version may change).
Canonical JSON: object keys sorted by byte order recursively, lists in order,
empty arrays and objects both `[]`, strings encoded like JavaScript
`JSON.stringify` (unescaped `/`, unicode and U+2028/U+2029), integers in decimal,
no whitespace, no floats. Vectors: [`tests/fixtures/hash-vectors.json`](../tests/fixtures/hash-vectors.json).

Collectors verify each page with `prev_hash === previous.hash` and the
recomputed hash, and compare the last event with `X-NF-Chain-Head`.
Pruning deletes an id prefix only; `/verify` anchors the first remaining event on
the stored prune anchor (`manifest.storage.pruned_through`).
