#!/usr/bin/env bash
# End-to-end smoke test against the site created by setup.sh:
# WP-CLI writes events, the signed API reads them back through tools/fetch-events.mjs.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP_DIR="${NFLOG_WP_DIR:-$PWD/.local/wp}"
[ -f "${WP_DIR}/.nflog-env" ] || { echo "Run tests/integration/setup.sh first" >&2; exit 1; }
# shellcheck disable=SC1091
source "${WP_DIR}/.nflog-env"
export NFLOG_SITE NFLOG_KEY_ID NFLOG_SECRET
WP="wp --path=${WP_DIR}"
FETCH="node ${PLUGIN_DIR}/tools/fetch-events.mjs"
OUT="$(mktemp -d -t nflog-smoke)"
MU_DIR="${WP_DIR}/wp-content/mu-plugins"
TEST_MU="${MU_DIR}/zz-nflog-smoke-test.php"
PASS=0
FAIL=0

cleanup() {
  if [ -n "${SERVER_PID:-}" ]; then kill "${SERVER_PID}" 2>/dev/null || true; fi
  pkill -f "127.0.0.1:${NFLOG_PORT}" 2>/dev/null || true
  rm -f "${TEST_MU}"
  ${WP} option delete nflog_smoke_block_rest > /dev/null 2>&1 || true
  rm -rf "${OUT}"
}
trap cleanup EXIT

ok()   { PASS=$((PASS + 1)); echo "  ok   $1"; }
fail() { FAIL=$((FAIL + 1)); echo "  FAIL $1"; }
# assert <name> <file> <js expression over `r` (the fetch result)>
assert() {
  if node -e "const r=JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')); if(!(${3})){process.exit(1)}" "$2"; then ok "$1"; else fail "$1"; sed -n '1,40p' "$2"; fi
}

# Temporary mu-plugin: X-Test-Client-Ip sets the client IP (flood test from many
# "clients"); option nflog_smoke_block_rest mimics a security plugin that
# rejects every REST request in rest_authentication_errors.
mkdir -p "${MU_DIR}"
cat > "${TEST_MU}" <<'PHP'
<?php
/**
 * Plugin Name: nflog smoke test helpers (temporary)
 */
// phpcs:ignoreFile
add_filter( 'noformat_logger/client_ip', static function ( $ip ) {
	return isset( $_SERVER['HTTP_X_TEST_CLIENT_IP'] ) ? (string) $_SERVER['HTTP_X_TEST_CLIENT_IP'] : $ip;
} );
add_filter( 'rest_authentication_errors', static function ( $result ) {
	return get_option( 'nflog_smoke_block_rest' ) ? new WP_Error( 'rest_forbidden', 'REST API restricted to logged-in users', array( 'status' => 401 ) ) : $result;
}, 99 );
PHP

echo "==> Starting wp server on ${NFLOG_SITE}"
pkill -f "127.0.0.1:${NFLOG_PORT}" 2>/dev/null || true
${WP} server --host=127.0.0.1 --port="${NFLOG_PORT}" > "${OUT}/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do
  curl -fs -o /dev/null "${NFLOG_SITE}/" && break
  sleep 0.2
done

echo "==> Writing events through WP-CLI"
${WP} nflog test-event
${WP} nflog marker "smoke" --ref=smoke-test
${WP} post create --post_title="Smoke test post" --post_status=publish --porcelain > /dev/null
ok "wp-cli writes"

echo "==> Reading through the signed API"
${FETCH} --endpoint manifest > "${OUT}/manifest.json"
assert "manifest 200 + producer"        "${OUT}/manifest.json" "r.status===200 && r.body.producer.name==='wp-noformat-logger' && r.body.schema.database===1"
assert "manifest headers"               "${OUT}/manifest.json" "r.headers['cache-control'].includes('no-store') && r.headers['x-nf-schema']==='1' && /^\\d+:[0-9a-f]{64}\$/.test(r.headers['x-nf-chain-head'])"
assert "manifest modules + storage"     "${OUT}/manifest.json" "r.body.storage.available===true && r.body.modules.PostListener.registered===true && r.body.key.authenticated_id===process.env.NFLOG_KEY_ID"

