<?php
/**
 * HMAC request authentication.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Rest;

use Noformat\Logger\Category;
use Noformat\Logger\Config;
use Noformat\Logger\Event;
use Noformat\Logger\Logger;
use Noformat\Logger\Rest\Contracts\NonceRegistry;
use Noformat\Logger\Rest\Contracts\RateLimit;
use Noformat\Logger\Rest\Contracts\SecretResolver;
use Noformat\Logger\Severity;
use Noformat\Logger\Storage\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * CONTRACT §3 request signing, v1.
 *
 *     X-NF-Key:       nfk_…
 *     X-NF-Timestamp: unix seconds
 *     X-NF-Nonce:     32 hex chars
 *     X-NF-Signature: v1=<hex HMAC-SHA256(secret, string_to_sign)>
 *
 *     string_to_sign = "v1\n" METHOD "\n" PATH "\n" CANONICAL_QUERY "\n" TIMESTAMP "\n" NONCE "\n" sha256_hex(BODY)
 *
 * PATH is the request path as sent (e.g. /wp-json/noformat-logger/v1/events, or
 * /blog/wp-json/… on a subdirectory install). For the `?rest_route=` form the
 * signer uses "/wp-json" + route (rest_route itself is excluded from the query);
 * the server accepts either the literal path or that canonical form.
 *
 * Cookie and Application Password authentication never grant access: this
 * check ignores the current WordPress user entirely.
 */
final class Authenticator {

	public const MAX_SKEW  = 300;
	public const NAMESPACE = 'noformat-logger/v1';

	private ?string $authenticatedKey = null;

	/**
	 * Decision per request object: WordPress calls permission callbacks more than once
	 * per request (e.g. rest_send_allow_header), and a nonce is single-use. A WeakMap,
	 * so a decision never outlives its request object (object ids are reused).
	 *
	 * @var \WeakMap<\WP_REST_Request<array<string, mixed>>, true|\WP_Error>
	 */
	private \WeakMap $decisions;

	/**
	 * Constructor.
	 *
	 * @param Config         $config  Config.
	 * @param SecretResolver $secrets Secrets.
	 * @param NonceRegistry  $nonces  Nonces.
	 * @param RateLimit      $limiter Rate limiter.
	 * @param Logger|null    $logger  Logger for failure events.
	 * @param Meta|null      $meta    Meta store for last pull / ack.
	 */
	public function __construct(
		private Config $config,
		private SecretResolver $secrets,
		private NonceRegistry $nonces,
		private RateLimit $limiter,
		private ?Logger $logger = null,
		private ?Meta $meta = null
	) {
		$this->decisions = new \WeakMap();
	}

	/**
	 * RFC 3986 canonical query: decode pairs (+ is a space), drop rest_route,
	 * re-encode with rawurlencode, sort by encoded key then encoded value, join with &.
	 *
	 * @param string $rawQuery Query string without "?".
	 */
	public static function canonicalQuery( string $rawQuery ): string {
		$pairs = [];
		foreach ( explode( '&', $rawQuery ) as $part ) {
			if ( '' === $part ) {
				continue;
			}
			$eq    = strpos( $part, '=' );
			$key   = false === $eq ? $part : substr( $part, 0, $eq );
			$value = false === $eq ? '' : substr( $part, $eq + 1 );
			$key   = rawurldecode( str_replace( '+', ' ', $key ) );
			$value = rawurldecode( str_replace( '+', ' ', $value ) );
			if ( 'rest_route' === $key ) {
				continue;
			}
			$pairs[] = [ rawurlencode( $key ), rawurlencode( $value ) ];
		}
		usort(
			$pairs,
			static fn( array $a, array $b ): int => 0 !== strcmp( $a[0], $b[0] ) ? strcmp( $a[0], $b[0] ) : strcmp( $a[1], $b[1] )
		);
		return implode( '&', array_map( static fn( array $p ): string => $p[0] . '=' . $p[1], $pairs ) );
	}

	/**
	 * The string to sign.
	 *
	 * @param string     $method         HTTP method.
	 * @param string     $path           Path.
	 * @param string     $canonicalQuery Canonical query.
	 * @param string|int $timestamp      Unix seconds.
	 * @param string     $nonce          Nonce.
	 * @param string     $body           Raw body.
	 */
	public static function stringToSign( string $method, string $path, string $canonicalQuery, string|int $timestamp, string $nonce, string $body ): string {
		return implode( "\n", [ 'v1', strtoupper( $method ), $path, $canonicalQuery, (string) $timestamp, $nonce, hash( 'sha256', $body ) ] );
	}

