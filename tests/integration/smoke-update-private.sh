#!/usr/bin/env bash
# Update smoke test, private repository (token) path, with GitHub emulated.
#
#   NFLOG_WP_DIR=$PWD/.local/wp NFLOG_PORT=8787 bash tests/integration/setup.sh
#   NFLOG_WP_DIR=$PWD/.local/wp NFLOG_PORT=8787 bash tests/integration/smoke-update-private.sh
#
# tests/integration/github-emulator.php is installed as a mu-plugin: a
# pre_http_request filter answers api.github.com, github.com and
# release-assets.githubusercontent.com like GitHub does for a PRIVATE repository
# (anonymous 404, bad token 401, ETag / 304, asset API 302 to pre-signed storage
# that answers 400 when the token is forwarded, browser download URLs 404).
# Nothing reaches GitHub and no real token is used. Every intercepted request is
# logged as one JSON line, which the checks below read.
#
# Phases: no token, wrong token, correct token (check, ETag revalidation,
# wp plugin update), tampered zip (SHA-256 refusal), token never written.
#
# Like smoke-update.sh the plugin symlink is replaced by a copy for the update
# and restored afterwards; the constants, the mu-plugin and the caches are removed.
# Needs network access to api.wordpress.org: WordPress only asks the
# update_plugins_{hostname} filters after its own plugin update request succeeded.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP_DIR="${NFLOG_WP_DIR:-$PWD/.local/wp}"
[ -f "${WP_DIR}/.nflog-env" ] || { echo "Run tests/integration/setup.sh first" >&2; exit 1; }
# shellcheck disable=SC1091
source "${WP_DIR}/.nflog-env"
WP="wp --path=${WP_DIR}"
PLUGIN_PATH="${WP_DIR}/wp-content/plugins/noformat-logger"
MU_FILE="${WP_DIR}/wp-content/mu-plugins/nflog-github-emulator.php"
OUT="$(mktemp -d "${TMPDIR:-/tmp}/nflog-smoke-update-private.XXXXXX")"
SRV="${OUT}/srv"
LOG="${OUT}/github.jsonl"
REPO="nflog-emu/private-plugin"
TOKEN="ghp_EmulatorTestToken000000000000000000"
WRONG_TOKEN="ghp_WrongToken111111111111111111111111"
ETAG='"nflog-emu-etag-1"'
SOURCE_VERSION="$(sed -n "s/^define( 'NOFORMAT_LOGGER_VERSION', '\([^']*\)' );/\1/p" "${PLUGIN_DIR}/noformat-logger.php")"
CONSTANTS="NOFORMAT_LOGGER_UPDATE_REPO NOFORMAT_LOGGER_UPDATE_TOKEN NFLOG_GH_EMU_REPO NFLOG_GH_EMU_TOKEN NFLOG_GH_EMU_DIR NFLOG_GH_EMU_LOG"
PASS=0
FAIL=0
# Private WP-CLI package cache (see smoke-update.sh).
export WP_CLI_CACHE_DIR="${OUT}/wp-cli-cache"

ok()   { PASS=$((PASS + 1)); echo "  ok   $1"; }
fail() { FAIL=$((FAIL + 1)); echo "  FAIL $1"; }

# Number of logged requests for which the PHP expression over $l (one decoded line) is true.
log_count() {
  [ -f "${LOG}" ] || { echo 0; return; }
  php -r '$n = 0; foreach (file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) { $l = json_decode($line, true); if (is_array($l) && ('"$1"')) $n++; } echo $n;' "${LOG}"
}
log_reset() { : > "${LOG}"; }
log_dump() { [ -f "${LOG}" ] && sed 's/^/       /' "${LOG}"; }

API_LATEST="https://api.github.com/repos/${REPO}/releases/latest"
ASSET_ZIP="https://api.github.com/repos/${REPO}/releases/assets/101"
ASSET_MANIFEST="https://api.github.com/repos/${REPO}/releases/assets/102"
BROWSER_PREFIX="https://github.com/${REPO}/releases/download/"
OBJECTS_PREFIX="https://release-assets.githubusercontent.com/"

