#!/usr/bin/env bash
# wp-admin smoke test (Content log, System log, live tail, Status, Settings,
# export, dashboard widget, access settings, Tools tabs and legacy redirects, query budget) against the site created by setup.sh. Same conventions
# and env as smoke.sh:
#
#   NFLOG_WP_DIR=$PWD/.local/wp-admin-view NFLOG_PORT=8796 bash tests/integration/setup.sh
#   NFLOG_WP_DIR=$PWD/.local/wp-admin-view NFLOG_PORT=8796 bash tests/integration/smoke-admin.sh
#
# Seeds events through WP-CLI and curl, renders the viewer inside WP-CLI
# (tests/integration/admin-view.php via wp eval-file) and over HTTP with a
# logged-in administrator (export download, nonce, SAVEQUERIES budget).
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP_DIR="${NFLOG_WP_DIR:-$PWD/.local/wp}"
[ -f "${WP_DIR}/.nflog-env" ] || { echo "Run tests/integration/setup.sh first" >&2; exit 1; }
# shellcheck disable=SC1091
source "${WP_DIR}/.nflog-env"
export NFLOG_SITE NFLOG_KEY_ID NFLOG_SECRET
WP="wp --path=${WP_DIR}"
VIEW="${PLUGIN_DIR}/tests/integration/admin-view.php"
OUT="$(mktemp -d -t nflog-smoke-admin)"
MU_DIR="${WP_DIR}/wp-content/mu-plugins"
QUERIES_MU="${MU_DIR}/zz-nflog-admin-queries.php"
QUERIES_OUT="${WP_DIR}/wp-content/nflog-admin-queries.json"
JAR="${OUT}/cookies.txt"
PASS=0
FAIL=0
VIEWER_ID=""

cleanup() {
  if [ -n "${SERVER_PID:-}" ]; then kill "${SERVER_PID}" 2>/dev/null || true; fi
  pkill -f "127.0.0.1:${NFLOG_PORT}" 2>/dev/null || true
  rm -f "${QUERIES_MU}" "${QUERIES_OUT}"
  ${WP} eval '$s = get_option( "nflog_settings", [] ); if ( is_array( $s ) ) { unset( $s["events_visible_to"], $s["system_log_visible_to"] ); update_option( "nflog_settings", $s ); }' > /dev/null 2>&1 || true
  if [ -n "${VIEWER_ID}" ]; then ${WP} user delete "${VIEWER_ID}" --yes > /dev/null 2>&1 || true; fi
  rm -rf "${OUT}"
}
trap cleanup EXIT

ok()   { PASS=$((PASS + 1)); echo "  ok   $1"; }
fail() { FAIL=$((FAIL + 1)); echo "  FAIL $1"; }
# Run admin-view.php and turn its JSON checks into ok/fail lines.
view_checks() {
  local file="${OUT}/view-$1.json"
  ${WP} eval-file "${VIEW}" "$@" > "${file}" 2> "${OUT}/view-$1.err" || true
  if ! node -e "JSON.parse(require('fs').readFileSync(process.argv[1],'utf8'))" "${file}" 2>/dev/null; then
    fail "admin-view.php $1 produced no JSON"; sed -n '1,30p' "${file}" "${OUT}/view-$1.err"; return
  fi
  while IFS=$'\t' read -r status name detail; do
    if [ "${status}" = "1" ]; then ok "${name}"; else fail "${name}"; [ -n "${detail}" ] && echo "       ${detail}"; fi
  done < <(node -e "for (const [n,o,d] of JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')).checks) console.log([o?1:0,n,String(d).replace(/\s+/g,' ').slice(0,300)].join('\t'))" "${file}")
}
# js <name> <file> <expression over r (parsed JSON)>
assert() {
  if node -e "const r=JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')); if(!(${3})){process.exit(1)}" "$2"; then ok "$1"; else fail "$1"; sed -n '1,20p' "$2"; fi
}

${WP} eval 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}nflog_fingerprints" );' > /dev/null

echo "==> Starting wp server on ${NFLOG_SITE}"
pkill -f "127.0.0.1:${NFLOG_PORT}" 2>/dev/null || true
${WP} server --host=127.0.0.1 --port="${NFLOG_PORT}" > "${OUT}/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do
  curl -fs -o /dev/null "${NFLOG_SITE}/" && break
  sleep 0.2
done

