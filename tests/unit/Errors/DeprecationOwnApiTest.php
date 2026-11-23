<?php
/**
 * Deprecation notices during the Controlroom's own signed pulls.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Errors;

use Brain\Monkey\Functions;
use Noformat\Logger\Config;
use Noformat\Logger\Context\RequestContext;
use Noformat\Logger\Errors\DeprecationListener;
use Noformat\Logger\Logger;
use Noformat\Logger\Privacy\Redactor;
use Noformat\Logger\Storage\EventRepository;
use Noformat\Logger\Storage\FingerprintStore;
use Noformat\Logger\Storage\Meta;
use Noformat\Logger\Tests\TestCase;

final class DeprecationOwnApiTest extends TestCase {

	private const SIGNED = [
		'HTTP_X_NF_KEY'       => 'nfk_7Hs9Qk2LmP4xT8vZ',
		'HTTP_X_NF_TIMESTAMP' => '1',
		'HTTP_X_NF_NONCE'     => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
		'HTTP_X_NF_SIGNATURE' => 'v1=0000000000000000000000000000000000000000000000000000000000000000',
	];

	/** @var array<string, mixed> */
	private array $previousServer = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_key' )->alias( static fn( $key ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ) );
		$this->previousServer = $_SERVER;
	}

	protected function tearDown(): void {
		$_SERVER = $this->previousServer;
		DeprecationListener::consumeTrigger( E_USER_DEPRECATED, 'wp-includes/functions.php' );
		parent::tearDown();
	}

	/**
	 * Fire one deprecated_function_run in a request with these server vars; returns events buffered.
	 *
	 * @param array<string, string> $server Server vars.
	 */
	private function deprecationIn( array $server ): int {
		$_SERVER = $server + [ 'REQUEST_METHOD' => 'GET' ];
		$config  = new Config();
		$meta    = new Meta();
		$logger  = new Logger(
			$config,
			new RequestContext( null, 'test-salt' ),
			new Redactor( $config->sensitiveKeys(), $config->safeKeys(), 'test-salt' ),
			new EventRepository( $meta ),
			new FingerprintStore( $config ),
			$meta
		);
		$listener = new DeprecationListener();
		$listener->register( $logger, $config );
		$listener->onFunction( 'WP\\MCP\\Core\\McpAdapter', '', '0.7.0' );
		return $logger->pending();
	}

	public function test_skipped_during_a_signed_pull(): void {
		self::assertSame( 0, $this->deprecationIn( self::SIGNED + [ 'REQUEST_URI' => '/wp-json/noformat-logger/v1/events?after=10' ] ) );
		self::assertTrue( DeprecationListener::consumeTrigger( E_USER_DEPRECATED, 'wp-includes/functions.php' ), 'the wp_trigger_error follow-up is still swallowed' );
	}

	public function test_still_logged_elsewhere(): void {
		self::assertSame( 1, $this->deprecationIn( [ 'REQUEST_URI' => '/wp-json/oembed/1.0/embed' ] ), 'other REST traffic' );
		self::assertSame( 1, $this->deprecationIn( [ 'REQUEST_URI' => '/wp-json/noformat-logger/v1/events' ] ), 'unsigned request to the namespace' );
		self::assertSame( 1, $this->deprecationIn( self::SIGNED + [ 'REQUEST_URI' => '/wp-json/wp/v2/posts' ] ), 'signing headers on another namespace' );
	}
}
