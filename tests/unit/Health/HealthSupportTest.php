<?php
/**
 * Cron overdue and pending update tracking tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Health;

use Noformat\Logger\Health\HealthReport;
use Noformat\Logger\Health\Support\CronInspector;
use Noformat\Logger\Health\Support\UpdatesTracker;
use Noformat\Logger\Tests\TestCase;

final class HealthSupportTest extends TestCase {

	public function test_overdue_computation(): void {
		$now  = 1_000_000;
		$cron = [
			$now - 30    => [ 'fresh' => [ 'k' => [ 'schedule' => 'hourly', 'interval' => 3600 ] ] ],
			$now - 5000  => [ 'hourly_late' => [ 'k' => [ 'schedule' => 'hourly', 'interval' => 3600 ] ] ],
			$now - 8000  => [ 'hourly_very_late' => [ 'k' => [ 'schedule' => 'hourly', 'interval' => 3600 ] ] ],
			$now - 90000 => [ 'single' => [ 'k' => [ 'schedule' => false ] ] ],
			$now + 100   => [ 'future' => [ 'k' => [ 'schedule' => 'daily', 'interval' => 86400 ] ] ],
			'version'    => 2,
		];
		$overdue = CronInspector::overdue( $cron, $now );
		self::assertSame( [ 'single', 'hourly_very_late', 'hourly_late' ], array_column( $overdue, 'hook' ) );
		$byHook = array_column( $overdue, null, 'hook' );
		self::assertTrue( $byHook['hourly_very_late']['alert'], '> 2x interval' );
		self::assertFalse( $byHook['hourly_late']['alert'], '< 2x interval' );
		self::assertFalse( $byHook['single']['alert'], 'one-off events never alert' );
		self::assertSame( 8000, $byHook['hourly_very_late']['overdue_seconds'] );
		$upcoming = CronInspector::upcoming( $cron, $now );
		self::assertSame( 'future', $upcoming[0]['hook'] );
		self::assertSame( 100, $upcoming[0]['in_seconds'] );

		$event = HealthReport::cronOverdueEvent( $byHook['hourly_very_late'] );
		self::assertSame( 'cron.overdue', $event->getAction() );
		self::assertSame( 'warning', $event->getSeverity()->value );
	}

	public function test_pending_updates_and_staleness(): void {
		$core    = (object) [
			'updates' => [
				(object) [
					'response' => 'upgrade',
					'current'  => '6.9.1',
				],
			],
		];
		$plugins = (object) [
			'response' => [
				'akismet/akismet.php' => (object) [
					'slug'        => 'akismet',
					'new_version' => '5.4',
				],
			],
		];
		$themes  = (object) [ 'response' => [ 'twentytwentyfour' => [ 'new_version' => '1.4' ] ] ];
		$pending = UpdatesTracker::pending( $core, $plugins, $themes );
		self::assertSame( [ 'core:wordpress@6.9.1', 'plugin:akismet@5.4', 'theme:twentytwentyfour@1.4' ], array_map( [ UpdatesTracker::class, 'key' ], $pending ) );

		$now   = 2_000_000;
		$first = UpdatesTracker::firstSeen( $pending, [ 'plugin:akismet@5.4' => $now - 8 * 86400, 'plugin:gone@1' => 1 ], $now );
		self::assertArrayNotHasKey( 'plugin:gone@1', $first );
		self::assertSame( $now, $first['core:wordpress@6.9.1'] );
		$items = UpdatesTracker::annotate( $pending, $first, $now );
		self::assertTrue( $items[1]['stale'] );
		self::assertSame( 8, $items[1]['pending_days'] );
		self::assertFalse( $items[0]['stale'] );

		$digest = UpdatesTracker::digest( $items );
		self::assertSame( $digest, UpdatesTracker::digest( array_reverse( $items ) ), 'order independent' );
		$fresh = UpdatesTracker::annotate( $pending, UpdatesTracker::firstSeen( $pending, [], $now ), $now );
		self::assertNotSame( $digest, UpdatesTracker::digest( $fresh ), 'staleness changes the state' );
		self::assertSame( '', UpdatesTracker::digest( [] ) );

		$event = HealthReport::updatesEvent( $items );
		self::assertSame( 'updates.pending', $event->getAction() );
		self::assertSame( 'warning', $event->getSeverity()->value );
		self::assertSame( 'notice', HealthReport::updatesEvent( $fresh )->getSeverity()->value );
		self::assertSame( 1, $event->getContext()['plugins'] );
	}

	public function test_plugin_slug(): void {
		self::assertSame( 'akismet', HealthReport::pluginSlug( 'akismet/akismet.php' ) );
		self::assertSame( 'hello', HealthReport::pluginSlug( 'hello.php' ) );
	}
}
