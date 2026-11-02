#!/usr/bin/env bash
# Phase B smoke test for the errors / stability / config audit / integrity / health group.
# Run against the site created by setup.sh (same env: NFLOG_WP_DIR, NFLOG_PORT).
#
#   NFLOG_WP_DIR=$PWD/.local/wp-errors-group NFLOG_PORT=8793 bash tests/integration/setup.sh
#   NFLOG_WP_DIR=$PWD/.local/wp-errors-group NFLOG_PORT=8793 bash tests/integration/smoke-errors.sh
#
# A throwaway mu-plugin (zz-nflog-errors-test.php) triggers errors on demand via
# ?nflog_test=<case>; it is removed again on exit, as is the MU loader.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP_DIR="${NFLOG_WP_DIR:-$PWD/.local/wp}"
[ -f "${WP_DIR}/.nflog-env" ] || { echo "Run tests/integration/setup.sh first" >&2; exit 1; }
# shellcheck disable=SC1091
source "${WP_DIR}/.nflog-env"
export NFLOG_SITE NFLOG_KEY_ID NFLOG_SECRET
WP="wp --path=${WP_DIR}"
FETCH="node ${PLUGIN_DIR}/tools/fetch-events.mjs"
OUT="$(mktemp -d -t nflog-smoke-errors)"
MU_DIR="${WP_DIR}/wp-content/mu-plugins"
TEST_MU="${MU_DIR}/zz-nflog-errors-test.php"
QUERIES_MU="${MU_DIR}/aa-nflog-savequeries.php"
QUERIES_OUT="${WP_DIR}/wp-content/nflog-queries.json"
LOADER="${MU_DIR}/noformat-logger-loader.php"
PASS=0
FAIL=0

cleanup() {
  if [ -n "${SERVER_PID:-}" ]; then kill "${SERVER_PID}" 2>/dev/null || true; fi
  pkill -f "127.0.0.1:${NFLOG_PORT}" 2>/dev/null || true
  rm -f "${TEST_MU}" "${LOADER}" "${QUERIES_MU}" "${QUERIES_OUT}"
  if [ "${KEEP_OUT:-0}" = "1" ]; then echo "output kept in ${OUT}"; else rm -rf "${OUT}"; fi
}
trap cleanup EXIT

ok()   { PASS=$((PASS + 1)); echo "  ok   $1"; }
fail() { FAIL=$((FAIL + 1)); echo "  FAIL $1"; }
# assert <name> <file> <js expression over `r` (parsed JSON) and `E` (events array, if any)>
assert() {
  if node -e "
    const fs=require('fs');
    const r=JSON.parse(fs.readFileSync(process.argv[1],'utf8'));
    const E=(r && r.body && Array.isArray(r.body.events)) ? r.body.events : [];
    const A=(a)=>E.filter(e=>e.action===a);
    if(!(${3})){process.exit(1)}" "$2"; then ok "$1"; else fail "$1"; fi
}
# Events written by this run only (after the id recorded at start).
fetch_events() { ${FETCH} --endpoint events --after "${BEFORE_MAX:-0}" --limit 500 --all > "$1"; }
# Extract the JSON document printed by a WP-CLI command (skips warnings before it).
cli_json() { node -e "const t=require('fs').readFileSync(0,'utf8'); const i=t.indexOf('{'); process.stdout.write(t.slice(i, t.lastIndexOf('}')+1));"; }

mkdir -p "${MU_DIR}"
cat > "${TEST_MU}" <<'PHP'
<?php
/**
 * Plugin Name: nflog errors smoke test (temporary)
 */
// phpcs:ignoreFile
$nflog_case = isset( $_GET['nflog_test'] ) ? (string) $_GET['nflog_test'] : '';
if ( 'early' === $nflog_case ) {
	trigger_error( 'nflog early notice before plugins', E_USER_NOTICE );
}
if ( '' !== $nflog_case ) {
	add_action(
		'init',
		static function () use ( $nflog_case ) {
			global $wpdb;
			switch ( $nflog_case ) {
				case 'notice':
					foreach ( array( 123, 456, 789 ) as $nflog_n ) {
						trigger_error( 'nflog test notice ' . $nflog_n . ' for bob@example.com', E_USER_NOTICE );
					}
					@trigger_error( 'nflog suppressed notice', E_USER_NOTICE );
					break;
				case 'deprecated':
					_deprecated_function( 'nflog_old_function', '6.0.0', 'nflog_new_function' );
					break;
				case 'http':
					wp_remote_get( 'http://127.0.0.1:9/nflog-test?token=secret-value', array( 'timeout' => 2 ) );
					break;
				case 'mail':
					wp_mail( 'not-an-email', 'Secret subject', 'Body' );
					break;
				case 'db':
					// wp_die() path: the error is still the last one when the die handler is resolved.
					$wpdb->suppress_errors( true );
					$wpdb->query( "SELECT * FROM nflog_missing_table WHERE secret_col = 'secret-literal' AND n = 42" );
					wp_die( 'nflog db test' );
					break;
				case 'db_shutdown':
					// shutdown path: last query of the request.
					add_action(
						'shutdown',
						static function () use ( $wpdb ) {
							$wpdb->suppress_errors( true );
							$wpdb->query( "SELECT * FROM nflog_other_missing WHERE secret_col = 'secret-literal'" );
						},
						0
					);
					break;
				case 'exception':
					throw new RuntimeException( 'nflog test exception' );
				case 'fatal':
					undefined_function_xyz();
					break;
				case 'oom':
					ini_set( 'memory_limit', '16M' );
					$nflog_big = str_repeat( 'x', 64 * 1024 * 1024 );
					break;
			}
			echo 'nflog-test-done';
		},
		1
	);
}
PHP