echo "==> Seeding events"
RAND="$(php -r 'echo bin2hex(random_bytes(3));')"
ADMIN_ID="$(${WP} user get admin --field=ID)"
DISPLAY="Admin Viewer ${RAND}"
ADMIN_PASS="Av-${RAND}-$(php -r 'echo bin2hex(random_bytes(6));')"
${WP} user update "${ADMIN_ID}" --display_name="${DISPLAY}" --user_pass="${ADMIN_PASS}" --skip-email > /dev/null
POST_TITLE="Admin view post ${RAND}"
POST_ID="$(${WP} --user="${ADMIN_ID}" post create --post_title="Draft ${RAND}" --post_status=draft --porcelain)"
${WP} --user="${ADMIN_ID}" post update "${POST_ID}" --post_title="${POST_TITLE}" --post_status=publish > /dev/null
XSS_ID="$(${WP} --user="${ADMIN_ID}" eval 'kses_remove_filters(); echo wp_insert_post( [ "post_title" => "<script>alert(1)</script>", "post_status" => "publish", "post_type" => "post" ] );')"
curl -s -o /dev/null -b "wordpress_test_cookie=WP%20Cookie%20check" \
  --data-urlencode "log=nflog-nobody-${RAND}" --data-urlencode "pwd=wrong-${RAND}" -d "testcookie=1" "${NFLOG_SITE}/wp-login.php"
${WP} nflog test-event --severity=error > /dev/null
${WP} nflog test-event --severity=critical > /dev/null
${WP} nflog test-event > /dev/null
STORY_ID="$(${WP} --user="${ADMIN_ID}" post create --post_title="Story ${RAND}" --post_status=publish --porcelain)"
${WP} --user="${ADMIN_ID}" eval "wp_update_post( [ 'ID' => ${STORY_ID}, 'post_title' => 'Story edited ${RAND}' ] ); update_post_meta( ${STORY_ID}, 'story_color', 'blue' ); update_post_meta( ${STORY_ID}, 'story_size', 'large' );" > /dev/null
${WP} --user="${ADMIN_ID}" eval "update_post_meta( ${STORY_ID}, 'story_lone', 'x' );" > /dev/null
${WP} eval "update_user_meta( ${ADMIN_ID}, 'aiowps_last_login_time', time() );" > /dev/null
${WP} eval '$r = \Noformat\Logger\Plugin::instance()->repository()->query( [ "object_type" => "post", "object_id" => "'"${STORY_ID}"'" ], 1, 50, "time", "asc" ); $by = []; foreach ( $r as $e ) { $c = json_decode( (string) $e["context"], true ); $by[ $e["action"] . ":" . ( $c["meta_key"] ?? "" ) ] = $e["request_id"]; } echo wp_json_encode( [ "body" => $by ] );' > "${OUT}/story.json"
assert "story seed: post.updated and both meta keys share one request id, the lone key has another" "${OUT}/story.json" \
  "r.body['post.updated:'] && r.body['post.updated:']===r.body['meta.added:story_color'] && r.body['post.updated:']===r.body['meta.added:story_size'] && r.body['meta.added:story_lone'] && r.body['meta.added:story_lone']!==r.body['post.updated:']"
ok "seeded post ${POST_ID}, xss post ${XSS_ID}, story post ${STORY_ID}, failed login, test events"

echo "==> Content log, System log, Status, Settings, export and widget (wp eval-file, administrator)"
view_checks admin "admin=${ADMIN_ID}" "post=${POST_ID}" "xss=${XSS_ID}" "display=${DISPLAY}" "post_title=${POST_TITLE}" "story=${STORY_ID}"

${WP} eval '$r = \Noformat\Logger\Plugin::instance()->repository()->latest( 50 ); $e = array_values( array_filter( $r, static fn( $x ) => "logger.events_exported" === $x["action"] ) ); echo wp_json_encode( [ "body" => null === ( $e[0] ?? null ) ? null : \Noformat\Logger\Storage\EventRepository::rowToArray( end( $e ) ) ] );' > "${OUT}/exported.json"
assert "logger.events_exported logged (notice, count and filter names only)" "${OUT}/exported.json" \
  "r.body && r.body.severity==='notice' && r.body.category==='system' && r.body.context.count>0 && JSON.stringify(r.body.context.filters)===JSON.stringify(['severity','severity_mode']) && r.body.context.scope==='content' && !JSON.stringify(r.body.context).includes('notice')"

echo "==> Over HTTP as a logged-in administrator"
curl -s -o /dev/null -c "${JAR}" -b "wordpress_test_cookie=WP%20Cookie%20check" \
  --data-urlencode "log=admin" --data-urlencode "pwd=${ADMIN_PASS}" -d "testcookie=1" \
  --data-urlencode "redirect_to=${NFLOG_SITE}/wp-admin/" "${NFLOG_SITE}/wp-login.php"
