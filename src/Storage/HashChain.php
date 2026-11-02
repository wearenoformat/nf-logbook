<?php
/**
 * Hash chain.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical JSON + SHA-256 chaining (CONTRACT §4).
 *
 * Canonical JSON (documented in docs/API.md and tests/fixtures/hash-vectors.json):
 *  - objects: keys sorted by byte order (strcmp), recursively;
 *  - lists stay in order; an empty array and an empty object both encode as [];
 *  - strings: JSON with unescaped slashes, unescaped unicode and unescaped
 *    U+2028/U+2029 (matches JavaScript JSON.stringify);
 *  - integers as decimal; booleans and null as literals; floats never occur
 *    (the Logger turns them into strings before storage);
 *  - no whitespace.
 *
 * hash = sha256_hex( prev_hash . canonical( payload ) ) where payload is the
 * event in contract shape WITHOUT id, hash, prev_hash, count (mutable through
 * aggregation), site and producer (envelope fields that are not stored per row
 * and legitimately change: site URL, plugin version).
 */
final class HashChain {

	public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

	/** Fields excluded from the hashed payload. */
	public const EXCLUDED = [ 'id', 'hash', 'prev_hash', 'count', 'site', 'producer' ];

	private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS;

	/**
	 * Canonical JSON encoding.
	 *
	 * @param mixed $value Value (arrays, scalars, null; objects are cast to arrays).
	 */
	public static function canonicalize( mixed $value ): string {
		if ( null === $value ) {
			return 'null';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			// Should not happen (Logger stringifies floats); encode deterministically anyway.
			return self::encodeString( (string) $value );
		}
		if ( is_string( $value ) ) {
			return self::encodeString( $value );
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			if ( [] === $value ) {
				return '[]';
			}
			if ( array_is_list( $value ) ) {
				return '[' . implode( ',', array_map( [ self::class, 'canonicalize' ], $value ) ) . ']';
			}
			$keys = array_map( 'strval', array_keys( $value ) );
			$map  = array_combine( $keys, array_values( $value ) );
			usort( $keys, 'strcmp' );
			$parts = [];
			foreach ( $keys as $key ) {
				$parts[] = self::encodeString( $key ) . ':' . self::canonicalize( $map[ $key ] );
			}
			return '{' . implode( ',', $parts ) . '}';
		}
		return 'null';
	}

	/**
	 * The hashed subset of a contract-shaped event.
	 *
	 * @param array<string, mixed> $event Contract-shaped event.
	 * @return array<string, mixed>
	 */
	public static function payload( array $event ): array {
		foreach ( self::EXCLUDED as $field ) {
			unset( $event[ $field ] );
		}
		return $event;
	}

	/**
	 * Compute an event hash.
	 *
	 * @param string               $prevHash Previous hash (64 hex).
	 * @param array<string, mixed> $event    Contract-shaped event.
	 */
	public static function hash( string $prevHash, array $event ): string {
		return hash( 'sha256', $prevHash . self::canonicalize( self::payload( $event ) ) );
	}

	/**
	 * Verify a contiguous, id-ascending run of contract-shaped events.
	 *
	 * @param iterable<array<string, mixed>> $events           Events.
	 * @param string|null                    $expectedPrevHash Expected prev_hash of the first event (null: trust it).
	 * @return array{ok: bool, checked: int, first_id: int|null, last_id: int|null, last_hash: string|null, break: array{id: int|null, reason: string, expected: string, actual: string}|null}
	 */
	public static function verify( iterable $events, ?string $expectedPrevHash = null ): array {
		$checked  = 0;
		$firstId  = null;
		$lastId   = null;
		$lastHash = $expectedPrevHash;
		foreach ( $events as $event ) {
			$id   = isset( $event['id'] ) ? (int) $event['id'] : null;
			$prev = (string) ( $event['prev_hash'] ?? '' );
			$hash = (string) ( $event['hash'] ?? '' );
			if ( null === $firstId ) {
				$firstId = $id;
			}
			if ( null !== $lastHash && ! hash_equals( $lastHash, $prev ) ) {
				return self::result( false, $checked, $firstId, $lastId, $lastHash, $id, 'prev_hash_mismatch', $lastHash, $prev );
			}
			$computed = self::hash( $prev, $event );
			if ( ! hash_equals( $computed, $hash ) ) {
				return self::result( false, $checked, $firstId, $lastId, $lastHash, $id, 'hash_mismatch', $computed, $hash );
			}
			++$checked;
			$lastId   = $id;
			$lastHash = $hash;
		}
		return self::result( true, $checked, $firstId, $lastId, $lastHash );
	}

	/**
	 * Result shape.
	 *
	 * @param bool        $ok       Ok.
	 * @param int         $checked  Checked rows.
	 * @param int|null    $firstId  First id.
	 * @param int|null    $lastId   Last verified id.
	 * @param string|null $lastHash Last verified hash.
	 * @param int|null    $breakId  Break id.
	 * @param string      $reason   Reason.
	 * @param string      $expected Expected.
	 * @param string      $actual   Actual.
	 * @return array{ok: bool, checked: int, first_id: int|null, last_id: int|null, last_hash: string|null, break: array{id: int|null, reason: string, expected: string, actual: string}|null}
	 */
	private static function result( bool $ok, int $checked, ?int $firstId, ?int $lastId, ?string $lastHash, ?int $breakId = null, string $reason = '', string $expected = '', string $actual = '' ): array {
		return [
			'ok'        => $ok,
			'checked'   => $checked,
			'first_id'  => $firstId,
			'last_id'   => $lastId,
			'last_hash' => $lastHash,
			'break'     => $ok ? null : [
				'id'       => $breakId,
				'reason'   => $reason,
				'expected' => $expected,
				'actual'   => $actual,
			],
		];
	}

	/**
	 * JSON string literal.
	 *
	 * @param string $value String.
	 */
	private static function encodeString( string $value ): string {
		$json = json_encode( $value, self::JSON_FLAGS | JSON_INVALID_UTF8_SUBSTITUTE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- exact flags are part of the canonical form.
		return false === $json ? '""' : $json;
	}
}
