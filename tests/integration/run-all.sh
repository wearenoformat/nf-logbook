#!/usr/bin/env bash
# Full integration run on ONE fresh site: setup.sh, then every smoke script in
# sequence against that same site, with a pass/fail summary per script.
#
#   NFLOG_WP_DIR   site directory (default: $PWD/.local/wp-all)
#   NFLOG_PORT     port (default: 8794)
#   NFLOG_FRESH    1 (default) deletes the site's database and plugin state first;
#                  0 reuses an existing site as is.
#
# Exit status: 0 when every script passed.
set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
export NFLOG_WP_DIR="${NFLOG_WP_DIR:-$PWD/.local/wp-all}"
export NFLOG_PORT="${NFLOG_PORT:-8794}"
HERE="${PLUGIN_DIR}/tests/integration"
LOG_DIR="${NFLOG_WP_DIR}/.nflog-run-all"

if [ "${NFLOG_FRESH:-1}" = "1" ] && [ -d "${NFLOG_WP_DIR}" ]; then
  echo "==> Resetting ${NFLOG_WP_DIR} (database, config, state)"
  rm -rf "${NFLOG_WP_DIR}/wp-content/database" "${NFLOG_WP_DIR}/wp-config.php" \
    "${NFLOG_WP_DIR}/.nflog-env" "${NFLOG_WP_DIR}"/.nflog-*-last-run \
    "${NFLOG_WP_DIR}/wp-content/mu-plugins" "${NFLOG_WP_DIR}/wp-content/debug.log" \
    "${NFLOG_WP_DIR}/wp-content/plugins/advanced-custom-fields"
fi

echo "==> setup.sh (${NFLOG_WP_DIR}, port ${NFLOG_PORT})"
if ! bash "${HERE}/setup.sh"; then
  echo "setup.sh failed" >&2
  exit 1
fi
mkdir -p "${LOG_DIR}"

declare -a NAMES=() RESULTS=() LINES=()
for script in smoke.sh smoke-content.sh smoke-privacy.sh smoke-system.sh smoke-errors.sh smoke-admin.sh smoke-update.sh smoke-update-private.sh; do
  echo
  echo "==> ${script}"
  log="${LOG_DIR}/${script%.sh}.log"
  start=$(date +%s)
  bash "${HERE}/${script}" 2>&1 | tee "${log}"
  status=${PIPESTATUS[0]}
  took=$(( $(date +%s) - start ))
  NAMES+=("${script}")
  RESULTS+=("$([ "${status}" -eq 0 ] && echo PASS || echo "FAIL(${status})")")
  LINES+=("$(grep -E '[0-9]+ passed, [0-9]+ failed' "${log}" | tail -1) [${took}s]")
done

echo
echo "==> run-all summary (${NFLOG_WP_DIR})"
FAILED=0
for i in "${!NAMES[@]}"; do
  printf '  %-26s %-8s %s\n' "${NAMES[$i]}" "${RESULTS[$i]}" "${LINES[$i]}"
  [ "${RESULTS[$i]}" = "PASS" ] || FAILED=1
done
echo "  logs: ${LOG_DIR}"
exit "${FAILED}"
