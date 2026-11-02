<?php
/**
 * PSR-4 fallback autoloader.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Maps Noformat\Logger\Foo\Bar to src/Foo/Bar.php when vendor/ is absent (PLAN D9).
 */
final class Autoloader {

	public const PREFIX = 'Noformat\\Logger\\';

	private static bool $registered = false;

	private static string $baseDir = '';

	/**
	 * Register once.
	 *
	 * @param string $baseDir Absolute path of src/ with trailing slash.
	 */
	public static function register( string $baseDir ): void {
		if ( self::$registered ) {
			return;
		}
		self::$baseDir    = rtrim( $baseDir, '/\\' ) . '/';
		self::$registered = spl_autoload_register( [ self::class, 'load' ] );
	}

	/**
	 * Load a class.
	 *
	 * @param string $class_name Class.
	 */
	public static function load( string $class_name ): void {
		if ( ! str_starts_with( $class_name, self::PREFIX ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( self::PREFIX ) );
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\\\\]+$/', $relative ) ) {
			return;
		}
		$file = self::$baseDir . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