TOOLS="${NFLOG_SITE}/wp-admin/tools.php?page=nf-logbook"
TABRE='tools\.php\?page=nf-logbook(&|&#038;|&amp;)tab='
curl -s -b "${JAR}" "${TOOLS}" > "${OUT}/page.html"
grep -q 'nflog-entry nflog-entry--' "${OUT}/page.html" && grep -q 'nflog-day__label' "${OUT}/page.html" && ok "GET tools.php?page=nf-logbook renders the Content log (default tab)" || { fail "Content log over HTTP"; sed -n '1,20p' "${OUT}/page.html"; }
grep -q "assets/admin.css" "${OUT}/page.html" && grep -q "assets/admin.js" "${OUT}/page.html" && ! grep -q "assets/system-log.js" "${OUT}/page.html" && ok "admin.css and admin.js enqueued on the plugin page (system-log.js only on its tab)" || fail "assets on the plugin page"
! grep -q 'id="toplevel_page_nf-logbook"' "${OUT}/page.html" && grep -Eq "href=[\"']tools\.php\?page=nf-logbook[\"']" "${OUT}/page.html" && ok "no top-level menu; Tools -> Noformat Logbook submenu" || fail "menu under Tools"
for t in content system status settings; do grep -Eq "class=\"nflog-tab[^\"]*\" href=\"[^\"]*${TABRE}${t}\"" "${OUT}/page.html" || MISSING="${MISSING:-} ${t}"; done
[ -z "${MISSING:-}" ] && grep -q 'class="nflog-tab is-active" href="[^"]*tab=content" aria-current="page"' "${OUT}/page.html" && ok "four tabs, Content log active" || { fail "tab bar (missing:${MISSING:-})"; grep -o '<nav class="nflog-tabs".*</nav>' "${OUT}/page.html" | head -c 1500; }
grep -q 'id="screen-options-wrap"' "${OUT}/page.html" && grep -q 'nflog_events_per_page' "${OUT}/page.html" && ok "per-page screen option" || fail "per-page screen option"
curl -s -b "${JAR}" "${NFLOG_SITE}/wp-admin/plugins.php" > "${OUT}/plugins.html"
grep -Eq "${TABRE}settings\"[^>]*>Settings</a>" "${OUT}/plugins.html" && ok "plugin row Settings link -> Settings tab" || fail "plugin row Settings link"
legacy() { # legacy <name> <path> <location regex>
  curl -s -o /dev/null -D "${OUT}/legacy.headers" -w '%{http_code}' -b "${JAR}" "${NFLOG_SITE}$2" > "${OUT}/legacy.code"
  if [ "$(cat "${OUT}/legacy.code")" = "301" ] && grep -Eqi "^location: .*$3" "${OUT}/legacy.headers"; then ok "$1"; else fail "$1"; cat "${OUT}/legacy.code" "${OUT}/legacy.headers"; fi
}
legacy "1.0 URL admin.php?page=noformat-logger -> 301 Content log tab" "/wp-admin/admin.php?page=noformat-logger&window=all" 'tools\.php\?page=nf-logbook&tab=content&window=all'
legacy "1.0 URL admin.php?page=noformat-logger-system -> 301 System log tab, filters kept" "/wp-admin/admin.php?page=noformat-logger-system&levels%5B%5D=critical&window=24h" 'tools\.php\?page=nf-logbook&tab=system.*levels=critical'
legacy "1.0 URL admin.php?page=noformat-logger-status -> 301 Status tab" "/wp-admin/admin.php?page=noformat-logger-status" 'tools\.php\?page=nf-logbook&tab=status'
legacy "1.0 URL admin.php?page=noformat-logger-settings -> 301 Settings tab" "/wp-admin/admin.php?page=noformat-logger-settings" 'tools\.php\?page=nf-logbook&tab=settings'
legacy "0.x URL tools.php?...&tab=events&category=security -> System log, filter kept" "/wp-admin/tools.php?page=nf-logbook&tab=events&category=security" 'tab=system.*category=security'
legacy "1.1 URL tools.php?page=noformat-logger -> 301 Content log tab" "/wp-admin/tools.php?page=noformat-logger" 'tools\.php\?page=nf-logbook&tab=content'
legacy "1.1 URL tools.php?page=noformat-logger&tab=status -> 301 Status tab" "/wp-admin/tools.php?page=noformat-logger&tab=status" 'tools\.php\?page=nf-logbook&tab=status'
legacy "1.1 URL tools.php?page=noformat-logger&tab=system&window=24h -> 301 System log tab, filters kept" "/wp-admin/tools.php?page=noformat-logger&tab=system&window=24h" 'tools\.php\?page=nf-logbook&tab=system.*window=24h'
legacy "0.x URL tools.php?page=noformat-logger&tab=events -> 301 Content log tab" "/wp-admin/tools.php?page=noformat-logger&tab=events" 'tools\.php\?page=nf-logbook&tab=content'

