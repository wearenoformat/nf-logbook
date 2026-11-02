<?php
/**
 * Burst counter storage contract.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Security\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Small expiring key/value store for BurstDetector state.
 */
interface BurstStore {

	/**
	 * Stored state or null.
	 *
	 * @param string $key Key.
	 * @return array<string, mixed>|null
	 */
	public function get( string $key ): ?array;

	/**
	 * Store state.
	 *
	 * @param string               $key   Key.
	 * @param array<string, mixed> $value State.
	 * @param int                  $ttl   Seconds.
	 */
	public function set( string $key, array $value, int $ttl ): void;
}
