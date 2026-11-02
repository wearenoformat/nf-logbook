#!/usr/bin/env bash
# Content listeners (Phase B, group 1) end to end, against the site created by setup.sh:
# WP-CLI performs each kind of content change, the signed API reads the events back
# through tools/fetch-events.mjs and the script asserts actions, objects, severities
# and that no event carries a name, email, IP, file name or path.
#
#   NFLOG_WP_DIR     site directory (default: $PWD/.local/wp)
#   NFLOG_SMOKE_ACF  1 (default) installs/activates Advanced Custom Fields from wordpress.org
#                    for the ACF checks; 0 skips them.
#
# WP-CLI runs with source "cli"; events have actor "user" (the smoke user via --user) or "cli".
# About a dozen signed API requests per run (rate limit: 120/min per key).
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WP_DIR="${NFLOG_WP_DIR:-$PWD/.local/wp}"
[ -f "${WP_DIR}/.nflog-env" ] || { echo "Run tests/integration/setup.sh first" >&2; exit 1; }
# shellcheck disable=SC1091
source "${WP_DIR}/.nflog-env"
export NFLOG_SITE NFLOG_KEY_ID NFLOG_SECRET
WP="wp --path=${WP_DIR}"
FETCH="node ${PLUGIN_DIR}/tools/fetch-events.mjs"
OUT="$(mktemp -d -t nflog-content)"
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
# assert <name> <file> <js expression>; in scope: r (fetch result), ev (events),
# find(action, pred?) -> matching events, one(action, pred?) -> exactly one match.
assert() {
  if node -e "
    const r = JSON.parse(require('fs').readFileSync(process.argv[1], 'utf8'));
    const ev = (r.body && r.body.events) || [];
    const find = (a, f = () => true) => ev.filter(e => e.action === a && f(e));
    const one = (a, f) => find(a, f).length === 1;
    const id = (e, v) => e.object && String(e.object.id) === String(v);
    const ch = (e, f) => (e.changes || []).find(c => c.field === f);
    if (!(${3})) process.exit(1);" "$2"; then
    ok "$1"
  else
    fail "$1"
    node -e "const r=JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')); for (const e of (r.body.events||[]).slice(-40)) console.log('       #'+e.id, e.severity, e.action, JSON.stringify(e.object), JSON.stringify(e.changes).slice(0,200))" "$2" || true
  fi
}
# Highest event id so far (chain head id, read through WP-CLI to stay well below the API rate limit).
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

# Smoke user with personal data that must never show up in content events.
SMOKE_LOGIN="nflogsmoke"
SMOKE_NAME="Zelda Smoketest"
SMOKE_EMAIL="zelda.smoketest@example.org"
if ! ${WP} user get "${SMOKE_LOGIN}" --field=ID > /dev/null 2>&1; then
  ${WP} user create "${SMOKE_LOGIN}" "${SMOKE_EMAIL}" --role=editor --display_name="${SMOKE_NAME}" \
    --first_name=Zelda --last_name=Smoketest --user_pass="$(php -r 'echo bin2hex(random_bytes(12));')" --quiet
fi
SMOKE_ID="$(${WP} user get "${SMOKE_LOGIN}" --field=ID)"
AS="--user=${SMOKE_LOGIN}"

ACF=0
if [ "${NFLOG_SMOKE_ACF:-1}" = "1" ]; then
  if ${WP} plugin is-active advanced-custom-fields 2>/dev/null || ${WP} plugin install advanced-custom-fields --activate --quiet > /dev/null 2>&1; then
    ACF=1
  else
    echo "  (ACF not available, ACF checks skipped)"
  fi
fi

FAILURES_BEFORE="$(failures)"
C0="$(cursor)"
echo "==> Cursor ${C0}"

