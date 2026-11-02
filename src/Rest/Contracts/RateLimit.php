<?php
/**
 * RateLimit contract.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Rest\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed-window rate limiter.
 */
interface RateLimit {

	/**
	 * Count a hit; false when the bucket is over the limit.
	 *
	 * @param string $bucket Bucket name.
	 * @param int    $limit  Hits per minute.
	 * @param int    $now    Unix time.
	 */
	public function hit( string $bucket, int $limit, int $now ): bool;
}
