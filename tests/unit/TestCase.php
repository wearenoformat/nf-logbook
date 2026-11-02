<?php
/**
 * Base test case.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

abstract class TestCase extends \PHPUnit\Framework\TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $value, $flags = 0, $depth = 512 ) => json_encode( $value, $flags, $depth )
		);
		Functions\when( 'wp_unslash' )->returnArg();
		// Functions the plugin guards with function_exists(): once any test stubs one it exists
		// for the rest of the run, so every test gets the same neutral defaults.
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'did_action' )->justReturn( 0 );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_is_json_request' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'home_url' )->justReturn( 'https://example.test' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( (string) $url, $component ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Load a shared fixture.
	 *
	 * @return array<string, mixed>
	 */
	protected static function fixture( string $name ): array {
		$json = file_get_contents( dirname( __DIR__ ) . '/fixtures/' . $name );
		self::assertIsString( $json );
		$data = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		self::assertIsArray( $data );
		return $data;
	}
}
