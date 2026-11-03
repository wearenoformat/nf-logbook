<?php
/**
 * Schema installation and migrations.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Storage;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- schema management of the plugin's own tables.

/**
 * Creates and upgrades the plugin tables with dbDelta.
 *
 * The schema version lives in the meta table under `nflog_schema_version`.
 * Migrations are keyed by target version in migrations(); dbDelta handles
 * additive column/index changes, explicit callbacks handle the rest.
 */
final class Schema {

	public const VERSION = 2;

	/** Table names without prefix. */
	public const TABLES = [ 'events', 'fingerprints', 'nonces', 'meta', 'resolutions' ];

	/** Autoloaded mirror of the installed version (the meta row stays the source of truth). */
	public const CACHE_OPTION = 'nflog_schema_cache';

	/** Transient that throttles install attempts while the schema is behind or tables are missing. */
	public const THROTTLE_TRANSIENT = 'nflog_schema_attempt';

	/**
	 * CREATE TABLE statements in dbDelta format.
	 *
	 * @return array<string, string>
	 */
	public static function definitions(): array {
		$wpdb    = Db::wpdb();
		$charset = $wpdb->get_charset_collate();
		$events  = Db::table( 'events' );
		$fps     = Db::table( 'fingerprints' );
		$nonces  = Db::table( 'nonces' );
		$meta    = Db::table( 'meta' );
		$res     = Db::table( 'resolutions' );

		return [
			'events'       => "CREATE TABLE {$events} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  occurred_at datetime NOT NULL,
  occurred_ms bigint(20) unsigned NOT NULL DEFAULT 0,
  severity tinyint(3) unsigned NOT NULL DEFAULT 4,
  category varchar(32) NOT NULL DEFAULT '',
  action varchar(64) NOT NULL DEFAULT '',
  object_type varchar(32) NULL,
  object_id varchar(64) NULL,
  object_subtype varchar(64) NULL,
  object_label_hash varchar(64) NULL,
  actor_type varchar(16) NOT NULL DEFAULT 'system',
  actor_id bigint(20) unsigned NULL,
  actor_role varchar(64) NULL,
  request_id char(32) NOT NULL DEFAULT '',
  source varchar(16) NOT NULL DEFAULT '',
  route varchar(255) NULL,
  method varchar(8) NULL,
  ip_hash char(32) NULL,
  ua_hash char(32) NULL,
  summary varchar(255) NOT NULL DEFAULT '',
  changes longtext NULL,
  context longtext NULL,
  fingerprint char(32) NULL,
  count int(10) unsigned NOT NULL DEFAULT 1,
  site_id bigint(20) unsigned NOT NULL DEFAULT 1,
  prev_hash char(64) NOT NULL DEFAULT '',
  hash char(64) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY severity_id (severity,id),
  KEY category_id (category,id),
  KEY request_id (request_id),
  KEY fingerprint (fingerprint),
  KEY occurred_ms (occurred_ms),
  KEY hash (hash)
) {$charset};",
			'fingerprints' => "CREATE TABLE {$fps} (
  fingerprint char(32) NOT NULL,
  last_event_id bigint(20) unsigned NOT NULL DEFAULT 0,
  window_start bigint(20) unsigned NOT NULL DEFAULT 0,
  count int(10) unsigned NOT NULL DEFAULT 0,
  last_seen bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (fingerprint),
  KEY last_seen (last_seen)
) {$charset};",
			'nonces'       => "CREATE TABLE {$nonces} (
  nonce_hash char(64) NOT NULL,
  expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (nonce_hash),
  KEY expires_at (expires_at)
) {$charset};",
			'meta'         => "CREATE TABLE {$meta} (
  meta_key varchar(191) NOT NULL,
  meta_value longtext NULL,
  updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (meta_key)
) {$charset};",
			'resolutions'  => "CREATE TABLE {$res} (
  issue_key varchar(64) NOT NULL,
  fingerprint char(32) NULL,
  event_id bigint(20) unsigned NULL,
  through_id bigint(20) unsigned NOT NULL DEFAULT 0,
  resolved_at bigint(20) unsigned NOT NULL DEFAULT 0,
  resolved_by bigint(20) unsigned NULL,
  PRIMARY KEY  (issue_key),
  KEY fingerprint (fingerprint),
  KEY event_id (event_id),
  KEY through_id (through_id)
) {$charset};",
		];
	}

	/**
	 * Create or update all tables and seed meta rows. Safe to call repeatedly.
	 */
	public static function install(): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$wpdb     = Db::wpdb();
		$suppress = $wpdb->suppress_errors( true );
		try {
			dbDelta( array_values( self::definitions() ) );
		} finally {
			$wpdb->suppress_errors( $suppress );
		}

		$meta = new Meta();
		$meta->add( Meta::SALT, bin2hex( random_bytes( 32 ) ) );
		$meta->add(
			Meta::CHAIN_HEAD,
			(string) wp_json_encode(
				[
					'id'   => 0,
					'hash' => HashChain::GENESIS,
				]
			)
		);
		$meta->add( Meta::INSTALLED_AT, (string) time() );

		$from = $meta->getInt( Meta::SCHEMA_VERSION );
		foreach ( self::migrations() as $version => $migration ) {
			if ( $version > $from ) {
				$migration();
			}
		}
		$meta->set( Meta::SCHEMA_VERSION, (string) self::VERSION );
		if ( function_exists( 'update_option' ) ) {
			update_option( self::CACHE_OPTION, self::VERSION, true );
		}
	}

	/**
	 * Versioned migrations beyond what dbDelta does. Version => callback.
	 *
	 * @return array<int, callable(): void>
	 */
	public static function migrations(): array {
		return [
			1 => static function (): void {
				// Initial schema: nothing beyond dbDelta.
			},
			2 => static function (): void {
				// 1.3.0: {prefix}nflog_resolutions (resolved issues, see Storage\Resolutions); created by dbDelta.
			},
		];
	}

	/**
	 * Installed schema version (0 when tables are missing).
	 */
	public static function installedVersion(): int {
		try {
			return ( new Meta() )->getInt( Meta::SCHEMA_VERSION );
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Install or migrate when the stored version differs.
	 *
	 * The autoloaded CACHE_OPTION answers the common case without a query; when
	 * it is behind, the meta table is checked and dbDelta runs at most once per
	 * 10 minutes (a site whose tables cannot be created does not pay dbDelta on
	 * every admin page).
	 */
	public static function maybeUpgrade(): void {
		if ( (int) get_option( self::CACHE_OPTION, 0 ) >= self::VERSION ) {
			return;
		}
		if ( false !== get_transient( self::THROTTLE_TRANSIENT ) ) {
			return;
		}
		set_transient( self::THROTTLE_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS );
		if ( self::installedVersion() < self::VERSION ) {
			self::install();
			return;
		}
		update_option( self::CACHE_OPTION, self::VERSION, true );
	}

	/**
	 * Whether all tables exist.
	 */
	public static function exists(): bool {
		try {
			foreach ( self::TABLES as $table ) {
				$name  = Db::table( $table );
				$found = Db::getVar( Db::prepare( 'SHOW TABLES LIKE %s', Db::wpdb()->esc_like( $name ) ) );
				if ( $found !== $name ) {
					return false;
				}
			}
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Drop all tables (uninstall only).
	 */
	public static function drop(): void {
		foreach ( self::TABLES as $table ) {
			Db::query( Db::prepare( 'DROP TABLE IF EXISTS %i', Db::table( $table ) ) );
		}
	}
}