${FETCH} --endpoint events --after 0 --limit 500 > "${OUT}/events.json"
assert "events >= 2"                    "${OUT}/events.json" "r.status===200 && r.body.events.length>=2"
assert "test event + marker present"    "${OUT}/events.json" "r.body.events.some(e=>e.action==='logger.test_event') && r.body.events.some(e=>e.action==='deploy.marker' && e.context.label==='smoke')"
assert "contract shape"                 "${OUT}/events.json" "r.body.events.every(e=>['id','occurred_at','severity','category','action','summary','object','actor','request','changes','context','fingerprint','count','site','producer','hash','prev_hash'].every(k=>k in e)) && /Z\$/.test(r.body.events[0].occurred_at)"
assert "cli source, no raw ip"          "${OUT}/events.json" "r.body.events.filter(e=>e.action==='logger.test_event').every(e=>e.request.source==='cli' && e.actor.type==='cli') && r.body.events.every(e=>e.request===null || e.request.ip_hash===null || /^[0-9a-f]{32}\$/.test(e.request.ip_hash)) && !/\"(\\d{1,3}\\.){3}\\d{1,3}\"/.test(JSON.stringify(r.body))"

${FETCH} --endpoint events --after 0 --limit 1 --query fields=compact --query min_severity=notice > "${OUT}/compact.json"
assert "compact + min_severity filter"  "${OUT}/compact.json" "r.status===200 && r.body.events.length===1 && !('context' in r.body.events[0]) && ['critical','error','warning','notice'].includes(r.body.events[0].severity) && r.body.has_more===true"

${FETCH} --endpoint summary --window 24h > "${OUT}/summary.json"
assert "summary"                        "${OUT}/summary.json" "r.status===200 && r.body.by_severity.info.events>=1 && 'system' in r.body.by_category"

${FETCH} --endpoint verify > "${OUT}/verify.json"
assert "server-side verify"             "${OUT}/verify.json" "r.status===200 && r.body.ok===true && r.body.checked>=3 && r.body.head_ok===true"

${FETCH} --endpoint events --verify --limit 500 > "${OUT}/client-verify.json"
assert "client-side chain verify"       "${OUT}/client-verify.json" "r.body.verification.ok===true && r.body.verification.checked>=3 && r.body.verification.head_ok===true"

${FETCH} --endpoint manifest --rest-route > "${OUT}/rest-route.json"
assert "?rest_route= form accepted"     "${OUT}/rest-route.json" "r.status===200"

${FETCH} --endpoint health > "${OUT}/health.json"
assert "health snapshot"                "${OUT}/health.json" "r.status===200 && r.body.implemented===true && r.body.versions.php && 'errors_24h' in r.body"

${FETCH} --endpoint marker --label "api marker" > "${OUT}/marker.json"
assert "POST marker 201"                "${OUT}/marker.json" "r.status===201 && r.body.id>0"

echo "==> Rejections"
${FETCH} --endpoint events --bad-signature --expect-status 401 > "${OUT}/bad.json" || true
assert "bad signature -> 401 generic"   "${OUT}/bad.json" "r.status===401 && r.body.code==='nflog_unauthorized' && r.body.message==='Unauthorized'"

curl -s -o "${OUT}/unsigned.body" -w '%{http_code}' "${NFLOG_SITE}/wp-json/noformat-logger/v1/events" > "${OUT}/unsigned.code"
if [ "$(cat "${OUT}/unsigned.code")" = "401" ]; then ok "unsigned request -> 401"; else fail "unsigned request -> $(cat "${OUT}/unsigned.code")"; fi

curl -s "${NFLOG_SITE}/wp-json/" > "${OUT}/index.json"
if grep -q 'noformat-logger' "${OUT}/index.json"; then fail "namespace hidden from REST index"; else ok "namespace hidden from REST index"; fi
curl -s -o /dev/null -w '%{http_code}' "${NFLOG_SITE}/wp-json/noformat-logger/v1" > "${OUT}/nsindex.code"
if [ "$(cat "${OUT}/nsindex.code")" = "401" ]; then ok "namespace index -> 401"; else fail "namespace index -> $(cat "${OUT}/nsindex.code")"; fi