	/**
	 * Signature header value.
	 *
	 * @param string $secret       Secret.
	 * @param string $stringToSign String to sign.
	 */
	public static function sign( string $secret, string $stringToSign ): string {
		return 'v1=' . hash_hmac( 'sha256', $stringToSign, $secret );
	}

	/**
	 * Pure verification.
	 *
	 * @param array{method: string, paths: list<string>, query: string, headers: array<string, string>, body: string, https: bool, ip: string|null, now: int} $input Request data; header names lower-case with dashes.
	 * @return array{ok: bool, status: int, reason: string, key_id: string|null}
	 */
	public function check( array $input ): array {
		$headers   = $input['headers'];
		$keyId     = trim( (string) ( $headers['x-nf-key'] ?? '' ) );
		$timestamp = trim( (string) ( $headers['x-nf-timestamp'] ?? '' ) );
		$nonce     = strtolower( trim( (string) ( $headers['x-nf-nonce'] ?? '' ) ) );
		$signature = strtolower( trim( (string) ( $headers['x-nf-signature'] ?? '' ) ) );
		$validId   = KeyStore::isValidId( $keyId ) ? $keyId : null;

		if ( '' === $keyId || '' === $timestamp || '' === $nonce || '' === $signature ) {
			return self::fail( 'missing_headers', $validId );
		}
		if ( null === $validId || ! ctype_digit( $timestamp ) || 1 !== preg_match( '/^[0-9a-f]{32}$/', $nonce ) || 1 !== preg_match( '/^v1=[0-9a-f]{64}$/', $signature ) ) {
			return self::fail( 'bad_format', $validId );
		}
		if ( ! $input['https'] && ! $this->config->allowHttp() ) {
			return self::fail( 'https_required', $validId );
		}
		$allow = $this->config->ipAllowlist();
		if ( [] !== $allow && ( null === $input['ip'] || ! self::ipAllowed( $input['ip'], $allow ) ) ) {
			return self::fail( 'ip_not_allowed', $validId );
		}

		$secret = $this->secrets->secretFor( $keyId, $input['now'] );
		if ( abs( $input['now'] - (int) $timestamp ) > self::MAX_SKEW ) {
			return self::fail( 'skew', $validId );
		}

		$canonical = self::canonicalQuery( $input['query'] );
		$matched   = false;
		foreach ( $input['paths'] as $path ) {
			$expected = self::sign( $secret ?? 'nflog-unknown-key-' . $keyId, self::stringToSign( $input['method'], $path, $canonical, $timestamp, $nonce, $input['body'] ) );
			if ( hash_equals( $expected, $signature ) ) {
				$matched = true;
			}
		}
		if ( null === $secret || ! $matched ) {
			return self::fail( null === $secret ? 'unknown_key' : 'bad_signature', $validId );
		}
		if ( ! $this->nonces->consume( $keyId, $nonce, $input['now'] ) ) {
			return self::fail( 'nonce_reused', $validId );
		}
		if ( ! $this->limiter->hit( 'key:' . $keyId, $this->config->int( 'rate_limit_per_minute' ), $input['now'] ) ) {
			return [
				'ok'     => false,
				'status' => 429,
				'reason' => 'rate_limited',
				'key_id' => $validId,
			];
		}
		return [
			'ok'     => true,
			'status' => 200,
			'reason' => 'ok',
			'key_id' => $keyId,
		];
	}

