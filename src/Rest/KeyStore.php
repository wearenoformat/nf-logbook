<?php
/**
 * API key storage.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Rest;

use Noformat\Logger\Rest\Contracts\SecretResolver;

defined( 'ABSPATH' ) || exit;

/**
 * API keys (PLAN D2).
 *
 * Precedence: wp-config constants NOFORMAT_LOGGER_KEY_ID / NOFORMAT_LOGGER_SECRET
 * (plus optional NOFORMAT_LOGGER_PREVIOUS_KEY_ID / NOFORMAT_LOGGER_PREVIOUS_SECRET
 * during a rotation); otherwise the option `nflog_key`, whose secrets are
 * libsodium secretbox ciphertext under sodium_crypto_generichash(wp_salt('auth'), '', 32),
 * so a database dump alone does not reveal them. A rotated-out option key stays
 * valid for 24 hours.
 *
 * Key id: "nfk_" + 16 base62 chars. Secret: 32 random bytes, base64url without
 * padding (43 chars). The HMAC key is the secret string itself (its ASCII bytes).
 */
final class KeyStore implements SecretResolver {

	public const OPTION = 'nflog_key';
	public const GRACE  = 86400;

	private const BASE62 = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

	/**
	 * Called with the key id when a stored secret cannot be decrypted.
	 *
	 * @var \Closure(string): void|null
	 */
	private ?\Closure $onUnreadable = null;

	/**
	 * Register the decrypt-failure callback (Plugin logs logger.key_unreadable).
	 *
	 * @param callable(string): void $callback Callback.
	 */
	public function onUnreadable( callable $callback ): void {
		$this->onUnreadable = \Closure::fromCallable( $callback );
	}

	/**
	 * Where the active secret comes from: constant, option or none.
	 */
	public function source(): string {
		if ( $this->constantsDefined() ) {
			return 'constant';
		}
		return null !== $this->currentId() ? 'option' : 'none';
	}

	/**
	 * Both key constants are defined and non-empty.
	 */
	public function constantsDefined(): bool {
		return defined( 'NOFORMAT_LOGGER_KEY_ID' ) && defined( 'NOFORMAT_LOGGER_SECRET' )
			&& '' !== (string) constant( 'NOFORMAT_LOGGER_KEY_ID' ) && '' !== (string) constant( 'NOFORMAT_LOGGER_SECRET' );
	}

	/**
	 * Current key id (public).
	 */
	public function currentId(): ?string {
		if ( $this->constantsDefined() ) {
			return (string) constant( 'NOFORMAT_LOGGER_KEY_ID' );
		}
		$stored = $this->stored();
		return isset( $stored['current']['id'] ) ? (string) $stored['current']['id'] : null;
	}

	/**
	 * Public metadata for status displays (never secrets).
	 *
	 * @return array{source: string, id: string|null, created_at: int|null, previous_id: string|null, previous_valid_until: int|null}
	 */
	public function describe(): array {
		$stored   = $this->stored();
		$previous = null;
		$until    = null;
		if ( $this->constantsDefined() ) {
			if ( defined( 'NOFORMAT_LOGGER_PREVIOUS_KEY_ID' ) && '' !== (string) constant( 'NOFORMAT_LOGGER_PREVIOUS_KEY_ID' ) ) {
				$previous = (string) constant( 'NOFORMAT_LOGGER_PREVIOUS_KEY_ID' );
			}
		} elseif ( isset( $stored['previous']['id'] ) ) {
			$previous = (string) $stored['previous']['id'];
			$until    = (int) ( $stored['previous']['rotated_at'] ?? 0 ) + self::GRACE;
		}
		return [
			'source'               => $this->source(),
			'id'                   => $this->currentId(),
			'created_at'           => $this->constantsDefined() ? null : ( isset( $stored['current']['created_at'] ) ? (int) $stored['current']['created_at'] : null ),
			'previous_id'          => $previous,
			'previous_valid_until' => $until,
		];
	}

	/**
	 * Secret for a key id.
	 *
	 * @param string $keyId Key id.
	 * @param int    $now   Unix time.
	 */
	public function secretFor( string $keyId, int $now ): ?string {
		if ( '' === $keyId ) {
			return null;
		}
		if ( $this->constantsDefined() ) {
			if ( hash_equals( (string) constant( 'NOFORMAT_LOGGER_KEY_ID' ), $keyId ) ) {
				return (string) constant( 'NOFORMAT_LOGGER_SECRET' );
			}
			if ( defined( 'NOFORMAT_LOGGER_PREVIOUS_KEY_ID' ) && defined( 'NOFORMAT_LOGGER_PREVIOUS_SECRET' )
				&& '' !== (string) constant( 'NOFORMAT_LOGGER_PREVIOUS_KEY_ID' )
				&& hash_equals( (string) constant( 'NOFORMAT_LOGGER_PREVIOUS_KEY_ID' ), $keyId ) ) {
				return (string) constant( 'NOFORMAT_LOGGER_PREVIOUS_SECRET' );
			}
			return null;
		}
		$stored = $this->stored();
		if ( isset( $stored['current']['id'], $stored['current']['box'] ) && hash_equals( (string) $stored['current']['id'], $keyId ) ) {
			return $this->readable( $keyId, $this->decrypt( (string) $stored['current']['box'] ) );
		}
		if ( isset( $stored['previous']['id'], $stored['previous']['box'] ) && hash_equals( (string) $stored['previous']['id'], $keyId ) ) {
			$rotatedAt = (int) ( $stored['previous']['rotated_at'] ?? 0 );
			return $now - $rotatedAt <= self::GRACE ? $this->readable( $keyId, $this->decrypt( (string) $stored['previous']['box'] ) ) : null;
		}
		return null;
	}

