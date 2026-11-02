<?php
/**
 * DbErrorListener.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Errors;

use Noformat\Logger\Category;
use Noformat\Logger\Config;
use Noformat\Logger\Errors\Support\PathMapper;
use Noformat\Logger\Errors\Support\SqlNormalizer;
use Noformat\Logger\Event;
use Noformat\Logger\Listener;
use Noformat\Logger\Logger;
use Noformat\Logger\Severity;
use Noformat\Logger\Storage\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Database errors (PLAN §4, §5: critical).
 *
 * Inspects `$wpdb->last_error` on `shutdown` (priority 1, before the Logger
 * flush) and whenever a `wp_die_handler*` filter is resolved (wp_die() paths
 * that still reach plugins). Limitation: wpdb only keeps the error of the LAST
 * query, so an error followed by a successful query in the same request is not
 * seen, and only one database error per request is reported. Hooking the
 * `query` filter would catch all of them but costs a callback per query.
 *
 * The query is reported with every literal replaced by "?"; when the last query
 * is one of the logger's own (its storage restores the previous error text but
 * not the query), the query is omitted.
 */
final class DbErrorListener implements Listener {

	private const DIE_FILTERS = [
		'wp_die_handler',
		'wp_die_ajax_handler',
		'wp_die_json_handler',
		'wp_die_jsonp_handler',
		'wp_die_xmlrpc_handler',
		'wp_die_xml_handler',
	];

	private ?Logger $logger = null;

	private string $reported = '';

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
		unset( $config );
		$this->logger = $logger;
		add_action( 'shutdown', [ $this, 'check' ], 1, 0 );
		foreach ( self::DIE_FILTERS as $filter ) {
			add_filter( $filter, [ $this, 'observeDie' ], PHP_INT_MAX, 1 );
		}
	}

	/**
	 * Observe a wp_die handler resolution (returned unchanged).
	 *
	 * @param mixed $handler Handler.
	 */
	public function observeDie( mixed $handler ): mixed {
		$this->check();
		return $handler;
	}

	/**
	 * Report $wpdb->last_error once.
	 */
	public function check(): void {
		$logger = $this->logger;
		if ( null === $logger ) {
			return;
		}
		$logger->guard(
			'db_error.check',
			function () use ( $logger ): void {
				global $wpdb;
				if ( ! is_object( $wpdb ) || ! isset( $wpdb->last_error ) || '' === (string) $wpdb->last_error ) {
					return;
				}
				$error = (string) $wpdb->last_error;
				$query = isset( $wpdb->last_query ) ? (string) $wpdb->last_query : '';
				// Same text again (e.g. wp_die path, then shutdown after another query): once per request.
				if ( $error === $this->reported ) {
					return;
				}
				$this->reported = $error;
				$own            = '' !== $query && str_contains( $query, $wpdb->prefix . Db::PREFIX );
				$caller         = self::caller( isset( $wpdb->func_call ) ? (string) $wpdb->func_call : '', is_array( $wpdb->queries ?? null ) ? $wpdb->queries : [] );
				$logger->log( self::buildEvent( $error, $own ? '' : $query, $caller, PathMapper::shared() ) );
			}
		);
	}

	/**
	 * Build the db.error event (pure).
	 *
	 * @param string          $error  Raw error text.
	 * @param string          $query  Raw last query ('' when unavailable).
	 * @param string|null     $caller Caller description.
	 * @param PathMapper|null $paths Path mapper (absolute paths in error texts).
	 */
	public static function buildEvent( string $error, string $query, ?string $caller, ?PathMapper $paths = null ): Event {
		$cleanError = SqlNormalizer::stripError( $error );
		if ( null !== $paths ) {
			$cleanError = $paths->relativeInText( $cleanError );
		}
		$cleanQuery = '' === $query ? null : SqlNormalizer::strip( $query );
		return Event::make( Category::STABILITY, 'db.error', Severity::CRITICAL )
			->object( 'database' )
			->summary( 'Database error: ' . $cleanError )
			->context(
				[
					'error'  => $cleanError,
					'query'  => $cleanQuery,
					'caller' => $caller,
				]
			)
			->fingerprint( 'db.error', (string) $cleanQuery, self::normalizeError( $cleanError ) );
	}

	/**
	 * Caller from wpdb: the wpdb method of the last call and, with SAVEQUERIES,
	 * the last three functions of the recorded call stack (function names only).
	 *
	 * @param string            $funcCall wpdb->func_call, e.g. '$db->get_results("…")'.
	 * @param array<int, mixed> $queries  wpdb->queries (SAVEQUERIES).
	 */
	public static function caller( string $funcCall, array $queries = [] ): ?string {
		$parts = [];
		if ( '' !== $funcCall && preg_match( '/^\$db->(\w+)\(/', $funcCall, $m ) ) {
			$parts[] = 'wpdb::' . $m[1];
		}
		$last = end( $queries );
		if ( is_array( $last ) && isset( $last[1] ) && is_string( $last[1] ) ) {
			$frames = array_map( 'trim', explode( ',', $last[1] ) );
			$frames = array_map( static fn( string $f ): string => (string) preg_replace( "/\\('.*'\\)$/", '()', $f ), $frames );
			$parts  = array_merge( array_slice( $frames, -3 ), $parts );
		}
		return [] === $parts ? null : substr( implode( ' > ', $parts ), 0, 255 );
	}

	/**
	 * Error text for fingerprints (numbers stripped).
	 *
	 * @param string $error Error.
	 */
	private static function normalizeError( string $error ): string {
		return (string) preg_replace( '/\d+/', '#', strtolower( $error ) );
	}
}
