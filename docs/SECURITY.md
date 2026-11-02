# Security model

## Assets

1. The event log (content changes, error and security signals). Integrity matters
   more than confidentiality, but it must never contain personal data.
2. The API secret, which lets its holder read the log.
3. The site itself: the logger must never break or slow it down.

## Threats and mitigations

| Threat | Mitigation |
| --- | --- |
| Someone reads the log through the REST API | Every route requires an HMAC-SHA256 signature over method, path, canonical query, timestamp, nonce and body hash. Cookie and Application Password authentication are ignored for the namespace. The namespace is hidden from the REST index, its namespace index answers 401, and parameters are validated only after authentication. |
| Replay of a captured request | Timestamp skew ≤ 300 s and single-use nonces (stored as `sha256(key_id:nonce)` for 600 s). |
| Brute force / key guessing | 128-bit+ secrets (32 random bytes), constant-time comparison, unknown keys and bad signatures look identical, every rejection is logged as `api.auth_failed` (aggregated per reason and IP hash; the key id is part of the fingerprint only when it is a key the site knows), 120 requests/min per key. Optional IP allowlist (exact or CIDR; malformed entries are rejected on save and skipped at runtime, never treated as `/0`). |
| Secret leaks through a database dump | Recommended storage is `wp-config.php` constants. The database fallback stores libsodium `secretbox` ciphertext under `sodium_crypto_generichash(wp_salt('auth'))`, so a dump without `wp-config.php` does not reveal it. The secret is shown once on creation and never again; the option is not autoloaded. |
| Key compromise | `wp nflog key rotate` (the previous key stays valid 24 h, `revoke` ends that immediately) or replace the constants (`NOFORMAT_LOGGER_PREVIOUS_*` for a planned switch). |
| Transport interception | HTTPS required; plain HTTP only with `NOFORMAT_LOGGER_ALLOW_HTTP` for local development. Responses carry `Cache-Control: no-store`. |
| Someone edits or deletes log rows in the database | Hash chain (`prev_hash`, `hash`) computed in PHP inside a transaction that locks the chain-head row; the head is in the manifest and every response header. Edits, deletions in the middle and truncation of the newest rows all break verification (`/verify`, `wp nflog verify`, collector-side check). Pruning only removes an id prefix and records an anchor. An attacker with full database and code access can rebuild a chain; the collector's copy (pulled continuously, acknowledged with `ack`) is the reference. |
| Log flooding (one bad plugin, attacker spamming failures) | Fingerprint aggregation (60 s window; 1 h for debug and suspicious 404 probes, 15 min for REST user and XML-RPC probes) with a fixed write budget (1 SELECT + 1 UPDATE per request for a recurring fingerprint, sampled above 100 occurrences). Unauthenticated, attacker-controlled events (`api.auth_failed`, `auth.login_failed`, probes, XML-RPC calls, bad cookies, REST auth errors) may create at most 120 new rows per minute site-wide; the excess becomes one `system.flood` event. The `max_rows` prune rule removes acknowledged rows and leading debug/info rows only, never an unacknowledged notice-or-higher row before `hard_max_days`, so a flood cannot evict unpulled events. Bounded context (64 KB) and strings (2 KB). |
| The logger breaks the site | Every logger, listener and storage path is wrapped; exceptions increment a failure counter (meta table, falling back to an option) and are never shown. A missing table disables storage for the request. No frontend assets, no outbound calls on page loads, writes batched on shutdown. The bootstrap shows an admin notice instead of a fatal on old PHP/WordPress. A development `vendor/` is never loaded into WordPress. |
| Unserialize gadgets in logged data | Serialized values are only unserialized with `allowed_classes => false`. |
| A security plugin blocks "unauthenticated" REST requests | `rest_authentication_errors` at `PHP_INT_MAX` clears the error only for requests to `noformat-logger/v1` that carry all four `X-NF-*` headers; the HMAC permission callback still decides. Other namespaces keep the security plugin's verdict. |
| The logger commits a crashed request's open transaction | Writes on the fatal/uncaught-exception path use no transaction and no row lock (`insertBatch( …, false )`); a chain fork from a concurrent write at that instant is accepted and detected by `/verify`. |
| 401 responses as an oracle or a load amplifier | Unauthenticated responses carry no `X-NF-Chain-Head` and cost no chain-head query; the rejection is buffered and written once at shutdown. |

