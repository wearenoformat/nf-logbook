<?php
/**
 * Listener contract.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * A listener subscribes to WordPress hooks for one domain and turns them into
 * events. Listeners are registered from Plugin::LISTENERS; each one is guarded
 * (a failing register() never affects the others) and can be disabled per site.
 *
 * Hook callbacks must be cheap and must not throw: wrap bodies with
 * Logger::guard() or a try/catch that calls Logger::recordFailure().
 */
interface Listener {

	/**
	 * Whether the listener applies to this site (e.g. ACF installed).
	 */
	public static function isAvailable(): bool;

	/**
	 * Add hooks.
	 *
	 * @param Logger $logger Logger.
	 * @param Config $config Config.
	 */
	public function register( Logger $logger, Config $config ): void;
}
