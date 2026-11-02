<?php
/**
 * FatalHandler.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Errors;

use Noformat\Logger\Category;
use Noformat\Logger\Config;
use Noformat\Logger\Errors\Support\PathMapper;
use Noformat\Logger\Errors\Support\Text;
use Noformat\Logger\Errors\Support\Trace;
use Noformat\Logger\Event;
use Noformat\Logger\Listener;
use Noformat\Logger\Logger;
use Noformat\Logger\Severity;
use Noformat\Logger\Source;

defined( 'ABSPATH' ) || exit;

/**
 * PHP fatals and uncaught exceptions (PLAN §4, §5: always critical).
 *
 * Fatals are seen in two places, deduplicated per request:
 * 1. the `wp_php_error_message` / `wp_php_error_args` filters, which WordPress'
 *    own fatal error handler applies while rendering the "critical error" page.
 *    That handler runs as an earlier shutdown function and ends with wp_die(),
 *    so later shutdown functions (including the Logger's flush) never run; the
 *    filters are therefore the reliable hook. They are observed only: the value
 *    is returned unchanged.
 * 2. our own register_shutdown_function() + error_get_last(), for sites where
 *    WordPress' handler is disabled or not displaying.
 * Both write synchronously with Logger::logNow( $event, false ): no transaction
 * is opened on the shared connection (a START TRANSACTION would commit a
 * transaction the crashed request left open).
 *
 * Uncaught throwables: `\Exception` subclasses become `php.exception`; engine
 * `\Error`s (undefined function, TypeError, …), which PHP 5 reported as fatal
 * errors, become `php.fatal` with `context.uncaught` set.
 * - When another exception handler is already installed at registration time,
 *   ours wraps it (set_exception_handler, chained: theirs still runs).
 * - When none is installed (the normal case) we deliberately do NOT install
 *   one: any user exception handler stops PHP from turning the throwable into a
 *   fatal error, which would change the response and hide the error from
 *   WordPress' fatal handler and recovery mode (a handler cannot re-throw: PHP
 *   would call it again). Instead the "Uncaught <Class>: <message> in
 *   <file>:<line>" fatal is parsed on the fatal path above, including its
 *   "Stack trace:" (call names only, arguments dropped).
 */
final class FatalHandler implements Listener {

	/** Fatal error types. */
	public const FATAL_TYPES = [
		E_ERROR             => 'E_ERROR',
		E_PARSE             => 'E_PARSE',
		E_CORE_ERROR        => 'E_CORE_ERROR',
		E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
		E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
	];

	private ?Logger $logger = null;

	/**
	 * Previous exception handler.
	 *
	 * @var callable|null
	 */
	private $previous = null;

	/**
	 * Logged fatal keys (file:line) in this request.
	 *
	 * @var array<string, true>
	 */
	private array $logged = [];

	private bool $uncaughtLogged = false;

	private bool $installed = false;

	/**
	 * Availability.
	 */
	public static function isAvailable(): bool {
		return true;
	}

	/**
	 * Install shutdown function, exception handler and observing filters.
	 *
	 * @param Logger $logger Logger.
	 * @param Config $config Config.
	 */
	public function register( Logger $logger, Config $config ): void {
		unset( $config );
		$this->logger = $logger;
		if ( $this->installed ) {
			return;
		}
		$this->installed = true;
		register_shutdown_function( [ $this, 'onShutdown' ] );
		$previous = set_exception_handler( null );
		if ( is_callable( $previous ) ) {
			$this->previous = $previous;
			set_exception_handler( [ $this, 'handleException' ] );
		}
		add_filter( 'wp_php_error_message', [ $this, 'observeMessage' ], PHP_INT_MAX, 2 );
		add_filter( 'wp_php_error_args', [ $this, 'observeArgs' ], PHP_INT_MAX, 2 );
	}

	/**
	 * Shutdown function: log the last error when it is fatal.
	 */
	public function onShutdown(): void {
		try {
			$error = error_get_last();
			if ( is_array( $error ) ) {
				$this->logFatal( $error );
			}
		} catch ( \Throwable $e ) {
			$this->logger?->recordFailure( 'fatal.shutdown', $e );
		}
	}

	/**
	 * Observe `wp_php_error_message` (returned unchanged).
	 *
	 * @param mixed $message Message HTML.
	 * @param mixed $error   error_get_last() array.
	 */
	public function observeMessage( mixed $message, mixed $error = null ): mixed {
		$this->observe( $error );
		return $message;
	}