echo "==> Starting wp server on ${NFLOG_SITE}"
pkill -f "127.0.0.1:${NFLOG_PORT}" 2>/dev/null || true
${WP} server --host=127.0.0.1 --port="${NFLOG_PORT}" > "${OUT}/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do
  curl -fs -o /dev/null "${NFLOG_SITE}/" && break
  sleep 0.2
done

${FETCH} --endpoint manifest > "${OUT}/before.json"
BEFORE_MAX="$(node -e "const r=JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')); console.log(r.body.storage.newest_id||0)" "${OUT}/before.json")"

# Recurring errors aggregate into the previous stored event for 60 s (FingerprintStore);
# forget earlier fingerprints so re-runs of this script produce fresh events.
reset_fingerprints() { ${WP} eval 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}nflog_fingerprints" );'; }
reset_fingerprints

echo "==> Triggering errors"
for c in notice deprecated http mail db db_shutdown exception fatal oom; do
  code="$(curl -s -o "${OUT}/case-${c}.html" -w '%{http_code}' "${NFLOG_SITE}/?nflog_test=${c}")"
  echo "     ${c}: HTTP ${code}"
done
case_fatal_code="$(curl -s -o /dev/null -w '%{http_code}' "${NFLOG_SITE}/?nflog_test=fatal")"
[ "${case_fatal_code}" = "500" ] && ok "fatal still returns WordPress' 500 page" || fail "fatal HTTP ${case_fatal_code}"
grep -q 'critical error' "${OUT}/case-exception.html" && ok "uncaught exception still shows the WordPress critical error page" || fail "exception page changed"

fetch_events "${OUT}/events.json"
REL_MU="wp-content/mu-plugins/zz-nflog-errors-test.php"
assert "php.notice (warning) with relative file + mu-plugin component" "${OUT}/events.json" \
  "A('php.notice').some(e=>e.severity==='warning' && e.context.file==='${REL_MU}' && e.context.component==='mu-plugin:zz-nflog-errors-test' && /nflog test notice/.test(e.context.message) && Array.isArray(e.context.trace))"
assert "php.notice repeats aggregate into one event (count >= 2)" "${OUT}/events.json" \
  "A('php.notice').filter(e=>/nflog test notice/.test(e.context.message)).length===1 && A('php.notice').find(e=>/nflog test notice/.test(e.context.message)).count>=2"
assert "@-suppressed notice not logged" "${OUT}/events.json" "!E.some(e=>/nflog suppressed/.test(JSON.stringify(e)))"
assert "e-mail scrubbed from messages" "${OUT}/events.json" "!JSON.stringify(E).includes('bob@example.com')"
assert "deprecated.function with caller component" "${OUT}/events.json" \
  "A('deprecated.function').some(e=>e.severity==='warning' && e.context.function==='nflog_old_function' && e.context.replacement==='nflog_new_function' && e.context.component==='mu-plugin:zz-nflog-errors-test')"
assert "deprecation not double-reported as php.deprecated" "${OUT}/events.json" "!A('php.deprecated').some(e=>/nflog_old_function/.test(e.context.message))"
assert "http.request_failed (error), path only, IP host masked" "${OUT}/events.json" \
  "A('http.request_failed').some(e=>e.severity==='error' && e.context.path==='/nflog-test' && e.context.ip_scope==='loopback' && /^ip-[0-9a-f]{8}\$/.test(e.context.host) && e.context.error_code) && !JSON.stringify(A('http.request_failed')).includes('secret-value')"
assert "mail.failed (error) without addresses or subject" "${OUT}/events.json" \
  "A('mail.failed').some(e=>e.severity==='error' && /^[0-9a-f]{8}\$/.test(e.context.subject_hash)) && !JSON.stringify(A('mail.failed')).includes('Secret subject')"
