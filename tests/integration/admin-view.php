<?php
/**
 * Renders the wp-admin screens (Content log, System log, Status, Settings,
 * dashboard widget) inside WP-CLI (wp eval-file) and prints JSON checks for
 * smoke-admin.sh: {"checks": [[name, ok, detail], ...]}.
 *
 *   wp eval-file tests/integration/admin-view.php <mode> [key=value ...]
 *
 * Modes: admin (default access), restricted (events_visible_to=capability_only).
 * Keys: admin=<user id>, viewer=<user id>, post=<post id>, xss=<post id>, display=<admin display name>, post_title=<title>,
 *       story=<post id updated with two meta keys in one process, plus one lone meta update>.
 *
 * @package Noformat\Logger
 */

// phpcs:ignoreFile

use Noformat\Logger\Admin\Access;
use Noformat\Logger\Admin\ContentLogPage;
use Noformat\Logger\Admin\DashboardWidget;
use Noformat\Logger\Admin\EventFilters;
use Noformat\Logger\Admin\Export;
use Noformat\Logger\Admin\Menu;
use Noformat\Logger\Admin\Scope;
use Noformat\Logger\Admin\SettingsPage;
use Noformat\Logger\Admin\StatusPage;
use Noformat\Logger\Admin\SystemLogPage;
use Noformat\Logger\Plugin;

$mode = $args[0] ?? 'admin';
$opt  = [];
foreach ( array_slice( $args, 1 ) as $pair ) {
	[ $k, $v ] = array_pad( explode( '=', $pair, 2 ), 2, '' );
	$opt[ $k ] = $v;
}
$checks = [];
$check  = static function ( string $name, bool $ok, string $detail = '' ) use ( &$checks ): void {
	$checks[] = [ $name, $ok, $detail ];
};

require_once ABSPATH . 'wp-admin/includes/admin.php';
$_SERVER['HTTP_HOST']   = (string) wp_parse_url( home_url(), PHP_URL_HOST ) . ':' . (string) wp_parse_url( home_url(), PHP_URL_PORT );
$_SERVER['REQUEST_URI'] = '/wp-admin/tools.php?page=nf-logbook&tab=content';
set_current_screen( 'tools_page_nf-logbook' );

$plugin = Plugin::instance();
if ( null === $plugin ) {
	echo wp_json_encode( [ 'checks' => [ [ 'plugin booted', false, '' ] ] ] );
	return;
}

/** Render a page object with GET params as the given user. */
$render = static function ( string $page, array $get, int $user ) use ( $plugin ): string {
	wp_set_current_user( $user );
	$_GET     = array_merge( [ 'page' => $page ], $get );
	$_REQUEST = $_GET;
	ob_start();
	match ( $page ) {
		'content'  => ( new ContentLogPage( $plugin ) )->render(),
		'system'   => ( new SystemLogPage( $plugin ) )->render(),
		'status'   => ( new StatusPage( $plugin ) )->render(),
		'settings' => ( new SettingsPage( $plugin ) )->render(),
	};
	return (string) ob_get_clean();
};
$content = static fn( array $get, int $user ): string => $render( 'content', $get, $user );
$system  = static fn( array $get, int $user ): string => $render( 'system', $get, $user );
/** Severities of the rendered feed entries, in order. */
$entries = static function ( string $html ): array {
	preg_match_all( '/<li class="nflog-entry nflog-entry--(\w+)"/', $html, $m );
	return $m[1];
};
/** Plain-text sentences of the feed. */
$sentences = static function ( string $html ): array {
	preg_match_all( '#<p class="nflog-entry__sentence">(.*?)</p>#s', $html, $m );
	return array_map( static fn( string $s ): string => html_entity_decode( trim( preg_replace( '/\s+/', ' ', strip_tags( $s ) ) ), ENT_QUOTES ), $m[1] );
};
/** Console payload of the System log. */
$payload = static function ( string $html ): ?array {
	if ( 1 !== preg_match( '#<script type="application/json" id="nflog-console-data">(.*?)</script>#s', $html, $m ) ) {
		return null;
	}
	$data = json_decode( $m[1], true );
	return is_array( $data ) ? $data : null;
};
$widget = static function ( int $user ) use ( $plugin ): string {
	wp_set_current_user( $user );
	ob_start();
	( new DashboardWidget( $plugin ) )->render();
	return (string) ob_get_clean();
};

