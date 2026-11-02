<?php
/**
 * RestProbeListener.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Security;

use Noformat\Logger\Category;
use Noformat\Logger\Config;
use Noformat\Logger\Event;
use Noformat\Logger\Listener;
use Noformat\Logger\Logger;
use Noformat\Logger\Security\Support\FailedLogin;
use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * REST probing: anonymous /wp/v2/users* (user enumeration) and REST authentication errors (PLAN §4).
 *
 * Observe only: both filters return their input unchanged, nothing is blocked.
 */
final class RestProbeListener implements Listener {

	private Logger $logger;

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
		add_filter( 'rest_pre_dispatch', [ $this, 'onPreDispatch' ], 0, 3 );
		add_filter( 'rest_authentication_errors', [ $this, 'onAuthenticationErrors' ], 1000, 1 );
	}

	/**
	 * Anonymous request to the users endpoint.
	 *
	 * @param mixed $result  Pre-dispatch result (returned unchanged).
	 * @param mixed $server  WP_REST_Server.
	 * @param mixed $request WP_REST_Request.
	 */
	public function onPreDispatch( mixed $result, mixed $server = null, mixed $request = null ): mixed {
		unset( $server );
		$this->logger->guard(
			'rest.users_probe',
			function () use ( $request ): void {
				if ( ! $request instanceof \WP_REST_Request || is_user_logged_in() ) {
					return;
				}
				$route = self::normaliseRoute( $request->get_route() );
				if ( ! self::isUsersRoute( $route ) ) {
					return;
				}
				$ipHash = $this->logger->context()->ipHash();
				$params = $request->get_query_params();
				$this->logger->log(
					Event::make( Category::SECURITY, 'rest.users_probe', Severity::WARNING )
						->object( 'route', $route )
						->summary( sprintf( 'Anonymous request to %s (user enumeration)', $route ) )
						->context(
							[
								'route'      => $route,
								'method'     => substr( (string) $request->get_method(), 0, 8 ),
								'has_search' => isset( $params['search'] ),
								'has_slug'   => isset( $params['slug'] ),
								'ip_hash'    => $ipHash,
							]
						)
						->fingerprint( 'rest.users_probe', $ipHash ?? '-' )
				);
			}
		);
		return $result;
	}

	/**
	 * Authentication error returned by any rest_authentication_errors filter.
	 *
	 * @param mixed $result Null, true or WP_Error (returned unchanged).
	 */
	public function onAuthenticationErrors( mixed $result ): mixed {
		if ( ! $result instanceof \WP_Error ) {
			return $result;
		}
		// Signed Controlroom pulls blocked by a security plugin: Authenticator::allowSignedRequests() clears the error.
		if ( \Noformat\Logger\Rest\Authenticator::isSignedNamespaceRequest( $_SERVER, self::currentRestRoute() ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- header presence only.
			return $result;
		}
		$this->logger->guard(
			'rest.auth_error',
			function () use ( $result ): void {
				$code   = FailedLogin::cleanCode( (string) $result->get_error_code() );
				$route  = self::normaliseRoute( self::currentRestRoute() );
				$ipHash = $this->logger->context()->ipHash();
				$data   = $result->get_error_data();
				$status = is_array( $data ) && isset( $data['status'] ) && is_numeric( $data['status'] ) ? (int) $data['status'] : null;
				$this->logger->log(
					Event::make( Category::SECURITY, 'rest.auth_error', Severity::WARNING )
						->object( 'route', $route )
						->summary( sprintf( 'REST authentication error %s on %s', $code, $route ) )
						->context(
							[
								'code'    => $code,
								'route'   => $route,
								'status'  => $status,
								'ip_hash' => $ipHash,
							]
						)
						->fingerprint( 'rest.auth_error', $code, $route, $ipHash ?? '-' )
				);
			}
		);
		return $result;
	}

	/**
	 * Whether a normalised route is the users collection or a user item.
	 *
	 * @param string $route Normalised route.
	 */
	public static function isUsersRoute( string $route ): bool {
		return 1 === preg_match( '#^/wp/v2/users(?:/|$)#', $route );
	}

	/**
	 * Lower-case route with numeric segments replaced by {id}, max 128 chars, no query.
	 *
	 * @param string $route Raw route.
	 */
	public static function normaliseRoute( string $route ): string {
		$route = strtolower( (string) preg_replace( '/[^\x21-\x7e]/', '', $route ) );
		$route = (string) strtok( $route, '?#' );
		$route = '/' . trim( $route, '/' );
		$route = (string) preg_replace( '#/\d+(?=/|$)#', '/{id}', $route );
		return substr( $route, 0, 128 );
	}

	/**
	 * REST route of the current request (rest_route query var or path after the prefix).
	 */
	private static function currentRestRoute(): string {
		global $wp;
		if ( is_object( $wp ) && isset( $wp->query_vars['rest_route'] ) && is_string( $wp->query_vars['rest_route'] ) ) {
			return $wp->query_vars['rest_route'];
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only route detection.
		if ( isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) {
			return sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only route detection.
		}
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$prefix = '/' . rest_get_url_prefix();
		$pos    = strpos( $uri, $prefix );
		return false === $pos ? '/' : substr( $uri, $pos + strlen( $prefix ) );
	}
}
