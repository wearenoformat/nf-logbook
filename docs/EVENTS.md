# Event catalogue

Every action the plugin emits, with its category, severity, object, payload
and aggregation rule. Action names are dotted, lower case, at most 64
characters, and form the contract with the Controlroom: they are not renamed
after release. Severities follow PLAN §4 and §5; the event shape is
CONTRACT §1.

Contents: [conventions](#conventions) · [catalogue](#catalogue) ·
[logger](#logger-itself) · [content](#content-and-structure) ·
[settings, extensions, users](#settings-extensions-users) ·
[authentication and probing](#authentication-and-probing) ·
[errors and stability](#errors-and-stability) ·
[config audit](#config-audit-findings) · [integrity](#integrity-result) ·
[health snapshot](#health-snapshot) · [aggregation](#aggregation-windows) ·
[filters](#filters) · [meta keys](#internal-storage-keys) ·
[known limitations](#known-limitations-and-deferred-work)

## Conventions

- **Object** is written `type / id` with the subtype in parentheses. IDs are
  strings in the JSON (`"12"`, option names, plugin slugs).
- **Summaries** contain ids, types, slugs, option names, role names and field
  names only: never titles, names, emails, usernames, file names, paths or IPs.
- **Redaction** (CONTRACT §2): any change field or context key that matches the
  sensitive key list becomes `{"redacted": true, "sha256_8": "…"}` (keyed with
  the site salt) so a change is visible but the value is not.
- **`label_hash`** is the salted 8-character hash (`Redactor::hash8()`) of the
  object's human label (post title, term name, menu name, attachment title).
  It is always a hash. Post titles and slugs appear in clear in `changes` only
  for publicly viewable post types (`is_post_type_viewable()`); for private
  types (form submissions, CRM contacts such as Flamingo, whose titles are
  e-mail addresses) both sides are redaction markers.
- **Value scrubbing**: every string that is not redacted has e-mail
  addresses replaced by `[email]` and IPv4/IPv6 addresses by `[ip]`, whatever
  its key. Routes have the author slug masked (`/author/{hash8}/`); upload file
  names in error messages become `wp-content/uploads/…/{hash8}.ext`; outbound
  HTTP paths have token-like segments replaced by `{hash8}`. Since 1.0.1,
  credential query parameters inside any such string (`signature`, `sig`,
  `awsaccesskeyid`, `x-amz-*`, `token`, `key`, `secret`, `auth`, `password`,
  `pass`, `session`, `sid`, `api_key`, `apikey`, `access_key`, `credential`,
  case-insensitive) become `name=[redacted]` and the rest of the URL is kept
  (`?AWSAccessKeyId=[redacted]&Expires=1700000000&Signature=[redacted]`), so
  pre-signed download URLs in option values such as Gravity Forms'
  `gform_version_info` do not leave the site.
- **Personal values** (since 1.0.1): values stored on user profiles, on
  comments and on posts of non-viewable post types (`is_post_type_viewable()`
  false or the type unknown: form submissions, CRM contacts) are personal by
  default, whatever their key: they are reported as a content summary (hash
  plus size, never the text); non-scalar values and values of sensitive keys
  as a redaction marker, never the structure. User preference keys
  (`locale`, `rich_editing`, `syntax_highlighting`, `comment_shortcuts`,
  `admin_color`, `use_ssl`, `show_admin_bar_front`;
  `MetaListener::USER_STRUCTURAL_KEYS`) are diffed in clear, as are term meta,
  meta of viewable post types and ACF options pages. The key-pattern list
  alone cannot catch keys such as Yoast's per-user `wpseo_title` (which holds
  the display name), `facebook`, or a form plugin's `your-message`.
- **Content summary**: long text (post content, excerpts, descriptions,
  comment text, widget markup) is `{"sha256_8", "length"}` for the old value and
  `{"sha256_8", "length", "length_delta", "changed"}` for the new value;
  full text diffs only with the `full_content_diff` setting.
- **Usernames** in failed logins are `Redactor::hashUsername()` (32 hex), never
  the username. IPs and user agents only appear as `request.ip_hash` /
  `request.ua_hash` (and `context.ip_hash` where noted).
- **Paths** in error events are relative to the WordPress root
  (`wp-content/plugins/<slug>/…`); see [errors](#errors-and-stability).
- **Fingerprint**: events with a fingerprint aggregate into `count` on the
  first stored event while the fingerprint's window is open (60 s by default,
  see [aggregation windows](#aggregation-windows)); in one request, repeats are
  merged in memory before they are written. "—" means not fingerprinted.
- Every hook body runs inside `Logger::guard()`: a failing listener counts an
  internal failure (manifest `failures`) and never throws or prints.
- `debug` events are dropped unless the `log_debug` setting is on.

## Catalogue

In wp-admin the categories are split by `Admin\Scope`: the **Content log**
shows content, media, taxonomy, menu, fields, comment, user, settings, theme,
plugin, core, commerce and privacy (as sentences); the **System log** shows
error, stability, security, http, mail, cron, deploy, build and system (as a
console). The split is display only (filter `noformat_logger/content_categories`).

| Action | Category | Severity | Object | Fingerprint | Listener |
| --- | --- | --- | --- | --- | --- |
| `logger.activated` | system | notice | `plugin / noformat-logger` | — | Plugin |
| `logger.deactivated` | system | warning | `plugin / noformat-logger` | — | Plugin |
| `logger.test_event` | system | per `--severity` (info) | — | — | CLI |
| `logger.pruned` | system | info | — | — | Scheduler |
| `logger.settings_changed` | system | notice | `plugin / noformat-logger` | — | Plugin (status page, CLI, any update of `nflog_settings`) |
| `logger.key_generated` | security | notice | `api_key / <key id>` | — | Status page, CLI |
| `logger.key_rotated` | security | notice | `api_key / <new key id>` | — | Status page, CLI |
| `logger.key_revoked` | security | notice | `api_key / <revoked key id>` | — | Status page, CLI |
| `logger.key_unreadable` | security | error | `api_key / <key id>` | — (at most once per hour) | KeyStore via Plugin |
| `logger.events_exported` | system | notice | `plugin / noformat-logger` | — | wp-admin Content log / System log (Export JSON) |
| `logger.events_viewed` | system | debug (dropped unless `log_debug`) | `plugin / noformat-logger` | — | wp-admin Content log / System log |
| `system.buffer_overflow` | system | warning | — | — | Logger |
| `system.flood` | security | warning | — | `system.flood` (60 s window) | Logger |
| `api.auth_failed` | security | warning (rate limited: notice) | `api_key / <key id>` when the key is known, else `api_key / null` | reason + ip hash (+ key id when known) | Authenticator |
| `deploy.marker` | deploy | notice | — | — | CLI, `POST /marker` |
| `post.created` | content | info | `post / id` (post type) | — | PostListener |
| `post.updated` | content | info | `post / id` (post type) | — | PostListener |
| `post.status_changed` | content | info | `post / id` (post type) | — | PostListener |
| `post.published` | content | notice | `post / id` (post type) | — | PostListener |
| `post.unpublished` | content | notice | `post / id` (post type) | — | PostListener |
| `post.trashed` | content | notice | `post / id` (post type) | — | PostListener |
| `post.restored` | content | notice | `post / id` (post type) | — | PostListener |
| `post.deleted` | content | notice | `post / id` (post type) | — | PostListener |
| `post.revision_restored` | content | info | `post / id` (post type) | — | PostListener |
| `meta.added` | fields | info (internal `_` keys and technical keys: debug) | `post`/`term`/`user`/`comment` `/ id` (meta key) | — | MetaListener |
| `meta.updated` | fields | info (internal `_` keys and technical keys: debug) | as above | — | MetaListener |
| `meta.deleted` | fields | info (internal `_` keys and technical keys: debug) | as above | — | MetaListener |
| `term.created` | taxonomy | info | `term / id` (taxonomy) | — | TermListener |
| `term.updated` | taxonomy | info | `term / id` (taxonomy) | — | TermListener |
| `term.deleted` | taxonomy | info | `term / id` (taxonomy) | — | TermListener |
| `term.assigned` | taxonomy | info | object the terms belong to (taxonomy) | — | TermListener |
| `menu.created` | menu | notice | `menu / term id` | — | MenuListener |
| `menu.updated` | menu | notice | `menu / term id` | — | MenuListener |
| `menu.deleted` | menu | notice | `menu / term id` | — | MenuListener |
| `menu.item_updated` | menu | notice | `menu_item / id` (item type) | — | MenuListener |
| `menu.item_deleted` | menu | notice | `menu_item / id` | menu + request | MenuListener |
| `menu.locations_changed` | menu | notice | `theme / stylesheet` | — | MenuListener |
| `media.uploaded` | media | info | `attachment / id` (mime) | — | MediaListener |
| `media.updated` | media | info | `attachment / id` (mime) | — | MediaListener |
| `media.metadata_updated` | media | info | `attachment / id` (mime) | attachment | MediaListener |
| `media.deleted` | media | notice | `attachment / id` (mime) | — | MediaListener |
| `comment.created` | comment | info | `comment / id` (comment type) | new spam: post | CommentListener |
| `comment.updated` | comment | info | `comment / id` (comment type) | — | CommentListener |
| `comment.status_changed` | comment | info | `comment / id` (comment type) | — | CommentListener |
| `comment.spammed` | comment | info | `comment / id` (comment type) | — | CommentListener |
| `comment.deleted` | comment | info | `comment / id` (comment type) | — | CommentListener |
| `widget.updated` | theme | notice | `widget / {id_base}-{n}` (id base) | — | WidgetListener |
| `sidebar.updated` | theme | notice | `sidebar / sidebar id` | — | WidgetListener |
| `customizer.saved` | theme | notice | `customizer / setting id` (setting id) | — | CustomizerListener |
| `acf.fields_saved` | fields | info | `post`/`term`/`user`/`comment`/`widget`/`block` `/ id` | — | AcfListener |
| `acf.options_saved` | fields | notice | `options / page id` | — | AcfListener |
| `acf.field_group_changed` | fields | notice | `post / id` (`acf-field-group`) | group + operation + request | AcfListener |
| `privacy.export_started` | privacy | notice | — | — | PrivacyListener |
| `privacy.import_started` | privacy | notice | — | — | PrivacyListener |
| `privacy.import_finished` | privacy | notice | — | — | PrivacyListener |
| `privacy.personal_data_exported` | privacy | notice | `user / id` or `user_request / id` | — | PrivacyListener |
| `privacy.personal_data_erased` | privacy | notice | `user / id` or `user_request / id` | — | PrivacyListener |
| `option.added` | settings | info / notice / critical (severity map) | `option / name` | — | OptionListener |
| `option.updated` | settings | info / notice / critical (severity map) | `option / name` | — | OptionListener |
| `option.deleted` | settings | info / notice / critical (severity map) | `option / name` | — | OptionListener |
| `plugin.installed` | plugin | notice (failed: error) | `plugin / slug` | — | PluginThemeListener |
| `plugin.activated` | plugin | notice | `plugin / slug` | — | PluginThemeListener |
| `plugin.deactivated` | plugin | notice | `plugin / slug` | — | PluginThemeListener |
| `plugin.updated` | plugin | notice (failed: error) | `plugin / slug` | — | PluginThemeListener |
| `plugin.deleted` | plugin | notice (failed: error) | `plugin / slug` | — | PluginThemeListener |
| `theme.installed` | theme | notice (failed: error) | `theme / stylesheet` | — | PluginThemeListener |
| `theme.switched` | theme | notice | `theme / stylesheet` | — | PluginThemeListener |
| `theme.updated` | theme | notice (failed: error) | `theme / stylesheet` | — | PluginThemeListener |
| `theme.deleted` | theme | notice (failed: error) | `theme / stylesheet` | — | PluginThemeListener |
| `editor.file_edited` | security | warning when the file is unchanged (attempt); critical when the file changed | `plugin / slug` or `theme / stylesheet` | — | PluginThemeListener |
| `core.updated` | core | notice | `core / wordpress` | — | CoreListener |
| `core.auto_updates_completed` | core | notice (any failure: error) | `core / wordpress` | — | CoreListener |
| `user.created` | user | notice (privileged role: critical) | `user / id` (role) | — | UserListener |
| `user.updated` | user | notice | `user / id` (role) | — | UserListener |
| `user.role_changed` | user | notice (new privileged role: critical) | `user / id` (new role) | — | UserListener |
| `user.role_added` | user | notice (privileged role: critical) | `user / id` (role) | — | UserListener |
| `user.role_removed` | user | notice | `user / id` (role) | — | UserListener |
| `user.deleted` | user | notice | `user / id` (role) | — | UserListener |
| `user.super_admin_granted` | user | critical | `user / id` | — | UserListener |
| `user.super_admin_revoked` | user | notice | `user / id` | — | UserListener |
| `user.app_password_created` | security | notice | `user / id` | — | UserListener |
| `user.app_password_deleted` | security | notice | `user / id` | — | UserListener |
| `user.password_reset` | user | notice | `user / id` | — | UserListener |
| `user.password_reset_requested` | user | notice | `user / id` | — | UserListener |
| `auth.login_failed` | security | warning | `user / id` or `user / null` | username hash + ip hash | AuthListener, XmlRpcListener |
| `auth.login_burst` | security | error | — | ip hash | AuthListener, XmlRpcListener |
| `auth.login` | security | info (privileged user from a new client: notice) | `user / id` (role) | — | AuthListener |
| `auth.logout` | security | info | `user / id` | — | AuthListener |
| `auth.cookie_invalid` | security | warning | `user / id` when resolvable | kind + ip hash | AuthListener |
| `auth.app_password_failed` | security | warning | `user / id` when resolvable | reason + username hash + ip hash | AuthListener |
| `rest.users_probe` | security | warning | `route / normalised route` | ip hash (900 s window) | RestProbeListener |
| `rest.auth_error` | security | warning | `route / normalised route` | code + route + ip hash | RestProbeListener |
| `xmlrpc.call` | security | warning | `method / name` | method + ip hash (900 s window) | XmlRpcListener |
| `probe.suspicious_404` | security | warning | `probe / pattern name` | pattern + ip hash (3600 s window) | ProbeListener |
| `php.notice` | error | warning | `file / relative path` | type + file + line + normalised message | ErrorHandler |
| `php.deprecated` | error | warning | `file / relative path` | as above | ErrorHandler |
| `php.warning` | error | error | `file / relative path` | as above | ErrorHandler |
| `php.error` | error | error | `file / relative path` | as above | ErrorHandler |
| `php.errors_suppressed` | error | warning | — | — | ErrorHandler |
| `php.fatal` | stability | critical | `file / relative path` | action + type/class + file + line + normalised message | FatalHandler |
| `php.exception` | stability | critical | `file / relative path` | as above | FatalHandler |
| `db.error` | stability | critical | `database` | normalised query + error | DbErrorListener |
| `deprecated.function` | error | warning | `function / name` | action + name | DeprecationListener |
| `deprecated.argument` | error | warning | `function / name` | action + name | DeprecationListener |
| `deprecated.hook` | error | warning | `hook / name` | action + name | DeprecationListener |
| `deprecated.file` | error | warning | `file / relative path` | action + name | DeprecationListener |
| `doing_it_wrong` | error | warning | `function / name` | action + name | DeprecationListener |
| `http.request_failed` | http | error | `host / host` | host + path + status or error code | HttpApiListener |
| `mail.failed` | mail | error | — | error code + PHPMailer code | MailListener |
| `config.risky_setting` | security | per finding (warning / error); resolved: info | `config / finding id` | — (one event per state change) | ConfigAudit |
| `integrity.mismatch` | security | critical; resolved: info | `core / wordpress` or `plugin / slug` | — (one event per state change) | IntegrityCheck |
| `chain.break_detected` | security | critical | `event / break id` | break id (once per break) | IntegrityCheck |
| `cron.overdue` | cron | warning | `cron_hook / hook` | hook | HealthReport |
| `updates.pending` | core | notice (pending ≥ 7 days: warning) | — | — (one event per change) | HealthReport |

Always critical (PLAN §5): `siteurl` / `home` / `admin_email` changed, a new
user with a privileged role, elevation to a privileged role, super admin
granted, **`wp_user_roles` changed** (kept critical by decision; sites where
plugins add roles or capabilities on activation see a critical event for that,
lower it with `noformat_logger/critical_options`), `active_plugins` changed
outside the plugins screen and WP-CLI, `default_role` set to `administrator`,
a plugin or theme file changed through the editor, fatal errors, uncaught
exceptions, database errors, core/plugin integrity mismatch, hash chain break.

## Logger itself

| Action | Changes / context |
| --- | --- |
| `logger.activated` | `schema` |
| `logger.deactivated` | |
| `logger.test_event` | `php` |
| `logger.pruned` | `boundary_id`, `rows`, `rules` |
| `logger.settings_changed` | `settings`: names of the changed settings only, never values (`log_debug`, `ip_allowlist`, `modules.MailListener`, …) |
| `logger.key_generated` / `logger.key_rotated` / `logger.key_revoked` | `key_id`; rotate: `previous_key_id`; `revoke --all`: `all: true` and `previous_key_id` (the other revoked key). Never secrets. `key generate --print-config` with constants stores nothing and logs nothing |
| `logger.events_exported` | `count` (events written, ≤ 5 000), `truncated` (more matched the filter), `scope` (`content` or `system`: the wp-admin screen, see `Admin\Scope`), `filters` (names of the filters in use, e.g. `["severity", "severity_mode", "from"]`; never their values), `format` (`json`). Actor: the user who downloaded |
| `logger.events_viewed` | `filters` (names only), `scope`. Reading the log is not logged by default: this is a `debug` event, dropped before buffering unless `log_debug` is on |
| `logger.key_unreadable` | `key_id`: a request named the stored (database) key but its secret cannot be decrypted, normally because the WordPress salts were rotated; generate a new key or move it to wp-config constants |
| `system.buffer_overflow` | `dropped`, `buffer_max`. Only when storage failed in this request: a full buffer (`buffer_max`, default 200) is written immediately and logging continues, so long imports and CLI runs keep every event |
| `system.flood` | `dropped` (occurrences folded into this event by the first request of the window), `per_minute` (the cap), `top_actions` (`[{action, count}]`, at most 5). `count` is the total folded in the window. See [flood cap](#anonymous-flood-cap) |
| `api.auth_failed` | `reason` (`missing_headers`, `bad_format`, `https_required`, `ip_not_allowed`, `skew`, `unknown_key`, `bad_signature`, `nonce_reused`, `rate_limited`), `endpoint`, `key_id` (only when it is a key this site knows; null for unknown or malformed key ids, which are never stored) |
| `deploy.marker` | `label`, `ref`; actor `cli` (WP-CLI) or `app` (signed `POST /marker`) |

## Content and structure

### Posts (PostListener)

Saves are collected during the request and emitted on `shutdown` (priority 1,
before the Logger flushes at 1000): one request produces at most one
`post.created` or `post.updated` per post, however often `wp_insert_post()`
ran (REST save plus meta-box save, `save_post` callbacks that save again).
The status events of that post follow it, in order. More than 50 posts pending
in one request (imports, long CLI runs) are flushed early.

Skipped post types: `revision`, `nav_menu_item` (MenuListener),
`acf-field-group`, `acf-field` (AcfListener), `customize_changeset`,
`oembed_cache`, `user_request`, `attachment` (MediaListener; attachments are
still logged by PostListener when trashed/restored with `MEDIA_TRASH`).
Logged with their type as subtype: `page`, custom post types, `wp_block`,
`wp_template`, `wp_template_part`, `wp_global_styles`, `wp_navigation`.
Autosaves (`DOING_AUTOSAVE`) and `auto-draft` inserts are skipped; the first
real save of an auto-draft is `post.created`.

| Action | Severity | Changes | Context |
| --- | --- | --- | --- |
| `post.created` | info | none | `status`, `author_id`, `parent_id`, `content` (content summary) |
| `post.updated` | info | `title`, `slug` (in clear for publicly viewable post types, redaction markers otherwise), `author_id`, `parent_id`, `menu_order`, `date`, `comment_status`, `ping_status`, `post_type`, `password` (always redacted), `content` and `excerpt` (content summaries, full text only with `full_content_diff`). Status is excluded; it has its own events. No event when nothing changed. | `baseline_missing: true` when no pre-save state was available |
| `post.published` | notice | `status` old → `publish` | |
| `post.unpublished` | notice | `status` `publish` → draft/pending/private/future | |
| `post.status_changed` | info | `status` (other transitions, e.g. draft → pending, draft → future) | |
| `post.trashed` | notice | | `previous_status` |
| `post.restored` | notice | | `restored_status` (untrash) |
| `post.deleted` | notice | | Logged at `deleted_post` for posts seen at `before_delete_post` |
| `post.revision_restored` | info | | `revision_id` (the restore also produces a `post.updated`) |

Transitions into or out of `trash` produce no `post.updated` (the slug gets a
`__trashed` suffix); `post.trashed` / `post.restored` cover them.

### Meta (MetaListener)

Post, term, user and comment meta. Object `type` is the meta type, `id` the
object id, `subtype` the meta key. For term meta and meta of viewable post
types, changes come from `Redactor::diff( old, new, meta_key )`: serialized
values are unserialized and diffed per key with dotted field names
(`settings.color`). For user meta (except the preference keys in
`USER_STRUCTURAL_KEYS`), comment meta and meta of non-viewable post types,
the one change `{meta_key}` carries content summaries (`{sha256_8, length}`
old, `{sha256_8, length, length_delta, changed}` new) or, for arrays and
sensitive keys, redaction markers ([personal values](#conventions)).

| Action | Severity | Changes | Context |
| --- | --- | --- | --- |
| `meta.added` | info; debug for internal and technical keys | `{meta_key}` null → value; no event for an empty value (`''`) | `meta_key`, `post_type` (post meta), `technical: true` for technical keys |
| `meta.updated` | as above | per-key diff old → new; no event when equal | `meta_key`, `post_type`, `old_unknown: true` when no old value was captured |
| `meta.deleted` | as above | old → null | `meta_key`, `post_type`, `rows`, `all_objects: true` for `delete_metadata( ..., $delete_all = true )` |

- Old values are read in the `update_{type}_metadata` / `update_{type}_metadata_by_mid`
  pre filters (priority 1, observe-only: they return their input) and in
  `delete_{type}_meta`.
- Internal keys (leading `_`) are `debug` unless meaningful: `_wp_page_template`,
  `_thumbnail_id`, `_wp_attachment_image_alt` and the Yoast SEO fields an
  editor fills in (`_yoast_wpseo_title`, `_metadesc`, `_focuskw`, `_canonical`,
  `_bctitle`, `_meta-robots-noindex` / `-nofollow`, `_opengraph-*` and
  `_twitter-*` title and description, `_is_cornerstone`)
  are `info` (filter `noformat_logger/meta_listener_meaningful_keys`).
- Technical keys (`MetaListener::TECHNICAL_KEYS`, filter
  `noformat_logger/technical_meta_keys`, exact names or `*` globs) are
  machine-written, never content: `aiowps_*`, `*_last_login*`,
  `show_welcome_panel`, `default_password_nag`, `_imagify_*`, `imagify_*`,
  `wpe_*`, `_wpe_*`, `rank_math_internal_links_processed`,
  `_yoast_wpseo_estimated-reading-time-minutes`,
  `_yoast_wpseo_wordproof_timestamp`, the user preferences
  `wpseo_keyword_analysis_disable`, `wpseo_content_analysis_disable`,
  `wpseo_inclusive_language_analysis_disable`, `screen_layout_*`,
  `edit_*_per_page`, `managenav-menuscolumnshidden`. They are `debug` with
  `context.technical: true`, so they are dropped unless `log_debug` is on.
  Pure noise (`_edit_lock`, `session_tokens`, `closedpostboxes_*`, `_wp_old_slug`,
  `last_update`, `dismissed_wp_pointers`, …) stays on the ignored list and is
  never stored.
- Never logged: `Config::isIgnoredMetaKey()` patterns; user meta ending in
  `capabilities` / `user_level` (UserListener); `_wp_attachment_metadata`,
  `_wp_attached_file` (file path; MediaListener); `_menu_item_*` (MenuListener);
  `akismet_*` (comment author data); `_wp_trash_meta_*`, `_wp_old_*`,
  `_wp_desired_post_slug`, `_customize_*`, `_edit_*`; meta of skipped post
  types (revisions, menu items, changesets, oEmbed cache, user requests, ACF
  field groups/fields).
- Meta clean-up while its object is deleted (post, attachment, user, comment,
  term) is not logged; the object's own delete event covers it.
- The default meta `wp_insert_user()` writes for a new user (nickname,
  first/last name, admin colour, …) is not logged; `user.created` covers it.
  Meta written later (from `user_register` on, or on profile updates) is.
- During an ACF save of the same object (`AcfListener::isSaving()`), per-key
  meta events are suppressed in favour of one `acf.fields_saved`.
- User meta is a content summary unless the key is a preference
  (`USER_STRUCTURAL_KEYS`); `description` (biography), `wpseo_title`,
  `facebook`, `billing_*` and any plugin's profile field included.

### Terms (TermListener)

Skipped taxonomies: `nav_menu` (MenuListener), `wp_theme`,
`wp_template_part_area`, `wp_pattern_category`.

Term names are logged in clear (field / context key `term_name`) only for
`category`, `post_tag`, `post_format`, `link_category` and public taxonomies;
for any other taxonomy the name is a redaction marker, because private
taxonomies may hold personal data.

| Action | Severity | Changes | Context |
| --- | --- | --- | --- |
| `term.created` | info | | `term_taxonomy_id`, `slug`, `parent_id`, `term_name` |
| `term.updated` | info | `term_name`, `slug`, `parent_id`, `description` (content summary); no event when nothing changed | |
| `term.deleted` | info | | `object_count` (objects that had the term) |
| `term.assigned` | info | | `added`, `removed` (term ids), `append`, `post_type`, `id_kind` (`term_id`, or `mixed` if a term taxonomy id could not be mapped) |

`term.assigned`: object is the object the terms belong to (`post`, `link`, or
the taxonomy's object type), subtype the taxonomy. It comes from
`set_object_terms` and, for `wp_remove_object_terms()` alone, from
`deleted_term_relationships` (queued and emitted on shutdown unless
`set_object_terms` covered it, and only if the object still exists). No event
when nothing changed, while a term is being deleted (objects are reassigned;
`term.deleted` carries the count), or for revisions, menu items, changesets
and template posts.

### Menus (MenuListener)

| Action | Severity | Object | Changes | Context |
| --- | --- | --- | --- | --- |
| `menu.created` | notice | `menu` / term id | | `menu_name` |
| `menu.updated` | notice | `menu` | `menu_name` (only when the name changed) | |
| `menu.deleted` | notice | `menu` | | |
| `menu.item_updated` | notice | `menu_item` / item id, subtype item type (`custom`, `post_type`, `taxonomy`) | `type`, `object`, `object_id`, `parent_id`, `position`, `title_hash`, `url_host`, `url_hash`, `target`, `classes`, `status`; for new items only non-empty fields | `menu_id`, `created` |
| `menu.item_deleted` | notice | `menu_item` | | `menu_id`; fingerprint per menu and request |
| `menu.locations_changed` | notice | `theme` / stylesheet | `location.{name}`: old → new menu id (null = unassigned) | |

Items are snapshotted on the first `_menu_item_*` meta write of a save, so a
menu save logs only the items that changed. Custom links are logged as host
(lower-case, no path or query) plus a salted hash of the full URL. Locations
come from `updated_option` / `added_option` on `theme_mods_{stylesheet}`
(only `nav_menu_locations` is looked at; other theme mods are left to
OptionListener/CustomizerListener).

### Media (MediaListener)

File names and paths are never logged: only `file_hash` (salted hash of the
base name). Attachment titles and slugs default to the file name, so in
`media.updated` they are redaction markers. Object subtype is the MIME type.

| Action | Severity | Changes | Context |
| --- | --- | --- | --- |
| `media.uploaded` | info | | `mime`, `parent_id`, `filesize` (bytes, from the file), `file_hash`, `width`, `height`, `sizes_count` (when metadata was generated in the same request) |
| `media.updated` | info | `title`, `slug` (markers), `parent_id`, `author_id`, `caption`, `description` (content summaries); from `attachment_updated` (before/after objects) | |
| `media.metadata_updated` | info | | `width`, `height`, `sizes_count`, `filesize`; fingerprint per attachment (regenerations aggregate into `count`) |
| `media.deleted` | notice | | `parent_id` |

`media.uploaded` is emitted on shutdown so the metadata generated during the
upload request is merged into it instead of producing a separate
`media.metadata_updated`. EXIF/IPTC `image_meta` (camera, credit, copyright)
is never read.

### Comments (CommentListener)

Author name, email, URL, IP, user agent and the comment text are never copied.
Object `comment` / id, subtype comment type (`comment` when empty).
Context on every event: `post_id`, `parent_id`, `comment_type`, `status`
(`approved`, `unapproved`, `spam`, `trash`, `post-trashed`), `author_user_id`
(only for registered users).

| Action | Severity | Changes | Context |
| --- | --- | --- | --- |
| `comment.created` | info | | `content` (content summary); new spam is fingerprinted per post (floods aggregate) |
| `comment.updated` | info | `post_id`, `parent_id`, `type`, `user_id`, `date`, `karma`, `content` (content summary); status excluded | `identity_fields_changed` (names of changed author fields, never values) |
| `comment.status_changed` | info | `status` | |
| `comment.spammed` | info | `status` → `spam` | |
| `comment.deleted` | info | | |

`comment.spammed` is emitted from `transition_comment_status`, which every
spam action goes through (`spam_comment` is not hooked, to log a spam action
once). The `delete` pseudo-transition is ignored (`comment.deleted` covers it).

### Widgets (WidgetListener)

OptionListener skips `sidebars_widgets` and `widget_*`; these options are
logged here only. `Config::isIgnoredOption()` is honoured (e.g.
`widget_recent-comments`).

| Action | Severity | Object | Changes | Context |
| --- | --- | --- | --- | --- |
| `sidebar.updated` | notice | `sidebar` / sidebar id (incl. `wp_inactive_widgets`) | `widgets`: old → new id list | `added`, `removed`, `reordered` |
| `widget.updated` | notice | `widget` / `{id_base}-{n}`, subtype id base | instance diff (`Redactor::diff`); `text` and `content` (block widget markup) as content summaries | `operation`: `added`, `updated`, `removed` |

### Customizer (CustomizerListener)

| Action | Severity | Object | Changes | Context |
| --- | --- | --- | --- | --- |
| `customizer.saved` | notice | `customizer` / setting id (subtype the same) | `Redactor::diff( old, new, setting_id )`; `custom_css` as content summary | `setting_id`, `setting_type` (`theme_mod`, `option`, …), `changeset_uuid` |

Old values are read on `customize_save`, new ones on `customize_save_after`
(both fire once per changeset publish, including scheduled changesets published
by cron); one event per setting whose value changed. Settings owned by other
listeners are skipped: `nav_menu*`, `nav_menu_locations[…]`,
`nav_menus_created_posts`, `sidebars_widgets[…]`, `widget_*`. Option-type
settings (e.g. `blogname`) are also seen by OptionListener as `option.updated`
in the same request; the two events share the request id (see
[known limitations](#known-limitations-and-deferred-work)).

### ACF (AcfListener)

Available when ACF is loaded. ACF usually loads after this plugin, so the
listener is re-checked on `plugins_loaded` (priority 0) like every listener
whose dependency was missing at boot; callbacks check that the ACF functions
exist.

| Action | Severity | Object | Changes | Context |
| --- | --- | --- | --- | --- |
| `acf.fields_saved` | info | `post` (subtype post type) / `term` / `user` / `comment` / `widget` / `block`, id decoded from the ACF id (`12`, `term_3`, `user_2`, `comment_5`, legacy `{taxonomy}_{id}`) | one change per field name (dotted for repeaters/groups: `rows.0.title`), raw values (`get_fields( $id, false )`) | `acf_id`, `fields_changed` |
| `acf.options_saved` | notice | `options` / options page id (`options`, or a custom `post_id`) | as above | as above |

On users (except preference field names in `USER_STRUCTURAL_KEYS`), comments
and posts of non-viewable post types, `acf.fields_saved` carries one change
per top-level field name with content summaries (or redaction markers for
repeaters, groups and other non-scalar values) instead of the values. Terms,
viewable post types and options pages keep the dotted value diff.
| `acf.field_group_changed` | notice | `post` / group post id, subtype `acf-field-group`, label hash of the title | | `field_group` (group key), `operation` (`updated`, `duplicated`, `trashed`, `restored`, `deleted`), `fields_count`, `active`; fingerprint per group, operation and request |

Snapshot at `acf/save_post` priority 1, diff at priority 20 (ACF saves at 10);
no event when nothing changed. `AcfListener::isSaving( $type, $id )` is true
between the two; MetaListener uses it to suppress per-key meta events.
OptionListener skips the option rows of an options page being saved
(`{page}_{field}` and `_{page}_{field}`, page `options` or a custom
`post_id`) through `AcfListener::isSavingOption()`, so an options page save is
one `acf.options_saved` and no `option.*` events.

### Export / import / privacy (PrivacyListener)

| Action | Severity | Object | Context |
| --- | --- | --- | --- |
| `privacy.export_started` | notice | | `content` (`all` or post type), `filters` (names of the filters used: `author`, `category`, `start_date`, `end_date`, `status`), `posts_count` (single post type exports), `via`: `export_wp` (Tools → Export, `export_wp()` callers) or `wp-cli` (`wp export`, which does not call `export_wp()`; hooked through `WP_CLI::add_hook( 'before_invoke:export' )`) |
| `privacy.import_started` | notice | | `importer` (the `?import=` slug in wp-admin) |
| `privacy.import_finished` | notice | | `importer` |
| `privacy.personal_data_exported` | notice | `user` / id of the request owner, or `user_request` / request id when the email has no account | `request_id`, `request_action`, `has_account` |
| `privacy.personal_data_erased` | notice | as above | as above |

The request email, export file names and paths are never logged.

## Settings, extensions, users

### Settings (`OptionListener`)

| Action | Severity | Object | Changes / context |
| --- | --- | --- | --- |
| `option.added` | per severity map | `option` / name | `changes`: `Redactor::diff(null, value, name)`: per key for arrays/serialized values, sensitive keys redacted, long strings truncated. `context.critical_option: true` when critical. |
| `option.updated` | per severity map | `option` / name | `changes`: `Redactor::diff(old, new, name)`. Skipped when the diff is empty (no-op update). For `active_plugins`: one change `{field: "active_plugins", old: [removed files], new: [added files]}` and `context.added` / `context.removed`. |
| `option.deleted` | per severity map | `option` / name | `changes`: diff of the value captured on `delete_option` → `null`. |

Severity map (`OptionRules`):

- **critical** (PLAN §5, filter `noformat_logger/critical_options`): `siteurl`,
  `home`, `admin_email`, `{blog_prefix}user_roles` (`wp_user_roles`);
  `active_plugins` when changed outside the plugins screen and WP-CLI (request
  source not `admin`/`cli`, i.e. activated by code, REST or cron);
  `default_role` set to `administrator`.
- **notice**: `users_can_register`, `default_role`, `permalink_structure`,
  `category_base`, `tag_base`, `blog_public`, `default_comment_status`,
  `default_ping_status`, `comment_registration`, `comment_moderation`,
  `comment_previously_approved`, `require_name_email`, `timezone_string`,
  `gmt_offset`, `WPLANG`, `page_on_front`, `show_on_front`, `page_for_posts`,
  `posts_per_page`, `blogname`, `blogdescription`, `mailserver_*`, `*_email`,
  `upload_path`, `upload_url_path`, `wp_page_for_privacy_policy`,
  `auto_update_plugins`, `auto_update_themes`, `auto_update_core_*`,
  `default_category`, `theme_mods_*`.
- **info**: everything else.

Exact names win over glob patterns; among matching globs the most severe wins.

Skipped (never logged by this listener):

- `Config::isIgnoredOption()` (transients, `cron`, `rewrite_rules`, `nflog_*`, … and the `noformat_logger/ignored_options` filter).
- `{taxonomy}_children` (WordPress' term hierarchy cache, rebuilt whenever a
  hierarchical term changes; `term.*` covers the change).
- ACF options page fields (`{page}_{field}`, `_{page}_{field}`) while ACF
  saves that page (`acf.options_saved` carries them).
- `widget_*`, `sidebars_widgets` (WidgetListener), `template`, `stylesheet`,
  `current_theme` (`theme.switched`), `theme_switch_menu_locations` (menu state
  WordPress keeps during a theme switch).
- `active_plugins` when the source is `admin` or `cli` (`plugin.activated` /
  `plugin.deactivated` cover it).
- `theme_mods_*` while a Customizer save runs (`did_action('customize_save') >
  did_action('customize_save_after')`): CustomizerListener logs those.
- Inside `theme_mods_*`: the keys `nav_menu_locations` (MenuListener) and
  `custom_css_post_id` (a pointer WordPress writes lazily on the first frontend
  render after a theme switch) are dropped before diffing; a `theme_mods_*`
  write with nothing left is not logged.

### Plugins, themes, core (`PluginThemeListener`, `CoreListener`)

| Action | Severity | Object | Changes / context |
| --- | --- | --- | --- |
| `plugin.activated` | notice | `plugin` / slug | `file` (e.g. `akismet/akismet.php`), `version` (plugin header, cached per request), `network_wide` |
| `plugin.deactivated` | notice | `plugin` / slug | as above |
| `plugin.installed` | notice (failed: error) | `plugin` / slug | `type`, `action`, `bulk`, `auto_update`, `file`, `version`, `error_code` |
| `plugin.updated` | notice (failed: error) | `plugin` / slug | as above; `changes`: `version` before → after (before captured on `upgrader_pre_install`) |
| `plugin.deleted` | notice (failed: error) | `plugin` / slug | `file`, `version` (captured on `delete_plugin`), `deleted` |
| `theme.installed` | notice (failed: error) | `theme` / stylesheet | `type`, `action`, `bulk`, `auto_update`, `version`, `error_code` |
| `theme.updated` | notice (failed: error) | `theme` / stylesheet | as above; `changes`: `version` |
| `theme.switched` | notice | `theme` / new stylesheet | `changes`: `stylesheet` old → new, `template` old → new for child themes; `context.version`, `context.old_version` (theme names are not logged) |
| `theme.deleted` | notice (failed: error) | `theme` / stylesheet | `version`, `deleted` |
| `editor.file_edited` | warning (attempt) / critical (file changed) | `plugin` / slug or `theme` / stylesheet | `file` (relative to the plugins directory for plugins, to the theme directory for themes), `changed`, `attempt`, `via` (`ajax` / `screen`), `exists`. Never file contents. |
| `core.updated` | notice | `core` / `wordpress` | `changes`: `version` (version running when the request started → new); `auto_update` |
| `core.auto_updates_completed` | notice (any failure: error) | `core` / `wordpress` | `results`: per type (`core`, `plugin`, `theme`, `translation`) `{count, succeeded, failed, slugs, failed_slugs}` (slugs only, no names) |

Notes:

- `auto_update` is true when `wp_doing_cron()` or `pre_auto_update` fired in the request.
- `upgrader_process_complete` with `type: core` or `translation` is ignored here (`core.updated` covers core).
- File editor: hooked at priority 0 on `wp_ajax_edit-theme-plugin-file`
  (WordPress ≥ 4.9 saves through AJAX) and on `load-theme-editor.php` /
  `load-plugin-editor.php` for POSTs when `DISALLOW_FILE_EDIT` is off.
  WordPress has no hook after a successful save, so the target file's SHA-256
  is taken before the save and compared again on `shutdown` (priority 1):
  unchanged → `warning` with `attempt: true` (rejected nonce, syntax check
  failure, no permission), changed → `critical` with `changed: true`. One
  event per request. Files over 5 MB are compared by size and mtime.

### Users (`UserListener`)

| Action | Severity | Object | Changes / context |
| --- | --- | --- | --- |
| `user.created` | notice (privileged role: critical) | `user` / id (subtype: role) | `roles`, `privileged` |
| `user.updated` | notice | `user` / id (subtype: role) | `changed_fields`: names of the changed `wp_users` columns (`user_login`, `user_pass`, `user_nicename`, `user_email`, `user_url`, `user_registered`, `user_activation_key`, `user_status`, `display_name`). No values. Not logged when nothing changed. User meta (first/last name, nickname…) is MetaListener's. |
| `user.role_changed` | notice (new privileged role: critical) | `user` / id (subtype: new role) | `changes`: `role` old (comma-joined) → new; `old_roles`, `new_role`, `elevated` |
| `user.role_added` | notice (privileged role: critical) | `user` / id (subtype: role) | `changes`: `roles` null → role; `role`, `elevated` |
| `user.role_removed` | notice | `user` / id (subtype: role) | `changes`: `roles` role → null; `role` |
| `user.deleted` | notice | `user` / id (subtype: role) | `reassign_id` (null when content was deleted), `roles` |
| `user.super_admin_granted` | critical | `user` / id | |
| `user.super_admin_revoked` | notice | `user` / id | |
| `user.app_password_created` | notice (category security) | `user` / id, `label_hash` = name hash | `uuid` (application password item uuid), `app_id`, `name_hash` (hash8 of the name). The password is never seen. |
| `user.app_password_deleted` | notice (category security) | as above | as above |
| `user.password_reset` | notice | `user` / id | |
| `user.password_reset_requested` | notice | `user` / id | The login passed by `retrieve_password` is only used to resolve the id. |

Role event folding: `WP_User::set_role()` fires `remove_user_role`,
`add_user_role` and `set_user_role`. Role events are held until `shutdown`
(priority 5, before the Logger flush at 1000):

- `set_user_role` drops the add/remove pair of the same call → one `user.role_changed`.
- `user_register` drops the role events of the user being created → only `user.created` (with the role and its severity).
- `add_user_role` / `remove_user_role` without `set_user_role` (e.g. `wp user add-role`, `WP_User::add_role()`) are logged as `user.role_added` / `user.role_removed`.

Privileged roles: `administrator` (filter `noformat_logger/privileged_roles`).
"Elevation" means the privileged role was not held before.

## Authentication and probing

### Authentication (`AuthListener`, shared `Security\Support\FailedLogin`)

| Action | Severity | Object | Changes / context | Fingerprint |
| --- | --- | --- | --- | --- |
| `auth.login_failed` | warning | `user` / id when the username exists, else `user` / null | `username_hash`, `ip_hash`, `user_exists`, `user_id` (only when it exists), `reason` (WP_Error code: `invalid_username`, `incorrect_password`, `invalid_email`, `login_prevented`, …) | username hash + ip hash |
| `auth.login_burst` | error | none | `ip_hash`, `failures` (count in the window when reported), `threshold`, `window_seconds` | ip hash |
| `auth.login` | info (privileged user from a new client: notice) | `user` / id (subtype: role); actor set to the user | `role`, `privileged`, `source`, `new_client` (true/false for privileged users, null otherwise), `ip_hash` | |
| `auth.logout` | info | `user` / id; actor set to the user | | |
| `auth.cookie_invalid` | warning | `user` / id when resolvable | `kind` (`bad_hash`, `bad_username`, `bad_session_token`, `expired`), `scheme`, `user_id`, `ip_hash`. Once per kind per request. `auth_cookie_malformed` is not hooked (it fires for every visitor without a cookie). | kind + ip hash |
| `auth.app_password_failed` | warning | `user` / id when resolvable | `reason`, `user_id`, `user_exists`, `username_hash` (from HTTP Basic `PHP_AUTH_USER` when present), `ip_hash` | reason + username hash + ip hash |

- Username resolution for `user_exists` is silent: `get_user_by('login')`, then `get_user_by('email')` for email-looking input.
- Burst rule: ≥ 10 failures per ip hash in a rolling 5-minute window, reported at most once per window per ip hash (filter `noformat_logger/login_burst`, `['threshold' => 10, 'window' => 300]`). State lives in a transient `nflog_burst_<ip hash>` (object cache when present; `_transient_*` is ignored by OptionListener).
- Known clients: for privileged users the last 10 ip hashes are kept in user meta `nflog_known_clients` (hashes only, most recent first). The first login from a hash not in the list is `notice` with `new_client: true`. `nflog_*` is in the default ignored meta keys, so this bookkeeping is never logged as `meta.*`.
- XML-RPC: the first failed login of an XML-RPC request goes through `wp_authenticate()` → `wp_login_failed` → `auth.login_failed` with `request.source: xmlrpc`. WordPress refuses further logins in the same request without authenticating (`login_prevented`, e.g. `system.multicall` brute force); `XmlRpcListener` records those through the same `FailedLogin` helper (`username_hash: null`, `reason: login_prevented`), so the attempt count stays correct without double counting.
- When application passwords are in use, WordPress also fires `application_password_failed_authentication` for failed XML-RPC/REST logins, so such a request can carry both `auth.login_failed` and `auth.app_password_failed`.

### Probing (`RestProbeListener`, `XmlRpcListener`, `ProbeListener`)

| Action | Severity | Object | Changes / context | Fingerprint |
| --- | --- | --- | --- | --- |
| `rest.users_probe` | warning | `route` / normalised route (`/wp/v2/users`, `/wp/v2/users/{id}`) | `route`, `method`, `has_search`, `has_slug`, `ip_hash`. Only for requests without a logged-in user (`rest_pre_dispatch`, priority 0). | ip hash (900 s window) |
| `rest.auth_error` | warning | `route` / normalised route | `code` (WP_Error code, e.g. `rest_cookie_invalid_nonce`, `incorrect_password`), `route`, `status`, `ip_hash`. From `rest_authentication_errors` at priority 1000 (after core's checks) when the result is a `WP_Error`. | code + route + ip hash |
| `xmlrpc.call` | warning | `method` / method name | `method`, `ip_hash`; for `system.multicall` also `multicall_calls` and `multicall_methods` (inner method → count, max 20 names) | method + ip hash (900 s window) |
| `probe.suspicious_404` | warning | `probe` / pattern name | `pattern`, `method`, `ip_hash`. `request.route` is set to null: the requested path is not stored. | pattern + ip hash (3600 s window: hourly) |

- Observe only: no filter changes its return value, nothing is blocked.
- `xmlrpc.call`: WordPress fires `xmlrpc_call` only after a successful login and never for `system.*` methods, so the top-level `methodName` is read once per request from the request body (first 64 KB) when the server is constructed (`xmlrpc_methods`). Invalid names become `(invalid)`. `xmlrpc_call` is only used as a fallback when the body could not be parsed. GET requests to `xmlrpc.php` are ignored.
- `probe.suspicious_404`: on `template_redirect` (priority 0) only when `is_404()`; one `preg_match` of a combined pattern over the decoded, lower-cased path. Patterns (filter `noformat_logger/probe_patterns`, name → regex body without delimiters): `env_file`, `wp_config`, `vcs` (`.git`/`.svn`/`.hg`), `sql_dump`, `backup_file` (`.bak`, `.old`, `.orig`, `.save`, `.swp`, `~`), `archive` (zip/tar/gz/rar/7z in `wp-content` or at the root), `phpmyadmin`, `adminer`, `xmlrpc` and `wp_login` (those files requested in other directories), `vendor`, `uploads_php`, `wlwmanifest`, `shell` (common web shell names), `cgi_bin`, `server_status`. Pattern names are sanitised to `[a-z0-9_]` (≤ 32, not starting with a digit). Flooding of the real `wp-login.php` shows up as `auth.login_failed` / `auth.login_burst`, not here.

## Errors and stability

Conventions for every error event:

- Paths are canonical and relative to the WordPress root: `wp-includes/…`,
  `wp-admin/…`, `wp-content/plugins/<slug>/…`, `wp-content/themes/<slug>/…`,
  `wp-content/mu-plugins/…` (also when `WP_CONTENT_DIR` lives elsewhere or a
  plugin is symlinked). A file outside every known root becomes
  `[outside]/<basename>`; a file inside a phar archive outside the roots
  (e.g. WP-CLI's own code) becomes `[phar:<archive name>]/<path inside>`. Absolute paths inside messages are rewritten the same
  way (PHP 8.4 closure names such as `{closure:/abs/file.php:12}` included).
- `component` is derived from the file: `core`, `plugin:<slug>`,
  `theme:<slug>`, `mu-plugin:<slug>`, `drop-in:<name>`, `wp-content`,
  `wp-cli` (WP-CLI's phar), `phar:<archive>`, `unknown`.
- Messages are scrubbed: e-mail addresses become `[email]`, IPv4/IPv6
  addresses `[ip]`; truncated to 1 KB (error messages) or 512 bytes.
- `trace` is at most 10 frames of `{function, class?, file?, line?}`; arguments
  are never kept.
- Errors raised inside the logger's own `src/` are never logged (loop guard);
  they increment the internal failure counter shown in the manifest.

### Actions

| Action | Category | Severity | Object | Context | Fingerprint |
| --- | --- | --- | --- | --- | --- |
| `php.notice` | error | warning | `file` / relative path (≤ 64 chars, long paths keep their tail) | `type` (`E_NOTICE`, `E_USER_NOTICE`, `E_STRICT`), `message`, `file`, `line`, `component`, `trace`; `early: true` when drained from the MU loader | type, file, line, message with digits / hex / quoted values stripped |
| `php.deprecated` | error | warning | `file` | as above (`E_DEPRECATED`, `E_USER_DEPRECATED`) | as above |
| `php.warning` | error | error | `file` | as above (`E_WARNING`, `E_USER_WARNING`, `E_CORE_WARNING`, `E_COMPILE_WARNING`) | as above |
| `php.error` | error | error | `file` | as above (`E_USER_ERROR`, `E_RECOVERABLE_ERROR`) | as above |
| `php.errors_suppressed` | error | warning | | `cap` (50), `by_type` (label => count; `early_mu` for MU buffer overflow); `count` = suppressed occurrences | |
| `php.fatal` | stability | critical | `file` | `type` (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`, `E_RECOVERABLE_ERROR`), `message` (first line), `file`, `line`, `component`, `public_request` (source = frontend). Uncaught engine `\Error`s (undefined function, `TypeError`, …): additionally `class`, `trace`, `uncaught: true` | action, type/class, file, line, normalised message |
| `php.exception` | stability | critical | `file` | `class`, `message`, `file`, `line`, `component`, `trace`, `public_request`, `uncaught: true`; via a wrapped previous handler also `code`, `previous {class, file, line}` | action, class, file, line, normalised message |
| `db.error` | stability | critical | `database` | `error` (literal values removed: `Duplicate entry '?'`, `near '<stripped SQL>'`; the SQLite drop-in's HTML report reduced to its message), `query` (every string / number / hex literal → `?`, `IN (?)`, `VALUES (?)`; the logger restores `$wpdb->last_query` after its own queries, so this is the site's query; null when unavailable), `caller` (`wpdb::<method>`, plus the last 3 call names with `SAVEQUERIES`) | normalised query, error with digits stripped |
| `deprecated.function` | error | warning | `function` / name | `function`, `replacement`, `version`, `message` (null), `component` and `caller {file, line}` of the first frame outside `wp-includes/` | action, name |
| `deprecated.argument` | error | warning | `function` | `function`, `version`, `message` (tags stripped, paths relative), `component`, `caller` | action, name |
| `deprecated.hook` | error | warning | `hook` | `hook`, `replacement`, `version`, `message`, `component`, `caller` | action, name |
| `deprecated.file` | error | warning | `file` | `file` (relative), `replacement`, `version`, `message`, `component`, `caller` | action, name |
| `doing_it_wrong` | error | warning | `function` | `function`, `version`, `message`, `component`, `caller` | action, name |
| `http.request_failed` | http | error | `host` | `host` (IP literals: `ip-<hash8>`), `ip_scope` (IP hosts only: `loopback`/`private`/`reserved`/`public`), `scheme`, `port`, `path` (no query; segments containing `@`, longer than 12 characters with a digit, or opaque tokens of 20+ characters become `{hash8}`, e.g. Slack/Discord/Telegram webhook tokens and e-mails in CRM paths; the first segment too when it is a token), `method`, `status` (≥ 500) or null, `error_code` / `error` (WP_Error), `duration_ms` | host, path, status or error code |
| `mail.failed` | mail | error | | `error_code`, `error` (addresses scrubbed), `exception_code` (PHPMailer), `recipient_count`, `subject_hash` (hash8), `attachments` (bool) | error code, PHPMailer exception code |
| `config.risky_setting` | security | per finding (warning / error); `resolved`: info | `config` / finding id | `finding`, `state` (`present` \| `changed` \| `resolved`), `detail` (see below), `previous_severity` | none (one event per state change) |
| `integrity.mismatch` | security | critical; `resolved`: info | `core` / `wordpress`, or `plugin` / slug | `scope` (`core` \| `plugin:<slug>`), `state` (`detected` \| `changed` \| `resolved`), `paths` (≤ 50, prefixed `modified:` / `missing:` / `unknown:`), `path_count` | none (one event per scope state change) |
| `chain.break_detected` | security | critical | `event` / break id | `from`, `to`, `break_id`, `reason` (`prev_hash_mismatch` \| `hash_mismatch` \| `chain_head_mismatch`), `checked` | break id (reported once per break id) |
| `cron.overdue` | cron | warning | `cron_hook` / hook | `hook`, `overdue_seconds`, `interval`, `schedule`, `wp_cron_off` | hook (event when a hook starts alerting) |
| `updates.pending` | core | notice; warning when an update has been pending ≥ 7 days | | `core`, `plugins`, `themes` (counts), `stale`, `stale_after` (7), `items` (≤ 50 × `{type, slug, version, pending_days, stale}`) | none (one event per change of the pending set or its staleness) |

### Mechanics and limits

- **ErrorHandler** honours `error_reporting()` (the `@` operator, WordPress'
  level when `WP_DEBUG` is off: notices/deprecations are then not reported).
  It always chains: the previous handler is called and its return value
  passed through (exceptions it throws are the site's behaviour); without one
  it returns `false`, so PHP's own handling (`display_errors`, `debug.log`)
  is unchanged. Per request at most 50 distinct errors become events; repeats
  of a fingerprint bump `count` without re-processing; the rest go into one
  `php.errors_suppressed`.
- **MU loader (PLAN D8).** When the handler being replaced is the loader's,
  the loader's buffer is drained into events (`context.early: true`, original
  timestamp), the loader is detached (stops buffering; `detach()`), and the
  plugin chains to `Noformat_Logger_Early_Errors::previous_handler()`, i.e. the
  handler active before the loader. If another handler sits in between, the
  plugin chains to that one and the detached loader merely passes through.
  The buffer is drained again at shutdown. Older loader copies without
  `detach()` keep working.
- **Deprecations** are taken from WordPress' `*_run` actions, which fire even
  with `WP_DEBUG` off. The `wp_trigger_error()` follow-up error (file
  `wp-includes/functions.php`) is skipped once, so a deprecation is reported
  as `deprecated.*` only, not also as `php.deprecated`. Cap: 50 per request.
- **Fatals** are captured in WordPress' fatal error handler via the
  `wp_php_error_message` / `wp_php_error_args` filters (observed, value
  returned unchanged), because that handler ends in `wp_die()` and later
  shutdown functions never run; plus our own shutdown function for sites
  where WordPress' handler is disabled. Written synchronously (`logNow`).
  Recovery mode is unaffected.
- **Uncaught throwables.** No exception handler is installed when none
  exists: a user exception handler would stop PHP from turning the throwable
  into a fatal error (changing the response and hiding it from recovery mode),
  and a handler cannot re-throw (PHP calls it again). The resulting
  `Uncaught <Class>: <message> in <file>:<line>` fatal is parsed instead,
  including its `Stack trace:` (call names only). When another plugin
  installed a handler before ours, ours wraps it and still calls it.
- **db.error** only sees `$wpdb->last_error`, which is the error of the LAST
  query: an error followed by a successful query is missed, and one database
  error per request (per distinct text) is reported. Checked on `shutdown`
  (priority 1) and whenever a `wp_die_*handler` filter is resolved.
  "Error establishing a database connection" happens before plugins load and
  cannot be captured.
- **HTTP**: `http_request_args` adds `_nflog_started` (microtime) to the
  request args for `duration_ms`. Bodies and headers are never read.

## Config audit findings

Runs daily (`noformat_logger/cron/config_audit`) and on demand. `run()` returns
`{implemented: true, ran_at, findings: [{id, severity, state, detail}], changes, http_checks}`.
State per finding id (severity + digest of detail) is stored in meta
`config_audit_state`; an event is emitted only when a finding appears,
changes or disappears.

| Finding id | Severity | Condition | `detail` |
| --- | --- | --- | --- |
| `debug_display` | error | `WP_DEBUG` and `WP_DEBUG_DISPLAY` truthy | `wp_debug_display` |
| `debug_in_production` | warning | `WP_DEBUG` and `wp_get_environment_type() === 'production'` | `environment` |
| `file_edit_allowed` | error | neither `DISALLOW_FILE_EDIT` nor `DISALLOW_FILE_MODS` is true | `disallow_file_edit` |
| `open_registration_elevated` | error | `users_can_register` on and `default_role` ≠ subscriber with capabilities beyond `read` / `level_0` (or an unknown role) | `default_role`, `capabilities` (count) |
| `xmlrpc_enabled` | warning | `apply_filters('xmlrpc_enabled', true)` | |
| `wp_config_writable` | warning | `wp-config.php` (ABSPATH or parent) writable by the PHP user running the audit (cron: the web user) | `location` (`abspath` \| `parent`) |
| `admin_username` | warning | a user with login `admin` exists | `user_id` |
| `table_prefix_default` | warning | `$wpdb->base_prefix === 'wp_'` | |
| `force_ssl_admin_off` | warning | site uses HTTPS and `FORCE_SSL_ADMIN` / `force_ssl_admin()` is off | |
| `auto_updater_disabled` | warning | `AUTOMATIC_UPDATER_DISABLED` true | |
| `core_auto_update_disabled` | warning | `WP_AUTO_UPDATE_CORE === false` | |
| `generator_exposed` | warning | `wp_generator` still hooked on `wp_head` and `the_generator` not emptied | |
| `debug_log_public` | warning | debug log file inside the web root and a HEAD request to its URL returns 200 (cron / WP-CLI only) | `http_status` |
| `readme_exposed` | warning | `readme.html` exists and HEAD returns 200 (cron / WP-CLI only) | `http_status` |

Not reported as findings: directory listing (not detectable from PHP),
`DISALLOW_FILE_MODS` (state is exposed in `/health` `debug.disallow_file_mods`;
disabling file mods is a policy choice, not a risk). HTTP checks are skipped
outside cron / WP-CLI and their previous state is carried over (no spurious
`resolved`).

## Integrity result

```jsonc
{
  "implemented": true,
  "ran_at": "2026-10-28T16:21:23Z",
  "core": {
    "status": "ok",            // ok | mismatch | partial (time budget hit, resumes next run) | unavailable (API unreachable / unknown version)
    "version": "6.8.3", "locale": "en_US",
    "checked": 3338,           // files hashed (wp-content/ excluded)
    "modified": [], "missing": [], "unknown": [],   // ≤ 50 relative paths each; unknown = *.php under wp-admin/ and wp-includes/ not in the list
    "modified_total": 0, "missing_total": 0, "unknown_total": 0,
    "error": "checksums_unavailable"                // only when unavailable
  },
  "plugins": {
    "status": "ok",            // ok | mismatch | partial | deferred (core pass unfinished)
    "checked": 1,
    "verified": ["sqlite-database-integration"],
    "skipped": ["noformat-logger"],                  // not on wordpress.org, no checksum file (404, cached per slug@version), network error
    "mismatches": { "<slug>": { "version": "1.2.3", "algo": "sha256", "modified": ["wp-content/plugins/<slug>/…"], "missing": [] } }
  },
  "chain": { "ok": true, "checked": 100, "from": 1, "to": 100, "break": null }  // last 1 000 events
}
```

Core checksums are cached in meta (`integrity_core_checksums`) per version and
locale (falls back to `en_US`). Budget: 30 s per run (filter
`noformat_logger/integrity_budget`); an unfinished core pass stores a cursor
in `integrity_progress`. Scope digests live in `integrity_state`, the compact
summary for `/health` in `integrity_last`, the last reported chain break id in
`chain_break_reported`.

`wp nflog integrity` prints this JSON and exits non-zero (`WP_CLI::error`) when
`core.status` or `plugins.status` is `mismatch` or `chain.ok` is false; pass
`--no-fail` to always exit 0. `unavailable`, `partial` and `deferred` are not
failures.

## Health snapshot

`GET /v1/health` and `wp nflog health` return the same JSON.

Cheap parts are computed per call; `updates`, `users`, `content`,
`errors_24h` and `site_health` come from the cache written every 15 minutes by
`noformat_logger/cron/health_cache` (meta `health_cache`, treated as missing
after 1 h; without a cache they are computed inline, except Site Health).

```jsonc
{
  "implemented": true,
  "generated_at": "2026-10-28T16:21:22Z",
  "cache": { "generated_at": "…", "age_seconds": 23 },          // null when computed inline
  "versions": {
    "php": "8.3.12", "wordpress": "6.8.3", "db_engine": "mysql", // mysql | sqlite
    "db_server": "8.0.38", "wp_db": 60421, "plugin": "1.0.1",
    "schema": { "event": 1, "database": 1, "expected": 1 },
    "php_sapi": "fpm-fcgi", "php_memory": "256M"
  },
  "environment": "production",
  "multisite": false,
  "active_theme": { "stylesheet": "child", "template": "parent", "version": "1.2", "update": null },
  "plugins": [ { "slug": "akismet", "version": "5.3", "update": "5.4", "auto_update": false, "network": false } ],  // active only
  "updates": {
    "core": 0, "plugins": 1, "themes": 0, "core_version": null,
    "plugin_slugs": ["akismet"], "theme_slugs": [],
    "items": [ { "type": "plugin", "slug": "akismet", "version": "5.4", "pending_days": 2, "stale": false } ],
    "stale": 0,
    "last_checked": { "core": "…", "plugins": "…", "themes": "…" }   // from the update transients; no check is forced
  },
  "debug": {
    "wp_debug": false, "wp_debug_display": false, "wp_debug_log": false,   // wp_debug_log: bool or "custom_path"
    "script_debug": false, "disallow_file_edit": true, "disallow_file_mods": false,
    "disable_wp_cron": false, "wp_cache": true, "object_cache": true, "display_errors": false
  },
  "cron": {
    "disabled": false, "alternate": false, "events": 13,
    "last_run_at": "…", "last_run_age": 12,                 // from meta last_cron_run, written on wp_loaded in WP-Cron requests
    "overdue_count": 0,
    "overdue": [ { "hook": "…", "overdue_seconds": 8000, "interval": 3600, "schedule": "hourly", "alert": true } ],  // ≤ 20, > 60 s late
    "next": [ { "hook": "…", "in_seconds": 877, "schedule": "nflog_15min" } ],                                     // ≤ 10
    "alerting_hooks": ["…"]                                   // > 2× interval late → cron.overdue
  },
  "uploads_writable": true,
  "ssl": { "is_ssl": true, "using_https": true, "force_ssl_admin": true },
  "users": { "administrator": 2, "editor": 3, "total": 5 },  // counts per role only
  "content": { "attachment": 120, "page": 14, "post": 80 },  // published per public post type (attachments: inherit)
  "errors_24h": { "by_severity": { "critical": { "events": 0, "occurrences": 0 }, "…": {} }, "since": "…" },  // EventRepository::countsBySeverity(), one grouped query
  "site_health": {                                           // direct tests, cron / WP-CLI only; ids and status only
    "ran_at": "…", "counts": { "good": 15, "recommended": 4, "critical": 1 },
    "results": [ { "test": "php_version", "status": "good" } ]
  },
  "integrity": { "ran_at": "…", "core": { "status": "ok", "version": "…", "checked": 3338, "modified": 0, "missing": 0, "unknown": 0 },
                 "plugins": { "status": "ok", "checked": 1, "mismatched": [] }, "chain": { "ok": true, "checked": 100 } },
  "config_audit": { "count": 5, "findings": [ { "id": "xmlrpc_enabled", "severity": "warning" } ] }
}
```

Any part that fails is `null` (the failure is counted, never thrown). The
snapshot contains no names, e-mails or filesystem paths, only slugs, ids,
versions and counts.

## Aggregation windows

The Logger aggregates a fingerprinted event into the stored first occurrence
while the fingerprint's window is open; the window is chosen per event:

1. the per-action map, setting `fingerprint_windows` (default below; a stored
   setting replaces the whole map);
2. otherwise `fingerprint_window_debug` (3600 s) for `debug` events and
   `fingerprint_window` (60 s) for everything else;
3. then the filter `noformat_logger/fingerprint_window`
   (`int $seconds, Severity $severity, string $action`), minimum 1 s.

| Action | Default window |
| --- | --- |
| `probe.suspicious_404` | 3600 s (hourly, PLAN §4) |
| `rest.users_probe` | 900 s |
| `xmlrpc.call` | 900 s |
| any other fingerprinted event | 60 s (`debug`: 3600 s) |

Within one request, repeats of a fingerprint are merged in memory regardless
of the window. Fingerprint rows not seen for 7 days are removed by the
hourly cleanup cron (their final count is written to the event first).

### Write budget

While a window is open, its **fingerprint row** is the one place that counts
occurrences:

| Occurrence | Queries on the plugin's tables |
| --- | --- |
| recurring fingerprint, no persistent object cache | 1 SELECT (fingerprint row) + 1 UPDATE (fingerprint row) per request, however often it repeats in the request |
| recurring fingerprint, persistent object cache (`wp_using_ext_object_cache()`) | 0 SELECT (row cached in group `nflog`) + 1 UPDATE |
| hot fingerprint (≥ 100 occurrences in its window) | the UPDATE runs for one request in ten (random), adding ten times that request's occurrences, so the count is an unbiased estimate; 1 SELECT, or none with an object cache |
| first occurrence / new window | the normal batch insert; the previous window's final count is written to its event once |

The event row's `count` is not updated per occurrence: `GET /events`,
`/events/{id}`, `/summary`, `/health` and `wp nflog tail|events` read the live
count from the fingerprint row (hydration). The client IP / user-agent hashes
are only computed for events that are actually stored, so an aggregated
occurrence never reads the site salt. `count` is not part of the hash.

Once the collector has an event (`GET /events` served it, or `ack` covers
it), its window is closed: the next occurrence starts a new event instead of
raising a count the collector already read (docs/API.md, "Counts").

### Anonymous flood cap

Events that an unauthenticated client can trigger at will — `api.auth_failed`,
`auth.login_failed`, `probe.suspicious_404`, `rest.users_probe`,
`xmlrpc.call`, `auth.cookie_invalid`, `rest.auth_error`, when the actor is
anonymous — may create at most `anonymous_per_minute` (default 120, `0`
disables) **new rows** per minute, site-wide. Aggregated occurrences do not
count. The counter is a per-minute bucket in the meta table (`rl:anon:<minute>`,
removed by the hourly cleanup) or, with a persistent object cache, a
`wp_cache_incr()` key. Excess occurrences are folded into one `system.flood`
event per 60 s window (its `count` is the number of folded occurrences). This
also bounds failed logins with random usernames (fingerprinted per username
hash + IP) and probes from many IPs. Together with the prune rule (acked and
debug/info rows first, never unacknowledged notice+ rows before
`hard_max_days`), a flood cannot evict events the collector has not pulled.

## Filters

Every filter the plugin applies. List filters receive and return a list of
strings; a non-array return falls back to the default.

| Filter | Value (default) | Used by |
| --- | --- | --- |
| `noformat_logger/event` | `Event\|null`; return null to drop the event before it is buffered | Logger |
| `noformat_logger/settings` | resolved settings array (merged over constants > option > defaults) | Config |
| `noformat_logger/sensitive_keys` | `list<string>` key patterns redacted everywhere (`Config::defaultSensitiveKeys()`) | Redactor |
| `noformat_logger/safe_keys` | `list<string>` keys never redacted (`Config::defaultSafeKeys()`) | Redactor |
| `noformat_logger/ignored_options` | `list<string>` option globs never logged (`Config::defaultIgnoredOptions()`) | OptionListener, WidgetListener |
| `noformat_logger/ignored_meta_keys` | `list<string>` meta key globs never logged (`Config::defaultIgnoredMetaKeys()`, includes `nflog_*`) | MetaListener |
| `noformat_logger/fingerprint_window` | `int` seconds; args `Severity $severity`, `string $action` | FingerprintStore |
| `noformat_logger/client_ip` | `string` client IP used for `ip_hash` and the API allowlist (default `REMOTE_ADDR`; set it from a trusted proxy header) | RequestContext |
| `noformat_logger/is_https` | `bool` whether the API request counts as HTTPS (default `is_ssl()`; TLS terminated at a trusted proxy) | Authenticator |
| `noformat_logger/critical_options` | `list<string>` option names / globs logged as critical (`siteurl`, `home`, `admin_email`, `{prefix}user_roles`) | OptionListener |
| `noformat_logger/option_severity` | `array<string, string>` option name / glob → severity | OptionListener |
| `noformat_logger/privileged_roles` | `list<string>` roles whose grant is critical (`administrator`) | UserListener, AuthListener |
| `noformat_logger/login_burst` | `array{threshold: int, window: int}` (10 / 300 s) | AuthListener, XmlRpcListener |
| `noformat_logger/probe_patterns` | `array<string, string>` pattern name → regex body | ProbeListener |
| `noformat_logger/integrity_budget` | `int` seconds per integrity run (30) | IntegrityCheck |
| `noformat_logger/post_listener_skip_types` | `list<string>` post types ignored (`PostListener::SKIP_TYPES`) | PostListener |
| `noformat_logger/meta_listener_owned_keys` | `list<string>` extra meta key globs ignored (`MetaListener::OWNED_KEYS`) | MetaListener |
| `noformat_logger/meta_listener_meaningful_keys` | `list<string>` underscore keys logged at info instead of debug (`MetaListener::MEANINGFUL_KEYS`) | MetaListener |
| `noformat_logger/term_listener_skip_taxonomies` | `list<string>` taxonomies ignored (`TermListener::SKIP_TAXONOMIES`) | TermListener |
| `noformat_logger/content_categories` | `list<string>` categories shown in the wp-admin Content log (`Admin\Scope::CONTENT_DEFAULT`); every other category is in the System log. Display only: nothing about storage or the API changes | Admin\Scope |
| `noformat_logger/term_name_taxonomies` | `list<string>` taxonomies whose term names may be logged in clear (`TermListener::NAME_TAXONOMIES` + public taxonomies) | TermListener |

Request arg: `http_request_args` adds `_nflog_started` (microtime) to outbound
HTTP API requests for `http.request_failed.duration_ms`.

Cron hooks (actions, not filters): `noformat_logger/cron/prune` (daily),
`noformat_logger/cron/nonce_cleanup` (hourly), `noformat_logger/cron/integrity`
(weekly), `noformat_logger/cron/config_audit` (daily),
`noformat_logger/cron/health_cache` (every 15 minutes).

## Internal storage keys

Meta keys in `{prefix}nflog_meta` used by the health, audit and integrity
services: `config_audit_state`, `integrity_core_checksums`, `integrity_progress`,
`integrity_state`, `integrity_last`, `integrity_plugin_unavailable`,
`chain_break_reported`, `health_cache`, `last_cron_run`,
`health_cron_alerts`, `updates_first_seen`, `updates_digest`; logger state:
`last_served_id` (highest id `GET /events` returned), `key_unreadable_at`
(throttle of `logger.key_unreadable`), `rl:<bucket>:<minute>` rate-limit and
anonymous-cap buckets (`rl:anon:<minute>`).

Other storage: `{prefix}nflog_meta` also holds the chain head, site salt, ack
cursor, last pull, schema version and failure counters. Options:
`nflog_settings` (autoloaded), `nflog_schema_cache` (autoloaded mirror of the
schema version, so admin pages check it without a query), `nflog_key`
(encrypted key when not in wp-config), `nflog_failures_fallback`. User meta:
`nflog_known_clients`. Transients: `nflog_burst_<ip hash>`,
`nflog_schema_attempt` (install attempts at most every 10 minutes while the
schema is behind). Object cache group (persistent caches only): `nflog`. All of it is removed on uninstall when
`delete_data_on_uninstall` is set.

## Known limitations and deferred work

- **`db.error` sees only the last query.** `$wpdb->last_error` belongs to the
  last query of the request, so an error followed by a successful query is
  missed, and only one error per distinct text per request is reported.
  "Error establishing a database connection" happens before plugins load and
  cannot be captured.
- **File editor success is detected by hashing.** WordPress has no hook after a
  successful plugin/theme editor save; the target file is hashed before the
  save and again on `shutdown`. Files over 5 MB are compared by size and mtime.
- **Customizer and option events overlap.** Option-type Customizer settings
  (`blogname`, `blogdescription`, `site_icon`, …) produce both
  `customizer.saved` and `option.updated`; both are correct and share the
  request id, so the Controlroom can group them.
- **Exception wrapping is untested end to end.** The path where another
  plugin installed an exception handler before ours (ours wraps and still
  calls it) is unit tested only; the integration smoke tests cover the
  default path (no handler, uncaught throwable becomes a fatal).
- **`wp_user_roles` is critical.** Sites whose plugins add roles or
  capabilities on activation see a critical `option.updated`; lower it per
  site with `noformat_logger/critical_options`.
- **Transients with an object cache** (`nflog_burst_*`) are not deleted on
  uninstall; they expire within the burst window.
- **Signatures do not bind the host** (v2 consideration). The string to
  sign covers method, path, query, timestamp, nonce and body, not the host.
  Keys are per site, so this only matters for cloned sites sharing
  `wp-config.php` constants: a request captured for one could be replayed to
  the clone within the 300 s skew window (nonces are per database). Give every
  environment its own key (docs/SECURITY.md).
- **Multisite uninstall cleans the current site only.** Tables, options and
  user meta of other sites in a network are left in place (Phase 2,
  multisite module).
- **Concurrent first occurrences.** Two requests that see the same new
  fingerprint at the same moment may both insert an event; the second one
  then owns the fingerprint row. Harmless: two rows with counts instead of one.
- **Sampled counts.** Above 100 occurrences in a window the count is an
  estimate (one write in ten, adding ten times); low counts are exact.
- **Count window closing races.** An occurrence counted between the moment
  `GET /events` reads a row and the moment it closes that row's window adds to
  a count the collector may already have; it is visible on `/events/{id}`.
- **Crash-path writes skip the transaction.** On a fatal error or uncaught
  exception the event is written without `START TRANSACTION` / `FOR UPDATE`
  (a transaction on the shared connection would commit the site's
  half-finished one). Two requests writing at that same instant can fork the
  chain; `/verify` reports it as a break.
- **Value scrubbing is pattern based.** E-mail and IP addresses are removed
  from every logged string; other personal data in free text under
  non-sensitive keys (names in a "note") is not detectable and relies on the
  key list and content summaries.
- **Phase 2**: push beacon for `critical`, WooCommerce and multisite modules,
  full content diffs opt-in UI, Controlroom-side chain verification, key
  rotation tooling, object-cache nonce store (PLAN §9).
