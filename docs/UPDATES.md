# Updates

Noformat Logbook updates like any other plugin: it appears in **Dashboard →
Updates** and on the **Plugins** screen with *update now*, supports the
auto-update toggle, `wp plugin update nf-logbook` and the *View details*
modal. There is no update server of our own: the source of truth is the
**GitHub Releases** of the repository named by `NOFORMAT_LOGGER_UPDATE_REPO`
(default `wearenoformat/nf-logbook`).

Code: `src/Update/` (`Updater`, `ReleaseClient`, `Release`, `Changelog`).

## How a site finds and installs an update

1. The plugin header carries `Update URI: https://github.com/wearenoformat/nf-logbook`.
   WordPress therefore never asks wordpress.org about this plugin; instead it
   calls the filter `update_plugins_github.com` (WordPress 5.8+) whenever it
   refreshes its plugin update list (twice a day by cron, and on the Updates
   and Plugins screens).
2. `ReleaseClient` answers from its cache, or makes **one** API call:
   `GET https://api.github.com/repos/{repo}/releases/latest` with
   `Accept: application/vnd.github+json`, `X-GitHub-Api-Version: 2022-11-28`, a
   User-Agent naming the plugin, its version and the site host, and a 10 s
   timeout. `/releases/latest` never returns drafts or pre-releases (both are
   also refused if they ever appear).
3. The release must carry two assets: `nf-logbook.zip` and `manifest.json`.
   The manifest is downloaded only when the release changed:

   ```json
   {
     "name": "Noformat Logbook",
     "slug": "nf-logbook",
     "version": "1.1.0",
     "requires": "6.4",
     "requires_php": "8.1",
     "tested": "7.1",
     "released_at": "2026-11-02T10:00:00Z",
     "download_url": "https://github.com/wearenoformat/nf-logbook/releases/download/v1.1.0/nf-logbook.zip",
     "sha256": "<64 hex characters: SHA-256 of nf-logbook.zip>",
     "changelog": "<the CHANGELOG.md section of this version, Markdown>"
   }
   ```

   The manifest's version must equal the tag's (`v1.1.0` → `1.1.0`), the slug
   must be `nf-logbook` and `sha256` must be 64 hex characters.
4. The answer is cached in the transient `noformat_logger_update`: fresh for
   **6 hours**, kept for 7 days so that the next check sends the stored ETag
   (`If-None-Match`); GitHub then answers 304, which keeps the cached answer
   and does not count against the rate limit.
5. The filter returns the update array (`id`, `slug`, `version`, `url`,
   `package`, `requires`, `requires_php`, `tested`, `upgrade_notice`) for the
   latest release whether or not it is newer: WordPress files it under
   *update available* or *no update*, and the second is what shows the
   auto-update toggle and *View details* on the Plugins screen.
6. On *update now*, an auto-update or `wp plugin update`, `upgrader_pre_download`
   downloads the package itself (HTTPS only, our headers, 300 s) and computes
   its SHA-256. A package whose digest differs from the manifest is deleted and
   the update is refused with:

   > The Noformat Logbook 1.2.0 package failed the SHA-256 check (manifest 3f2a…, download 9c41…). The update was refused.

7. `upgrader_source_selection` renames the unpacked folder to the installed
   one (`nf-logbook`) whatever the zip's top folder is (GitHub's
   "Source code" zips are `nf-logbook-1.2.0/`), and refuses a package
   without `nf-logbook.php`.
8. `upgrader_process_complete` clears the cache. The update is logged as
   `plugin.updated` (with the version change) by `PluginThemeListener`; when
   that module is switched off, the updater logs it itself.

Nothing in the updater can break the site: every failure means "no update"
and is shown on **Tools → Noformat Logbook → Status → Updates**, e.g.

```
Updates: 1.1.0 installed · latest 1.1.0 · source GitHub wearenoformat/nf-logbook · last check 2026-11-02 10:00:00 UTC · ok
Updates: 1.1.0 installed · latest unknown · source GitHub wearenoformat/nf-logbook · last check … · error: GitHub answered 404: the repository is private or the name is wrong (or it has no published release yet)
```

