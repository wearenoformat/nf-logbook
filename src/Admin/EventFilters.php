<?php
/**
 * Event viewer filters.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Admin;

use Noformat\Logger\Category;
use Noformat\Logger\Severity;
use Noformat\Logger\Source;

defined( 'ABSPATH' ) || exit;

/**
 * Sanitises and validates the viewer's GET parameters (pure; no WordPress state).
 *
 * Clean() returns the validated string form of every accepted parameter
 * (invalid values dropped), used for the form and links; toRepository() the
 * typed filters for EventRepository::query() / count().
 */
final class EventFilters {

	public const PER_PAGE_DEFAULT = 50;
	public const PER_PAGE_MAX     = 200;
	public const EXPORT_MAX       = 5000;

	/** Sortable columns (query arg => repository order key). */
	public const ORDER_BY = [ 'time', 'severity', 'category', 'action', 'count' ];

	/** Accepted filter parameters, in display order. */
	public const PARAMS = [
		'severity',
		'severity_mode',
		'category',
		'event_action',
		'source',
		'actor_id',
		'object_type',
		'object_id',
		'request_id',
		'fingerprint',
		'from',
		'to',
		's',
		'levels',
		'cats',
		'window',
		'actor_type',
		'type',
		'important',
		'resolved',
	];

	/** Named windows (query arg `window`, `since:` in the console filter); any `<n>m|h|d` works too. */
	public const WINDOWS = [ '1h', '24h', '7d', '30d', 'all', 'today' ];

	/** Non-user actor types accepted by the `actor_type` filter. */
	public const ACTOR_TYPES = [ 'system', 'cron', 'cli', 'anonymous', 'app', 'user' ];

	/** Console filter prefixes (`key:value`) => request parameter. */
	public const QUERY_KEYS = [
		'action'   => 'event_action',
		'act'      => 'event_action',
		'cat'      => 'cats',
		'category' => 'cats',
		'src'      => 'source',
		'source'   => 'source',
		'req'      => 'request_id',
		'request'  => 'request_id',
		'fp'       => 'fingerprint',
		'since'    => 'window',
		'window'   => 'window',
		'sev'      => 'levels',
		'level'    => 'levels',
		'min'      => 'severity',
		'actor'    => 'actor',
		'obj'      => 'object',
		'object'   => 'object',
		'from'     => 'from',
		'to'       => 'to',
	];

	private const DATE = '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?$/';

