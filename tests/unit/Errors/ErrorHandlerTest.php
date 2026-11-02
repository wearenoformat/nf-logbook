<?php
/**
 * ErrorHandler / FatalHandler event building tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Errors;

use Noformat\Logger\Errors\ErrorHandler;
use Noformat\Logger\Errors\FatalHandler;
use Noformat\Logger\Errors\Support\PathMapper;
use Noformat\Logger\Severity;
use Noformat\Logger\Tests\TestCase;

final class ErrorHandlerTest extends TestCase {

	private function mapper(): PathMapper {
		return new PathMapper(
			[
				'/srv/www/'            => '',
				'/srv/www/wp-content/' => 'wp-content',
			]
		);
	}

	/**
	 * Mapping table.
	 *
	 * @return array<string, array{int, string|null, Severity|null}>
	 */
	public static function types(): array {
		return [
			'notice'        => [ E_NOTICE, 'php.notice', Severity::WARNING ],
			'user notice'   => [ E_USER_NOTICE, 'php.notice', Severity::WARNING ],
			'strict'        => [ 2048, 'php.notice', Severity::WARNING ],
			'deprecated'    => [ E_DEPRECATED, 'php.deprecated', Severity::WARNING ],
			'user depr.'    => [ E_USER_DEPRECATED, 'php.deprecated', Severity::WARNING ],
			'warning'       => [ E_WARNING, 'php.warning', Severity::ERROR ],
			'user warning'  => [ E_USER_WARNING, 'php.warning', Severity::ERROR ],
			'core warning'  => [ E_CORE_WARNING, 'php.warning', Severity::ERROR ],
			'compile warn.' => [ E_COMPILE_WARNING, 'php.warning', Severity::ERROR ],
			'user error'    => [ E_USER_ERROR, 'php.error', Severity::ERROR ],
			'recoverable'   => [ E_RECOVERABLE_ERROR, 'php.error', Severity::ERROR ],
			'fatal'         => [ E_ERROR, null, null ],
			'parse'         => [ E_PARSE, null, null ],
		];
	}

	/**
	 * @dataProvider types
	 */
	public function test_type_mapping( int $type, ?string $action, ?Severity $severity ): void {
		$map = ErrorHandler::classify( $type );
		if ( null === $action ) {
			self::assertNull( $map );
			return;
		}
		self::assertNotNull( $map );
		self::assertSame( $action, $map[0] );
		self::assertSame( $severity, $map[1] );
	}

	public function test_event_has_relative_paths_and_component(): void {
		$trace = [
			[
				'function' => 'boom',
				'class'    => 'Foo',
				'file'     => 'wp-content/plugins/foo/foo.php',
				'line'     => 9,
			],
		];
		$event = ErrorHandler::buildEvent( E_WARNING, 'file_get_contents(/srv/www/wp-content/plugins/foo/x.txt): Failed for bob@example.com', '/srv/www/wp-content/plugins/foo/foo.php', 12, $this->mapper(), $trace );
		self::assertNotNull( $event );
		$ctx = $event->getContext();
		self::assertSame( 'php.warning', $event->getAction() );
		self::assertSame( 'error', $event->getSeverity()->value );
		self::assertSame( 'wp-content/plugins/foo/foo.php', $ctx['file'] );
		self::assertSame( 'plugin:foo', $ctx['component'] );
		self::assertSame( 'E_WARNING', $ctx['type'] );
		self::assertSame( 12, $ctx['line'] );
		self::assertSame( 'file_get_contents(wp-content/plugins/foo/x.txt): Failed for [email]', $ctx['message'] );
		self::assertSame( $trace, $ctx['trace'] );
		self::assertStringNotContainsString( '/srv/', (string) json_encode( $event->toArray() ) );
		self::assertSame( 'wp-content/plugins/foo/foo.php', $event->getObject()['id'] ?? null );
	}

	public function test_fingerprint_ignores_numbers_but_not_location(): void {
		$m = $this->mapper();
		$a = ErrorHandler::buildEvent( E_WARNING, 'Undefined array key 3', '/srv/www/a.php', 5, $m );
		$b = ErrorHandler::buildEvent( E_WARNING, 'Undefined array key 99', '/srv/www/a.php', 5, $m );
		$c = ErrorHandler::buildEvent( E_WARNING, 'Undefined array key 3', '/srv/www/a.php', 6, $m );
		$d = ErrorHandler::buildEvent( E_NOTICE, 'Undefined array key 3', '/srv/www/a.php', 5, $m );
		self::assertNotNull( $a );
		self::assertNotNull( $b );
		self::assertNotNull( $c );
		self::assertNotNull( $d );
		self::assertSame( $a->getFingerprint(), $b->getFingerprint() );
		self::assertNotSame( $a->getFingerprint(), $c->getFingerprint() );
		self::assertNotSame( $a->getFingerprint(), $d->getFingerprint() );
	}

	public function test_message_truncated_to_1kb(): void {
		$event = ErrorHandler::buildEvent( E_NOTICE, str_repeat( 'x', 5000 ), '/srv/www/a.php', 1, $this->mapper() );
		self::assertNotNull( $event );
		self::assertLessThanOrEqual( 1024, strlen( (string) $event->getContext()['message'] ) );
	}

	public function test_object_id_keeps_tail(): void {
		$long = 'wp-content/plugins/' . str_repeat( 'a', 80 ) . '/file.php';
		$id   = ErrorHandler::objectId( $long );
		self::assertSame( 64, strlen( $id ) );
		self::assertStringEndsWith( '/file.php', $id );
	}

	public function test_handler_chains_and_returns_previous_result(): void {
		$calls    = [];
		$previous = set_error_handler(
			static function ( int $no, string $str ) use ( &$calls ): bool {
				$calls[] = $str;
				return true;
			}
		);
		$handler  = new ErrorHandler();
		$property = new \ReflectionProperty( ErrorHandler::class, 'previous' );
		$property->setValue( $handler, static fn( int $no, string $str ): bool => 'chained' === $str );
		restore_error_handler();
		unset( $previous );
		self::assertTrue( $handler->handle( E_USER_NOTICE, 'chained', __FILE__, __LINE__ ) );
		self::assertFalse( $handler->handle( E_USER_NOTICE, 'other', __FILE__, __LINE__ ) );
		$bare = new ErrorHandler();
		self::assertFalse( $bare->handle( E_USER_NOTICE, 'x', __FILE__, __LINE__ ), 'without a previous handler PHP keeps its standard handling' );
		self::assertSame( [], $calls );
	}

	public function test_fatal_event_from_error_get_last(): void {
		$event = FatalHandler::fatalEvent(
			[
				'type'    => E_ERROR,
				'message' => "Uncaught Error: Call to undefined function x() in /srv/www/wp-content/themes/t/functions.php:4\nStack trace:\n#0 /srv/www/wp-settings.php(1): include()",
				'file'    => '/srv/www/wp-content/themes/t/functions.php',
				'line'    => 4,
			],
			$this->mapper(),
			true
		);
		self::assertNotNull( $event );
		self::assertSame( 'php.fatal', $event->getAction() );
		self::assertSame( Severity::CRITICAL, $event->getSeverity() );
		$ctx = $event->getContext();
		self::assertTrue( $ctx['public_request'] );
		self::assertSame( 'theme:t', $ctx['component'] );
		self::assertStringNotContainsString( 'Stack trace', (string) $ctx['message'] );
		self::assertStringNotContainsString( '/srv/', (string) json_encode( $event->toArray() ) );
		self::assertNull( FatalHandler::fatalEvent( [ 'type' => E_WARNING ], $this->mapper(), false ) );
	}

	public function test_uncaught_fatal_message_is_parsed(): void {
		$raw   = "Uncaught RuntimeException: boom in /srv/www/wp-content/plugins/p/p.php:5 with 7 in /srv/www/wp-content/plugins/p/p.php:49\nStack trace:\n#0 /srv/www/wp-includes/class-wp-hook.php(324): {closure:/srv/www/wp-content/plugins/p/p.php:40}('secret')\n#1 /srv/www/wp-includes/plugin.php(517): WP_Hook->do_action(Array)\n#2 [internal function]: Foo::bar('x')\n#3 {main}\n  thrown in /srv/www/wp-content/plugins/p/p.php on line 49";
		$event = FatalHandler::fatalEvent(
			[
				'type'    => E_ERROR,
				'message' => $raw,
				'file'    => '/srv/www/wp-content/plugins/p/p.php',
				'line'    => 49,
			],
			$this->mapper(),
			false
		);
		self::assertNotNull( $event );
		self::assertSame( 'php.exception', $event->getAction() );
		$ctx = $event->getContext();
		self::assertSame( 'RuntimeException', $ctx['class'] );
		self::assertSame( 'boom in wp-content/plugins/p/p.php:5 with 7', $ctx['message'] );
		self::assertSame( 'wp-content/plugins/p/p.php', $ctx['file'] );
		self::assertSame( 49, $ctx['line'] );
		self::assertSame( 'plugin:p', $ctx['component'] );
		self::assertSame(
			[
				[
					'function' => '{closure:wp-content/plugins/p/p.php:40}',
					'file'     => 'wp-includes/class-wp-hook.php',
					'line'     => 324,
				],
				[
					'function' => 'do_action',
					'class'    => 'WP_Hook',
					'file'     => 'wp-includes/plugin.php',
					'line'     => 517,
				],
				[
					'function' => 'bar',
					'class'    => 'Foo',
				],
			],
			$ctx['trace']
		);
		self::assertStringNotContainsString( 'secret', (string) json_encode( $event->toArray() ) );
		$direct = FatalHandler::uncaughtEvent( 'RuntimeException', 'boom in /srv/www/wp-content/plugins/p/p.php:5 with 7', '/srv/www/wp-content/plugins/p/p.php', 49, [], $this->mapper(), false );
		self::assertSame( $direct->getFingerprint(), $event->getFingerprint(), 'same fingerprint as the handler path' );

		$error = FatalHandler::fatalEvent(
			[
				'type'    => E_ERROR,
				'message' => "Uncaught Error: Call to undefined function nope() in /srv/www/a.php:3\nStack trace:\n#0 {main}\n  thrown in /srv/www/a.php on line 3",
				'file'    => '/srv/www/a.php',
				'line'    => 3,
			],
			$this->mapper(),
			true
		);
		self::assertNotNull( $error );
		self::assertSame( 'php.fatal', $error->getAction() );
		self::assertSame( 'Error', $error->getContext()['class'] );
		self::assertNull( FatalHandler::parseUncaught( 'Allowed memory size of 1 bytes exhausted', $this->mapper() ) );
	}

	public function test_exception_event(): void {
		$exception = new \RuntimeException( 'boom 42', 7 );
		$event     = FatalHandler::exceptionEvent( $exception, new PathMapper( [ dirname( __DIR__, 3 ) => '' ] ), false );
		self::assertSame( 'php.exception', $event->getAction() );
		self::assertSame( Severity::CRITICAL, $event->getSeverity() );
		$ctx = $event->getContext();
		self::assertSame( 'RuntimeException', $ctx['class'] );
		self::assertSame( 'tests/unit/Errors/ErrorHandlerTest.php', $ctx['file'] );
		self::assertSame( 7, $ctx['code'] );
		self::assertIsArray( $ctx['trace'] );
		self::assertLessThanOrEqual( 10, count( $ctx['trace'] ) );
		foreach ( $ctx['trace'] as $frame ) {
			self::assertArrayNotHasKey( 'args', $frame );
		}
		$error = FatalHandler::exceptionEvent( new \TypeError( 't' ), new PathMapper( [] ), false );
		self::assertSame( 'php.fatal', $error->getAction(), 'engine errors are fatals' );
	}
}