# The plugin folder as a real copy of the working tree (never a symlink during an update).
install_copy() {
  if [ -L "${PLUGIN_PATH}" ]; then
    rm "${PLUGIN_PATH}"
  elif [ -d "${PLUGIN_PATH}" ]; then
    rm -rf "${PLUGIN_PATH}"
  fi
  mkdir -p "${PLUGIN_PATH}"
  rsync -a --exclude .git --exclude-from="${PLUGIN_DIR}/.distignore" "${PLUGIN_DIR}/" "${PLUGIN_PATH}/"
  [ ! -L "${PLUGIN_PATH}" ] || { echo "plugin folder is still a symlink, refusing to continue" >&2; exit 1; }
  ${WP} plugin activate noformat-logger > /dev/null 2>&1 || true
}

cleanup() {
  rm -f "${MU_FILE}"
  for c in ${CONSTANTS}; do ${WP} config delete "${c}" > /dev/null 2>&1 || true; done
  if [ ! -L "${PLUGIN_PATH}" ]; then
    rm -rf "${PLUGIN_PATH}"
    ln -sfn "${PLUGIN_DIR}" "${PLUGIN_PATH}"
  fi
  ${WP} plugin activate noformat-logger > /dev/null 2>&1 || true
  ${WP} transient delete noformat_logger_update > /dev/null 2>&1 || true
  ${WP} transient delete update_plugins --network > /dev/null 2>&1 || true
  rm -rf "${OUT}"
}
trap cleanup EXIT

echo "==> Building release assets (tools/release.sh --local-only --version 9.9.9)"
"${PLUGIN_DIR}/tools/release.sh" --local-only --version 9.9.9 --out "${SRV}" --download-url "${BROWSER_PREFIX}v9.9.9/noformat-logger.zip" > "${OUT}/build.log" 2>&1 \
  && ok "assets built" || { fail "release.sh --local-only"; cat "${OUT}/build.log"; exit 1; }
SHA="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["sha256"];' "${SRV}/manifest.json")"
[ "$(shasum -a 256 "${SRV}/noformat-logger.zip" | awk '{print $1}')" = "${SHA}" ] && ok "manifest sha256 matches the zip" || fail "manifest sha256"

echo "==> Emulator mu-plugin, plugin folder as a copy, NOFORMAT_LOGGER_UPDATE_REPO=${REPO}"
mkdir -p "$(dirname "${MU_FILE}")"
cp "${PLUGIN_DIR}/tests/integration/github-emulator.php" "${MU_FILE}"
install_copy
${WP} config set NOFORMAT_LOGGER_UPDATE_REPO "${REPO}" --type=constant > /dev/null
${WP} config set NFLOG_GH_EMU_REPO "${REPO}" --type=constant > /dev/null
${WP} config set NFLOG_GH_EMU_TOKEN "${TOKEN}" --type=constant > /dev/null
${WP} config set NFLOG_GH_EMU_DIR "${SRV}" --type=constant > /dev/null
${WP} config set NFLOG_GH_EMU_LOG "${LOG}" --type=constant > /dev/null
${WP} config delete NOFORMAT_LOGGER_UPDATE_TOKEN > /dev/null 2>&1 || true
${WP} config delete NOFORMAT_LOGGER_UPDATE_MANIFEST > /dev/null 2>&1 || true
${WP} transient delete noformat_logger_update > /dev/null 2>&1 || true
[ "$(${WP} plugin get noformat-logger --field=version)" = "${SOURCE_VERSION}" ] && ok "installed ${SOURCE_VERSION} from a copy" || fail "baseline version"
${WP} eval 'echo has_filter( "pre_http_request" ) ? "yes" : "no";' 2>/dev/null | grep -q yes && ok "emulator mu-plugin loaded" || fail "emulator mu-plugin not loaded"

echo "==> No token: the private repository answers 404"
log_reset
${WP} nflog update-check > "${OUT}/check-notoken.txt" 2>&1 || true
grep -q "repository is private" "${OUT}/check-notoken.txt" && ok "update-check: \"repository is private\"" || { fail "update-check without token"; cat "${OUT}/check-notoken.txt"; }
${WP} plugin list --update=available --field=name > "${OUT}/available-notoken.txt" 2>/dev/null || true
grep -qx noformat-logger "${OUT}/available-notoken.txt" && fail "plugin list --update=available lists noformat-logger without a token" || ok "no update offered without a token"
[ "$(log_count "\$l['url'] === '${API_LATEST}' && ! \$l['has_auth'] && \$l['status'] === 404")" -ge 1 ] \
  && ok "log: anonymous /releases/latest answered 404" || { fail "log: anonymous API request"; log_dump; }