	/**
	 * Observe `wp_php_error_args` (returned unchanged).
	 *
	 * @param mixed $args  wp_die() args.
	 * @param mixed $error error_get_last() array.
	 */
	public function observeArgs( mixed $args, mixed $error = null ): mixed {
		$this->observe( $error );
		return $args;
	}

	/**
	 * Uncaught throwable handler (installed only in front of an existing handler).
	 *
	 * @param \Throwable $throwable Throwable.
	 */
	public function handleException( \Throwable $throwable ): void {
		$logger = $this->logger;
		if ( null !== $logger ) {
			try {
				if ( ! PathMapper::shared()->isOwn( $throwable->getFile() ) ) {
					$this->uncaughtLogged = true;
					$logger->logNow( self::exceptionEvent( $throwable, PathMapper::shared(), $this->isPublicRequest() ), false );
				}
			} catch ( \Throwable $e ) {
				$logger->recordFailure( 'fatal.exception', $e );
			}
		}
		if ( null !== $this->previous ) {
			call_user_func( $this->previous, $throwable );
		}
	}

	/**
	 * Build the event for an uncaught throwable (pure).
	 *
	 * @param \Throwable $throwable Throwable.
	 * @param PathMapper $paths     Path mapper.
	 * @param bool       $isPublic  Frontend request.
	 */
	public static function exceptionEvent( \Throwable $throwable, PathMapper $paths, bool $isPublic ): Event {
		$event    = self::uncaughtEvent(
			get_class( $throwable ),
			$throwable->getMessage(),
			$throwable->getFile(),
			$throwable->getLine(),
			Trace::sanitize( $throwable->getTrace(), $paths ),
			$paths,
			$isPublic
		);
		$previous = $throwable->getPrevious();
		$extra    = [ 'code' => is_int( $throwable->getCode() ) ? $throwable->getCode() : 0 ];
		if ( null !== $previous ) {
			$extra['previous'] = [
				'class' => get_class( $previous ),
				'file'  => $paths->relative( $previous->getFile() ),
				'line'  => $previous->getLine(),
			];
		}
		return $event->context( $extra );
	}

	/**
	 * Event for an uncaught throwable from its parts (pure).
	 *
	 * @param string                    $className Throwable class.
	 * @param string                    $message  Raw message.
	 * @param string                    $file     Absolute file.
	 * @param int                       $line     Line.
	 * @param list<array<string,mixed>> $trace    Sanitised trace.
	 * @param PathMapper                $paths    Path mapper.
	 * @param bool                      $isPublic Frontend request.
	 */
	public static function uncaughtEvent( string $className, string $message, string $file, int $line, array $trace, PathMapper $paths, bool $isPublic ): Event {
		$class    = ltrim( $className, '\\' );
		$relative = $paths->relative( $file );
		$message  = Text::clip( Text::scrub( $paths->relativeInText( $message ) ) );
		$isError  = 'Error' === $class || ( class_exists( $class, false ) && is_subclass_of( $class, \Error::class ) );
		$action   = $isError ? 'php.fatal' : 'php.exception';
		$context  = [
			'class'          => $class,
			'message'        => $message,
			'file'           => $relative,
			'line'           => $line,
			'component'      => PathMapper::component( $relative ),
			'trace'          => $trace,
			'public_request' => $isPublic,
			'uncaught'       => true,
		];
		if ( $isError ) {
			$context['type'] = 'E_ERROR';
		}
		return Event::make( Category::STABILITY, $action, Severity::CRITICAL )
			->object( 'file', ErrorHandler::objectId( $relative ) )
			->summary( sprintf( 'Uncaught %s in %s:%d: %s', $class, $relative, $line, $message ) )
			->context( $context )
			->fingerprint( $action, $class, $relative, $line, Text::normalize( $message ) );
	}

	/**
	 * Parse PHP's "Uncaught <Class>: <message> in <file>:<line>\nStack trace:\n#0 …"
	 * fatal message (pure). Returns null for other messages.
	 *
	 * @param string     $raw   error_get_last() message.
	 * @param PathMapper $paths Path mapper.
	 * @return array{class: string, message: string, file: string, line: int, trace: list<array<string, mixed>>}|null
	 */
	public static function parseUncaught( string $raw, PathMapper $paths ): ?array {
		$parts = explode( "\nStack trace:\n", $raw, 2 );
		if ( ! preg_match( '/^Uncaught ([A-Za-z_\\\\][\w\\\\]*)(?:: (.*))? in (.+?):(\d+)$/s', $parts[0], $m ) ) {
			return null;
		}
		$frames = [];
		if ( isset( $parts[1] ) && preg_match_all( '/^#\d+ (?:\[internal function\]|(.+?)\((\d+)\)): (.+)$/m', $parts[1], $rows, PREG_SET_ORDER ) ) {
			foreach ( $rows as $row ) {
				$call  = (string) preg_replace( '/\(.*$/s', '', $row[3] );
				$frame = [];
				if ( preg_match( '/^(.+?)(->|::)(.+)$/', $call, $cm ) ) {
					$frame['function'] = $cm[3];
					$frame['class']    = $cm[1];
				} else {
					$frame['function'] = $call;
				}
				if ( '' !== $row[1] ) {
					$frame['file'] = $row[1];
					$frame['line'] = (int) $row[2];
				}
				$frames[] = $frame;
			}
		}
		return [
			'class'   => $m[1],
			'message' => $m[2],
			'file'    => $m[3],
			'line'    => (int) $m[4],
			'trace'   => Trace::sanitize( $frames, $paths ),
		];
	}

