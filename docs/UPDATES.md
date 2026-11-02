# Updates

Noformat Logger updates like any other plugin: it appears in **Dashboard →
Updates** and on the **Plugins** screen with *update now*, supports the
auto-update toggle, `wp plugin update noformat-logger` and the *View details*
modal. There is no update server of our own: the source of truth is the
**GitHub Releases** of the repository named by `NOFORMAT_LOGGER_UPDATE_REPO`
(default `wearenoformat/wp-noformat-logger`).

Code: `src/Update/` (`Updater`, `ReleaseClient`, `Release`, `Changelog`).

## How a site finds and installs an update

1. The plugin header carries `Update URI: https://github.com/wearenoformat/wp-noformat-logger`.
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
3. The release must carry two assets: `noformat-logger.zip` and `manifest.json`.
   The manifest is downloaded only when the release changed:

   ```json
   {
     "name": "Noformat Logger",
     "slug": "noformat-logger",
     "version": "1.1.0",
     "requires": "6.4",
     "requires_php": "8.1",
     "tested": "7.1",
     "released_at": "2026-11-02T10:00:00Z",
     "download_url": "https://github.com/wearenoformat/wp-noformat-logger/releases/download/v1.1.0/noformat-logger.zip",
     "sha256": "<64 hex characters: SHA-256 of noformat-logger.zip>",
     "changelog": "<the CHANGELOG.md section of this version, Markdown>"
   }
   ```

   The manifest's version must equal the tag's (`v1.1.0` → `1.1.0`), the slug
   must be `noformat-logger` and `sha256` must be 64 hex characters.
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

   > The Noformat Logger 1.2.0 package failed the SHA-256 check (manifest 3f2a…, download 9c41…). The update was refused.

7. `upgrader_source_selection` renames the unpacked folder to the installed
   one (`noformat-logger`) whatever the zip's top folder is (GitHub's
   "Source code" zips are `wp-noformat-logger-1.2.0/`), and refuses a package
   without `noformat-logger.php`.
8. `upgrader_process_complete` clears the cache. The update is logged as
   `plugin.updated` (with the version change) by `PluginThemeListener`; when
   that module is switched off, the updater logs it itself.

Nothing in the updater can break the site: every failure means "no update"
and is shown on **Tools → Noformat Logger → Status → Updates**, e.g.

```
Updates: 1.1.0 installed · latest 1.1.0 · source GitHub wearenoformat/wp-noformat-logger · last check 2026-11-02 10:00:00 UTC · ok
Updates: 1.1.0 installed · latest unknown · source GitHub wearenoformat/wp-noformat-logger · last check … · error: GitHub answered 404: the repository is private or the name is wrong (or it has no published release yet)
```

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
| `NOFORMAT_LOGGER_UPDATE_REPO` | `wearenoformat/wp-noformat-logger` | `owner/name` of the repository whose releases are used |
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
   `NOFORMAT_LOGGER_VERSION` in `noformat-logger.php`, and add a
   `## [x.y.z] - YYYY-MM-DD` section to `CHANGELOG.md`. Commit.
2. Run:

   ```sh
   tools/release.sh 1.2.0
   ```

   It checks that the header, the constant, the CHANGELOG section and the tag
   agree (and that `v1.2.0` does not exist), runs `composer check`, builds
   `noformat-logger.zip` (top folder `noformat-logger/`) from `git archive HEAD`
   honouring `.distignore` (no `vendor`, `tests`, `tools`, `composer.*`,
   `PLAN.md`, dot files …), computes its SHA-256, writes `manifest.json`
   (changelog = that version's CHANGELOG section), tags `v1.2.0`, pushes the tag
   and runs `gh release create v1.2.0 --notes-file … noformat-logger.zip manifest.json`.

`.github/workflows/release.yml` does the same on a pushed `v*` tag (PHP 8.3,
`composer install`, `composer check`, `tools/release.sh --ci`), so a tag pushed
by hand also produces a release. When `tools/release.sh` already created the
release, the workflow finds it and stops.

`tools/release.sh --local-only --version 9.9.9 --out <dir> [--download-url <url>]`
builds the two assets from the working tree without git or GitHub (a version
different from the header is written into the built copy);
`tests/integration/smoke-update.sh` uses it.

## Verifying a release

```sh
gh release view v1.2.0 --repo wearenoformat/wp-noformat-logger
gh release download v1.2.0 --repo wearenoformat/wp-noformat-logger -p noformat-logger.zip -p manifest.json -D /tmp/nflog-v1.2.0
cd /tmp/nflog-v1.2.0
shasum -a 256 noformat-logger.zip                 # must equal:
php -r 'echo json_decode(file_get_contents("manifest.json"))->sha256, "\n";'
unzip -l noformat-logger.zip | head               # every path starts with noformat-logger/
```

## How client sites get updates

- Sites running 1.1.0 or later check by themselves; an available update shows
  in Dashboard → Updates, on the Plugins screen and on the Status tab.
- Auto-updates: Plugins screen → *Enable auto-updates* on the Noformat Logger
  row (per site), or for a fleet: `wp plugin auto-updates enable noformat-logger`,
  or `add_filter( 'auto_update_plugin', fn( $u, $item ) => 'noformat-logger' === ( $item->slug ?? '' ) ? true : $u, 10, 2 );`
  in a site's mu-plugin. WordPress then installs the release within about 12
  hours of the next check, through the same SHA-256 check.
- Sites on 1.0.x have no updater: install 1.1.0 once by hand (upload the zip of
  the release); from then on updates arrive by themselves.
- The update keeps the folder `noformat-logger`, the settings, the key and the
  event tables; the MU loader (`wp-content/mu-plugins/noformat-logger-loader.php`)
  is independent of the plugin folder and keeps working.
