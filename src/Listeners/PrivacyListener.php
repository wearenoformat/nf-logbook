<?php
/**
 * PrivacyListener.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Listeners;

use Noformat\Logger\Category;
use Noformat\Logger\Config;
use Noformat\Logger\Event;
use Noformat\Logger\Listener;
use Noformat\Logger\Listeners\Support\Hooks;
use Noformat\Logger\Logger;
use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Export / import / privacy tools: export_wp, import_start, import_end, personal data export and erasure (PLAN §4).
 *
 * Only types, ids and counts: never the request email, file names or paths.
 */
final class PrivacyListener implements Listener {

	use Hooks;

	/**
	 * Availability.
	 */
	public static function isAvailable(): bool {
		return true;
	}

	/**
	 * Add hooks.
	 *
	 * @param Logger $logger Logger.
	 * @param Config $config Config.
	 */
	public function register( Logger $logger, Config $config ): void {
		$this->bind( $logger, $config );
		$this->onAction( 'export_wp', fn( $args ) => $this->exportStarted( $args ), 10, 1 );
		$this->onAction( 'import_start', fn() => $this->import( 'privacy.import_started', 'started' ), 10, 0 );
		$this->onAction( 'import_end', fn() => $this->import( 'privacy.import_finished', 'finished' ), 10, 0 );
		$this->onAction( 'wp_privacy_personal_data_export_file', fn( $requestId ) => $this->personalData( 'privacy.personal_data_exported', 'exported', $requestId ), 10, 1 );
		$this->onAction( 'wp_privacy_personal_data_erased', fn( $requestId ) => $this->personalData( 'privacy.personal_data_erased', 'erased', $requestId ), 10, 1 );
		// `wp export` does not call export_wp(): log it through the WP-CLI hook instead.
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_hook(
				'before_invoke:export',
				function () use ( $logger ): void {
					$logger->guard( 'listener.wp_cli_export', fn() => $this->exportStarted( [ 'content' => 'all' ], 'wp-cli' ) );
				}
			);
		}
	}

	/**
	 * WordPress export (Tools → Export, wp export).
	 *
	 * @param mixed  $args export_wp() arguments.
	 * @param string $via  "export_wp" (Tools → Export, export_wp() callers) or "wp-cli" (wp export).
	 */
	public function exportStarted( mixed $args, string $via = 'export_wp' ): void {
		$args    = is_array( $args ) ? $args : [];
		$content = is_string( $args['content'] ?? null ) ? $args['content'] : 'all';
		$filters = [];
		foreach ( [ 'author', 'category', 'start_date', 'end_date', 'status' ] as $key ) {
			if ( ! empty( $args[ $key ] ) ) {
				$filters[] = $key;
			}
		}
		$count = null;
		if ( 'all' !== $content && function_exists( 'post_type_exists' ) && post_type_exists( $content ) && function_exists( 'wp_count_posts' ) ) {
			$counts = (array) wp_count_posts( $content );
			$count  = (int) array_sum( array_map( 'intval', $counts ) );
		}
		$this->emit(
			Event::make( Category::PRIVACY, 'privacy.export_started', Severity::NOTICE )
				->summary( sprintf( 'Content export started (%s)', $content ) )
				->context(
					[
						'content'     => $content,
						'filters'     => $filters,
						'posts_count' => $count,
						'via'         => $via,
					]
				)
		);
	}

	/**
	 * Importer start / end.
	 *
	 * @param string $action Action.
	 * @param string $verb   Summary verb.
	 */
	public function import( string $action, string $verb ): void {
		$importer = isset( $_GET['import'] ) && is_string( $_GET['import'] ) ? sanitize_key( wp_unslash( $_GET['import'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only label of the running importer.
		$this->emit(
			Event::make( Category::PRIVACY, $action, Severity::NOTICE )
				->summary( sprintf( 'Import %s%s', $verb, null === $importer || '' === $importer ? '' : ' (' . $importer . ')' ) )
				->context( [ 'importer' => $importer ] )
		);
	}

	/**
	 * Personal data export file generated / data erased for a request.
	 *
	 * @param string $action    Action.
	 * @param string $verb      Summary verb.
	 * @param mixed  $requestId User request (post) id.
	 */
	public function personalData( string $action, string $verb, mixed $requestId ): void {
		$id      = self::intId( $requestId );
		$request = $id > 0 && function_exists( 'wp_get_user_request' ) ? wp_get_user_request( $id ) : null;
		$userId  = is_object( $request ) ? (int) $request->user_id : 0;
		$event   = Event::make( Category::PRIVACY, $action, Severity::NOTICE )
			->summary( sprintf( 'Personal data %s for request #%d%s', $verb, $id, $userId > 0 ? ' (user #' . $userId . ')' : '' ) )
			->context(
				[
					'request_id'     => $id,
					'request_action' => is_object( $request ) ? (string) $request->action_name : null,
					'has_account'    => $userId > 0,
				]
			);
		if ( $userId > 0 ) {
			$event->object( 'user', $userId );
		} else {
			$event->object( 'user_request', $id );
		}
		$this->emit( $event );
	}
}