## What is never stored or transmitted

- Names, emails, usernames, phone numbers, addresses, raw IP addresses, user agents.
  Actors and objects are referenced by ID; IPs and user agents only as
  `HMAC-SHA256(site salt, value)` truncated to 32 hex characters. The site salt is
  random per site, generated at install, independent of the API secret.
- Values under sensitive keys (`email`, `mail`, `phone`, `tel`, `address`,
  `password`, `pass`, `secret`, `token`, `key`, `auth`, `salt`, `iban`, `card`,
  `ssn`, `dob`, `birth`, `name`, `login`, `billing`, `shipping`, `ip`, `*_ip`,
  `remote_ip`, `comment_author*`, `adminhash`, … see
  `Config::defaultSensitiveKeys()`): replaced by `{"redacted": true, "sha256_8": …}`
  where `sha256_8` is a salted HMAC prefix, so a change is visible but the value is
  not, even for short values that could otherwise be dictionary-reversed. Keys
  are matched case-insensitively as substrings, at every nesting level, including
  inside serialized arrays. The matching errs on the side of over-redaction.
- E-mail and IP addresses under any other key: every logged string is
  scrubbed (`[email]`, `[ip]`) after key redaction.
- Titles and slugs of non-public post types (form entries, CRM contacts):
  redaction markers; public post types keep their (public) titles.
- Upload file names in error messages, author slugs in routes, and tokens or
  e-mails in outbound HTTP paths: replaced by `{hash8}`.
- Long content: only a hash and the length delta (full diffs are opt-in, Phase 2).
- Attempted usernames of failed logins: only a salted hash.
- Comment author fields.
- Files, source code, SQL literal values.

## Operational notes

- Events are readable in wp-admin (Tools → Noformat Logger → Content log / System log,
  dashboard widget, JSON export, the System log live tail) by administrators (`manage_options`) and by users with
  the `noformat_logger_view_events` capability, which is assigned to no role.
  On client sites set `events_visible_to` to `capability_only`
  (`define( 'NOFORMAT_LOGGER_EVENTS_VISIBLE_TO', 'capability_only' );`) so only
  explicitly granted users see the log; `system_log_visible_to` does the same
  for the developer view alone. The viewer resolves IDs to post titles
  and user display names at render time (names only with `list_users`); nothing
  resolved is stored. Exports are nonce-protected and logged as
  `logger.events_exported` (filter names only).
- `api.auth_failed` events record the failure reason, the (public) key id if well
  formed and the IP hash; never the signature or nonce.
- A cloned staging site with the same constants shares the key on purpose only if
  the constants are copied; give each environment its own key.
- The plugin's own configuration is audited: `logger.settings_changed`
  (setting names only), `logger.key_generated` / `key_rotated` /
  `key_revoked` (key ids only), `logger.key_unreadable` (once per hour).
- A database key is encrypted under `wp_salt('auth')`: **rotating the
  WordPress salts makes a stored key unreadable** (`logger.key_unreadable`,
  every request then gets 401). Prefer the wp-config constants.

## Known limitations (v2 considerations)

- **The signature does not bind the host.** The string to sign covers method,
  path, canonical query, timestamp, nonce and body hash. Because keys are per
  site, the impact is limited to cloned sites that share `wp-config.php`
  constants: a captured request could be replayed against the clone within
  the 300 s skew window (nonces are stored per database). v2 may add the host
  (or a site id) to the string to sign; until then give every environment its
  own key.
- **Multisite uninstall** removes data of the current site only (Phase 2).
- **Crash-path writes** skip the transaction (see above).
- **Value scrubbing** only recognises e-mail and IP address patterns.