echo "==> Posts"
POST="$(${WP} post create ${AS} --post_title="Content smoke alpha" --post_content="First body" --porcelain)"
${WP} post update "${POST}" ${AS} --post_title="Content smoke beta" --post_content="Body text one" --quiet
since "${C0}" "${OUT}/posts.json"
assert "post.created (info, post/post, label hash)" "${OUT}/posts.json" "one('post.created', e => id(e, ${POST}) && e.severity==='info' && e.category==='content' && e.object.subtype==='post' && /^[0-9a-f]{8}\$/.test(e.object.label_hash))"
assert "one update -> exactly one post.updated"      "${OUT}/posts.json" "find('post.updated', e => id(e, ${POST})).length===1"
assert "post.updated changes: title + content summary" "${OUT}/posts.json" "one('post.updated', e => id(e, ${POST}) && ch(e,'title').new==='Content smoke beta' && /^[0-9a-f]{8}\$/.test(ch(e,'content').new.sha256_8) && ch(e,'content').new.length===13 && !JSON.stringify(e).includes('Body text one'))"
assert "WP-CLI source, smoke user as actor"           "${OUT}/posts.json" "ev.length>0 && ev.every(e => e.request.source==='cli') && find('post.created').every(e => e.actor.type==='user' && e.actor.id==='${SMOKE_ID}')"

C1="$(cursor)"
${WP} post update "${POST}" --post_status=publish --quiet
${WP} post update "${POST}" --post_status=draft --quiet
REV="$(${WP} eval "echo (int) array_key_last( wp_get_post_revisions( ${POST} ) );")"
${WP} eval "wp_restore_post_revision( ${REV} );"
POST2="$(${WP} post create --post_title="Content smoke trash" --post_status=publish --porcelain)"
${WP} post delete "${POST2}" --quiet
${WP} eval "wp_untrash_post( ${POST2} );"
${WP} post delete "${POST2}" --force --quiet
since "${C1}" "${OUT}/status.json"
assert "post.published (notice)"                      "${OUT}/status.json" "one('post.published', e => id(e, ${POST}) && e.severity==='notice' && ch(e,'status').old==='draft' && ch(e,'status').new==='publish')"
assert "post.unpublished (notice)"                    "${OUT}/status.json" "one('post.unpublished', e => id(e, ${POST}) && e.severity==='notice')"
assert "post.revision_restored"                       "${OUT}/status.json" "one('post.revision_restored', e => id(e, ${POST}) && e.context.revision_id===${REV})"
assert "post.trashed / restored / deleted (notice)"   "${OUT}/status.json" "one('post.trashed', e => id(e, ${POST2}) && e.severity==='notice' && e.context.previous_status==='publish') && one('post.restored', e => id(e, ${POST2})) && one('post.deleted', e => id(e, ${POST2}) && e.severity==='notice')"
assert "trash/untrash produce no post.updated"        "${OUT}/status.json" "find('post.updated', e => id(e, ${POST2})).length===0"
assert "no meta noise from post deletion"             "${OUT}/status.json" "find('meta.deleted', e => id(e, ${POST2})).length===0"
assert "revisions never logged as posts"              "${OUT}/status.json" "ev.every(e => !(e.object && e.object.subtype==='revision'))"

echo "==> Meta"
C2="$(cursor)"
${WP} post meta add "${POST}" smoke_color blue --quiet
${WP} post meta update "${POST}" smoke_color red --quiet
${WP} post meta update "${POST}" _wp_page_template templates/smoke.php --quiet
${WP} post meta update "${POST}" smoke_phone "+41 79 555 00 00" --quiet
${WP} post meta update "${POST}" _edit_lock "1:1" --quiet
${WP} post meta delete "${POST}" smoke_color --quiet
${WP} user meta update "${SMOKE_LOGIN}" smoke_pref "dark-${RANDOM}" --quiet
since "${C2}" "${OUT}/meta.json"
assert "meta.added"                                   "${OUT}/meta.json" "one('meta.added', e => id(e, ${POST}) && e.object.type==='post' && e.object.subtype==='smoke_color' && e.category==='fields' && ch(e,'smoke_color').new==='blue')"
assert "meta.updated with old value"                  "${OUT}/meta.json" "one('meta.updated', e => id(e, ${POST}) && e.object.subtype==='smoke_color' && ch(e,'smoke_color').old==='blue' && ch(e,'smoke_color').new==='red')"
assert "meta.deleted"                                 "${OUT}/meta.json" "one('meta.deleted', e => id(e, ${POST}) && e.object.subtype==='smoke_color' && ch(e,'smoke_color').old==='red')"
assert "_wp_page_template logged at info"             "${OUT}/meta.json" "find('meta.added', e => e.object.subtype==='_wp_page_template' && e.severity==='info').length===1"
assert "sensitive meta value redacted"                "${OUT}/meta.json" "one('meta.added', e => e.object.subtype==='smoke_phone' && ch(e,'smoke_phone').new.redacted===true)"
assert "_edit_lock ignored"                           "${OUT}/meta.json" "ev.every(e => !(e.object && e.object.subtype==='_edit_lock'))"
assert "user meta"                                    "${OUT}/meta.json" "[...find('meta.added'), ...find('meta.updated')].filter(e => e.object.type==='user' && id(e, ${SMOKE_ID}) && e.object.subtype==='smoke_pref').length===1"

