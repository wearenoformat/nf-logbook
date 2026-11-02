<?php
/**
 * FingerprintStore tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests;

use Noformat\Logger\Config;
use Noformat\Logger\Severity;
use Noformat\Logger\Storage\FingerprintStore;

final class FingerprintStoreTest extends TestCase {

	public function test_windows(): void {
		$store = new FingerprintStore( new Config() );
		self::assertSame( 60, $store->window( Severity::ERROR ) );
		self::assertSame( 3600, $store->window( Severity::DEBUG ) );
	}

	public function test_should_aggregate_within_window_only(): void {
		$store = new FingerprintStore( new Config() );
		$row   = [
			'last_event_id' => 10,
			'window_start'  => 1000,
		];
		self::assertFalse( $store->shouldAggregate( null, 1000, Severity::ERROR ), 'no row' );
		self::assertTrue( $store->shouldAggregate( $row, 1000, Severity::ERROR ), 'same second' );
		self::assertTrue( $store->shouldAggregate( $row, 1059, Severity::ERROR ), 'inside 60 s' );
		self::assertFalse( $store->shouldAggregate( $row, 1060, Severity::ERROR ), 'window closed at 60 s' );
		self::assertTrue( $store->shouldAggregate( $row, 4599, Severity::DEBUG ), 'debug window 3600 s' );
		self::assertFalse( $store->shouldAggregate( $row, 4600, Severity::DEBUG ) );
		self::assertFalse( $store->shouldAggregate( $row, 999, Severity::ERROR ), 'clock went backwards' );
		self::assertFalse(
			$store->shouldAggregate(
				[
					'last_event_id' => 0,
					'window_start'  => 1000,
				],
				1001,
				Severity::ERROR
			),
			'row without stored event'
		);
	}

	public function test_window_is_configurable(): void {
		$store = new FingerprintStore( new Config( [ 'fingerprint_window' => 5 ] ) );
		self::assertSame( 5, $store->window( Severity::WARNING ) );
	}

	public function test_per_action_windows(): void {
		$store = new FingerprintStore( new Config() );
		self::assertSame( 3600, $store->window( Severity::WARNING, 'probe.suspicious_404' ) );
		self::assertSame( 900, $store->window( Severity::WARNING, 'rest.users_probe' ) );
		self::assertSame( 900, $store->window( Severity::WARNING, 'xmlrpc.call' ) );
		self::assertSame( 60, $store->window( Severity::WARNING, 'auth.login_failed' ), 'other warnings keep 60 s' );
		$row = [
			'last_event_id' => 10,
			'window_start'  => 1000,
		];
		self::assertTrue( $store->shouldAggregate( $row, 4599, Severity::WARNING, 'probe.suspicious_404' ) );
		self::assertFalse( $store->shouldAggregate( $row, 4600, Severity::WARNING, 'probe.suspicious_404' ) );
		self::assertFalse( $store->shouldAggregate( $row, 1060, Severity::WARNING, 'auth.login_failed' ) );

		$custom = new FingerprintStore( new Config( [ 'fingerprint_windows' => [ 'php.warning' => 300 ] ] ) );
		self::assertSame( 300, $custom->window( Severity::ERROR, 'php.warning' ) );
		self::assertSame( 60, $custom->window( Severity::WARNING, 'probe.suspicious_404' ), 'setting replaces the default map' );
	}

	public function test_window_filter_receives_severity_and_action(): void {
		\Brain\Monkey\Filters\expectApplied( 'noformat_logger/fingerprint_window' )
			->once()
			->with( 3600, Severity::WARNING, 'probe.suspicious_404' )
			->andReturn( 7200 );
		$store = new FingerprintStore( new Config() );
		self::assertSame( 7200, $store->window( Severity::WARNING, 'probe.suspicious_404' ) );
	}
}
