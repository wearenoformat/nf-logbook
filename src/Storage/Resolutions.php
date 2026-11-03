<?php
/**
 * Resolved issues.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Storage;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table; dynamic placeholder lists are passed through prepare().

/**
 * Resolution state of issues in {prefix}nflog_resolutions, kept outside the
 * (append-only, hash-chained) events.
 *
 * An issue is a fingerprint (32 hex) or, for an event without one, the single
 * event (`event:<id>`). A resolution records "resolved through event id N":
 * an event with that fingerprint counts as resolved iff its id <= N, so a
 * later occurrence (a new event id) is open again without any job. For a
 * single event N is its own id.
 *
 * Rows are removed on reopen and by prune once every event they cover is gone
 * (through_id <= the prune boundary). Separate from {prefix}nflog_fingerprints
 * on purpose: those rows are an aggregation cache, rewritten with REPLACE on
 * every new window and deleted 7 days after last_seen while older events with
 * the fingerprint are still stored.
 */
final class Resolutions {

	/** Most issues one "Resolve all open" handles (one event logged per issue). */
	public const BULK_MAX = 200;

	/** First schema version with the resolutions table. */
	public const SINCE_SCHEMA = 2;

	/**
	 * Whether the table exists (schema at least SINCE_SCHEMA per the autoloaded
	 * mirror, so no query). Readers skip resolution filters until then.
	 */
	public static function ready(): bool {
		return function_exists( 'get_option' ) && (int) get_option( Schema::CACHE_OPTION, 0 ) >= self::SINCE_SCHEMA;
	}

	/**
	 * Issue key: the fingerprint, or `event:<id>` for an event without one.
	 *
	 * @param string|null $fingerprint Fingerprint (32 hex) or null.
	 * @param int|null    $eventId     Event id (used when there is no fingerprint).
	 */
	public static function key( ?string $fingerprint, ?int $eventId ): string {
		if ( null !== $fingerprint && '' !== $fingerprint ) {
			return $fingerprint;
		}
		return 'event:' . (int) $eventId;
	}

	/**
	 * Issue key of an event row.
	 *
	 * @param array<string, mixed> $row Event row.
	 */
	public static function keyForRow( array $row ): string {
		$fingerprint = isset( $row['fingerprint'] ) && is_string( $row['fingerprint'] ) && '' !== $row['fingerprint'] ? $row['fingerprint'] : null;
		return self::key( $fingerprint, (int) ( $row['id'] ?? 0 ) );
	}

	/**
	 * Parse an issue reference: a fingerprint (32 hex) or `event:<id>`.
	 *
	 * @param string $value Reference.
	 * @return array{fingerprint: string|null, event_id: int|null}|null Null when invalid.
	 */
	public static function parse( string $value ): ?array {
		$value = strtolower( trim( $value ) );
		if ( 1 === preg_match( '/^[0-9a-f]{32}$/', $value ) ) {
			return [
				'fingerprint' => $value,
				'event_id'    => null,
			];
		}
		if ( 1 === preg_match( '/^event:([1-9]\d{0,18})$/', $value, $m ) ) {
			return [
				'fingerprint' => null,
				'event_id'    => (int) $m[1],
			];
		}
		return null;
	}

	/**
	 * Pure: whether a resolution covers an event row.
	 *
	 * @param array{fingerprint: string|null, event_id: int|null, through_id: int} $resolution Resolution.
	 * @param array<string, mixed>                                                 $row        Event row (id, fingerprint).
	 */
	public static function covers( array $resolution, array $row ): bool {
		$id          = (int) ( $row['id'] ?? 0 );
		$fingerprint = isset( $row['fingerprint'] ) && is_string( $row['fingerprint'] ) ? $row['fingerprint'] : '';
		if ( null !== $resolution['fingerprint'] ) {
			return '' !== $fingerprint && $fingerprint === $resolution['fingerprint'] && $id > 0 && $id <= $resolution['through_id'];
		}
		return '' === $fingerprint && null !== $resolution['event_id'] && $id === $resolution['event_id'];
	}

	/**
	 * SQL condition (for a WHERE on the events table, unaliased) that keeps
	 * only unresolved events, with its arguments.
	 *
	 * @return array{0: string, 1: list<string>}
	 */
	public static function excludeClause(): array {
		$events = Db::table( 'events' );
		$table  = Db::table( 'resolutions' );
		return [
			'NOT EXISTS (SELECT 1 FROM %i r WHERE r.fingerprint = %i.fingerprint AND r.through_id >= %i.id) AND NOT EXISTS (SELECT 1 FROM %i r WHERE r.event_id = %i.id)',
			[ $table, $events, $events, $table, $events ],
		];
	}

