# wp-noformat-logger: plan and specification

Status: Phase 1 delivered (1.0.0; privacy fix 1.0.1). Owner: Noformat. Consumer: Controlroom collector.
Shared contract: [`docs/CONTRACT.md`](docs/CONTRACT.md) (a copy of the outer `../CONTRACT.md`, kept identical).

## 1. Goals

1. Record every change made through WordPress to the database, in every area:
   posts, pages and custom post types, post/term/user/comment meta, taxonomies,
   menus, media, comments, users and roles, options and settings, theme mods and
   the Customizer, widgets, plugins, themes, core updates, ACF fields and option
   pages, WooCommerce (when present).
2. Record every error, security and stability signal available from PHP and
   WordPress: PHP errors and fatals, database errors, deprecations, failed
   outbound HTTP calls, mail failures, failed logins and auth-cookie failures,
   XML-RPC and REST probing, risky configuration, core file integrity drift,
   cron health.
3. Expose it to the Controlroom through a signed, read-only HTTPS API
   with cursor pagination, server-side severity ordering and a health snapshot;
   inside wp-admin only through a read-only viewer under the access rule of D11.
4. Never store or transmit personal data (see contract §2).
5. Never harm the site: zero fatal paths, bounded storage, batched writes,
   no frontend assets, no outbound calls on page loads.

Non-goals: logging code changes (git does this), being a UI for clients,
replacing backups or revisions.

## 2. Decisions

| # | Decision | Why |
| --- | --- | --- |
| D1 | **Pull API, HMAC-signed requests**, not Application Passwords or a static bearer token | Application Passwords are user-bound and grant the whole REST API. A static bearer has no replay protection. HMAC with timestamp and nonce gives per-request integrity, replay protection and keys that are never sent on the wire. Pull keeps the site ignorant of Controlroom and matches the brief ("available only to read by our scripts"). |
| D2 | Secret lives in `wp-config.php` constants (`NOFORMAT_LOGGER_KEY_ID`, `NOFORMAT_LOGGER_SECRET`); fallback: option encrypted with libsodium `secretbox` under a key derived from `wp_salt('auth')` | A database dump alone must not expose the key. Constants also survive site clones badly on purpose: a cloned staging site needs its own key. |
| D3 | Own tables (`{prefix}nflog_events`, `{prefix}nflog_fingerprints`, `{prefix}nflog_nonces`), not a custom post type or options | Volume, indexes, prune by range, no `wp_posts` pollution, no autoload cost. |
| D4 | Events buffered in memory per request and written in one multi-row `INSERT` on `shutdown` | Page requests should not pay one query per hook. Shutdown still runs after fatals, so the buffer is not lost. The fatal handler flushes synchronously as a last resort. |
| D5 | Hash chain per site (`prev_hash`, `hash`) | Tamper evidence at near-zero cost. A row edited or deleted by hand breaks the chain, which the collector notices. |
| D6 | Fingerprint + rate limit for recurring signals (errors, deprecations, failed logins, probes) | One bad plugin can throw 10 000 notices a minute. Store the first occurrence, aggregate the rest into `count` per fingerprint per window (60 s default, 1 h for `debug`). |
| D7 | Listeners are independent classes registered from a static list; each guards its own hooks and can be disabled per site | A faulty listener must not take the others down; sites differ in what is installed. |
| D8 | Optional MU loader (`mu-loader/noformat-logger-loader.php`) | Must-use plugins load before regular plugins, so the error handler catches failures inside other plugins' bootstrap. Optional because some hosts manage mu-plugins. |
| D9 | PHP 8.1+, WordPress 6.4+, strict types, namespaced, PSR-4 with a bundled fallback autoloader, no runtime Composer dependencies | Modern hosts, no vendor directory in production, nothing to conflict with other plugins. |
| D10 | WPCS, PHPStan (level 6, `szepeviktor/phpstan-wordpress`), PHPUnit unit tests with Brain Monkey, an integration smoke test on a real WordPress running on SQLite | "High end" means it is verifiable, not just well written. |
| D11 | Tools → Noformat Logger, one page with tabs (Content log, System log, Status, Settings) for two audiences: a **Content log** (activity feed with human sentences, day groups, before/after diffs; categories content, media, taxonomy, menu, fields, comment, user, settings, theme, plugin, core, commerce, privacy) for site administrators, and a **System log** (dark developer console: monospace lines by severity, repeat counts, JSON and stack detail, query filter, live follow) for developers; each has its own visibility setting (`events_visible_to`, `system_log_visible_to`; default administrators, restrictable to the `noformat_logger_view_events` capability on client sites). IDs are resolved to names at render time only. | The log must be useful to the people on the site as well as to Noformat, but clients and developers need different views, and client sites may need the developer view hidden. |

