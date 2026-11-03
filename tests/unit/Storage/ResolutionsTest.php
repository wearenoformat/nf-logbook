<?php
/**
 * Resolutions: issue keys, the coverage rule, SQL shapes; schema upgrade to v2.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Storage;

use Brain\Monkey\Functions;
use Noformat\Logger\Admin\EventFilters;
use Noformat\Logger\Severity;
use Noformat\Logger\Storage\EventRepository;
use Noformat\Logger\Storage\Meta;
use Noformat\Logger\Storage\Resolutions;
use Noformat\Logger\Storage\Schema;
use Noformat\Logger\Tests\TestCase;

if ( ! class_exists( \wpdb::class, false ) ) {
	require __DIR__ . '/FakeWpdb.php';
}

final class ResolutionsTest extends TestCase {

	private const FP = '0123456789abcdef0123456789abcdef';

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

	public function test_issue_keys_and_references(): void {
		self::assertSame( self::FP, Resolutions::key( self::FP, 12 ) );
		self::assertSame( 'event:12', Resolutions::key( null, 12 ) );
		self::assertSame( 'event:7', Resolutions::keyForRow( [ 'id' => '7', 'fingerprint' => null ] ) );
		self::assertSame( self::FP, Resolutions::keyForRow( [ 'id' => '7', 'fingerprint' => self::FP ] ) );
		self::assertSame(
			[
				'fingerprint' => self::FP,
				'event_id'    => null,
			],
			Resolutions::parse( strtoupper( self::FP ) )
		);
		self::assertSame(
			[
				'fingerprint' => null,
				'event_id'    => 42,
			],
			Resolutions::parse( 'event:42' )
		);
		foreach ( [ '', 'event:0', 'event:-1', 'event:abc', substr( self::FP, 1 ), self::FP . 'a', '42' ] as $bad ) {
			self::assertNull( Resolutions::parse( $bad ), $bad );
		}
	}

	public function test_coverage_rule_through_id(): void {
		$fp = [
			'fingerprint' => self::FP,
			'event_id'    => null,
			'through_id'  => 10,
		];
		self::assertTrue( Resolutions::covers( $fp, [ 'id' => 10, 'fingerprint' => self::FP ] ) );
		self::assertTrue( Resolutions::covers( $fp, [ 'id' => '3', 'fingerprint' => self::FP ] ) );
		self::assertFalse( Resolutions::covers( $fp, [ 'id' => 11, 'fingerprint' => self::FP ] ), 'a later occurrence is open again' );
		self::assertFalse( Resolutions::covers( $fp, [ 'id' => 5, 'fingerprint' => str_repeat( 'b', 32 ) ] ) );
		self::assertFalse( Resolutions::covers( $fp, [ 'id' => 5, 'fingerprint' => null ] ) );

		$single = [
			'fingerprint' => null,
			'event_id'    => 8,
			'through_id'  => 8,
		];
		self::assertTrue( Resolutions::covers( $single, [ 'id' => 8, 'fingerprint' => null ] ) );
		self::assertFalse( Resolutions::covers( $single, [ 'id' => 7, 'fingerprint' => null ] ) );
		self::assertFalse( Resolutions::covers( $single, [ 'id' => 8, 'fingerprint' => self::FP ] ) );
	}

	public function test_save_and_prune_sql(): void {
		$store = new Resolutions();
		$store->save( self::FP, null, 99, 1_700_000_000, 3 );
		$store->save( null, 12, 12, 1_700_000_001, null );
		$store->prune( 0 );
		$store->prune( 50 );
		self::assertSame(
			[
				"REPLACE INTO `wp_nflog_resolutions` (issue_key, fingerprint, event_id, through_id, resolved_at, resolved_by) VALUES ('" . self::FP . "', '" . self::FP . "', NULL, 99, 1700000000, 3)",
				"REPLACE INTO `wp_nflog_resolutions` (issue_key, fingerprint, event_id, through_id, resolved_at, resolved_by) VALUES ('event:12', NULL, 12, 12, 1700000001, NULL)",
				'DELETE FROM `wp_nflog_resolutions` WHERE through_id <= 50',
			],
			$this->wpdb->ran
		);
	}

	public function test_shape_types_rows(): void {
		self::assertSame(
			[
				'issue_key'   => 'event:5',
				'fingerprint' => null,
				'event_id'    => 5,
				'through_id'  => 5,
				'resolved_at' => 1_700_000_000,
				'resolved_by' => null,
			],
			Resolutions::shape(
				[
					'issue_key'   => 'event:5',
					'fingerprint' => null,
					'event_id'    => '5',
					'through_id'  => '5',
					'resolved_at' => '1700000000',
					'resolved_by' => '0',
				]
			)
		);
	}

	public function test_counts_exclude_resolved_only_on_request(): void {
		Functions\when( 'get_option' )->justReturn( Schema::VERSION );
		$repo = new EventRepository( new Meta() );
		$repo->countsBySeverity( 1_700_000_000_000, [], [ 'error' ] );
		self::assertSame( "SELECT severity, COUNT(*) AS events, SUM(`count`) AS occurrences FROM `wp_nflog_events` WHERE occurred_ms >= 1700000000000 AND category IN ('error') GROUP BY severity", $this->wpdb->ran[0], 'REST summary / health counts unchanged' );

		$repo->countsBySeverity( 1_700_000_000_000, [], [ 'error' ], true );
		self::assertSame(
			"SELECT severity, COUNT(*) AS events, SUM(`count`) AS occurrences FROM `wp_nflog_events` WHERE occurred_ms >= 1700000000000 AND category IN ('error') AND NOT EXISTS (SELECT 1 FROM `wp_nflog_resolutions` r WHERE r.fingerprint = `wp_nflog_events`.fingerprint AND r.through_id >= `wp_nflog_events`.id) AND NOT EXISTS (SELECT 1 FROM `wp_nflog_resolutions` r WHERE r.event_id = `wp_nflog_events`.id) GROUP BY severity",
			$this->wpdb->ran[1]
		);
	}

	public function test_no_resolution_filter_before_the_upgrade(): void {
		Functions\when( 'get_option' )->justReturn( 1 );
		( new EventRepository( new Meta() ) )->countsBySeverity( 1_700_000_000_000, [], [], true );
		self::assertStringNotContainsString( 'nflog_resolutions', $this->wpdb->ran[0] );
		[ $where ] = EventRepository::viewerWhere( [ 'exclude_resolved' => true ] );
		self::assertSame( '', $where );
	}

	public function test_viewer_filters_hide_resolved_and_cap_severity(): void {
		Functions\when( 'get_option' )->justReturn( Schema::VERSION );
		$clean = EventFilters::clean(
			[
				'resolved' => 'hide',
				'window'   => 'all',
			]
		);
		self::assertSame( 'hide', $clean['resolved'] );
		self::assertArrayNotHasKey( 'resolved', EventFilters::clean( [ 'resolved' => 'show' ] ) );
		$filters = EventFilters::toRepository( $clean, new \DateTimeZone( 'UTC' ), 1_700_000_000_000 );
		self::assertTrue( $filters['exclude_resolved'] );

		[ $where, $args ] = EventRepository::viewerWhere( $filters + [ 'max_priority' => Severity::WARNING->priority() ] );
		self::assertSame( ' WHERE severity <= %d AND NOT EXISTS (SELECT 1 FROM %i r WHERE r.fingerprint = %i.fingerprint AND r.through_id >= %i.id) AND NOT EXISTS (SELECT 1 FROM %i r WHERE r.event_id = %i.id)', $where );
		self::assertSame( [ 2, 'wp_nflog_resolutions', 'wp_nflog_events', 'wp_nflog_events', 'wp_nflog_resolutions', 'wp_nflog_events' ], $args );
	}

	public function test_schema_v2_adds_the_resolutions_table(): void {
		self::assertSame( 2, Schema::VERSION );
		self::assertContains( 'resolutions', Schema::TABLES );
		self::assertArrayHasKey( 2, Schema::migrations() );
		self::assertSame( Schema::VERSION, max( array_keys( Schema::migrations() ) ), 'a migration per version' );
		self::assertSame( Resolutions::SINCE_SCHEMA, Schema::VERSION );
		$sql = Schema::definitions()['resolutions'];
		self::assertStringStartsWith( 'CREATE TABLE wp_nflog_resolutions (', $sql );
		foreach ( [ 'issue_key varchar(64) NOT NULL', 'fingerprint char(32) NULL', 'event_id bigint(20) unsigned NULL', 'through_id bigint(20) unsigned NOT NULL DEFAULT 0', 'resolved_at bigint(20) unsigned NOT NULL DEFAULT 0', 'resolved_by bigint(20) unsigned NULL', 'PRIMARY KEY  (issue_key)', 'KEY fingerprint (fingerprint)', 'KEY event_id (event_id)', 'KEY through_id (through_id)' ] as $part ) {
			self::assertStringContainsString( $part, $sql );
		}
		self::assertStringNotContainsString( 'resolved', Schema::definitions()['events'], 'events stay untouched' );
	}

	public function test_upgrade_from_v1_runs_dbdelta_and_records_v2(): void {
		$db                     = new MemoryDb();
		$this->wpdb->handler    = $db;
		$db->meta['site_salt']  = [
			'value'      => 's',
			'updated_at' => 0,
		];
		$db->meta['chain_head'] = [
			'value'      => '{"id":5,"hash":"x"}',
			'updated_at' => 0,
		];
		$db->meta['installed_at']         = [
			'value'      => '1',
			'updated_at' => 0,
		];
		$db->meta['nflog_schema_version'] = [
			'value'      => '1',
			'updated_at' => 0,
		];
		$delta = [];
		Functions\when( 'dbDelta' )->alias(
			static function ( array $queries ) use ( &$delta ): array {
				$delta = $queries;
				return [];
			}
		);
		$cached = null;
		Functions\when( 'update_option' )->alias(
			static function ( string $name, mixed $value ) use ( &$cached ): bool {
				if ( Schema::CACHE_OPTION === $name ) {
					$cached = $value;
				}
				return true;
			}
		);
		Schema::install();
		self::assertCount( 5, $delta );
		self::assertNotEmpty( array_filter( $delta, static fn( string $q ): bool => str_contains( $q, 'CREATE TABLE wp_nflog_resolutions' ) ) );
		self::assertSame( '2', $db->meta['nflog_schema_version']['value'] );
		self::assertSame( 2, $cached );
		self::assertSame( '{"id":5,"hash":"x"}', $db->meta['chain_head']['value'], 'chain head untouched' );
	}

	public function test_upgrade_check_runs_install_when_behind(): void {
		if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
			define( 'MINUTE_IN_SECONDS', 60 );
		}
		Functions\when( 'get_option' )->justReturn( 1 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\expect( 'set_transient' )->once();
		$db                               = new MemoryDb();
		$this->wpdb->handler              = $db;
		$db->meta['nflog_schema_version'] = [
			'value'      => '1',
			'updated_at' => 0,
		];
		$db->meta['site_salt']            = [
			'value'      => 's',
			'updated_at' => 0,
		];
		$db->meta['chain_head']           = [
			'value'      => '{"id":0,"hash":"g"}',
			'updated_at' => 0,
		];
		$db->meta['installed_at']         = [
			'value'      => '1',
			'updated_at' => 0,
		];
		Functions\expect( 'dbDelta' )->once()->andReturn( [] );
		Functions\when( 'update_option' )->justReturn( true );
		Schema::maybeUpgrade();
		self::assertSame( '2', $db->meta['nflog_schema_version']['value'] );
	}
}
