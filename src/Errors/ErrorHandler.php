<?php
/**
 * ErrorHandler.
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

defined( 'ABSPATH' ) || exit;

/**
 * PHP errors via a chained set_error_handler (PLAN §4).
 *
 * - Honours error_reporting() (the @ operator and WordPress' own level).
 * - Never changes behaviour: the previous handler is always called and its
 *   return value passed through; without one, false is returned so PHP's
 *   standard handler (display_errors, log_errors, debug.log) still runs.
 * - MU loader (PLAN D8): when the early loader's handler is the one we replace,
 *   its buffer is drained into events and we chain to the handler that was
 *   active before the loader (Noformat_Logger_Early_Errors::previous_handler()),
 *   then detach the loader so nothing is buffered twice.
 * - Errors raised inside the logger's own src/ are never logged (loop guard);
 *   they only increment the internal failure counter.
 * - At most MAX_EVENTS distinct errors per request become events; repeats of
 *   the same fingerprint only bump the count; anything beyond the cap is
 *   summarised in one `php.errors_suppressed` event at shutdown.
 */
final class ErrorHandler implements Listener {

	/** Global class defined by the MU loader. */
	public const EARLY_BUFFER_CLASS = 'Noformat_Logger_Early_Errors';

	/** Distinct error events per request. */
	public const MAX_EVENTS = 50;

	/** Error type => [action, severity, label]. */
	private const MAP = [
		E_NOTICE            => [ 'php.notice', 'warning', 'E_NOTICE' ],
		E_USER_NOTICE       => [ 'php.notice', 'warning', 'E_USER_NOTICE' ],
		2048                => [ 'php.notice', 'warning', 'E_STRICT' ],
		E_DEPRECATED        => [ 'php.deprecated', 'warning', 'E_DEPRECATED' ],
		E_USER_DEPRECATED   => [ 'php.deprecated', 'warning', 'E_USER_DEPRECATED' ],
		E_WARNING           => [ 'php.warning', 'error', 'E_WARNING' ],
		E_USER_WARNING      => [ 'php.warning', 'error', 'E_USER_WARNING' ],
		E_CORE_WARNING      => [ 'php.warning', 'error', 'E_CORE_WARNING' ],
		E_COMPILE_WARNING   => [ 'php.warning', 'error', 'E_COMPILE_WARNING' ],
		E_USER_ERROR        => [ 'php.error', 'error', 'E_USER_ERROR' ],
		E_RECOVERABLE_ERROR => [ 'php.error', 'error', 'E_RECOVERABLE_ERROR' ],
	];

	private ?Logger $logger = null;

	/**
	 * Handler we chain to.
	 *
	 * @var callable|null
	 */
	private $previous = null;

	private bool $inside = false;

	private bool $installed = false;

	private int $events = 0;

	private int $suppressed = 0;

	/**
	 * Suppressed occurrences per type label.
	 *
	 * @var array<string, int>
	 */
	private array $suppressedByType = [];

	/**
	 * Pending events by fingerprint (repeat occurrences bump the count).
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
	 * Install the handler and drain the MU loader buffer.
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
		$previous        = set_error_handler( [ $this, 'handle' ] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- the purpose of this class.
		if ( self::earlyLoaderActive() && self::isEarlyHandler( $previous ) ) {
			$previous = call_user_func( [ self::EARLY_BUFFER_CLASS, 'previous_handler' ] );
		}
		$this->previous = is_callable( $previous ) ? $previous : null;
		$this->drainEarly();
		add_action( 'shutdown', [ $this, 'onShutdown' ], 990, 0 );
	}

	/**
	 * The error handler. Never throws on its own; returns what the chain returns.
	 *
	 * @param int    $errno   Level.
	 * @param string $errstr  Message.
	 * @param string $errfile File.
	 * @param int    $errline Line.
	 */
	public function handle( int $errno, string $errstr, string $errfile = '', int $errline = 0 ): bool {
		if ( ! $this->inside ) {
			$this->inside = true;
			try {
				if ( 0 !== ( error_reporting() & $errno ) ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- read only.
					$this->record( $errno, $errstr, $errfile, $errline, null );
				}
			} catch ( \Throwable $e ) {
				$this->logger?->recordFailure( 'error_handler', $e );
			} finally {
				$this->inside = false;
			}
		}
		if ( null !== $this->previous ) {
			// The previous handler's behaviour (including exceptions it throws) is the site's behaviour.
			return (bool) call_user_func( $this->previous, $errno, $errstr, $errfile, $errline );
		}
		return false;
	}