## 3. Architecture

```
wp-noformat-logger/
├── noformat-logger.php          bootstrap: constants, requirement checks, autoloader, Plugin::boot()
├── uninstall.php                drops tables/options only if "delete data on uninstall" is set
├── mu-loader/noformat-logger-loader.php   optional early error handler (copy to wp-content/mu-plugins)
├── src/
│   ├── Plugin.php               wiring, listener registry, activation/deactivation, upgrades
│   ├── Autoloader.php           PSR-4 fallback when vendor/ is absent
│   ├── Config.php               constants > options > defaults; feature toggles; sensitive key patterns
│   ├── Event.php, Severity.php, Category.php, Source.php   value object + enums
│   ├── Logger.php               log(), buffer, flush(), never throws
│   ├── Context/RequestContext.php    request id, source detection, actor, ip/ua hashes, route
│   ├── Privacy/Redactor.php     sensitive key matching, truncation, hashing, diff builder
│   ├── Storage/Schema.php       dbDelta install + versioned migrations
│   ├── Storage/EventRepository.php   batch insert, cursor query, summary, prune, chain head
│   ├── Storage/HashChain.php    canonical JSON + SHA-256 chaining, verify(range)
│   ├── Storage/FingerprintStore.php  rate limiting + aggregation of recurring events
│   ├── Listeners/               one class per domain (see §4), each implements Listener::register()
│   ├── Errors/                  ErrorHandler, FatalHandler, DbErrorListener, DeprecationListener, HttpApiListener, MailListener
│   ├── Security/                AuthListener, RestProbeListener, XmlRpcListener, ConfigAudit, IntegrityCheck, RateLimiter
│   ├── Health/HealthReport.php  versions, updates, cron, debug flags, Site Health tests, integrity state
│   ├── Rest/                    Controller (routes), Authenticator (HMAC), NonceStore, Responder
│   ├── Admin/Menu.php           Tools → Noformat Logger: tabs Content log / System log / Status / Settings (D11), legacy redirects
│   ├── Admin/ContentLogPage.php, SystemLogPage.php, StatusPage.php, SettingsPage.php, EventFilters.php, Resolver.php, Access.php   the tabs, JSON export
│   ├── Admin/DashboardWidget.php   last 24 h per severity, latest critical/error
│   ├── Update/                  Updater (WordPress update hooks), ReleaseClient (GitHub Releases / manifest, cache), Release, Changelog
│   ├── Cli/Commands.php         wp nflog …
│   └── Cron/Scheduler.php       prune, integrity check, nonce cleanup, health cache
├── tools/fetch-events.mjs       zero-dependency Node reference client that signs requests (for Controlroom devs and smoke tests)
├── tests/unit, tests/integration
├── composer.json, phpcs.xml.dist, phpstan.neon.dist, phpunit.xml.dist
├── README.md, CHANGELOG.md, docs/API.md, docs/EVENTS.md, docs/SECURITY.md
```

### Event flow

```
WP hook → Listener builds Event (redacted, with changes[]) → Logger::log()
  → FingerprintStore (aggregate? drop? pass) → in-memory buffer
  → shutdown: EventRepository::insertBatch() with hash chain
Controlroom → GET /events?after=N (signed) → Authenticator → EventRepository::after(N)
```

