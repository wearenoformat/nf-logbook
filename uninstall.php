<?php
/**
 * Uninstall: drops tables, options, user meta and transients only when
 * "delete data on uninstall" is set. Cron events are always unscheduled.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'NOFORMAT_LOGGER_DIR' ) ) {
	define( 'NOFORMAT_LOGGER_DIR', plugin_dir_path( __FILE__ ) );
}
// The plugin has no runtime dependencies: the bundled autoloader is always sufficient.
require_once NOFORMAT_LOGGER_DIR . 'src/Autoloader.php';
\Noformat\Logger\Autoloader::register( NOFORMAT_LOGGER_DIR . 'src/' );

\Noformat\Logger\Cron\Scheduler::unschedule();
// Cached release information of the updater (a cache, removed whatever the data setting).
delete_transient( \Noformat\Logger\Update\ReleaseClient::TRANSIENT );

$nflog_config = \Noformat\Logger\Config::fromEnvironment();
if ( $nflog_config->bool( 'delete_data_on_uninstall' ) ) {
	try {
		\Noformat\Logger\Storage\Schema::drop();
	} catch ( \Throwable $nflog_error ) {
		unset( $nflog_error );
	}
	foreach ( array( \Noformat\Logger\Config::OPTION, \Noformat\Logger\Rest\KeyStore::OPTION, \Noformat\Logger\Logger::FALLBACK_FAILURE_OPTION, \Noformat\Logger\Storage\Schema::CACHE_OPTION ) as $nflog_option ) {
		delete_option( $nflog_option );
	}
	delete_transient( \Noformat\Logger\Storage\Schema::THROTTLE_TRANSIENT );
	// Known-client hashes of administrators (all users).
	delete_metadata( 'user', 0, \Noformat\Logger\Security\Support\KnownClients::META_KEY, '', true );
	// Event viewer "per page" screen option (a user option, stored with the blog prefix).
	global $wpdb;
	if ( isset( $wpdb ) && $wpdb instanceof \wpdb ) {
		delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . \Noformat\Logger\Admin\ContentLogPage::PER_PAGE_OPT, '', true );
	}
	// Failed-login burst state. With a persistent object cache the transients are not in
	// the options table and simply expire (TTL ≤ the burst window).
	global $wpdb;
	if ( isset( $wpdb ) && $wpdb instanceof \wpdb ) {
		foreach ( array( '_transient_', '_transient_timeout_' ) as $nflog_prefix ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup on uninstall.
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $nflog_prefix . \Noformat\Logger\Security\Support\TransientBurstStore::PREFIX ) . '%'
				)
			);
		}
	}
}
