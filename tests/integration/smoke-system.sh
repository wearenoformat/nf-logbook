#!/usr/bin/env bash
# System-group smoke test (settings, plugins/themes, users, security listeners)
# against the site created by setup.sh. Same conventions and env as smoke.sh:
#
#   NFLOG_WP_DIR=$PWD/.local/wp-system-group NFLOG_PORT=8792 bash tests/integration/setup.sh
#   NFLOG_WP_DIR=$PWD/.local/wp-system-group bash tests/integration/smoke-system.sh
#
# Writes through WP-CLI and plain HTTP (curl), reads back through the signed API
# with tools/fetch-events.mjs and asserts actions, severities, objects and that
# no personal data (username, email, display name, passwords, raw IP) leaked.
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP_DIR="${NFLOG_WP_DIR:-$PWD/.local/wp}"
[ -f "${WP_DIR}/.nflog-env" ] || { echo "Run tests/integration/setup.sh first" >&2; exit 1; }
# shellcheck disable=SC1091
source "${WP_DIR}/.nflog-env"
export NFLOG_SITE NFLOG_KEY_ID NFLOG_SECRET
WP="wp --path=${WP_DIR}"
FETCH="node ${PLUGIN_DIR}/tools/fetch-events.mjs"
OUT="$(mktemp -d -t nflog-smoke-system)"
PASS=0
FAIL=0

cleanup() {
  if [ -n "${SERVER_PID:-}" ]; then kill "${SERVER_PID}" 2>/dev/null || true; fi
  pkill -f "127.0.0.1:${NFLOG_PORT}" 2>/dev/null || true
  rm -rf "${OUT}"
}
trap cleanup EXIT

ok()   { PASS=$((PASS + 1)); echo "  ok   $1"; }
fail() { FAIL=$((FAIL + 1)); echo "  FAIL $1"; }
# assert <name> <js expression>; `E` = events after the cursor, helpers below.
PRELUDE='const r=JSON.parse(require("fs").readFileSync(process.argv[1],"utf8")); const E=r.body.events||[];
const of=(a)=>E.filter(e=>e.action===a); const has=(a,f=()=>true)=>of(a).some(f);
const total=(a,f=()=>true)=>of(a).filter(f).reduce((s,e)=>s+e.count,0); const env=process.env;'
assert() {
  if node -e "${PRELUDE} if(!(${2})){process.exit(1)}" "${OUT}/events.json"; then ok "$1"; else fail "$1"; fi
}
show() { node -e "${PRELUDE} console.log(JSON.stringify(of('$1').map(e=>({id:e.id,sev:e.severity,obj:e.object,sum:e.summary,count:e.count,ctx:e.context,changes:e.changes})),null,1))" "${OUT}/events.json" | head -40; }

# Fingerprinted events aggregate into earlier rows while their window is open (60 s;
# 900 s for rest.users_probe / xmlrpc.call, 3600 s for probe.suspicious_404). Forget
# the fingerprints of earlier runs (and other smoke scripts) so this run stores its own rows.
${WP} eval 'global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->prefix}nflog_fingerprints" );' > /dev/null

echo "==> Starting wp server on ${NFLOG_SITE}"
pkill -f "127.0.0.1:${NFLOG_PORT}" 2>/dev/null || true
${WP} server --host=127.0.0.1 --port="${NFLOG_PORT}" > "${OUT}/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do
  curl -fs -o /dev/null "${NFLOG_SITE}/" && break
  sleep 0.2
done

${FETCH} --endpoint manifest > "${OUT}/manifest.json"
CURSOR="$(node -e 'const r=JSON.parse(require("fs").readFileSync(process.argv[1],"utf8")); console.log(r.body.storage.newest_id||0)' "${OUT}/manifest.json")"
echo "==> Cursor: ${CURSOR}"
${WP} transient delete --all --quiet >/dev/null 2>&1 || true   # burst state of earlier runs

