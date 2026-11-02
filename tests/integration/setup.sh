#!/usr/bin/env bash
# Creates a throwaway WordPress on SQLite with this plugin symlinked and active.
#
#   NFLOG_WP_DIR   target directory (default: $PWD/.local/wp)
#   NFLOG_PORT     port used by smoke.sh (default: 8787)
#
# Requires: WP-CLI, PHP 8.1+ with pdo_sqlite and sodium, curl, unzip.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP_DIR="${NFLOG_WP_DIR:-$PWD/.local/wp}"
PORT="${NFLOG_PORT:-8787}"
URL="http://127.0.0.1:${PORT}"
KEY_ID="${NFLOG_KEY_ID:-nfk_SmokeTest0000001}"
SECRET="${NFLOG_SECRET:-$(php -r 'echo rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=");')}"
WP="wp --path=${WP_DIR} --quiet"

php -r 'exit(extension_loaded("pdo_sqlite") && extension_loaded("sodium") ? 0 : 1);' \
  || { echo "PHP needs pdo_sqlite and sodium" >&2; exit 1; }

mkdir -p "${WP_DIR}"
if [ ! -f "${WP_DIR}/wp-includes/version.php" ]; then
  echo "==> Downloading WordPress into ${WP_DIR}"
  wp core download --path="${WP_DIR}" --skip-content --quiet
  mkdir -p "${WP_DIR}/wp-content/plugins" "${WP_DIR}/wp-content/themes"
fi

SQLITE_DIR="${WP_DIR}/wp-content/plugins/sqlite-database-integration"
if [ ! -f "${SQLITE_DIR}/load.php" ]; then
  echo "==> Installing sqlite-database-integration from wordpress.org"
  TMP_ZIP="$(mktemp -t nflog-sqlite).zip"
  curl -fsSL -o "${TMP_ZIP}" "https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip"
  unzip -q -o "${TMP_ZIP}" -d "${WP_DIR}/wp-content/plugins"
  rm -f "${TMP_ZIP}"
fi
# db.php drop-in (same placeholder replacement the plugin performs on activation).
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#${SQLITE_DIR}#g" \
    -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" \
    "${SQLITE_DIR}/db.copy" > "${WP_DIR}/wp-content/db.php"

if [ ! -f "${WP_DIR}/wp-config.php" ]; then
  echo "==> Writing wp-config.php"
  wp config create --path="${WP_DIR}" --dbname=wordpress --dbuser=unused --dbpass=unused --dbhost=localhost --skip-check --quiet
fi
${WP} config set DB_ENGINE sqlite --type=constant
${WP} config set WP_ENVIRONMENT_TYPE local --type=constant
${WP} config set WP_DEBUG true --raw --type=constant
${WP} config set WP_DEBUG_DISPLAY false --raw --type=constant
${WP} config set WP_DEBUG_LOG true --raw --type=constant
${WP} config set NOFORMAT_LOGGER_ALLOW_HTTP true --raw --type=constant
${WP} config set NOFORMAT_LOGGER_KEY_ID "${KEY_ID}" --type=constant
# Keep an existing secret on re-runs so a running collector keeps working.
if ! ${WP} config has NOFORMAT_LOGGER_SECRET 2>/dev/null; then
  ${WP} config set NOFORMAT_LOGGER_SECRET "${SECRET}" --type=constant
fi
SECRET="$(${WP} config get NOFORMAT_LOGGER_SECRET)"

if ! ${WP} core is-installed 2>/dev/null; then
  echo "==> Installing site at ${URL}"
  ${WP} core install --url="${URL}" --title="Noformat Logger smoke" --admin_user=admin \
    --admin_password="$(php -r 'echo bin2hex(random_bytes(12));')" --admin_email=admin@example.com --skip-email
fi
${WP} option update home "${URL}"
${WP} option update siteurl "${URL}"
${WP} rewrite structure '/%postname%/'

THEME_DIR="${WP_DIR}/wp-content/themes"
if [ -z "$(ls -A "${THEME_DIR}" 2>/dev/null)" ]; then
  ${WP} theme install twentytwentyfour --activate || true
fi

ln -sfn "${PLUGIN_DIR}" "${WP_DIR}/wp-content/plugins/noformat-logger"
${WP} plugin activate noformat-logger

cat > "${WP_DIR}/.nflog-env" <<ENV
NFLOG_SITE=${URL}
NFLOG_PORT=${PORT}
NFLOG_KEY_ID=${KEY_ID}
NFLOG_SECRET=${SECRET}
ENV
chmod 600 "${WP_DIR}/.nflog-env"

echo "==> Ready: ${URL} (credentials in ${WP_DIR}/.nflog-env)"
wp --path="${WP_DIR}" nflog status
