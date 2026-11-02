<?php
/**
 * DeprecationListener.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Errors;

use Noformat\Logger\Category;
use Noformat\Logger\Config;
use Noformat\Logger\Errors\Support\PathMapper;
use Noformat\Logger\Errors\Support\Text;
use Noformat\Logger\Event;
use Noformat\Logger\Listener;
use Noformat\Logger\Logger;
use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Deprecations and misuse reported by WordPress itself (PLAN §4). The `*_run`
 * actions fire regardless of WP_DEBUG, so these are captured on production too.
 *
 * When WP_DEBUG is on, WordPress additionally raises E_USER_DEPRECATED /
 * E_USER_NOTICE through wp_trigger_error() (wp-includes/functions.php); the
 * ErrorHandler skips that one follow-up error (see consumeTrigger()) so each
 * deprecation is reported once, as `deprecated.*` / `doing_it_wrong`.
 */
final class DeprecationListener implements Listener {

	/** Max distinct deprecation events per request. */
	public const MAX_EVENTS = 50;

	private static bool $triggerPending = false;

	private ?Logger $logger = null;

	private int $events = 0;

	/**
	 * Logged fingerprints in this request.
	 *
	 * @var array<string, Event>
	 */
	private array $seen = [];

	/**
	 * Availability.
	 */
	public static function isAvailable(): bool {
		return true;
	}

	/**
	 * Add hooks.
	 *
	 * @param Logger $logger Logger.
	 * @param Config $config Config.
	 */
	public function register( Logger $logger, Config $config ): void {
		unset( $config );
		$this->logger = $logger;
		add_action( 'deprecated_function_run', [ $this, 'onFunction' ], 10, 3 );
		add_action( 'deprecated_argument_run', [ $this, 'onArgument' ], 10, 3 );
		add_action( 'deprecated_hook_run', [ $this, 'onHook' ], 10, 4 );
		add_action( 'deprecated_file_included', [ $this, 'onFile' ], 10, 4 );
		add_action( 'doing_it_wrong_run', [ $this, 'onDoingItWrong' ], 10, 3 );
	}

	/**
	 * Called by the ErrorHandler: true (once) when the error is WordPress'
	 * wp_trigger_error() follow-up of a deprecation just logged here.
	 *
	 * @param int    $errno    Error type.
	 * @param string $relative Relative file of the error.
	 */
	public static function consumeTrigger( int $errno, string $relative ): bool {
		if ( ! self::$triggerPending ) {
			return false;
		}
		if ( 'wp-includes/functions.php' !== $relative || 0 === ( $errno & ( E_USER_DEPRECATED | E_USER_NOTICE | E_USER_WARNING ) ) ) {
			return false;
		}
		self::$triggerPending = false;
		return true;
	}

	/**
	 * `deprecated_function_run`.
	 *
	 * @param mixed $name        Function.
	 * @param mixed $replacement Replacement.
	 * @param mixed $version     Version.
	 */
	public function onFunction( mixed $name = '', mixed $replacement = '', mixed $version = '' ): void {
		$this->handle( 'deprecated.function', 'function', (string) $name, (string) $replacement, (string) $version, '' );
	}

	/**
	 * `deprecated_argument_run`.
	 *
	 * @param mixed $name    Function.
	 * @param mixed $message Message.
	 * @param mixed $version Version.
	 */
	public function onArgument( mixed $name = '', mixed $message = '', mixed $version = '' ): void {
		$this->handle( 'deprecated.argument', 'function', (string) $name, '', (string) $version, (string) $message );
	}

	/**
	 * `deprecated_hook_run`.
	 *
	 * @param mixed $hook        Hook.
	 * @param mixed $replacement Replacement.
	 * @param mixed $version     Version.
	 * @param mixed $message     Message.
	 */
	public function onHook( mixed $hook = '', mixed $replacement = '', mixed $version = '', mixed $message = '' ): void {
		$this->handle( 'deprecated.hook', 'hook', (string) $hook, (string) $replacement, (string) $version, (string) $message );
	}

	/**
	 * `deprecated_file_included`.
	 *
	 * @param mixed $file        File.
	 * @param mixed $replacement Replacement.
	 * @param mixed $version     Version.
	 * @param mixed $message     Message.
	 */
	public function onFile( mixed $file = '', mixed $replacement = '', mixed $version = '', mixed $message = '' ): void {
		$paths = PathMapper::shared();
		$this->handle( 'deprecated.file', 'file', $paths->relative( (string) $file ), $paths->relative( (string) $replacement ), (string) $version, (string) $message );
	}