$admin  = (int) ( $opt['admin'] ?? 1 );
$viewer = (int) ( $opt['viewer'] ?? 0 );
$contentCats = Scope::content();
$systemCats  = Scope::system();

if ( 'admin' === $mode ) {
	$check( 'default access modes are administrators', Access::ADMINISTRATORS === Access::mode( $plugin->config() ) && Access::ADMINISTRATORS === Access::systemMode( $plugin->config() ) );

	$html = $content( [], $admin );
	$sev  = $entries( $html );
	$says = $sentences( $html );
	$check( 'content log renders the feed', str_contains( $html, 'nflog-page--content' ) && count( $sev ) >= 3, implode( ',', $sev ) );
	$check( 'content log groups entries by day ("Today")', 1 === preg_match( '#<h3 class="nflog-day__label"><span>Today</span></h3>#', $html ) );
	$check( 'content log default period is 30 days', 1 === preg_match( '#class="nflog-chip is-active"[^>]*>30 days<#', $html ) );
	$check( 'content log has the export link with nonce', str_contains( $html, 'nflog_export=json' ) && str_contains( $html, '_wpnonce=' ) );
	$check( 'content log shows no system events', ! str_contains( $html, 'Test event from WP-CLI' ) && ! str_contains( $html, 'Failed login' ) );
	$check( 'needs-attention strip links to the System log', str_contains( $html, 'nflog-attention' ) && 1 === preg_match( '#tools\.php\?page=nf-logbook(&|&\#038;|&amp;)tab=system#', $html ) );

	$postId  = (int) ( $opt['post'] ?? 0 );
	$title   = (string) ( $opt['post_title'] ?? '' );
	$display = (string) ( $opt['display'] ?? '' );
	$postView = $content( [ 'object_type' => 'post', 'object_id' => (string) $postId, 'window' => 'all' ], $admin );
	$postSays = $sentences( $postView );
	$check( 'humanised sentence: "<admin> published the post <title>"', [] !== array_filter( $postSays, static fn( string $s ): bool => $s === $display . ' published the post ' . $title || str_starts_with( $s, $display . ' published the post ' . $title . ' and ' ) ), implode( ' | ', $postSays ) );
	$check( 'post title linked to the editor', 1 === preg_match( '#<a class="nflog-object-link" href="[^"]*post\.php\?post=' . $postId . '(&|&\#038;|&amp;)action=edit"><em>' . preg_quote( esc_html( $title ), '#' ) . '</em></a>#', $postView ) );
	$check( 'what-changed expander with aria-expanded and a before/after table', str_contains( $postView, 'aria-expanded="false"' ) && str_contains( $postView, 'class="nflog-diff"' ) && str_contains( $postView, '<ins>' ) );
	$check( 'detail footer links the request ("show everything from this save")', 1 === preg_match( '/request_id=[0-9a-f]{32}/', $postView ) && str_contains( $postView, 'Show everything from this save' ) );

	$xss     = (int) ( $opt['xss'] ?? 0 );
	$raw     = (string) get_post_field( 'post_title', $xss );
	$check( 'xss fixture stored raw', '<script>alert(1)</script>' === $raw, $raw );
	$xssView = $content( [ 'object_type' => 'post', 'object_id' => (string) $xss, 'window' => 'all' ], $admin );
	$check( 'content log output escaped: no literal <script> from the title', [] !== $entries( $xssView ) && ! str_contains( $xssView, '<script>alert(1)</script>' ) && str_contains( $xssView, '&lt;script&gt;alert(1)&lt;/script&gt;' ) );

	$important = $entries( $content( [ 'important' => '1', 'window' => 'all' ], $admin ) );
	$check( 'importance filter: notice and above only', [] !== $important && [] === array_diff( $important, [ 'critical', 'error', 'warning', 'notice' ] ), implode( ',', $important ) );
	$posts = $sentences( $content( [ 'type' => 'posts', 'window' => 'all' ], $admin ) );
	$check( 'type filter (posts)', [] !== $posts && [] === array_filter( $posts, static fn( string $s ): bool => str_contains( $s, 'the setting' ) ), (string) count( $posts ) );
	$person = $content( [ 'person' => (string) $admin, 'window' => 'all' ], $admin );
	$check( 'person filter (user id)', [] !== $entries( $person ) && [] === array_filter( $sentences( $person ), static fn( string $s ): bool => str_starts_with( $s, 'WP-CLI' ) ) );
	$future = $content( [ 'from' => gmdate( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ) ], $admin );
	$check( 'empty state names the filter', [] === $entries( $future ) && str_contains( $future, 'No events match: from ' ) && str_contains( $future, 'nflog-empty' ) );

	$story = (int) ( $opt['story'] ?? 0 );
	if ( $story > 0 ) {
		$sv    = $content( [ 'object_type' => 'post', 'object_id' => (string) $story, 'window' => 'all' ], $admin );
		$says  = $sentences( $sv );
		$grouped = array_values( array_filter( $says, static fn( string $s ): bool => str_contains( $s, 'and 2 fields changed' ) ) );
		$check( 'story: post update + 2 meta keys in one request is one entry "… and 2 fields changed"', 1 === count( $grouped ) && str_starts_with( $grouped[0], $display . ' updated the ' ), implode( ' | ', $says ) );
		$check( 'story: no separate entries for the grouped meta keys', [] === array_filter( $says, static fn( string $s ): bool => str_contains( $s, 'story_color' ) || str_contains( $s, 'story_size' ) ), implode( ' | ', $says ) );
		$lone = array_values( array_filter( $says, static fn( string $s ): bool => str_contains( $s, 'story_lone' ) ) );
		$check( 'story: a lone meta update keeps its own sentence', 1 === count( $lone ) && str_starts_with( $lone[0], $display . ' set the field story_lone on the post' ) && ! str_contains( $lone[0], ' and ' ), implode( ' | ', $lone ) );
		$check( 'story: expanded panel lists the secondary fields with before/after', str_contains( $sv, 'nflog-diff--also' ) && str_contains( $sv, 'Also in this save (2 events)' ) && str_contains( $sv, '<code class="nflog-diff__key">story_color</code>' ) && str_contains( $sv, '<ins>blue</ins>' ) );
		$check( 'story: muted suffix markup', str_contains( $sv, '<span class="nflog-entry__also">and 2 fields changed</span>' ) );
	}

	$filters = EventFilters::scoped( [], $contentCats );
	$events  = $plugin->repository()->query( $filters, 1, 5000, 'time', 'desc' );
	$keys    = [];
	foreach ( $events as $e ) {
		$keys[ '' === (string) $e['request_id'] ? '#' . $e['id'] : (string) $e['request_id'] ] = true;
	}
	$pageData = $plugin->repository()->storiesPage( $filters, 1, 200 );
	$check( 'pagination total counts stories, not events', count( $keys ) === $pageData['total'] && $pageData['total'] < count( $events ) && count( $pageData['stories'] ) === min( 200, $pageData['total'] ), count( $keys ) . ' stories / ' . count( $events ) . ' events / total ' . $pageData['total'] );
	$all = $content( [ 'window' => 'all' ], $admin );
	$check( 'feed count says entries (stories)', str_contains( $all, ' of ' . number_format_i18n( $pageData['total'] ) . ' entr' ), (string) $pageData['total'] );
	wp_set_current_user( $admin );
	update_user_option( $admin, ContentLogPage::PER_PAGE_OPT, 2 );
	$p1 = $entries( $content( [ 'window' => 'all' ], $admin ) );
	$p2 = $content( [ 'window' => 'all', 'paged' => '2' ], $admin );
	delete_user_option( $admin, ContentLogPage::PER_PAGE_OPT );
	$check( 'pagination pages over stories (2 per page)', 2 === count( $p1 ) && 2 === count( $entries( $p2 ) ) && str_contains( $p2, '3–4 of ' ), implode( ',', $p1 ) );

	$technical = array_filter( $plugin->repository()->query( [ 'action_prefix' => 'meta.' ], 1, 5000 ), static fn( array $e ): bool => str_contains( (string) $e['context'], 'aiowps_last_login_time' ) );
	$check( 'technical meta key (aiowps_last_login_time) not stored with log_debug off', [] === $technical && ! str_contains( $all, 'aiowps_last_login_time' ), (string) count( $technical ) );

	$sys  = $system( [], $admin );
	$data = $payload( $sys );
	$cats = is_array( $data ) ? array_unique( array_column( $data['lines'], 'category' ) ) : [];
	$check( 'system log renders the console markup', str_contains( $sys, 'nflog-console__bar' ) && str_contains( $sys, 'data-nflog-lines' ) && str_contains( $sys, 'id="nflog-q"' ) && str_contains( $sys, 'nflog-stats' ) );
	$check( 'system log JSON payload with lines, nonce and tail settings', is_array( $data ) && count( $data['lines'] ) >= 3 && '' !== (string) $data['ajax']['nonce'] && 5000 === $data['follow']['interval'], is_array( $data ) ? (string) count( $data['lines'] ) : 'no payload' );
	$check( 'system log lines are system categories only', [] !== $cats && [] === array_diff( $cats, $systemCats ), implode( ',', $cats ) );
	$line = is_array( $data ) ? ( $data['lines'][0] ?? [] ) : [];
	$check( 'console line: CONTRACT event + display fields', isset( $line['id'], $line['severity'], $line['action'], $line['hash'], $line['prev_hash'], $line['_time'], $line['_msg'] ) && 1 === preg_match( '/^\d\d:\d\d:\d\d\.\d{3}$/', (string) $line['_time'] ) );
	$check( 'payload JSON is script-safe (no raw < in the script element)', 1 === preg_match( '#<script type="application/json" id="nflog-console-data">([^<]*)</script>#', $sys ) );
	$q = $payload( $system( [ 'q' => 'action:logger.test_event', 'window' => 'all' ], $admin ) );
	$check( 'console filter text (parseQuery) narrows the lines', is_array( $q ) && [] !== $q['lines'] && [ 'logger.test_event' ] === array_values( array_unique( array_column( $q['lines'], 'action' ) ) ) );
	$lv = $payload( $system( [ 'levels' => [ 'critical' ], 'window' => 'all' ], $admin ) );
	$check( 'severity chips (levels[]) filter', is_array( $lv ) && [] !== $lv['lines'] && [ 'critical' ] === array_values( array_unique( array_column( $lv['lines'], 'severity' ) ) ) );
	$cross = $payload( $system( [ 'cats' => 'content', 'window' => 'all' ], $admin ) );
	$check( 'a content category asked of the System log matches nothing', is_array( $cross ) && [] === $cross['lines'] );

	$status = $render( 'status', [], $admin );
	$check( 'status page: cards, key management and verify button', str_contains( $status, 'nflog-status-card' ) && str_contains( $status, 'Plugin version' ) && str_contains( $status, 'data-nflog-verify' ) && str_contains( $status, 'Scheduled jobs' ) );
	$check( 'status page: Updates card with source and Check now (administrator)', str_contains( $status, '>Updates</h2>' ) && str_contains( $status, 'GitHub wearenoformat/nf-logbook' ) && str_contains( $status, 'name="nflog_update_check"' ) );
	$settings = $render( 'settings', [], $admin );
	$check( 'settings page: options.php form with both access settings and modules', str_contains( $settings, 'action="options.php"' ) && str_contains( $settings, '[events_visible_to]' ) && str_contains( $settings, '[system_log_visible_to]' ) && str_contains( $settings, '[modules][PostListener]' ) && str_contains( $settings, 'option_page' ) );
	$check( 'settings page uses human labels, option names unchanged', str_contains( $settings, 'Retention (days)' ) && str_contains( $settings, 'Maximum stored events' ) && str_contains( $settings, 'Delete data on uninstall' ) && str_contains( $settings, '[max_rows]' ) && str_contains( $settings, 'inputmode="numeric"' ) );

	wp_set_current_user( $admin );
	$keys = [ 'id', 'occurred_at', 'severity', 'category', 'action', 'summary', 'object', 'actor', 'request', 'changes', 'context', 'fingerprint', 'count', 'site', 'producer', 'hash', 'prev_hash' ];
	$exporter = new Export( $plugin );
	$json = '';
	$exporter->write( Scope::SYSTEM, [], static function ( string $c ) use ( &$json ): void { $json .= $c; } );
	$sexp = json_decode( $json, true );
	$check( 'system export contains system categories only', is_array( $sexp ) && [] !== $sexp['events'] && [] === array_filter( $sexp['events'], static fn( $e ) => ! in_array( $e['category'], $systemCats, true ) ) );
	$json     = '';
	$n        = $exporter->write( Scope::CONTENT, [ 'severity' => 'notice', 'severity_mode' => 'min' ], static function ( string $c ) use ( &$json ): void { $json .= $c; } );
	$exp      = json_decode( $json, true );
	$check( 'content export JSON parses', is_array( $exp ) && is_array( $exp['events'] ?? null ), substr( $json, 0, 200 ) );
	$check( 'content export has the CONTRACT shape', is_array( $exp ) && $n > 0 && count( $exp['events'] ) === $n && [] === array_filter( $exp['events'], static fn( $e ) => array_keys( $e ) !== $keys ) );
	$check( 'content export respects filter and scope', is_array( $exp ) && 'content' === $exp['scope'] && [] === array_filter( $exp['events'], static fn( $e ) => in_array( $e['severity'], [ 'info', 'debug' ], true ) || ! in_array( $e['category'], $contentCats, true ) ) );

	$w = $widget( $admin );
	$check( 'dashboard widget: recent activity sentences', 1 === preg_match_all( '#<li class="nflog-widget__item">#', $w ) || substr_count( $w, 'nflog-widget__item' ) >= 1, substr( strip_tags( $w ), 0, 200 ) );
	$check( 'dashboard widget: at most 5 entries and the system line', substr_count( $w, '<li class="nflog-widget__item">' ) <= 5 && str_contains( $w, 'nflog-widget__system' ) );
	$recent = $plugin->repository()->storiesPage( EventFilters::scoped( [], $contentCats ), 1, 5, false );
	$check( 'dashboard widget: 5 stories, one item each', min( 5, count( $recent['stories'] ) ) === substr_count( $w, '<li class="nflog-widget__item">' ) && substr_count( $w, 'class="nflog-widget__sentence"' ) === substr_count( $w, '<li class="nflog-widget__item">' ) );
	$check( 'widget output escaped', ! str_contains( $w, '<script>' ) );
}