	/**
	 * Shutdown: drain late early-buffer entries and summarise suppressed errors.
	 */
	public function onShutdown(): void {
		try {
			$this->drainEarly();
			if ( $this->suppressed > 0 && null !== $this->logger ) {
				$this->logger->log(
					Event::make( Category::ERROR, 'php.errors_suppressed', Severity::WARNING )
						->summary( sprintf( '%d further PHP errors in this request were not logged individually (cap %d)', $this->suppressed, self::MAX_EVENTS ) )
						->context(
							[
								'cap'     => self::MAX_EVENTS,
								'by_type' => $this->suppressedByType,
							]
						)
						->count( $this->suppressed )
				);
				$this->suppressed       = 0;
				$this->suppressedByType = [];
			}
		} catch ( \Throwable $e ) {
			$this->logger?->recordFailure( 'error_handler.shutdown', $e );
		}
	}

	/**
	 * Map an error type to [action, severity, label]; null for fatal types (FatalHandler).
	 *
	 * @param int $type Error type.
	 * @return array{0: string, 1: Severity, 2: string}|null
	 */
	public static function classify( int $type ): ?array {
		if ( ! isset( self::MAP[ $type ] ) ) {
			return null;
		}
		[ $action, $severity, $label ] = self::MAP[ $type ];
		return [ $action, Severity::from( $severity ), $label ];
	}

	/**
	 * Fingerprint parts for an error (type, relative file, line, normalised message).
	 *
	 * @param string $label    Type label.
	 * @param string $relative Relative file.
	 * @param int    $line     Line.
	 * @param string $message  Relativised message.
	 * @return list<string|int>
	 */
	public static function fingerprintParts( string $label, string $relative, int $line, string $message ): array {
		return [ 'php', $label, $relative, $line, Text::normalize( $message ) ];
	}

	/**
	 * Build the event for an error (pure; no logging).
	 *
	 * @param int                       $errno   Type.
	 * @param string                    $errstr  Message.
	 * @param string                    $errfile Absolute file.
	 * @param int                       $errline Line.
	 * @param PathMapper                $paths   Path mapper.
	 * @param list<array<string,mixed>> $trace   Sanitised trace.
	 */
	public static function buildEvent( int $errno, string $errstr, string $errfile, int $errline, PathMapper $paths, array $trace = [] ): ?Event {
		$map = self::classify( $errno );
		if ( null === $map ) {
			return null;
		}
		[ $action, $severity, $label ] = $map;
		$relative                      = $paths->relative( $errfile );
		$message                       = Text::clip( Text::scrub( $paths->relativeInText( $errstr ) ) );
		$component                     = PathMapper::component( $relative );

		$event = Event::make( Category::ERROR, $action, $severity )
			->object( 'file', self::objectId( $relative ) )
			->summary( sprintf( 'PHP %s in %s:%d: %s', substr( $label, 2 ), $relative, $errline, $message ) )
			->context(
				[
					'type'      => $label,
					'message'   => $message,
					'file'      => $relative,
					'line'      => $errline,
					'component' => $component,
					'trace'     => $trace,
				]
			)
			->fingerprint( ...self::fingerprintParts( $label, $relative, $errline, $message ) );
		return $event;
	}

	/**
	 * Object id for a relative path (≤ 64 chars; long paths keep their tail).
	 *
	 * @param string $relative Relative path.
	 */
	public static function objectId( string $relative ): string {
		return strlen( $relative ) > 64 ? '...' . substr( $relative, -61 ) : $relative;
	}

	/**
	 * Whether the MU loader is active in this request.
	 */
	public static function earlyLoaderActive(): bool {
		return class_exists( self::EARLY_BUFFER_CLASS, false );
	}