assert "db.error (critical) with literals stripped" "${OUT}/events.json" \
  "A('db.error').some(e=>e.severity==='critical' && /nflog_missing_table/.test(e.context.query) && /nflog_missing_table/.test(e.context.error)) && !JSON.stringify(A('db.error')).includes('secret-literal') && !JSON.stringify(A('db.error')).includes('<')"
assert "db.error on the shutdown path" "${OUT}/events.json" "A('db.error').some(e=>/nflog_other_missing/.test(e.context.query))"
assert "php.exception (critical) RuntimeException, public request" "${OUT}/events.json" \
  "A('php.exception').some(e=>e.severity==='critical' && e.context.class==='RuntimeException' && e.context.file==='${REL_MU}' && e.context.public_request===true && e.context.trace.every(f=>!('args' in f)))"
assert "one uncaught throwable = one occurrence (no handler recursion)" "${OUT}/events.json" "A('php.exception').concat(A('php.fatal')).every(e=>e.count<=2)"
assert "exception not logged twice as php.fatal" "${OUT}/events.json" "!A('php.fatal').some(e=>/nflog test exception/.test(e.context.message))"
assert "php.fatal (critical) for undefined_function_xyz()" "${OUT}/events.json" \
  "A('php.fatal').some(e=>e.severity==='critical' && /undefined_function_xyz/.test(e.context.message) && e.context.file==='${REL_MU}')"
assert "php.fatal (critical) E_ERROR via error_get_last (memory exhausted)" "${OUT}/events.json" \
  "A('php.fatal').some(e=>e.severity==='critical' && e.context.type==='E_ERROR' && /memory size/i.test(e.context.message) && e.context.public_request===true)"
assert "no absolute paths in any event" "${OUT}/events.json" \
  "!JSON.stringify(E).includes('${WP_DIR}') && !JSON.stringify(E).includes('${PLUGIN_DIR}') && !JSON.stringify(E).includes('/Users/') && !JSON.stringify(E).includes('/home/')"
assert "no raw IPs" "${OUT}/events.json" "!/\"(\\d{1,3}\\.){3}\\d{1,3}\"/.test(JSON.stringify(E)) && !JSON.stringify(E.map(e=>e.context)).includes('127.0.0.1')"

echo "==> Query budget of a recurring notice (H2)"
# Temporary mu-plugin: SAVEQUERIES for ?nflog_queries=1 page views, a recurring
# notice at init, and the plugin's own queries written out at the very end.
cat > "${QUERIES_MU}" <<'PHP'
<?php
// phpcs:ignoreFile
if ( isset( $_GET['nflog_queries'] ) ) {
	define( 'SAVEQUERIES', true );
	add_action( 'init', static function () {
		trigger_error( 'nflog recurring budget notice', E_USER_NOTICE );
	}, 5 );
	add_action( 'shutdown', static function () {
		global $wpdb;
		$own = array();
		foreach ( (array) $wpdb->queries as $q ) {
			if ( false !== strpos( (string) $q[0], 'nflog_' ) ) {
				$own[] = (string) $q[0];
			}
		}
		file_put_contents( WP_CONTENT_DIR . '/nflog-queries.json', json_encode( array( 'count' => count( $own ), 'queries' => $own ) ) );
	}, PHP_INT_MAX );
}
PHP
reset_fingerprints
curl -s -o /dev/null "${NFLOG_SITE}/?nflog_queries=1"      # first occurrence: stored as an event
rm -f "${QUERIES_OUT}"
curl -s -o /dev/null "${NFLOG_SITE}/?nflog_queries=1"      # recurring: counted on the fingerprint row
if [ -f "${QUERIES_OUT}" ]; then
  echo '{"body":'"$(cat "${QUERIES_OUT}")"'}' > "${OUT}/queries.json"
  node -e "const r=JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')); console.log('     H2 evidence: '+r.body.count+' nflog_* queries: '+r.body.queries.map(q=>q.replace(/\s+/g,' ').slice(0,90)).join(' | '))" "${OUT}/queries.json"
  assert "recurring notice on a page view adds <= 2 nflog_* queries (1 SELECT + 1 UPDATE)" "${OUT}/queries.json" \
    "r.body.count<=2 && r.body.queries.every(q=>/^(SELECT fingerprint|UPDATE)/.test(q.trim())) && !r.body.queries.some(q=>/nflog_events/.test(q))"
else
  fail "SAVEQUERIES mu-plugin wrote no report"
fi
rm -f "${QUERIES_MU}" "${QUERIES_OUT}"

echo "==> MU loader: early errors"
${WP} nflog install-mu-loader --force > /dev/null
reset_fingerprints
curl -s -o /dev/null "${NFLOG_SITE}/?nflog_test=early"
fetch_events "${OUT}/events-early.json"
assert "early notice (before plugins) logged after draining the MU buffer" "${OUT}/events-early.json" \
  "A('php.notice').some(e=>/nflog early notice before plugins/.test(e.context.message) && e.context.early===true && e.context.file==='${REL_MU}')"