RAND="$(php -r 'echo bin2hex(random_bytes(4));')"
export T_USER="nflogsmoke${RAND}" T_EMAIL="nflog.smoke.${RAND}@example.org" T_DISPLAY="Smoketest Person ${RAND}"
export T_PASS="Pw-${RAND}-$(php -r 'echo bin2hex(random_bytes(6));')"
export T_ADMIN="nflogadmin${RAND}" T_ADMIN_EMAIL="nflog.admin.${RAND}@example.org" T_ADMIN_PASS="Ad-${RAND}-$(php -r 'echo bin2hex(random_bytes(6));')"
export T_NEW_ADMIN_EMAIL="nflog.owner.${RAND}@example.org" T_APP_NAME="smoke laptop ${RAND}"

echo "==> Fixtures: dummy plugin and two themes"
PLUG="${WP_DIR}/wp-content/plugins/nflog-smoke-dummy"
mkdir -p "${PLUG}"
printf '<?php\n/**\n * Plugin Name: NFLOG Smoke Dummy\n * Version: 1.2.3\n */\n' > "${PLUG}/nflog-smoke-dummy.php"
for t in a b; do
  TD="${WP_DIR}/wp-content/themes/nflog-smoke-${t}"
  mkdir -p "${TD}"
  printf '/*\nTheme Name: NFLOG Smoke %s\nVersion: 0.0.%s\n*/\n' "${t}" "${t/a/1}" > "${TD}/style.css"
  printf '<?php\n// Smoke theme.\nwp_head(); if ( have_posts() ) { the_post(); the_title(); } wp_footer();\n' > "${TD}/index.php"
done
${WP} plugin deactivate nflog-smoke-dummy --quiet >/dev/null 2>&1 || true

echo "==> Settings"
${WP} option update blogname "Smoke Blog ${RAND}" > /dev/null
${WP} option update admin_email "${T_NEW_ADMIN_EMAIL}" > /dev/null
${WP} option update users_can_register 1 > /dev/null
${WP} option update users_can_register 0 > /dev/null

echo "==> Users"
export T_UID="$(${WP} user create "${T_USER}" "${T_EMAIL}" --role=editor --display_name="${T_DISPLAY}" --user_pass="${T_PASS}" --porcelain)"
${WP} user set-role "${T_UID}" administrator > /dev/null
${WP} user update "${T_UID}" --display_name="Renamed ${RAND}" --user_url="https://smoke-${RAND}.example.org" > /dev/null
export T_APP_PASS="$(${WP} user application-password create "${T_UID}" "${T_APP_NAME}" --porcelain)"
export T_ADMIN_ID="$(${WP} user create "${T_ADMIN}" "${T_ADMIN_EMAIL}" --role=administrator --user_pass="${T_ADMIN_PASS}" --porcelain)"

echo "==> Plugins and themes"
${WP} plugin activate nflog-smoke-dummy > /dev/null
${WP} plugin deactivate nflog-smoke-dummy > /dev/null
${WP} theme activate nflog-smoke-a > /dev/null
${WP} theme activate nflog-smoke-b > /dev/null

echo "==> HTTP: logins, probes"
login() { curl -s -o /dev/null -w '%{http_code}' -b 'wordpress_test_cookie=WP%20Cookie%20check' \
  --data-urlencode "log=$1" --data-urlencode "pwd=$2" -d 'testcookie=1' -d 'wp-submit=Log+In' "${NFLOG_SITE}/wp-login.php"; }
login "${T_ADMIN}" "${T_ADMIN_PASS}" > "${OUT}/login1.code"
login "${T_ADMIN}" "${T_ADMIN_PASS}" > "${OUT}/login2.code"
for _ in $(seq 1 12); do login "${T_USER}" "wrong-${RAND}" > /dev/null; done
curl -s -o /dev/null "${NFLOG_SITE}/wp-json/wp/v2/users"
curl -s -o /dev/null "${NFLOG_SITE}/wp-json/wp/v2/users/1"
curl -s -o /dev/null -H 'Content-Type: text/xml' \
  --data '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName><params></params></methodCall>' \
  "${NFLOG_SITE}/xmlrpc.php"
