<?php
/**
 * Cron array inspection.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Health\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers over the `cron` option array (timestamp => hook => sig => event).
 */
final class CronInspector {

	/** One-off events are listed as overdue after this many seconds. */
	public const GRACE = 60;

	/**
	 * Due-but-not-run events, most overdue first.
	 *
	 * `alert` is true for recurring events more than 2× their interval overdue
	 * (the `cron.overdue` threshold).
	 *
	 * @param array<int|string, mixed> $cron Cron array.
	 * @param int                      $now  Unix time.
	 * @return list<array{hook: string, overdue_seconds: int, interval: int|null, schedule: string|null, alert: bool}>
	 */
	public static function overdue( array $cron, int $now ): array {
		$out = [];
		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_numeric( $timestamp ) || ! is_array( $hooks ) ) {
				continue;
			}
			$late = $now - (int) $timestamp;
			if ( $late <= self::GRACE ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				if ( ! is_array( $events ) ) {
					continue;
				}
				foreach ( $events as $event ) {
					$interval = is_array( $event ) && isset( $event['interval'] ) ? (int) $event['interval'] : null;
					$schedule = is_array( $event ) && isset( $event['schedule'] ) && is_string( $event['schedule'] ) ? $event['schedule'] : null;
					$key      = (string) $hook;
					if ( isset( $out[ $key ] ) && $out[ $key ]['overdue_seconds'] >= $late ) {
						continue;
					}
					$out[ $key ] = [
						'hook'            => substr( $key, 0, 191 ),
						'overdue_seconds' => $late,
						'interval'        => $interval,
						'schedule'        => $schedule,
						'alert'           => null !== $interval && $interval > 0 && $late > 2 * $interval,
					];
				}
			}
		}
		$out = array_values( $out );
		usort( $out, static fn( array $a, array $b ): int => $b['overdue_seconds'] <=> $a['overdue_seconds'] );
		return $out;
	}

	/**
	 * Next scheduled events (soonest first).
	 *
	 * @param array<int|string, mixed> $cron  Cron array.
	 * @param int                      $now   Unix time.
	 * @param int                      $limit Max entries.
	 * @return list<array{hook: string, in_seconds: int, schedule: string|null}>
	 */
	public static function upcoming( array $cron, int $now, int $limit = 20 ): array {
		$out = [];
		ksort( $cron, SORT_NUMERIC );
		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_numeric( $timestamp ) || ! is_array( $hooks ) || (int) $timestamp < $now ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				foreach ( (array) $events as $event ) {
					$out[] = [
						'hook'       => substr( (string) $hook, 0, 191 ),
						'in_seconds' => (int) $timestamp - $now,
						'schedule'   => is_array( $event ) && isset( $event['schedule'] ) && is_string( $event['schedule'] ) ? $event['schedule'] : null,
					];
					if ( count( $out ) >= $limit ) {
						return $out;
					}
				}
			}
		}
		return $out;
	}
}
