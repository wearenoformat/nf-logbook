<?php
/**
 * Key/value meta table.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin state in {prefix}nflog_meta: chain head, site salt, failure counters,
 * ack cursor, last pull, schema version, rate-limit buckets.
 *
 * Reads are cached for the request. Methods throw RuntimeException on storage
 * failure; callers inside logging paths catch.
 */
final class Meta {

	public const SCHEMA_VERSION = 'nflog_schema_version';
	public const SALT           = 'site_salt';
	public const CHAIN_HEAD     = 'chain_head';
	public const FAILURES       = 'failures';
	public const LAST_FAILURE   = 'last_failure';
	public const ACK_CURSOR     = 'ack_cursor';
	public const LAST_PULL      = 'last_pull';
	public const LAST_SERVED    = 'last_served_id';
	public const PRUNED_THROUGH = 'pruned_through_id';
	public const INSTALLED_AT   = 'installed_at';

	/**
	 * Request cache.
	 *
	 * @var array<string, string|null>
	 */
	private array $cache = [];

	/**
	 * Raw string value.
	 *
	 * @param string      $key      Key.
	 * @param string|null $fallback Fallback.
	 */
	public function get( string $key, ?string $fallback = null ): ?string {
		if ( ! array_key_exists( $key, $this->cache ) ) {
			$this->cache[ $key ] = Db::getVar(
				Db::prepare( 'SELECT meta_value FROM %i WHERE meta_key = %s', Db::table( 'meta' ), $key )
			);
		}
		return $this->cache[ $key ] ?? $fallback;
	}

	/**
	 * JSON value.
	 *
	 * @param string $key Key.
	 * @return array<string|int, mixed>|null
	 */
	public function getJson( string $key ): ?array {
		$raw = $this->get( $key );
		if ( null === $raw ) {
			return null;
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Integer value.
	 *
	 * @param string $key Key.
	 */
	public function getInt( string $key ): int {
		$raw = $this->get( $key );
		return null === $raw ? 0 : (int) $raw;
	}

	/**
	 * Store a value.
	 *
	 * @param string $key   Key.
	 * @param string $value Value.
	 */
	public function set( string $key, string $value ): void {
		Db::query(
			Db::prepare(
				'REPLACE INTO %i (meta_key, meta_value, updated_at) VALUES (%s, %s, %d)',
				Db::table( 'meta' ),
				$key,
				$value,
				time()
			)
		);
		$this->cache[ $key ] = $value;
	}

	/**
	 * Store JSON.
	 *
	 * @param string                   $key   Key.
	 * @param array<string|int, mixed> $value Value.
	 */
	public function setJson( string $key, array $value ): void {
		$this->set( $key, (string) wp_json_encode( $value ) );
	}

	/**
	 * Insert only when missing.
	 *
	 * @param string $key   Key.
	 * @param string $value Value.
	 */
	public function add( string $key, string $value ): void {
		unset( $this->cache[ $key ] );
		if ( null === $this->get( $key ) ) {
			$this->set( $key, $value );
		}
	}

	/**
	 * Atomic-ish increment; returns the new value.
	 *
	 * @param string $key Key.
	 * @param int    $by  Step.
	 */
	public function increment( string $key, int $by = 1 ): int {
		$affected = Db::query(
			Db::prepare(
				'UPDATE %i SET meta_value = meta_value + %d, updated_at = %d WHERE meta_key = %s',
				Db::table( 'meta' ),
				$by,
				time(),
				$key
			)
		);
		if ( 0 === $affected ) {
			$this->set( $key, (string) $by );
			return $by;
		}
		unset( $this->cache[ $key ] );
		return $this->getInt( $key );
	}

	/**
	 * Delete a key.
	 *
	 * @param string $key Key.
	 */
	public function delete( string $key ): void {
		Db::query( Db::prepare( 'DELETE FROM %i WHERE meta_key = %s', Db::table( 'meta' ), $key ) );
		unset( $this->cache[ $key ] );
	}

	/**
	 * Delete keys with a prefix not updated since a timestamp.
	 *
	 * @param string $prefix Key prefix.
	 * @param int    $before Unix time.
	 */
	public function deletePrefixOlderThan( string $prefix, int $before ): int {
		$this->cache = [];
		return Db::query(
			Db::prepare(
				'DELETE FROM %i WHERE meta_key LIKE %s AND updated_at < %d',
				Db::table( 'meta' ),
				Db::wpdb()->esc_like( $prefix ) . '%',
				$before
			)
		);
	}

	/**
	 * Forget cached values (after another process may have written).
	 */
	public function flushCache(): void {
		$this->cache = [];
	}
}
