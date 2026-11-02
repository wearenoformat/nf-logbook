<?php
/**
 * Authenticator tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests;

use Noformat\Logger\Config;
use Noformat\Logger\Rest\Authenticator;
use Noformat\Logger\Rest\Contracts\NonceRegistry;
use Noformat\Logger\Rest\Contracts\RateLimit;
use Noformat\Logger\Rest\Contracts\SecretResolver;

final class AuthenticatorTest extends TestCase {

	public function test_vectors_canonical_query_string_to_sign_and_signature(): void {
		$fx = self::fixture( 'signing-vectors.json' );
		foreach ( $fx['vectors'] as $v ) {
			$cq = Authenticator::canonicalQuery( $v['raw_query'] );
			self::assertSame( $v['expected']['canonical_query'], $cq, $v['name'] );
			$sts = Authenticator::stringToSign( $v['method'], $v['signing_path'], $cq, $v['timestamp'], $v['nonce'], $v['body'] );
			self::assertSame( $v['expected']['string_to_sign'], $sts, $v['name'] );
			self::assertSame( $v['expected']['body_sha256'], hash( 'sha256', $v['body'] ) );
			self::assertSame( $v['expected']['signature'], Authenticator::sign( $fx['secret'], $sts ), $v['name'] );
		}
	}

	public function test_string_to_sign_layout(): void {
		$sts = Authenticator::stringToSign( 'get', '/p', 'a=1', 10, 'n', '' );
		self::assertSame( "v1\nGET\n/p\na=1\n10\nn\n" . hash( 'sha256', '' ), $sts );
	}

	public function test_valid_vectors_are_accepted(): void {
		$fx = self::fixture( 'signing-vectors.json' );
		foreach ( $fx['vectors'] as $v ) {
			$result = $this->authenticator()->check( $this->input( $v, $v['timestamp'] ) );
			self::assertTrue( $result['ok'], $v['name'] . ': ' . $result['reason'] );
			self::assertSame( $fx['key_id'], $result['key_id'] );
		}
	}

	public function test_rejections(): void {
		$fx      = self::fixture( 'signing-vectors.json' );
		$vectors = array_column( $fx['vectors'], null, 'name' );
		foreach ( $fx['rejections'] as $case ) {
			$auth  = $this->authenticator();
			$input = $this->input( $vectors[ $case['based_on'] ], $case['now'] );
			foreach ( $case['override_headers'] ?? [] as $name => $value ) {
				$input['headers'][ $name ] = $value;
			}
			if ( isset( $case['https'] ) ) {
				$input['https'] = $case['https'];
			}
			$result = $auth->check( $input );
			for ( $i = 1; $i < ( $case['repeat'] ?? 1 ); $i++ ) {
				$result = $auth->check( $input );
			}
			self::assertSame( $case['expected_reason'], $result['reason'], $case['name'] );
			self::assertSame( 'ok' === $case['expected_reason'], $result['ok'], $case['name'] );
			if ( ! $result['ok'] ) {
				self::assertSame( 401, $result['status'], $case['name'] );
			}
		}
	}

	public function test_rest_route_form_accepts_canonical_path(): void {
		$fx = self::fixture( 'signing-vectors.json' );
		$v  = array_column( $fx['vectors'], null, 'name' )['rest_route_form'];
		$in = $this->input( $v, $v['timestamp'] );
		// What WordPress gives us for the ?rest_route= form: no literal path, only the canonical one.
		$in['paths'] = [ '/wp-json/noformat-logger/v1/events' ];
		self::assertTrue( $this->authenticator()->check( $in )['ok'] );
		$in['paths'] = [ '/index.php' ];
		self::assertSame( 'bad_signature', $this->authenticator()->check( $in )['reason'] );
	}

	public function test_http_allowed_with_setting(): void {
		$fx = self::fixture( 'signing-vectors.json' );
		$v  = $fx['vectors'][0];
		$in = $this->input( $v, $v['timestamp'] );
		$in['https'] = false;
		self::assertSame( 'https_required', $this->authenticator()->check( $in )['reason'] );
		self::assertTrue( $this->authenticator( [ 'allow_http' => true ] )->check( $in )['ok'] );
	}

	public function test_rate_limit_returns_429(): void {
		$fx      = self::fixture( 'signing-vectors.json' );
		$v       = $fx['vectors'][0];
		$limiter = new class() implements RateLimit {
			public function hit( string $bucket, int $limit, int $now ): bool {
				return false;
			}
		};
		$auth    = new Authenticator( new Config(), $this->secrets(), $this->nonces(), $limiter );
		$result  = $auth->check( $this->input( $v, $v['timestamp'] ) );
		self::assertFalse( $result['ok'] );
		self::assertSame( 429, $result['status'] );
		self::assertSame( 'rate_limited', $result['reason'] );
	}

	public function test_ip_allowlist(): void {
		$fx = self::fixture( 'signing-vectors.json' );
		$v  = $fx['vectors'][0];
		$in = $this->input( $v, $v['timestamp'] );
		$in['ip'] = '203.0.113.9';
		self::assertTrue( $this->authenticator( [ 'ip_allowlist' => [ '203.0.113.0/24' ] ] )->check( $in )['ok'] );
		self::assertSame( 'ip_not_allowed', $this->authenticator( [ 'ip_allowlist' => [ '198.51.100.1' ] ] )->check( $in )['reason'] );
		self::assertTrue( Authenticator::ipAllowed( '2001:db8::1', [ '2001:db8::/32' ] ) );
		self::assertFalse( Authenticator::ipAllowed( '2001:db9::1', [ '2001:db8::/32' ] ) );
		self::assertTrue( Authenticator::ipAllowed( '10.1.2.3', [ '10.0.0.0/7' ] ) );
		self::assertFalse( Authenticator::ipAllowed( 'garbage', [ '10.0.0.0/8' ] ) );
	}

	public function test_signature_header_is_case_insensitive_hex(): void {
		$fx = self::fixture( 'signing-vectors.json' );
		$v  = $fx['vectors'][0];
		$in = $this->input( $v, $v['timestamp'] );
		$in['headers']['x-nf-signature'] = strtoupper( $in['headers']['x-nf-signature'] );
		self::assertTrue( $this->authenticator()->check( $in )['ok'] );
	}

	/**
	 * Authenticator with in-memory collaborators.
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	private function authenticator( array $settings = [] ): Authenticator {
		$limiter = new class() implements RateLimit {
			public function hit( string $bucket, int $limit, int $now ): bool {
				return true;
			}
		};
		return new Authenticator( new Config( $settings ), $this->secrets(), $this->nonces(), $limiter );
	}

	private function secrets(): SecretResolver {
		$fx = self::fixture( 'signing-vectors.json' );
		return new class( $fx['key_id'], $fx['secret'] ) implements SecretResolver {
			public function __construct( private string $id, private string $secret ) {
			}
			public function secretFor( string $keyId, int $now ): ?string {
				return $keyId === $this->id ? $this->secret : null;
			}
		};
	}

	private function nonces(): NonceRegistry {
		return new class() implements NonceRegistry {
			/** @var array<string, true> */
			private array $seen = [];
			public function consume( string $keyId, string $nonce, int $now ): bool {
				if ( isset( $this->seen[ $keyId . $nonce ] ) ) {
					return false;
				}
				$this->seen[ $keyId . $nonce ] = true;
				return true;
			}
		};
	}

	/**
	 * Verification input for a vector.
	 *
	 * @param array<string, mixed> $v   Vector.
	 * @param int                  $now Server time.
	 * @return array{method: string, paths: list<string>, query: string, headers: array<string, string>, body: string, https: bool, ip: string|null, now: int}
	 */
	private function input( array $v, int $now ): array {
		$fx = self::fixture( 'signing-vectors.json' );
		return [
			'method'  => $v['method'],
			'paths'   => [ $v['signing_path'] ],
			'query'   => $v['raw_query'],
			'headers' => [
				'x-nf-key'       => $fx['key_id'],
				'x-nf-timestamp' => (string) $v['timestamp'],
				'x-nf-nonce'     => $v['nonce'],
				'x-nf-signature' => $v['expected']['signature'],
			],
			'body'    => $v['body'],
			'https'   => true,
			'ip'      => null,
			'now'     => $now,
		];
	}
}
