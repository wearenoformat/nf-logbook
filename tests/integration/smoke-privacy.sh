#!/usr/bin/env bash
# Privacy canary, against the site created by setup.sh: a canary string is written
# into values that a key-pattern redactor cannot recognise as personal (a user's
# wpseo_title, a non-viewable post type's message meta, comment meta, an ACF field
# on a user), the signed API reads the events back through tools/fetch-events.mjs
# and the script asserts the canary never appears in the JSON, while structural
# values (viewable post meta, user preferences) are still diffed in clear.
#
#   NFLOG_WP_DIR   site directory (default: $PWD/.local/wp)
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP_DIR="${NFLOG_WP_DIR:-$PWD/.local/wp}"
[ -f "${WP_DIR}/.nflog-env" ] || { echo "Run tests/integration/setup.sh first" >&2; exit 1; }
# shellcheck disable=SC1091
source "${WP_DIR}/.nflog-env"
export NFLOG_SITE NFLOG_KEY_ID NFLOG_SECRET
WP="wp --path=${WP_DIR}"
FETCH="node ${PLUGIN_DIR}/tools/fetch-events.mjs"
OUT="$(mktemp -d -t nflog-privacy)"
PASS=0
FAIL=0
CANARY="CANARY-7f3a-DISPLAYNAME"

cleanup() {
  if [ -n "${SERVER_PID:-}" ]; then kill "${SERVER_PID}" 2>/dev/null || true; fi
  pkill -f "127.0.0.1:${NFLOG_PORT}" 2>/dev/null || true
  rm -rf "${OUT}"
}
trap cleanup EXIT

ok()   { PASS=$((PASS + 1)); echo "  ok   $1"; }
fail() { FAIL=$((FAIL + 1)); echo "  FAIL $1"; }
assert() {
  if node -e "
    const r = JSON.parse(require('fs').readFileSync(process.argv[1], 'utf8'));
    const ev = (r.body && r.body.events) || [];
    const find = (a, f = () => true) => ev.filter(e => e.action === a && f(e));
    const one = (a, f) => find(a, f).length === 1;
    const id = (e, v) => e.object && String(e.object.id) === String(v);
    const ch = (e, f) => (e.changes || []).find(c => c.field === f);
    const summary = v => v && typeof v === 'object' && /^[0-9a-f]{8}\$/.test(v.sha256_8) && typeof v.length === 'number' && !v.redacted;
    if (!(${3})) process.exit(1);" "$2"; then
    ok "$1"
  else
    fail "$1"
    node -e "const r=JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')); for (const e of (r.body.events||[]).slice(-30)) console.log('       #'+e.id, e.severity, e.action, JSON.stringify(e.object), JSON.stringify(e.changes).slice(0,200))" "$2" || true
  fi
}
cursor() { ${WP} nflog status 2>/dev/null | awk '$1=="chain_head"{split($2,a,":"); print a[1]+0; found=1} END{if(!found) print 0}'; }
since() { ${FETCH} --endpoint events --after "$1" --limit 500 --all > "$2"; }
failures() { ${WP} nflog status 2>/dev/null | awk '$1=="failures"{print $2}'; }

echo "==> Starting wp server on ${NFLOG_SITE}"
pkill -f "127.0.0.1:${NFLOG_PORT}" 2>/dev/null || true
${WP} server --host=127.0.0.1 --port="${NFLOG_PORT}" > "${OUT}/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do
  curl -fs -o /dev/null "${NFLOG_SITE}/" && break
  sleep 0.2
done

LOGIN="nflogcanary"
if ! ${WP} user get "${LOGIN}" --field=ID > /dev/null 2>&1; then
  ${WP} user create "${LOGIN}" "canary.person@example.org" --role=editor \
    --user_pass="$(php -r 'echo bin2hex(random_bytes(12));')" --quiet
fi
USER_ID="$(${WP} user get "${LOGIN}" --field=ID)"
ACF=0
${WP} plugin is-active advanced-custom-fields 2>/dev/null && ACF=1

FAILURES_BEFORE="$(failures)"
C0="$(cursor)"
echo "==> Cursor ${C0}"

echo "==> Canary values"
# User profile: arbitrary keys (Yoast per-user SEO fields, social profiles, billing) and the display name.
${WP} user meta update "${USER_ID}" wpseo_title "${CANARY} %%sep%% %%sitename%%" --quiet
${WP} user meta update "${USER_ID}" wpseo_title "${CANARY} v2 %%sep%% %%sitename%%" --quiet
${WP} user meta update "${USER_ID}" wpseo_metadesc "About ${CANARY}" --quiet
${WP} user meta update "${USER_ID}" facebook "https://facebook.example/${CANARY}" --quiet
${WP} user meta update "${USER_ID}" canary_profile_blob '{"who":"'"${CANARY}"'"}' --format=json --quiet
${WP} user update "${USER_ID}" --display_name="${CANARY}" --quiet
# User preference: still diffed in clear.
${WP} user meta update "${USER_ID}" admin_color midnight --quiet

# Non-viewable post type (form submission store): message meta.
FORM="$(${WP} eval "
  register_post_type( 'nflog_submission', [ 'public' => false ] );
  \$id = wp_insert_post( [ 'post_type' => 'nflog_submission', 'post_status' => 'publish', 'post_title' => 'Submission' ] );
  add_post_meta( \$id, 'your-message', 'Hello, my name is ${CANARY}' );
  update_post_meta( \$id, 'your-message', 'Hello again, ${CANARY}' );
  add_post_meta( \$id, '_field_data', [ 'subject' => '${CANARY}' ] );
  echo \$id;")"

