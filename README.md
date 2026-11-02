# Noformat Logger (WordPress)

Site-side logging for websites Noformat builds and maintains. The plugin keeps a
tamper-evident, privacy-preserving log of every change made through WordPress
and of error, security and stability signals, and exposes it to the Noformat
Controlroom over a signed, read-only HTTPS API. Inside wp-admin a read-only
viewer shows it to administrators, or only to explicitly granted users on
client sites.

- Spec: [`PLAN.md`](PLAN.md) · shared contract: [`docs/CONTRACT.md`](docs/CONTRACT.md)
- Event catalogue: [`docs/EVENTS.md`](docs/EVENTS.md) · API and signing:
  [`docs/API.md`](docs/API.md) · threat model: [`docs/SECURITY.md`](docs/SECURITY.md)

Requirements: WordPress 6.4+, PHP 8.1+ (`hash`, `random_bytes`; `sodium` only to
store a key in the database). No runtime Composer dependencies, no frontend
assets, no outbound calls on page loads.

## What is logged

Every event has a severity (`critical` … `debug`), a category, a stable dotted
action, the object and actor by ID, the request (source, route, hashed client)
and, for changes, per-field `changes` (old → new). The full list with payloads
and aggregation rules is [`docs/EVENTS.md`](docs/EVENTS.md).

| Area | Examples |
| --- | --- |
| Content | posts, pages and CPTs (created, updated, published, trashed, deleted), post/term/user/comment meta, terms and assignments, menus and locations, media, comments, widgets, Customizer, ACF fields, option pages and field groups, export/import/privacy requests |
| Settings and extensions | options (with a critical list: `siteurl`, `home`, `admin_email`, `wp_user_roles`, …), plugins and themes (install, activate, update, delete, switch), core updates, plugin/theme file editor |
| Users and auth | user created/updated/deleted, role changes (elevation to administrator is critical), application passwords, password resets, logins (an administrator from a new client is a notice), failed logins with burst detection, bad auth cookies |
| Errors and stability | PHP notices/warnings/errors, fatals and uncaught exceptions, database errors, deprecations and `_doing_it_wrong`, failed outbound HTTP calls, mail failures |
| Security signals | REST user enumeration, REST auth errors, XML-RPC calls, suspicious 404 probes (`.env`, `.git`, `wp-config` backups, shells, …), risky configuration (daily audit), core and plugin file integrity (weekly), hash chain breaks |
| Operations | overdue cron hooks, pending updates, deploy markers, the logger's own activation, pruning and buffer overflow |

Recurring signals are fingerprinted and aggregated into `count` (60 s window,
1 h for suspicious 404 probes, 15 min for REST user and XML-RPC probes), so a
plugin throwing 10 000 notices a minute produces one row, and costs at most one
SELECT and one UPDATE per page view (no SELECT with a persistent object cache).
Unauthenticated clients cannot flood the log: anonymous security events may
create at most 120 rows per minute, the rest is folded into one `system.flood`.

## Privacy guarantees

CONTRACT §2, enforced in code and asserted by the integration tests:

- Actors and objects are referenced by ID only. No names, emails, usernames,
  phone numbers, addresses, raw IPs or user agents are stored or sent; IPs and
  user agents are `HMAC-SHA256(site salt, value)` truncated to 32 hex.
- Values under sensitive keys (`email`, `phone`, `address`, `password`,
  `token`, `key`, `name`, `login`, `billing`, … at any nesting level, also inside
  serialized arrays) become `{"redacted": true, "sha256_8": "…"}`.
- Long content is a hash plus length delta; full diffs only when
  `full_content_diff` is enabled.
- Failed logins carry a salted hash of the attempted username; comment author
  fields are never copied; file names and paths of uploads are hashed; every
  logged string has e-mail addresses and IPs scrubbed; error paths are made
  relative; SQL literals are stripped; titles of non-public post types (form
  entries, CRM contacts) are hashed; author slugs in routes and tokens in
  outbound HTTP paths are hashed.
