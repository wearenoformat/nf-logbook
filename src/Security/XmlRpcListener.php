<?php
/**
 * XmlRpcListener.
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
 * XML-RPC calls and login errors (PLAN §4).
 *
 * WordPress fires xmlrpc_call only after a successful login and never for
 * system.* methods, so the called method is read once per request from the
 * request body (methodName element, first 64 KB) when the server is built
 * (xmlrpc_methods filter). xmlrpc_call is a fallback for when the body could
 * not be parsed. For system.multicall the inner method names are summarised.
 *
 * Failed logins: the first failure of a request reaches wp_login_failed
 * (AuthListener, source "xmlrpc"). Later attempts in the same request are
 * refused by WordPress without authenticating ("login_prevented"); those are
 * recorded here through the shared FailedLogin helper, so a multicall brute
 * force shows its real attempt count.
 */
final class XmlRpcListener implements Listener {

	public const MAX_BODY = 65536;

	private Logger $logger;

	private ?FailedLogin $failed = null;

	private bool $logged = false;

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
		add_filter( 'xmlrpc_methods', [ $this, 'onServerBuilt' ], PHP_INT_MAX, 1 );
		add_action( 'xmlrpc_call', [ $this, 'onCall' ], 10, 1 );
		add_filter( 'xmlrpc_login_error', [ $this, 'onLoginError' ], 10, 2 );
	}

	/**
	 * XML-RPC server constructed: log the requested method from the body.
	 *
	 * @param mixed $methods Methods (returned unchanged).
	 */
	public function onServerBuilt( mixed $methods ): mixed {
		$this->logger->guard(
			'xmlrpc.call',
			function (): void {
				if ( $this->logged || ! ( defined( 'XMLRPC_REQUEST' ) && constant( 'XMLRPC_REQUEST' ) ) || 'POST' !== $this->logger->context()->method() ) {
					return;
				}
				$parsed = self::parseBody( self::rawBody() );
				if ( null === $parsed ) {
					return; // Fallback: xmlrpc_call.
				}
				$this->logCall( $parsed['method'], $parsed['inner'] );
			}
		);
		return $methods;
	}

	/**
	 * Authenticated method call (fallback when the body was not parsed).
	 *
	 * @param mixed $method Method name.
	 */
	public function onCall( mixed $method ): void {
		$this->logger->guard(
			'xmlrpc.call',
			function () use ( $method ): void {
				if ( ! $this->logged ) {
					$this->logCall( self::cleanMethod( (string) $method ), [] );
				}
			}
		);
	}

	/**
	 * XML-RPC login error (filter value returned unchanged).
	 *
	 * @param mixed $error IXR_Error.
	 * @param mixed $user  WP_Error from wp_authenticate() or "login_prevented".
	 */
	public function onLoginError( mixed $error, mixed $user = null ): mixed {
		$this->logger->guard(
			'xmlrpc.login_error',
			function () use ( $user ): void {
				if ( $user instanceof \WP_Error && 'login_prevented' === $user->get_error_code() ) {
					$this->failedLogin()->record( null, 'login_prevented' );
				}
			}
		);
		return $error;
	}

	/**
	 * Extract the method name (and multicall inner names) from an XML-RPC body.
	 *
	 * @param string $body Raw body (only the first MAX_BODY bytes are inspected).
	 * @return array{method: string, inner: array<string, int>}|null
	 */
	public static function parseBody( string $body ): ?array {
		$body = substr( $body, 0, self::MAX_BODY );
		if ( 1 !== preg_match( '#<methodName>\s*([^<]{1,128})\s*</methodName>#i', $body, $m ) ) {
			return null;
		}
		$method = self::cleanMethod( $m[1] );
		$inner  = [];
		if ( 'system.multicall' === $method && preg_match_all( '#<name>\s*methodName\s*</name>\s*<value>\s*(?:<string>)?\s*([^<]{1,128})#i', $body, $all ) ) {
			foreach ( $all[1] as $name ) {
				$name = self::cleanMethod( $name );
				if ( isset( $inner[ $name ] ) || count( $inner ) < 20 ) {
					$inner[ $name ] = ( $inner[ $name ] ?? 0 ) + 1;
				}
			}
		}
		return [
			'method' => $method,
			'inner'  => $inner,
		];
	}

	/**
	 * Method name: [A-Za-z0-9_.:], max 64; anything else becomes "(invalid)".
	 *
	 * @param string $method Raw.
	 */
	public static function cleanMethod( string $method ): string {
		$method = trim( $method );
		return 1 === preg_match( '/^[A-Za-z0-9_.:]{1,64}$/', $method ) ? $method : '(invalid)';
	}

	/**
	 * Log one xmlrpc.call.
	 *
	 * @param string             $method Method.
	 * @param array<string, int> $inner  Multicall inner method counts.
	 */
	private function logCall( string $method, array $inner ): void {
		$this->logged = true;
		$ipHash       = $this->logger->context()->ipHash();
		$context      = [
			'method'  => $method,
			'ip_hash' => $ipHash,
		];
		if ( 'system.multicall' === $method ) {
			$context['multicall_calls']   = array_sum( $inner );
			$context['multicall_methods'] = $inner;
		}
		$this->logger->log(
			Event::make( Category::SECURITY, 'xmlrpc.call', Severity::WARNING )
				->object( 'method', $method )
				->summary( sprintf( 'XML-RPC call %s', $method ) )
				->context( $context )
				->fingerprint( 'xmlrpc.call', $method, $ipHash ?? '-' )
		);
	}

	/**
	 * Raw request body as read by xmlrpc.php.
	 */
	private static function rawBody(): string {
		global $HTTP_RAW_POST_DATA;
		if ( is_string( $HTTP_RAW_POST_DATA ) && '' !== $HTTP_RAW_POST_DATA ) {
			return substr( $HTTP_RAW_POST_DATA, 0, self::MAX_BODY );
		}
		$body = file_get_contents( 'php://input', false, null, 0, self::MAX_BODY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- request body stream.
		return is_string( $body ) ? $body : '';
	}

	/**
	 * Shared failed-login recorder.
	 */
	private function failedLogin(): FailedLogin {
		if ( null === $this->failed ) {
			$this->failed = new FailedLogin( $this->logger );
		}
		return $this->failed;
	}
}