### Storage schema (v1)

`{prefix}nflog_events`

| column | type | notes |
| --- | --- | --- |
| id | bigint unsigned auto_increment PK | cursor |
| occurred_at | datetime(3) | UTC |
| severity | tinyint unsigned | 0 critical … 5 debug; index `(severity, id)` |
| category | varchar(32) | index `(category, id)` |
| action | varchar(64) | |
| object_type | varchar(32) null | |
| object_id | varchar(64) null | string: supports non-numeric IDs (option names, plugin slugs) |
| object_subtype | varchar(64) null | post type, taxonomy, meta key group |
| actor_type | varchar(16) | |
| actor_id | bigint unsigned null | |
| actor_role | varchar(64) null | |
| request_id | char(32) | index |
| source | varchar(16) | |
| route | varchar(255) null | |
| method | varchar(8) null | |
| ip_hash | char(32) null | |
| ua_hash | char(32) null | |
| summary | varchar(255) | already redacted |
| changes | json / longtext | array of {field, old, new} |
| context | json / longtext | ≤ 64 KB, redacted |
| fingerprint | char(32) null | index |
| count | int unsigned default 1 | |
| site_id | bigint unsigned default 1 | multisite blog id |
| prev_hash | char(64) | |
| hash | char(64) | |

`{prefix}nflog_fingerprints`: `fingerprint` PK, `last_event_id`, `window_start`, `count`, `last_seen`.
`{prefix}nflog_nonces`: `nonce_hash` PK, `expires_at` (index). Cleaned by cron.

Options (not autoloaded): `nflog_schema_version`, `nflog_settings`, `nflog_ack_cursor`, `nflog_key_meta` (key id, created_at, previous key id during rotation), `nflog_chain_head`, `nflog_integrity_state`, `nflog_health_cache`.

## 4. Coverage: what is logged

Every event records actor, request context and the fields in the contract.
`debug`-severity events are dropped unless the site setting `log_debug` is on.

### Content and structure

