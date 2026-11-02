<?php
/**
 * Plugin Name: Noformat Logger (early error loader)
 * Description: Optional must-use loader for Noformat Logger. Buffers PHP errors raised before regular plugins load; the plugin consumes the buffer. Safe without the plugin.
 * Version:     0.2.0
 * License:     GPL-2.0-or-later
 * Text Domain: noformat-logger
 *
 * Copy this file to wp-content/mu-plugins/ (or run `wp nflog install-mu-loader`).
 * It has no dependency on the plugin and uses only PHP 7-compatible syntax.
 *
 * @package Noformat\Logger
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Noformat_Logger_Early_Errors', false ) ) {

	/**
	 * Static buffer of early PHP errors (max 100), chained to the previous handler.
	 */
	final class Noformat_Logger_Early_Errors {

		const MAX = 100;

		/**
		 * Buffered errors.
		 *
		 * @var array<int, array<string, mixed>>
		 */
		private static $buffer = array();

		/**
		 * Previous error handler.
		 *
		 * @var callable|null
		 */
		private static $previous = null;

		/**
		 * Installed flag.
		 *
		 * @var bool
		 */
		private static $installed = false;

		/**
		 * Detached: the plugin's handler took over; only chain from now on.
		 *
		 * @var bool
		 */
		private static $detached = false;

		/**
		 * Errors dropped after the buffer was full.
		 *
		 * @var int
		 */
		private static $dropped = 0;

		/**
		 * Install the handler once.
		 *
		 * @return void
		 */
		public static function install() {
			if ( self::$installed ) {
				return;
			}
			self::$installed = true;
			self::$previous  = set_error_handler( array( __CLASS__, 'handle' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- intended.
		}

		/**
		 * Error handler: buffer (honouring error_reporting) and chain.
		 *
		 * @param int    $errno   Level.
		 * @param string $errstr  Message.
		 * @param string $errfile File.
		 * @param int    $errline Line.
		 * @return bool
		 */
		public static function handle( $errno, $errstr, $errfile = '', $errline = 0 ) {
			if ( ! self::$detached && ( error_reporting() & $errno ) ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- read only.
				if ( count( self::$buffer ) < self::MAX ) {
					self::$buffer[] = array(
						'type'    => (int) $errno,
						'message' => substr( (string) $errstr, 0, 1024 ),
						'file'    => (string) $errfile,
						'line'    => (int) $errline,
						'time'    => microtime( true ),
					);
				} else {
					++self::$dropped;
				}
			}
			if ( null !== self::$previous ) {
				return (bool) call_user_func( self::$previous, $errno, $errstr, $errfile, $errline );
			}
			return false;
		}

		/**
		 * Return and clear the buffer.
		 *
		 * @return array<int, array<string, mixed>>
		 */
		public static function drain() {
			$buffer       = self::$buffer;
			self::$buffer = array();
			return $buffer;
		}

		/**
		 * Errors dropped because the buffer was full.
		 *
		 * @return int
		 */
		public static function dropped() {
			return self::$dropped;
		}

		/**
		 * Reset the dropped counter (after the plugin reported it).
		 *
		 * @return void
		 */
		public static function reset_dropped() {
			self::$dropped = 0;
		}

		/**
		 * Stop buffering: the plugin's own handler is installed and chains to
		 * previous_handler() directly. If another handler still chains into this
		 * one, errors pass through unbuffered, so nothing is reported twice.
		 *
		 * @return void
		 */
		public static function detach() {
			self::$detached = true;
			self::$buffer   = array();
		}

		/**
		 * Whether detach() was called.
		 *
		 * @return bool
		 */
		public static function is_detached() {
			return self::$detached;
		}

		/**
		 * The handler that was active before this one (for re-chaining).
		 *
		 * @return callable|null
		 */
		public static function previous_handler() {
			return self::$previous;
		}
	}

	Noformat_Logger_Early_Errors::install();
}