	/**
	 * Build the event for a fatal error_get_last() entry (pure; null when not fatal).
	 *
	 * @param array<string, mixed> $error  error_get_last() array.
	 * @param PathMapper           $paths  Path mapper.
	 * @param bool                 $isPublic Frontend request.
	 */
	public static function fatalEvent( array $error, PathMapper $paths, bool $isPublic ): ?Event {
		$type = (int) ( $error['type'] ?? 0 );
		if ( ! isset( self::FATAL_TYPES[ $type ] ) ) {
			return null;
		}
		$label    = self::FATAL_TYPES[ $type ];
		$uncaught = E_ERROR === $type ? self::parseUncaught( (string) ( $error['message'] ?? '' ), $paths ) : null;
		if ( null !== $uncaught ) {
			return self::uncaughtEvent( $uncaught['class'], $uncaught['message'], $uncaught['file'], $uncaught['line'], $uncaught['trace'], $paths, $isPublic );
		}
		$relative = $paths->relative( (string) ( $error['file'] ?? '' ) );
		$line     = (int) ( $error['line'] ?? 0 );
		$raw      = (string) ( $error['message'] ?? '' );
		// PHP appends the stack trace of uncaught throwables to the message; keep the first line.
		$first   = strtok( $raw, "\n" );
		$message = Text::clip( Text::scrub( $paths->relativeInText( false === $first ? $raw : $first ) ) );
		return Event::make( Category::STABILITY, 'php.fatal', Severity::CRITICAL )
			->object( 'file', ErrorHandler::objectId( $relative ) )
			->summary( sprintf( 'PHP Fatal error (%s) in %s:%d: %s', $label, $relative, $line, $message ) )
			->context(
				[
					'type'           => $label,
					'message'        => $message,
					'file'           => $relative,
					'line'           => $line,
					'component'      => PathMapper::component( $relative ),
					'public_request' => $isPublic,
				]
			)
			->fingerprint( 'php.fatal', $label, $relative, $line, Text::normalize( $message ) );
	}

	/**
	 * Common path for both observers.
	 *
	 * @param mixed $error error_get_last() array.
	 */
	private function observe( mixed $error ): void {
		try {
			if ( ! is_array( $error ) ) {
				$error = error_get_last();
			}
			if ( is_array( $error ) ) {
				$this->logFatal( $error );
			}
		} catch ( \Throwable $e ) {
			$this->logger?->recordFailure( 'fatal.observe', $e );
		}
	}

	/**
	 * Log a fatal once per request.
	 *
	 * @param array<string, mixed> $error error_get_last() array.
	 */
	private function logFatal( array $error ): void {
		if ( null === $this->logger ) {
			return;
		}
		$type = (int) ( $error['type'] ?? 0 );
		if ( ! isset( self::FATAL_TYPES[ $type ] ) ) {
			return;
		}
		$file = (string) ( $error['file'] ?? '' );
		$key  = $file . ':' . (int) ( $error['line'] ?? 0 );
		if ( isset( $this->logged[ $key ] ) ) {
			return;
		}
		$this->logged[ $key ] = true;
		if ( $this->uncaughtLogged && str_starts_with( (string) ( $error['message'] ?? '' ), 'Uncaught ' ) ) {
			return;
		}
		$paths = PathMapper::shared();
		if ( $paths->isOwn( $file ) ) {
			$this->logger->recordFailure( 'fatal.own:' . basename( $file ) );
			$this->logger->flush();
			return;
		}
		$event = self::fatalEvent( $error, $paths, $this->isPublicRequest() );
		if ( null !== $event ) {
			$this->logger->logNow( $event, false );
		}
	}

	/**
	 * Whether the current request is a public (frontend) one.
	 */
	private function isPublicRequest(): bool {
		try {
			return null !== $this->logger && Source::FRONTEND === $this->logger->context()->source();
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
