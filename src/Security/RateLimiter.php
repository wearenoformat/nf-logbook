<?php
/**
 * Rate limiter.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Security;

use Noformat\Logger\Rest\Contracts\RateLimit;
use Noformat\Logger\Storage\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed one-minute windows stored in the meta table ("rl:<bucket>:<minute>").
 * Old buckets are removed by the hourly cleanup.
 */
final class RateLimiter implements RateLimit {

	public const PREFIX = 'rl:';

	/**
	 * Constructor.
	 *
	 * @param Meta $meta Meta store.
	 */
	public function __construct( private Meta $meta ) {
	}

	/**
	 * Count a hit.
	 *
	 * @param string $bucket Bucket.
	 * @param int    $limit  Hits per minute (0 disables).
	 * @param int    $now    Unix time.
	 */
	public function hit( string $bucket, int $limit, int $now ): bool {
		if ( $limit <= 0 ) {
			return true;
		}
		$key   = self::PREFIX . substr( $bucket, 0, 120 ) . ':' . intdiv( $now, 60 );
		$count = $this->meta->increment( $key );
		return $count <= $limit;
	}

	/**
	 * Remove buckets older than two minutes.
	 *
	 * @param int $now Unix time.
	 */
	public function cleanup( int $now ): int {
		return $this->meta->deletePrefixOlderThan( self::PREFIX, $now - 120 );
	}
}