curl -s -D "${OUT}/unsigned.headers" -o /dev/null "${NFLOG_SITE}/wp-json/noformat-logger/v1/manifest"
if grep -qi '^x-nf-chain-head' "${OUT}/unsigned.headers"; then fail "401 has no X-NF-Chain-Head"; else ok "401 has no X-NF-Chain-Head (M1)"; fi
grep -qi '^cache-control: no-store' "${OUT}/unsigned.headers" && ok "401 still no-store" || fail "401 Cache-Control"

${FETCH} --endpoint events --query 'category[]=security' --expect-status 400 > "${OUT}/array-param.json" || true
assert "array-valued filter -> 400 (L9)"  "${OUT}/array-param.json" "r.status===400 && r.body.code==='nflog_bad_request'"

${FETCH} --endpoint events --after 0 --limit 500 --query category=security > "${OUT}/security.json"
assert "no spurious auth failures"     "${OUT}/security.json" "r.status===200 && !r.body.events.some(e=>e.context.reason==='nonce_reused')"
assert "401 produced api.auth_failed"   "${OUT}/security.json" "r.status===200 && r.body.events.some(e=>e.action==='api.auth_failed' && e.severity==='warning' && e.context.reason==='bad_signature' && /^[0-9a-f]{32}\$/.test(e.fingerprint) && /^[0-9a-f]{32}\$/.test(e.request.ip_hash))"

echo "==> ack only on GET /events (L12)"
${FETCH} --endpoint manifest > "${OUT}/ack0.json"
HEAD_ID="$(node -e "console.log(JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')).body.chain_head.id)" "${OUT}/ack0.json")"
${FETCH} --endpoint manifest --query "ack=${HEAD_ID}" > "${OUT}/ack1.json"
${FETCH} --endpoint manifest > "${OUT}/ack2.json"
node -e "const f=p=>JSON.parse(require('fs').readFileSync(p,'utf8')).body.ack_cursor; process.exit(f(process.argv[1])===f(process.argv[2])?0:1)" "${OUT}/ack0.json" "${OUT}/ack2.json" \
  && ok "ack on /manifest is ignored" || fail "ack on /manifest moved the cursor"
${FETCH} --endpoint events --after 0 --limit 1 --query "ack=1" > /dev/null
${FETCH} --endpoint manifest > "${OUT}/ack3.json"
assert "ack on /events moves the cursor; last_served_id recorded" "${OUT}/ack3.json" "r.body.ack_cursor>=1 && r.body.last_served_id>=1 && r.body.rest_prefix==='wp-json'"

echo "==> Security plugin blocking REST (M3)"
${WP} option update nflog_smoke_block_rest 1 > /dev/null
${FETCH} --endpoint manifest > "${OUT}/blocked.json" || true
assert "signed pull still answered while rest_authentication_errors rejects REST" "${OUT}/blocked.json" "r.status===200 && r.body.producer.name==='wp-noformat-logger'"
${FETCH} --endpoint manifest --bad-signature --expect-status 401 > "${OUT}/blocked-bad.json" || true
assert "the HMAC check still decides (bad signature -> 401)" "${OUT}/blocked-bad.json" "r.status===401 && r.body.code==='nflog_unauthorized'"
curl -s -o /dev/null -w '%{http_code}' "${NFLOG_SITE}/wp-json/wp/v2/posts" > "${OUT}/blocked-core.code"
[ "$(cat "${OUT}/blocked-core.code")" = "401" ] && ok "other namespaces keep the security plugin's error" || fail "core REST -> $(cat "${OUT}/blocked-core.code")"
${WP} option delete nflog_smoke_block_rest > /dev/null

echo "==> Unauthenticated flood (H1)"
# Start in a fresh minute so the per-minute cap is not split across two buckets.
sec=$(date +%S); sec=${sec#0}; if [ "${sec}" -gt 20 ]; then sleep $((61 - sec)); fi
${FETCH} --endpoint manifest > "${OUT}/flood0.json"
FLOOD_FROM="$(node -e "console.log(JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')).body.chain_head.id)" "${OUT}/flood0.json")"
flood() { # flood <requests> <vary client ip: 0|1>
  node -e "
    const crypto=require('crypto'); const [n, vary]=[Number(process.argv[1]), process.argv[2]==='1'];
    const b62='0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    (async()=>{ let s401=0; for(let i=0;i<n;i++){
      const key='nfk_'+Array.from(crypto.randomBytes(16),x=>b62[x%62]).join('');
      const h={'X-NF-Key':key,'X-NF-Timestamp':String(Math.floor(Date.now()/1000)),'X-NF-Nonce':crypto.randomBytes(16).toString('hex'),'X-NF-Signature':'v1='+crypto.randomBytes(32).toString('hex')};
      if(vary) h['X-Test-Client-Ip']='198.18.'+(i>>8)+'.'+(i&255);
      const r=await fetch(process.env.NFLOG_SITE+'/wp-json/noformat-logger/v1/events',{headers:h}); await r.text(); if(r.status===401) s401++; }
      console.log(s401); })();" "$1" "$2"
}
# Forget the anonymous per-minute bucket afterwards so later suites (failed logins, probes) are not capped.
reset_anon_cap() { ${WP} eval 'global $wpdb; $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}nflog_meta WHERE meta_key LIKE %s", "rl:anon:%" ) );'; }
FLOOD_A="$(flood 200 0)"
${FETCH} --endpoint events --after "${FLOOD_FROM}" --limit 500 --all > "${OUT}/flood-a.json"
node -e "const E=JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')).body.events; console.log('     H1 evidence (one client): 401s=${FLOOD_A} rows='+JSON.stringify(E.map(e=>[e.action,e.count,e.context.reason])))" "${OUT}/flood-a.json"
# count is exact up to 100, then sampled (1 write in 10 adding 10): an estimate around 200.
assert "200 random key ids from one client: all 401, aggregated into 1 row" "${OUT}/flood-a.json" \
  "${FLOOD_A}===200 && r.body.events.filter(e=>e.action==='api.auth_failed').length===1 && r.body.events.find(e=>e.action==='api.auth_failed').count>=100 && r.body.events.find(e=>e.action==='api.auth_failed').count<=400 && r.body.events.find(e=>e.action==='api.auth_failed').context.key_id===null && r.body.events.find(e=>e.action==='api.auth_failed').object.id===null"
