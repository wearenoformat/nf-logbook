<?php
/**
 * Known client list for administrator logins.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Security\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Most-recent-first list of ip hashes an administrator logged in from (hashes only).
 */
final class KnownClients {

	public const META_KEY = 'nflog_known_clients';
	public const MAX      = 10;

	/**
	 * Put a hash at the front of the list.
	 *
	 * @param mixed  $stored Stored meta value.
	 * @param string $hash   Ip hash.
	 * @param int    $max    Max entries.
	 * @return array{list: list<string>, new: bool}
	 */
	public static function remember( mixed $stored, string $hash, int $max = self::MAX ): array {
		$list = [];
		foreach ( is_array( $stored ) ? $stored : [] as $entry ) {
			if ( is_string( $entry ) && 1 === preg_match( '/^[0-9a-f]{32}$/', $entry ) && ! in_array( $entry, $list, true ) ) {
				$list[] = $entry;
			}
		}
		$new  = ! in_array( $hash, $list, true );
		$list = array_values( array_diff( $list, [ $hash ] ) );
		array_unshift( $list, $hash );
		return [
			'list' => array_slice( $list, 0, max( 1, $max ) ),
			'new'  => $new,
		];
	}
}