	/**
	 * Validated string parameters.
	 *
	 * @param array<string|int, mixed> $input Unslashed request parameters ($_GET).
	 * @return array<string, string>
	 */
	public static function clean( array $input ): array {
		$out = [];
		$raw = static fn( string $key ): string => isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';

		$severity = Severity::parse( $raw( 'severity' ) );
		$mode     = 'exact' === $raw( 'severity_mode' ) ? 'exact' : 'min';
		$minimum  = Severity::parse( $raw( 'min_severity' ) );
		if ( null === $severity && null !== $minimum ) {
			$severity = $minimum;
			$mode     = 'min';
		}
		if ( null !== $severity ) {
			$out['severity']      = $severity->value;
			$out['severity_mode'] = $mode;
		}
		if ( in_array( $raw( 'category' ), Category::values(), true ) ) {
			$out['category'] = $raw( 'category' );
		}
		$action = strtolower( $raw( 'event_action' ) );
		if ( 1 === preg_match( '/^[a-z0-9_.\-]{1,64}$/', $action ) ) {
			$out['event_action'] = $action;
		}
		if ( null !== Source::tryFrom( $raw( 'source' ) ) ) {
			$out['source'] = $raw( 'source' );
		}
		if ( 1 === preg_match( '/^[1-9]\d{0,18}$/', $raw( 'actor_id' ) ) ) {
			$out['actor_id'] = $raw( 'actor_id' );
		}
		$type = strtolower( $raw( 'object_type' ) );
		if ( 1 === preg_match( '/^[a-z0-9_\-]{1,32}$/', $type ) ) {
			$out['object_type'] = $type;
			$id                 = self::text( $raw( 'object_id' ), 64 );
			if ( '' !== $id ) {
				$out['object_id'] = $id;
			}
		}
		foreach ( [ 'request_id', 'fingerprint' ] as $key ) {
			$value = strtolower( $raw( $key ) );
			if ( 1 === preg_match( '/^[0-9a-f]{32}$/', $value ) ) {
				$out[ $key ] = $value;
			}
		}
		foreach ( [ 'from', 'to' ] as $key ) {
			$value = str_replace( ' ', 'T', $raw( $key ) );
			if ( null !== self::parseDate( $value ) ) {
				$out[ $key ] = $value;
			}
		}
		$search = self::text( $raw( 's' ), 100 );
		if ( '' !== $search ) {
			$out['s'] = $search;
		}
		$levels = [];
		foreach ( self::listOf( $raw( 'levels' ) ) as $value ) {
			$level = Severity::parse( $value );
			if ( null !== $level && ! in_array( $level->value, $levels, true ) ) {
				$levels[] = $level->value;
			}
		}
		if ( [] !== $levels ) {
			$out['levels'] = implode( ',', $levels );
		}
		$cats = array_values( array_intersect( Category::values(), self::listOf( $raw( 'cats' ) ) ) );
		if ( [] !== $cats ) {
			$out['cats'] = implode( ',', $cats );
		}
		$window = strtolower( $raw( 'window' ) );
		if ( in_array( $window, self::WINDOWS, true ) || null !== self::windowSeconds( $window ) ) {
			$out['window'] = $window;
		}
		if ( in_array( $raw( 'actor_type' ), self::ACTOR_TYPES, true ) ) {
			$out['actor_type'] = $raw( 'actor_type' );
		}
		if ( isset( Scope::TYPES[ $raw( 'type' ) ] ) ) {
			$out['type'] = $raw( 'type' );
		}
		if ( '1' === $raw( 'important' ) ) {
			$out['important'] = '1';
		}
		if ( 'hide' === $raw( 'resolved' ) ) {
			$out['resolved'] = 'hide';
		}
		return $out;
	}

	/** Parameters represented by the console filter text (replaced when `q` is submitted). */
	public const QUERY_PARAMS = [ 'event_action', 'request_id', 'fingerprint', 'actor_id', 'actor_type', 'object_type', 'object_id', 'severity', 'severity_mode', 'from', 'to', 's' ];

