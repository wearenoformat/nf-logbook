<?php
/**
 * EventRepository::query() / count() for the wp-admin viewer.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Storage;

use Noformat\Logger\Admin\EventFilters;
use Noformat\Logger\Severity;
use Noformat\Logger\Storage\EventRepository;
use Noformat\Logger\Storage\Meta;
use Noformat\Logger\Tests\TestCase;

if ( ! class_exists( \wpdb::class, false ) ) {
	require __DIR__ . '/FakeWpdb.php';
}

final class EventRepositoryViewerTest extends TestCase {

	private mixed $previous = null;

	private \wpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->previous  = $GLOBALS['wpdb'] ?? null;
		$this->wpdb      = new \wpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous;
		parent::tearDown();
	}

	public function test_no_filters_default_order_is_bounded(): void {
		( new EventRepository( new Meta() ) )->query( [], 1, 50 );
		self::assertSame( [ 'SELECT * FROM `wp_nflog_events` ORDER BY severity ASC, occurred_ms DESC, id DESC LIMIT 50 OFFSET 0' ], $this->wpdb->ran );
	}

	public function test_every_filter_becomes_a_prepared_clause(): void {
		$filters = [
			'severity'      => Severity::WARNING,
			'severity_mode' => 'min',
			'category'      => 'security',
			'action_prefix' => 'api.auth_',
			'source'        => 'rest',
			'actor_id'      => 3,
			'object_type'   => 'post',
			'object_id'     => "4'2",
			'request_id'    => str_repeat( 'a', 32 ),
			'fingerprint'   => str_repeat( 'f', 32 ),
			'since_ms'      => 1_700_000_000_000,
			'until_ms'      => 1_700_000_100_000,
			'search'        => '50%_off',
		];
		( new EventRepository( new Meta() ) )->query( $filters, 3, 20, 'time', 'asc' );
		self::assertSame(
			'SELECT * FROM `wp_nflog_events` WHERE severity <= 2 AND category = \'security\' AND source = \'rest\' AND object_type = \'post\' AND object_id = \'4\\\'2\''
			. ' AND request_id = \'' . str_repeat( 'a', 32 ) . '\' AND fingerprint = \'' . str_repeat( 'f', 32 ) . '\''
			. ' AND action LIKE \'api.auth\\\\_%\' AND actor_id = 3 AND occurred_ms >= 1700000000000 AND occurred_ms < 1700000100000'
			. ' AND (summary LIKE \'%50\\\\%\\\\_off%\' OR action LIKE \'%50\\\\%\\\\_off%\')'
			. ' ORDER BY occurred_ms ASC, id ASC LIMIT 20 OFFSET 40',
			$this->wpdb->ran[0]
		);
	}

	public function test_exact_severity_and_count(): void {
		$repo = new EventRepository( new Meta() );
		$repo->count(
			[
				'severity'      => Severity::CRITICAL,
				'severity_mode' => 'exact',
			]
		);
		$repo->count( [] );
		self::assertSame( 'SELECT COUNT(*) FROM `wp_nflog_events` WHERE severity = 0', $this->wpdb->ran[0] );
		self::assertSame( 'SELECT COUNT(*) FROM `wp_nflog_events`', $this->wpdb->ran[1] );
	}

	public function test_wrong_types_are_ignored(): void {
		[ $where, $args ] = EventRepository::viewerWhere(
			[
				'severity'    => 'critical',
				'category'    => [ 'a', 'b' ],
				'actor_id'    => '3',
				'since_ms'    => '1700000000000',
				'search'      => '',
				'object_id'   => 42,
				'unsupported' => 'x',
			]
		);
		self::assertSame( '', $where );
		self::assertSame( [], $args );
	}

	public function test_order_by_whitelist(): void {
		self::assertSame( 'severity ASC, occurred_ms DESC, id DESC', EventRepository::viewerOrder( '', 'desc' ) );
		self::assertSame( 'severity ASC, occurred_ms DESC, id DESC', EventRepository::viewerOrder( 'id; DROP TABLE wp_users', 'asc' ) );
		self::assertSame( 'occurred_ms DESC, id DESC', EventRepository::viewerOrder( 'time', 'sideways' ) );
		self::assertSame( 'severity DESC, occurred_ms DESC, id DESC', EventRepository::viewerOrder( 'severity', 'DESC' ) );
		self::assertSame( 'category ASC, severity ASC, occurred_ms DESC, id DESC', EventRepository::viewerOrder( 'category', 'asc' ) );
		self::assertSame( '`count` DESC, severity ASC, occurred_ms DESC, id DESC', EventRepository::viewerOrder( 'count', 'desc' ) );
	}

	public function test_page_size_bounds(): void {
		$repo = new EventRepository( new Meta() );
		$repo->query( [], 0, 0 );
		$repo->query( [], 2, 100000 );
		self::assertStringEndsWith( 'LIMIT 1 OFFSET 0', $this->wpdb->ran[0] );
		self::assertStringEndsWith( 'LIMIT 5000 OFFSET 5000', $this->wpdb->ran[1] );
	}

	public function test_filters_from_the_request_reach_sql_only_as_validated_values(): void {
		$clean   = EventFilters::clean(
			[
				'severity'     => "1 OR 1=1",
				'category'     => "content' OR '1'='1",
				'event_action' => 'post.',
				'from'         => '2026-10-28',
			]
		);
		$filters = EventFilters::toRepository( $clean, new \DateTimeZone( 'UTC' ) );
		( new EventRepository( new Meta() ) )->query( $filters );
		self::assertSame( "SELECT * FROM `wp_nflog_events` WHERE action LIKE 'post.%' AND occurred_ms >= " . ( gmmktime( 0, 0, 0, 10, 28, 2026 ) * 1000 ) . ' ORDER BY severity ASC, occurred_ms DESC, id DESC LIMIT 50 OFFSET 0', $this->wpdb->ran[0] );
	}

	public function test_page_counts_are_hydrated_from_fingerprint_rows(): void {
		$db                = new MemoryDb();
		$this->wpdb->handler = new class( $db ) {
			public function __construct( private MemoryDb $db ) {
			}
			public function getResults( string $sql ): array {
				if ( str_starts_with( $sql, 'SELECT * FROM `wp_nflog_events` ORDER BY' ) ) {
					return [
						[
							'id'          => 7,
							'fingerprint' => str_repeat( 'c', 32 ),
							'count'       => 1,
						],
						[
							'id'          => 8,
							'fingerprint' => null,
							'count'       => 1,
						],
					];
				}
				return $this->db->getResults( $sql );
			}
		};
		$db->fingerprints[ str_repeat( 'c', 32 ) ] = [
			'fingerprint'   => str_repeat( 'c', 32 ),
			'last_event_id' => 7,
			'window_start'  => 1,
			'count'         => 9,
			'last_seen'     => 1,
		];
		$rows = ( new EventRepository( new Meta() ) )->query( [] );
		self::assertSame( 9, $rows[0]['count'] );
		self::assertSame( 1, $rows[1]['count'] );
		self::assertCount( 2, $this->wpdb->ran, 'one page query + one fingerprint lookup' );
	}

	public function test_stories_page_groups_by_request_in_sql(): void {
		$repo = new EventRepository( new Meta() );
		$this->wpdb->results = [];
		$repo->storiesPage( [ 'categories' => [ 'content', 'fields' ] ], 2, 10 );
		self::assertSame(
			"SELECT COUNT(DISTINCT CASE WHEN request_id <> '' THEN request_id END) AS requests, SUM(CASE WHEN request_id = '' THEN 1 ELSE 0 END) AS singles FROM `wp_nflog_events` WHERE category IN ('content','fields')",
			$this->wpdb->ran[0]
		);
		self::assertCount( 1, $this->wpdb->ran, 'no story query when the total is 0' );
		$this->wpdb->ran = [];
		$repo->storiesPage( [], 3, 5, false );
		self::assertSame(
			"SELECT request_id, MAX(id) AS last_id, MAX(occurred_ms) AS last_ms, COUNT(*) AS events FROM `wp_nflog_events` GROUP BY request_id, CASE WHEN request_id = '' THEN id ELSE 0 END ORDER BY last_ms DESC, last_id DESC LIMIT 5 OFFSET 10",
			$this->wpdb->ran[0]
		);
	}
}
