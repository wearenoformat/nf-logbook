<?php
/**
 * Database helper.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Storage;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- custom tables; every caller prepares its SQL.

/**
 * Thin, quiet wrapper around $wpdb for the plugin's own tables.
 *
 * Every call suppresses wpdb error output, restores the previous
 * $wpdb->last_error and $wpdb->last_query (so the DB error listener only ever
 * sees the site's own errors and the query that caused them) and throws a RuntimeException on failure, which callers catch.
 */
final class Db {

	public const PREFIX = 'nflog_';

	/**
	 * Global wpdb.
	 */
	public static function wpdb(): \wpdb {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			throw new \RuntimeException( 'wpdb unavailable' );
		}
		return $wpdb;
	}

	/**
	 * Full table name.
	 *
	 * @param string $name events|fingerprints|nonces|meta.
	 */
	public static function table( string $name ): string {
		return self::wpdb()->prefix . self::PREFIX . $name;
	}

	/**
	 * Whether WordPress runs on the SQLite Database Integration drop-in.
	 */
	public static function isSqlite(): bool {
		if ( defined( 'DB_ENGINE' ) && 'sqlite' === constant( 'DB_ENGINE' ) ) {
			return true;
		}
		return str_contains( strtolower( get_class( self::wpdb() ) ), 'sqlite' );
	}

	/**
	 * Run a write/DDL query.
	 *
	 * @param string $sql Prepared SQL.
	 * @return int Affected rows.
	 */
	public static function query( string $sql ): int {
		return (int) self::run( static fn( \wpdb $db ) => $db->query( $sql ) );
	}

	/**
	 * Single value.
	 *
	 * @param string $sql Prepared SQL.
	 */
	public static function getVar( string $sql ): ?string {
		$value = self::run( static fn( \wpdb $db ) => $db->get_var( $sql ) );
		return null === $value ? null : (string) $value;
	}

	/**
	 * Single row.
	 *
	 * @param string $sql Prepared SQL.
	 * @return array<string, mixed>|null
	 */
	public static function getRow( string $sql ): ?array {
		$row = self::run( static fn( \wpdb $db ) => $db->get_row( $sql, ARRAY_A ) );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Rows.
	 *
	 * @param string $sql Prepared SQL.
	 * @return list<array<string, mixed>>
	 */
	public static function getResults( string $sql ): array {
		$rows = self::run( static fn( \wpdb $db ) => $db->get_results( $sql, ARRAY_A ) );
		return is_array( $rows ) ? array_values( $rows ) : [];
	}

	/**
	 * Prepare (thin passthrough so callers do not need the global).
	 *
	 * @param string $sql  SQL with placeholders.
	 * @param mixed  ...$args Arguments.
	 */
	public static function prepare( string $sql, mixed ...$args ): string {
		$prepared = self::wpdb()->prepare( $sql, ...$args ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- passthrough.
		return is_string( $prepared ) ? $prepared : '';
	}

	/**
	 * Current wpdb error text.
	 *
	 * @param \wpdb $wpdb Database.
	 */
	private static function lastError( \wpdb $wpdb ): string {
		return (string) $wpdb->last_error;
	}

	/**
	 * Run a callback against wpdb quietly.
	 *
	 * @param callable(\wpdb): mixed $callback Callback.
	 * @throws \RuntimeException On database error.
	 */
	private static function run( callable $callback ): mixed {
		$wpdb          = self::wpdb();
		$previousError = $wpdb->last_error;
		$previousQuery = $wpdb->last_query;
		$suppress      = $wpdb->suppress_errors( true );
		$show          = $wpdb->hide_errors();
		try {
			$wpdb->last_error = '';
			$result           = $callback( $wpdb );
			$error            = self::lastError( $wpdb );
		} finally {
			$wpdb->suppress_errors( $suppress );
			if ( $show ) {
				$wpdb->show_errors();
			}
		}
		$wpdb->last_error = $previousError;
		$wpdb->last_query = $previousQuery;
		if ( '' !== $error || false === $result ) {
			throw new \RuntimeException( 'Database error in Noformat Logger storage' . ( '' !== $error ? ': ' . substr( $error, 0, 200 ) : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- never printed; caught by the logger.
		}
		return $result;
	}
}
