<?php
/**
 * Transient-backed burst store.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Security\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Stores burst state in transients (object cache when present, options table
 * otherwise; `_transient_*` is on the OptionListener ignore list). Keys are
 * derived from keyed hashes only.
 */
final class TransientBurstStore implements BurstStore {

	public const PREFIX = 'nflog_burst_';

	/**
	 * Stored state or null.
	 *
	 * @param string $key Key.
	 * @return array<string, mixed>|null
	 */
	public function get( string $key ): ?array {
		$value = get_transient( self::name( $key ) );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Store state.
	 *
	 * @param string               $key   Key.
	 * @param array<string, mixed> $value State.
	 * @param int                  $ttl   Seconds.
	 */
	public function set( string $key, array $value, int $ttl ): void {
		set_transient( self::name( $key ), $value, max( 60, $ttl ) );
	}

	/**
	 * Transient name (≤ 172 chars).
	 *
	 * @param string $key Key.
	 */
	private static function name( string $key ): string {
		return self::PREFIX . substr( (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( $key ) ), 0, 64 );
	}
}