	/**
	 * REST permission_callback for every route of the namespace.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|\WP_Error
	 */
	public function permission( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! isset( $this->decisions[ $request ] ) ) {
			$this->decisions[ $request ] = $this->decide( $request );
		}
		return $this->decisions[ $request ];
	}

	/**
	 * Filter rest_authentication_errors at PHP_INT_MAX: security plugins that block
	 * "unauthenticated" REST requests return a WP_Error here, which would stop
	 * WordPress before our permission callback runs. For requests to our namespace
	 * that carry all four X-NF-* headers the error is cleared; the HMAC check in
	 * permission() still decides. Cookie / application password results (true) are kept.
	 *
	 * @param mixed $result Null, true or WP_Error.
	 */
	public static function allowSignedRequests( mixed $result ): mixed {
		if ( ! $result instanceof \WP_Error ) {
			return $result;
		}
		try {
			return self::isSignedNamespaceRequest( $_SERVER, self::currentRestRoute() ) ? null : $result; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only header presence and the route are checked.
		} catch ( \Throwable $e ) {
			return $result;
		}
	}

	/**
	 * Whether a request targets this namespace and carries all four signing headers (pure).
	 *
	 * @param array<string, mixed> $server    $_SERVER.
	 * @param string|null          $restRoute Current REST route (rest_route query var), if known.
	 */
	public static function isSignedNamespaceRequest( array $server, ?string $restRoute ): bool {
		foreach ( [ 'HTTP_X_NF_KEY', 'HTTP_X_NF_TIMESTAMP', 'HTTP_X_NF_NONCE', 'HTTP_X_NF_SIGNATURE' ] as $header ) {
			if ( ! isset( $server[ $header ] ) || ! is_string( $server[ $header ] ) || '' === trim( $server[ $header ] ) ) {
				return false;
			}
		}
		$route = (string) $restRoute;
		if ( '' === $route ) {
			$uri    = isset( $server['REQUEST_URI'] ) && is_string( $server['REQUEST_URI'] ) ? $server['REQUEST_URI'] : '';
			$path   = (string) strtok( $uri, '?' );
			$prefix = '/' . ( function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json' );
			$pos    = strpos( $path, $prefix . '/' );
			$route  = false === $pos ? '' : substr( $path, $pos + strlen( $prefix ) );
		}
		$route = '/' . ltrim( $route, '/' );
		return str_starts_with( $route . '/', '/' . self::NAMESPACE . '/' );
	}

	/**
	 * REST route of the current request from the parsed query vars or ?rest_route=.
	 */
	private static function currentRestRoute(): ?string {
		global $wp;
		if ( is_object( $wp ) && isset( $wp->query_vars['rest_route'] ) && is_string( $wp->query_vars['rest_route'] ) ) {
			return $wp->query_vars['rest_route'];
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only route detection.
		if ( isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) {
			return sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only route detection.
		}
		return null;
	}

	/**
	 * Authenticate once.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return true|\WP_Error
	 */
	private function decide( \WP_REST_Request $request ): bool|\WP_Error {
		try {
			$input  = $this->inputFromRequest( $request );
			$result = $this->check( $input );
		} catch ( \Throwable $e ) {
			$this->logger?->recordFailure( 'auth', $e );
			$result = self::fail( 'internal_error', null );
			$input  = null;
		}

		if ( $result['ok'] ) {
			$this->authenticatedKey = $result['key_id'];
			$this->recordPull( $request, (int) ( $input['now'] ?? time() ) );
			return true;
		}

		$this->logFailure( $result, $request );
		if ( 429 === $result['status'] ) {
			return new \WP_Error( 'nflog_rate_limited', 'Too Many Requests', [ 'status' => 429 ] );
		}
		return new \WP_Error( 'nflog_unauthorized', 'Unauthorized', [ 'status' => 401 ] );
	}

	/**
	 * Key id that authenticated the current request.
	 */
	public function authenticatedKey(): ?string {
		return $this->authenticatedKey;
	}

	/**
	 * Collect verification input from WordPress.
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @return array{method: string, paths: list<string>, query: string, headers: array<string, string>, body: string, https: bool, ip: string|null, now: int}
	 */
	public function inputFromRequest( \WP_REST_Request $request ): array {
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared byte-wise against an HMAC.
		$qpos  = strpos( $uri, '?' );
		$path  = false === $qpos ? $uri : substr( $uri, 0, $qpos );
		$query = false === $qpos ? '' : substr( $uri, $qpos + 1 );
		$hash  = strpos( $query, '#' );
		if ( false !== $hash ) {
			$query = substr( $query, 0, $hash );
		}
		$paths     = [];
		$canonical = '/' . rest_get_url_prefix() . $request->get_route();
		if ( ! str_contains( '&' . $query, '&rest_route=' ) && '' !== $path ) {
			$paths[] = $path;
		}
		if ( ! in_array( $canonical, $paths, true ) ) {
			$paths[] = $canonical;
		}

		$headers = [];
		foreach ( [ 'x-nf-key', 'x-nf-timestamp', 'x-nf-nonce', 'x-nf-signature' ] as $name ) {
			$headers[ $name ] = (string) $request->get_header( str_replace( '-', '_', $name ) );
		}

		$https = function_exists( 'is_ssl' ) && is_ssl();
		/**
		 * Filter whether the API request counts as HTTPS (e.g. TLS terminated at a trusted proxy).
		 *
		 * @param bool $https Detected state.
		 */
		$https = (bool) apply_filters( 'noformat_logger/is_https', $https );

		return [
			'method'  => $request->get_method(),
			'paths'   => $paths,
			'query'   => $query,
			'headers' => $headers,
			'body'    => (string) $request->get_body(),
			'https'   => $https,
			'ip'      => $this->logger?->context()->clientIp(),
			'now'     => time(),
		];
	}

	/**
	 * Whether an allowlist entry is a valid IP or CIDR (prefix 0–32 for IPv4, 0–128 for IPv6).
	 *
	 * @param string $entry Entry.
	 */
	public static function isValidAllowlistEntry( string $entry ): bool {
		$parts  = explode( '/', trim( $entry ), 2 );
		$subnet = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input tolerated.
		if ( false === $subnet ) {
			return false;
		}
		if ( ! isset( $parts[1] ) ) {
			return true;
		}
		return 1 === preg_match( '/^[0-9]{1,3}$/', $parts[1] ) && (int) $parts[1] <= strlen( $subnet ) * 8;
	}

	/**
	 * IPv4/IPv6 exact or CIDR match. Malformed entries ("10.0.0.0/", "/x", "/99") are skipped.
	 *
	 * @param string       $ip      Client IP.
	 * @param list<string> $entries Allowlist.
	 */
	public static function ipAllowed( string $ip, array $entries ): bool {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input tolerated.
		if ( false === $packed ) {
			return false;
		}
		foreach ( $entries as $entry ) {
			if ( ! self::isValidAllowlistEntry( $entry ) ) {
				continue;
			}
			$parts  = explode( '/', trim( $entry ), 2 );
			$subnet = (string) @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- validated above.
			if ( strlen( $subnet ) !== strlen( $packed ) ) {
				continue;
			}
			$bits = isset( $parts[1] ) ? (int) $parts[1] : strlen( $packed ) * 8;
			$full = intdiv( $bits, 8 );
			if ( substr( $packed, 0, $full ) !== substr( $subnet, 0, $full ) ) {
				continue;
			}
			$rest = $bits % 8;
			if ( 0 === $rest ) {
				return true;
			}
			$mask = ( 0xff << ( 8 - $rest ) ) & 0xff;
			if ( ( ord( $packed[ $full ] ) & $mask ) === ( ord( $subnet[ $full ] ) & $mask ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Record the last pull (the ack cursor is handled by GET /events, Controller::events()).
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request Request.
	 * @param int                                    $now     Unix time.
	 */
	private function recordPull( \WP_REST_Request $request, int $now ): void {
		if ( null === $this->meta ) {
			return;
		}
		try {
			$this->meta->setJson(
				Meta::LAST_PULL,
				[
					'at'     => $now,
					'key_id' => $this->authenticatedKey,
					'route'  => substr( $request->get_route(), 0, 100 ),
				]
			);
		} catch ( \Throwable $e ) {
			$this->logger?->recordFailure( 'auth.record_pull', $e );
		}
	}

	/**
	 * Log a security event for a rejected request through the normal buffer.
	 *
	 * Aggregated per (reason, ip hash): the key id is part of the fingerprint and
	 * the event only when it is a key this site knows, so random key ids from an
	 * attacker cannot create a row per request (the Logger's anonymous cap bounds
	 * what remains, e.g. many client IPs).
	 *
	 * @param array{ok: bool, status: int, reason: string, key_id: string|null} $result  Result.
	 * @param \WP_REST_Request<array<string, mixed>>                            $request Request.
	 */
	private function logFailure( array $result, \WP_REST_Request $request ): void {
		if ( null === $this->logger ) {
			return;
		}
		$known = null !== $result['key_id'] && 'unknown_key' !== $result['reason'] && null !== $this->secrets->secretFor( $result['key_id'], time() );
		$event = self::failureEvent( $result['reason'], $known ? $result['key_id'] : null, $this->logger->context()->ipHash(), $request->get_route() );
		$this->logger->log( $event );
	}

	/**
	 * The api.auth_failed event (pure): fingerprint (reason, ip hash[, known key id]).
	 *
	 * @param string      $reason   Rejection reason.
	 * @param string|null $knownKey Key id when it is a key this site knows, else null.
	 * @param string|null $ipHash   Client IP hash.
	 * @param string      $endpoint REST route.
	 */
	public static function failureEvent( string $reason, ?string $knownKey, ?string $ipHash, string $endpoint ): Event {
		return Event::make( Category::SECURITY, 'api.auth_failed', 'rate_limited' === $reason ? Severity::NOTICE : Severity::WARNING )
			->summary( sprintf( 'Signed API request rejected (%s)', $reason ) )
			->object( 'api_key', $knownKey )
			->context(
				[
					'reason'   => $reason,
					'endpoint' => substr( $endpoint, 0, 100 ),
					'key_id'   => $knownKey,
				]
			)
			->fingerprint( 'api.auth_failed', $reason, $ipHash ?? 'none', $knownKey ?? '-' );
	}

	/**
	 * Failure result.
	 *
	 * @param string      $reason Reason.
	 * @param string|null $keyId  Well-formed key id if any.
	 * @return array{ok: bool, status: int, reason: string, key_id: string|null}
	 */
	private static function fail( string $reason, ?string $keyId ): array {
		return [
			'ok'     => false,
			'status' => 401,
			'reason' => $reason,
			'key_id' => $keyId,
		];
	}
}