if ( 'restricted' === $mode ) {
	$check( 'access mode is capability_only', Access::CAPABILITY_ONLY === Access::mode( $plugin->config() ) );
	wp_set_current_user( $admin );
	$check( 'administrator tabs: System log, Status, Settings (no Content log)', [ Menu::SYSTEM, Menu::STATUS, Menu::SETTINGS ] === Access::tabs( $plugin->config() ), implode( ',', Access::tabs( $plugin->config() ) ) );
	$html = $content( [], $admin );
	$check( 'administrator without the capability gets the no-permission view', str_contains( $html, 'You do not have permission to view the Noformat Logbook event log.' ) && [] === $entries( $html ) && ! str_contains( $html, 'nflog_export=json' ) );
	$check( 'administrator widget: nothing rendered', '' === trim( $widget( $admin ) ) );
	$check( 'administrator keeps the System log (separate setting)', Access::canViewSystemLog( $plugin->config() ) );

	wp_set_current_user( $viewer );
	$check( 'viewer has the capability', current_user_can( Plugin::CAP_VIEW_EVENTS ) && ! current_user_can( 'manage_options' ) );
	$check( 'viewer tabs: Content log, System log, Status (no Settings)', [ Menu::CONTENT, Menu::SYSTEM, Menu::STATUS ] === Access::tabs( $plugin->config() ), implode( ',', Access::tabs( $plugin->config() ) ) );
	$check( 'viewer with the capability reads the System log but cannot resolve issues (manage_options)', Access::canViewSystemLog( $plugin->config() ) && ! Access::canResolve( $plugin->config() ) );
	$v = $content( [ 'window' => 'all' ], $viewer );
	$check( 'user granted the capability sees the content log', count( $entries( $v ) ) >= 3 );
	$va = $sentences( $content( [ 'person' => (string) $admin, 'window' => 'all' ], $viewer ) );
	$check( 'viewer without list_users: actors stay ids', [] !== $va && ! str_contains( implode( ' ', $va ), (string) ( $opt['display'] ?? '' ) ) && str_starts_with( $va[0], 'User #' . $admin ), $va[0] ?? '' );
	$vp = $content( [ 'object_type' => 'post', 'object_id' => (string) ( $opt['post'] ?? '0' ), 'window' => 'all' ], $viewer );
	$check( 'viewer without edit_post: titles without edit links', [] !== $entries( $vp ) && ! str_contains( $vp, 'action=edit' ) && str_contains( $vp, esc_html( (string) ( $opt['post_title'] ?? '' ) ) ) );
	$check( 'viewer widget renders', str_contains( $widget( $viewer ), 'nflog-widget__item' ) );
	$check( 'viewer sees the System log too (capability)', is_array( $payload( $system( [], $viewer ) ) ) );
}

echo wp_json_encode( [ 'checks' => $checks ] );