	/**
	 * `doing_it_wrong_run`.
	 *
	 * @param mixed $name    Function.
	 * @param mixed $message Message.
	 * @param mixed $version Version.
	 */
	public function onDoingItWrong( mixed $name = '', mixed $message = '', mixed $version = '' ): void {
		$this->handle( 'doing_it_wrong', 'function', (string) $name, '', (string) $version, (string) $message );
	}

	/**
	 * Build the event (pure).
	 *
	 * @param string                              $action      Action.
	 * @param string                              $objectType  function|hook|file.
	 * @param string                              $name        Name.
	 * @param string                              $replacement Replacement.
	 * @param string                              $version     Version.
	 * @param string                              $message     Message.
	 * @param array{file: string, line: int}|null $caller      Caller location.
	 * @param PathMapper                          $paths       Path mapper.
	 */
	public static function buildEvent( string $action, string $objectType, string $name, string $replacement, string $version, string $message, ?array $caller, PathMapper $paths ): Event {
		$name    = substr( $name, 0, 191 );
		$message = '' === $message ? null : Text::clip( Text::scrub( $paths->relativeInText( Text::plain( $message ) ) ), 512 );
		$labels  = [
			'deprecated.function' => 'Deprecated function',
			'deprecated.argument' => 'Deprecated argument of',
			'deprecated.hook'     => 'Deprecated hook',
			'deprecated.file'     => 'Deprecated file',
			'doing_it_wrong'      => 'Incorrect use of',
		];
		$context = [
			$objectType   => $name,
			'replacement' => '' === $replacement ? null : substr( Text::plain( $replacement ), 0, 191 ),
			'version'     => '' === $version ? null : substr( $version, 0, 32 ),
			'message'     => $message,
			'component'   => null === $caller ? 'unknown' : PathMapper::component( $caller['file'] ),
			'caller'      => $caller,
		];
		return Event::make( Category::ERROR, $action, Severity::WARNING )
			->object( $objectType, ErrorHandler::objectId( $name ) )
			->summary( sprintf( '%s %s%s', $labels[ $action ] ?? $action, $name, '' === $version ? '' : ' (since ' . $version . ')' ) )
			->context( $context )
			->fingerprint( $action, $name );
	}

	/**
	 * Caller of the deprecated API: first frame outside wp-includes/ and the logger.
	 *
	 * @param array<int, array<string, mixed>> $frames debug_backtrace() frames.
	 * @param PathMapper                       $paths  Path mapper.
	 * @return array{file: string, line: int}|null
	 */
	public static function findCaller( array $frames, PathMapper $paths ): ?array {
		foreach ( $frames as $frame ) {
			if ( ! isset( $frame['file'] ) || ! is_string( $frame['file'] ) || $paths->isOwn( $frame['file'] ) ) {
				continue;
			}
			$relative = $paths->relative( $frame['file'] );
			if ( str_starts_with( $relative, 'wp-includes/' ) ) {
				continue;
			}
			return [
				'file' => $relative,
				'line' => (int) ( $frame['line'] ?? 0 ),
			];
		}
		return null;
	}

	/**
	 * Common handler.
	 *
	 * @param string $action      Action.
	 * @param string $objectType  Object type.
	 * @param string $name        Name.
	 * @param string $replacement Replacement.
	 * @param string $version     Version.
	 * @param string $message     Message.
	 */
	private function handle( string $action, string $objectType, string $name, string $replacement, string $version, string $message ): void {
		$logger = $this->logger;
		if ( null === $logger ) {
			return;
		}
		self::$triggerPending = true;
		$logger->guard(
			'deprecation.' . $action,
			function () use ( $logger, $action, $objectType, $name, $replacement, $version, $message ): void {
				$key = $action . "\x1f" . $name;
				if ( isset( $this->seen[ $key ] ) && null === $this->seen[ $key ]->getId() ) {
					$this->seen[ $key ]->count( $this->seen[ $key ]->getCount() + 1 );
					return;
				}
				if ( $this->events >= self::MAX_EVENTS ) {
					return;
				}
				++$this->events;
				$paths              = PathMapper::shared();
				$frames             = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 16 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- caller detection, no args.
				$event              = self::buildEvent( $action, $objectType, $name, $replacement, $version, $message, self::findCaller( $frames, $paths ), $paths );
				$this->seen[ $key ] = $event;
				$logger->log( $event );
			}
		);
	}
}