FLOOD_MID="$(node -e "const e=JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')).body.events; console.log(e.length?e[e.length-1].id:${FLOOD_FROM})" "${OUT}/flood-a.json")"
FLOOD_B="$(flood 200 1)"
${FETCH} --endpoint events --after "${FLOOD_MID}" --limit 500 --all > "${OUT}/flood-b.json"
node -e "
  const E=JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')).body.events;
  const a=E.filter(e=>e.action==='api.auth_failed'), f=E.filter(e=>e.action==='system.flood');
  console.log('     H1 evidence: requests=${FLOOD_B} api.auth_failed rows='+a.length+' system.flood rows='+f.length+' flood count='+(f[0]?f[0].count:0)+' occurrences total='+(a.reduce((s,e)=>s+e.count,0)+(f[0]?f[0].count:0)));" "${OUT}/flood-b.json"
assert "200 clients in a minute: <= 120 rows + exactly one system.flood carrying the rest" "${OUT}/flood-b.json" \
  "(()=>{const a=r.body.events.filter(e=>e.action==='api.auth_failed'), f=r.body.events.filter(e=>e.action==='system.flood'); return ${FLOOD_B}===200 && a.length<=120 && a.length>=100 && f.length===1 && f[0].severity==='warning' && f[0].category==='security' && f[0].count===200-a.length && f[0].context.top_actions[0].action==='api.auth_failed'})()"

reset_anon_cap

echo "==> Chain still intact after all writes"
${WP} nflog verify > "${OUT}/cli-verify.txt" && ok "wp nflog verify" || { fail "wp nflog verify"; cat "${OUT}/cli-verify.txt"; }
${WP} nflog integrity --no-fail > "${OUT}/integrity.txt" 2>&1 || true
grep -q '"implemented": true' "${OUT}/integrity.txt" && ok "wp nflog integrity" || { fail "wp nflog integrity"; sed -n '1,20p' "${OUT}/integrity.txt"; }
${WP} nflog health > "${OUT}/health-cli.txt" 2>&1 && grep -q '"implemented": true' "${OUT}/health-cli.txt" && ok "wp nflog health" || fail "wp nflog health"

echo
echo "smoke: ${PASS} passed, ${FAIL} failed"
[ "${FAIL}" -eq 0 ]
