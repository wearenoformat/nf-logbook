<?php
/**
 * NonceRegistry contract.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Rest\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Single-use nonce registry.
 */
interface NonceRegistry {

	/**
	 * Consume a nonce; false when it was already used.
	 *
	 * @param string $keyId Key id.
	 * @param string $nonce Nonce (32 hex).
	 * @param int    $now   Unix time.
	 */
	public function consume( string $keyId, string $nonce, int $now ): bool;
}
