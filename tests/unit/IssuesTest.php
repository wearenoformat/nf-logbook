<?php
/**
 * Issues: resolve / reopen against the in-memory database, the logged events
 * (shape shared with the Controlroom), recurrence after a resolution, prune.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests;

use Brain\Monkey\Functions;
use Noformat\Logger\Category;
use Noformat\Logger\Config;
use Noformat\Logger\Context\RequestContext;
use Noformat\Logger\Event;
use Noformat\Logger\Issues;
use Noformat\Logger\Logger;
use Noformat\Logger\Privacy\Redactor;
use Noformat\Logger\Severity;
use Noformat\Logger\Storage\EventRepository;
use Noformat\Logger\Storage\FingerprintStore;
use Noformat\Logger\Storage\Meta;
use Noformat\Logger\Storage\Resolutions;
use Noformat\Logger\Storage\Schema;
use Noformat\Logger\Tests\Storage\MemoryDb;

if ( ! class_exists( \wpdb::class, false ) ) {
	require __DIR__ . '/Storage/FakeWpdb.php';
}

final class IssuesTest extends TestCase {

	private mixed $previous = null;

	private MemoryDb $db;

	/** @var array<string, mixed> */
	private array $server = [];

	protected function setUp(): void {
		parent::setUp();
		$this->previous              = $GLOBALS['wpdb'] ?? null;
		$this->server                = $_SERVER;
		$this->db                    = new MemoryDb();
		$wpdb                        = new \wpdb();
		$wpdb->handler               = $this->db;
		$GLOBALS['wpdb']             = $wpdb;
		$this->db->meta['site_salt'] = [
			'value'      => 'salt',
			'updated_at' => 0,
		];
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn( $k ) => strtolower( (string) $k ) );
		Functions\when( 'get_option' )->justReturn( Schema::VERSION );
		$_SERVER['REQUEST_URI']    = '/wp-admin/admin-ajax.php';
		$_SERVER['REQUEST_METHOD'] = 'POST';
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous;
		$_SERVER         = $this->server;
		parent::tearDown();
	}

	/**
	 * Fresh services over the same database.
	 *
	 * @return array{0: Logger, 1: Issues}
	 */
	private function request(): array {
		$config  = new Config( [] );
		$meta    = new Meta();
		$context = new RequestContext( $meta );
		$fps     = new FingerprintStore( $config );
		$logger  = new Logger( $config, $context, new Redactor( $config->sensitiveKeys(), $config->safeKeys(), static fn() => $context->salt() ), new EventRepository( $meta ), $fps, $meta );
		return [ $logger, new Issues( new Resolutions(), $fps, $logger ) ];
	}

	private static function warning(): Event {
		return Event::make( Category::ERROR, 'php.warning', Severity::ERROR )->context( [ 'message' => 'Undefined variable $x' ] )->fingerprint( 'php.warning', 'theme.php', 7 );
	}

	/** Stored id of a freshly logged event. */
	private function store( Event $event ): int {
		[ $logger ] = $this->request();
		$stored     = $logger->logNow( $event );
		self::assertNotNull( $stored );
		return (int) $stored->getId();
	}

	/**
	 * Stored rows of an action.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function logged( string $action ): array {
		return $this->db->eventsOf( $action );
	}

	public function test_resolve_fingerprint_logs_the_shared_event_shape(): void {
		$first  = $this->store( self::warning() );
		$fp     = (string) $this->db->events[ $first ]['fingerprint'];
		$before = $this->db->events[ $first ];

		[ $logger, $issues ] = $this->request();
		$result              = $issues->resolve( $fp, 3, 1_800_000_000 );
		$logger->flush();

		self::assertTrue( $result['changed'] );
		self::assertSame( $first, $result['through_id'] );
		self::assertSame(
			[
				'issue_key'   => $fp,
				'fingerprint' => $fp,
				'event_id'    => null,
				'through_id'  => $first,
				'resolved_at' => 1_800_000_000,
				'resolved_by' => 3,
			],
			$this->db->resolutions[ $fp ]
		);
		$rows = $this->logged( 'logger.issue_resolved' );
		self::assertCount( 1, $rows );
		$row = $rows[0];
		self::assertSame( 'system', $row['category'] );
		self::assertSame( Severity::NOTICE->priority(), (int) $row['severity'] );
		self::assertSame( 'issue', $row['object_type'] );
		self::assertSame( $fp, $row['object_id'] );
		self::assertNull( $row['fingerprint'] );
		self::assertSame( '{"fingerprint":"' . $fp . '","event_id":null,"through_id":' . $first . '}', $row['context'], 'context keys survive the redactor unchanged' );
		self::assertSame( 0, $this->db->fingerprints[ $fp ]['window_start'], 'aggregation window closed' );
		self::assertSame( $before, $this->db->events[ $first ], 'events are never modified' );
	}

	public function test_resolve_is_idempotent_and_a_recurrence_reopens(): void {
		$first = $this->store( self::warning() );
		$fp    = (string) $this->db->events[ $first ]['fingerprint'];
		$hash  = $this->db->events[ $first ]['hash'];
		[ $logger, $issues ] = $this->request();
		$issues->resolve( $fp, 3 );
		$again = $issues->resolve( $fp, 4 );
		$logger->flush();
		self::assertFalse( $again['changed'] );
		self::assertSame( 3, $again['resolved_by'] );
		self::assertCount( 1, $this->logged( 'logger.issue_resolved' ), 'nothing logged when already resolved' );

		// The window was closed: the next occurrence is a new event above the resolution.
		$second = $this->store( self::warning() );
		self::assertGreaterThan( $first, $second );
		$resolution = Resolutions::shape( $this->db->resolutions[ $fp ] );
		self::assertTrue( Resolutions::covers( $resolution, $this->db->events[ $first ] ) );
		self::assertFalse( Resolutions::covers( $resolution, $this->db->events[ $second ] ), 'open again' );
		self::assertSame( $hash, $this->db->events[ $first ]['hash'] );

		[ $logger, $issues ] = $this->request();
		$third               = $issues->resolve( 'event:' . $first, 3 );
		$logger->flush();
		self::assertTrue( $third['changed'] );
		self::assertSame( $fp, $third['key'], 'an event with a fingerprint resolves its fingerprint' );
		self::assertSame( $second, $third['through_id'] );
		self::assertCount( 2, $this->logged( 'logger.issue_resolved' ) );
	}

	public function test_single_event_without_fingerprint(): void {
		$id = $this->store( Event::make( Category::MAIL, 'mail.failed', Severity::ERROR )->context( [ 'error_code' => 'x' ] ) );
		[ $logger, $issues ] = $this->request();
		$result              = $issues->resolve( 'event:' . $id, null );
		$logger->flush();
		self::assertSame( 'event:' . $id, $result['key'] );
		self::assertNull( $this->db->resolutions[ 'event:' . $id ]['resolved_by'] );
		$row = $this->logged( 'logger.issue_resolved' )[0];
		self::assertSame( 'event:' . $id, $row['object_id'] );
		self::assertSame( '{"fingerprint":null,"event_id":' . $id . ',"through_id":' . $id . '}', $row['context'] );

		[ $logger, $issues ] = $this->request();
		$reopened            = $issues->reopen( 'event:' . $id );
		$logger->flush();
		self::assertSame( 'event:' . $id, $reopened['key'] );
		self::assertSame( [], $this->db->resolutions );
		$row = $this->logged( 'logger.issue_reopened' )[0];
		self::assertSame( 'system', $row['category'] );
		self::assertSame( Severity::NOTICE->priority(), (int) $row['severity'] );
		self::assertSame( 'issue', $row['object_type'] );
		self::assertSame( '{"fingerprint":null,"event_id":' . $id . '}', $row['context'] );
	}

	public function test_reopen_fingerprint_context(): void {
		$first = $this->store( self::warning() );
		$fp    = (string) $this->db->events[ $first ]['fingerprint'];
		[ $logger, $issues ] = $this->request();
		$issues->resolve( $fp, 1 );
		$issues->reopen( 'event:' . $first );
		$logger->flush();
		self::assertSame( [], $this->db->resolutions );
		self::assertSame( '{"fingerprint":"' . $fp . '","event_id":null}', $this->logged( 'logger.issue_reopened' )[0]['context'] );
		self::assertSame( $fp, $this->logged( 'logger.issue_reopened' )[0]['object_id'] );
	}

	/**
	 * @return iterable<string, array{0: string, 1: string}>
	 */
	public static function refusals(): iterable {
		yield 'invalid' => [ 'resolve', 'nope' ];
		yield 'unknown fingerprint' => [ 'resolve', str_repeat( 'a', 32 ) ];
		yield 'unknown event' => [ 'resolve', 'event:999' ];
		yield 'notice event' => [ 'resolve', 'event:1' ];
		yield 'reopen unresolved' => [ 'reopen', 'event:1' ];
	}

	/**
	 * @dataProvider refusals
	 */
	public function test_refusals( string $op, string $reference ): void {
		$this->store( Event::make( Category::SYSTEM, 'logger.settings_changed', Severity::NOTICE ) );
		[ , $issues ] = $this->request();
		$this->expectException( \DomainException::class );
		'reopen' === $op ? $issues->reopen( $reference ) : $issues->resolve( $reference, 1 );
	}

	public function test_prune_drops_resolutions_whose_events_are_gone(): void {
		$first = $this->store( self::warning() );
		$fp    = (string) $this->db->events[ $first ]['fingerprint'];
		$other = $this->store( Event::make( Category::HTTP, 'http.request_failed', Severity::ERROR ) );
		[ $logger, $issues ] = $this->request();
		$issues->resolve( $fp, 1 );
		$issues->resolve( 'event:' . $other, 1 );
		$logger->flush();
		$meta = new Meta();
		( new EventRepository( $meta ) )->prune( 90, 0, 0, (int) $first, false, ( time() + 100 * 86400 ) * 1000 );
		self::assertArrayNotHasKey( $fp, $this->db->resolutions, 'every event of the fingerprint was pruned' );
		self::assertArrayHasKey( 'event:' . $other, $this->db->resolutions, 'still has its event' );
	}

	public function test_open_issues_query_is_scoped_capped_and_unresolved(): void {
		[ , $issues ]    = $this->request();
		$wpdb            = new \wpdb();
		$wpdb->results   = [
			[
				'fingerprint' => str_repeat( 'a', 32 ),
				'last_id'     => 5,
				'id'          => 5,
			],
			[
				'fingerprint' => str_repeat( 'b', 32 ),
				'last_id'     => 4,
				'id'          => 4,
			],
		];
		$GLOBALS['wpdb'] = $wpdb;
		$open            = $issues->openIssues( [ 'categories' => [ 'error' ] ], 3 );
		self::assertSame( [ str_repeat( 'a', 32 ), str_repeat( 'b', 32 ), 'event:5' ], $open['issues'] );
		self::assertTrue( $open['more'] );
		$unresolved = "NOT EXISTS (SELECT 1 FROM `wp_nflog_resolutions` r WHERE r.fingerprint = `wp_nflog_events`.fingerprint AND r.through_id >= `wp_nflog_events`.id) AND NOT EXISTS (SELECT 1 FROM `wp_nflog_resolutions` r WHERE r.event_id = `wp_nflog_events`.id)";
		self::assertSame(
			[
				"SELECT fingerprint, MAX(id) AS last_id FROM `wp_nflog_events` WHERE category IN ('error') AND severity <= 2 AND {$unresolved} AND fingerprint IS NOT NULL GROUP BY fingerprint ORDER BY last_id DESC LIMIT 4",
				"SELECT id FROM `wp_nflog_events` WHERE category IN ('error') AND severity <= 2 AND {$unresolved} AND fingerprint IS NULL ORDER BY id DESC LIMIT 2",
			],
			$wpdb->ran
		);
	}
}