[ "$(log_count "\$l['url'] !== '${API_LATEST}'")" = "0" ] && ok "log: no asset or storage request without a token" || { fail "log: unexpected requests without a token"; log_dump; }

echo "==> Wrong token: 401"
${WP} config set NOFORMAT_LOGGER_UPDATE_TOKEN "${WRONG_TOKEN}" --type=constant > /dev/null
${WP} transient delete noformat_logger_update > /dev/null 2>&1 || true
log_reset
${WP} nflog update-check > "${OUT}/check-wrong.txt" 2>&1 || true
grep -q "401" "${OUT}/check-wrong.txt" && grep -q "invalid or expired" "${OUT}/check-wrong.txt" \
  && ok "update-check: 401, token invalid or expired" || { fail "update-check with a wrong token"; cat "${OUT}/check-wrong.txt"; }
${WP} transient get noformat_logger_update --format=json > "${OUT}/transient-wrong.json" 2>&1 || true
php -r 'exit("github:" . $argv[2] . ":token" === (json_decode(file_get_contents($argv[1]), true)["source"] ?? "") ? 0 : 1);' "${OUT}/transient-wrong.json" "${REPO}" && ok "transient source github:${REPO}:token" || { fail "transient source"; cat "${OUT}/transient-wrong.json"; }
cat "${OUT}/check-wrong.txt" "${OUT}/transient-wrong.json" | grep -q "${WRONG_TOKEN}" && fail "wrong token printed or stored" || ok "wrong token neither printed nor stored"
[ "$(log_count "\$l['url'] === '${API_LATEST}' && \$l['has_auth'] && ! \$l['auth_ok'] && \$l['status'] === 401")" -ge 1 ] \
  && ok "log: Bearer with the wrong token answered 401" || { fail "log: wrong-token API request"; log_dump; }

echo "==> Correct token"
${WP} config set NOFORMAT_LOGGER_UPDATE_TOKEN "${TOKEN}" --type=constant > /dev/null
${WP} transient delete noformat_logger_update > /dev/null 2>&1 || true
log_reset
${WP} nflog update-check > "${OUT}/check-token.txt" 2>&1 || true
grep -q "latest 9.9.9 (update available)" "${OUT}/check-token.txt" && grep -q '· ok$' "${OUT}/check-token.txt" && grep -q "source GitHub ${REPO} (with token)" "${OUT}/check-token.txt" \
  && ok "update-check: latest 9.9.9, source ${REPO} (with token), ok" || { fail "update-check with the token"; cat "${OUT}/check-token.txt"; }
${WP} transient get noformat_logger_update --format=json > "${OUT}/transient-token.json" 2>&1 || true
php -r '$t = json_decode(file_get_contents($argv[1]), true); exit(is_array($t) && "github:'"${REPO}"':token" === $t["source"] && "9.9.9" === ($t["release"]["version"] ?? "") && $argv[2] === ($t["release"]["package"] ?? "") && $argv[3] === $t["etag"] ? 0 : 1);' "${OUT}/transient-token.json" "${ASSET_ZIP}" "${ETAG}" \
  && ok "transient: source, version 9.9.9, package = asset API URL, ETag stored" || { fail "transient after the check"; cat "${OUT}/transient-token.json"; }
[ "$(log_count "\$l['url'] === '${API_LATEST}' && \$l['auth_ok'] && \$l['accept'] === 'application/vnd.github+json' && \$l['api_version'] === '2022-11-28' && \$l['status'] === 200")" -ge 1 ] \
  && ok "log: /releases/latest with Bearer, Accept and API version, 200" || { fail "log: authenticated API request"; log_dump; }
[ "$(log_count "\$l['url'] === '${ASSET_MANIFEST}' && \$l['auth_ok'] && \$l['accept'] === 'application/octet-stream' && \$l['status'] === 302")" -ge 1 ] \
  && ok "log: manifest via asset API 102 (octet-stream, Bearer) -> 302" || { fail "log: manifest asset request"; log_dump; }
[ "$(log_count "str_starts_with(\$l['url'], '${OBJECTS_PREFIX}nflog-emu/manifest.json') && ! \$l['has_auth'] && \$l['status'] === 200")" -ge 1 ] \
  && ok "log: redirect followed to storage without Authorization, 200" || { fail "log: storage request for the manifest"; log_dump; }