echo "==> Terms"
C3="$(cursor)"
TERM="$(${WP} term create category "Smoke Category" --porcelain)"
${WP} term update category "${TERM}" --name="Smoke Category Two" --quiet
${WP} term meta add "${TERM}" smoke_term green --quiet
${WP} post term add "${POST}" category "${TERM}" --by=id --quiet
${WP} post term remove "${POST}" category "${TERM}" --by=id --quiet
${WP} term delete category "${TERM}" --quiet
since "${C3}" "${OUT}/terms.json"
assert "term.created (taxonomy/category)"            "${OUT}/terms.json" "one('term.created', e => id(e, ${TERM}) && e.category==='taxonomy' && e.object.subtype==='category' && e.context.term_name==='Smoke Category')"
assert "term.updated name diff"                       "${OUT}/terms.json" "one('term.updated', e => id(e, ${TERM}) && ch(e,'term_name').old==='Smoke Category' && ch(e,'term_name').new==='Smoke Category Two')"
assert "term.assigned added / removed"                "${OUT}/terms.json" "one('term.assigned', e => id(e, ${POST}) && e.context.added.includes(${TERM})) && one('term.assigned', e => id(e, ${POST}) && e.context.removed.includes(${TERM}))"
assert "term.deleted"                                 "${OUT}/terms.json" "one('term.deleted', e => id(e, ${TERM}))"
assert "term meta"                                    "${OUT}/terms.json" "one('meta.added', e => e.object.type==='term' && id(e, ${TERM}))"

echo "==> Menus"
C4="$(cursor)"
MENU="$(${WP} menu create "Smoke Menu" --porcelain)"
ITEM="$(${WP} menu item add-custom "${MENU}" "Smoke link" "https://shop.example.net/path?secret=abc123" --porcelain)"
${WP} menu item update "${ITEM}" --title="Smoke link two" --quiet
${WP} eval "set_theme_mod( 'nav_menu_locations', [ 'primary' => ${MENU} ] );"
${WP} eval "set_theme_mod( 'nav_menu_locations', [] );"
${WP} menu item delete "${ITEM}" --quiet
${WP} menu delete "${MENU}" --quiet
since "${C4}" "${OUT}/menus.json"
assert "menu.created (notice)"                        "${OUT}/menus.json" "one('menu.created', e => id(e, ${MENU}) && e.severity==='notice' && e.category==='menu')"
assert "menu.item_updated: added, host only"          "${OUT}/menus.json" "one('menu.item_updated', e => id(e, ${ITEM}) && e.context.created===true && ch(e,'url_host').new==='shop.example.net')"
assert "menu.item_updated: title hash changed"        "${OUT}/menus.json" "one('menu.item_updated', e => id(e, ${ITEM}) && e.context.created===false && ch(e,'title_hash'))"
assert "menu.locations_changed x2"                    "${OUT}/menus.json" "find('menu.locations_changed', e => e.object.type==='theme' && ch(e,'location.primary')).length===2"
assert "menu.item_deleted + menu.deleted"             "${OUT}/menus.json" "one('menu.item_deleted', e => id(e, ${ITEM}) && e.context.menu_id===${MENU}) && one('menu.deleted', e => id(e, ${MENU}))"
assert "menu items are not posts/meta/terms"          "${OUT}/menus.json" "ev.every(e => !e.action.startsWith('post.') && !e.action.startsWith('meta.') && !e.action.startsWith('term.'))"
assert "no full URL / query string"                   "${OUT}/menus.json" "!JSON.stringify(ev).includes('abc123') && !JSON.stringify(ev).includes('/path')"