| Domain | Hooks | Captured | Severity |
| --- | --- | --- | --- |
| Posts, pages, CPTs | `pre_post_update` (snapshot), `wp_after_insert_post`, `transition_post_status`, `wp_trash_post`, `untrashed_post`, `before_delete_post`, `wp_restore_post_revision`, `wp_insert_post_data` (for `post_name`) | created / updated / status changed / trashed / restored / deleted / revision restored; changes[] for title, slug, status, author id, parent, menu order, template, dates, comment/ping status; content as hash + length delta (full diff opt-in); post type and whether autosave/revision (skipped) | publish, unpublish, delete, trash: `notice`; other edits: `info` |
| Post / term / user / comment meta | `update_{type}_metadata` (pre, to capture old), `added_{type}_meta`, `updated_{type}_meta`, `deleted_{type}_meta` | key, old, new (redacted, truncated to 2 KB, serialized values summarised as key diff); internal noise keys ignored (`_edit_lock`, `_edit_last`, `_encloseme`, `_pingme`, `_oembed_*`, `session_tokens`, `wp_user-settings*`, `closedpostboxes_*`, `metaboxhidden_*`, `*_capabilities` handled by the user listener, `_wp_attachment_metadata` by media); filterable | `info`; capability/role meta: `notice` |
| Taxonomies and terms | `created_term`, `edit_terms` (pre), `edited_term`, `delete_term`, `set_object_terms` | term id, taxonomy, changed fields; assignment diff (added/removed term ids) per object | `info` |
| Menus | `wp_create_nav_menu`, `wp_update_nav_menu`, `wp_delete_nav_menu`, `wp_update_nav_menu_item`, `update_option_theme_mods_*` for `nav_menu_locations` | menu id, item id, item fields (type, object id, parent, position, url hash for custom links), location assignments | `notice` |
| Media | `add_attachment`, `edit_attachment`, `delete_attachment`, `wp_handle_upload`, `wp_update_attachment_metadata` | attachment id, mime, size, dimensions, parent id, file name hash; never the file | `info`; delete: `notice` |
| Comments | `wp_insert_comment`, `edit_comment`, `transition_comment_status`, `delete_comment`, `spam_comment` | comment id, post id, type, status change, actor; author fields never copied | `info` |
| Widgets and Customizer | `customize_save_after` (per setting id with old/new), `update_option_sidebars_widgets`, `update_option_widget_*` | setting id, redacted diff | `notice` |
| ACF | `acf/save_post` at priority 1 (snapshot) and 20 (diff); ACF option pages via options `options_*`; field groups are posts of type `acf-field-group` (content listener, `fields` category) | field name, key, old/new redacted; grouped per save under one request id | `info`; field group / option page changes: `notice` |
| Settings | `added_option`, `updated_option`, `deleted_option` | option name, old/new redacted; serialized arrays diffed by key; denylist of noise (transients, `cron`, `_site_transient_*`, `recently_activated`, `*_last_checked`, `rewrite_rules`, `akismet_spam_count`, plus a filter) | `info`; critical options (`siteurl`, `home`, `admin_email`, `users_can_register`, `default_role`, `active_plugins`, `template`, `stylesheet`, `permalink_structure`, `blog_public`, `wp_user_roles`): `notice` or `critical` as listed in §5 |
| Plugins / themes / core | `activated_plugin`, `deactivated_plugin`, `deleted_plugin`, `upgrader_process_complete`, `switch_theme`, `deleted_theme`, `_core_updated_successfully`, `automatic_updates_complete`, `wp_ajax_edit-theme-plugin-file` (priority 0, logs the attempt) | slug, versions before/after, whether auto-update, installer source | `notice`; file editor use: `warning` |
| Users and roles | `user_register`, `profile_update` (changed field names only; `user_pass` as "changed"), `set_user_role`, `add_user_role`, `remove_user_role`, `deleted_user`, `grant_super_admin`, `revoke_super_admin`, `wp_create_application_password`, `wp_delete_application_password`, `password_reset`, `retrieve_password` | user id, role before/after, capability diff | `notice`; new administrator or elevation to administrator: `critical` |
| Export / import / privacy | `export_wp`, `import_start`, `import_end`, `wp_privacy_personal_data_export_file`, `wp_privacy_personal_data_erased` | type and counts only | `notice` |
| WooCommerce (module, phase 2) | `woocommerce_order_status_changed`, `woocommerce_new_order`, product changes (posts), settings (options), `woocommerce_payment_gateway_*` | order id and status only; no customer data | `info` / settings `notice` |
| Multisite (phase 2) | `wp_initialize_site`, `wp_delete_site`, `add_user_to_blog`, `remove_user_from_blog`, network options | | `notice` |

### Errors, security, stability