[ "$(log_count "str_starts_with(\$l['url'], '${OBJECTS_PREFIX}') && \$l['has_auth']")" = "0" ] && ok "log: the token never reached the storage host" || { fail "log: token sent to storage"; log_dump; }
[ "$(log_count "str_starts_with(\$l['url'], '${BROWSER_PREFIX}')")" = "0" ] && ok "log: no browser_download_url request" || { fail "log: browser download URL used"; log_dump; }
${WP} plugin list --update=available --format=json > "${OUT}/available.json" 2>/dev/null || echo '[]' > "${OUT}/available.json"
php -r '$l = json_decode(file_get_contents($argv[1]), true); foreach ((array) $l as $p) { if ($p["name"] === "noformat-logger" && $p["update_version"] === "9.9.9") exit(0); } exit(1);' "${OUT}/available.json" \
  && ok "wp plugin list --update=available shows noformat-logger -> 9.9.9" || { fail "plugin list --update=available"; cat "${OUT}/available.json"; }

echo "==> ETag revalidation (stale cache, scheduled-style check)"
# `wp nflog update-check` clears the cache (no ETag). A scheduled check reuses it:
# age the cached answer past 6 hours and let WordPress refresh its update list.
${WP} eval '$t = get_transient( "noformat_logger_update" ); $t["checked_at"] = time() - 7 * HOUR_IN_SECONDS; set_transient( "noformat_logger_update", $t, WEEK_IN_SECONDS );' > /dev/null
log_reset
${WP} eval 'wp_clean_plugins_cache( true ); wp_update_plugins();' > "${OUT}/etag.txt" 2>&1 || true
[ "$(log_count "\$l['url'] === '${API_LATEST}' && \$l['auth_ok'] && \$l['if_none_match'] === '${ETAG}' && \$l['status'] === 304")" -ge 1 ] \
  && ok "log: If-None-Match ${ETAG} answered 304" || { fail "log: ETag revalidation"; cat "${OUT}/etag.txt"; log_dump; }
[ "$(log_count "\$l['url'] !== '${API_LATEST}'")" = "0" ] && ok "log: 304 does not re-read the manifest" || { fail "log: assets read after a 304"; log_dump; }
${WP} nflog update-check --cached > "${OUT}/check-etag.txt" 2>&1 || true
grep -q "latest 9.9.9 (update available)" "${OUT}/check-etag.txt" && grep -q '· ok$' "${OUT}/check-etag.txt" \
  && ok "cached answer still 9.9.9, ok, after the 304" || { fail "state after the 304"; cat "${OUT}/check-etag.txt"; }
${WP} eval '$t = get_transient( "noformat_logger_update" ); echo time() - $t["checked_at"] < 300 ? "fresh" : "stale";' > "${OUT}/fresh.txt" 2>&1 || true
[ "$(cat "${OUT}/fresh.txt")" = "fresh" ] && ok "304 refreshed last check" || { fail "checked_at after the 304"; cat "${OUT}/fresh.txt"; }

echo "==> wp plugin update noformat-logger (token path)"
log_reset
${WP} plugin update noformat-logger > "${OUT}/update.txt" 2>&1 && ok "wp plugin update exits 0" || { fail "wp plugin update"; cat "${OUT}/update.txt"; }
[ "$(${WP} plugin get noformat-logger --field=version 2>/dev/null)" = "9.9.9" ] && ok "version 9.9.9 installed" || fail "version after update"
[ -d "${PLUGIN_PATH}" ] && [ ! -L "${PLUGIN_PATH}" ] && [ -f "${PLUGIN_PATH}/noformat-logger.php" ] && ok "folder is still noformat-logger" || fail "plugin folder after update"
ls "${WP_DIR}/wp-content/plugins" | grep -E '^noformat-logger.' > "${OUT}/strays.txt" && { fail "stray plugin folders"; cat "${OUT}/strays.txt"; } || ok "no stray noformat-logger-* folder"
[ "$(${WP} plugin get noformat-logger --field=status 2>/dev/null)" = "active" ] && ok "plugin still active" || fail "plugin active after update"
${WP} eval 'foreach ( \Noformat\Logger\Plugin::instance()->repository()->latest( 50 ) as $e ) { if ( "plugin.updated" === $e["action"] && "noformat-logger" === $e["object_id"] ) { echo $e["changes"]; break; } }' > "${OUT}/event.txt" 2>&1 || true
grep -q '9.9.9' "${OUT}/event.txt" && grep -q "${SOURCE_VERSION}" "${OUT}/event.txt" && ok "plugin.updated logged (${SOURCE_VERSION} -> 9.9.9)" || { fail "plugin.updated event"; cat "${OUT}/event.txt"; }
${WP} nflog verify > /dev/null 2>&1 && ok "chain intact after the update" || fail "chain after update"
[ "$(log_count "\$l['url'] === '${ASSET_ZIP}' && \$l['auth_ok'] && \$l['accept'] === 'application/octet-stream' && \$l['status'] === 302")" -ge 1 ] \
  && ok "log: package via asset API 101 (octet-stream, Bearer) -> 302" || { fail "log: package asset request"; log_dump; }