echo "==> Media"
C5="$(cursor)"
PNG="${OUT}/zelda-private-scan.png"
php -r '$i = imagecreatetruecolor(64, 48); imagefill($i, 0, 0, imagecolorallocate($i, 200, 40, 40)); imagepng($i, $argv[1]);' "${PNG}" 2>/dev/null \
  || php -r 'file_put_contents($argv[1], base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="));' "${PNG}"
MEDIA="$(${WP} media import "${PNG}" ${AS} --porcelain)"
${WP} media regenerate "${MEDIA}" --yes --quiet > /dev/null 2>&1 || true
${WP} post update "${MEDIA}" --post_excerpt="Smoke caption" --quiet
${WP} post delete "${MEDIA}" --force --quiet
UPLOADS="$(${WP} eval 'echo wp_upload_dir()["basedir"];')"
since "${C5}" "${OUT}/media.json"
assert "media.uploaded (mime, size, dims, file hash)" "${OUT}/media.json" "one('media.uploaded', e => id(e, ${MEDIA}) && e.category==='media' && e.severity==='info' && e.object.subtype==='image/png' && e.context.filesize>0 && e.context.width>0 && /^[0-9a-f]{8}\$/.test(e.context.file_hash))"
assert "media.metadata_updated on regenerate"         "${OUT}/media.json" "find('media.metadata_updated', e => id(e, ${MEDIA})).length<=1 && find('media.metadata_updated', e => id(e, ${MEDIA})).every(e => /^[0-9a-f]{32}\$/.test(e.fingerprint))"
assert "media.updated (caption summary)"              "${OUT}/media.json" "one('media.updated', e => id(e, ${MEDIA}) && ch(e,'caption').new.length===13)"
assert "media.deleted (notice)"                       "${OUT}/media.json" "one('media.deleted', e => id(e, ${MEDIA}) && e.severity==='notice')"
assert "attachments are not post.* events"            "${OUT}/media.json" "ev.every(e => !e.action.startsWith('post.'))"

echo "==> Comments"
C6="$(cursor)"
COMMENT="$(${WP} comment create --comment_post_ID="${POST}" --comment_author="${SMOKE_NAME}" --comment_author_email="${SMOKE_EMAIL}" \
  --comment_author_IP=198.51.100.23 --comment_author_url="https://zelda.example.org" --comment_content="Private comment body" --comment_approved=0 --porcelain)"
${WP} comment approve "${COMMENT}" --quiet
${WP} comment update "${COMMENT}" --comment_content="Edited comment body" --quiet
${WP} comment spam "${COMMENT}" --quiet
${WP} comment delete "${COMMENT}" --force --quiet
since "${C6}" "${OUT}/comments.json"
assert "comment.created (post id in context)"         "${OUT}/comments.json" "one('comment.created', e => id(e, ${COMMENT}) && e.category==='comment' && e.context.post_id===${POST} && e.context.status==='unapproved' && e.object.subtype==='comment')"
assert "comment.status_changed (approve)"             "${OUT}/comments.json" "one('comment.status_changed', e => id(e, ${COMMENT}) && ch(e,'status').new==='approved')"
assert "comment.updated (content summary)"            "${OUT}/comments.json" "one('comment.updated', e => id(e, ${COMMENT}) && ch(e,'content').new.length===19)"
assert "comment.spammed once"                         "${OUT}/comments.json" "one('comment.spammed', e => id(e, ${COMMENT}))"
assert "comment.deleted"                              "${OUT}/comments.json" "one('comment.deleted', e => id(e, ${COMMENT}))"

