#!/usr/bin/env bash
# Update smoke test: the plugin updates itself the WordPress way from a release.
#
#   NFLOG_WP_DIR=$PWD/.local/wp NFLOG_PORT=8787 bash tests/integration/setup.sh
#   NFLOG_WP_DIR=$PWD/.local/wp NFLOG_PORT=8787 bash tests/integration/smoke-update.sh
#
# Builds release assets with tools/release.sh --local-only (fake version 9.9.9),
# serves them with `php -S 127.0.0.1:<port>`, points NOFORMAT_LOGGER_UPDATE_MANIFEST
# at them (self-hosted mode, GitHub is not contacted) and drives WP-CLI:
# update-check, plugin list --update=available, plugin update, then a tampered zip
# (one byte flipped, same manifest) that must be refused by the SHA-256 check.
#
# setup.sh symlinks the plugin into the site. An update deletes the plugin folder,
# so the symlink is replaced by a copy first (the repository is never touched)
# and restored afterwards, with the constant and the update caches removed.
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
OUT="$(mktemp -d "${TMPDIR:-/tmp}/nflog-smoke-update.XXXXXX")"
SRV="${OUT}/srv"
UPD_PORT="${NFLOG_UPDATE_PORT:-$((NFLOG_PORT + 111))}"
BASE="http://127.0.0.1:${UPD_PORT}"
SOURCE_VERSION="$(sed -n "s/^define( 'NOFORMAT_LOGGER_VERSION', '\([^']*\)' );/\1/p" "${PLUGIN_DIR}/noformat-logger.php")"
PASS=0
FAIL=0
# WP-CLI caches plugin packages by slug and version (~/.wp-cli/cache/plugin/noformat-logger-9.9.9.zip)
# and serves them through pre_http_request; a private cache keeps every run honest.
export WP_CLI_CACHE_DIR="${OUT}/wp-cli-cache"

ok()   { PASS=$((PASS + 1)); echo "  ok   $1"; }
fail() { FAIL=$((FAIL + 1)); echo "  FAIL $1"; }

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
  if [ -n "${SERVER_PID:-}" ]; then kill "${SERVER_PID}" 2>/dev/null || true; fi
  ${WP} config delete NOFORMAT_LOGGER_UPDATE_MANIFEST > /dev/null 2>&1 || true
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
"${PLUGIN_DIR}/tools/release.sh" --local-only --version 9.9.9 --out "${SRV}" --download-url "${BASE}/noformat-logger.zip" > "${OUT}/build.log" 2>&1 \
  && ok "assets built" || { fail "release.sh --local-only"; cat "${OUT}/build.log"; exit 1; }
SHA="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["sha256"];' "${SRV}/manifest.json")"
[ "$(shasum -a 256 "${SRV}/noformat-logger.zip" | awk '{print $1}')" = "${SHA}" ] && ok "manifest sha256 matches the zip" || fail "manifest sha256"
unzip -Z1 "${SRV}/noformat-logger.zip" | awk -F/ '{print $1}' | sort -u > "${OUT}/top.txt"
[ "$(cat "${OUT}/top.txt")" = "noformat-logger" ] && ok "zip top-level folder is noformat-logger/" || { fail "zip top folder"; cat "${OUT}/top.txt"; }
unzip -Z1 "${SRV}/noformat-logger.zip" | grep -Eq '^noformat-logger/(vendor|tests|tools|\.git|PLAN\.md|composer\.)' \
  && fail "zip honours .distignore" || ok "zip honours .distignore (no vendor, tests, tools, PLAN.md, composer files)"

echo "==> Serving them on ${BASE}"
php -S "127.0.0.1:${UPD_PORT}" -t "${SRV}" > "${OUT}/srv.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do
  curl -fs -o /dev/null "${BASE}/manifest.json" && break
  sleep 0.1
done

echo "==> Plugin folder as a copy, NOFORMAT_LOGGER_UPDATE_MANIFEST set"
install_copy
${WP} config set NOFORMAT_LOGGER_UPDATE_MANIFEST "${BASE}/manifest.json" --type=constant
[ "$(${WP} plugin get noformat-logger --field=version)" = "${SOURCE_VERSION}" ] && ok "installed ${SOURCE_VERSION} from a copy" || fail "baseline version"

${WP} nflog update-check > "${OUT}/check.txt" 2>&1 || true
grep -q "source manifest ${BASE}/manifest.json" "${OUT}/check.txt" && grep -q "latest 9.9.9 (update available)" "${OUT}/check.txt" && grep -q '· ok$' "${OUT}/check.txt" \
  && ok "wp nflog update-check: source, latest 9.9.9, ok" || { fail "update-check"; cat "${OUT}/check.txt"; }

${WP} plugin list --update=available --format=json > "${OUT}/available.json" 2>/dev/null || echo '[]' > "${OUT}/available.json"
php -r '$l = json_decode(file_get_contents($argv[1]), true); foreach ((array) $l as $p) { if ($p["name"] === "noformat-logger" && $p["update_version"] === "9.9.9") exit(0); } exit(1);' "${OUT}/available.json" \
  && ok "wp plugin list --update=available shows noformat-logger -> 9.9.9" || { fail "plugin list --update=available"; cat "${OUT}/available.json"; }