	/**
	 * One resolution.
	 *
	 * @param string $key Issue key.
	 * @return array{issue_key: string, fingerprint: string|null, event_id: int|null, through_id: int, resolved_at: int, resolved_by: int|null}|null
	 */
	public function find( string $key ): ?array {
		$row = Db::getRow( Db::prepare( 'SELECT * FROM %i WHERE issue_key = %s', Db::table( 'resolutions' ), $key ) );
		return null === $row ? null : self::shape( $row );
	}

	/**
	 * Resolutions covering the given event rows, by event id (one query).
	 *
	 * @param list<array<string, mixed>> $rows Event rows.
	 * @return array<int, array{issue_key: string, fingerprint: string|null, event_id: int|null, through_id: int, resolved_at: int, resolved_by: int|null}>
	 */
	public function forRows( array $rows ): array {
		$keys = [];
		foreach ( $rows as $row ) {
			$keys[ self::keyForRow( $row ) ] = true;
		}
		if ( [] === $keys ) {
			return [];
		}
		$found = [];
		foreach ( array_chunk( array_keys( $keys ), 500 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			foreach ( Db::getResults( Db::prepare( "SELECT * FROM %i WHERE issue_key IN ({$in})", Db::table( 'resolutions' ), ...$chunk ) ) as $raw ) {
				$shaped                        = self::shape( $raw );
				$found[ $shaped['issue_key'] ] = $shaped;
			}
		}
		$out = [];
		foreach ( $rows as $row ) {
			$resolution = $found[ self::keyForRow( $row ) ] ?? null;
			if ( null !== $resolution && self::covers( $resolution, $row ) ) {
				$out[ (int) $row['id'] ] = $resolution;
			}
		}
		return $out;
	}

	/**
	 * Store (or replace) a resolution.
	 *
	 * @param string|null $fingerprint Fingerprint, or null for a single event.
	 * @param int|null    $eventId     Single event id (null for a fingerprint).
	 * @param int         $throughId   Resolved through this event id.
	 * @param int         $at          Unix time.
	 * @param int|null    $by          User id (null when none, e.g. WP-CLI without --user).
	 */
	public function save( ?string $fingerprint, ?int $eventId, int $throughId, int $at, ?int $by ): void {
		$cells = [ '%s' ];
		$args  = [ Db::table( 'resolutions' ), self::key( $fingerprint, $eventId ) ];
		if ( null === $fingerprint ) {
			$cells[] = 'NULL';
			$cells[] = '%d';
			$args[]  = (int) $eventId;
		} else {
			$cells[] = '%s';
			$cells[] = 'NULL';
			$args[]  = $fingerprint;
		}
		array_push( $cells, '%d', '%d', null === $by ? 'NULL' : '%d' );
		array_push( $args, $throughId, $at );
		if ( null !== $by ) {
			$args[] = $by;
		}
		Db::query( Db::prepare( 'REPLACE INTO %i (issue_key, fingerprint, event_id, through_id, resolved_at, resolved_by) VALUES (' . implode( ', ', $cells ) . ')', ...$args ) );
	}

	/**
	 * Remove a resolution (reopen).
	 *
	 * @param string $key Issue key.
	 * @return int Rows removed.
	 */
	public function delete( string $key ): int {
		return Db::query( Db::prepare( 'DELETE FROM %i WHERE issue_key = %s', Db::table( 'resolutions' ), $key ) );
	}

	/**
	 * Remove resolutions whose events are all pruned (every covered id <= through_id <= boundary).
	 *
	 * @param int $boundary Highest pruned event id.
	 */
	public function prune( int $boundary ): int {
		if ( $boundary <= 0 ) {
			return 0;
		}
		return Db::query( Db::prepare( 'DELETE FROM %i WHERE through_id <= %d', Db::table( 'resolutions' ), $boundary ) );
	}

	/**
	 * Typed row.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array{issue_key: string, fingerprint: string|null, event_id: int|null, through_id: int, resolved_at: int, resolved_by: int|null}
	 */
	public static function shape( array $row ): array {
		return [
			'issue_key'   => (string) ( $row['issue_key'] ?? '' ),
			'fingerprint' => isset( $row['fingerprint'] ) && '' !== (string) $row['fingerprint'] ? (string) $row['fingerprint'] : null,
			'event_id'    => isset( $row['event_id'] ) && is_numeric( $row['event_id'] ) ? (int) $row['event_id'] : null,
			'through_id'  => (int) ( $row['through_id'] ?? 0 ),
			'resolved_at' => (int) ( $row['resolved_at'] ?? 0 ),
			'resolved_by' => isset( $row['resolved_by'] ) && is_numeric( $row['resolved_by'] ) && (int) $row['resolved_by'] > 0 ? (int) $row['resolved_by'] : null,
		];
	}
}