# Comment meta.
POST="$(${WP} post create --post_title="Privacy canary post" --post_status=publish --porcelain)"
COMMENT="$(${WP} comment create --comment_post_ID="${POST}" --comment_content="Canary comment" --comment_approved=1 --porcelain)"
${WP} comment meta add "${COMMENT}" canary_note "Call ${CANARY} back" --quiet

# Viewable post: structural meta keeps full diffs.
${WP} post meta update "${POST}" _thumbnail_id 4242 --quiet
${WP} post meta update "${POST}" canary_layout wide --quiet

if [ "${ACF}" = "1" ]; then
  cat > "${OUT}/acf.php" <<'PHP'
<?php
acf_add_local_field_group(
	[
		'key'      => 'group_nflog_canary',
		'title'    => 'Canary',
		'fields'   => [ [ 'key' => 'field_nflog_canary_bio', 'name' => 'canary_bio', 'label' => 'Bio', 'type' => 'text' ] ],
		'location' => [ [ [ 'param' => 'user_form', 'operator' => '==', 'value' => 'all' ] ] ],
	]
);
acf_save_post( 'user_' . (int) $args[0], [ 'field_nflog_canary_bio' => 'Bio of ' . $args[1] ] );
echo 'ok';
PHP
  ${WP} eval-file "${OUT}/acf.php" "${USER_ID}" "${CANARY}" > "${OUT}/acf.out"
else
  echo "  (ACF not active, ACF canary skipped)"
fi

since "${C0}" "${OUT}/events.json"

echo "==> Shapes"
assert "user wpseo_title: meta.updated is a content summary" "${OUT}/events.json" "one('meta.updated', e => id(e, ${USER_ID}) && e.object.type==='user' && e.object.subtype==='wpseo_title' && summary(ch(e,'wpseo_title').old) && summary(ch(e,'wpseo_title').new) && e.summary==='Meta \"wpseo_title\" updated on user #${USER_ID}')"
assert "user wpseo_metadesc / facebook: summaries"           "${OUT}/events.json" "[...find('meta.added'), ...find('meta.updated')].filter(e => e.object.type==='user' && ['wpseo_metadesc','facebook'].includes(e.object.subtype)).every(e => summary(ch(e, e.object.subtype).new)) && find('meta.added', e => e.object.subtype==='facebook').length + find('meta.updated', e => e.object.subtype==='facebook').length===1"
assert "user non-scalar meta: redaction marker"             "${OUT}/events.json" "[...find('meta.added'), ...find('meta.updated')].filter(e => e.object.subtype==='canary_profile_blob').length===1 && [...find('meta.added'), ...find('meta.updated')].filter(e => e.object.subtype==='canary_profile_blob').every(e => ch(e,'canary_profile_blob').new.redacted===true)"
assert "user preference admin_color: diffed in clear"        "${OUT}/events.json" "[...find('meta.added'), ...find('meta.updated')].some(e => e.object.subtype==='admin_color' && ch(e,'admin_color').new==='midnight')"
assert "non-viewable post meta: content summary"            "${OUT}/events.json" "one('meta.updated', e => id(e, ${FORM}) && e.object.subtype==='your-message' && summary(ch(e,'your-message').new) && e.context.post_type==='nflog_submission')"
assert "comment meta: content summary"                      "${OUT}/events.json" "one('meta.added', e => e.object.type==='comment' && id(e, ${COMMENT}) && summary(ch(e,'canary_note').new))"
assert "viewable post _thumbnail_id: diffed in clear"       "${OUT}/events.json" "[...find('meta.added'), ...find('meta.updated')].some(e => id(e, ${POST}) && e.object.subtype==='_thumbnail_id' && String(ch(e,'_thumbnail_id').new)==='4242')"
assert "viewable post plain meta: diffed in clear"          "${OUT}/events.json" "[...find('meta.added'), ...find('meta.updated')].some(e => id(e, ${POST}) && ch(e,'canary_layout') && ch(e,'canary_layout').new==='wide')"
if [ "${ACF}" = "1" ]; then
  assert "ACF field on user: content summary"               "${OUT}/events.json" "one('acf.fields_saved', e => e.object.type==='user' && id(e, ${USER_ID}) && summary(ch(e,'canary_bio').new))"
fi

echo "==> Canary"
for needle in "${CANARY}" "7f3a-DISPLAYNAME" "canary.person@example.org" "${LOGIN}"; do
  if node -e "const s=JSON.stringify(JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')).body.events); process.exit(s.includes(process.argv[2]) ? 1 : 0)" "${OUT}/events.json" "${needle}"; then
    ok "no '${needle}' in events"
  else
    fail "found '${needle}' in events"
    node -e "for (const e of JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')).body.events) if (JSON.stringify(e).includes(process.argv[2])) console.log('       #'+e.id, e.action, JSON.stringify(e).slice(0,300))" "${OUT}/events.json" "${needle}"
  fi
done
assert "events captured"                                    "${OUT}/events.json" "ev.length>=10"

FAILURES_AFTER="$(failures)"
if [ "${FAILURES_BEFORE}" = "${FAILURES_AFTER}" ]; then ok "no internal logger failures (${FAILURES_AFTER})"; else fail "logger failures ${FAILURES_BEFORE} -> ${FAILURES_AFTER}"; fi

${WP} post delete "${POST}" --force --quiet > /dev/null 2>&1 || true
${WP} post delete "${FORM}" --force --quiet > /dev/null 2>&1 || true

echo
echo "smoke-privacy: ${PASS} passed, ${FAIL} failed"
[ "${FAIL}" -eq 0 ]