curl -s -o /dev/null "${NFLOG_SITE}/?nflog_test=notice"
fetch_events "${OUT}/events-early2.json"
assert "with the loader active, notices still logged once" "${OUT}/events-early2.json" \
  "A('php.notice').filter(e=>/nflog test notice/.test(e.context.message)).every(e=>!e.context.early)"

echo "==> Integrity"
${WP} nflog integrity 2> "${OUT}/integrity.err" | cli_json > "${OUT}/integrity.raw" || true
node -e "const r=JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')); require('fs').writeFileSync(process.argv[1]+'.json', JSON.stringify({body:r}))" "${OUT}/integrity.raw" 2>/dev/null || echo '{"body":{}}' > "${OUT}/integrity.raw.json"
assert "integrity result shape" "${OUT}/integrity.raw.json" \
  "r.body.implemented===true && typeof r.body.ran_at==='string' && ['ok','mismatch','unavailable','partial'].includes(r.body.core.status) && Array.isArray(r.body.core.modified) && 'plugins' in r.body && 'chain' in r.body"
assert "fresh core: no modified/missing files (or API unreachable)" "${OUT}/integrity.raw.json" \
  "r.body.core.status==='unavailable' || (r.body.core.modified.length===0 && r.body.core.missing.length===0 && r.body.core.checked>500)"
assert "local hash chain ok" "${OUT}/integrity.raw.json" "r.body.chain===null || r.body.chain.ok===true"

echo "==> Health"
${WP} nflog health 2> "${OUT}/health.err" | cli_json > "${OUT}/health.raw" || true
node -e "const r=JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')); require('fs').writeFileSync(process.argv[1]+'.json', JSON.stringify({body:r}))" "${OUT}/health.raw" 2>/dev/null || echo '{"body":{}}' > "${OUT}/health.raw.json"
assert "wp nflog health: implemented + shape" "${OUT}/health.raw.json" \
  "r.body.implemented===true && ['versions','environment','multisite','active_theme','plugins','updates','debug','cron','uploads_writable','ssl','users','content','errors_24h','site_health','integrity','config_audit'].every(k=>k in r.body) && r.body.versions.php && r.body.debug.wp_debug===true && Array.isArray(r.body.plugins) && r.body.plugins.some(p=>p.slug==='noformat-logger')"
assert "health has no paths, names or e-mails" "${OUT}/health.raw.json" \
  "!JSON.stringify(r.body).includes('${WP_DIR}') && !JSON.stringify(r.body).includes('@example.com')"
${WP} cron event run noformat_logger/cron/health_cache > /dev/null 2>&1 || true
${FETCH} --endpoint health > "${OUT}/health-api.json"
assert "GET /health served from cache with Site Health results" "${OUT}/health-api.json" \
  "r.status===200 && r.body.implemented===true && r.body.cache!==null && r.body.site_health && Array.isArray(r.body.site_health.results) && r.body.site_health.results.length>0 && r.body.site_health.results.every(t=>Object.keys(t).sort().join()==='status,test') && r.body.errors_24h.by_severity.critical.events>=1"

echo "==> Config audit"
# Start from an empty audit state so the first run reports every current finding.
${WP} eval '\Noformat\Logger\Plugin::instance()->meta()->delete( "config_audit_state" );'
${WP} cron event run noformat_logger/cron/config_audit > /dev/null 2>&1
fetch_events "${OUT}/audit1.json"
assert "config.risky_setting findings on the dev site" "${OUT}/audit1.json" \
  "A('config.risky_setting').some(e=>e.context.finding==='file_edit_allowed' && e.severity==='error') && A('config.risky_setting').some(e=>e.context.finding==='admin_username' && Number.isInteger(e.context.detail.user_id))"
${WP} cron event run noformat_logger/cron/config_audit > /dev/null 2>&1
fetch_events "${OUT}/audit2.json"
node -e "
  const f=p=>JSON.parse(require('fs').readFileSync(p,'utf8')).body.events.filter(e=>e.action==='config.risky_setting').length;
  process.exit(f(process.argv[1])===f(process.argv[2]) ? 0 : 1)" "${OUT}/audit1.json" "${OUT}/audit2.json" \
  && ok "second audit run: no duplicate config.risky_setting events" || fail "second audit run emitted duplicates"

echo "==> Chain still intact"
${WP} nflog verify > "${OUT}/verify.txt" 2>&1 && ok "wp nflog verify" || { fail "wp nflog verify"; tail -5 "${OUT}/verify.txt"; }

echo
echo "smoke-errors: ${PASS} passed, ${FAIL} failed"
[ "${FAIL}" -eq 0 ] || KEEP_OUT=1
[ "${FAIL}" -eq 0 ]