${WP} eval '$t = get_site_transient( "update_plugins" ); $r = $t->response["noformat-logger/noformat-logger.php"] ?? null; echo $r && "noformat-logger" === $r->slug && "https://github.com/wearenoformat/wp-noformat-logger" === $r->id ? "yes" : "no";' > "${OUT}/transient.txt" 2>&1 || true
[ "$(cat "${OUT}/transient.txt")" = "yes" ] && ok "update_plugins transient: response entry with slug and Update URI id" || { fail "update_plugins transient"; cat "${OUT}/transient.txt"; }
${WP} eval 'require_once ABSPATH . "wp-admin/includes/plugin-install.php"; $i = plugins_api( "plugin_information", [ "slug" => "noformat-logger" ] ); echo is_object( $i ) && "9.9.9" === $i->version && isset( $i->sections["changelog"] ) ? "yes" : "no";' > "${OUT}/details.txt" 2>&1 || true
[ "$(cat "${OUT}/details.txt")" = "yes" ] && ok "plugins_api (View details): version and changelog" || { fail "plugins_api"; cat "${OUT}/details.txt"; }

echo "==> wp plugin update noformat-logger"
${WP} plugin update noformat-logger > "${OUT}/update.txt" 2>&1 && ok "wp plugin update exits 0" || { fail "wp plugin update"; cat "${OUT}/update.txt"; }
[ "$(${WP} plugin get noformat-logger --field=version 2>/dev/null)" = "9.9.9" ] && ok "version 9.9.9 installed" || fail "version after update"
[ -d "${PLUGIN_PATH}" ] && [ ! -L "${PLUGIN_PATH}" ] && [ -f "${PLUGIN_PATH}/noformat-logger.php" ] && ok "folder is still noformat-logger" || fail "plugin folder after update"
ls "${WP_DIR}/wp-content/plugins" | grep -E '^noformat-logger.' > "${OUT}/strays.txt" && { fail "stray plugin folders"; cat "${OUT}/strays.txt"; } || ok "no stray noformat-logger-* folder"
[ "$(${WP} plugin get noformat-logger --field=status 2>/dev/null)" = "active" ] && ok "plugin still active" || fail "plugin active after update"
${WP} nflog status > "${OUT}/status.txt" 2>&1 && grep -Eq 'version[[:space:]]+9\.9\.9' "${OUT}/status.txt" && grep -Eq 'storage[[:space:]]+available' "${OUT}/status.txt" \
  && ok "wp nflog status healthy on 9.9.9" || { fail "nflog status"; head -20 "${OUT}/status.txt"; }
${WP} eval 'foreach ( \Noformat\Logger\Plugin::instance()->repository()->latest( 50 ) as $e ) { if ( "plugin.updated" === $e["action"] && "noformat-logger" === $e["object_id"] ) { echo $e["changes"]; break; } }' > "${OUT}/event.txt" 2>&1 || true
grep -q '9.9.9' "${OUT}/event.txt" && grep -q "${SOURCE_VERSION}" "${OUT}/event.txt" && ok "plugin.updated logged (${SOURCE_VERSION} -> 9.9.9)" || { fail "plugin.updated event"; cat "${OUT}/event.txt"; }
${WP} nflog verify > /dev/null 2>&1 && ok "chain intact after the update" || fail "chain after update"

echo "==> Tampered package (one byte flipped, same manifest)"
install_copy
rm -rf "${WP_CLI_CACHE_DIR}"   # the verified 9.9.9 zip from the first update would be served again
${WP} transient delete noformat_logger_update > /dev/null 2>&1 || true
php -r '$f = $argv[1]; $b = file_get_contents($f); $i = intdiv(strlen($b), 2); $b[$i] = chr(ord($b[$i]) ^ 0x01); file_put_contents($f, $b);' "${SRV}/noformat-logger.zip"
[ "$(shasum -a 256 "${SRV}/noformat-logger.zip" | awk '{print $1}')" != "${SHA}" ] && ok "zip tampered" || fail "tamper"
${WP} nflog update-check > "${OUT}/check2.txt" 2>&1 || true
grep -q "latest 9.9.9 (update available)" "${OUT}/check2.txt" && ok "update offered again from ${SOURCE_VERSION}" || { fail "update-check before tampered update"; cat "${OUT}/check2.txt"; }
${WP} plugin update noformat-logger > "${OUT}/update2.txt" 2>&1 && fail "tampered update refused (exit status)" || ok "tampered update: wp plugin update fails"
grep -q 'failed the SHA-256 check' "${OUT}/update2.txt" && ok "refused with the sha256 error" || { fail "sha256 error message"; cat "${OUT}/update2.txt"; }
[ "$(${WP} plugin get noformat-logger --field=version 2>/dev/null)" = "${SOURCE_VERSION}" ] && [ "$(${WP} plugin get noformat-logger --field=status 2>/dev/null)" = "active" ] \
  && ok "still ${SOURCE_VERSION}, active, folder intact" || fail "state after the refused update"

echo "==> update-check prints the source and never fatals"
${WP} nflog update-check > /dev/null 2>&1 || true
kill "${SERVER_PID}" 2>/dev/null || true
SERVER_PID=""
${WP} nflog update-check > "${OUT}/check3.txt" 2>&1 && ok "update-check exits 0 with the manifest host down" || { fail "update-check exit status"; cat "${OUT}/check3.txt"; }
grep -q "source manifest ${BASE}/manifest.json" "${OUT}/check3.txt" && grep -q 'error: The manifest URL could not be reached' "${OUT}/check3.txt" && grep -q 'latest 9.9.9' "${OUT}/check3.txt" \
  && ok "update-check: source, error reason, cached latest kept" || { fail "update-check output with the host down"; cat "${OUT}/check3.txt"; }

echo
echo "smoke-update: ${PASS} passed, ${FAIL} failed"
[ "${FAIL}" -eq 0 ]
