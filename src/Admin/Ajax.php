<?php
/**
 * Admin AJAX endpoints.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Admin;

use Noformat\Logger\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * `nflog_tail`: System log lines after (follow mode) or before ("Load older")
 * an event id for the console's filters, at most 200, in the shape the console
 * renders. `nflog_verify`: recompute the hash chain over the last 1 000 events
 * (Status page). Both check a nonce and access; both are read-only.
 *
 * `nflog_resolve` (POST `issue`, `op` resolve|reopen) and `nflog_resolve_all`
 * (POST, the console's filters) are the only writes: nonce, manage_options and
 * System log access (Access::canResolve()); they change resolution state only.
 */
final class Ajax {

	public const TAIL_NONCE    = 'nflog_tail';
	public const VERIFY_NONCE  = 'nflog_verify';
	public const VERIFY_ROWS   = 1000;
	public const RESOLVE_NONCE = 'nflog_resolve';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {
	}

	/**
	 * Hooks (logged-in admin-ajax only).
	 */
	public function hooks(): void {
		add_action( 'wp_ajax_nflog_tail', [ $this, 'tail' ] );
		add_action( 'wp_ajax_nflog_verify', [ $this, 'verify' ] );
		add_action( 'wp_ajax_nflog_resolve', [ $this, 'resolve' ] );
		add_action( 'wp_ajax_nflog_resolve_all', [ $this, 'resolveAll' ] );
	}