| Signal | Mechanism | Captured | Severity |
| --- | --- | --- | --- |
| PHP errors | `set_error_handler` (chained: previous handler still called), honours `error_reporting()` | type, message, file relative to ABSPATH, line, 10-frame sanitized trace (no args), fingerprint, count | notice/deprecated: `warning`; warning: `error`; |
| PHP fatals | `register_shutdown_function` + `error_get_last()`; also wraps WP's fatal handler via `wp_php_error_message` filter if present | same as above plus whether on a public URL | `critical` |
| Uncaught exceptions | `set_exception_handler` chained | class, message, file, line, trace | `critical` |
| Database errors | `shutdown` inspects `$wpdb->last_error`; `wp_die_handler` for "Error establishing…" paths that still reach plugins | error text (SQL stripped of literal values), caller | `critical` |
| Deprecations and misuse | `deprecated_function_run`, `deprecated_argument_run`, `deprecated_hook_run`, `deprecated_file_included`, `doing_it_wrong_run` | function, replacement, version; fingerprinted | `warning` |
| Outbound HTTP failures | `http_api_debug` with `WP_Error` or status ≥ 500 | host and path (query stripped), status, error code, duration | `error` |
| Mail failures | `wp_mail_failed` | error message, subject hash, recipient count | `error` |
| Failed logins | `wp_login_failed`, `authenticate` failures | username hash, ip hash, fingerprinted per (username hash, ip hash); burst detection (≥ 10 in 5 min) | `warning`; burst: `error` |
| Successful logins / logouts | `wp_login`, `wp_logout`, `set_logged_in_cookie` | user id, role, source; first login of an administrator from a new ip hash | `info`; admin from new client: `notice` |
| Auth cookie problems | `auth_cookie_bad_hash`, `auth_cookie_bad_username`, `auth_cookie_bad_session_token`, `auth_cookie_expired` | which, user id if resolvable | `warning` |
| Application password auth failures | `application_password_failed_authentication` | user id, ip hash | `warning` |
| REST probing | `rest_pre_dispatch`: anonymous access to `/wp/v2/users*`; `rest_authentication_errors` | route, ip hash; aggregated | `warning` |
| XML-RPC | `xmlrpc_call`; `xmlrpc_enabled` state in health | method name, ip hash; aggregated | `warning` |
| Suspicious 404 probes | `template_redirect` on 404 matching a pattern list (`.env`, `wp-config`, `.git`, `.sql`, `phpmyadmin`, `xmlrpc.php` POST floods, `wp-login.php` floods) | path pattern (not full path), ip hash; aggregated hourly | `warning` |
| Risky configuration | daily `ConfigAudit`: `WP_DEBUG_DISPLAY` on, `DISALLOW_FILE_EDIT` off, `users_can_register` on with `default_role` ≠ subscriber, file editor reachable, `xmlrpc` enabled, directory listing, `wp-config.php` writable, admin user named `admin`, debug.log inside web root and readable | finding id, state; one event per state change, not per day | `warning`; some `error` |
| Core integrity | weekly (and on demand) check against `api.wordpress.org/core/checksums`; plugin checksums for wordpress.org plugins where available | list of modified/missing/unknown files (paths only) | mismatch: `critical` |
| Cron health | `HealthReport` compares scheduled vs now; event when a hook is > 2× its interval overdue; `DISABLE_WP_CRON` state | hook name, overdue seconds | `warning` |
| Updates pending | daily: core/plugin/theme updates available, flagged as security when the wordpress.org API says so | counts and slugs | `notice`; security update pending > 7 days: `warning` |
| Deploy markers | `wp nflog marker "<text>"` or `POST …/v1/marker` (signed) | free text label, git ref if provided | `notice` |

## 5. Critical event list (always `critical`)

`siteurl` or `home` changed · `admin_email` changed · new user with `administrator` role · role elevated to `administrator` · `wp_user_roles` changed · `active_plugins` changed outside the plugins screen (source ≠ admin) · plugin or theme file edited via the editor · fatal error on a frontend request · database error · uncaught exception · core integrity mismatch · hash chain break detected locally · logging storage unavailable (the plugin reports its own failure once per hour through the manifest).

## 6. REST API

Namespace `noformat-logger/v1`, `show_in_index => false`, every route uses
`Rest\Authenticator` as `permission_callback`; cookie and application password
auth are explicitly ignored for this namespace. Endpoints as in the contract §3
plus `POST /marker`. Limits: `limit` ≤ 500; `context` is omitted from list
responses when `?fields=compact`. Responses: JSON, `Cache-Control: no-store`,
`X-NF-Chain-Head`, `X-NF-Schema: 1`.

Authentication failures return 401 with a generic body and are logged as
`security` events (fingerprinted per reason and ip hash, plus the key id when
it is a known key; subject to the anonymous cap). Unknown key ids and
bad signatures are indistinguishable to the caller.

## 7. WP-CLI