**Rate limit fallback (1.3.1).** GitHub's API allows 60 anonymous requests per
hour per IP address, and shared hosts (WP Engine and the like) send many sites
through one address. Without a token, when the API answers with its rate
limit (403 with `x-ratelimit-remaining: 0`, or 429), the check reads
`https://github.com/{repo}/releases/latest/download/manifest.json` instead: a
plain download link, not counted against the API limit. Its `download_url`
must start with `https://github.com/{repo}/releases/download/`, and the
package's SHA-256 is verified as always. With a token there is no fallback.

On 403, 429 and 404 (and network errors) the previously cached release is kept
and the reason recorded. Administrators get a **Check now** button (nonce,
`manage_options`) that clears the cache and WordPress' plugin update cache
(`wp_clean_plugins_cache`) and checks again. From the shell:

```sh
wp nflog update-check            # check now, print the line above (a failure is a warning, exit 0)
wp nflog update-check --cached   # print the cached answer only
wp nflog update-check --format=json
```

## Constants (wp-config.php)

| Constant | Default | Purpose |
| --- | --- | --- |
| `NOFORMAT_LOGGER_UPDATE_REPO` | `wearenoformat/nf-logbook` | `owner/name` of the repository whose releases are used |
| `NOFORMAT_LOGGER_UPDATE_TOKEN` | — | Optional token for a **private** repository (see below) |
| `NOFORMAT_LOGGER_UPDATE_MANIFEST` | — | Self-hosted mode: a direct manifest URL (`https://…`, or `http://127.0.0.1…` for local tests). GitHub is not contacted; `download_url` in that manifest is the package. |

### Private repositories

A private repository answers 404 to anonymous requests, so sites see "GitHub
answered 404: the repository is private or the name is wrong" and get no
updates. Two ways out:

- **Recommended:** make the plugin repository public, or publish releases to a
  public **releases-only mirror** repository and point
  `NOFORMAT_LOGGER_UPDATE_REPO` at it. Nothing secret then lives on client sites.
- `NOFORMAT_LOGGER_UPDATE_TOKEN`: a fine-grained personal access token,
  read-only, limited to this one repository, permission *Contents: read*. With
  it every GitHub request carries `Authorization: Bearer …`; assets are read
  through the asset API (`Accept: application/octet-stream`) and the redirect
  to GitHub's storage is followed without the token. The token would have to
  live in `wp-config.php` on **every** site (anyone with file access to any
  client site could read the repository), it expires, and rotating it means
  editing every site. Never put it in the database or in this repository.

## Cutting a release

Prerequisites: a clean `main`, `composer install`, the GitHub CLI logged in
with push rights.

1. Bump the version in the plugin header (`Version:`) and
   `NOFORMAT_LOGGER_VERSION` in `nf-logbook.php`, and add a
   `## [x.y.z] - YYYY-MM-DD` section to `CHANGELOG.md`. Commit.