curl -s -o /dev/null -H 'Content-Type: text/xml' \
  --data "<?xml version=\"1.0\"?><methodCall><methodName>wp.getUsersBlogs</methodName><params><param><value><string>ghost${RAND}</string></value></param><param><value><string>bad</string></value></param></params></methodCall>" \
  "${NFLOG_SITE}/xmlrpc.php"
curl -s -o /dev/null -w '%{http_code}' "${NFLOG_SITE}/.env" > "${OUT}/env.code"

echo "==> Delete users"
${WP} user delete "${T_UID}" --reassign=1 --yes > /dev/null
${WP} user delete "${T_ADMIN_ID}" --yes > /dev/null

echo "==> Reading through the signed API (after ${CURSOR})"
${FETCH} --endpoint events --after "${CURSOR}" --limit 500 --all > "${OUT}/events.json"
assert "events fetched"                       "r.status===200 && E.length>=15"

echo "-- settings"
assert "option.updated blogname (notice)"     "has('option.updated',e=>e.object.type==='option'&&e.object.id==='blogname'&&e.severity==='notice'&&e.category==='settings')"
assert "option.updated admin_email critical, redacted" "has('option.updated',e=>e.object.id==='admin_email'&&e.severity==='critical'&&e.changes.length>0&&e.changes.every(c=>c.new&&c.new.redacted===true))"
assert "users_can_register notice + diff"     "has('option.updated',e=>e.object.id==='users_can_register'&&e.severity==='notice'&&e.changes.some(c=>String(c.new)==='1'))"
assert "active_plugins/template not logged from cli" "!E.some(e=>e.action.startsWith('option.')&&['active_plugins','template','stylesheet','cron','sidebars_widgets'].includes(e.object.id))"
assert "theme_mods noise dropped"             "!E.some(e=>e.action.startsWith('option.')&&(/^theme_mods_/.test(e.object.id)&&(e.changes.length===0||e.changes.some(c=>/custom_css_post_id|nav_menu_locations/.test(c.field)))||e.object.id==='theme_switch_menu_locations'))"
assert "no nflog_*/transient option events"   "!E.some(e=>e.action.startsWith('option.')&&/^(nflog_|_transient_|_site_transient_)/.test(e.object.id))"

echo "-- users"
assert "user.created editor (notice)"         "has('user.created',e=>e.object.id===env.T_UID&&e.severity==='notice'&&e.object.subtype==='editor')"
assert "user.created administrator critical"  "has('user.created',e=>e.object.id===env.T_ADMIN_ID&&e.severity==='critical')"
assert "role set during creation folded"      "!has('user.role_changed',e=>[env.T_UID,env.T_ADMIN_ID].includes(e.object.id)&&e.context.old_roles.length===0)"
assert "user.role_changed to admin critical"  "has('user.role_changed',e=>e.object.id===env.T_UID&&e.severity==='critical'&&e.summary==='User #'+env.T_UID+' role changed from editor to administrator')"
assert "set-role has no extra add/remove"     "!has('user.role_added',e=>e.object.id===env.T_UID)&&!has('user.role_removed',e=>e.object.id===env.T_UID)"
assert "user.updated field names"             "has('user.updated',e=>e.object.id===env.T_UID&&e.context.changed_fields.includes('display_name')&&e.context.changed_fields.includes('user_url'))"
assert "user.app_password_created"            "has('user.app_password_created',e=>e.object.id===env.T_UID&&e.category==='security'&&/^[0-9a-f]{8}\$/.test(e.context.name_hash)&&e.context.uuid)"
assert "user.deleted with reassign"           "has('user.deleted',e=>e.object.id===env.T_UID&&e.context.reassign_id===1)"

