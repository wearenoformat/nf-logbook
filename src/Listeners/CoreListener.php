<?php
/**
 * CoreListener.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Listeners;

use Noformat\Logger\Category;
use Noformat\Logger\Config;
use Noformat\Logger\Event;
use Noformat\Logger\Listener;
use Noformat\Logger\Logger;
use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Core updates: _core_updated_successfully and automatic_updates_complete (PLAN §4).
 *
 * The version running when the listener registers is the version being updated
 * from; _core_updated_successfully receives the new one.
 */
final class CoreListener implements Listener {

	private Logger $logger;

	private ?string $runningVersion = null;

	/**
	 * Availability.
	 */
	public static function isAvailable(): bool {
		return true;
	}

	/**
	 * Add hooks.
	 *
	 * @param Logger $logger Logger.
	 * @param Config $config Config.
	 */
	public function register( Logger $logger, Config $config ): void {
		unset( $config );
		$this->logger         = $logger;
		$this->runningVersion = isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : null;
		add_action( '_core_updated_successfully', [ $this, 'onCoreUpdated' ], 10, 1 );
		add_action( 'automatic_updates_complete', [ $this, 'onAutoUpdatesComplete' ], 10, 1 );
	}

	/**
	 * Core updated.
	 *
	 * @param mixed $newVersion New version.
	 */
	public function onCoreUpdated( mixed $newVersion ): void {
		$this->logger->guard(
			'core.updated',
			function () use ( $newVersion ): void {
				$new = substr( (string) $newVersion, 0, 32 );
				$old = $this->runningVersion;
				$this->logger->log(
					Event::make( Category::CORE, 'core.updated', Severity::NOTICE )
						->object( 'core', 'WordPress' )
						->summary( sprintf( 'WordPress core updated from %s to %s', $old ?? '(unknown)', $new ) )
						->change( 'version', $old, $new )
						->context( [ 'auto_update' => wp_doing_cron() || did_action( 'pre_auto_update' ) > 0 ] )
				);
			}
		);
	}

	/**
	 * Background updates finished.
	 *
	 * @param mixed $results Results keyed by type (core, plugin, theme, translation).
	 */
	public function onAutoUpdatesComplete( mixed $results ): void {
		$this->logger->guard(
			'core.auto_updates_completed',
			function () use ( $results ): void {
				$summary = self::summarise( is_array( $results ) ? $results : [] );
				if ( [] === $summary ) {
					return;
				}
				$failed = 0;
				$total  = 0;
				foreach ( $summary as $row ) {
					$failed += $row['failed'];
					$total  += $row['count'];
				}
				$this->logger->log(
					Event::make( Category::CORE, 'core.auto_updates_completed', $failed > 0 ? Severity::ERROR : Severity::NOTICE )
						->object( 'core', 'WordPress' )
						->summary( sprintf( 'Automatic updates completed: %d items, %d failed', $total, $failed ) )
						->context( [ 'results' => $summary ] )
				);
			}
		);
	}

	/**
	 * Per-type counts and slugs of an automatic update run.
	 *
	 * @param array<string|int, mixed> $results WP_Automatic_Updater::$update_results.
	 * @return array<string, array{count: int, succeeded: int, failed: int, slugs: list<string>, failed_slugs: list<string>}>
	 */
	public static function summarise( array $results ): array {
		$out = [];
		foreach ( $results as $type => $items ) {
			if ( ! is_array( $items ) || [] === $items ) {
				continue;
			}
			$row = [
				'count'        => 0,
				'succeeded'    => 0,
				'failed'       => 0,
				'slugs'        => [],
				'failed_slugs' => [],
			];
			foreach ( $items as $entry ) {
				if ( ! is_object( $entry ) ) {
					continue;
				}
				++$row['count'];
				$slug   = self::itemSlug( (string) $type, $entry->item ?? null );
				$result = $entry->result ?? null;
				$ok     = null !== $result && false !== $result && ! $result instanceof \WP_Error;
				if ( $ok ) {
					++$row['succeeded'];
				} else {
					++$row['failed'];
					if ( null !== $slug ) {
						$row['failed_slugs'][] = $slug;
					}
				}
				if ( null !== $slug && count( $row['slugs'] ) < 50 ) {
					$row['slugs'][] = $slug;
				}
			}
			$out[ substr( (string) $type, 0, 32 ) ] = $row;
		}
		return $out;
	}

	/**
	 * Slug of one update offer.
	 *
	 * @param string $type Type.
	 * @param mixed  $item Update offer object.
	 */
	private static function itemSlug( string $type, mixed $item ): ?string {
		if ( ! is_object( $item ) ) {
			return null;
		}
		$value = match ( $type ) {
			'core'        => $item->current ?? $item->version ?? null,
			'plugin'      => $item->slug ?? $item->plugin ?? null,
			'theme'       => $item->theme ?? $item->slug ?? null,
			'translation' => isset( $item->slug, $item->language ) ? $item->slug . ':' . $item->language : null,
			default       => $item->slug ?? null,
		};
		return is_scalar( $value ) && '' !== (string) $value ? substr( (string) $value, 0, 64 ) : null;
	}
}