2. Run:

   ```sh
   tools/release.sh 1.2.0
   ```

   It checks that the header, the constant, the CHANGELOG section and the tag
   agree (and that `v1.2.0` does not exist), runs `composer check`, builds
   `nf-logbook.zip` (top folder `nf-logbook/`) from `git archive HEAD`
   honouring `.distignore` (no `vendor`, `tests`, `tools`, `composer.*`,
   `PLAN.md`, dot files …), computes its SHA-256, writes `manifest.json`
   (changelog = that version's CHANGELOG section), tags `v1.2.0`, pushes the tag
   and runs `gh release create v1.2.0 --notes-file … nf-logbook.zip manifest.json`.

`.github/workflows/release.yml` does the same on a pushed `v*` tag (PHP 8.3,
`composer install`, `composer check`, `tools/release.sh --ci`), so a tag pushed
by hand also produces a release. When `tools/release.sh` already created the
release, the workflow finds it and stops.

`tools/release.sh --local-only --version 9.9.9 --out <dir> [--download-url <url>]`
builds the two assets from the working tree without git or GitHub (a version
different from the header is written into the built copy);
`tests/integration/smoke-update.sh` uses it.

`tests/integration/smoke-update-private.sh` covers the private-repository
(token) path without GitHub and without a real token: it installs
`tests/integration/github-emulator.php` as a mu-plugin, whose
`pre_http_request` filter answers `api.github.com`, `github.com` and
`release-assets.githubusercontent.com` the way GitHub does for a private repository
(anonymous 404, bad token 401, ETag / 304, asset API 302 to pre-signed storage
that answers 400 when the token is forwarded, browser download URLs 404). It
checks the three error/ok states of `wp nflog update-check`, the headers of
every request (Bearer only to the API, never to storage, `application/octet-stream`
for assets), the ETag revalidation, `wp plugin update`, the SHA-256 refusal of a
tampered zip, and that the token is never printed or stored.

## Verifying a release

```sh
gh release view v1.2.0 --repo wearenoformat/nf-logbook
gh release download v1.2.0 --repo wearenoformat/nf-logbook -p nf-logbook.zip -p manifest.json -D /tmp/nflog-v1.2.0
cd /tmp/nflog-v1.2.0
shasum -a 256 nf-logbook.zip                 # must equal:
php -r 'echo json_decode(file_get_contents("manifest.json"))->sha256, "\n";'
unzip -l nf-logbook.zip | head               # every path starts with nf-logbook/
```

## How client sites get updates

- Sites running 1.1.0 or later check by themselves; an available update shows
  in Dashboard → Updates, on the Plugins screen and on the Status tab.
- Auto-updates: Plugins screen → *Enable auto-updates* on the Noformat Logbook
  row (per site), or for a fleet: `wp plugin auto-updates enable nf-logbook`,
  or `add_filter( 'auto_update_plugin', fn( $u, $item ) => 'nf-logbook' === ( $item->slug ?? '' ) ? true : $u, 10, 2 );`
  in a site's mu-plugin. WordPress then installs the release within about 12
  hours of the next check, through the same SHA-256 check.
- Sites on 1.0.x have no updater: install 1.1.0 once by hand (upload the zip of
  the release); from then on updates arrive by themselves. Sites on 1.0.x or
  1.1.x move to 1.2.0 by hand once, see below.
- The update keeps the folder `nf-logbook`, the settings, the key and the
  event tables; the MU loader (`wp-content/mu-plugins/nf-logbook-loader.php`)
  is independent of the plugin folder and keeps working.

## Upgrading from 1.1.x (the rename)

1.2.0 renames the plugin from **Noformat Logger** (`noformat-logger/noformat-logger.php`,
asset `noformat-logger.zip`) to **Noformat Logbook** (`nf-logbook/nf-logbook.php`,
asset `nf-logbook.zip`). WordPress identifies a plugin by its folder and main
file, so a site on 1.1.x is **not** offered 1.2.0 (its updater looks for
`noformat-logger.zip` and a manifest with slug `noformat-logger`) and cannot
auto-update across the rename. Do it once per site:

1. Deactivate **Noformat Logger** (Plugins screen, or `wp plugin deactivate noformat-logger`).
2. Remove its folder **without** WordPress "Delete": *Delete* runs `uninstall.php`,
   which drops the log tables and settings when "delete data on uninstall" is on.
   Remove `wp-content/plugins/noformat-logger/` over SFTP/SSH instead
   (`rm -rf wp-content/plugins/noformat-logger`).
3. Install `nf-logbook.zip` of the 1.2.0 release (Plugins → Add New → Upload, or
   `wp plugin install nf-logbook.zip`) and activate **Noformat Logbook**
   (`wp plugin activate nf-logbook`).

The log, the hash chain, the API key and the settings carry over: the tables
(`{prefix}nflog_*`), options, constants and the REST namespace
(`noformat-logger/v1`) are unchanged, so the Controlroom keeps pulling with the
same key. An installed MU loader (`noformat-logger-loader.php`) keeps working;
`wp nflog install-mu-loader --force` replaces it with `nf-logbook-loader.php` and
removes the old file. Bookmarks to `tools.php?page=noformat-logger[&tab=…]`
redirect to `tools.php?page=nf-logbook`. From 1.2.0 on, updates arrive by
themselves again.
