<?php
/**
 * Viewer filter sanitisation.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Admin;

use Noformat\Logger\Admin\EventFilters;
use Noformat\Logger\Severity;
use Noformat\Logger\Tests\TestCase;

final class EventFiltersTest extends TestCase {

	public function test_invalid_enum_values_are_rejected(): void {
		$clean = EventFilters::clean(
			[
				'severity'      => 'loud',
				'category'      => 'nope',
				'source'        => 'carrier-pigeon',
				'actor_id'      => '0',
				'request_id'    => 'xyz',
				'fingerprint'   => str_repeat( 'g', 32 ),
				'event_action'  => "post.' OR 1=1 --",
				'object_type'   => 'post type!',
				'object_id'     => '5',
				'from'          => '2026-02-30',
				'to'            => 'yesterday',
				'severity_mode' => 'exact',
				'unknown'       => 'x',
				'category[]'    => 'content',
			]
		);
		self::assertSame( [], $clean, 'object_id without a valid object_type is dropped too' );
	}

	public function test_valid_values_are_kept_and_normalised(): void {
		$clean = EventFilters::clean(
			[
				'severity'      => 'ERROR',
				'severity_mode' => 'exact',
				'category'      => 'security',
				'source'        => 'rest',
				'event_action'  => 'Post.',
				'actor_id'      => '12',
				'object_type'   => 'post',
				'object_id'     => " 42\x00 ",
				'request_id'    => str_repeat( 'A', 32 ),
				'fingerprint'   => str_repeat( 'b', 32 ),
				'from'          => '2026-10-01 08:30',
				'to'            => '2026-10-02',
				's'             => "  fatal\x07 error ",
			]
		);
		self::assertSame(
			[
				'severity'      => 'error',
				'severity_mode' => 'exact',
				'category'      => 'security',
				'event_action'  => 'post.',
				'source'        => 'rest',
				'actor_id'      => '12',
				'object_type'   => 'post',
				'object_id'     => '42',
				'request_id'    => str_repeat( 'a', 32 ),
				'fingerprint'   => str_repeat( 'b', 32 ),
				'from'          => '2026-10-01T08:30',
				'to'            => '2026-10-02',
				's'             => 'fatal error',
			],
			$clean
		);
		self::assertSame( [ 'severity', 'severity_mode', 'category', 'event_action', 'source', 'actor_id', 'object_type', 'object_id', 'request_id', 'fingerprint', 'from', 'to', 's' ], EventFilters::names( $clean ) );
	}

	public function test_min_severity_alias_and_default_mode(): void {
		self::assertSame(
			[
				'severity'      => 'error',
				'severity_mode' => 'min',
			],
			EventFilters::clean( [ 'min_severity' => 'error' ] )
		);
		self::assertSame( 'min', EventFilters::clean( [ 'severity' => '2' ] )['severity_mode'] );
		self::assertSame( 'warning', EventFilters::clean( [ 'severity' => '2' ] )['severity'] );
		self::assertSame( [], EventFilters::clean( [ 'severity' => [ 'error' ] ] ), 'array values are ignored' );
	}

	public function test_to_repository_types_and_dates_in_site_timezone(): void {
		$tz      = new \DateTimeZone( 'Europe/Berlin' );
		$filters = EventFilters::toRepository(
			EventFilters::clean(
				[
					'min_severity' => 'warning',
					'actor_id'     => '7',
					'event_action' => 'php.',
					's'            => '100%_sure',
					'from'         => '2026-10-28T10:00',
					'to'           => '2026-10-28',
				]
			),
			$tz
		);
		self::assertSame( Severity::WARNING, $filters['severity'] );
		self::assertSame( 'min', $filters['severity_mode'] );
		self::assertSame( 7, $filters['actor_id'] );
		self::assertSame( 'php.', $filters['action_prefix'] );
		self::assertSame( '100%_sure', $filters['search'] );
		// 10:00 CET (UTC+1 after the DST change on 2026-10-25) = 09:00 UTC.
		self::assertSame( gmmktime( 9, 0, 0, 10, 28, 2026 ) * 1000, $filters['since_ms'] );
		// Date-only upper bound: the next local midnight, exclusive.
		self::assertSame( gmmktime( 23, 0, 0, 10, 28, 2026 ) * 1000, $filters['until_ms'] );
	}

	public function test_date_to_ms_precision_and_invalid_dates(): void {
		$utc = new \DateTimeZone( 'UTC' );
		self::assertSame( gmmktime( 0, 0, 0, 1, 2, 2026 ) * 1000, EventFilters::dateToMs( '2026-01-01', $utc, true ) );
		self::assertSame( gmmktime( 12, 31, 0, 1, 1, 2026 ) * 1000, EventFilters::dateToMs( '2026-01-01T12:30', $utc, true ) );
		self::assertSame( gmmktime( 12, 30, 6, 1, 1, 2026 ) * 1000, EventFilters::dateToMs( '2026-01-01T12:30:05', $utc, true ) );
		self::assertSame( gmmktime( 12, 30, 0, 1, 1, 2026 ) * 1000, EventFilters::dateToMs( '2026-01-01 12:30', $utc, false ) );
		self::assertNull( EventFilters::dateToMs( '2026-13-01', $utc, false ) );
		self::assertNull( EventFilters::dateToMs( '2026-01-01T25:00', $utc, false ) );
		self::assertNull( EventFilters::dateToMs( '1960-01-01', $utc, false ) );
		self::assertNull( EventFilters::dateToMs( '@1700000000', $utc, false ) );
	}

	public function test_per_page_is_clamped(): void {
		self::assertSame( 50, EventFilters::perPage( null ) );
		self::assertSame( 50, EventFilters::perPage( 'lots' ) );
		self::assertSame( 1, EventFilters::perPage( '0' ) );
		self::assertSame( 1, EventFilters::perPage( -5 ) );
		self::assertSame( 75, EventFilters::perPage( '75' ) );
		self::assertSame( 200, EventFilters::perPage( 100000 ) );
	}

	public function test_order_by_whitelist_and_default_direction(): void {
		foreach ( [ 'time', 'severity', 'category', 'action', 'count' ] as $column ) {
			self::assertSame( $column, EventFilters::orderBy( $column ) );
		}
		foreach ( [ 'id', 'occurred_ms', 'summary', 'severity; DROP TABLE x', '', null, [ 'time' ], 'TIME' ] as $bad ) {
			self::assertSame( '', EventFilters::orderBy( $bad ) );
		}
		self::assertSame( 'desc', EventFilters::order( null, 'time' ) );
		self::assertSame( 'desc', EventFilters::order( 'sideways', 'count' ) );
		self::assertSame( 'asc', EventFilters::order( null, 'category' ) );
		self::assertSame( 'asc', EventFilters::order( 'ASC', 'time' ) );
		self::assertSame( 'desc', EventFilters::order( 'desc', 'action' ) );
	}

	public function test_describe_lists_active_filters_in_order(): void {
		\Brain\Monkey\Functions\stubTranslationFunctions();
		self::assertSame( [], EventFilters::describe( [] ) );
		self::assertSame(
			[ 'severity error or worse', 'category security' ],
			EventFilters::describe( EventFilters::clean( [ 'category' => 'security', 'severity' => 'error', 'severity_mode' => 'min' ] ) )
		);
		self::assertSame(
			[ 'severity critical', 'action starting with post.', 'from 2026-01-02 03:04', 'search "x y"' ],
			EventFilters::describe( [ 'severity' => 'critical', 'severity_mode' => 'exact', 'event_action' => 'post.', 'from' => '2026-01-02T03:04', 's' => 'x y' ] )
		);
	}

	public function test_parse_query_maps_console_tokens(): void {
		$raw = EventFilters::parseQuery( 'undefined key action:php.warning cat:security cat:error src:cron req:' . str_repeat( 'A', 32 ) . ' fp:' . str_repeat( 'b', 32 ) . ' since:24h sev:error,warning actor:7 obj:post/12 "array offset" foo:bar' );
		self::assertSame(
			[
				'event_action' => 'php.warning',
				'cats'         => 'security,error',
				'source'       => 'cron',
				'request_id'   => str_repeat( 'A', 32 ),
				'fingerprint'  => str_repeat( 'b', 32 ),
				'window'       => '24h',
				'levels'       => 'error,warning',
				'actor_id'     => '7',
				'object_type'  => 'post',
				'object_id'    => '12',
				's'            => 'undefined key array offset foo:bar',
			],
			$raw
		);
		$clean = EventFilters::clean( $raw );
		self::assertSame( str_repeat( 'a', 32 ), $clean['request_id'], 'validated by clean()' );
		self::assertSame( 'security,error', $clean['cats'] );
		self::assertSame( [ 'actor_type' => 'cli' ], EventFilters::parseQuery( 'actor:cli' ) );
		self::assertSame( [], EventFilters::parseQuery( '   ' ) );
		self::assertSame( [ 'severity' => 'warning', 'severity_mode' => 'min' ], EventFilters::parseQuery( 'min:warning' ) );
	}

	public function test_parse_query_values_are_still_validated(): void {
		$clean = EventFilters::clean( EventFilters::parseQuery( "cat:nope src:pigeon since:forever req:xyz action:'x;drop" ) );
		self::assertSame( [], $clean );
		self::assertArrayNotHasKey( 'cats', $clean );
		self::assertArrayNotHasKey( 'source', $clean );
		self::assertArrayNotHasKey( 'window', $clean );
		self::assertArrayNotHasKey( 'request_id', $clean );
		self::assertArrayNotHasKey( 'event_action', $clean );
	}

	public function test_from_input_merges_query_and_controls(): void {
		$clean = EventFilters::fromInput(
			[
				'q'      => 'cat:http timeout',
				'cats'   => [ 'mail' ],
				'levels' => [ 'error', 'bogus', 'critical' ],
				'window' => '7d',
				's'      => 'stale text replaced by q',
				'person' => 'cron',
			]
		);
		self::assertSame( 'mail,http', $clean['cats'] );
		self::assertSame( 'error,critical', $clean['levels'] );
		self::assertSame( 'timeout', $clean['s'] );
		self::assertSame( '7d', $clean['window'] );
		self::assertSame( 'cron', $clean['actor_type'] );
		self::assertSame( 'cat:mail,http sev:error,critical since:7d actor:cron timeout', EventFilters::toQuery( $clean ) );
	}

	public function test_windows_levels_and_scope_reach_the_repository_filters(): void {
		$now     = 1_700_000_000_000;
		$filters = EventFilters::toRepository( EventFilters::clean( [ 'window' => '24h', 'levels' => 'critical,error', 'cats' => 'security' ] ), new \DateTimeZone( 'UTC' ), $now );
		self::assertSame( $now - 86_400_000, $filters['since_ms'] );
		self::assertSame( [ Severity::CRITICAL, Severity::ERROR ], $filters['severities'] );
		self::assertSame( [ 'security' ], EventFilters::scoped( $filters, [ 'security', 'error' ] )['categories'] );
		self::assertSame( [ '__none__' ], EventFilters::scoped( $filters, [ 'content' ] )['categories'], 'a category outside the scope matches nothing' );
		self::assertSame( [ 'content', 'media' ], EventFilters::scoped( [], [ 'content', 'media' ] )['categories'] );
		self::assertNull( EventFilters::windowStartMs( 'all', new \DateTimeZone( 'UTC' ), $now ) );
		self::assertSame( 1_699_920_000_000, EventFilters::windowStartMs( 'today', new \DateTimeZone( 'UTC' ), $now ) );
		self::assertSame( 15 * 60, EventFilters::windowSeconds( '15m' ) );
		$important = EventFilters::toRepository( EventFilters::clean( [ 'important' => '1', 'type' => 'pages' ] ), new \DateTimeZone( 'UTC' ), $now );
		self::assertSame( Severity::NOTICE, $important['severity'] );
		self::assertSame( 'page', $important['object_subtype'] );
	}
}
