# Changelog

All notable changes to this plugin. Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.1.1] - 2026-11-02

### Changed

- Plugin description: "Tamper-evident, privacy-preserving log of content changes and error, security and stability signals." (the clause about the Controlroom reading it over a signed API is gone from the header and the *View details* modal; the signed API itself is unchanged).

### Fixed (noise in the Content log)

Machine-written state no longer shows up as content changes. Both rules log the write at `debug` with `context.technical: true` and `context.technical_reason`, so it is dropped unless `log_debug` is on (like the existing technical meta keys). Notice and critical options (`siteurl`, `home`, `admin_email`, `{prefix}user_roles`, `users_can_register`, `default_role`, `active_plugins`, `blogname`, `permalink_structure`, `theme_mods_*`, … and anything the `noformat_logger/option_severity` / `critical_options` filters raise above `info`) are always exempt.

- **Write-on-read rule** (`RequestContext::machineWriteReason()`): an `option.*` or `meta.*` (all object types) write during a `GET` or `HEAD` request (front-end views, admin page loads, REST GETs; reason `read_request`), during WP-Cron (`cron`) or in the admin-ajax `heartbeat` action (`heartbeat`) is machine state. A non-REST GET that carries `_wpnonce` (activate / trash links, bulk edit on `edit.php`) is still a deliberate action. POST/PUT/PATCH/DELETE, REST writes, forms and WP-CLI are unchanged; ACF grouped events are unchanged.
- **State-key patterns** (`Config::defaultTechnicalKeyPatterns()`, filter `noformat_logger/technical_key_patterns`; reason `key_pattern`), whatever the method: names containing a state word (`dismissed`, `pointer(s)`, `version_info`, `usage_analytics`, `sticky_admin_messages`, `last_checked`, `last_run`, `last_update_check`, `transient`, `batch`, `queue`, `lock`, `telemetry`, `install_date`, `activation_time`, `review_notice`) or ending in `notification(s)` / `notices` / `admin_notice`, and the prefixes `wp_yoast_`, `_yoast_`, `imagify_`, `gsmtp_`, `gform_version`, `_transient_`, `_site_transient_` plus `rg_gforms_message`. Keys ending in `_settings` / `_options` are exempt (`imagify_settings` saved from its screen stays `info`), and so are the Yoast fields an editor types (`MetaListener::MEANINGFUL_KEYS`). Covers `wp_yoast_notifications`, `wpseo_notifications`, `gsmtp_version_info`, `gform_version_info`, `gform_sticky_admin_messages`, `imagify_optimize_media_batch_*`. `rg_gforms_*` as a whole is deliberately not on the list: most of those options are Gravity Forms settings.

### Changed (errors)

- `doing_it_wrong` for `_load_textdomain_just_in_time` (raised on every request by translation-eager plugins since WordPress 6.7) is `info` instead of `warning`; still aggregated by fingerprint. Every other `doing_it_wrong` stays `warning`.

## [1.1.0] - 2026-11-02

### Added (updates)