echo "==> Widgets and Customizer"
C7="$(cursor)"
WIDGET=$(( (RANDOM % 9000) + 100 ))
${WP} eval "\$w = get_option( 'widget_block', [] ); \$w = is_array( \$w ) ? \$w : []; \$w[ ${WIDGET} ] = [ 'content' => '<p>Widget secret text ' . wp_generate_password( 6, false ) . '</p>' ]; \$w['_multiwidget'] = 1; update_option( 'widget_block', \$w );"
${WP} eval "\$s = get_option( 'sidebars_widgets', [] ); \$s = is_array( \$s ) ? \$s : []; \$s['wp_inactive_widgets'] = array_values( array_merge( (array) ( \$s['wp_inactive_widgets'] ?? [] ), [ 'block-${WIDGET}' ] ) ); \$s['array_version'] = 3; update_option( 'sidebars_widgets', \$s );"
cat > "${OUT}/customize.php" <<'PHP'
<?php
require_once ABSPATH . WPINC . '/class-wp-customize-manager.php';
$nflog_manager = new WP_Customize_Manager( [ 'changeset_uuid' => wp_generate_uuid4(), 'settings_previewed' => false ] );
$nflog_manager->register_controls();
$nflog_result = $nflog_manager->save_changeset_post(
	[
		'data'   => [ 'blogdescription' => [ 'value' => 'Smoke tagline ' . wp_generate_password( 6, false ) ] ],
		'status' => 'publish',
	]
);
echo is_wp_error( $nflog_result ) ? 'error' : 'ok';
PHP
${WP} eval-file "${OUT}/customize.php" --user=admin > "${OUT}/customize.out"
since "${C7}" "${OUT}/theme.json"
assert "widget.updated (block, content summary)"      "${OUT}/theme.json" "one('widget.updated', e => e.object.id==='block-${WIDGET}' && e.object.subtype==='block' && e.severity==='notice' && e.category==='theme' && ch(e,'content').new.sha256_8)"
assert "sidebar.updated"                              "${OUT}/theme.json" "one('sidebar.updated', e => e.object.id==='wp_inactive_widgets' && e.context.added.includes('block-${WIDGET}'))"
assert "customizer.saved once per setting"            "${OUT}/theme.json" "one('customizer.saved', e => e.object.id==='blogdescription' && e.severity==='notice' && ch(e,'blogdescription'))"
assert "changeset post not logged as post"            "${OUT}/theme.json" "ev.every(e => !(e.object && e.object.subtype==='customize_changeset'))"

echo "==> Export / import / privacy"
C8="$(cursor)"
mkdir -p "${OUT}/export"
${WP} export --dir="${OUT}/export" --post_type=post --quiet > /dev/null 2>&1
${WP} eval 'require_once ABSPATH . "wp-admin/includes/export.php"; ob_start(); export_wp( [ "content" => "page" ] ); ob_end_clean();' > /dev/null 2>&1 || true
${WP} eval 'foreach ( get_posts( [ "post_type" => "user_request", "post_status" => "any", "numberposts" => -1, "fields" => "ids" ] ) as $nflog_id ) { wp_delete_post( $nflog_id, true ); }'
${WP} eval 'do_action( "import_start" ); do_action( "import_end" );'
${WP} eval "\$r = wp_create_user_request( '${SMOKE_EMAIL}', 'export_personal_data' ); remove_action( 'wp_privacy_personal_data_export_file', 'wp_privacy_generate_personal_data_export_file', 10 ); do_action( 'wp_privacy_personal_data_export_file', \$r );"
${WP} eval "\$r = wp_create_user_request( '${SMOKE_EMAIL}', 'remove_personal_data' ); do_action( 'wp_privacy_personal_data_erased', \$r );"
since "${C8}" "${OUT}/privacy.json"
assert "privacy.export_started (wp export + export_wp)" "${OUT}/privacy.json" "one('privacy.export_started', e => e.context.via==='wp-cli') && one('privacy.export_started', e => e.context.via==='export_wp' && e.context.content==='page' && e.severity==='notice')"
assert "privacy.import_started / finished"            "${OUT}/privacy.json" "one('privacy.import_started') && one('privacy.import_finished')"
assert "personal data exported/erased -> user id"     "${OUT}/privacy.json" "one('privacy.personal_data_exported', e => e.object.type==='user' && id(e, ${SMOKE_ID})) && one('privacy.personal_data_erased', e => e.object.type==='user' && id(e, ${SMOKE_ID}) && e.context.request_id>0)"
assert "user_request posts not logged"                "${OUT}/privacy.json" "ev.every(e => !e.action.startsWith('post.') && !e.action.startsWith('meta.'))"

if [ "${ACF}" = "1" ]; then
  echo "==> ACF"
  C9="$(cursor)"
  cat > "${OUT}/acf.php" <<'PHP'