`wp nflog status` · `wp nflog key generate|rotate|revoke [--print-config]` ·
`wp nflog tail [--follow] [--severity=] [--category=]` · `wp nflog events --after= --limit=` ·
`wp nflog prune [--days=] [--dry-run]` · `wp nflog verify [--from= --to=]` ·
`wp nflog integrity` · `wp nflog health` · `wp nflog marker "text"` ·
`wp nflog test-event` · `wp nflog install-mu-loader`.

## 8. Performance and safety budget

- ≤ 1 extra query on a normal page view (none on cached pages). Listener hooks
  are cheap closures; nothing is computed unless a write happens.
- Admin saves: the snapshot in `pre_post_update` is one `get_post()` (already
  cached by WordPress); ACF snapshot reads existing values once.
- Batch insert on shutdown; the 200-event buffer is written when full (long
  runs), `system.buffer_overflow` only when storage failed.
- A recurring fingerprint costs ≤ 1 SELECT + 1 UPDATE per request (0 SELECT
  with a persistent object cache); anonymous security events ≤ 120 new rows per
  minute site-wide (`system.flood` for the rest).
- Prune daily: keep `retention_days` (default 90) and never delete past the
  collector's `ack` cursor unless rows are older than `hard_max_days` (default 180)
  or the table exceeds `max_rows` (default 500 000).
- Every public method of `Logger`, listeners and storage is wrapped so an
  exception inside the logger cannot surface; failures increment an internal
  counter exposed in the manifest.
- No JavaScript or CSS on the frontend. Admin CSS only on the plugin page and
  on the dashboard when the widget renders; no JavaScript. The viewer queries
  the log only on its own tab (count + page + count hydration, summary strip),
  the dashboard widget runs one grouped count and one 5-row query; every other
  admin screen runs no query on the plugin tables.

## 9. Phases

**Phase 1 (now): WordPress plugin, pull API.**
A. Foundation: bootstrap, config, schema, repository, logger with buffer, hash chain, fingerprints, request context, redactor, REST authenticator and controller, CLI, status page, cron, uninstall, tooling. Listener classes exist as registered stubs.
B. Listeners: content group (posts, meta, terms, menus, media, comments, widgets/customizer, ACF) and system group (settings, plugins/themes/core, users, errors/fatal/db/deprecations/http/mail, security, config audit, integrity, health).
C. Verification: unit tests, PHPStan, WPCS, SQLite-backed integration smoke test that activates the plugin, performs changes through WP-CLI and reads them back through the signed API with `tools/fetch-events.mjs`; docs.

**Phase 2:** push beacon for `critical`, WooCommerce and multisite modules,
plugin updates served from Controlroom (`Update URI`), full content diffs
opt-in, Controlroom-side verification of the chain, site-to-site key rotation
tooling, optional object-cache backed nonce store.

## 10. Open questions for Noformat

- Controlroom egress IPs for the optional allowlist (leave empty until known).
- Which client sites should run with `events_visible_to = capability_only`
  (default `administrators`: site administrators can read the log).
- Retention defaults per client tier (currently 90 / 180 days).

## 11. Change log of this plan

- 2026-10-28: first version (Fable planning session).
- 2026-10-29: wp-admin redesigned into Content log and System log (D11 rewritten); first live test on a real site (ajovista on Local, PHP 8.4, MySQL 8): WP-CLI `--json` flag replaced by `--format=json`; Yoast `rest_cookie_invalid_nonce` noted as a false positive to tune; technical meta keys and per-request story grouping added after the test.
- 2026-10-28 (Phase A delivered): deviations accepted from the first version:
  `occurred_at` is a plain datetime plus `occurred_ms bigint` (SQLite and old
  MySQL drop fractions); extra column `object_label_hash`; hash excludes `site`
  and `producer` too; canonical JSON encodes empty objects as `[]`; `sha256_8`
  is keyed with the site salt; chain head, salt, ack cursor, last pull, schema
  version and failure counters live in `{prefix}nflog_meta` instead of options;
  sensitive-key matching has a safe-key list and filter
  `noformat_logger/safe_keys`; rate limiting applies only to signed requests
  (429), all other failures are 401; constants `NOFORMAT_LOGGER_PREVIOUS_KEY_ID`
  / `NOFORMAT_LOGGER_PREVIOUS_SECRET` allow rotation when keys are in
  wp-config; capability `noformat_logger_view_events` grants read-only access
  to the status page.

