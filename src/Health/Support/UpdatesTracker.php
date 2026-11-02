<?php
/**
 * Pending update tracking.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Health\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers: pending updates from the update transients, first-seen
 * bookkeeping and the state digest used to emit `updates.pending` once per change.
 */
final class UpdatesTracker {

	public const STALE_DAYS = 7;

	/**
	 * Pending updates from the three transients.
	 *
	 * @param mixed $core    update_core transient.
	 * @param mixed $plugins update_plugins transient.
	 * @param mixed $themes  update_themes transient.
	 * @return list<array{type: string, slug: string, version: string}>
	 */
	public static function pending( mixed $core, mixed $plugins, mixed $themes ): array {
		$out = [];
		if ( is_object( $core ) && isset( $core->updates ) && is_array( $core->updates ) ) {
			foreach ( $core->updates as $offer ) {
				$offer = (array) $offer;
				if ( 'upgrade' === ( $offer['response'] ?? '' ) && ! empty( $offer['current'] ) ) {
					$out[] = [
						'type'    => 'core',
						'slug'    => 'wordpress',
						'version' => (string) $offer['current'],
					];
					break;
				}
			}
		}
		if ( is_object( $plugins ) && isset( $plugins->response ) && is_array( $plugins->response ) ) {
			foreach ( $plugins->response as $file => $item ) {
				$item  = (array) $item;
				$slug  = isset( $item['slug'] ) ? (string) $item['slug'] : dirname( (string) $file );
				$out[] = [
					'type'    => 'plugin',
					'slug'    => '.' === $slug ? basename( (string) $file, '.php' ) : $slug,
					'version' => (string) ( $item['new_version'] ?? '' ),
				];
			}
		}
		if ( is_object( $themes ) && isset( $themes->response ) && is_array( $themes->response ) ) {
			foreach ( $themes->response as $stylesheet => $item ) {
				$item  = (array) $item;
				$out[] = [
					'type'    => 'theme',
					'slug'    => (string) $stylesheet,
					'version' => (string) ( $item['new_version'] ?? '' ),
				];
			}
		}
		return $out;
	}

	/**
	 * Merge first-seen times: keeps known keys, adds new ones at $now, drops gone ones.
	 *
	 * @param list<array{type: string, slug: string, version: string}> $pending   Pending.
	 * @param array<string|int, mixed>                                 $firstSeen key => unix time.
	 * @param int                                                      $now       Now.
	 * @return array<string, int>
	 */
	public static function firstSeen( array $pending, array $firstSeen, int $now ): array {
		$out = [];
		foreach ( $pending as $item ) {
			$key         = self::key( $item );
			$out[ $key ] = isset( $firstSeen[ $key ] ) ? (int) $firstSeen[ $key ] : $now;
		}
		return $out;
	}

	/**
	 * Items with pending_days and stale flags.
	 *
	 * @param list<array{type: string, slug: string, version: string}> $pending   Pending.
	 * @param array<string, int>                                       $firstSeen First seen.
	 * @param int                                                      $now       Now.
	 * @return list<array{type: string, slug: string, version: string, pending_days: int, stale: bool}>
	 */
	public static function annotate( array $pending, array $firstSeen, int $now ): array {
		$out = [];
		foreach ( $pending as $item ) {
			$since = $firstSeen[ self::key( $item ) ] ?? $now;
			$days  = intdiv( max( 0, $now - $since ), 86400 );
			$out[] = $item + [
				'pending_days' => $days,
				'stale'        => $days >= self::STALE_DAYS,
			];
		}
		return $out;
	}

	/**
	 * State digest: the set of pending keys plus the stale keys ('' when none).
	 *
	 * @param list<array{type: string, slug: string, version: string, pending_days: int, stale: bool}> $items Annotated items.
	 */
	public static function digest( array $items ): string {
		if ( [] === $items ) {
			return '';
		}
		$keys = array_map( static fn( array $i ): string => self::key( $i ) . ( $i['stale'] ? '!' : '' ), $items );
		sort( $keys, SORT_STRING );
		return substr( hash( 'sha256', implode( "\n", $keys ) ), 0, 32 );
	}

	/**
	 * Item key.
	 *
	 * @param array{type: string, slug: string, version: string} $item Item.
	 */
	public static function key( array $item ): string {
		return $item['type'] . ':' . $item['slug'] . '@' . $item['version'];
	}
}