	/**
	 * Validated filters from request input ($_GET of the viewer pages, the tail endpoint).
	 *
	 * Lists may arrive as arrays (levels[]=…, cats[]=…); `person` is a user id or
	 * an actor type; `q` is the console filter text (parseQuery()), which replaces
	 * the parameters it can express and adds to the level/category lists.
	 *
	 * @param array<string|int, mixed> $input Unslashed input.
	 * @return array<string, string>
	 */
	public static function fromInput( array $input ): array {
		$raw = [];
		foreach ( $input as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ',', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
			}
			if ( is_scalar( $value ) ) {
				$raw[ (string) $key ] = (string) $value;
			}
		}
		if ( isset( $raw['q'] ) ) {
			foreach ( self::QUERY_PARAMS as $param ) {
				unset( $raw[ $param ] );
			}
			foreach ( self::parseQuery( $raw['q'] ) as $key => $value ) {
				$raw[ $key ] = in_array( $key, [ 'cats', 'levels' ], true ) && isset( $raw[ $key ] ) && '' !== $raw[ $key ] ? $raw[ $key ] . ',' . $value : $value;
			}
		}
		if ( isset( $raw['person'] ) && '' !== $raw['person'] ) {
			if ( 1 === preg_match( '/^[1-9]\d{0,18}$/', $raw['person'] ) ) {
				$raw['actor_id'] = $raw['person'];
			} else {
				$raw['actor_type'] = $raw['person'];
			}
		}
		return self::clean( $raw );
	}

	/**
	 * Parse the console filter (`filter: text, action:php.warning, cat:security,
	 * src:cron, req:<id>, fp:<fingerprint>, since:24h`) into request parameters.
	 *
	 * Known `key:value` tokens map to parameters (lists such as cat: and sev:
	 * accumulate, comma separated); `actor:12` is a user id, `actor:cli` an actor
	 * type; `obj:post/12` an object type and id; everything else (bare words,
	 * "quoted phrases", unknown keys) becomes the free-text search `s`. The
	 * result is raw: pass it through clean().
	 *
	 * @param string $query Console filter text.
	 * @return array<string, string>
	 */
	public static function parseQuery( string $query ): array {
		$query = self::text( $query, 300 );
		$out   = [];
		$text  = [];
		if ( '' === $query || false === preg_match_all( '/"([^"]*)"|(\S+)/u', $query, $matches, PREG_SET_ORDER ) ) {
			return $out;
		}
		foreach ( $matches as $match ) {
			if ( isset( $match[2] ) ) {
				$token = rtrim( $match[2], ',' );
			} else {
				$token = trim( $match[1] );
				if ( '' !== $token ) {
					$text[] = $token;
				}
				continue;
			}
			$pos = strpos( $token, ':' );
			$key = false === $pos ? '' : strtolower( substr( $token, 0, $pos ) );
			if ( '' === $key || ! isset( self::QUERY_KEYS[ $key ] ) ) {
				if ( '' !== $token ) {
					$text[] = $token;
				}
				continue;
			}
			$value = trim( substr( $token, (int) $pos + 1 ) );
			$param = self::QUERY_KEYS[ $key ];
			if ( '' === $value ) {
				continue;
			}
			switch ( $param ) {
				case 'cats':
				case 'levels':
					$out[ $param ] = isset( $out[ $param ] ) ? $out[ $param ] . ',' . $value : $value;
					break;
				case 'actor':
					if ( 1 === preg_match( '/^[1-9]\d{0,18}$/', $value ) ) {
						$out['actor_id'] = $value;
					} else {
						$out['actor_type'] = strtolower( $value );
					}
					break;
				case 'object':
					$parts              = preg_split( '#[/:]#', $value, 2 );
					$out['object_type'] = (string) ( $parts[0] ?? '' );
					if ( isset( $parts[1] ) && '' !== $parts[1] ) {
						$out['object_id'] = $parts[1];
					}
					break;
				case 'severity':
					$out['severity']      = $value;
					$out['severity_mode'] = 'min';
					break;
				default:
					$out[ $param ] = $value;
			}
		}
		if ( [] !== $text ) {
			$out['s'] = implode( ' ', $text );
		}
		return $out;
	}

	/**
	 * Console filter text for a set of clean() parameters (round trip of parseQuery()).
	 *
	 * @param array<string, string> $clean Output of clean().
	 */
	public static function toQuery( array $clean ): string {
		$parts = [];
		$map   = [
			'event_action' => 'action',
			'source'       => 'src',
			'request_id'   => 'req',
			'fingerprint'  => 'fp',
			'cats'         => 'cat',
			'levels'       => 'sev',
			'window'       => 'since',
			'from'         => 'from',
			'to'           => 'to',
		];
		foreach ( $map as $param => $key ) {
			if ( isset( $clean[ $param ] ) ) {
				$parts[] = $key . ':' . $clean[ $param ];
			}
		}
		if ( isset( $clean['actor_id'] ) || isset( $clean['actor_type'] ) ) {
			$parts[] = 'actor:' . ( $clean['actor_id'] ?? $clean['actor_type'] );
		}
		if ( isset( $clean['object_type'] ) ) {
			$parts[] = 'obj:' . $clean['object_type'] . ( isset( $clean['object_id'] ) ? '/' . $clean['object_id'] : '' );
		}
		if ( isset( $clean['severity'] ) && 'min' === ( $clean['severity_mode'] ?? 'min' ) ) {
			$parts[] = 'min:' . $clean['severity'];
		}
		if ( isset( $clean['s'] ) ) {
			$parts[] = str_contains( $clean['s'], ' ' ) ? '"' . str_replace( '"', '', $clean['s'] ) . '"' : $clean['s'];
		}
		return implode( ' ', $parts );
	}

	/**
	 * Length of a window in seconds: 1h, 24h, 7d, 30m … (null for all, today and invalid values).
	 *
	 * @param string $window Window.
	 */
	public static function windowSeconds( string $window ): ?int {
		if ( 1 !== preg_match( '/^([1-9]\d{0,3})([mhd])$/', $window, $m ) ) {
			return null;
		}
		$unit = [
			'm' => 60,
			'h' => 3600,
			'd' => 86400,
		][ $m[2] ];
		return min( 3650 * 86400, (int) $m[1] * $unit );
	}

	/**
	 * Start of a window in Unix milliseconds (null: no lower bound).
	 *
	 * @param string        $window   Window (WINDOWS or <n>m|h|d).
	 * @param \DateTimeZone $timezone Site timezone (for "today").
	 * @param int           $nowMs    Now in milliseconds.
	 */
	public static function windowStartMs( string $window, \DateTimeZone $timezone, int $nowMs ): ?int {
		if ( 'today' === $window ) {
			$midnight = ( new \DateTimeImmutable( '@' . intdiv( $nowMs, 1000 ) ) )->setTimezone( $timezone )->setTime( 0, 0 );
			return $midnight->getTimestamp() * 1000;
		}
		$seconds = self::windowSeconds( $window );
		return null === $seconds ? null : $nowMs - $seconds * 1000;
	}

	/**
	 * Restrict typed filters to a scope's categories (an empty intersection matches nothing).
	 *
	 * @param array<string, mixed> $filters    Typed filters.
	 * @param list<string>         $categories Scope categories.
	 * @return array<string, mixed>
	 */
	public static function scoped( array $filters, array $categories ): array {
		$wanted = isset( $filters['categories'] ) && is_array( $filters['categories'] ) ? $filters['categories'] : $categories;
		if ( isset( $filters['category'] ) && is_string( $filters['category'] ) ) {
			$wanted = array_intersect( $wanted, [ $filters['category'] ] );
			unset( $filters['category'] );
		}
		$wanted                = array_values( array_intersect( $categories, $wanted ) );
		$filters['categories'] = [] === $wanted ? [ '__none__' ] : $wanted;
		return $filters;
	}

	/**
	 * Comma separated list.
	 *
	 * @param string $value Raw value.
	 * @return list<string>
	 */
	private static function listOf( string $value ): array {
		$parts = preg_split( '/[\s,]+/', strtolower( $value ) );
		return array_values( array_filter( false === $parts ? [] : $parts, static fn( string $p ): bool => '' !== $p ) );
	}

	/**
	 * Typed repository filters from clean() output.
	 *
	 * @param array<string, string> $clean    Output of clean().
	 * @param \DateTimeZone         $timezone Site timezone for from/to and "today".
	 * @param int|null              $nowMs    Now in milliseconds (windows; tests).
	 * @return array<string, mixed>
	 */
	public static function toRepository( array $clean, \DateTimeZone $timezone, ?int $nowMs = null ): array {
		$filters = [];
		if ( isset( $clean['severity'] ) ) {
			$severity = Severity::tryFrom( $clean['severity'] );
			if ( null !== $severity ) {
				$filters['severity']      = $severity;
				$filters['severity_mode'] = 'exact' === ( $clean['severity_mode'] ?? 'min' ) ? 'exact' : 'min';
			}
		}
		$map = [
			'category'     => 'category',
			'event_action' => 'action_prefix',
			'source'       => 'source',
			'object_type'  => 'object_type',
			'object_id'    => 'object_id',
			'request_id'   => 'request_id',
			'fingerprint'  => 'fingerprint',
			's'            => 'search',
		];
		foreach ( $map as $from => $to ) {
			if ( isset( $clean[ $from ] ) && '' !== $clean[ $from ] ) {
				$filters[ $to ] = $clean[ $from ];
			}
		}
		if ( isset( $clean['actor_id'] ) ) {
			$filters['actor_id'] = (int) $clean['actor_id'];
		}
		if ( isset( $clean['from'] ) ) {
			$ms = self::dateToMs( $clean['from'], $timezone, false );
			if ( null !== $ms ) {
				$filters['since_ms'] = $ms;
			}
		}
		if ( isset( $clean['to'] ) ) {
			$ms = self::dateToMs( $clean['to'], $timezone, true );
			if ( null !== $ms ) {
				$filters['until_ms'] = $ms;
			}
		}
		if ( isset( $clean['levels'] ) ) {
			$levels = [];
			foreach ( explode( ',', $clean['levels'] ) as $value ) {
				$level = Severity::tryFrom( $value );
				if ( null !== $level ) {
					$levels[] = $level;
				}
			}
			if ( [] !== $levels ) {
				$filters['severities'] = $levels;
			}
		}
		if ( isset( $clean['cats'] ) ) {
			$filters['categories'] = array_values( array_intersect( Category::values(), explode( ',', $clean['cats'] ) ) );
		}
		if ( isset( $clean['window'] ) && ! isset( $clean['from'] ) ) {
			$since = self::windowStartMs( $clean['window'], $timezone, $nowMs ?? (int) floor( microtime( true ) * 1000 ) );
			if ( null !== $since ) {
				$filters['since_ms'] = $since;
			}
		}
		if ( isset( $clean['actor_type'] ) ) {
			$filters['actor_type'] = $clean['actor_type'];
		}
		if ( 'hide' === ( $clean['resolved'] ?? '' ) ) {
			$filters['exclude_resolved'] = true;
		}
		if ( isset( $clean['important'] ) && ! isset( $filters['severity'] ) ) {
			$filters['severity']      = Severity::NOTICE;
			$filters['severity_mode'] = 'min';
		}
		if ( isset( $clean['type'] ) ) {
			$type = Scope::typeFilters( $clean['type'] );
			if ( isset( $filters['categories'] ) && isset( $type['categories'] ) && is_array( $type['categories'] ) ) {
				$type['categories'] = array_values( array_intersect( $type['categories'], $filters['categories'] ) );
			}
			$filters = array_merge( $filters, $type );
		}
		return $filters;
	}

	/**
	 * Local date (Y-m-d or Y-m-dTH:i[:s]) in a timezone to Unix milliseconds.
	 *
	 * With $end a date-only value means the end of that day (exclusive bound:
	 * the next midnight) and a minute value the end of that minute.
	 *
	 * @param string        $value    Date.
	 * @param \DateTimeZone $timezone Timezone.
	 * @param bool          $end      Upper (exclusive) bound.
	 */
	public static function dateToMs( string $value, \DateTimeZone $timezone, bool $end ): ?int {
		$parts = self::parseDate( $value );
		if ( null === $parts ) {
			return null;
		}
		[ $y, $m, $d, $h, $i, $s, $precision ] = $parts;
		try {
			$date = ( new \DateTimeImmutable( 'now', $timezone ) )->setDate( $y, $m, $d )->setTime( $h, $i, $s );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( $end ) {
			$date = $date->modify(
				match ( $precision ) {
				'day'    => '+1 day',
				'minute' => '+1 minute',
				default  => '+1 second',
				}
			);
			if ( false === $date ) {
				return null;
			}
		}
		return $date->getTimestamp() * 1000;
	}

	/**
	 * Whitelisted order-by key ('' = default: severity, then newest).
	 *
	 * @param mixed $value Raw orderby.
	 */
	public static function orderBy( mixed $value ): string {
		return is_string( $value ) && in_array( $value, self::ORDER_BY, true ) ? $value : '';
	}

	/**
	 * Sort direction; default per column (time and count newest/largest first).
	 *
	 * @param mixed  $value   Raw order.
	 * @param string $orderBy Clean order-by.
	 */
	public static function order( mixed $value, string $orderBy ): string {
		if ( is_string( $value ) && in_array( strtolower( $value ), [ 'asc', 'desc' ], true ) ) {
			return strtolower( $value );
		}
		return in_array( $orderBy, [ 'category', 'action', 'severity' ], true ) ? 'asc' : 'desc';
	}

	/**
	 * Rows per page, clamped to 1..200.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function perPage( mixed $value ): int {
		if ( ! is_numeric( $value ) ) {
			return self::PER_PAGE_DEFAULT;
		}
		return max( 1, min( self::PER_PAGE_MAX, (int) $value ) );
	}

	/**
	 * Names of the filters in use (for logger.events_exported; never values).
	 *
	 * @param array<string, string> $clean Output of clean().
	 * @return list<string>
	 */
	public static function names( array $clean ): array {
		return array_values( array_intersect( self::PARAMS, array_keys( $clean ) ) );
	}

	/**
	 * Human readable description of the active filters (plain text, unescaped).
	 *
	 * @param array<string, string> $clean Output of clean().
	 * @return list<string> One phrase per filter, in PARAMS order.
	 */
	public static function describe( array $clean ): array {
		$out = [];
		if ( isset( $clean['severity'] ) ) {
			$out[] = 'exact' === ( $clean['severity_mode'] ?? 'min' )
				/* translators: %s: severity */
				? sprintf( __( 'severity %s', 'nf-logbook' ), $clean['severity'] )
				/* translators: %s: severity */
				: sprintf( __( 'severity %s or worse', 'nf-logbook' ), $clean['severity'] );
		}
		$labels = [
			/* translators: %s: event category */
			'category'     => __( 'category %s', 'nf-logbook' ),
			/* translators: %s: action prefix */
			'event_action' => __( 'action starting with %s', 'nf-logbook' ),
			/* translators: %s: event source */
			'source'       => __( 'source %s', 'nf-logbook' ),
			/* translators: %s: user id */
			'actor_id'     => __( 'actor id %s', 'nf-logbook' ),
			/* translators: %s: object type */
			'object_type'  => __( 'object type %s', 'nf-logbook' ),
			/* translators: %s: object id */
			'object_id'    => __( 'object id %s', 'nf-logbook' ),
			/* translators: %s: request id */
			'request_id'   => __( 'request %s', 'nf-logbook' ),
			/* translators: %s: fingerprint */
			'fingerprint'  => __( 'fingerprint %s', 'nf-logbook' ),
			/* translators: %s: local date and time */
			'from'         => __( 'from %s', 'nf-logbook' ),
			/* translators: %s: local date and time */
			'to'           => __( 'until %s', 'nf-logbook' ),
			/* translators: %s: search text */
			's'            => __( 'search "%s"', 'nf-logbook' ),
		];
		foreach ( $labels as $key => $format ) {
			if ( isset( $clean[ $key ] ) && '' !== $clean[ $key ] ) {
				$value = in_array( $key, [ 'from', 'to' ], true ) ? str_replace( 'T', ' ', $clean[ $key ] ) : $clean[ $key ];
				$out[] = sprintf( $format, $value );
			}
		}
		if ( 'hide' === ( $clean['resolved'] ?? '' ) ) {
			$out[] = __( 'resolved issues hidden', 'nf-logbook' );
		}
		return $out;
	}

	/**
	 * Parse a date value.
	 *
	 * @param string $value Value.
	 * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int, 6: string}|null
	 */
	private static function parseDate( string $value ): ?array {
		if ( 1 !== preg_match( self::DATE, $value, $m ) ) {
			return null;
		}
		[ $y, $mo, $d ] = [ (int) $m[1], (int) $m[2], (int) $m[3] ];
		if ( ! checkdate( $mo, $d, $y ) || $y < 1970 || $y > 9999 ) {
			return null;
		}
		$h = isset( $m[4] ) ? (int) $m[4] : 0;
		$i = isset( $m[5] ) ? (int) $m[5] : 0;
		$s = isset( $m[6] ) ? (int) $m[6] : 0;
		if ( $h > 23 || $i > 59 || $s > 59 ) {
			return null;
		}
		$precision = ! isset( $m[4] ) ? 'day' : ( isset( $m[6] ) ? 'second' : 'minute' );
		return [ $y, $mo, $d, $h, $i, $s, $precision ];
	}

	/**
	 * Single-line text without control characters, clipped.
	 *
	 * @param string $value Value.
	 * @param int    $max   Max characters.
	 */
	private static function text( string $value, int $max ): string {
		$value = (string) preg_replace( '/[\p{Cc}\p{Cf}]+/u', '', $value );
		$value = trim( $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}
}
