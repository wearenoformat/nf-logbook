<?php
/**
 * Stack trace sanitising.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Errors\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reduces debug_backtrace()/Throwable::getTrace() frames to function, class,
 * relative file and line. Arguments are never kept.
 */
final class Trace {

	public const MAX_FRAMES = 10;

	/**
	 * Sanitise frames.
	 *
	 * @param array<int, array<string, mixed>> $frames Frames.
	 * @param PathMapper                       $paths  Path mapper.
	 * @param int                              $max    Max frames.
	 * @return list<array{function: string, class?: string, file?: string, line?: int}>
	 */
	public static function sanitize( array $frames, PathMapper $paths, int $max = self::MAX_FRAMES ): array {
		$out = [];
		foreach ( $frames as $frame ) {
			if ( count( $out ) >= $max ) {
				break;
			}
			if ( ! is_array( $frame ) ) {
				continue;
			}
			// PHP 8.4 names closures "{closure:/abs/path/file.php:12}".
			$item = [ 'function' => substr( $paths->relativeInText( (string) ( $frame['function'] ?? '' ) ), 0, 128 ) ];
			if ( isset( $frame['class'] ) && is_string( $frame['class'] ) ) {
				$item['class'] = substr( $frame['class'], 0, 128 );
			}
			if ( isset( $frame['file'] ) && is_string( $frame['file'] ) ) {
				$item['file'] = $paths->relative( $frame['file'] );
			}
			if ( isset( $frame['line'] ) ) {
				$item['line'] = (int) $frame['line'];
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Current backtrace without arguments, dropping frames inside the logger itself.
	 *
	 * @param PathMapper $paths Path mapper.
	 * @param int        $skip  Leading frames to drop.
	 * @return list<array{function: string, class?: string, file?: string, line?: int}>
	 */
	public static function current( PathMapper $paths, int $skip = 0 ): array {
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, self::MAX_FRAMES + $skip + 6 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- sanitised trace is the point.
		$frames = array_slice( $frames, $skip );
		$frames = array_values(
			array_filter(
				$frames,
				static fn( array $f ): bool => ! ( isset( $f['class'] ) && is_string( $f['class'] ) && str_starts_with( $f['class'], 'Noformat\\Logger\\' ) )
			)
		);
		return self::sanitize( $frames, $paths );
	}
}