	/**
	 * Report an undecryptable stored secret.
	 *
	 * @param string      $keyId  Key id.
	 * @param string|null $secret Decrypted secret.
	 */
	private function readable( string $keyId, ?string $secret ): ?string {
		if ( null === $secret && null !== $this->onUnreadable ) {
			try {
				( $this->onUnreadable )( $keyId );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return $secret;
	}

	/**
	 * Create the first key (or replace all keys when $force).
	 *
	 * @param bool $force Replace existing keys without grace.
	 * @return array{id: string, secret: string}
	 * @throws \RuntimeException When constants manage the key or a key exists.
	 */
	public function generate( bool $force = false ): array {
		$this->assertWritable();
		if ( ! $force && null !== $this->currentId() ) {
			throw new \RuntimeException( 'A key already exists; use rotate.' );
		}
		$pair = self::newPair();
		$this->save(
			[
				'current' => $this->entry( $pair ),
			]
		);
		return $pair;
	}

	/**
	 * New current key; the old one stays valid for 24 h.
	 *
	 * @return array{id: string, secret: string, previous_id: string|null}
	 * @throws \RuntimeException When constants manage the key.
	 */
	public function rotate(): array {
		$this->assertWritable();
		$stored   = $this->stored();
		$pair     = self::newPair();
		$value    = [ 'current' => $this->entry( $pair ) ];
		$previous = null;
		if ( isset( $stored['current']['id'] ) ) {
			$value['previous']               = $stored['current'];
			$value['previous']['rotated_at'] = time();
			$previous                        = (string) $stored['current']['id'];
		}
		$this->save( $value );
		return $pair + [ 'previous_id' => $previous ];
	}

	/**
	 * Revoke the previous key now (default) or every stored key.
	 *
	 * @param bool $all Also revoke the current key.
	 * @throws \RuntimeException When constants manage the key.
	 */
	public function revoke( bool $all = false ): void {
		$this->assertWritable();
		if ( $all ) {
			delete_option( self::OPTION );
			return;
		}
		$stored = $this->stored();
		unset( $stored['previous'] );
		$this->save( $stored );
	}

	/**
	 * Fresh key pair (not stored).
	 *
	 * @return array{id: string, secret: string}
	 */
	public static function newPair(): array {
		$id = 'nfk_';
		for ( $i = 0; $i < 16; $i++ ) {
			$id .= self::BASE62[ random_int( 0, 61 ) ];
		}
		return [
			'id'     => $id,
			'secret' => rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- key encoding.
		];
	}

	/**
	 * The two define() lines for wp-config.php.
	 *
	 * @param string $id     Key id.
	 * @param string $secret Secret.
	 */
	public static function configLines( string $id, string $secret ): string {
		return "define( 'NOFORMAT_LOGGER_KEY_ID', '" . $id . "' );\ndefine( 'NOFORMAT_LOGGER_SECRET', '" . $secret . "' );";
	}

	/**
	 * Valid key id format.
	 *
	 * @param string $id Candidate.
	 */
	public static function isValidId( string $id ): bool {
		return 1 === preg_match( '/^nfk_[0-9A-Za-z]{16}$/', $id );
	}

	/**
	 * Stored option.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function stored(): array {
		$value = function_exists( 'get_option' ) ? get_option( self::OPTION, [] ) : [];
		return is_array( $value ) ? $value : [];
	}

	/**
	 * Save the option (never autoloaded).
	 *
	 * @param array<string, mixed> $value Value.
	 */
	private function save( array $value ): void {
		update_option( self::OPTION, $value, false );
	}

	/**
	 * Option entry for a pair.
	 *
	 * @param array{id: string, secret: string} $pair Pair.
	 * @return array{id: string, box: string, created_at: int}
	 */
	private function entry( array $pair ): array {
		return [
			'id'         => $pair['id'],
			'box'        => $this->encrypt( $pair['secret'] ),
			'created_at' => time(),
		];
	}

	/**
	 * Refuse writes when constants manage the key.
	 *
	 * @throws \RuntimeException When constants are defined.
	 */
	private function assertWritable(): void {
		if ( $this->constantsDefined() ) {
			throw new \RuntimeException( 'The API key is managed by wp-config.php constants.' );
		}
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			throw new \RuntimeException( 'libsodium is required to store a key in the database; use wp-config.php constants instead.' );
		}
	}

	/**
	 * Encryption key derived from the auth salt.
	 */
	private function boxKey(): string {
		return sodium_crypto_generichash( wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Encrypt.
	 *
	 * @param string $secret Plain secret.
	 */
	private function encrypt( string $secret ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $secret, $nonce, $this->boxKey() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- ciphertext encoding.
	}

	/**
	 * Decrypt; null when the salts changed or the data is corrupt.
	 *
	 * @param string $box Ciphertext.
	 */
	private function decrypt( string $box ): ?string {
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return null;
		}
		$raw = base64_decode( $box, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- ciphertext encoding.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				$this->boxKey()
			);
		} catch ( \Throwable $e ) {
			return null;
		}
		return false === $plain ? null : $plain;
	}
}