[ "$(log_count "str_starts_with(\$l['url'], '${OBJECTS_PREFIX}nflog-emu/noformat-logger.zip') && \$l['stream'] && ! \$l['has_auth'] && \$l['status'] === 200")" -ge 1 ] \
  && ok "log: package streamed from storage without Authorization, 200" || { fail "log: package storage request"; log_dump; }
[ "$(log_count "str_starts_with(\$l['url'], '${BROWSER_PREFIX}') || (str_starts_with(\$l['url'], '${OBJECTS_PREFIX}') && \$l['has_auth'])")" = "0" ] \
  && ok "log: never fetched from github.com/.../download/, token never sent to storage" || { fail "log: package fetched the wrong way"; log_dump; }

echo "==> Tampered package (one byte flipped, same manifest)"
install_copy
rm -rf "${WP_CLI_CACHE_DIR}"   # the verified 9.9.9 zip from the first update would be served again
${WP} transient delete noformat_logger_update > /dev/null 2>&1 || true
php -r '$f = $argv[1]; $b = file_get_contents($f); $i = intdiv(strlen($b), 2); $b[$i] = chr(ord($b[$i]) ^ 0x01); file_put_contents($f, $b);' "${SRV}/noformat-logger.zip"
[ "$(shasum -a 256 "${SRV}/noformat-logger.zip" | awk '{print $1}')" != "${SHA}" ] && ok "zip tampered" || fail "tamper"
${WP} nflog update-check > "${OUT}/check-tamper.txt" 2>&1 || true
grep -q "latest 9.9.9 (update available)" "${OUT}/check-tamper.txt" && ok "update offered again from ${SOURCE_VERSION}" || { fail "update-check before tampered update"; cat "${OUT}/check-tamper.txt"; }
${WP} plugin update noformat-logger > "${OUT}/update-tamper.txt" 2>&1 && fail "tampered update refused (exit status)" || ok "tampered update: wp plugin update fails"
grep -q 'SHA-256' "${OUT}/update-tamper.txt" && ok "refused with the SHA-256 error" || { fail "sha256 error message"; cat "${OUT}/update-tamper.txt"; }
[ "$(${WP} plugin get noformat-logger --field=version 2>/dev/null)" = "${SOURCE_VERSION}" ] && [ "$(${WP} plugin get noformat-logger --field=status 2>/dev/null)" = "active" ] \
  && ok "still ${SOURCE_VERSION}, active" || fail "state after the refused update"

echo "==> The token is never written"
${WP} transient get noformat_logger_update --format=json > "${OUT}/transient-final.json" 2>&1 || true
FILES=("${OUT}"/check-*.txt "${OUT}"/update*.txt "${OUT}"/transient-*.json "${OUT}/etag.txt")
[ -f "${WP_DIR}/wp-content/debug.log" ] && FILES+=("${WP_DIR}/wp-content/debug.log")
HITS="$(cat "${FILES[@]}" | grep -c -e "${TOKEN}" -e "${WRONG_TOKEN}" || true)"
[ "${HITS}" = "0" ] && ok "no token in ${#FILES[@]} files (update-check / update outputs, transients, debug.log)" || { fail "token found ${HITS} times"; grep -l -e "${TOKEN}" -e "${WRONG_TOKEN}" "${FILES[@]}"; }
${WP} eval 'global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", "%" . $wpdb->esc_like( "ghp_" ) . "%" ) );' > "${OUT}/db.txt" 2>&1 || true
[ "$(cat "${OUT}/db.txt")" = "0" ] && ok "no token in the options table" || { fail "options table holds a token"; cat "${OUT}/db.txt"; }

echo
echo "smoke-update-private: ${PASS} passed, ${FAIL} failed"
[ "${FAIL}" -eq 0 ]
