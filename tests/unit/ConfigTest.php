<?php
/**
 * Config tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests;

use Brain\Monkey\Functions;
use Noformat\Logger\Config;

final class ConfigTest extends TestCase {

	public function test_defaults(): void {
		$config = new Config();
		self::assertSame( 90, $config->int( 'retention_days' ) );
		self::assertSame( 180, $config->int( 'hard_max_days' ) );
		self::assertSame( 500000, $config->int( 'max_rows' ) );
		self::assertFalse( $config->bool( 'log_debug' ) );
		self::assertFalse( $config->bool( 'full_content_diff' ) );
		self::assertSame( 120, $config->int( 'rate_limit_per_minute' ) );
		self::assertSame( [], $config->ipAllowlist() );
		self::assertContains( 'email', $config->sensitiveKeys() );
		self::assertTrue( $config->isEnabled( 'Noformat\\Logger\\Listeners\\PostListener' ) );
	}

	public function test_constants_beat_options_beat_defaults(): void {
		$config = new Config(
			[
				'retention_days' => '30',
				'log_debug'      => '1',
				'max_rows'       => 1000,
			],
			[ 'retention_days' => 7 ]
		);
		self::assertSame( 7, $config->int( 'retention_days' ), 'constant wins' );
		self::assertTrue( $config->bool( 'log_debug' ), 'option wins over default' );
		self::assertSame( 1000, $config->int( 'max_rows' ) );
		self::assertSame( 180, $config->int( 'hard_max_days' ), 'default' );
		self::assertTrue( $config->isPinned( 'retention_days' ) );
		self::assertFalse( $config->isPinned( 'log_debug' ) );
	}

	public function test_from_environment_reads_option_and_filter(): void {
		Functions\when( 'get_option' )->justReturn( [ 'retention_days' => 15, 'unknown' => 1 ] );
		$config = Config::fromEnvironment();
		self::assertSame( 15, $config->int( 'retention_days' ) );
		self::assertNull( $config->get( 'unknown' ) );
	}

	public function test_module_toggles(): void {
		$config = new Config( [ 'modules' => [ 'AcfListener' => false, 'PostListener' => true ] ], [ 'disabled_modules' => 'MailListener, PostListener' ] );
		self::assertFalse( $config->isEnabled( 'Noformat\\Logger\\Listeners\\AcfListener' ) );
		self::assertFalse( $config->isEnabled( 'PostListener' ), 'constant disables over option' );
		self::assertFalse( $config->isEnabled( 'MailListener' ) );
		self::assertTrue( $config->isEnabled( 'UserListener' ) );
		self::assertTrue( $config->isPinned( 'module:MailListener' ) );
	}

	public function test_ignore_lists(): void {
		$config = new Config();
		self::assertTrue( $config->isIgnoredOption( '_transient_feed_123' ) );
		self::assertTrue( $config->isIgnoredOption( 'cron' ) );
		self::assertTrue( $config->isIgnoredOption( 'update_core_last_checked' ) );
		self::assertFalse( $config->isIgnoredOption( 'blogname' ) );
		self::assertTrue( $config->isIgnoredMetaKey( '_edit_lock' ) );
		self::assertTrue( $config->isIgnoredMetaKey( '_oembed_abc' ) );
		self::assertTrue( $config->isIgnoredMetaKey( 'wp_capabilities' ) );
		self::assertFalse( $config->isIgnoredMetaKey( '_thumbnail_id' ) );
		self::assertTrue( $config->isIgnoredMetaKey( 'nflog_known_clients' ), 'own bookkeeping meta' );
	}

	public function test_ip_allowlist_from_string(): void {
		$config = new Config( [ 'ip_allowlist' => "10.0.0.1\n10.0.0.0/8, ::1" ] );
		self::assertSame( [ '10.0.0.1', '10.0.0.0/8', '::1' ], $config->ipAllowlist() );
	}
}
