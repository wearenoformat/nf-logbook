# Noformat Logger contract (shared by WordPress and Sanity)

> The plugin repository ([wearenoformat/wp-noformat-logger](https://github.com/wearenoformat/wp-noformat-logger)) carries a copy of this file as `docs/CONTRACT.md`; keep the two identical.

This is the shape the Controlroom collector consumes. Both site-side projects
produce it; the collector should not need stack-specific code beyond the
transport section.

## 1. Event

```jsonc
{
  "id": 48213,                       // monotonically increasing per site (cursor)
  "occurred_at": "2026-10-28T14:03:11.482Z", // UTC, millisecond precision
  "severity": "critical",            // critical | error | warning | notice | info | debug
  "category": "security",            // see table below
  "action": "user.role_changed",     // dotted, stable, machine-readable
  "summary": "Role of user #12 changed from editor to administrator",
  "object": { "type": "user", "id": "12", "subtype": null, "label_hash": null },
  "actor":  { "type": "user", "id": "3", "role": "administrator" }, // type: user|system|cron|cli|anonymous|app
  "request": {
    "id": "a6f2…",                   // groups events of one HTTP request / CLI run
    "source": "admin",               // admin | rest | ajax | xmlrpc | cron | cli | frontend | app_password | webhook | studio | build
    "route": "/wp-admin/post.php",   // path only, query string stripped
    "method": "POST",
    "ip_hash": "9f3c1b…",            // HMAC-SHA256(site salt, ip), first 32 hex chars; never the IP
    "ua_hash": "02ab…"               // same treatment for the user agent
  },
  "changes": [                       // optional; per field
    { "field": "role", "old": "editor", "new": "administrator" }
  ],
  "context": { "...": "free-form, redacted, ≤ 64 KB" },
  "fingerprint": "c0ffee…",          // optional; same recurring error → same fingerprint
  "count": 1,                        // aggregated occurrences for rate-limited events
  "site": { "id": "1", "url": "https://example.com" },
  "producer": { "name": "wp-noformat-logger", "version": "1.0.0", "schema": 1 },
  "hash": "…", "prev_hash": "…"     // tamper-evidence chain (see §4)
}
```

### Severity (priority order, highest first)

| Severity | Meaning | Examples |
| --- | --- | --- |
| `critical` | Site is broken or compromised, act now | fatal error on a public URL, site URL changed, new administrator, admin email changed, core file integrity mismatch, database error |
| `error` | Something failed | PHP error, failed outbound HTTP call, mail failure, failed update, build failed |
| `warning` | Suspicious or degrading | burst of failed logins, deprecated API use, XML-RPC probing, user enumeration attempt, slow cron |
| `notice` | Important change that is probably intended | publish/unpublish/delete, role change, plugin activated, settings changed, menu changed |
| `info` | Routine change | post edited, media uploaded, term created, field value updated |
| `debug` | Noise kept only when explicitly enabled | meta writes for internal keys, cron ticks |

### Category

`content`, `media`, `taxonomy`, `menu`, `fields` (ACF / custom fields / Sanity
fields), `user`, `comment`, `settings`, `theme`, `plugin`, `core`, `commerce`,
`security`, `error`, `stability`, `mail`, `http`, `cron`, `privacy`, `build`,
`deploy`, `system`.

Controlroom sorts by severity first, then by `occurred_at` descending, and groups
by `category`; recurring errors collapse on `fingerprint`.

## 2. Privacy rules (both stacks)

- Actors and objects are referenced by ID only. No names, emails, usernames,
  phone numbers, street addresses or raw IPs ever leave the site.
- `ip_hash` and `ua_hash` are keyed hashes with a per-site secret salt; they allow
  "same client as yesterday" correlation but cannot be reversed.
- Field-level diffs are kept for structural fields (status, slug, title, parent,
  template, menu order, taxonomy, role, capabilities). Long content is reported as
  a content hash plus size delta; full text diffs are opt-in per site.
- Values whose key matches the sensitive pattern list (`email`, `mail`, `phone`,
  `tel`, `address`, `password`, `pass`, `secret`, `token`, `key`, `auth`, `salt`,
  `iban`, `card`, `ssn`, `dob`, `birth`, `name` and others) are replaced by
  `{"redacted": true, "sha256_8": "…"}` so a change is visible but the value is not.
- Values stored on user profiles and on non-public content types (and on
  comments) are reported as content summaries, hash plus size, never the
  text, whatever their key; user preference keys (language, admin colour,
  editor settings) are excepted.
- Failed login attempts record a salted hash of the attempted username, never
  the username.
- Comment events reference the comment ID and post ID; the author fields are
  never copied.

## 3. Transport: pull API (signed, read-only)

The site exposes a small HTTPS API. Only the Controlroom collector holds the key.

| Endpoint | Purpose |
| --- | --- |
| `GET …/v1/manifest` | producer version, schema version, enabled modules, clock, retention, buffer stats, chain head |
| `GET …/v1/events?after=<id>&limit=500&min_severity=&category=&since=&until=&ack=<id>` | cursor pagination, ascending by `id`; `ack` tells the site what the collector has durably stored |
| `GET …/v1/events/<id>` | single event |
| `GET …/v1/summary?window=24h` | counts per severity and category plus top fingerprints, for the "most crucial on top" view |
| `GET …/v1/health` | health snapshot (versions, pending updates, debug flags, cron, integrity, Site Health results) |
| `GET …/v1/verify?from=<id>&to=<id>` | recompute the hash chain over a range |

### Request signing (v1)

```
X-NF-Key:       <key id, public, e.g. nfk_7Hs9…>
X-NF-Timestamp: <unix seconds>
X-NF-Nonce:     <32 hex chars, random per request>
X-NF-Signature: v1=<hex HMAC-SHA256(secret, string_to_sign)>

string_to_sign =
  "v1" "\n" METHOD "\n" PATH "\n" CANONICAL_QUERY "\n" TIMESTAMP "\n" NONCE "\n" SHA256_HEX(BODY)
```

- `PATH` is the request path without the host and without the query string.
- `CANONICAL_QUERY` is the query parameters sorted by key, RFC 3986 encoded, joined with `&` (empty string if none).
- Site rejects: skew > 300 s, reused nonce (10 min window), unknown key, bad
  signature (constant-time compare), plain HTTP (unless local dev flag), more
  than 120 requests per minute per key, and optionally any IP outside the
  configured allowlist. Every rejection is itself a `security` event (rate limited).
- Responses carry `Cache-Control: no-store` and never include personal data.
- Keys are per site. Rotation: a second key can be active during a grace period.
- Unauthenticated responses carry no site-specific headers (no chain head, no
  schema) and cost the site no query beyond the key lookup.
- Planned v2 of the scheme adds the request host to `string_to_sign` so a
  signed request cannot be replayed against a cloned site that shares the key.

### Aggregation and the cursor

`count` on an event is final once the collector has acknowledged the event
(`ack`) or the site has already served it: later occurrences of the same
fingerprint open a new event instead of raising a count the collector would
never see again. Collectors should therefore sum `count` over events with the
same `fingerprint`, not take the last value.

### Flood protection

Events whose actor is anonymous (failed logins, probes, rejected API calls,
XML-RPC calls) are capped per minute site-wide; the excess collapses into one
`system.flood` event (category `security`, severity `warning`) carrying the
dropped count and the top actions, so an attacker cannot fill the log or evict
unpulled rows.

### Push (optional, phase 2)

For `critical` events the site may also POST a signed beacon to
`https://controlroom-api.noformat.cloud/v1/ingest/<site>` using the same
signing scheme in reverse. Pull remains the source of truth; the beacon only
lowers latency.

## 4. Tamper evidence

Every stored event carries `prev_hash` (hash of the previous event) and
`hash = SHA256(prev_hash || canonical JSON of the event without hash fields)`.
The collector verifies the chain as it pulls and alerts on a break (someone
edited or deleted rows in the database). The chain head is in the manifest.
