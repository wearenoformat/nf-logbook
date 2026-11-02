<?php
/**
 * AuthListener.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Security;

use Noformat\Logger\Category;
use Noformat\Logger\Config;
use Noformat\Logger\Event;
use Noformat\Logger\Listener;
use Noformat\Logger\Listeners\Support\System\UserRules;
use Noformat\Logger\Logger;
use Noformat\Logger\Security\Support\FailedLogin;
use Noformat\Logger\Security\Support\KnownClients;
use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Logins, logouts, failed logins (username hashed), auth cookie problems, app password failures (PLAN §4).
 *
 * Failed logins over XML-RPC reach wp_login_failed as well (source "xmlrpc");
 * XmlRpcListener only adds the attempts WordPress refuses without authenticating
 * (system.multicall amplification, "login_prevented").
 *
 * Administrator logins are compared with the last 10 ip hashes of that user
 * (user meta nflog_known_clients, hashes only; nflog_* meta is in the default
 * ignored meta keys); a new one makes the login a notice.
 */
final class AuthListener implements Listener {

	private Logger $logger;

	private ?FailedLogin $failed = null;

	/**
	 * Cookie problems already logged in this request, by kind.
	 *
	 * @var array<string, true>
	 */
	private array $cookieSeen = [];

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
		add_action( 'wp_login_failed', [ $this, 'onLoginFailed' ], 10, 2 );
		add_action( 'wp_login', [ $this, 'onLogin' ], 10, 2 );
		add_action( 'wp_logout', [ $this, 'onLogout' ], 10, 1 );
		foreach ( [ 'bad_hash', 'bad_username', 'bad_session_token', 'expired' ] as $kind ) {
			add_action(
				'auth_cookie_' . $kind,
				function ( $elements = [] ) use ( $kind ): void {
					$this->onCookieInvalid( $kind, $elements );
				},
				10,
				1
			);
		}
		add_action( 'application_password_failed_authentication', [ $this, 'onAppPasswordFailed' ], 10, 1 );
	}

	/**
	 * Failed login.
	 *
	 * @param mixed $username Attempted username or email (hashed only).
	 * @param mixed $error    WP_Error (WordPress ≥ 5.4).
	 */
	public function onLoginFailed( mixed $username, mixed $error = null ): void {
		$this->logger->guard(
			'auth.login_failed',
			function () use ( $username, $error ): void {
				$code = $error instanceof \WP_Error ? (string) $error->get_error_code() : 'unknown';
				$this->failedLogin()->record( is_string( $username ) ? $username : null, $code );
			}
		);
	}

	/**
	 * Successful login.
	 *
	 * @param mixed $login User login (not logged).
	 * @param mixed $user  WP_User.
	 */
	public function onLogin( mixed $login, mixed $user = null ): void {
		unset( $login );
		$this->logger->guard(
			'auth.login',
			function () use ( $user ): void {
				if ( ! $user instanceof \WP_User ) {
					return;
				}
				$id         = (int) $user->ID;
				$roles      = UserRules::roleList( array_values( (array) $user->roles ) );
				$role       = $roles[0] ?? null;
				$privileged = self::isPrivileged( $id, $roles );
				$ipHash     = $this->logger->context()->ipHash();
				$newClient  = null;
				if ( $privileged && null !== $ipHash ) {
					$known     = KnownClients::remember( get_user_meta( $id, KnownClients::META_KEY, true ), $ipHash );
					$newClient = $known['new'];
					update_user_meta( $id, KnownClients::META_KEY, $known['list'] );
				}
				$this->logger->log(
					Event::make( Category::SECURITY, 'auth.login', true === $newClient ? Severity::NOTICE : Severity::INFO )
						->object( 'user', $id, $role )
						->actor( 'user', $id, $role )
						->summary(
							true === $newClient
								? sprintf( 'User #%d (%s) logged in from a new client', $id, $role ?? 'no role' )
								: sprintf( 'User #%d logged in', $id )
						)
						->context(
							[
								'role'       => $role,
								'privileged' => $privileged,
								'source'     => $this->logger->context()->source()->value,
								'new_client' => $newClient,
								'ip_hash'    => $ipHash,
							]
						)
				);
			}
		);
	}

	/**
	 * Logout.
	 *
	 * @param mixed $userId User id (WordPress ≥ 5.5).
	 */
	public function onLogout( mixed $userId = 0 ): void {
		$this->logger->guard(
			'auth.logout',
			function () use ( $userId ): void {
				$id = (int) $userId;
				if ( $id <= 0 ) {
					return;
				}
				$this->logger->log(
					Event::make( Category::SECURITY, 'auth.logout', Severity::INFO )
						->object( 'user', $id )
						->actor( 'user', $id )
						->summary( sprintf( 'User #%d logged out', $id ) )
				);
			}
		);
	}

	/**
	 * Invalid auth cookie.
	 *
	 * @param string $kind     bad_hash|bad_username|bad_session_token|expired.
	 * @param mixed  $elements Parsed cookie (username, hmac, token, expiration, scheme); only the user id is derived.
	 */
	public function onCookieInvalid( string $kind, mixed $elements = [] ): void {
		$this->logger->guard(
			'auth.cookie_invalid',
			function () use ( $kind, $elements ): void {
				if ( isset( $this->cookieSeen[ $kind ] ) ) {
					return; // wp_validate_auth_cookie() runs several times per request.
				}
				$this->cookieSeen[ $kind ] = true;
				$userId                    = 0;
				if ( 'bad_username' !== $kind && is_array( $elements ) && isset( $elements['username'] ) && is_string( $elements['username'] ) ) {
					$user   = get_user_by( 'login', $elements['username'] );
					$userId = $user instanceof \WP_User ? (int) $user->ID : 0;
				}
				$scheme = is_array( $elements ) && isset( $elements['scheme'] ) && is_string( $elements['scheme'] ) ? substr( sanitize_key( $elements['scheme'] ), 0, 16 ) : null;
				$ipHash = $this->logger->context()->ipHash();
				$this->logger->log(
					Event::make( Category::SECURITY, 'auth.cookie_invalid', Severity::WARNING )
						->object( 'user', $userId > 0 ? $userId : null )
						->summary(
							$userId > 0
								? sprintf( 'Invalid auth cookie (%s) for user #%d', $kind, $userId )
								: sprintf( 'Invalid auth cookie (%s)', $kind )
						)
						->context(
							[
								'kind'    => $kind,
								'scheme'  => $scheme,
								'user_id' => $userId > 0 ? $userId : null,
								'ip_hash' => $ipHash,
							]
						)
						->fingerprint( 'auth.cookie_invalid', $kind, $ipHash ?? '-' )
				);
			}
		);
	}

	/**
	 * Application password authentication failed.
	 *
	 * @param mixed $error WP_Error.
	 */
	public function onAppPasswordFailed( mixed $error = null ): void {
		$this->logger->guard(
			'auth.app_password_failed',
			function () use ( $error ): void {
				$code     = FailedLogin::cleanCode( $error instanceof \WP_Error ? (string) $error->get_error_code() : 'unknown' );
				$username = isset( $_SERVER['PHP_AUTH_USER'] ) ? sanitize_user( wp_unslash( (string) $_SERVER['PHP_AUTH_USER'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_user(); only hashed.
				$userId   = '' === $username ? 0 : FailedLogin::resolveUserId( $username );
				$hash     = '' === $username ? null : $this->logger->redactor()->hashUsername( $username );
				$ipHash   = $this->logger->context()->ipHash();
				$this->logger->log(
					Event::make( Category::SECURITY, 'auth.app_password_failed', Severity::WARNING )
						->object( 'user', $userId > 0 ? $userId : null )
						->summary(
							$userId > 0
								? sprintf( 'Application password authentication failed for user #%d (%s)', $userId, $code )
								: sprintf( 'Application password authentication failed (%s)', $code )
						)
						->context(
							[
								'reason'        => $code,
								'user_id'       => $userId > 0 ? $userId : null,
								'user_exists'   => $userId > 0,
								'username_hash' => $hash,
								'ip_hash'       => $ipHash,
							]
						)
						->fingerprint( 'auth.app_password_failed', $code, $hash ?? '-', $ipHash ?? '-' )
				);
			}
		);
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

	/**
	 * Administrator (or super admin) check.
	 *
	 * @param int          $userId User id.
	 * @param list<string> $roles  Roles.
	 */
	private static function isPrivileged( int $userId, array $roles ): bool {
		foreach ( $roles as $role ) {
			if ( UserRules::isPrivileged( $role ) ) {
				return true;
			}
		}
		return function_exists( 'is_multisite' ) && is_multisite() && is_super_admin( $userId );
	}
}
