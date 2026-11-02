<?php
/**
 * Burst detection and failed-login recording.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\System;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Noformat\Logger\Config;
use Noformat\Logger\Context\RequestContext;
use Noformat\Logger\Event;
use Noformat\Logger\Logger;
use Noformat\Logger\Privacy\Redactor;
use Noformat\Logger\Security\Support\BurstDetector;
use Noformat\Logger\Security\Support\BurstStore;
use Noformat\Logger\Security\Support\FailedLogin;
use Noformat\Logger\Security\Support\KnownClients;
use Noformat\Logger\Severity;
use Noformat\Logger\Storage\EventRepository;
use Noformat\Logger\Storage\FingerprintStore;
use Noformat\Logger\Storage\Meta;
use Noformat\Logger\Tests\TestCase;

final class BurstDetectorTest extends TestCase {

	private BurstStore $store;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new class() implements BurstStore {
			/** @var array<string, array<string, mixed>> */
			public array $data = [];
			public function get( string $key ): ?array {
				return $this->data[ $key ] ?? null;
			}
			public function set( string $key, array $value, int $ttl ): void {
				$this->data[ $key ] = $value;
			}
		};
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	public function test_burst_reported_once_per_window(): void {
		$detector = new BurstDetector( $this->store );
		$t        = 1_000_000;
		for ( $i = 1; $i <= 9; $i++ ) {
			self::assertNull( $detector->hit( 'ip', $t + $i ), "hit $i" );
		}
		self::assertSame( 10, $detector->hit( 'ip', $t + 10 ) );
		self::assertNull( $detector->hit( 'ip', $t + 11 ), 'same window: no second burst' );
		self::assertNull( $detector->hit( 'other', $t + 11 ), 'keys are independent' );
		// 5 minutes after the report, the window still holds >= 10 recent hits only if they keep coming.
		for ( $i = 0; $i < 9; $i++ ) {
			self::assertNull( $detector->hit( 'ip', $t + 320 + $i ) );
		}
		self::assertSame( 10, $detector->hit( 'ip', $t + 329 ), 'next window: reported again' );
	}

	public function test_window_is_rolling(): void {
		$detector = new BurstDetector( $this->store, 3, 60 );
		self::assertNull( $detector->hit( 'k', 100 ) );
		self::assertNull( $detector->hit( 'k', 150 ) );
		self::assertNull( $detector->hit( 'k', 170 ), 'the hit at 100 left the window' );
		self::assertSame( 3, $detector->hit( 'k', 180 ) );
	}

	public function test_known_clients(): void {
		$a = str_repeat( 'a', 32 );
		$b = str_repeat( 'b', 32 );
		$r = KnownClients::remember( null, $a );
		self::assertTrue( $r['new'] );
		self::assertSame( [ $a ], $r['list'] );
		$r = KnownClients::remember( [ $b, $a, 'garbage' ], $a );
		self::assertFalse( $r['new'] );
		self::assertSame( [ $a, $b ], $r['list'] );
		$many = [];
		for ( $i = 0; $i < 12; $i++ ) {
			$many[] = str_pad( dechex( $i ), 32, '0', STR_PAD_LEFT );
		}
		self::assertCount( KnownClients::MAX, KnownClients::remember( $many, $b )['list'] );
	}

	public function test_failed_login_events_and_burst(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'get_user_by' )->justReturn( false );
		Functions\when( 'is_email' )->justReturn( false );
		$events = [];
		Filters\expectApplied( 'noformat_logger/event' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( Event $event ) use ( &$events ): Event {
				$events[] = $event;
				return $event;
			}
		);
		$failed = new FailedLogin( self::logger(), new BurstDetector( $this->store ) );
		for ( $i = 0; $i < 12; $i++ ) {
			$failed->record( 'Victim.Admin', 'invalid_username', 5000 + $i );
		}
		$fails  = array_values( array_filter( $events, static fn( Event $e ): bool => 'auth.login_failed' === $e->getAction() ) );
		$bursts = array_values( array_filter( $events, static fn( Event $e ): bool => 'auth.login_burst' === $e->getAction() ) );
		self::assertCount( 12, $fails );
		self::assertCount( 1, $bursts );
		self::assertSame( Severity::ERROR, $bursts[0]->getSeverity() );
		self::assertSame( 10, $bursts[0]->getContext()['failures'] );
		self::assertSame( Severity::WARNING, $fails[0]->getSeverity() );
		self::assertFalse( $fails[0]->getContext()['user_exists'] );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', (string) $fails[0]->getContext()['username_hash'] );
		self::assertSame( $fails[0]->getFingerprint(), $fails[11]->getFingerprint(), 'same username + ip aggregate' );
		$json = (string) json_encode( array_map( static fn( Event $e ): array => $e->toArray(), $events ) );
		self::assertStringNotContainsStringIgnoringCase( 'victim', $json );
		self::assertStringNotContainsString( '203.0.113.9', $json );
	}

	public function test_failed_login_resolves_existing_user_by_email(): void {
		self::stubUserClass();
		$user     = new \WP_User();
		$user->ID = 12;
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'is_email' )->justReturn( true );
		Functions\expect( 'get_user_by' )->twice()->andReturnUsing(
			static fn( string $field ) => 'email' === $field ? $user : false
		);
		$events = [];
		Filters\expectApplied( 'noformat_logger/event' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( Event $event ) use ( &$events ): Event {
				$events[] = $event;
				return $event;
			}
		);
		( new FailedLogin( self::logger(), new BurstDetector( $this->store ) ) )->record( 'jane@example.com', 'incorrect_password' );
		self::assertCount( 1, $events );
		self::assertTrue( $events[0]->getContext()['user_exists'] );
		self::assertSame( 12, $events[0]->getContext()['user_id'] );
		self::assertSame( '12', $events[0]->getObject()['id'] ?? null );
		self::assertStringNotContainsString( 'jane', (string) json_encode( $events[0]->toArray() ) );
	}

	/**
	 * Logger without a database.
	 */
	public static function logger(): Logger {
		$config = new Config();
		$meta   = new Meta();
		return new Logger(
			$config,
			new RequestContext( null, 'test-salt' ),
			new Redactor( $config->sensitiveKeys(), $config->safeKeys(), 'test-salt' ),
			new EventRepository( $meta ),
			new FingerprintStore( $config ),
			$meta
		);
	}

	/**
	 * Minimal WP_User for instanceof checks.
	 */
	public static function stubUserClass(): void {
		if ( ! class_exists( 'WP_User', false ) ) {
			eval( 'class WP_User { public $ID = 0; public $roles = []; public $data; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
	}
}
