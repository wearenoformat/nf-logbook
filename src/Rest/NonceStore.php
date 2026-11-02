<?php
/**
 * Nonce store.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Rest;

use Noformat\Logger\Rest\Contracts\NonceRegistry;
use Noformat\Logger\Storage\Db;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- plugin table.

/**
 * Single-use request nonces in {prefix}nflog_nonces (10 minute window).
 * Only sha256(key_id:nonce) is stored. Cleaned hourly by cron.
 */
final class NonceStore implements NonceRegistry {

	public const TTL = 600;

	/**
	 * Consume a nonce.
	 *
	 * @param string $keyId Key id.
	 * @param string $nonce Nonce.
	 * @param int    $now   Unix time.
	 * @throws \RuntimeException On storage failure other than a duplicate.
	 */
	public function consume( string $keyId, string $nonce, int $now ): bool {
		$hash   = hash( 'sha256', $keyId . ':' . strtolower( $nonce ) );
		$table  = Db::table( 'nonces' );
		$exists = Db::getVar( Db::prepare( 'SELECT nonce_hash FROM %i WHERE nonce_hash = %s', $table, $hash ) );
		if ( null !== $exists ) {
			return false;
		}
		try {
			Db::query( Db::prepare( 'INSERT INTO %i (nonce_hash, expires_at) VALUES (%s, %d)', $table, $hash, $now + self::TTL ) );
		} catch ( \RuntimeException $e ) {
			if ( 1 === preg_match( '/duplicate|unique/i', $e->getMessage() ) ) {
				return false;
			}
			throw $e;
		}
		return true;
	}

	/**
	 * Delete expired nonces.
	 *
	 * @param int $now Unix time.
	 */
	public function cleanup( int $now ): int {
		return Db::query( Db::prepare( 'DELETE FROM %i WHERE expires_at < %d', Db::table( 'nonces' ), $now ) );
	}
}
