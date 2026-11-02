<?php
/**
 * Logger flush against an in-memory database: query budget of recurring
 * fingerprints (H2), anonymous flood cap (H1), closed windows after delivery
 * (M2), non-transactional crash path (M7), buffer flush instead of drop (M8),
 * prune guard (H1).
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Storage;

use Brain\Monkey\Functions;
use Noformat\Logger\Category;
use Noformat\Logger\Config;
use Noformat\Logger\Context\RequestContext;
use Noformat\Logger\Event;
use Noformat\Logger\Logger;
use Noformat\Logger\Privacy\Redactor;
use Noformat\Logger\Rest\Authenticator;
use Noformat\Logger\Severity;
use Noformat\Logger\Storage\EventRepository;
use Noformat\Logger\Storage\FingerprintStore;
use Noformat\Logger\Storage\Meta;
use Noformat\Logger\Tests\TestCase;

if ( ! class_exists( \wpdb::class, false ) ) {
	require __DIR__ . '/FakeWpdb.php';
}

final class FlushBudgetTest extends TestCase {

	private mixed $previous = null;

	private MemoryDb $db;

	/** @var array<string, mixed> */
	private array $server = [];

	protected function setUp(): void {
		parent::setUp();
		$this->previous = $GLOBALS['wpdb'] ?? null;
		$this->server   = $_SERVER;
		$this->db       = new MemoryDb();
		$wpdb           = new \wpdb();
		$wpdb->handler  = $this->db;
		$GLOBALS['wpdb'] = $wpdb;
		$this->db->meta['site_salt'] = [ 'value' => 'salt', 'updated_at' => 0 ];
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => strtolower( (string) $k ) );
		$_SERVER['REMOTE_ADDR']    = '203.0.113.7';
		$_SERVER['REQUEST_URI']    = '/sample-page/';
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous;
		$_SERVER         = $this->server;
		parent::tearDown();
	}

	/**
	 * A fresh "request": new services over the same database.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @param callable|null        $random   Sampling random source.
	 * @return array{0: Logger, 1: FingerprintStore}
	 */
	private function request( array $settings = [], ?callable $random = null ): array {
		$config  = new Config( $settings );
		$meta    = new Meta();
		$context = new RequestContext( $meta );
		$fps     = new FingerprintStore( $config, $random );
		$logger  = new Logger( $config, $context, new Redactor( $config->sensitiveKeys(), $config->safeKeys(), static fn() => $context->salt() ), new EventRepository( $meta ), $fps, $meta );
		return [ $logger, $fps ];
	}

	private static function notice( int $n = 1 ): Event {
		return Event::make( Category::ERROR, 'php.notice', Severity::WARNING )->context( [ 'message' => 'Undefined index' ] )->fingerprint( 'php.notice', 'x.php', 3 )->count( $n );
	}

	public function test_recurring_fingerprint_costs_one_select_and_one_update(): void {
		[ $first ] = $this->request();
		$first->log( self::notice() );
		$first->log( self::notice() );
		$stored = $first->flush();
		self::assertCount( 1, $stored );
		self::assertSame( 2, $stored[0]->getCount(), 'coalesced in the request' );

		$this->db->log = [];
		[ $second ] = $this->request();
		$second->log( self::notice() );
		$second->log( self::notice() );
		self::assertSame( [], $second->flush() );
		$queries = $this->db->nflogQueries();
		self::assertCount( 2, $queries, implode( "\n", $queries ) );
		self::assertStringStartsWith( 'SELECT fingerprint', $queries[0] );
		self::assertStringStartsWith( 'UPDATE `wp_nflog_fingerprints` SET `count` = `count` + 2', $queries[1] );
		self::assertCount( 1, $this->db->events );
		self::assertSame( 2, (int) array_values( $this->db->events )[0]['count'], 'event row is not written per occurrence' );

		// Readers hydrate the live count from the fingerprint row.
		$rows = ( new EventRepository( new Meta() ) )->after( 0, 10 );
		self::assertSame( 4, (int) $rows['rows'][0]['count'] );
	}

	public function test_hot_fingerprint_writes_are_sampled(): void {
		[ $first ] = $this->request();
		$first->log( self::notice( 100 ) );
		$first->flush();
		$fp = array_key_first( $this->db->fingerprints );

		[ $skip ] = $this->request( [], static fn( int $min, int $max ): int => 2 );
		$this->db->log = [];
		$skip->log( self::notice( 3 ) );
		$skip->flush();
		self::assertCount( 1, $this->db->nflogQueries(), 'sampled out: the lookup only' );
		self::assertSame( 100, $this->db->fingerprints[ $fp ]['count'] );

		[ $write ] = $this->request( [], static fn( int $min, int $max ): int => 1 );
		$write->log( self::notice( 3 ) );
		$write->flush();
		self::assertSame( 130, $this->db->fingerprints[ $fp ]['count'], 'one in ten writes adds ten times' );
	}

	public function test_new_window_writes_the_final_count_back(): void {
		[ $first ] = $this->request();
		$first->log( self::notice() );
		$first->flush();
		[ $second ] = $this->request();
		$second->log( self::notice( 4 ) );
		$second->flush();
		$fp = array_key_first( $this->db->fingerprints );
		$this->db->fingerprints[ $fp ]['window_start'] -= 120; // Window over.

		[ $third ] = $this->request();
		$third->log( self::notice() );
		self::assertCount( 1, $third->flush() );
		self::assertSame( [ 5, 1 ], array_map( static fn( $r ) => (int) $r['count'], array_values( $this->db->events ) ) );
	}

	public function test_served_events_are_not_bumped(): void {
		[ $first, $fps ] = $this->request();
		$first->log( self::notice() );
		$stored = $first->flush();
		$fps->closeServed( (int) $stored[0]->getId() );

		[ $second ] = $this->request();
		$second->log( self::notice() );
		self::assertCount( 1, $second->flush(), 'window closed by delivery: new event' );
		self::assertCount( 2, $this->db->events );
		self::assertSame( 1, (int) array_values( $this->db->events )[0]['count'], 'the delivered count never changes' );
	}

	public function test_anonymous_flood_is_capped_and_folded(): void {
		for ( $i = 0; $i < 200; $i++ ) {
			[ $logger ] = $this->request();
			$_SERVER['REMOTE_ADDR'] = '198.51.100.' . ( $i % 250 );
			$logger->log( Authenticator::failureEvent( 'unknown_key', null, 'ip' . $i, '/noformat-logger/v1/events' ) );
			$logger->flush();
		}
		self::assertCount( 120, $this->db->eventsOf( 'api.auth_failed' ) );
		$flood = $this->db->eventsOf( 'system.flood' );
		self::assertCount( 1, $flood, 'one system.flood row per window' );
		$fp = (string) $flood[0]['fingerprint'];
		self::assertSame( 80, $this->db->fingerprints[ $fp ]['count'], 'dropped occurrences counted on the flood event' );
		$context = json_decode( (string) $flood[0]['context'], true );
		self::assertSame( [ [ 'action' => 'api.auth_failed', 'count' => 1 ] ], $context['top_actions'], 'context describes the first folded request; count carries the total' );
		self::assertSame( 'security', $flood[0]['category'] );
	}

	public function test_random_key_ids_from_one_client_aggregate(): void {
		for ( $i = 0; $i < 50; $i++ ) {
			[ $logger ] = $this->request();
			$logger->log( Authenticator::failureEvent( 'unknown_key', null, 'same-ip', '/noformat-logger/v1/events' ) );
			$logger->flush();
		}
		self::assertCount( 1, $this->db->events );
		$known = Authenticator::failureEvent( 'bad_signature', 'nfk_7Hs9Qk2LmP4xT8vZ', 'same-ip', '/x' );
		self::assertNotSame( Authenticator::failureEvent( 'bad_signature', null, 'same-ip', '/x' )->getFingerprint(), $known->getFingerprint(), 'a known key id is part of the fingerprint' );
		self::assertSame( 'nfk_7Hs9Qk2LmP4xT8vZ', $known->getObject()['id'] ?? null );
	}

	public function test_logged_in_actors_are_not_capped(): void {
		[ $logger ] = $this->request( [ 'anonymous_per_minute' => 1 ] );
		$logger->log( Event::make( Category::SECURITY, 'xmlrpc.call', Severity::WARNING )->actor( 'user', 3, 'editor' )->fingerprint( 'a' ) );
		$logger->log( Event::make( Category::SECURITY, 'xmlrpc.call', Severity::WARNING )->actor( 'user', 3, 'editor' )->fingerprint( 'b' ) );
		self::assertCount( 2, $logger->flush() );
	}

	public function test_crash_path_uses_no_transaction(): void {
		[ $logger ] = $this->request();
		self::assertNotNull( $logger->logNow( Event::make( Category::STABILITY, 'php.fatal', Severity::CRITICAL ), false ) );
		self::assertNotContains( 'START TRANSACTION', $this->db->log );
		self::assertSame( [], array_filter( $this->db->log, static fn( $q ) => str_contains( $q, 'FOR UPDATE' ) ) );
		$logger->log( Event::make( Category::SYSTEM, 'later' ) );
		$logger->flush();
		self::assertNotContains( 'START TRANSACTION', $this->db->log, 'rest of the request stays non-transactional' );

		[ $normal ] = $this->request();
		$normal->logNow( Event::make( Category::SYSTEM, 'x' ) );
		self::assertContains( 'START TRANSACTION', $this->db->log );
		self::assertContains( 'COMMIT', $this->db->log );
	}

	public function test_full_buffer_is_written_not_dropped(): void {
		[ $logger ] = $this->request( [ 'buffer_max' => 3 ] );
		for ( $i = 0; $i < 7; $i++ ) {
			self::assertTrue( $logger->log( Event::make( Category::SYSTEM, 'import.row' ) ) );
		}
		$logger->flush();
		self::assertCount( 7, $this->db->eventsOf( 'import.row' ) );
		self::assertCount( 0, $this->db->eventsOf( 'system.buffer_overflow' ) );
	}

	public function test_aggregated_occurrences_do_not_read_the_salt(): void {
		[ $first ] = $this->request();
		$first->log( self::notice() );
		$stored = $first->flush();
		self::assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', (string) $stored[0]->getRequest()['ip_hash'], 'hashes filled on write' );
		$this->db->log = [];
		[ $second ] = $this->request();
		$second->log( self::notice() );
		$second->flush();
		self::assertSame( [], array_filter( $this->db->log, static fn( $q ) => str_contains( $q, 'site_salt' ) ) );
	}

	public function test_prune_max_rows_never_removes_unacked_important_rows(): void {
		$now = 1_800_000_000_000;
		foreach ( [ 4, 4, 2, 4, 4, 5 ] as $i => $severity ) {
			$this->db->events[ $i + 1 ] = [ 'id' => $i + 1, 'severity' => $severity, 'occurred_ms' => $now - 1000, 'hash' => str_repeat( (string) $i, 64 ), 'count' => 1, 'action' => 'x' ];
		}
		$repo   = new EventRepository( new Meta() );
		$result = $repo->prune( 90, 180, 2, 1, false, $now );
		self::assertSame( 2, $result['boundary_id'], 'acked row 1 and unacked info row 2; stops before the warning (3)' );
		self::assertTrue( $result['max_rows_capped'] ?? false );
		self::assertSame( [ 3, 4, 5, 6 ], array_keys( $this->db->events ) );

		$old = $repo->prune( 90, 180, 2, 1, false, $now + 200 * 86400000 );
		self::assertSame( 6, $old['boundary_id'], 'hard_max_days still removes everything older' );
	}
}