- The plugin updates like any other plugin, from the **GitHub Releases** of `wearenoformat/wp-noformat-logger` (constant `NOFORMAT_LOGGER_UPDATE_REPO`), with no update server of our own: Dashboard → Updates and the Plugins screen show *update now*, the auto-update toggle works, `wp plugin update noformat-logger` works, and *View details* shows the release notes (`src/Update/`, `docs/UPDATES.md`).
- `Update URI: https://github.com/wearenoformat/wp-noformat-logger` (was `false`); `update_plugins_github.com` returns the latest release's update array, `plugins_api` the details modal.
- Release discovery: one `GET /repos/{repo}/releases/latest` per check, cached 6 hours in the transient `noformat_logger_update` with the ETag reused (304 keeps the cache); the release's `manifest.json` (version, requirements, changelog, `sha256`) is read only when the release changed. On 403/429/404 or network errors the cached answer is kept and a human reason recorded ("GitHub answered 404: the repository is private or the name is wrong …").
- Every package is downloaded over HTTPS by the plugin and its SHA-256 compared with the manifest; a mismatch refuses the update ("… failed the SHA-256 check …"). The unpacked folder is always renamed to `noformat-logger`. Our own update clears the cache and is logged as `plugin.updated` (by the updater itself when PluginThemeListener is off).
- Optional constants `NOFORMAT_LOGGER_UPDATE_TOKEN` (fine-grained read-only token for a private repository; it would live in every site's wp-config, a public repository or releases-only mirror is recommended) and `NOFORMAT_LOGGER_UPDATE_MANIFEST` (self-hosted manifest URL, bypasses GitHub).
- Status tab: **Updates** card (installed, latest, source, last check, result) with **Check now** for administrators; `wp nflog update-check [--cached] [--format=json]` prints the same line.
- Packaging: `.distignore`, `tools/release.sh <version>` (version / CHANGELOG / tag checks, `composer check`, zip with top folder `noformat-logger/`, `manifest.json`, tag, `gh release create`; `--local-only` for tests) and `.github/workflows/release.yml` (same on a pushed `v*` tag).
- Integration: `tests/integration/smoke-update.sh` (in `run-all.sh`) updates the test site from a locally served 9.9.9 release through WP-CLI and checks that a tampered zip is refused.

### Changed (wp-admin)

- The admin UI moves under **Tools**: one page, **Tools → Noformat Logger** (`tools.php?page=noformat-logger`), replaces the top-level menu and its four submenus; the screens are in-page tabs (**Content log** · **System log** · **Status** · **Settings**, `&tab=content|system|status|settings`) styled with the existing design system (`.nflog-tabs`).
- Access rules are unchanged (Content log per `events_visible_to`, System log per `system_log_visible_to`, Status for everyone who may open the page with key actions for `manage_options`, Settings for `manage_options`). A tab the user may not open is not rendered and a direct `?tab=` request to it answers 403; without `tab` the first allowed tab opens (an administrator under `events_visible_to = capability_only` now lands on the System log instead of a "no permission" view).
- The 1.0 URLs (`admin.php?page=noformat-logger`, `…-system`, `…-status`, `…-settings`) and the older `tools.php?page=noformat-logger&tab=events` redirect with 301 to the matching tab, filters kept.
- Every internal link (dashboard widget, the "Needs attention" strip, the Content log / System log cross-links, the console and toolbar filter forms, the Status key form, exports) points to the tabs. New *Settings* action link on the Plugins screen for administrators.

## [1.0.1] - 2026-10-30

### Fixed (privacy)

- Personal text could leave the site in `meta.updated` / `meta.added` events on user objects: user meta was only content-summarised for `description`, every other key was diffed and redacted by key pattern only, so Yoast's per-user `wpseo_title` / `wpseo_metadesc` (holding the display name), `facebook`, `twitter`, `billing_city` and any plugin's profile field were sent in clear (CONTRACT §2).
- New rule (`Listeners\Support\PersonalValues`): every user meta value is a content summary (hash plus size, never the text) unless the key is a preference in `MetaListener::USER_STRUCTURAL_KEYS` (`locale`, `rich_editing`, `syntax_highlighting`, `comment_shortcuts`, `admin_color`, `use_ssl`, `show_admin_bar_front`); non-scalar values and sensitive keys get the redaction marker, never the structure. `MetaListener::TEXT_KEYS` is removed (folded into this rule).
- Post meta of non-viewable post types (form submissions such as CFDB7, Flamingo, Gravity Forms entries stored as posts), or of posts whose type cannot be determined, is content-summarised; viewable post types keep full diffs.
- Comment meta is content-summarised.
- `acf.fields_saved` on users, comments and non-viewable post types carries content summaries per top-level field; terms, viewable post types and options pages are unchanged. Term meta is unchanged.
- Credentials inside values: every scrubbed string (changes, context, error messages) has credential query parameters rewritten to `name=[redacted]` (`signature`, `sig`, `awsaccesskeyid`, `x-amz-*`, `token`, `key`, `secret`, `auth`, `password`, `pass`, `session`, `sid`, `api_key`, `apikey`, `access_key`, `credential`; case-insensitive, also after `&amp;`), keeping the rest of the URL. Option diffs such as Gravity Forms' `gform_version_info` carried Amazon S3 pre-signed download URLs (`AWSAccessKeyId=…&Signature=…`) that key-pattern redaction cannot see (`Text::scrubQueryParams()`).
- Integration: new `tests/integration/smoke-privacy.sh` (wired into `run-all.sh`) writes a canary string into such values and asserts it never appears in events pulled over the signed API.

## [1.0.0] - unreleased

First release: foundation (Phase A), all listeners (Phase B), integration
(Phase C) and hardening (Phase D). Event names and the API are now stable (CONTRACT schema 1).

### Changed (wp-admin redesign)

- The Tools submenu is replaced by a top-level **Noformat Logger** menu (own SVG icon, after Tools) with **Content log**, **System log**, **Status** and **Settings**; `tools.php?page=noformat-logger[&tab=…]` redirects there, filters kept.
- **Content log**: an activity feed for site administrators and editors, grouped by day, one human sentence per event (`Admin\Humanizer`), avatars, a before/after view with human field labels and privacy markers, type / person / importance / period filters, a "Needs attention" strip linking to the System log.
- **System log**: a dark developer console with severity counters, a one-line filter (`EventFilters::parseQuery()`: `action:`, `cat:`, `src:`, `req:`, `fp:`, `since:` …), severity and category chips, expandable details (tinted JSON, stack, fingerprint and request filters, copy as JSON), Follow mode over the new `nflog_tail` admin AJAX endpoint and cursor-based *Load older*; one vanilla JS renderer (`assets/system-log.js`).
- Categories are split once in `Admin\Scope` (filter `noformat_logger/content_categories`); exports carry the scope.
- New setting `system_log_visible_to` (constant `NOFORMAT_LOGGER_SYSTEM_LOG_VISIBLE_TO`) so a client site can hide the developer view and keep the content log.
- Status rebuilt as cards (connection, storage with inline *Verify chain* over the last 1 000 events via `nflog_verify`, health, scheduled jobs); Settings grouped into sections with module descriptions under Content and System; the dashboard widget shows recent activity sentences and a system line.
- New design system in `assets/admin.css` (tokens, cards, buttons, chips, badges, inputs, tables, pagination, empty states, diff); no external fonts or assets.
- Repository: viewer filters `categories`, `severities`, `object_subtype`, `exclude_object_subtype`, `actor_type`, `after_id` / `before_id`; `countsBySeverity()` takes categories; new `actors()` and `requestsWithCategories()`. The API is unchanged.

### Added (Phase A: foundation)

- Bootstrap with requirement checks (PHP 8.1, WordPress 6.4) shown as an admin notice instead of a fatal.
- Config resolution (constants > `nflog_settings` > defaults) with module toggles and filters.
- `Event` value object and builder, `Severity` / `Category` / `Source` enums matching CONTRACT §1.
- `Logger` with request buffer (200), in-request and cross-request fingerprint aggregation, overflow event, shutdown flush with PHP shutdown fallback, failure counter.
- Request context: request id, source detection, actor, route, keyed IP / user-agent hashes.
- Redactor: sensitive keys (nested, serialized), truncation, dotted-path diffs, username hash, content summary.
- Storage: schema v1 (events, fingerprints, nonces, meta), chained batch insert under a row lock, cursor queries, summary, prune honouring the ack cursor, chain verification.
- Signed pull API `noformat-logger/v1`: manifest, events, event, summary, health, verify, marker.
- API keys from constants or encrypted option, rotation with 24 h grace.
- Tools → Noformat Logger status page, WP-CLI `wp nflog …`, cron jobs, uninstall.
- Optional MU loader buffering early PHP errors.
- Reference Node client (`tools/fetch-events.mjs`), shared test vectors, unit tests, SQLite integration smoke test.

### Added (Phase B: listeners)

- Content: posts and CPTs (one created/updated per post and request, status events, content as hash + length delta), post/term/user/comment meta, terms and assignments, menus, menu items (`menu.item_deleted`) and locations, media, comments, widgets and sidebars, Customizer, ACF field saves, options pages and field groups, export/import/privacy requests.
- Settings and extensions: options with severity map and critical options (`siteurl`, `home`, `admin_email`, `wp_user_roles`, `active_plugins` outside the plugins screen), plugins and themes (install/activate/update/delete/switch), core updates and auto-update results, plugin/theme file editor (attempt: warning, file changed: critical).
- Users: created/updated/deleted, folded role events (elevation to administrator is critical), super admin, application passwords, password resets.
- Security: logins and logouts (administrator from a new client is a notice), failed logins (username hashed) with a rolling burst detector, bad auth cookies, application password failures, REST user enumeration and auth errors, XML-RPC calls including `system.multicall`, suspicious 404 probes.
- Errors and stability: chained PHP error handler with a per-request cap (`php.errors_suppressed`), fatals via WordPress' fatal handler and a shutdown fallback, uncaught exceptions, `db.error` with literals stripped, deprecations and `_doing_it_wrong`, failed outbound HTTP calls, mail failures; MU loader buffer drained into events.
- Daily config audit (`config.risky_setting` per state change), weekly core and plugin checksum integrity check plus local chain verification, health snapshot (`/health`, cached every 15 minutes) with cron, updates, debug flags, Site Health results.
- Integration smoke scripts per listener group.

### Added (Phase C: integration)

- Listeners whose dependency loads after this plugin (ACF, WooCommerce-style) are re-checked and registered on `plugins_loaded` priority 0 (`ListenerRegistry`); available listeners still register at boot so the error handlers stay early.
- Per-action aggregation windows: setting `fingerprint_windows` (defaults `probe.suspicious_404` 3600 s, `rest.users_probe` and `xmlrpc.call` 900 s); `noformat_logger/fingerprint_window` now receives `(int $seconds, Severity $severity, string $action)`.
- `EventRepository::countsBySeverity()`; `/health` `errors_24h` uses it (one grouped query).
- `wp nflog integrity` exits non-zero on a core/plugin mismatch or chain break (`--no-fail` to only print); `wp nflog health` prints the full snapshot.
- `tests/integration/run-all.sh`: one fresh site, every smoke script, per-script summary.
- Complete operator README and a single event catalogue in `docs/EVENTS.md` (filters, health shape, config audit ids, known limitations).

### Changed

- OptionListener skips ACF options page rows (`{page}_{field}`, `_{page}_{field}`) while ACF saves that page; `acf.options_saved` carries them.
- `nflog_*` is in the default ignored meta keys (AuthListener's filter workaround removed); AcfListener no longer guesses availability from `active_plugins`.
- `Db` restores `$wpdb->last_query` as well as `last_error` after the logger's own queries, so `db.error` reports the site's failing query.
- Uninstall (with "delete data on uninstall") also removes `nflog_known_clients` user meta and the failed-login burst transients.

### Added (wp-admin event viewer)

- Tools → Noformat Logger is split into tabs: **Events** (default for users who may read the log), **Status** (unchanged content and key management) and **Settings** (settings and module toggles); `?page=noformat-logger&tab=…`.
- Events tab on `WP_List_Table`: severity badges, site-time and relative times (UTC on hover), objects and actors resolved to titles/display names at render time (permission-aware, never stored), most crucial first (severity, then newest), sortable by time/severity/category/action/count, `<details>` row with changes (redaction markers readable), context JSON, request id and fingerprint filter links, short hash/prev_hash, site id.
- Filters validated against the enums and patterns: severity (minimum or exact), category, action prefix, source, actor id, object type + id, request id, fingerprint, date range in site time, free-text search on summary/action (`esc_like`); quick links *Critical and errors only* and *Last 24 h*; summary strip with counts per severity and the top 5 fingerprints; per-page screen option (50, max 200).
- *Download JSON* export of the current filter (max 5 000 events, CONTRACT §1 shape), nonce- and capability-checked, logged as `logger.events_exported`; `logger.events_viewed` (debug) when `log_debug` is on.
- Dashboard widget: last 24 h per severity linking into the viewer and the 5 latest critical/error summaries.
- Setting `events_visible_to` (`administrators` default, `capability_only`) and constant `NOFORMAT_LOGGER_EVENTS_VISIBLE_TO`; unknown values fail closed.
- `EventRepository::query()` and `count()` for the viewer (prepared clauses, whitelisted ORDER BY, always bounded).
- `tests/integration/smoke-admin.sh` (in `run-all.sh`), unit tests for filters, access, query builder and resolver.

### Security and performance hardening (Phase D)

- Unauthenticated flood protection: `api.auth_failed` is aggregated per reason and IP hash (the key id only when the key is known) and buffered instead of written synchronously; anonymous security events may create at most `anonymous_per_minute` (120) new rows per minute site-wide, the excess is folded into one `system.flood` event; the `max_rows` prune rule never removes unacknowledged notice-or-higher rows before `hard_max_days`.
- Recurring fingerprints cost at most 1 SELECT + 1 UPDATE per request (no SELECT with a persistent object cache); the count lives on the fingerprint row and is hydrated on read; writes are sampled 1:10 above 100 occurrences; client hashes are computed only for stored events.
- Events already served by `GET /events` (`last_served_id`) or acknowledged are never bumped again: later occurrences start a new event.
- `ack` is accepted on `GET /events` only and processed after the response is built.
- Signed requests survive security plugins that reject REST requests in `rest_authentication_errors`.
- 401 responses carry no `X-NF-Chain-Head` (and cost no query).
- Fatal/exception path writes without a transaction on the shared connection.
- A full event buffer is written instead of dropped (long imports, CLI); `MetaListener` tracking is capped (500, FIFO) and cleared when an update turns into an add.
- Privacy: e-mail and IP addresses scrubbed from every logged string; new sensitive keys `ip`, `*_ip`, `remote_ip`, `comment_author*`, `adminhash`; titles/slugs of non-viewable post types hashed; outbound HTTP path tokens, upload file names in error messages and author slugs in routes replaced by `{hash8}`.
- IP allowlist: malformed CIDR entries rejected on save and skipped at runtime; authentication decisions cached per request object (`WeakMap`).
- New events `logger.settings_changed`, `logger.key_generated`, `logger.key_rotated`, `logger.key_revoked`, `logger.key_unreadable`, `system.flood`.
- Schema check on admin pages costs no query (autoloaded `nflog_schema_cache`); install attempts are throttled to one per 10 minutes.
- Array-valued API parameters answer 400; the manifest reports `rest_prefix` and `last_served_id`; `tools/fetch-events.mjs --rest-prefix`.