<?php
acf_add_local_field_group(
	[
		'key'      => 'group_nflog_smoke',
		'title'    => 'Smoke',
		'fields'   => [
			[ 'key' => 'field_nflog_hero', 'name' => 'hero', 'label' => 'Hero', 'type' => 'text' ],
			[ 'key' => 'field_nflog_contact_email', 'name' => 'contact_email', 'label' => 'Email', 'type' => 'email' ],
		],
		'location' => [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ] ] ],
	]
);
$nflog_id = (int) $args[0];
acf_save_post( $nflog_id, [ 'field_nflog_hero' => 'Hero ' . wp_generate_password( 6, false ), 'field_nflog_contact_email' => 'acf.secret@example.org' ] );
acf_save_post( 'options', [ 'field_nflog_hero' => 'Options hero ' . wp_generate_password( 6, false ) ] );
acf_update_field_group( [ 'key' => 'group_nflog_db_smoke', 'title' => 'DB smoke group', 'fields' => [], 'location' => [] ] );
echo 'ok';
PHP
  ${WP} eval-file "${OUT}/acf.php" "${POST}" ${AS} > "${OUT}/acf.out"
  since "${C9}" "${OUT}/acf.json"
  assert "acf.fields_saved: one grouped event"        "${OUT}/acf.json" "one('acf.fields_saved', e => e.object.type==='post' && id(e, ${POST}) && e.category==='fields' && ch(e,'hero') && ch(e,'contact_email').new.redacted===true)"
  assert "ACF meta writes not logged per key"         "${OUT}/acf.json" "ev.every(e => !(e.action.startsWith('meta.') && ['hero','_hero','contact_email','_contact_email'].includes(e.object.subtype)))"
  assert "acf.options_saved (notice)"                 "${OUT}/acf.json" "one('acf.options_saved', e => e.object.type==='options' && e.severity==='notice')"
  assert "acf.field_group_changed (notice)"           "${OUT}/acf.json" "find('acf.field_group_changed', e => e.object.subtype==='acf-field-group' && e.context.field_group==='group_nflog_db_smoke' && e.severity==='notice').length===1"
  assert "field group post not logged as post"        "${OUT}/acf.json" "ev.every(e => !e.action.startsWith('post.'))"
  ${WP} eval 'foreach ( get_posts( [ "post_type" => "acf-field-group", "name" => "group_nflog_db_smoke", "post_status" => "any", "numberposts" => -1 ] ) as $p ) { wp_delete_post( $p->ID, true ); }' > /dev/null 2>&1 || true
fi

echo "==> Privacy across all content events"
since "${C0}" "${OUT}/all.json"
for needle in "${SMOKE_NAME}" "${SMOKE_EMAIL}" "${SMOKE_LOGIN}" "Zelda" "zelda" "198.51.100.23" "Private comment body" "Edited comment body" \
              "Widget secret text" "acf.secret@example.org" "zelda-private-scan" "${UPLOADS}" "${WP_DIR}" "/uploads/"; do
  if node -e "const s=JSON.stringify(JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')).body.events); process.exit(s.includes(process.argv[2]) ? 1 : 0)" "${OUT}/all.json" "${needle}"; then
    ok "no '${needle}' in events"
  else
    fail "found '${needle}' in events"
    node -e "for (const e of JSON.parse(require('fs').readFileSync(process.argv[1],'utf8')).body.events) if (JSON.stringify(e).includes(process.argv[2])) console.log('       #'+e.id, e.action, JSON.stringify(e).slice(0,300))" "${OUT}/all.json" "${needle}"
  fi
done
assert "summaries carry ids, not titles"            "${OUT}/all.json" "ev.every(e => !/Content smoke|Smoke Category|Smoke Menu|Smoke link/.test(e.summary))"
assert "events captured for the whole run"          "${OUT}/all.json" "ev.length>40"
${FETCH} --endpoint events --verify --limit 500 > "${OUT}/verify.json" && ok "client-side chain verify" || fail "client-side chain verify"

FAILURES_AFTER="$(failures)"
if [ "${FAILURES_BEFORE}" = "${FAILURES_AFTER}" ]; then ok "no internal logger failures (${FAILURES_AFTER})"; else fail "logger failures ${FAILURES_BEFORE} -> ${FAILURES_AFTER}"; fi

${WP} post delete "${POST}" --force --quiet || true

echo
echo "smoke-content: ${PASS} passed, ${FAIL} failed"
[ "${FAIL}" -eq 0 ]
