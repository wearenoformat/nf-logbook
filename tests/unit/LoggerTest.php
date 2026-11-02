<?php
/**
 * Logger tests (buffering, redaction, guards; no database).
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Noformat\Logger\Category;
use Noformat\Logger\Config;
use Noformat\Logger\Context\RequestContext;
use Noformat\Logger\Event;
use Noformat\Logger\Logger;
use Noformat\Logger\Privacy\Redactor;
use Noformat\Logger\Severity;
use Noformat\Logger\Storage\EventRepository;
use Noformat\Logger\Storage\FingerprintStore;
use Noformat\Logger\Storage\Meta;

final class LoggerTest extends TestCase {

	/**
	 * Logger without a database.
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	private function logger( array $settings = [] ): Logger {
		$config = new Config( $settings );
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

	public function test_redacts_before_buffering(): void {
		$event = Event::make( Category::USER, 'user.updated' )
			->change( 'user_email', 'a@b.c', 'd@e.f' )
			->change( 'role', 'editor', 'administrator' )
			->context( [ 'billing' => [ 'phone' => '123' ], 'ratio' => 1.5 ] );
		self::assertTrue( $this->logger()->log( $event ) );
		self::assertTrue( $event->getChanges()[0]['old']['redacted'] );
		self::assertSame( 'administrator', $event->getChanges()[1]['new'] );
		self::assertTrue( $event->getContext()['billing']['redacted'] );
		self::assertSame( '1.5', $event->getContext()['ratio'], 'floats become strings' );
		self::assertNotNull( $event->getRequest() );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', (string) $event->getRequest()['id'] );
	}

	public function test_drops_debug_unless_enabled(): void {
		self::assertFalse( $this->logger()->log( Event::make( Category::SYSTEM, 'x', Severity::DEBUG ) ) );
		$logger = $this->logger( [ 'log_debug' => true ] );
		self::assertTrue( $logger->log( Event::make( Category::SYSTEM, 'x', Severity::DEBUG ) ) );
		self::assertSame( 1, $logger->pending() );
	}

	public function test_filter_can_drop(): void {
		Filters\expectApplied( 'noformat_logger/event' )->once()->andReturn( null );
		$logger = $this->logger();
		self::assertFalse( $logger->log( Event::make( Category::SYSTEM, 'x' ) ) );
		self::assertSame( 0, $logger->pending() );
	}

	public function test_aggregates_same_fingerprint_in_request(): void {
		$logger = $this->logger();
		$first  = Event::make( Category::ERROR, 'php.warning', Severity::ERROR )->fingerprint( 'a' );
		$logger->log( $first );
		$logger->log( Event::make( Category::ERROR, 'php.warning', Severity::ERROR )->fingerprint( 'a' ) );
		$logger->log( Event::make( Category::ERROR, 'php.warning', Severity::ERROR )->fingerprint( 'b' ) );
		self::assertSame( 2, $logger->pending() );
		self::assertSame( 2, $first->getCount() );
	}

	public function test_full_buffer_without_storage_is_bounded(): void {
		$logger = $this->logger( [ 'buffer_max' => 3 ] );
		$results = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$results[] = $logger->log( Event::make( Category::SYSTEM, 'x' ) );
		}
		// The 4th event tries to write the full buffer; storage is missing, so it is dropped (overflow).
		self::assertSame( [ true, true, true, false, true ], $results );
		self::assertTrue( $logger->isDisabled() );
		self::assertLessThanOrEqual( 3, $logger->pending() );
	}

	public function test_context_is_capped(): void {
		$logger = $this->logger();
		$event  = Event::make( Category::SYSTEM, 'x' )->context( array_fill( 0, 100, str_repeat( 'x', 1000 ) ) );
		$logger->log( $event );
		self::assertTrue( $event->getContext()['truncated'] );
		self::assertGreaterThan( Logger::MAX_CONTEXT_BYTES, $event->getContext()['length'] );
	}

	public function test_flush_without_storage_never_throws_and_counts_failure(): void {
		$stored = 0;
		Functions\when( 'get_option' )->alias( static fn() => $stored );
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) use ( &$stored ) {
				$stored = (int) $value;
				return true;
			}
		);
		$logger = $this->logger();
		$logger->log( Event::make( Category::SYSTEM, 'x' ) );
		self::assertSame( [], $logger->flush() );
		self::assertTrue( $logger->isDisabled() );
		self::assertSame( 0, $logger->pending() );
		self::assertSame( 1, $stored, 'failure persisted to the fallback option when the meta table is missing' );
		// Subsequent logging is a no-op but still never throws.
		self::assertTrue( $logger->log( Event::make( Category::SYSTEM, 'y' ) ) );
		self::assertSame( [], $logger->flush() );
	}

	public function test_guard_swallows_exceptions(): void {
		$result = $this->logger()->guard(
			'test',
			static function (): void {
				throw new \RuntimeException( 'boom' );
			},
			'fallback'
		);
		self::assertSame( 'fallback', $result );
	}
}