echo "-- plugins / themes"
assert "plugin.activated with version"        "has('plugin.activated',e=>e.object.id==='nflog-smoke-dummy'&&e.severity==='notice'&&e.context.version==='1.2.3'&&e.context.network_wide===false)"
assert "plugin.deactivated"                   "has('plugin.deactivated',e=>e.object.id==='nflog-smoke-dummy')"
assert "theme.switched a -> b"                "has('theme.switched',e=>e.object.id==='nflog-smoke-b'&&e.changes.some(c=>c.field==='stylesheet'&&c.old==='nflog-smoke-a'&&c.new==='nflog-smoke-b'))"

echo "-- auth"
assert "admin login from new client (notice)" "has('auth.login',e=>e.object.id===env.T_ADMIN_ID&&e.severity==='notice'&&e.context.new_client===true&&e.actor.id===env.T_ADMIN_ID)"
assert "second admin login is info"           "has('auth.login',e=>e.object.id===env.T_ADMIN_ID&&e.severity==='info'&&e.context.new_client===false)"
assert "auth.login_failed aggregated (>=12)"  "total('auth.login_failed',e=>e.context.user_exists===true&&e.object.id===env.T_UID&&e.request.source!=='xmlrpc')>=12 && of('auth.login_failed').every(e=>/^[0-9a-f]{32}\$/.test(e.context.username_hash)&&/^[0-9a-f]{32}\$/.test(e.fingerprint))"
assert "one auth.login_burst (error)"         "of('auth.login_burst').length===1 && has('auth.login_burst',e=>e.severity==='error'&&e.context.failures>=10&&/^[0-9a-f]{32}\$/.test(e.context.ip_hash))"
assert "xmlrpc failed login via wp_login_failed" "has('auth.login_failed',e=>e.request.source==='xmlrpc'&&e.context.user_exists===false&&e.context.reason==='invalid_username')"

echo "-- probes"
assert "rest.users_probe"                     "has('rest.users_probe',e=>e.severity==='warning'&&e.object.id==='/wp/v2/users'&&/^[0-9a-f]{32}\$/.test(e.fingerprint))"
assert "xmlrpc.call system.listMethods"       "has('xmlrpc.call',e=>e.severity==='warning'&&e.context.method==='system.listMethods'&&e.request.source==='xmlrpc')"
assert "probe.suspicious_404 env_file"        "has('probe.suspicious_404',e=>e.severity==='warning'&&e.object.id==='env_file'&&e.request.route===null&&e.context.pattern==='env_file')"
[ "$(cat "${OUT}/env.code")" = "404" ] && ok "/.env answered 404 (observe only)" || fail "/.env answered $(cat "${OUT}/env.code")"

echo "-- privacy"
# site.url is the site's own home URL (http://127.0.0.1:PORT here); everything else must be clean.
assert "no username/email/display name/password/app password/raw IP in any event" "(()=>{const j=JSON.stringify(E.map(e=>({...e,site:null}))).toLowerCase(); return [env.T_USER,env.T_EMAIL,env.T_DISPLAY,env.T_PASS,env.T_APP_PASS,env.T_ADMIN,env.T_ADMIN_EMAIL,env.T_ADMIN_PASS,env.T_NEW_ADMIN_EMAIL,env.T_APP_NAME,'127.0.0.1','wrong-','ghost'].every(s=>!j.includes(String(s).toLowerCase()))})()"

if [ "${FAIL}" -gt 0 ]; then
  echo "==> Details"
  for a in option.updated user.created user.role_changed auth.login auth.login_failed auth.login_burst xmlrpc.call rest.users_probe probe.suspicious_404; do echo "# ${a}"; show "${a}"; done
  grep -i 'fatal\|warning' "${OUT}/server.log" | head -20 || true
fi

echo
echo "smoke-system: ${PASS} passed, ${FAIL} failed"
[ "${FAIL}" -eq 0 ]
