<?php
/**
 * Fingerprint aggregation.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Storage;

use Noformat\Logger\Config;
use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin table.

/**
 * Aggregates recurring events (PLAN D6): the first occurrence of a fingerprint
 * is stored; further occurrences within the window are counted on the
 * fingerprint row instead of inserting a new event. Window: 60 s, 3600 s for
 * debug, per-action overrides from the `fingerprint_windows` setting (defaults:
 * probe.suspicious_404 3600 s, rest.users_probe and xmlrpc.call 900 s); all of
 * it filterable through noformat_logger/fingerprint_window.
 *
 * Write budget (docs/EVENTS.md, "Aggregation windows"): a recurring
 * fingerprint costs one SELECT (none with a persistent object cache) and one
 * UPDATE of its fingerprint row per request. The fingerprint row is the single
 * place that counts occurrences of an open window; the event's own `count` is
 * written back once, when the window is replaced or the row is cleaned up, and
 * readers hydrate it from the fingerprint row meanwhile
 * (EventRepository::hydrateCounts()). Above SAMPLE_AFTER occurrences the row is
 * written for one request in SAMPLE_RATE only, adding SAMPLE_RATE times the
 * request's occurrences, so hot paths stay bounded (the count is then an
 * unbiased estimate).
 */
final class FingerprintStore {

	/** Occurrences in a window after which counter writes are sampled. */
	public const SAMPLE_AFTER = 100;

	/** One write in SAMPLE_RATE requests once sampling starts. */
	public const SAMPLE_RATE = 10;

	/** Object cache group. */
	public const CACHE_GROUP = 'nflog';

	/**
	 * Per-request cache of fingerprint rows.
	 *
	 * @var array<string, array{fingerprint: string, last_event_id: int, window_start: int, count: int, last_seen: int}|null>
	 */
	private array $cache = [];

	private ?int $generation = null;

	/**
	 * Random source for sampling (tests inject a deterministic one).
	 *
	 * @var \Closure(int, int): int
	 */
	private \Closure $random;

	/**
	 * Constructor.
	 *
	 * @param Config        $config Config.
	 * @param callable|null $random Random integer source (min, max) for counter sampling.
	 */
	public function __construct( private Config $config, ?callable $random = null ) {
		$this->random = null === $random ? static fn( int $min, int $max ): int => random_int( $min, $max ) : \Closure::fromCallable( $random );
	}