curl -s -b "${JAR}" "${TOOLS}&tab=system" > "${OUT}/system.html"
grep -q 'nflog-console__bar' "${OUT}/system.html" && grep -q 'assets/system-log.js' "${OUT}/system.html" && grep -q 'class="nflog-tab is-active" href="[^"]*tab=system"' "${OUT}/system.html" && ok "GET System log tab renders the console and enqueues system-log.js" || fail "System log over HTTP"
grep -q 'name="tab" value="system"' "${OUT}/system.html" && grep -q 'action="[^"]*/wp-admin/tools.php"' "${OUT}/system.html" && ok "console filter form stays on tools.php, tab=system" || fail "console form target"
TAIL_NONCE="$(node -e "const h=require('fs').readFileSync(process.argv[1],'utf8'); const m=h.match(/id=\"nflog-console-data\">([^<]*)<\/script>/); console.log(m?JSON.parse(m[1]).ajax.nonce:'')" "${OUT}/system.html")"
[ -n "${TAIL_NONCE}" ] && ok "console payload carries the tail nonce" || fail "tail nonce"
curl -s -b "${JAR}" "${NFLOG_SITE}/wp-admin/admin-ajax.php?action=nflog_tail&_ajax_nonce=${TAIL_NONCE}&window=all&before=999999999" > "${OUT}/tail.json"
assert "AJAX nflog_tail (authorised): JSON lines, system categories only" "${OUT}/tail.json" \
  "r.success===true && r.data.lines.length>0 && r.data.lines.every(l=>!['content','media','taxonomy','menu','fields','comment','user','settings','theme','plugin','core','commerce','privacy'].includes(l.category)) && typeof r.data.newest==='number'"