- 2026-10-28 (Phase C delivered, 1.0.0): listeners unavailable at boot are
  re-checked on `plugins_loaded` priority 0; per-action fingerprint windows
  (`probe.suspicious_404` 1 h as in §4, `rest.users_probe` / `xmlrpc.call`
  15 min); `wp_user_roles` stays critical (§5, confirmed); `wp nflog integrity`
  exits non-zero on a mismatch or chain break; Phase B event additions
  `menu.item_deleted` and `php.errors_suppressed`. The catalogue, filters,
  health shape and known limitations are in `docs/EVENTS.md`.

- 2026-10-28 (Phase D, hardening, folded into 1.0.0): independent review
  fixes. `api.auth_failed` fingerprint is (reason, ip hash[, known key id]) and
  buffered; site-wide anonymous cap (`anonymous_per_minute`, 120) with
  `system.flood`; `max_rows` prune never removes unacknowledged notice+ rows;
  fingerprint row is the single counter (hydrated on read, sampled above 100,
  object-cache lookups); windows close once events are served or acked
  (`last_served_id`); `ack` only on `GET /events`; `rest_authentication_errors`
  bypass for signed requests; no chain head on 401; non-transactional crash
  path; buffer flushes when full; value scrubbing of e-mails/IPs and new
  sensitive keys; non-viewable post titles hashed; path/route/upload-name
  masking; strict CIDR validation; `WeakMap` auth decisions; logger
  settings/key events; throttled, query-free schema check; key decrypt failure
  event; manifest `rest_prefix`. Deferred and documented: host binding of the
  signature (v2), multisite uninstall (Phase 2), concurrent first occurrences.

- 2026-10-29 (wp-admin event viewer, folded into 1.0.0): D11 revised. Tabs
  Events / Status / Settings on Tools → Noformat Logger (`?tab=`), Events is the
  default for users who may read the log. Access setting `events_visible_to`
  (`administrators` default, `capability_only`), constant
  `NOFORMAT_LOGGER_EVENTS_VISIBLE_TO`. `EventRepository::query()` / `count()`
  (prepared filters, whitelisted order, always LIMIT), render-time `Resolver`,
  JSON export (≤ 5 000, CONTRACT shape, `logger.events_exported`),
  `logger.events_viewed` (debug), dashboard widget. Measured with SAVEQUERIES:
  0 queries on the plugin tables on `edit.php` and `profile.php`, 2 on the
  dashboard with the widget (`tests/integration/smoke-admin.sh`).

- 2026-10-30 (1.0.1, privacy fix): events pulled from the test site carried a
  display name in `meta.updated` on users (Yoast per-user `wpseo_title`).
  Values on user profiles (except preference keys), comments and posts of
  non-viewable post types, including ACF fields there, are now content
  summaries whatever their key; CONTRACT §2 gained the bullet. Canary check in
  `tests/integration/smoke-privacy.sh`.

- 2026-11-02 (1.1.0): the admin UI moves from the top-level menu back under
  Tools, as one page `tools.php?page=noformat-logger` with in-page tabs
  (Content log · System log · Status · Settings); access rules unchanged, a tab
  the user may not open is hidden and answers 403; the 1.0 URLs redirect (301).
  D11 reworded. New `src/Update/`: the plugin updates like any other plugin
  from GitHub Releases of `wearenoformat/wp-noformat-logger` (`Update URI`,
  `update_plugins_github.com`, `plugins_api`, SHA-256 check of the package
  against the release's `manifest.json`), no update server of our own;
  `tools/release.sh` and `.github/workflows/release.yml` cut releases
  (`docs/UPDATES.md`).
