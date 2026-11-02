<?php
/**
 * Db::run() restores the site's last error and last query.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Storage;

use Noformat\Logger\Storage\Db;
use Noformat\Logger\Tests\TestCase;

if ( ! class_exists( \wpdb::class, false ) ) {
	require __DIR__ . '/FakeWpdb.php';
}

final class DbTest extends TestCase {

	private mixed $previous = null;

	protected function setUp(): void {
		parent::setUp();
		$this->previous = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous;
		parent::tearDown();
	}

	public function test_restores_last_error_and_last_query(): void {
		$wpdb             = new \wpdb( '', '', '', '' );
		$wpdb->last_error = "Unknown column 'x'";
		$wpdb->last_query = 'SELECT x FROM wp_posts';
		$GLOBALS['wpdb']  = $wpdb;

		Db::query( 'UPDATE wp_nflog_meta SET v = 1' );

		self::assertSame( "Unknown column 'x'", $wpdb->last_error );
		self::assertSame( 'SELECT x FROM wp_posts', $wpdb->last_query );
		self::assertSame( [ 'UPDATE wp_nflog_meta SET v = 1' ], $wpdb->ran );
	}

	public function test_failure_throws_and_still_restores(): void {
		$wpdb             = new \wpdb( '', '', '', '' );
		$wpdb->last_error = '';
		$wpdb->last_query = 'SELECT 1';
		$wpdb->failWith   = 'Table missing';
		$GLOBALS['wpdb']  = $wpdb;

		try {
			Db::query( 'INSERT INTO wp_nflog_events VALUES (1)' );
			self::fail( 'expected an exception' );
		} catch ( \RuntimeException $e ) {
			self::assertStringContainsString( 'Table missing', $e->getMessage() );
		}
		self::assertSame( '', $wpdb->last_error );
		self::assertSame( 'SELECT 1', $wpdb->last_query );
	}
}
