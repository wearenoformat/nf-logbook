<?php
/**
 * EventRepository::countsBySeverity().
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Storage;

use Noformat\Logger\Storage\EventRepository;
use Noformat\Logger\Storage\Meta;
use Noformat\Logger\Tests\TestCase;

if ( ! class_exists( \wpdb::class, false ) ) {
	require __DIR__ . '/FakeWpdb.php';
}

final class EventRepositoryCountsTest extends TestCase {

	private mixed $previous = null;

	protected function setUp(): void {
		parent::setUp();
		$this->previous = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous;
		parent::tearDown();
	}

	public function test_counts_by_severity_fills_every_severity(): void {
		$wpdb            = new \wpdb();
		$wpdb->results   = [
			[
				'severity'    => '0',
				'events'      => '2',
				'occurrences' => '5',
			],
			[
				'severity'    => '4',
				'events'      => '10',
				'occurrences' => '10',
			],
		];
		$GLOBALS['wpdb'] = $wpdb;

		$counts = ( new EventRepository( new Meta() ) )->countsBySeverity( 1_700_000_000_000, [] );

		self::assertSame( [ 'critical', 'error', 'warning', 'notice', 'info', 'debug' ], array_keys( $counts ) );
		self::assertSame(
			[
				'events'      => 2,
				'occurrences' => 5,
			],
			$counts['critical']
		);
		self::assertSame( 10, $counts['info']['events'] );
		self::assertSame( 0, $counts['warning']['events'] );
		self::assertCount( 1, $wpdb->ran, 'one grouped query' );
		self::assertStringContainsString( 'occurred_ms >= 1700000000000', $wpdb->ran[0] );
		self::assertStringContainsString( 'GROUP BY severity', $wpdb->ran[0] );

		$open = ( new EventRepository( new Meta() ) )->countsBySeverity(
			1_700_000_000_000,
			[
				[
					'severity'    => 4,
					'category'    => 'content',
					'fingerprint' => 'f',
					'delta'       => 7,
				],
			]
		);
		self::assertSame( 17, $open['info']['occurrences'], 'occurrences still on open fingerprint rows are added' );
		self::assertSame( 10, $open['info']['events'] );
	}
}
