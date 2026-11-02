<?php
/**
 * Request context.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Context;

use Noformat\Logger\Source;
use Noformat\Logger\Storage\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Per-process request information: id, source, actor, route, method and keyed
 * hashes of the client IP and user agent (never the raw values).
 */
final class RequestContext {

	private string $requestId;

	private ?string $salt = null;

	private bool $appPassword = false;

	private ?string $sourceOverride = null;

	/**
	 * Constructor.
	 *
	 * @param Meta|null   $meta Meta store holding the per-site salt (null in tests).
	 * @param string|null $salt Explicit salt (tests).
	 */
	public function __construct( private ?Meta $meta = null, ?string $salt = null ) {
		$this->requestId = bin2hex( random_bytes( 16 ) );
		$this->salt      = $salt;
	}

	/**
	 * 32 hex chars, one per PHP process.
	 */
	public function requestId(): string {
		return $this->requestId;
	}

	/**
	 * Called from application_password_did_authenticate.
	 */
	public function markAppPassword(): void {
		$this->appPassword = true;
	}

	/**
	 * Force a source (e.g. "webhook" for a signed API call).
	 *
	 * @param Source $source Source.
	 */
	public function overrideSource( Source $source ): void {
		$this->sourceOverride = $source->value;
	}

	/**
	 * Detect the request source. Evaluated per call: REST_REQUEST is defined late.
	 */
	public function source(): Source {
		if ( null !== $this->sourceOverride ) {
			return Source::from( $this->sourceOverride );
		}
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			return Source::CLI;
		}
		if ( function_exists( 'wp_doing_cron' ) ? wp_doing_cron() : ( defined( 'DOING_CRON' ) && constant( 'DOING_CRON' ) ) ) {
			return Source::CRON;
		}
		if ( defined( 'XMLRPC_REQUEST' ) && constant( 'XMLRPC_REQUEST' ) ) {
			return Source::XMLRPC;
		}
		if ( $this->isRest() ) {
			return $this->appPassword ? Source::APP_PASSWORD : Source::REST;
		}
		if ( function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : ( defined( 'DOING_AJAX' ) && constant( 'DOING_AJAX' ) ) ) {
			return Source::AJAX;
		}
		if ( $this->appPassword ) {
			return Source::APP_PASSWORD;
		}
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			return Source::ADMIN;
		}
		return Source::FRONTEND;
	}

	/**
	 * REST detection that also works before REST_REQUEST is defined.
	 */
	private function isRest(): bool {
		if ( defined( 'REST_REQUEST' ) && constant( 'REST_REQUEST' ) ) {
			return true;
		}
		$prefix = '/' . ( function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json' ) . '/';
		if ( str_contains( (string) $this->route() . '/', $prefix ) ) {
			return true;
		}
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only detection.
		if ( isset( $_GET['rest_route'] ) ) {
			return true;
		}
		$ajax = function_exists( 'wp_doing_ajax' ) && wp_doing_ajax();
		return ! $ajax && function_exists( 'wp_is_json_request' ) && wp_is_json_request();
	}

	/**
	 * Actor of the current request.
	 *
	 * @return array{type: string, id: string|null, role: string|null}
	 */
	public function actor(): array {
		$userId = 0;
		$role   = null;
		if ( function_exists( 'did_action' ) && did_action( 'init' ) && function_exists( 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();
			if ( $user instanceof \WP_User && $user->ID > 0 ) {
				$userId = (int) $user->ID;
				$roles  = array_values( (array) $user->roles );
				$role   = isset( $roles[0] ) ? (string) $roles[0] : null;
			}
		}
		if ( $userId > 0 ) {
			return [
				'type' => 'user',
				'id'   => (string) $userId,
				'role' => $role,
			];
		}
		$source = $this->source();
		$type   = match ( $source ) {
			Source::CLI  => 'cli',
			Source::CRON => 'cron',
			default      => 'anonymous',
		};
		return [
			'type' => $type,
			'id'   => null,
			'role' => null,
		];
	}

	/**
	 * Whether the actor can be resolved yet (current user is known after init).
	 */
	public function actorResolvable(): bool {
		return function_exists( 'did_action' ) && did_action( 'init' ) > 0;
	}

	/**
	 * Request path without the query string (max 255); the author slug of
	 * author archives (/author/<nicename>/, derived from the username) is
	 * replaced by "{hash8}".
	 */
	public function route(): ?string {
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			return null;
		}
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? self::printable( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by printable().
		$route = self::cleanRoute( $uri );
		return null === $route ? null : self::maskAuthor( $route, fn( string $slug ): string => substr( $this->hash( 'author:' . $slug ), 0, 8 ), self::authorBase() );
	}

	/**
	 * Replace the segment after the author base by "{hash8}".
	 *
	 * @param string                   $route Clean route.
	 * @param callable(string): string $hash  Hasher (8 hex).
	 * @param string                   $base  Author base ("author").
	 */
	public static function maskAuthor( string $route, callable $hash, string $base = 'author' ): string {
		$base = trim( $base, '/' );
		if ( '' === $base || ! str_contains( $route, '/' . $base . '/' ) ) {
			return $route;
		}
		return (string) preg_replace_callback(
			'#(/' . preg_quote( $base, '#' ) . '/)([^/]+)#',
			static fn( array $m ): string => $m[1] . '{' . $hash( strtolower( rawurldecode( $m[2] ) ) ) . '}',
			$route
		);
	}

	/**
	 * Author permalink base (default "author").
	 */
	private static function authorBase(): string {
		global $wp_rewrite;
		$base = is_object( $wp_rewrite ) && isset( $wp_rewrite->author_base ) && is_string( $wp_rewrite->author_base ) ? $wp_rewrite->author_base : 'author';
		return '' === $base ? 'author' : $base;
	}

	/**
	 * Keep printable ASCII only (percent-encoding survives, control bytes do not).
	 *
	 * @param string $value Raw value.
	 */
	public static function printable( string $value ): string {
		return (string) preg_replace( '/[^\x21-\x7e]/', '', $value );
	}

	/**
	 * Strip query/fragment and clip.
	 *
	 * @param string $uri URI.
	 */
	public static function cleanRoute( string $uri ): ?string {
		if ( '' === $uri ) {
			return null;
		}
		$path = strtok( $uri, '?#' );
		$path = false === $path ? '' : $path;
		if ( '' === $path ) {
			$path = '/';
		}
		return substr( $path, 0, 255 );
	}

	/**
	 * HTTP method or null on CLI.
	 */
	public function method(): ?string {
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
			return null;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		return '' === $method ? null : substr( $method, 0, 8 );
	}

	/**
	 * Why an option or meta write in this request is machine state rather than a
	 * human change (null: treat it as a change). Humans change things with
	 * POST/PUT/PATCH/DELETE, REST writes, forms and WP-CLI; a write while a page,
	 * feed or REST resource is merely read is a plugin caching or bookkeeping:
	 *
	 * - `cron`: WP-Cron;
	 * - `heartbeat`: the admin-ajax `heartbeat` action;
	 * - `read_request`: a GET or HEAD request (front-end views, admin page loads,
	 *   REST GETs). Exception: a non-REST GET that carries `_wpnonce` is a
	 *   deliberate admin action (activate/trash links, bulk edit on edit.php).
	 *
	 * WP-CLI is never machine state here.
	 */
	public function machineWriteReason(): ?string {
		$source = $this->source();
		if ( Source::CLI === $source ) {
			return null;
		}
		if ( Source::CRON === $source ) {
			return 'cron';
		}
		// phpcs:disable WordPress.Security.NonceVerification -- read-only request classification.
		if ( Source::AJAX === $source ) {
			$action = $_REQUEST['action'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- compared to a literal.
			if ( 'heartbeat' === $action ) {
				return 'heartbeat';
			}
		}
		$method = $this->method();
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return null;
		}
		$rest = Source::REST === $source || Source::APP_PASSWORD === $source;
		if ( ! $rest && isset( $_GET['_wpnonce'] ) ) {
			return null;
		}
		// phpcs:enable WordPress.Security.NonceVerification
		return 'read_request';
	}

	/**
	 * Client IP (REMOTE_ADDR, filterable for trusted proxies). Never stored.
	 */
	public function clientIp(): ?string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the client IP used for ip_hash and the API allowlist (e.g. for a trusted proxy).
			 *
			 * @param string $ip REMOTE_ADDR.
			 */
			$ip = (string) apply_filters( 'noformat_logger/client_ip', $ip );
		}
		return false === filter_var( $ip, FILTER_VALIDATE_IP ) ? null : $ip;
	}

	/**
	 * Keyed IP hash.
	 */
	public function ipHash(): ?string {
		$ip = $this->clientIp();
		return null === $ip ? null : $this->hash( 'ip:' . $ip );
	}

	/**
	 * Keyed user agent hash.
	 */
	public function uaHash(): ?string {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return '' === $ua ? null : $this->hash( 'ua:' . $ua );
	}

	/**
	 * First 32 hex of HMAC-SHA256(site salt, value).
	 *
	 * @param string $value Value.
	 */
	public function hash( string $value ): string {
		return substr( hash_hmac( 'sha256', $value, $this->salt() ), 0, 32 );
	}

	/**
	 * Per-site salt (generated at install, independent of the API secret).
	 */
	public function salt(): string {
		if ( null !== $this->salt ) {
			return $this->salt;
		}
		$salt = null;
		try {
			$salt = $this->meta?->get( Meta::SALT );
		} catch ( \Throwable $e ) {
			$salt = null;
		}
		if ( null === $salt || '' === $salt ) {
			// Storage unavailable: derive a stable fallback from the WordPress salts.
			$salt = hash( 'sha256', 'nflog-salt|' . ( function_exists( 'wp_salt' ) ? wp_salt( 'logged_in' ) : 'none' ) );
		}
		$this->salt = $salt;
		return $salt;
	}

	/**
	 * Contract request block.
	 *
	 * @param bool $hashes Include ip_hash / ua_hash (false: the Logger fills them when the event is written).
	 * @return array{id: string, source: string, route: string|null, method: string|null, ip_hash: string|null, ua_hash: string|null}
	 */
	public function toArray( bool $hashes = true ): array {
		$cli = defined( 'WP_CLI' ) && constant( 'WP_CLI' );
		return [
			'id'      => $this->requestId,
			'source'  => $this->source()->value,
			'route'   => $this->route(),
			'method'  => $this->method(),
			'ip_hash' => $cli || ! $hashes ? null : $this->ipHash(),
			'ua_hash' => $cli || ! $hashes ? null : $this->uaHash(),
		];
	}
}