	/**
	 * Nonce, method and capability of the write endpoints (sends the error and exits).
	 */
	private function guardWrite(): void {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			wp_send_json_error( [ 'message' => __( 'Bad request.', 'nf-logbook' ) ], 405 );
		}
		if ( false === check_ajax_referer( self::RESOLVE_NONCE, '_ajax_nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Your session expired. Reload the page.', 'nf-logbook' ) ], 403 );
		}
		if ( ! Access::canResolve( $this->plugin->config() ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to resolve issues.', 'nf-logbook' ) ], 403 );
		}
	}

	/**
	 * Resolve or reopen one issue: {op, key, fingerprint, event_id, through_id?, resolved?}.
	 */
	public function resolve(): void {
		$this->guardWrite();
		$issue = isset( $_POST['issue'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['issue'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guardWrite().
		$op    = isset( $_POST['op'] ) && 'reopen' === $_POST['op'] ? 'reopen' : 'resolve'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guardWrite().
		try {
			$result = 'reopen' === $op ? $this->plugin->issues()->reopen( $issue ) : $this->plugin->issues()->resolve( $issue, get_current_user_id() );
		} catch ( \DomainException $e ) {
			wp_send_json_error( [ 'message' => self::issueError( $e->getMessage() ) ], 400 );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => __( 'The event log storage is not available.', 'nf-logbook' ) ], 500 );
		}
		$out = [
			'op'          => $op,
			'key'         => $result['key'],
			'fingerprint' => $result['fingerprint'],
			'event_id'    => $result['event_id'],
		];
		if ( isset( $result['through_id'], $result['resolved_at'] ) ) {
			$out['through_id'] = $result['through_id'];
			$out['resolved']   = SystemLogPage::resolvedInfo(
				[
					'issue_key'   => $result['key'],
					'fingerprint' => $result['fingerprint'],
					'event_id'    => $result['event_id'],
					'through_id'  => $result['through_id'],
					'resolved_at' => $result['resolved_at'],
					'resolved_by' => $result['resolved_by'] ?? null,
				],
				new Resolver()
			);
		}
		wp_send_json_success( $out );
	}

	/**
	 * Resolve every open warning/error/critical issue in the console's filters (at most 200 per call).
	 */
	public function resolveAll(): void {
		$this->guardWrite();
		$input = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guardWrite().
		unset( $input['action'], $input['_ajax_nonce'] );
		$clean = SystemLogPage::withDefaults( EventFilters::fromInput( is_array( $input ) ? $input : [] ) );
		unset( $clean['resolved'] );
		try {
			$result = $this->plugin->issues()->resolveAllOpen( SystemLogPage::repositoryFilters( $clean ), get_current_user_id() );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => __( 'The event log storage is not available.', 'nf-logbook' ) ], 500 );
		}
		wp_send_json_success(
			$result + [
				'message' => $result['more']
					/* translators: %s: number of issues */
					? sprintf( __( '%s issues resolved; more remain, run it again.', 'nf-logbook' ), number_format_i18n( $result['resolved'] ) )
					/* translators: %s: number of issues */
					: sprintf( _n( '%s issue resolved.', '%s issues resolved.', $result['resolved'], 'nf-logbook' ), number_format_i18n( $result['resolved'] ) ),
			]
		);
	}

	/**
	 * Message for an Issues error code.
	 *
	 * @param string $code invalid_reference|not_found|not_an_issue|not_resolved.
	 */
	public static function issueError( string $code ): string {
		return match ( $code ) {
			'not_found'    => __( 'No event belongs to this issue (pruned?).', 'nf-logbook' ),
			'not_an_issue' => __( 'Only warnings, errors and critical events can be resolved.', 'nf-logbook' ),
			'not_resolved' => __( 'This issue is not resolved.', 'nf-logbook' ),
			default        => __( 'Unknown issue.', 'nf-logbook' ),
		};
	}

	/**
	 * Console lines as JSON: {lines, newest, oldest, hasMore}.
	 */
	public function tail(): void {
		if ( false === check_ajax_referer( self::TAIL_NONCE, '_ajax_nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Your session expired. Reload the page.', 'nf-logbook' ) ], 403 );
		}
		if ( ! Access::canViewSystemLog( $this->plugin->config() ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to read the System log.', 'nf-logbook' ) ], 403 );
		}
		$input  = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above.
		$after  = isset( $input['after'] ) && is_numeric( $input['after'] ) ? max( 0, (int) $input['after'] ) : 0;
		$before = isset( $input['before'] ) && is_numeric( $input['before'] ) ? max( 0, (int) $input['before'] ) : 0;
		unset( $input['after'], $input['before'], $input['action'], $input['_ajax_nonce'] );
		$clean   = SystemLogPage::withDefaults( EventFilters::fromInput( is_array( $input ) ? $input : [] ) );
		$filters = SystemLogPage::repositoryFilters( $clean );
		if ( $after > 0 ) {
			$filters['after_id'] = $after;
		}
		if ( $before > 0 ) {
			$filters['before_id'] = $before;
		}
		try {
			$repo = $this->plugin->repository();
			$rows = $repo->query( $filters, 1, SystemLogPage::LINES + 1, 'time', $after > 0 ? 'asc' : 'desc' );
			$more = count( $rows ) > SystemLogPage::LINES;
			$rows = array_slice( $rows, 0, SystemLogPage::LINES );
			if ( $after > 0 ) {
				$rows = array_reverse( $rows );
			}
			$resolver = new Resolver();
			$resolver->prime( $rows );
			$lines = SystemLogPage::lines( $repo, $resolver, $rows );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => __( 'The event log storage is not available.', 'nf-logbook' ) ], 500 );
		}
		$ids = array_map( static fn( array $line ): int => (int) $line['id'], $lines );
		wp_send_json_success(
			[
				'lines'   => $lines,
				'newest'  => [] === $ids ? null : max( $ids ),
				'oldest'  => [] === $ids ? null : min( $ids ),
				'hasMore' => $more,
			]
		);
	}

	/**
	 * Chain verification over the newest 1 000 events.
	 */
	public function verify(): void {
		if ( false === check_ajax_referer( self::VERIFY_NONCE, '_ajax_nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Your session expired. Reload the page.', 'nf-logbook' ) ], 403 );
		}
		$config = $this->plugin->config();
		if ( ! current_user_can( 'manage_options' ) && ! Access::canViewSystemLog( $config ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to verify the chain.', 'nf-logbook' ) ], 403 );
		}
		try {
			$repo   = $this->plugin->repository();
			$stats  = $repo->stats();
			$from   = max( 1, $stats['min_id'], $stats['max_id'] - self::VERIFY_ROWS + 1 );
			$result = $repo->verify( $from, null, self::VERIFY_ROWS );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => __( 'The event log storage is not available.', 'nf-logbook' ) ], 500 );
		}
		$ok    = (bool) ( $result['ok'] ?? false );
		$break = is_array( $result['break'] ?? null ) ? $result['break'] : null;
		wp_send_json_success(
			[
				'ok'      => $ok,
				'checked' => (int) ( $result['checked'] ?? 0 ),
				'from'    => (int) ( $result['from'] ?? 0 ),
				'to'      => (int) ( $result['to'] ?? 0 ),
				'break'   => null === $break ? null : [
					'id'     => (int) ( $break['id'] ?? 0 ),
					'reason' => is_scalar( $break['reason'] ?? null ) ? (string) $break['reason'] : '',
				],
				'message' => $ok
					/* translators: 1: number of events, 2: first id, 3: last id */
					? sprintf( __( 'Chain intact: %1$s events checked (#%2$s–#%3$s).', 'nf-logbook' ), number_format_i18n( (int) ( $result['checked'] ?? 0 ) ), number_format_i18n( (int) ( $result['from'] ?? 0 ) ), number_format_i18n( (int) ( $result['to'] ?? 0 ) ) )
					/* translators: 1: event id, 2: reason */
					: sprintf( __( 'Chain broken at event #%1$s (%2$s).', 'nf-logbook' ), number_format_i18n( (int) ( $break['id'] ?? 0 ) ), is_scalar( $break['reason'] ?? null ) ? (string) $break['reason'] : '?' ),
			]
		);
	}
}
