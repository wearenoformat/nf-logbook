<?php
/**
 * Phase D hardening: pure helpers and small units.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests;

use Brain\Monkey\Functions;
use Noformat\Logger\Config;
use Noformat\Logger\Context\RequestContext;
use Noformat\Logger\Errors\HttpApiListener;
use Noformat\Logger\Errors\Support\PathMapper;
use Noformat\Logger\Plugin;
use Noformat\Logger\Rest\Authenticator;
use Noformat\Logger\Rest\Contracts\NonceRegistry;
use Noformat\Logger\Rest\Contracts\RateLimit;
use Noformat\Logger\Rest\Contracts\SecretResolver;
use Noformat\Logger\Rest\KeyStore;
use Noformat\Logger\Storage\Schema;

require_once __DIR__ . '/Stubs/RestRequest.php';

final class HardeningTest extends TestCase {

	/** @var array<string, mixed> */
	private array $server = [];

	protected function setUp(): void {
		parent::setUp();
		$this->server = $_SERVER;
	}

	protected function tearDown(): void {
		$_SERVER = $this->server;
		PathMapper::useHasher( null );
		parent::tearDown();
	}

	public function test_signed_namespace_requests_bypass_rest_authentication_errors(): void {
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
		$headers = [
			'HTTP_X_NF_KEY'       => 'nfk_7Hs9Qk2LmP4xT8vZ',
			'HTTP_X_NF_TIMESTAMP' => '1',
			'HTTP_X_NF_NONCE'     => str_repeat( 'a', 32 ),
			'HTTP_X_NF_SIGNATURE' => 'v1=' . str_repeat( '0', 64 ),
		];
		$uri     = [ 'REQUEST_URI' => '/blog/wp-json/noformat-logger/v1/events?after=0' ];
		self::assertTrue( Authenticator::isSignedNamespaceRequest( $headers + $uri, null ) );
		self::assertTrue( Authenticator::isSignedNamespaceRequest( $headers, '/noformat-logger/v1/manifest' ), '?rest_route= form' );
		self::assertFalse( Authenticator::isSignedNamespaceRequest( $headers + [ 'REQUEST_URI' => '/wp-json/wp/v2/users' ], null ), 'other namespaces keep the error' );
		self::assertFalse( Authenticator::isSignedNamespaceRequest( $headers, '/noformat-logger/v10/x' ) );
		$partial = $headers;
		unset( $partial['HTTP_X_NF_NONCE'] );
		self::assertFalse( Authenticator::isSignedNamespaceRequest( $partial + $uri, null ), 'all four headers required' );

		$_SERVER = $headers + $uri;
		$error   = new \WP_Error( 'blocked', 'REST API restricted' );
		self::assertNull( Authenticator::allowSignedRequests( $error ) );
		self::assertTrue( Authenticator::allowSignedRequests( true ), 'cookie auth results are kept' );
		self::assertNull( Authenticator::allowSignedRequests( null ) );
		$_SERVER = $uri;
		self::assertSame( $error, Authenticator::allowSignedRequests( $error ), 'unsigned requests keep the error' );
	}

	public function test_outbound_paths_are_scrubbed(): void {
		$hash = static fn( string $v ): string => substr( hash( 'sha256', $v ), 0, 8 );
		self::assertSame( '/api/v1/messages', HttpApiListener::scrubPath( '/api/v1/messages', $hash ) );
		$slack = HttpApiListener::scrubPath( '/services/T0123ABCD/B0456EFGH/XXXXXXXXXXXXXXXXXXXXXXXX', $hash );
		self::assertMatchesRegularExpression( '#^/services/T0123ABCD/B0456EFGH/\{[0-9a-f]{8}\}$#', $slack );
		$discord = HttpApiListener::scrubPath( '/api/webhooks/123456789012345678/abc-DEF_ghi', $hash );
		self::assertMatchesRegularExpression( '#^/api/webhooks/\{[0-9a-f]{8}\}/abc-DEF_ghi$#', $discord );
		$telegram = HttpApiListener::scrubPath( '/bot123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw/sendMessage', $hash );
		self::assertMatchesRegularExpression( '#^/\{[0-9a-f]{8}\}/sendMessage$#', $telegram, 'a token in the first segment is hashed too' );
		$crm = HttpApiListener::scrubPath( '/v3/contacts/jane%40example.com/notes', $hash );
		self::assertMatchesRegularExpression( '#^/v3/contacts/\{[0-9a-f]{8}\}/notes$#', $crm );
		$event = HttpApiListener::buildEvent( 'https://hooks.slack.com/services/T1/B2/abcdefghijklmnopqrstuvwx?x=1', 'POST', 500, null, null, null, $hash );
		self::assertNotNull( $event );
		self::assertStringNotContainsString( 'abcdefghijklmnopqrstuvwx', (string) wp_json_encode( [ $event->getSummary(), $event->getContext() ] ) );
	}

	public function test_malformed_cidr_entries_are_rejected(): void {
		foreach ( [ '10.0.0.0/', '10.0.0.0/x', '/8', '10.0.0.0/33', '2001:db8::/129', 'nope', '10.0.0.0/8/1' ] as $bad ) {
			self::assertFalse( Authenticator::isValidAllowlistEntry( $bad ), $bad );
			self::assertFalse( Authenticator::ipAllowed( '10.1.2.3', [ $bad ] ), $bad . ' must not act as /0' );
		}
		foreach ( [ '10.0.0.0/8', '203.0.113.9', '2001:db8::/32', '::1', '0.0.0.0/0' ] as $good ) {
			self::assertTrue( Authenticator::isValidAllowlistEntry( $good ), $good );
		}
		self::assertTrue( Authenticator::ipAllowed( '10.1.2.3', [ '10.0.0.0/x', '10.0.0.0/8' ] ), 'later valid entries still apply' );
	}

	public function test_decisions_never_outlive_their_request_object(): void {
		$fx = self::fixture( 'signing-vectors.json' );
		Functions\when( 'rest_get_url_prefix' )->justReturn( 'wp-json' );
		Functions\when( 'is_ssl' )->justReturn( true );
		$auth = new Authenticator( new Config(), $this->secrets( $fx['key_id'], $fx['secret'] ), $this->nonces(), $this->limiter() );

		$_SERVER['REQUEST_URI'] = '/wp-json/noformat-logger/v1/manifest';
		$nonce                  = str_repeat( 'b', 32 );
		$request                = $this->signed( $fx, $nonce );
		self::assertTrue( $auth->permission( $request ) );
		self::assertTrue( $auth->permission( $request ), 'same object: decided once (nonce not reused)' );
		unset( $request );
		gc_collect_cycles();
		$replay = $this->signed( $fx, $nonce );
		$result = $auth->permission( $replay );
		self::assertInstanceOf( \WP_Error::class, $result, 'a new object (even with a recycled object id) is authenticated again' );
	}

	public function test_changed_setting_names_only(): void {
		self::assertSame( [], Plugin::changedSettings( [ 'log_debug' => false ], [ 'log_debug' => false ] ) );
		self::assertSame(
			[ 'ip_allowlist', 'log_debug', 'modules.MailListener' ],
			Plugin::changedSettings(
				[ 'log_debug' => false, 'ip_allowlist' => [], 'modules' => [ 'MailListener' => true, 'PostListener' => true ] ],
				[ 'log_debug' => true, 'ip_allowlist' => [ '10.0.0.0/8' ], 'modules' => [ 'MailListener' => false, 'PostListener' => true ] ]
			)
		);
	}

	public function test_upload_file_names_are_hashed(): void {
		PathMapper::useHasher( static fn( string $v ): string => 'cafef00d' );
		$m = new PathMapper( [ '/srv/www/' => '' ] );
		self::assertSame(
			'fopen(wp-content/uploads/2026/10/{cafef00d}.pdf): failed',
			$m->relativeInText( 'fopen(/srv/www/wp-content/uploads/2026/10/Jane-Doe-Invoice.PDF): failed' )
		);
		self::assertSame( 'wp-content/plugins/x/x.php', $m->relativeInText( '/srv/www/wp-content/plugins/x/x.php' ), 'other paths keep their names' );
	}

	public function test_author_slug_is_masked(): void {
		$hash = static fn( string $slug ): string => 'h-' . $slug;
		self::assertSame( '/author/{h-jane}/', RequestContext::maskAuthor( '/author/jane/', $hash ) );
		self::assertSame( '/blog/author/{h-jane}/page/2/', RequestContext::maskAuthor( '/blog/author/jane/page/2/', $hash ) );
		self::assertSame( '/writers/{h-jane}', RequestContext::maskAuthor( '/writers/jane', $hash, 'writers' ) );
		self::assertSame( '/authority/x/', RequestContext::maskAuthor( '/authority/x/', $hash ) );
	}

	public function test_schema_upgrade_check_is_free_when_current(): void {
		Functions\when( 'get_option' )->justReturn( Schema::VERSION );
		Functions\expect( 'get_transient' )->never();
		Schema::maybeUpgrade();
		self::assertTrue( true );
	}

	public function test_schema_upgrade_is_throttled(): void {
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'get_transient' )->justReturn( 1 );
		Functions\expect( 'set_transient' )->never();
		Schema::maybeUpgrade();
		self::assertTrue( true );
	}

	public function test_unreadable_stored_key_is_reported(): void {
		if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			self::markTestSkipped( 'sodium missing' );
		}
		Functions\when( 'get_option' )->justReturn(
			[
				'current' => [
					'id'  => 'nfk_AAAAAAAAAAAAAAAA',
					'box' => base64_encode( random_bytes( 64 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				],
			]
		);
		Functions\when( 'wp_salt' )->justReturn( 'rotated-salts' );
		$keys = new KeyStore();
		$seen = [];
		$keys->onUnreadable(
			static function ( string $id ) use ( &$seen ): void {
				$seen[] = $id;
			}
		);
		self::assertNull( $keys->secretFor( 'nfk_AAAAAAAAAAAAAAAA', time() ) );
		self::assertSame( [ 'nfk_AAAAAAAAAAAAAAAA' ], $seen );
		self::assertNull( $keys->secretFor( 'nfk_BBBBBBBBBBBBBBBB', time() ) );
		self::assertCount( 1, $seen, 'unknown ids are not decrypt failures' );
	}

	/**
	 * Signed request for the manifest route.
	 *
	 * @param array<string, mixed> $fx    Signing fixture.
	 * @param string               $nonce Nonce.
	 */
	private function signed( array $fx, string $nonce ): \WP_REST_Request {
		$ts  = time();
		$sts = Authenticator::stringToSign( 'GET', '/wp-json/noformat-logger/v1/manifest', '', $ts, $nonce, '' );
		return new \WP_REST_Request(
			'GET',
			'/noformat-logger/v1/manifest',
			[
				'x_nf_key'       => $fx['key_id'],
				'x_nf_timestamp' => (string) $ts,
				'x_nf_nonce'     => $nonce,
				'x_nf_signature' => Authenticator::sign( $fx['secret'], $sts ),
			]
		);
	}

	private function secrets( string $id, string $secret ): SecretResolver {
		return new class( $id, $secret ) implements SecretResolver {
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

	private function limiter(): RateLimit {
		return new class() implements RateLimit {
			public function hit( string $bucket, int $limit, int $now ): bool {
				return true;
			}
		};
	}
}
