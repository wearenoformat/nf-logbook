<?php
/**
 * JSON export of the Content log and the System log.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Admin;

use Noformat\Logger\Category;
use Noformat\Logger\Event;
use Noformat\Logger\Plugin;
use Noformat\Logger\Severity;
use Noformat\Logger\Storage\EventRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Download of the current filter and scope as JSON (at most 5 000 events),
 * each event in the CONTRACT §1 shape the API serves. Nonce and access
 * checked; logged as logger.events_exported (filter names, never values).
 */
final class Export {

	public const NONCE = 'nflog_export';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {
	}

	/**
	 * Export URL for a scope and filters.
	 *
	 * @param string                $scope Scope::CONTENT or Scope::SYSTEM.
	 * @param array<string, string> $clean Validated filters.
	 */
	public static function url( string $scope, array $clean ): string {
		$slug = Scope::SYSTEM === $scope ? Menu::SYSTEM : Menu::CONTENT;
		return wp_nonce_url( Menu::url( $slug, array_merge( $clean, [ 'nflog_export' => 'json' ] ) ), self::NONCE );
	}

	/**
	 * Export handler on load-{hook}: nonce, access, stream, log, exit.
	 *
	 * @param string                $scope Scope of the page.
	 * @param array<string, string> $clean Validated filters of the request.
	 */
	public function maybeHandle( string $scope, array $clean ): void {
		if ( ! isset( $_GET['nflog_export'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
			return;
		}
		check_admin_referer( self::NONCE );
		$config  = $this->plugin->config();
		$allowed = Scope::SYSTEM === $scope ? Access::canViewSystemLog( $config ) : Access::canViewEvents( $config );
		if ( ! $allowed ) {
			wp_die( esc_html__( 'You are not allowed to export the Noformat Logger event log.', 'noformat-logger' ), 403 );
		}
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$name = 'nflog-' . ( Scope::SYSTEM === $scope ? 'system' : 'content' ) . '-' . sanitize_file_name( '' === $host ? 'site' : $host ) . '-' . gmdate( 'Ymd-His' ) . '.json';
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		$this->write(
			$scope,
			$clean,
			static function ( string $chunk ): void {
				echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
			}
		);
		exit;
	}

	/**
	 * Write the export through $emit, newest first, in pages of 500.
	 *
	 * Shape: {exported_at, site, producer, scope, filters, total, count, truncated,
	 * events: [CONTRACT §1 events, as served by the API]}.
	 *
	 * @param string                 $scope Scope::CONTENT or Scope::SYSTEM.
	 * @param array<string, string>  $clean Validated filters.
	 * @param callable(string): void $emit  Output callback.
	 * @return int Events written.
	 */
	public function write( string $scope, array $clean, callable $emit ): int {
		$repo    = $this->plugin->repository();
		$filters = EventFilters::scoped( EventFilters::toRepository( $clean, wp_timezone() ), Scope::categories( $scope ) );
		$site    = home_url();
		$total   = 0;
		$written = 0;
		try {
			$total = $repo->count( $filters );
		} catch ( \Throwable $e ) {
			$total = 0;
		}
		$head = wp_json_encode(
			[
				'exported_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'site'        => [
					'id'  => (string) get_current_blog_id(),
					'url' => $site,
				],
				'producer'    => Event::producer(),
				'scope'       => $scope,
				'filters'     => (object) $clean,
				'total'       => $total,
				'count'       => min( $total, EventFilters::EXPORT_MAX ),
				'truncated'   => $total > EventFilters::EXPORT_MAX,
			],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		$emit( substr( (string) $head, 0, -1 ) . ',"events":[' );
		$limit = min( $total, EventFilters::EXPORT_MAX );
		$page  = 1;
		while ( $written < $limit ) {
			try {
				$rows = $repo->query( $filters, $page, 500, 'time', 'desc' );
			} catch ( \Throwable $e ) {
				break;
			}
			if ( [] === $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				if ( $written >= $limit ) {
					break;
				}
				$json = wp_json_encode( EventRepository::rowToArray( $row, $site ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				if ( false === $json ) {
					continue;
				}
				$emit( ( $written > 0 ? ',' : '' ) . $json );
				++$written;
			}
			++$page;
		}
		$emit( ']}' );
		$this->log( $scope, $clean, $written, $total > EventFilters::EXPORT_MAX );
		return $written;
	}

	/**
	 * Logger.events_exported (filter names, never values).
	 *
	 * @param string                $scope     Scope.
	 * @param array<string, string> $clean     Filters.
	 * @param int                   $count     Events written.
	 * @param bool                  $truncated More matched than exported.
	 */
	private function log( string $scope, array $clean, int $count, bool $truncated ): void {
		$logger = $this->plugin->logger();
		$logger->guard(
			'events_exported',
			static fn() => $logger->log(
				Event::make( Category::SYSTEM, 'logger.events_exported', Severity::NOTICE )
					->object( 'plugin', 'noformat-logger' )
					->summary( sprintf( 'Event log exported from wp-admin (%d events)', $count ) )
					->context(
						[
							'count'     => $count,
							'truncated' => $truncated,
							'filters'   => EventFilters::names( $clean ),
							'scope'     => $scope,
							'format'    => 'json',
						]
					)
			)
		);
	}

	/**
	 * Logger.events_viewed at debug severity: dropped unless log_debug is on.
	 *
	 * @param Plugin                $plugin Plugin.
	 * @param string                $scope  Scope.
	 * @param array<string, string> $clean  Filters.
	 */
	public static function logViewed( Plugin $plugin, string $scope, array $clean ): void {
		if ( ! $plugin->config()->bool( 'log_debug' ) ) {
			return;
		}
		$logger = $plugin->logger();
		$logger->guard(
			'events_viewed',
			static fn() => $logger->log(
				Event::make( Category::SYSTEM, 'logger.events_viewed', Severity::DEBUG )
					->object( 'plugin', 'noformat-logger' )
					->summary( 'Event log viewed in wp-admin' )
					->context(
						[
							'filters' => EventFilters::names( $clean ),
							'scope'   => $scope,
						]
					)
			)
		);
	}
}
