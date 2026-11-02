<?php
/**
 * Rolling-window burst detection.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Security\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Counts hits per key in a rolling window and reports a burst at most once per
 * window per key (PLAN §4: ≥ 10 failed logins in 5 minutes per ip hash).
 *
 * State per key: {"t": [unix times inside the window], "s": unix time of the last burst report}.
 */
final class BurstDetector {

	public const DEFAULT_THRESHOLD = 10;
	public const DEFAULT_WINDOW    = 300;
	public const MAX_SAMPLES       = 200;

	/**
	 * Constructor.
	 *
	 * @param BurstStore $store     State store.
	 * @param int        $threshold Hits within the window that make a burst.
	 * @param int        $window    Window in seconds.
	 */
	public function __construct( private BurstStore $store, private int $threshold = self::DEFAULT_THRESHOLD, private int $window = self::DEFAULT_WINDOW ) {
		$this->threshold = max( 1, $this->threshold );
		$this->window    = max( 1, $this->window );
	}

	/**
	 * Record one hit. Returns the hit count inside the window when a burst should
	 * be reported now, null otherwise.
	 *
	 * @param string $key Key (e.g. "login:<ip hash>").
	 * @param int    $now Unix time.
	 */
	public function hit( string $key, int $now ): ?int {
		$state = $this->store->get( $key ) ?? [];
		$times = [];
		foreach ( is_array( $state['t'] ?? null ) ? $state['t'] : [] as $time ) {
			$time = (int) $time;
			if ( $time > $now - $this->window && $time <= $now ) {
				$times[] = $time;
			}
		}
		$times[] = $now;
		if ( count( $times ) > self::MAX_SAMPLES ) {
			$times = array_slice( $times, -self::MAX_SAMPLES );
		}
		$sent  = (int) ( $state['s'] ?? 0 );
		$burst = null;
		if ( count( $times ) >= $this->threshold && ( $sent <= 0 || $now - $sent >= $this->window ) ) {
			$burst = count( $times );
			$sent  = $now;
		}
		$this->store->set(
			$key,
			[
				't' => $times,
				's' => $sent,
			],
			$this->window * 2
		);
		return $burst;
	}

	/** Threshold. */
	public function threshold(): int {
		return $this->threshold;
	}

	/** Window in seconds. */
	public function window(): int {
		return $this->window;
	}
}
