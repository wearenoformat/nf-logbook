<?php
/**
 * HttpApiListener.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Errors;

use Noformat\Logger\Category;
use Noformat\Logger\Config;
use Noformat\Logger\Errors\Support\Text;
use Noformat\Logger\Event;
use Noformat\Logger\Listener;
use Noformat\Logger\Logger;
use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Failed outbound HTTP calls through the WordPress HTTP API (PLAN §4): a
 * WP_Error or a status ≥ 500. Records host, path (no query), method, status or
 * error code and duration. Never request/response bodies or headers. Hosts that
 * are IP literals are reported as "ip-<hash8>" with `ip_scope` (contract §2: no
 * raw IPs leave the site). Path segments that look like secrets or personal
 * data (webhook tokens, ids embedded in Slack/Discord/Telegram URLs, e-mail
 * addresses in CRM paths) become "{hash8}" (scrubPath()).
 */
final class HttpApiListener implements Listener {

	public const START_ARG = '_nflog_started';

	private ?Logger $logger = null;

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
		$this->logger = $logger;
		add_filter( 'http_request_args', [ $this, 'stamp' ], PHP_INT_MAX, 1 );
		add_action( 'http_api_debug', [ $this, 'onDebug' ], 10, 5 );
	}

	/**
	 * Add the start time to the request args.
	 *
	 * @param mixed $args Args.
	 */
	public function stamp( mixed $args ): mixed {
		if ( is_array( $args ) ) {
			$args[ self::START_ARG ] = microtime( true );
		}
		return $args;
	}

	/**
	 * `http_api_debug`.
	 *
	 * @param mixed $response Response or WP_Error.
	 * @param mixed $context  'response'.
	 * @param mixed $transport Transport class.
	 * @param mixed $args     Parsed args.
	 * @param mixed $url      URL.
	 */
	public function onDebug( mixed $response = null, mixed $context = '', mixed $transport = '', mixed $args = [], mixed $url = '' ): void {
		unset( $transport );
		$logger = $this->logger;
		if ( null === $logger || 'response' !== $context ) {
			return;
		}
		$isError = $response instanceof \WP_Error;
		$status  = 0;
		if ( ! $isError ) {
			$status = is_array( $response ) && isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
			if ( $status < 500 ) {
				return;
			}
		}
		$logger->guard(
			'http.debug',
			function () use ( $logger, $response, $isError, $status, $args, $url ): void {
				$args  = is_array( $args ) ? $args : [];
				$code  = $isError ? (string) $response->get_error_code() : null;
				$error = $isError ? (string) $response->get_error_message() : null;
				$start = isset( $args[ self::START_ARG ] ) && is_float( $args[ self::START_ARG ] ) ? $args[ self::START_ARG ] : null;
				$ms    = null === $start ? null : (int) round( ( microtime( true ) - $start ) * 1000 );
				$event = self::buildEvent( (string) $url, (string) ( $args['method'] ?? 'GET' ), $status, $code, $error, $ms, $this->hashIp( ... ) );
				if ( null !== $event ) {
					$logger->log( $event );
				}
			}
		);
	}

	/**
	 * Build the event (pure).
	 *
	 * @param string                   $url        URL.
	 * @param string                   $method     Method.
	 * @param int                      $status     HTTP status (0 on WP_Error).
	 * @param string|null              $errorCode  WP_Error code.
	 * @param string|null              $error      WP_Error message.
	 * @param int|null                 $durationMs Duration.
	 * @param callable(string): string $hashIp     Hasher (8 hex) for IP hosts and secret path segments.
	 */
	public static function buildEvent( string $url, string $method, int $status, ?string $errorCode, ?string $error, ?int $durationMs, callable $hashIp ): ?Event {
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- fallback.
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}
		$host    = strtolower( trim( (string) $parts['host'], '[]' ) );
		$ipScope = null;
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ipScope = self::ipScope( $host );
			$host    = 'ip-' . $hashIp( $host );
		}
		$path    = substr( self::scrubPath( (string) ( $parts['path'] ?? '/' ), $hashIp ), 0, 255 );
		$method  = strtoupper( substr( $method, 0, 8 ) );
		$outcome = null !== $errorCode ? $errorCode : (string) $status;
		$message = null === $error ? null : Text::clip( Text::scrub( $error ), 512 );
		$context = [
			'host'        => $host,
			'scheme'      => isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : null,
			'port'        => isset( $parts['port'] ) ? (int) $parts['port'] : null,
			'path'        => $path,
			'method'      => $method,
			'status'      => $status > 0 ? $status : null,
			'error_code'  => $errorCode,
			'error'       => $message,
			'duration_ms' => $durationMs,
		];
		if ( null !== $ipScope ) {
			$context['ip_scope'] = $ipScope;
		}
		return Event::make( Category::HTTP, 'http.request_failed', Severity::ERROR )
			->object( 'host', $host )
			->summary( sprintf( 'Outbound HTTP %s %s%s failed: %s', $method, $host, $path, null !== $message ? $message : 'HTTP ' . $status ) )
			->context( $context )
			->fingerprint( 'http.request_failed', $host, $path, $outcome );
	}

	/**
	 * Replace path segments that look like tokens or personal data by "{hash8}":
	 * any segment containing "@", any segment longer than 12 characters that
	 * contains a digit, and any opaque segment of 20+ token characters. Short
	 * structural segments ("/api/v1/messages") are kept. E-mail addresses and IPs
	 * left anywhere are scrubbed as well.
	 *
	 * @param string                   $path Path (no query).
	 * @param callable(string): string $hash Hasher (8 hex).
	 */
	public static function scrubPath( string $path, callable $hash ): string {
		$segments = explode( '/', $path );
		foreach ( $segments as $i => $segment ) {
			if ( '' === $segment ) {
				continue;
			}
			$decoded = rawurldecode( $segment );
			$secret  = str_contains( $decoded, '@' )
				|| ( strlen( $decoded ) > 12 && 1 === preg_match( '/\d/', $decoded ) )
				|| ( strlen( $decoded ) >= 20 && 1 === preg_match( '/^[A-Za-z0-9_\-.:=+~]+$/', $decoded ) );
			if ( $secret ) {
				$segments[ $i ] = '{' . $hash( 'path:' . $decoded ) . '}';
			}
		}
		return Text::scrub( implode( '/', $segments ) );
	}

	/**
	 * Scope of an IP: loopback | private | reserved | public.
	 *
	 * @param string $ip IP.
	 */
	public static function ipScope( string $ip ): string {
		if ( '::1' === $ip || str_starts_with( $ip, '127.' ) ) {
			return 'loopback';
		}
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE ) ) {
			return 'private';
		}
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE ) ) {
			return 'reserved';
		}
		return 'public';
	}

	/**
	 * Keyed hash of an IP host (8 hex).
	 *
	 * @param string $ip IP.
	 */
	private function hashIp( string $ip ): string {
		return null === $this->logger ? substr( hash( 'sha256', $ip ), 0, 8 ) : $this->logger->redactor()->hash8( 'host:' . $ip );
	}
}
