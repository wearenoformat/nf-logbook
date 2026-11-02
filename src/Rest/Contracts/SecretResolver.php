<?php
/**
 * SecretResolver contract.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Rest\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves API secrets by key id.
 */
interface SecretResolver {

	/**
	 * Secret for a key id, or null when unknown/expired.
	 *
	 * @param string $keyId Key id.
	 * @param int    $now   Unix time.
	 */
	public function secretFor( string $keyId, int $now ): ?string;
}
