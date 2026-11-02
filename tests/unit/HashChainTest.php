<?php
/**
 * HashChain tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests;

use Noformat\Logger\Storage\HashChain;

final class HashChainTest extends TestCase {

	public function test_canonicalization_vectors(): void {
		foreach ( self::fixture( 'hash-vectors.json' )['canonicalization'] as $case ) {
			self::assertSame( $case['canonical'], HashChain::canonicalize( $case['input'] ), $case['name'] );
		}
	}

	public function test_empty_object_and_array_are_equal(): void {
		self::assertSame( '[]', HashChain::canonicalize( new \stdClass() ) );
		self::assertSame( '[]', HashChain::canonicalize( [] ) );
	}

	public function test_chain_vectors(): void {
		$prev = HashChain::GENESIS;
		foreach ( self::fixture( 'hash-vectors.json' )['chain'] as $event ) {
			$expectedPayload = $event['canonical_payload'];
			unset( $event['canonical_payload'] );
			self::assertSame( $prev, $event['prev_hash'] );
			self::assertSame( $expectedPayload, HashChain::canonicalize( HashChain::payload( $event ) ) );
			self::assertSame( $event['hash'], HashChain::hash( $prev, $event ), 'event ' . $event['id'] );
			$prev = $event['hash'];
		}
	}

	public function test_payload_excludes_mutable_and_envelope_fields(): void {
		$payload = HashChain::payload(
			[
				'id'        => 1,
				'hash'      => 'h',
				'prev_hash' => 'p',
				'count'     => 3,
				'site'      => [],
				'producer'  => [],
				'action'    => 'x',
			]
		);
		self::assertSame( [ 'action' => 'x' ], $payload );
	}

	public function test_verify_intact_chain(): void {
		$result = HashChain::verify( $this->chain(), HashChain::GENESIS );
		self::assertTrue( $result['ok'] );
		self::assertSame( 3, $result['checked'] );
		self::assertNull( $result['break'] );
	}

	public function test_verify_reports_first_break(): void {
		foreach ( self::fixture( 'hash-vectors.json' )['tampered'] as $case ) {
			$events = $this->chain();
			if ( isset( $case['mutate'] ) ) {
				$events[ $case['mutate']['index'] ][ $case['mutate']['field'] ] = $case['mutate']['value'];
			}
			if ( isset( $case['delete_index'] ) ) {
				array_splice( $events, $case['delete_index'], 1 );
			}
			$result = HashChain::verify( $events, HashChain::GENESIS );
			if ( null === $case['expected_break'] ) {
				self::assertTrue( $result['ok'], $case['name'] );
				continue;
			}
			self::assertFalse( $result['ok'], $case['name'] );
			self::assertSame( $case['expected_break']['id'], $result['break']['id'] ?? null, $case['name'] );
			self::assertSame( $case['expected_break']['reason'], $result['break']['reason'] ?? null, $case['name'] );
		}
	}

	public function test_verify_detects_wrong_anchor(): void {
		$result = HashChain::verify( $this->chain(), str_repeat( 'f', 64 ) );
		self::assertFalse( $result['ok'] );
		self::assertSame( 'prev_hash_mismatch', $result['break']['reason'] ?? null );
	}

	/**
	 * Fixture chain without helper fields.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function chain(): array {
		return array_map(
			static function ( array $event ): array {
				unset( $event['canonical_payload'] );
				return $event;
			},
			self::fixture( 'hash-vectors.json' )['chain']
		);
	}
}
