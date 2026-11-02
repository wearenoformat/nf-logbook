<?php
/**
 * Cron scheduling.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Cron;

use Noformat\Logger\Category;
use Noformat\Logger\Event;
use Noformat\Logger\Plugin;
use Noformat\Logger\Severity;
use Noformat\Logger\Storage\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Background jobs: daily prune, hourly nonce/rate-limit cleanup, weekly
 * integrity check, daily config audit, 15-minute health cache.
 */
final class Scheduler {

	public const PRUNE        = 'noformat_logger/cron/prune';
	public const CLEANUP      = 'noformat_logger/cron/nonce_cleanup';
	public const INTEGRITY    = 'noformat_logger/cron/integrity';
	public const CONFIG_AUDIT = 'noformat_logger/cron/config_audit';
	public const HEALTH_CACHE = 'noformat_logger/cron/health_cache';

	/** Hook => recurrence. */
	public const JOBS = [
		self::PRUNE        => 'daily',
		self::CLEANUP      => 'hourly',
		self::INTEGRITY    => 'nflog_weekly',
		self::CONFIG_AUDIT => 'daily',
		self::HEALTH_CACHE => 'nflog_15min',
	];

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {
	}

	/**
	 * Register schedules and job callbacks.
	 */
	public function hooks(): void {
		add_filter( 'cron_schedules', [ self::class, 'schedules' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 15 minutes is intended.
		add_action( self::PRUNE, [ $this, 'prune' ] );
		add_action( self::CLEANUP, [ $this, 'cleanup' ] );
		add_action( self::INTEGRITY, [ $this, 'integrity' ] );
		add_action( self::CONFIG_AUDIT, [ $this, 'configAudit' ] );
		add_action( self::HEALTH_CACHE, [ $this, 'healthCache' ] );
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) ) {
			add_action( 'init', [ self::class, 'schedule' ] );
		}
	}

	/**
	 * Custom recurrences.
	 *
	 * @param mixed $schedules Schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function schedules( mixed $schedules ): array {
		$schedules                 = is_array( $schedules ) ? $schedules : [];
		$schedules['nflog_15min']  = [
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => 'Every 15 minutes (Noformat Logbook)',
		];
		$schedules['nflog_weekly'] = [
			'interval' => WEEK_IN_SECONDS,
			'display'  => 'Weekly (Noformat Logbook)',
		];
		return $schedules;
	}

	/**
	 * Schedule missing jobs.
	 */
	public static function schedule(): void {
		add_filter( 'cron_schedules', [ self::class, 'schedules' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- see hooks().
		$offset = 60;
		foreach ( self::JOBS as $hook => $recurrence ) {
			if ( false === wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + $offset, $recurrence, $hook );
			}
			$offset += 60;
		}
	}

	/**
	 * Remove all jobs.
	 */
	public static function unschedule(): void {
		foreach ( array_keys( self::JOBS ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Next run per job.
	 *
	 * @return array<string, int|null>
	 */
	public static function nextRuns(): array {
		$out = [];
		foreach ( array_keys( self::JOBS ) as $hook ) {
			$next         = wp_next_scheduled( $hook );
			$out[ $hook ] = false === $next ? null : (int) $next;
		}
		return $out;
	}

	/**
	 * Daily prune (cron callback).
	 */
	public function prune(): void {
		$this->runPrune();
	}

	/**
	 * Prune with the configured rules.
	 *
	 * @return array<string, mixed>|null
	 */
	public function runPrune(): ?array {
		$logger = $this->plugin->logger();
		return $logger->guard(
			'cron.prune',
			function () use ( $logger ) {
				$config = $this->plugin->config();
				$result = $this->plugin->repository()->prune(
					$config->int( 'retention_days' ),
					$config->int( 'hard_max_days' ),
					$config->int( 'max_rows' ),
					$this->plugin->meta()->getInt( Meta::ACK_CURSOR )
				);
				if ( $result['rows'] > 0 ) {
					$logger->log(
						Event::make( Category::SYSTEM, 'logger.pruned', Severity::INFO )
							->summary( sprintf( 'Pruned %d events through #%d', $result['rows'], $result['boundary_id'] ) )
							->context( $result )
					);
				}
				return $result;
			}
		);
	}

	/**
	 * Hourly cleanup of nonces, rate-limit buckets and stale fingerprints.
	 */
	public function cleanup(): void {
		$this->plugin->logger()->guard(
			'cron.cleanup',
			function (): void {
				$now = time();
				$this->plugin->nonces()->cleanup( $now );
				$this->plugin->limiter()->cleanup( $now );
				$this->plugin->fingerprints()->cleanup( $now - 7 * DAY_IN_SECONDS );
			}
		);
	}

	/**
	 * Weekly integrity check.
	 */
	public function integrity(): void {
		$this->plugin->logger()->guard( 'cron.integrity', fn() => $this->plugin->integrity()->run() );
	}

	/**
	 * Daily config audit.
	 */
	public function configAudit(): void {
		$this->plugin->logger()->guard( 'cron.config_audit', fn() => $this->plugin->configAudit()->run() );
	}

	/**
	 * Health cache refresh.
	 */
	public function healthCache(): void {
		$this->plugin->logger()->guard( 'cron.health_cache', fn() => $this->plugin->health()->refreshCache() );
	}
}