- The wp-admin viewer shows the same IDs and hashes. It may resolve IDs to
  post titles and user display names while the page renders (names only for
  users who can `list_users`), because that stays on the site; nothing
  resolved is stored. Administrators see it by default; on client sites use
  `events_visible_to = capability_only` (see
  [Reading the log in wp-admin](#reading-the-log-in-wp-admin)).

## Installation

1. Upload the plugin to `wp-content/plugins/noformat-logger/` (no `vendor/`
   needed) and activate it (`wp plugin activate noformat-logger`). Activation
   creates four tables (`{prefix}nflog_events`, `nflog_fingerprints`,
   `nflog_nonces`, `nflog_meta`) and schedules the cron jobs.
2. Create an API key and print the `wp-config.php` lines:

   ```sh
   wp nflog key generate --print-config
   ```

3. Add the constants to `wp-config.php` above "That's all, stop editing!"
   (recommended: a database dump then does not contain the key):

   ```php
   define( 'NOFORMAT_LOGGER_KEY_ID', 'nfk_XXXXXXXXXXXXXXXX' );
   define( 'NOFORMAT_LOGGER_SECRET', '…43 chars base64url…' );
   ```

   Without constants the generated key stays in the database, encrypted with
   libsodium `secretbox` under a key derived from `wp_salt('auth')`; it can also
   be generated under **Tools → Noformat Logger → Status**. The secret is shown once.
   When constants are set, `wp nflog key generate --print-config` prints a fresh
   pair without storing it (for rotation).
4. Optional, recommended: install the MU loader so PHP errors raised while
   other plugins bootstrap are caught too:

   ```sh
   wp nflog install-mu-loader     # copies mu-loader/noformat-logger-loader.php to wp-content/mu-plugins/
   ```

   The loader is a small chained error handler that buffers up to 100 early
   errors; the plugin drains it when it loads. It is harmless without the plugin.
5. Give the site URL, key id and secret to the Controlroom and check
   `wp nflog status`.

Every site and environment gets its own key; a cloned staging site must not keep
the production constants.

## Updates

From 1.1.0 the plugin updates like any other plugin (Dashboard → Updates,
*update now*, auto-update toggle, `wp plugin update noformat-logger`, *View
details*) from the **GitHub Releases** of `wearenoformat/wp-noformat-logger`,
with no update server of our own: the `Update URI` header routes WordPress to
the `update_plugins_github.com` filter, the release's `manifest.json` carries
the version, requirements, changelog and the SHA-256 of `noformat-logger.zip`,
and a package whose digest differs is refused. Checks are cached for 6 hours
(ETag reused) and never break the site; the result is on the Status tab
(*Check now*) and in `wp nflog update-check`. Optional constants:
`NOFORMAT_LOGGER_UPDATE_REPO`, `NOFORMAT_LOGGER_UPDATE_TOKEN` (private
repositories; a public repository or a public releases-only mirror is
recommended instead), `NOFORMAT_LOGGER_UPDATE_MANIFEST` (self-hosted manifest).
Releases are cut with `tools/release.sh <version>` or by pushing a `v*` tag
(`.github/workflows/release.yml`). Details: [docs/UPDATES.md](docs/UPDATES.md).

## How the Controlroom connects

The Controlroom holds the key id and secret and pulls, signed, over HTTPS:

```
GET /wp-json/noformat-logger/v1/manifest
GET /wp-json/noformat-logger/v1/events?after=<cursor>&limit=500&ack=<last durably stored id>
GET /wp-json/noformat-logger/v1/summary?window=24h
GET /wp-json/noformat-logger/v1/health
GET /wp-json/noformat-logger/v1/verify?from=&to=
POST /wp-json/noformat-logger/v1/marker
```

Signing in one paragraph: each request carries `X-NF-Key`, `X-NF-Timestamp`
(unix seconds), `X-NF-Nonce` (32 random hex) and
`X-NF-Signature: v1=<hex HMAC-SHA256(secret, string_to_sign)>`, where
`string_to_sign` is `v1`, method, path, canonical query, timestamp, nonce and
the SHA-256 of the body joined with newlines. The site rejects skew over 300 s,
reused nonces, unknown keys, bad signatures, plain HTTP and IPs outside the
optional allowlist with the same generic 401. Details, canonical query rules and
a worked example: [`docs/API.md`](docs/API.md). Every event carries
`prev_hash` / `hash`; the collector verifies the chain as it pulls.

The zero-dependency Node reference client (Node 18+) signs requests and can
verify the chain locally:

```sh
export NFLOG_SITE=https://example.com NFLOG_KEY_ID=nfk_… NFLOG_SECRET=…
node tools/fetch-events.mjs --endpoint manifest
node tools/fetch-events.mjs --endpoint events --after 0 --limit 500 --query min_severity=warning
node tools/fetch-events.mjs --endpoint events --verify --limit 500     # page through everything, recompute the chain
node tools/fetch-events.mjs --endpoint summary --window 24h
node tools/fetch-events.mjs --endpoint marker --label "Release 2.3"
```

`tools/sign.mjs` and `tools/chain.mjs` export the signing and hash-chain
functions for reuse; `node tools/check-vectors.mjs` checks them against the
shared fixtures in `tests/fixtures/`.

## Settings and module toggles

**Tools → Noformat Logger** is one page with four tabs:
**Content log** (who changed what, see [below](#reading-the-log-in-wp-admin)),
**System log** (the developer console), **Status** (connection, key id and
source, last pull, ack cursor, storage, chain head with an inline *Verify
chain*, health, scheduled jobs, plugin updates; generate/rotate/revoke a
database key) and **Settings** (administrators: the settings and module
toggles below). URLs: `tools.php?page=noformat-logger&tab=content`
(`system`, `status`, `settings`); without `tab` the first tab the user may
open is shown, and a tab the user may not open is not in the bar and answers
403. The 1.0 menu URLs (`admin.php?page=noformat-logger`, `…-system`,
`…-status`, `…-settings`) and the older `tools.php?page=noformat-logger&tab=events`
redirect there (301) with their filters. The Plugins screen has a *Settings*
link for administrators. Users with `noformat_logger_view_events` but without
`manage_options` see the logs and a read-only Status tab.

Settings resolve as wp-config constants > option `nflog_settings` (status page)
> defaults, then the `noformat_logger/settings` filter:

| Setting | Default | Constant |
| --- | --- | --- |
| `retention_days` | 90 | `NOFORMAT_LOGGER_RETENTION_DAYS` |
| `hard_max_days` | 180 | `NOFORMAT_LOGGER_HARD_MAX_DAYS` |
| `max_rows` | 500 000 | `NOFORMAT_LOGGER_MAX_ROWS` |
| `log_debug` | false (*Debug events*: also store debug-severity events, which includes technical meta fields such as `aiowps_last_login_time`, screen options and editor preferences) | `NOFORMAT_LOGGER_LOG_DEBUG` |
| `full_content_diff` | false | `NOFORMAT_LOGGER_FULL_CONTENT_DIFF` |
| `rate_limit_per_minute` | 120 | `NOFORMAT_LOGGER_RATE_LIMIT_PER_MINUTE` |
| `ip_allowlist` | empty (exact IPs or CIDR) | `NOFORMAT_LOGGER_IP_ALLOWLIST` (comma separated) |
| `delete_data_on_uninstall` | false | `NOFORMAT_LOGGER_DELETE_DATA_ON_UNINSTALL` |
| `events_visible_to` | `administrators` (`manage_options` or `noformat_logger_view_events`); `capability_only`: only `noformat_logger_view_events`. Other values count as `capability_only`. Content log, its export, dashboard widget | `NOFORMAT_LOGGER_EVENTS_VISIBLE_TO` |
| `system_log_visible_to` | same two values and default; System log, its export and live tail, the widget's system line | `NOFORMAT_LOGGER_SYSTEM_LOG_VISIBLE_TO` |
| `buffer_max` | 200 events buffered before an intermediate write | `NOFORMAT_LOGGER_BUFFER_MAX` |
| `anonymous_per_minute` | 120 new rows per minute from anonymous security events (0 disables; excess becomes one `system.flood`) | `NOFORMAT_LOGGER_ANONYMOUS_PER_MINUTE` |
| `fingerprint_window` / `fingerprint_window_debug` | 60 / 3600 s | `NOFORMAT_LOGGER_FINGERPRINT_WINDOW`, `…_DEBUG` |
| `fingerprint_windows` | per-action map (see EVENTS.md) | option or filter only |

Other constants:

| Constant | Purpose |
| --- | --- |
| `NOFORMAT_LOGGER_KEY_ID`, `NOFORMAT_LOGGER_SECRET` | API key (take precedence over a database key) |
| `NOFORMAT_LOGGER_PREVIOUS_KEY_ID`, `NOFORMAT_LOGGER_PREVIOUS_SECRET` | Second key accepted during a planned rotation; remove when done |
| `NOFORMAT_LOGGER_ALLOW_HTTP` | `true` allows the API over plain HTTP. **Local development only** |
| `NOFORMAT_LOGGER_DISABLED_MODULES` | Comma-separated listener names to disable, e.g. `'AcfListener,MailListener'`; pinned modules cannot be re-enabled on the status page |

Modules are the listener classes (`PostListener`, `MetaListener`, …,
`ErrorHandler`, `ConfigAudit`, `IntegrityCheck`, `HealthReport`); `wp nflog
status` lists each one as `registered`, `disabled`, `unavailable` (its
dependency, e.g. ACF, is not installed) or `error`. Listeners whose dependency
loads after this plugin are re-checked on `plugins_loaded`.

Filters for site code (full list in [EVENTS.md](docs/EVENTS.md#filters)):

| Filter | Use |
| --- | --- |
| `noformat_logger/event` | Inspect or change an `Event` before it is buffered; return `null` to drop it |
| `noformat_logger/sensitive_keys`, `noformat_logger/safe_keys` | Extend or narrow redaction |
| `noformat_logger/ignored_options`, `noformat_logger/ignored_meta_keys` | Silence noisy options / meta keys (`*` globs) |
| `noformat_logger/critical_options`, `noformat_logger/option_severity` | Change option severities (e.g. lower `wp_user_roles`) |
| `noformat_logger/fingerprint_window` | Aggregation window per severity and action |
| `noformat_logger/client_ip`, `noformat_logger/is_https` | Trusted proxies (see troubleshooting) |
| `noformat_logger/privileged_roles`, `noformat_logger/login_burst`, `noformat_logger/probe_patterns` | Security rules |
| `noformat_logger/content_categories` | Which categories the Content log shows (the System log shows all others) |

## Reading the log in wp-admin

Both screens read the same table the API serves; nothing is written while you
browse, and names and titles are looked up at render time only (never stored).
The log is split once, in `Admin\Scope`:

| Screen | Categories | For |
| --- | --- | --- |
| **Content log** | content, media, taxonomy, menu, fields, comment, user, settings, theme, plugin, core, commerce, privacy | site administrators and editors |
| **System log** | error, stability, security, http, mail, cron, deploy, build, system | developers |

The filter `noformat_logger/content_categories` (list of category values) moves
categories between the two.

### Content log

An activity feed, newest first, grouped by day (*Today*, *Yesterday*,
*Monday 27 October*), 50 entries per page (*Screen Options* up to 200).

- **One entry per save**: events that share a request id form one story
  (`Admin\Stories`), paged in SQL by request (`EventRepository::storiesPage()`,
  so page counts are entries, not events). The sentence is the primary
  event's: content, user, menu, media, comment, settings, plugin, theme and
  core actions outrank the side effects of a save (`meta.*`, `term.assigned`,
  `acf.fields_saved`, `media.metadata_updated`); within equal rank the most
  severe, then the earliest. A muted suffix counts the rest ("… and 5 fields
  changed", "… and 2 fields, 1 category changed"); *What changed* shows the
  primary's diff, then each other event as a compact row (field, before →
  after). Profile meta of a `user.updated` joins its field list ("Demo Editor
  updated their profile: website, bio"). Filters apply to the events (a story
  is shown when any of its events matches). Meta written during a login
  before the current user is set is attributed to the user who logged in
  during that request. The System log keeps one line per event.

- **Entries**: avatar (the user's avatar, or a monogram for WP-CLI, Cron,
  System and visitors), one sentence in the site language built by
  `Admin\Humanizer` from the action, object and changes ("Demo Editor published
  the page *Demo landing page*", "WP-CLI changed the role of Demo Editor from
  editor to administrator", "Demo Editor changed the setting Tagline"), a meta
  line (time, source chip, object type) and a dot for notice and above
  (critical: an *Important* badge). Unknown actions show the stored summary.
  Titles link to the editor when you may edit the item; titles of items that
  are not publicly viewable and that you may not edit, and hashed titles of
  deleted private items, read "a private item". User names need `list_users`
  (otherwise "User #7").
- **What changed**: a before/after table with human field labels (`post_name` →
  Slug, `blogdescription` → Tagline …); redacted values show "hidden for
  privacy", truncated ones "shortened", content summaries "Content changed:
  26 → 53 characters (+27)". Footer: request id with *Show everything from this
  save*, route, event id.
- **Header**: period (Today, 7 days, 30 days default, All), search (summary,
  action, object id), *Export JSON*.
- **Needs attention**: critical and error counts of the System log in the last
  24 h with a link there (only when there are any and you may read it).
- **Filters** (GET, bookmarkable): `type` (pages, posts, media, menus, fields,
  users, comments, settings, extensions, other), `person` (a user id or
  `cli` / `cron` / `system` / `anonymous` / `app`), `important=1` (notice and
  above), and under *More filters* `from` / `to` (site time), `object_type` +
  `object_id`, `request_id`. The older parameters (`severity`, `category`,
  `event_action`, `actor_id`, `s` …) still work.

### System log

A console, always dark, with severity counters for the window (click to toggle
that severity), events / occurrences totals and the chain head above it.

- **Filter**: one text field (press `/`): free text searches summary and
  action; keys `action:` (prefix), `cat:`, `src:`, `req:`, `fp:`, `since:`
  (`1h`, `24h`, `7d`, `30d`, `15m` …, `all`), `sev:`, `min:`, `actor:` (user id
  or type), `obj:type/id`, `from:` / `to:`. Plus severity and category chips,
  source and window selects (default 7 days). Parsed by
  `EventFilters::parseQuery()` into the same validated filters.
- **Lines**: `HH:MM:SS.mmm`, severity tag (`CRIT ERR WARN NOTE INFO DBG`),
  action, location (`file:line` shortened to the last 3 segments for PHP,
  deprecation and database events, otherwise the object), message, `×N` for
  aggregated signals. *Wrap* toggles truncation (remembered per browser).
  Click a line for the detail: changes and context as tinted JSON, the stack
  when `context.trace` exists, request id and fingerprint (click to filter),
  actor, source, route, hash and previous hash, *Copy as JSON* and *Open in
  Content log* when the same request also changed content.
- **Follow**: polls `admin-ajax.php?action=nflog_tail` every 5 s for newer
  events (nonce, `system_log_visible_to`, at most 200 per call), never while
  the tab is hidden, and stops after 30 minutes. *Load older* pages back 200
  lines at a time through the same endpoint. Every line, including the first
  page (JSON in the page), is rendered by one script, `assets/system-log.js`.

### Export, widget, access

- **Export JSON** (both screens): the current filter and scope, newest first,
  at most 5 000 events (`truncated: true` when more matched), each event in the
  CONTRACT §1 shape, plus `exported_at`, `site`, `producer`, `scope`,
  `filters`, `total`. Nonce and access checked; logged as
  `logger.events_exported` (count, scope and filter names, never values).
- **Dashboard widget** (*Recent activity*): the 5 latest Content log stories,
  one line each (sentence and suffix, shortened with the full text as a
  tooltip), and, for users who may read the System log, "System: 3 critical,
  4 errors in 24 h → System log" when there are any. Three small queries (a
  fourth when an entry is aggregated or a login story needs attribution),
  only on the dashboard.

**Who can read it**: two settings with the same values (Settings → Access, or
constants):

| Setting | `administrators` (default) | `capability_only` |
| --- | --- | --- |
| `events_visible_to`: Content log, its export, widget | `manage_options` or `noformat_logger_view_events` | only `noformat_logger_view_events`; administrators lose the Content log tab (direct links answer 403) and keep the other tabs |
| `system_log_visible_to`: System log, its export and live tail | `manage_options` or `noformat_logger_view_events` | only `noformat_logger_view_events`; the tab and links disappear for everyone else (direct links answer 403) |

On a client site that should keep the content log but not the developer view:

```php
define( 'NOFORMAT_LOGGER_SYSTEM_LOG_VISIBLE_TO', 'capability_only' );
```

To hide both, also set `NOFORMAT_LOGGER_EVENTS_VISIBLE_TO`, and grant the
capability to Noformat staff accounts only:
`wp user add-cap <noformat-user> noformat_logger_view_events`. The capability is
assigned to no role on install. Reading is not logged (`logger.events_viewed`
is a `debug` event, kept only with `log_debug`).

Pages other than the plugin's own and the dashboard run no query on the log
tables and load no plugin CSS or JavaScript.

## Retention

A daily cron prunes events older than `retention_days` (90) **that the
collector has acknowledged** (`ack` cursor); unacknowledged rows are kept until
`hard_max_days` (180). Above `max_rows` (500 000) acknowledged rows and leading
debug/info rows are removed, never an unacknowledged row of severity notice or
higher. Pruning
always removes an id prefix and records an anchor, so the remaining chain still
verifies. `wp nflog prune --dry-run` shows what would go. Nonces expire after
10 minutes, fingerprint rows after 7 days without activity (hourly cleanup).

## WP-CLI

```
wp nflog status [--format=json]                 # manifest: storage, chain head, key, modules, cron
wp nflog key generate|rotate|revoke [--print-config] [--force] [--all]
wp nflog tail [--lines=20] [--follow] [--severity=warning] [--category=security,error]
wp nflog events [--after=0] [--limit=50] [--severity=…] [--category=…] [--format=json]
wp nflog prune [--days=N] [--dry-run]
wp nflog verify [--from=ID] [--to=ID]           # recompute the hash chain; exits 1 on a break
wp nflog integrity [--no-fail]                  # core/plugin checksums + recent chain; exits 1 on a problem
wp nflog health                                 # health snapshot JSON (same as GET /health)
wp nflog marker "Release 2.3" [--ref=v2.3.0]    # deploy marker
wp nflog test-event [--severity=info]
wp nflog install-mu-loader [--force]
wp nflog update-check [--cached] [--format=json]  # check GitHub for a plugin update now (docs/UPDATES.md)
```

`key rotate` keeps the previous key valid for 24 hours; `key revoke` ends the
grace period (`--all` also revokes the current key).

## Health and integrity

- **Health** (`GET /health`, `wp nflog health`): versions (PHP, WordPress,
  database, plugin, schema), environment, active theme and plugins with pending
  updates, update counts and staleness, debug flags, cron state and overdue
  hooks, HTTPS state, users per role, published content counts, events per
  severity in the last 24 h, Site Health direct test results, the last integrity
  run and current config audit findings. Heavy parts are cached every 15
  minutes. Shape: [EVENTS.md](docs/EVENTS.md#health-snapshot).
- **Config audit** (daily): risky settings such as `WP_DEBUG_DISPLAY` on, file
  editor enabled, open registration with an elevated default role, XML-RPC on,
  writable `wp-config.php`, an `admin` user, a public `debug.log`. One
  `config.risky_setting` event per state change. Finding ids:
  [EVENTS.md](docs/EVENTS.md#config-audit-findings).
- **Integrity** (weekly and `wp nflog integrity`): core files against
  `api.wordpress.org` checksums, wordpress.org plugins against their checksum
  files, and the last 1 000 events of the hash chain. A mismatch is a critical
  `integrity.mismatch`; the CLI exits 1 on a mismatch or chain break (use
  `--no-fail` to only print), so it can gate CI or monitoring.

## Troubleshooting

**401 Unauthorized.** The body is deliberately generic; the reason is in the
site's own `api.auth_failed` event (`wp nflog tail --category=security`):

| `reason` | Fix |
| --- | --- |
| `missing_headers`, `bad_format` | Send all four `X-NF-*` headers; key id `nfk_` + 16 base62, nonce 32 lowercase hex, signature `v1=<64 hex>` |
| `unknown_key` | Key id not configured on this site (constants vs database key; a revoked or expired previous key) |
| `bad_signature` | Wrong secret, or the signed path/query differ from the request: sign the path as sent (subdirectory installs include it) and the canonical query (sorted, RFC 3986); see API.md |
| `skew` | Client and server clocks differ by more than 300 s: fix NTP on the collector or the server; the manifest's `clock` shows the site's time |
| `nonce_reused` | Retried a request with the same nonce: generate a new nonce per request |
| `https_required` | The request reached PHP as plain HTTP (see below) |
| `ip_not_allowed` | The client IP is not in `ip_allowlist` (check `noformat_logger/client_ip` behind a proxy) |

**HTTPS behind a proxy or load balancer.** When TLS terminates at a proxy,
`is_ssl()` is false and the API answers 401 (`https_required`). Prefer fixing
WordPress itself (`$_SERVER['HTTPS'] = 'on'` when the trusted proxy sends
`X-Forwarded-Proto: https`); or tell only the logger:

```php
add_filter( 'noformat_logger/is_https', static fn( bool $https ): bool =>
	$https || ( ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) === 'https' && '10.0.0.5' === ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
add_filter( 'noformat_logger/client_ip', static fn( string $ip ): string =>
	'10.0.0.5' === $ip && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? trim( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] ) : $ip );
```

Only trust forwarded headers from your own proxy's address.

**401 although the signature is right, and `api.auth_failed` is missing.** A
security plugin (or code) that rejects "unauthenticated" REST requests in
`rest_authentication_errors` stops WordPress before the logger's check runs.
The plugin clears that error at priority `PHP_INT_MAX` for requests to
`noformat-logger/v1` that carry all four `X-NF-*` headers (the HMAC check still
decides). If a plugin blocks earlier (e.g. on `rest_api_init`, `parse_request`
or at the web server / WAF), allow the path `/wp-json/noformat-logger/v1/` there.

**401 after rotating the WordPress salts.** A key stored in the database is
encrypted with `wp_salt('auth')`; new salts make it unreadable
(`logger.key_unreadable` once per hour). Generate a new key, or better, keep the
key in the wp-config constants, which salt rotation does not affect.

**Custom REST prefix.** Sites that change `wp-json` (`rest_url_prefix`) report
it in the manifest as `rest_prefix`; `tools/fetch-events.mjs --rest-prefix <p>`
(or `--rest-prefix auto`) signs accordingly.

**429 Too Many Requests.** More than `rate_limit_per_minute` (120) signed
requests per key per minute. Page with `limit=500`, back off until the next
minute, and avoid running several collectors with one key.

**503 `nflog_storage_unavailable`.** Tables are missing or the database failed.
Reactivate the plugin (re-runs the schema install) and check `wp nflog status`
(`storage`, `failures`).

**No events for a module.** `wp nflog status` shows the module state; check
`NOFORMAT_LOGGER_DISABLED_MODULES` and the toggles on the status page. `debug`
events need `log_debug`.

**`failures` keeps rising.** Internal errors are counted, never shown; the
manifest's `failures.last` names where (`flush`, `register.<Module>`, …).

## Development

```sh
composer install
composer check                   # php -l, WPCS (phpcs), PHPStan level 6, PHPUnit
node tools/check-vectors.mjs     # signing + hash-chain vectors shared with the Node client
```

Integration harness (WP-CLI, PHP with `pdo_sqlite` and `sodium`, Node 18+,
curl, unzip):

```sh
bash tests/integration/run-all.sh            # fresh site in .local/wp-all on :8794, all smoke scripts, summary
```

or step by step against one site:

```sh
NFLOG_WP_DIR=$PWD/.local/wp NFLOG_PORT=8787 bash tests/integration/setup.sh
NFLOG_WP_DIR=$PWD/.local/wp bash tests/integration/smoke.sh           # API, signing, chain, rejections
NFLOG_WP_DIR=$PWD/.local/wp bash tests/integration/smoke-content.sh   # content listeners (installs ACF; NFLOG_SMOKE_ACF=0 skips)
NFLOG_WP_DIR=$PWD/.local/wp bash tests/integration/smoke-privacy.sh   # privacy canary: profile, form-submission, comment and ACF values never leave in clear
NFLOG_WP_DIR=$PWD/.local/wp bash tests/integration/smoke-system.sh    # settings, plugins/themes, users, auth, probes
NFLOG_WP_DIR=$PWD/.local/wp bash tests/integration/smoke-errors.sh    # PHP/DB/HTTP/mail errors, MU loader, integrity, health, audit
NFLOG_WP_DIR=$PWD/.local/wp bash tests/integration/smoke-admin.sh     # Tools tabs, legacy redirects, viewer, export, widget, access setting, query budget
NFLOG_WP_DIR=$PWD/.local/wp bash tests/integration/smoke-update.sh    # self-update from a locally served release (9.9.9), tampered zip refused
```

`setup.sh` builds a throwaway WordPress on SQLite (`sqlite-database-integration`),
sets the key constants and `NOFORMAT_LOGGER_ALLOW_HTTP`, symlinks this plugin
and activates it. Each smoke script starts `wp server`, makes changes through
WP-CLI and HTTP, reads them back through the signed API with
`tools/fetch-events.mjs` and asserts actions, severities and that no personal
data leaked. `run-all.sh` resets the site first (`NFLOG_FRESH=0` reuses it) and
keeps per-script logs in `<site>/.nflog-run-all/`.

A development `vendor/` (PHPUnit, Mockery, …) is never loaded inside WordPress:
the bootstrap only uses `vendor/autoload.php` for a `--no-dev` install and falls
back to the bundled PSR-4 autoloader otherwise.

## Uninstall

Deactivation clears cron and keeps all data. Deleting the plugin always clears
cron and the updater's cached release (`noformat_logger_update` transient); it drops the tables, options, the `nflog_known_clients` user meta, the
viewer's per-page screen option and the burst transients only when
`delete_data_on_uninstall` is enabled.