NEWEST="$(node -e "console.log(JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')).data.newest)" "${OUT}/tail.json")"
curl -s -b "${JAR}" "${NFLOG_SITE}/wp-admin/admin-ajax.php?action=nflog_tail&_ajax_nonce=${TAIL_NONCE}&window=all&after=${NEWEST}" > "${OUT}/tail-after.json"
assert "AJAX nflog_tail after the newest id: only newer lines" "${OUT}/tail-after.json" "r.success===true && r.data.lines.every(l=>l.id>${NEWEST})"
curl -s -o "${OUT}/tail-bad.json" -w '%{http_code}' -b "${JAR}" "${NFLOG_SITE}/wp-admin/admin-ajax.php?action=nflog_tail&_ajax_nonce=0000000000" > "${OUT}/tail-bad.code"
[ "$(cat "${OUT}/tail-bad.code")" = "403" ] && ok "AJAX nflog_tail with a bad nonce -> 403" || fail "tail bad nonce -> $(cat "${OUT}/tail-bad.code")"
VERIFY_NONCE="$(curl -s -b "${JAR}" "${TOOLS}&tab=status" | node -e "let s='';process.stdin.on('data',d=>s+=d).on('end',()=>{const m=s.match(/data-nflog-verify[^>]*data-nonce=\"([0-9a-f]+)\"/);console.log(m?m[1]:'')})")"
curl -s -b "${JAR}" --data "action=nflog_verify&_ajax_nonce=${VERIFY_NONCE}" "${NFLOG_SITE}/wp-admin/admin-ajax.php" > "${OUT}/verify-ajax.json"
assert "AJAX nflog_verify: chain intact over the last events" "${OUT}/verify-ajax.json" "r.success===true && r.data.ok===true && r.data.checked>0"
echo "==> Resolve / reopen issues (System log)"
RESOLVE_NONCE="$(node -e "const h=require('fs').readFileSync(process.argv[1],'utf8'); const m=h.match(/id=\"nflog-console-data\">([^<]*)<\/script>/); const d=m?JSON.parse(m[1]):{}; console.log(d.resolve&&d.resolve.can?d.ajax.resolveNonce:'')" "${OUT}/system.html")"
[ -n "${RESOLVE_NONCE}" ] && grep -q 'data-nflog-resolve-all' "${OUT}/system.html" && grep -q 'Hide resolved' "${OUT}/system.html" && ok "console offers Resolve (nonce), Resolve all open and Hide resolved to an administrator" || fail "resolve controls in the console"
COUNTS='$r = \Noformat\Logger\Plugin::instance()->repository(); $s = \Noformat\Logger\Admin\Scope::system(); echo wp_json_encode( [ "open" => $r->countsBySeverity( 0, [], $s, true ), "all" => $r->countsBySeverity( 0, [], $s ) ] );'
ERR_ID="$(${WP} eval 'global $wpdb; echo (int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}nflog_events WHERE action = \"logger.test_event\" AND severity = 1 AND fingerprint IS NULL" );')"
${WP} eval "${COUNTS}" > "${OUT}/counts-before.json"
curl -s -b "${JAR}" --data "action=nflog_resolve&_ajax_nonce=${RESOLVE_NONCE}&issue=event:${ERR_ID}" "${NFLOG_SITE}/wp-admin/admin-ajax.php" > "${OUT}/resolve.json"
assert "AJAX nflog_resolve event:${ERR_ID}: resolved through its own id" "${OUT}/resolve.json" "r.success===true && r.data.op==='resolve' && r.data.key==='event:${ERR_ID}' && r.data.through_id===${ERR_ID} && r.data.resolved.through===${ERR_ID} && r.data.fingerprint===null"
${WP} eval "${COUNTS}" > "${OUT}/counts-after.json"
node -e "const f=require('fs'); console.log(JSON.stringify({b:JSON.parse(f.readFileSync(process.argv[1],'utf8')),a:JSON.parse(f.readFileSync(process.argv[2],'utf8'))}))" "${OUT}/counts-before.json" "${OUT}/counts-after.json" > "${OUT}/counts.json"
assert "resolved error leaves the wp-admin counts; the unfiltered (REST) counts are unchanged" "${OUT}/counts.json" "r.a.open.error.events===r.b.open.error.events-1 && r.a.all.error.events===r.b.all.error.events"
${WP} eval '$r = \Noformat\Logger\Plugin::instance()->repository()->latest( 20 ); $e = array_values( array_filter( $r, static fn( $x ) => "logger.issue_resolved" === $x["action"] ) ); $l = [] === $e ? null : $e[ count( $e ) - 1 ]; echo wp_json_encode( [ "body" => null === $l ? null : \Noformat\Logger\Storage\EventRepository::rowToArray( $l ), "raw" => $l["context"] ?? null ] );' > "${OUT}/resolved-event.json"
assert "logger.issue_resolved: system/notice, object issue/event:<id>, actor the admin, exact context" "${OUT}/resolved-event.json" \
  "r.body && r.body.category==='system' && r.body.severity==='notice' && r.body.object.type==='issue' && r.body.object.id==='event:${ERR_ID}' && r.body.actor.type==='user' && r.body.actor.id==='${ADMIN_ID}' && r.raw==='{\"fingerprint\":null,\"event_id\":${ERR_ID},\"through_id\":${ERR_ID}}'"
curl -s -b "${JAR}" "${NFLOG_SITE}/wp-admin/admin-ajax.php?action=nflog_tail&_ajax_nonce=${TAIL_NONCE}&window=all&levels=error&before=999999999" > "${OUT}/tail-res.json"
assert "nflog_tail: the resolved line carries _resolved (shown dimmed)" "${OUT}/tail-res.json" "r.success===true && r.data.lines.some(l=>l.id===${ERR_ID} && l._resolved && l._resolved.key==='event:${ERR_ID}')"
curl -s -b "${JAR}" "${NFLOG_SITE}/wp-admin/admin-ajax.php?action=nflog_tail&_ajax_nonce=${TAIL_NONCE}&window=all&levels=error&resolved=hide&before=999999999" > "${OUT}/tail-hide.json"
assert "nflog_tail resolved=hide: the resolved line is filtered out" "${OUT}/tail-hide.json" "r.success===true && !r.data.lines.some(l=>l.id===${ERR_ID})"
curl -s -o /dev/null -w '%{http_code}' -b "${JAR}" --data "action=nflog_resolve&_ajax_nonce=0000000000&issue=event:${ERR_ID}" "${NFLOG_SITE}/wp-admin/admin-ajax.php" > "${OUT}/resolve-bad.code"
[ "$(cat "${OUT}/resolve-bad.code")" = "403" ] && ok "AJAX nflog_resolve with a bad nonce -> 403" || fail "resolve bad nonce -> $(cat "${OUT}/resolve-bad.code")"
curl -s -o /dev/null -w '%{http_code}' -b "${JAR}" "${NFLOG_SITE}/wp-admin/admin-ajax.php?action=nflog_resolve&_ajax_nonce=${RESOLVE_NONCE}&issue=event:${ERR_ID}" > "${OUT}/resolve-get.code"
[ "$(cat "${OUT}/resolve-get.code")" = "405" ] && ok "AJAX nflog_resolve over GET -> 405" || fail "resolve over GET -> $(cat "${OUT}/resolve-get.code")"
curl -s -b "${JAR}" --data "action=nflog_resolve&_ajax_nonce=${RESOLVE_NONCE}&op=reopen&issue=event:${ERR_ID}" "${NFLOG_SITE}/wp-admin/admin-ajax.php" > "${OUT}/reopen.json"
assert "AJAX nflog_resolve op=reopen" "${OUT}/reopen.json" "r.success===true && r.data.op==='reopen' && r.data.key==='event:${ERR_ID}'"
${WP} eval '$r = \Noformat\Logger\Plugin::instance()->repository()->latest( 20 ); $e = array_values( array_filter( $r, static fn( $x ) => "logger.issue_reopened" === $x["action"] ) ); $l = [] === $e ? null : $e[ count( $e ) - 1 ]; echo wp_json_encode( [ "raw" => $l["context"] ?? null, "obj" => $l["object_id"] ?? null ] );' > "${OUT}/reopened-event.json"
assert "logger.issue_reopened: exact context" "${OUT}/reopened-event.json" "r.obj==='event:${ERR_ID}' && r.raw==='{\"fingerprint\":null,\"event_id\":${ERR_ID}}'"
${WP} eval "${COUNTS}" > "${OUT}/counts-reopen.json"
assert "reopened error counts again" "${OUT}/counts-reopen.json" "r.open.error.events===$(node -e "console.log(JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')).open.error.events)" "${OUT}/counts-before.json")"
curl -s -b "${JAR}" --data "action=nflog_resolve_all&_ajax_nonce=${RESOLVE_NONCE}&window=all" "${NFLOG_SITE}/wp-admin/admin-ajax.php" > "${OUT}/resolve-all.json"
assert "AJAX nflog_resolve_all: resolves the open issues in view" "${OUT}/resolve-all.json" "r.success===true && r.data.resolved>0 && r.data.more===false"
${WP} eval "${COUNTS}" > "${OUT}/counts-all.json"
assert "after Resolve all open: no open critical/error events in the System log counts" "${OUT}/counts-all.json" "r.open.critical.events===0 && r.open.error.events===0 && r.all.error.events>0"
${WP} nflog reopen "event:${ERR_ID}" > "${OUT}/cli-reopen.txt" 2>&1 && grep -q "event:${ERR_ID} reopened" "${OUT}/cli-reopen.txt" && ok "wp nflog reopen event:<id>" || { fail "wp nflog reopen"; cat "${OUT}/cli-reopen.txt"; }
${WP} nflog resolve "event:${ERR_ID}" > "${OUT}/cli-resolve.txt" 2>&1 && grep -q "event:${ERR_ID} resolved through event #${ERR_ID}" "${OUT}/cli-resolve.txt" && ok "wp nflog resolve event:<id>" || { fail "wp nflog resolve"; cat "${OUT}/cli-resolve.txt"; }
${WP} nflog resolve nope > "${OUT}/cli-bad.txt" 2>&1 && fail "wp nflog resolve nope should fail" || ok "wp nflog resolve with an invalid reference fails"
${WP} nflog verify > "${OUT}/cli-verify.txt" 2>&1 && ok "hash chain intact after resolving (events untouched)" || { fail "chain after resolving"; tail -5 "${OUT}/cli-verify.txt"; }
EXPORT_URL="$(node -e "const h=require('fs').readFileSync(process.argv[1],'utf8'); const m=h.match(/href=\"([^\"]*nflog_export=json[^\"]*)\"/); console.log(m?m[1].replace(/&#038;|&amp;/g,'&'):'')" "${OUT}/page.html")"
if [ -n "${EXPORT_URL}" ]; then ok "export link with nonce"; else fail "export link with nonce"; fi
curl -s -D "${OUT}/export.headers" -o "${OUT}/export.body" -b "${JAR}" "${EXPORT_URL}&severity=notice&severity_mode=min&window=all"
grep -qi '^content-type: application/json' "${OUT}/export.headers" && grep -qi '^content-disposition: attachment; filename="nflog-content-' "${OUT}/export.headers" \
  && ok "export download: application/json attachment" || { fail "export headers"; cat "${OUT}/export.headers"; }
grep -qi '^cache-control:.*no-cache\|^cache-control:.*no-store' "${OUT}/export.headers" && ok "export not cacheable" || fail "export cache headers"
echo '{}' > "${OUT}/empty.json"
node -e "const fs=require('fs'); fs.writeFileSync(process.argv[2], JSON.stringify({body: JSON.parse(fs.readFileSync(process.argv[1],'utf8'))}))" "${OUT}/export.body" "${OUT}/export.json" 2>/dev/null || cp "${OUT}/empty.json" "${OUT}/export.json"
assert "export JSON: CONTRACT events, filtered (notice and above, content scope)" "${OUT}/export.json" \
  "r.body && r.body.scope==='content' && r.body.events.length>=1 && r.body.events.every(e=>['critical','error','warning','notice'].includes(e.severity) && !['security','error','stability','mail','http','cron','build','deploy','system'].includes(e.category) && ['id','occurred_at','severity','category','action','summary','object','actor','request','changes','context','fingerprint','count','site','producer','hash','prev_hash'].every(k=>k in e)) && r.body.total===r.body.events.length"
assert "export contains no raw IPs or the admin's display name" "${OUT}/export.json" \
  "!/\"(\\d{1,3}\\.){3}\\d{1,3}\"/.test(JSON.stringify(r.body.events)) && !JSON.stringify(r.body).includes('Admin Viewer')"
BAD_URL="$(echo "${EXPORT_URL}" | sed -E 's/_wpnonce=[0-9a-f]+/_wpnonce=0000000000/')"
curl -s -o /dev/null -w '%{http_code}' -b "${JAR}" "${BAD_URL}" > "${OUT}/bad.code"
[ "$(cat "${OUT}/bad.code")" = "403" ] && ok "export with a bad nonce -> 403" || fail "export with a bad nonce -> $(cat "${OUT}/bad.code")"
curl -s -o /dev/null -w '%{http_code}' "${EXPORT_URL}" > "${OUT}/anon.code"
[ "$(cat "${OUT}/anon.code")" = "302" ] && ok "export logged out -> login redirect" || fail "export logged out -> $(cat "${OUT}/anon.code")"

echo "==> Query budget (SAVEQUERIES, queries on the plugin tables per admin page view)"
mkdir -p "${MU_DIR}"
cat > "${QUERIES_MU}" <<'PHP'
<?php
// phpcs:ignoreFile
if ( isset( $_GET['nflog_queries'] ) ) {
	define( 'SAVEQUERIES', true );
	add_action( 'shutdown', static function () {
		global $wpdb;
		$own = array();
		foreach ( (array) $wpdb->queries as $q ) {
			// The plugin's own tables only (the serialized cron option mentions nflog_* schedules).
			if ( preg_match( '/' . preg_quote( $wpdb->prefix . 'nflog_', '/' ) . '(events|fingerprints|nonces|meta)\b/', (string) $q[0] ) ) {
				$own[] = (string) $q[0];
			}
		}
		file_put_contents( WP_CONTENT_DIR . '/nflog-admin-queries.json', json_encode( array( 'count' => count( $own ), 'total' => count( (array) $wpdb->queries ), 'queries' => $own, 'css' => wp_style_is( 'nflog-admin', 'enqueued' ) ) ) );
	}, PHP_INT_MAX );
}
PHP
measure() { # measure <label> <path>
  rm -f "${QUERIES_OUT}"
  curl -s -o "${OUT}/measure.html" -b "${JAR}" "${NFLOG_SITE}$2"
  if [ -f "${QUERIES_OUT}" ]; then
    echo '{"body":'"$(cat "${QUERIES_OUT}")"'}' > "${OUT}/q-$1.json"
    node -e "const r=JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')).body; console.log('     $1: '+r.count+' nflog_* of '+r.total+' queries'+(r.count?': '+r.queries.map(q=>q.replace(/\s+/g,' ').slice(0,80)).join(' | '):'')+'; admin.css '+(r.css?'enqueued':'not enqueued'))" "${OUT}/q-$1.json"
  else
    echo '{"body":null}' > "${OUT}/q-$1.json"
  fi
}
curl -s -o /dev/null -b "${JAR}" "${NFLOG_SITE}/wp-admin/edit.php"   # warm-up (first admin view after login)
measure edit "/wp-admin/edit.php?nflog_queries=1"
assert "edit.php: 0 nflog_* queries, no admin.css" "${OUT}/q-edit.json" "r.body && r.body.count===0 && r.body.css===false"
measure dashboard "/wp-admin/index.php?nflog_queries=1"
assert "dashboard with the widget: <= 3 nflog_* queries (counts + latest + count hydration), admin.css enqueued" "${OUT}/q-dashboard.json" \
  "r.body && r.body.count>=1 && r.body.count<=3 && r.body.queries.every(q=>/^\\s*SELECT/.test(q)) && r.body.css===true"
grep -q 'id="nflog_events_widget"' "${OUT}/measure.html" && ok "dashboard widget present over HTTP" || fail "dashboard widget over HTTP"
measure profile "/wp-admin/profile.php?nflog_queries=1"
assert "profile.php: 0 nflog_* queries" "${OUT}/q-profile.json" "r.body && r.body.count===0"
measure viewer "/wp-admin/tools.php?page=nf-logbook&nflog_queries=1"
node -e "const r=JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')).body; process.exit(r && r.count>0 ? 0 : 1)" "${OUT}/q-viewer.json" && ok "the viewer itself queries the log" || fail "viewer queries"
rm -f "${QUERIES_MU}" "${QUERIES_OUT}"

echo "==> system_log_visible_to=capability_only"
${WP} eval '$s = get_option( "nflog_settings", [] ); $s = is_array( $s ) ? $s : []; $s["system_log_visible_to"] = "capability_only"; update_option( "nflog_settings", $s );' > /dev/null
curl -s -o /dev/null -w '%{http_code}' -b "${JAR}" "${NFLOG_SITE}/wp-admin/admin-ajax.php?action=nflog_tail&_ajax_nonce=${TAIL_NONCE}" > "${OUT}/tail-denied.code"
[ "$(cat "${OUT}/tail-denied.code")" = "403" ] && ok "AJAX nflog_tail for an administrator without system log access -> 403" || fail "tail without access -> $(cat "${OUT}/tail-denied.code")"
curl -s -b "${JAR}" "${TOOLS}" > "${OUT}/page-nosys.html"
grep -q 'nflog-entry nflog-entry--' "${OUT}/page-nosys.html" && ! grep -Eq "${TABRE}system" "${OUT}/page-nosys.html" && ok "content log kept, System log tab and links hidden" || fail "content log without system access"
curl -s -o "${OUT}/sys-denied.html" -w '%{http_code}' -b "${JAR}" "${TOOLS}&tab=system" > "${OUT}/sys-denied.code"
[ "$(cat "${OUT}/sys-denied.code")" = "403" ] && ! grep -q 'nflog-console' "${OUT}/sys-denied.html" && ok "direct ?tab=system without access -> 403" || fail "direct ?tab=system without access -> $(cat "${OUT}/sys-denied.code")"
${WP} eval '$s = get_option( "nflog_settings", [] ); unset( $s["system_log_visible_to"] ); update_option( "nflog_settings", $s );' > /dev/null

echo "==> events_visible_to=capability_only"
${WP} eval '$s = get_option( "nflog_settings", [] ); $s = is_array( $s ) ? $s : []; $s["events_visible_to"] = "capability_only"; update_option( "nflog_settings", $s );' > /dev/null
VIEWER_ID="$(${WP} user create "nflogviewer${RAND}" "nflog.viewer.${RAND}@example.org" --role=subscriber --porcelain)"
${WP} user add-cap "${VIEWER_ID}" noformat_logger_view_events > /dev/null
view_checks restricted "admin=${ADMIN_ID}" "viewer=${VIEWER_ID}" "display=${DISPLAY}" "post=${POST_ID}" "post_title=${POST_TITLE}"
curl -s -o /dev/null -w '%{http_code}' -b "${JAR}" "${EXPORT_URL}" > "${OUT}/restricted.code"
[ "$(cat "${OUT}/restricted.code")" = "403" ] && ok "capability_only: administrator export (valid nonce) -> 403" || fail "capability_only export -> $(cat "${OUT}/restricted.code")"
curl -s -b "${JAR}" "${NFLOG_SITE}/wp-admin/index.php" > "${OUT}/dash-restricted.html"
grep -q 'id="nflog_events_widget"' "${OUT}/dash-restricted.html" && fail "capability_only: no widget for the administrator" || ok "capability_only: no widget for the administrator"
curl -s -b "${JAR}" "${TOOLS}" > "${OUT}/page-nocontent.html"
grep -q 'class="nflog-tab is-active" href="[^"]*tab=system"' "${OUT}/page-nocontent.html" && ! grep -Eq "${TABRE}content" "${OUT}/page-nocontent.html" && ok "capability_only: administrator lands on the System log, no Content log tab" || fail "capability_only default tab"
curl -s -o /dev/null -w '%{http_code}' -b "${JAR}" "${TOOLS}&tab=content" > "${OUT}/content-denied.code"
[ "$(cat "${OUT}/content-denied.code")" = "403" ] && ok "capability_only: direct ?tab=content -> 403" || fail "direct ?tab=content -> $(cat "${OUT}/content-denied.code")"

echo "==> Chain still intact"
${WP} nflog verify > "${OUT}/verify.txt" 2>&1 && ok "wp nflog verify" || { fail "wp nflog verify"; tail -5 "${OUT}/verify.txt"; }

echo
echo "smoke-admin: ${PASS} passed, ${FAIL} failed"
[ "${FAIL}" -eq 0 ]