	/**
	 * Aggregation window in seconds for an event's severity and action.
	 *
	 * @param Severity $severity Severity.
	 * @param string   $action   Event action ('' when unknown).
	 */
	public function window( Severity $severity, string $action = '' ): int {
		$window    = Severity::DEBUG === $severity
			? $this->config->int( 'fingerprint_window_debug' )
			: $this->config->int( 'fingerprint_window' );
		$perAction = $this->config->get( 'fingerprint_windows', [] );
		if ( '' !== $action && is_array( $perAction ) && isset( $perAction[ $action ] ) && is_numeric( $perAction[ $action ] ) ) {
			$window = (int) $perAction[ $action ];
		}
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the aggregation window of a fingerprinted event.
			 *
			 * @param int      $window   Seconds.
			 * @param Severity $severity Severity of the occurrence.
			 * @param string   $action   Event action, e.g. "probe.suspicious_404".
			 */
			$window = (int) apply_filters( 'noformat_logger/fingerprint_window', $window, $severity, $action );
		}
		return max( 1, $window );
	}

	/**
	 * Pure decision: aggregate into the stored event?
	 *
	 * A window closed by the collector (window_start 0, see closeServed()) never aggregates.
	 *
	 * @param array{last_event_id: int, window_start: int}|null $row      Stored fingerprint row.
	 * @param int                                               $now      Unix time.
	 * @param Severity                                          $severity Severity of the new occurrence.
	 * @param string                                            $action   Action of the new occurrence.
	 */
	public function shouldAggregate( ?array $row, int $now, Severity $severity, string $action = '' ): bool {
		if ( null === $row || $row['last_event_id'] <= 0 || $row['window_start'] <= 0 ) {
			return false;
		}
		$age = $now - $row['window_start'];
		return $age >= 0 && $age < $this->window( $severity, $action );
	}

	/**
	 * Stored row for a fingerprint (request cache, then the persistent object cache, then the table).
	 *
	 * @param string $fingerprint Fingerprint.
	 * @return array{fingerprint: string, last_event_id: int, window_start: int, count: int, last_seen: int}|null
	 */
	public function lookup( string $fingerprint ): ?array {
		if ( array_key_exists( $fingerprint, $this->cache ) ) {
			return $this->cache[ $fingerprint ];
		}
		if ( self::extCache() ) {
			$cached = wp_cache_get( $this->cacheKey( $fingerprint ), self::CACHE_GROUP );
			if ( is_array( $cached ) && isset( $cached['last_event_id'], $cached['window_start'] ) ) {
				$this->cache[ $fingerprint ] = self::shape( $cached );
				return $this->cache[ $fingerprint ];
			}
		}
		$row                         = Db::getRow(
			Db::prepare( 'SELECT fingerprint, last_event_id, window_start, `count`, last_seen FROM %i WHERE fingerprint = %s', Db::table( 'fingerprints' ), $fingerprint )
		);
		$this->cache[ $fingerprint ] = null === $row ? null : self::shape( $row );
		if ( null !== $this->cache[ $fingerprint ] ) {
			$this->store( $this->cache[ $fingerprint ] );
		}
		return $this->cache[ $fingerprint ];
	}

	/**
	 * A new event was stored for this fingerprint: write the final count of the
	 * previous window back to its event, then open a new window.
	 *
	 * @param string $fingerprint Fingerprint.
	 * @param int    $eventId     Stored event id.
	 * @param int    $count       Occurrences in that event.
	 * @param int    $now         Unix time.
	 */
	public function remember( string $fingerprint, int $eventId, int $count, int $now ): void {
		$previous = $this->cache[ $fingerprint ] ?? null;
		if ( null !== $previous && $previous['last_event_id'] > 0 && $previous['last_event_id'] !== $eventId ) {
			// The row may have been counted by other requests since this one read it: re-read once.
			$fresh = Db::getRow( Db::prepare( 'SELECT last_event_id, `count` FROM %i WHERE fingerprint = %s', Db::table( 'fingerprints' ), $fingerprint ) );
			if ( null !== $fresh && (int) $fresh['last_event_id'] > 0 && (int) $fresh['last_event_id'] !== $eventId ) {
				self::writeBack( (int) $fresh['last_event_id'], (int) $fresh['count'] );
			}
		}
		Db::query(
			Db::prepare(
				'REPLACE INTO %i (fingerprint, last_event_id, window_start, `count`, last_seen) VALUES (%s, %d, %d, %d, %d)',
				Db::table( 'fingerprints' ),
				$fingerprint,
				$eventId,
				$now,
				$count,
				$now
			)
		);
		$this->cache[ $fingerprint ] = [
			'fingerprint'   => $fingerprint,
			'last_event_id' => $eventId,
			'window_start'  => $now,
			'count'         => $count,
			'last_seen'     => $now,
		];
		$this->store( $this->cache[ $fingerprint ] );
	}

	/**
	 * Occurrences were aggregated into the stored event: count them on the
	 * fingerprint row (one UPDATE; sampled above SAMPLE_AFTER).
	 *
	 * @param string $fingerprint Fingerprint.
	 * @param int    $count       Added occurrences.
	 * @param int    $now         Unix time.
	 * @return bool Whether the row was written.
	 */
	public function touch( string $fingerprint, int $count, int $now ): bool {
		$row = $this->cache[ $fingerprint ] ?? null;
		$add = $count;
		if ( null !== $row && $row['count'] >= self::SAMPLE_AFTER ) {
			if ( 1 !== ( $this->random )( 1, self::SAMPLE_RATE ) ) {
				return false;
			}
			$add = $count * self::SAMPLE_RATE;
		}
		Db::query(
			Db::prepare(
				'UPDATE %i SET `count` = `count` + %d, last_seen = %d WHERE fingerprint = %s',
				Db::table( 'fingerprints' ),
				$add,
				$now,
				$fingerprint
			)
		);
		if ( null !== $row ) {
			$row['count']               += $add;
			$row['last_seen']            = $now;
			$this->cache[ $fingerprint ] = $row;
			$this->store( $row );
		}
		return true;
	}

	/**
	 * The collector has received every event up to $throughId: close their
	 * windows so later occurrences start a new event the collector will see,
	 * instead of raising a count it already read (one UPDATE per pull).
	 *
	 * @param int $throughId Highest event id served or acknowledged.
	 */
	public function closeServed( int $throughId ): int {
		if ( $throughId <= 0 ) {
			return 0;
		}
		$this->forget();
		return Db::query(
			Db::prepare(
				'UPDATE %i SET window_start = 0 WHERE last_event_id > 0 AND last_event_id <= %d AND window_start > 0',
				Db::table( 'fingerprints' ),
				$throughId
			)
		);
	}

	/**
	 * Close one fingerprint's window (the issue was resolved): the next
	 * occurrence becomes a new event, with an id above the resolution, instead
	 * of raising the count of a resolved event.
	 *
	 * @param string $fingerprint Fingerprint.
	 */
	public function close( string $fingerprint ): int {
		$this->forget();
		return Db::query( Db::prepare( 'UPDATE %i SET window_start = 0 WHERE fingerprint = %s AND window_start > 0', Db::table( 'fingerprints' ), $fingerprint ) );
	}

	/**
	 * Remove fingerprints not seen for a while (cron), writing their final count back to the event first.
	 *
	 * @param int $before Unix time.
	 */
	public function cleanup( int $before ): int {
		$this->forget();
		$rows = Db::getResults( Db::prepare( 'SELECT last_event_id, `count` FROM %i WHERE last_seen < %d ORDER BY last_seen ASC LIMIT 5000', Db::table( 'fingerprints' ), $before ) );
		foreach ( $rows as $row ) {
			self::writeBack( (int) $row['last_event_id'], (int) $row['count'] );
		}
		return Db::query( Db::prepare( 'DELETE FROM %i WHERE last_seen < %d', Db::table( 'fingerprints' ), $before ) );
	}

	/**
	 * Drop cached rows (request and persistent object cache).
	 */
	public function forget(): void {
		$this->cache = [];
		if ( self::extCache() ) {
			$this->generation = (int) wp_cache_get( 'fp_gen', self::CACHE_GROUP ) + 1;
			wp_cache_set( 'fp_gen', $this->generation, self::CACHE_GROUP );
		}
	}

	/**
	 * Set an event's count to the final count of its window (never lowers it).
	 *
	 * @param int $eventId Event id.
	 * @param int $count   Occurrences.
	 */
	private static function writeBack( int $eventId, int $count ): void {
		if ( $eventId <= 0 || $count <= 1 ) {
			return;
		}
		Db::query( Db::prepare( 'UPDATE %i SET `count` = %d WHERE id = %d AND `count` < %d', Db::table( 'events' ), $count, $eventId, $count ) );
	}

	/**
	 * Whether a persistent object cache is in use.
	 */
	private static function extCache(): bool {
		return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_get' );
	}

	/**
	 * Put a row into the persistent object cache.
	 *
	 * @param array{fingerprint: string, last_event_id: int, window_start: int, count: int, last_seen: int} $row Row.
	 */
	private function store( array $row ): void {
		if ( self::extCache() ) {
			wp_cache_set( $this->cacheKey( $row['fingerprint'] ), $row, self::CACHE_GROUP, 7 * 86400 );
		}
	}

	/**
	 * Object cache key; the generation changes when windows are closed or rows removed.
	 *
	 * @param string $fingerprint Fingerprint.
	 */
	private function cacheKey( string $fingerprint ): string {
		if ( null === $this->generation ) {
			$this->generation = (int) wp_cache_get( 'fp_gen', self::CACHE_GROUP );
		}
		return 'fp:' . $this->generation . ':' . $fingerprint;
	}

	/**
	 * Typed row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array{fingerprint: string, last_event_id: int, window_start: int, count: int, last_seen: int}
	 */
	private static function shape( array $row ): array {
		return [
			'fingerprint'   => (string) ( $row['fingerprint'] ?? '' ),
			'last_event_id' => (int) ( $row['last_event_id'] ?? 0 ),
			'window_start'  => (int) ( $row['window_start'] ?? 0 ),
			'count'         => (int) ( $row['count'] ?? 0 ),
			'last_seen'     => (int) ( $row['last_seen'] ?? 0 ),
		];
	}
}
