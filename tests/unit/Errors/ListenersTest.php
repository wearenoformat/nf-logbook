<?php
/**
 * Deprecation, HTTP and mail event building tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Errors;

use Brain\Monkey\Functions;
use Noformat\Logger\Errors\DeprecationListener;
use Noformat\Logger\Errors\HttpApiListener;
use Noformat\Logger\Errors\MailListener;
use Noformat\Logger\Errors\Support\PathMapper;
use Noformat\Logger\Tests\TestCase;

final class ListenersTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $text ) => strip_tags( (string) $text ) );
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( (string) $url, $component ) );
	}

	private function mapper(): PathMapper {
		return new PathMapper( [ '/srv/www/' => '' ], '/srv/www/wp-content/plugins/noformat-logger/src' );
	}

	public function test_deprecation_caller_skips_core_and_logger(): void {
		$frames = [
			[ 'file' => '/srv/www/wp-content/plugins/noformat-logger/src/Errors/DeprecationListener.php', 'line' => 1 ],
			[ 'file' => '/srv/www/wp-includes/class-wp-hook.php', 'line' => 324 ],
			[ 'file' => '/srv/www/wp-includes/functions.php', 'line' => 5500 ],
			[ 'file' => '/srv/www/wp-includes/deprecated.php', 'line' => 10 ],
			[ 'file' => '/srv/www/wp-content/themes/old/functions.php', 'line' => 77 ],
			[ 'file' => '/srv/www/wp-settings.php', 'line' => 600 ],
		];
		$caller = DeprecationListener::findCaller( $frames, $this->mapper() );
		self::assertSame(
			[
				'file' => 'wp-content/themes/old/functions.php',
				'line' => 77,
			],
			$caller
		);
		$event = DeprecationListener::buildEvent( 'deprecated.function', 'function', 'get_page_by_title', 'WP_Query', '6.2.0', '', $caller, $this->mapper() );
		self::assertSame( 'deprecated.function', $event->getAction() );
		self::assertSame( 'warning', $event->getSeverity()->value );
		self::assertSame( 'theme:old', $event->getContext()['component'] );
		self::assertSame( 'get_page_by_title', $event->getContext()['function'] );
		self::assertSame( 'WP_Query', $event->getContext()['replacement'] );
		$again = DeprecationListener::buildEvent( 'deprecated.function', 'function', 'get_page_by_title', '', '6.2.0', '', null, $this->mapper() );
		self::assertSame( $event->getFingerprint(), $again->getFingerprint(), 'fingerprint per (action, name)' );
		self::assertNull( DeprecationListener::findCaller( array_slice( $frames, 0, 4 ), $this->mapper() ) );
	}

	public function test_doing_it_wrong_message_is_plain_and_relative(): void {
		$event = DeprecationListener::buildEvent( 'doing_it_wrong', 'function', 'wp_enqueue_script', '', '3.3.0', 'Scripts must be <code>enqueued</code> in /srv/www/wp-content/plugins/x/x.php.', null, $this->mapper() );
		self::assertSame( 'Scripts must be enqueued in wp-content/plugins/x/x.php.', $event->getContext()['message'] );
	}

	public function test_textdomain_just_in_time_is_info_other_doing_it_wrong_warning(): void {
		$jit = DeprecationListener::buildEvent( 'doing_it_wrong', 'function', '_load_textdomain_just_in_time', '', '6.7.0', 'Translation loading for the <code>x</code> domain was triggered too early.', null, $this->mapper() );
		self::assertSame( 'info', $jit->getSeverity()->value );
		self::assertSame( 'doing_it_wrong', $jit->getAction() );
		self::assertNotNull( $jit->getFingerprint(), 'still aggregated by fingerprint' );
		$again = DeprecationListener::buildEvent( 'doing_it_wrong', 'function', '_load_textdomain_just_in_time', '', '6.7.0', 'other domain', null, $this->mapper() );
		self::assertSame( $jit->getFingerprint(), $again->getFingerprint() );
		$other = DeprecationListener::buildEvent( 'doing_it_wrong', 'function', 'wp_enqueue_script', '', '3.3.0', '', null, $this->mapper() );
		self::assertSame( 'warning', $other->getSeverity()->value );
		$deprecated = DeprecationListener::buildEvent( 'deprecated.function', 'function', '_load_textdomain_just_in_time', '', '6.7.0', '', null, $this->mapper() );
		self::assertSame( 'warning', $deprecated->getSeverity()->value, 'only doing_it_wrong is downgraded' );
	}

	public function test_consume_trigger_only_once_and_only_from_functions_php(): void {
		$listener = new DeprecationListener();
		$property = new \ReflectionProperty( DeprecationListener::class, 'triggerPending' );
		$property->setValue( null, true );
		self::assertFalse( DeprecationListener::consumeTrigger( E_USER_DEPRECATED, 'wp-content/plugins/x/x.php' ) );
		self::assertFalse( DeprecationListener::consumeTrigger( E_WARNING, 'wp-includes/functions.php' ) );
		self::assertTrue( DeprecationListener::consumeTrigger( E_USER_DEPRECATED, 'wp-includes/functions.php' ) );
		self::assertFalse( DeprecationListener::consumeTrigger( E_USER_DEPRECATED, 'wp-includes/functions.php' ) );
		unset( $listener );
	}

	public function test_http_failure_event(): void {
		$hash  = static fn( string $ip ): string => 'abcd1234';
		$event = HttpApiListener::buildEvent( 'http://127.0.0.1:9/api/v1/ping?token=secret', 'get', 0, 'http_request_failed', 'cURL error 7: Failed to connect to 127.0.0.1 port 9', 12, $hash );
		self::assertNotNull( $event );
		$ctx = $event->getContext();
		self::assertSame( 'http.request_failed', $event->getAction() );
		self::assertSame( 'error', $event->getSeverity()->value );
		self::assertSame( 'ip-abcd1234', $ctx['host'] );
		self::assertSame( 'loopback', $ctx['ip_scope'] );
		self::assertSame( '/api/v1/ping', $ctx['path'] );
		self::assertSame( 'GET', $ctx['method'] );
		self::assertSame( 9, $ctx['port'] );
		self::assertSame( 12, $ctx['duration_ms'] );
		$json = (string) json_encode( $event->toArray() );
		self::assertStringNotContainsString( 'secret', $json );
		self::assertStringNotContainsString( '127.0.0.1', $json );

		$server = HttpApiListener::buildEvent( 'https://api.example.com/v2/x', 'POST', 503, null, null, null, $hash );
		self::assertNotNull( $server );
		self::assertSame( 503, $server->getContext()['status'] );
		self::assertSame( 'api.example.com', $server->getContext()['host'] );
		self::assertNull( HttpApiListener::buildEvent( 'not a url', 'GET', 500, null, null, null, $hash ) );
		self::assertSame( 'private', HttpApiListener::ipScope( '10.1.2.3' ) );
		self::assertSame( 'public', HttpApiListener::ipScope( '8.8.8.8' ) );
	}

	public function test_mail_failure_event(): void {
		$event = MailListener::buildEvent( 'wp_mail_failed', 'Invalid address:  (to): not-an-email; also bob@example.com', [ 'a@example.com, b@example.com', 'c@example.com' ], 'deadbeef', 2, false );
		$ctx   = $event->getContext();
		self::assertSame( 'mail.failed', $event->getAction() );
		self::assertSame( 3, $ctx['recipient_count'] );
		self::assertSame( 'deadbeef', $ctx['subject_hash'] );
		self::assertStringNotContainsString( '@example.com', (string) json_encode( $event->toArray() ) );
		self::assertSame( $event->getFingerprint(), MailListener::buildEvent( 'wp_mail_failed', 'other text', [], null, 2, false )->getFingerprint() );
	}
}
