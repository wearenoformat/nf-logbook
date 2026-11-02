<?php
/**
 * Plugin Name:       Noformat Logger
 * Plugin URI:        https://noformat.com
 * Description:       Tamper-evident, privacy-preserving log of content changes and error, security and stability signals.
 * Version:           1.1.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Tested up to:      7.1
 * Author:            Noformat
 * Author URI:        https://noformat.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       noformat-logger
 * Domain Path:       /languages
 * Update URI:        https://github.com/wearenoformat/wp-noformat-logger
 *
 * @package Noformat\Logger
 */

// This file must stay parseable by old PHP versions so the requirement notice can be shown.

defined( 'ABSPATH' ) || exit;

if ( defined( 'NOFORMAT_LOGGER_VERSION' ) ) {
	return;
}

define( 'NOFORMAT_LOGGER_VERSION', '1.1.1' );
define( 'NOFORMAT_LOGGER_FILE', __FILE__ );
define( 'NOFORMAT_LOGGER_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Unmet requirements as translatable messages.
 *
 * @return string[]
 */
function nflog_requirement_errors() {
	global $wp_version;
	$errors = array();
	if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
		/* translators: %s: PHP version */
		$errors[] = sprintf( __( 'Noformat Logger requires PHP 8.1 or newer (running %s). The plugin is inactive.', 'noformat-logger' ), PHP_VERSION );
	}
	if ( isset( $wp_version ) && version_compare( $wp_version, '6.4', '<' ) ) {
		/* translators: %s: WordPress version */
		$errors[] = sprintf( __( 'Noformat Logger requires WordPress 6.4 or newer (running %s). The plugin is inactive.', 'noformat-logger' ), $wp_version );
	}
	if ( ! function_exists( 'hash_hmac' ) || ! function_exists( 'random_bytes' ) ) {
		$errors[] = __( 'Noformat Logger requires the PHP hash extension and random_bytes(). The plugin is inactive.', 'noformat-logger' );
	}
	return $errors;
}

/**
 * Admin notice listing unmet requirements.
 *
 * @return void
 */
function nflog_requirement_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	foreach ( nflog_requirement_errors() as $nflog_error ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $nflog_error ) );
	}
}

if ( array() !== nflog_requirement_errors() ) {
	add_action( 'admin_notices', 'nflog_requirement_notice' );
	return;
}

/**
 * Whether vendor/ holds a production (no-dev) Composer install. A development
 * vendor/ (PHPUnit, Mockery, …) defines global helper functions and must never be
 * loaded into a WordPress request, so the bundled PSR-4 autoloader is used instead.
 *
 * @param string $dir Plugin directory with trailing slash.
 * @return bool
 */
function nflog_use_vendor_autoload( $dir ) {
	if ( ! is_readable( $dir . 'vendor/autoload.php' ) || ! is_readable( $dir . 'vendor/composer/installed.php' ) ) {
		return false;
	}
	$installed = include $dir . 'vendor/composer/installed.php';
	return is_array( $installed ) && isset( $installed['root']['dev'] ) && false === $installed['root']['dev'];
}

if ( nflog_use_vendor_autoload( NOFORMAT_LOGGER_DIR ) ) {
	require_once NOFORMAT_LOGGER_DIR . 'vendor/autoload.php';
} else {
	require_once NOFORMAT_LOGGER_DIR . 'src/Autoloader.php';
	\Noformat\Logger\Autoloader::register( NOFORMAT_LOGGER_DIR . 'src/' );
}

register_activation_hook( __FILE__, array( '\Noformat\Logger\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Noformat\Logger\Plugin', 'deactivate' ) );

\Noformat\Logger\Plugin::boot();