	/**
	 * Return and clear the errors buffered by the MU loader.
	 *
	 * @return list<array{type: int, message: string, file: string, line: int, time: float}>
	 */
	public static function drainEarlyBuffer(): array {
		if ( ! self::earlyLoaderActive() ) {
			return [];
		}
		$items = call_user_func( [ self::EARLY_BUFFER_CLASS, 'drain' ] );
		if ( ! is_array( $items ) ) {
			return [];
		}
		$out = [];
		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				$out[] = [
					'type'    => (int) ( $item['type'] ?? 0 ),
					'message' => (string) ( $item['message'] ?? '' ),
					'file'    => (string) ( $item['file'] ?? '' ),
					'line'    => (int) ( $item['line'] ?? 0 ),
					'time'    => (float) ( $item['time'] ?? 0 ),
				];
			}
		}
		return $out;
	}

	/**
	 * Whether a handler is the MU loader's.
	 *
	 * @param mixed $handler Handler returned by set_error_handler().
	 */
	private static function isEarlyHandler( mixed $handler ): bool {
		if ( is_array( $handler ) && isset( $handler[0], $handler[1] ) ) {
			$class = is_object( $handler[0] ) ? get_class( $handler[0] ) : (string) $handler[0];
			return self::EARLY_BUFFER_CLASS === ltrim( $class, '\\' ) && 'handle' === $handler[1];
		}
		return is_string( $handler ) && ltrim( $handler, '\\' ) === self::EARLY_BUFFER_CLASS . '::handle';
	}

	/**
	 * Turn MU loader entries into events and stop the loader from buffering more.
	 */
	private function drainEarly(): void {
		if ( ! self::earlyLoaderActive() ) {
			return;
		}
		$items = self::drainEarlyBuffer();
		// Older copies of the MU loader lack detach()/reset_dropped().
		// @phpstan-ignore-next-line function.alreadyNarrowedType
		if ( method_exists( self::EARLY_BUFFER_CLASS, 'detach' ) ) {
			call_user_func( [ self::EARLY_BUFFER_CLASS, 'detach' ] );
		}
		foreach ( $items as $item ) {
			$this->record( $item['type'], $item['message'], $item['file'], $item['line'], $item['time'] );
		}
		// @phpstan-ignore-next-line function.alreadyNarrowedType
		if ( method_exists( self::EARLY_BUFFER_CLASS, 'dropped' ) ) {
			$dropped = (int) call_user_func( [ self::EARLY_BUFFER_CLASS, 'dropped' ] );
			if ( $dropped > 0 ) {
				$this->suppressed                  += $dropped;
				$this->suppressedByType['early_mu'] = $dropped;
				if ( method_exists( self::EARLY_BUFFER_CLASS, 'reset_dropped' ) ) {
					call_user_func( [ self::EARLY_BUFFER_CLASS, 'reset_dropped' ] );
				}
			}
		}
	}

	/**
	 * Record one error.
	 *
	 * @param int        $errno   Type.
	 * @param string     $errstr  Message.
	 * @param string     $errfile File.
	 * @param int        $errline Line.
	 * @param float|null $time    Occurrence time (early buffer) or null for now.
	 */
	private function record( int $errno, string $errstr, string $errfile, int $errline, ?float $time ): void {
		if ( null === $this->logger ) {
			return;
		}
		$map = self::classify( $errno );
		if ( null === $map ) {
			return;
		}
		$paths = PathMapper::shared();
		if ( $paths->isOwn( $errfile ) ) {
			$this->logger->recordFailure( 'php_error.own:' . basename( $errfile ) . ':' . $errline );
			return;
		}
		if ( DeprecationListener::consumeTrigger( $errno, $paths->relative( $errfile ) ) ) {
			return;
		}
		$relative    = $paths->relative( $errfile );
		$message     = Text::clip( Text::scrub( $paths->relativeInText( $errstr ) ) );
		$fingerprint = substr( hash( 'sha256', implode( "\x1f", array_map( 'strval', self::fingerprintParts( $map[2], $relative, $errline, $message ) ) ) ), 0, 32 );

		if ( isset( $this->seen[ $fingerprint ] ) && null === $this->seen[ $fingerprint ]->getId() ) {
			$pending = $this->seen[ $fingerprint ];
			$pending->count( $pending->getCount() + 1 );
			return;
		}
		if ( $this->events >= self::MAX_EVENTS ) {
			++$this->suppressed;
			$this->suppressedByType[ $map[2] ] = ( $this->suppressedByType[ $map[2] ] ?? 0 ) + 1;
			return;
		}
		++$this->events;
		$trace = null === $time ? Trace::current( $paths, 2 ) : [];
		$event = self::buildEvent( $errno, $errstr, $errfile, $errline, $paths, $trace );
		if ( null === $event ) {
			return;
		}
		if ( null !== $time ) {
			$event->occurredAtMs( (int) floor( $time * 1000 ) )->context( [ 'early' => true ] );
		}
		$this->seen[ $fingerprint ] = $event;
		$this->logger->log( $event );
	}
}
