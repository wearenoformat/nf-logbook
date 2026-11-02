<?php
/**
 * Failed login recorder.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Security\Support;

use Noformat\Logger\Category;
use Noformat\Logger\Event;
use Noformat\Logger\Logger;
use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by AuthListener (wp_login_failed) and XmlRpcListener (xmlrpc_login_error
 * for logins WordPress refuses without calling wp_authenticate()), so both emit
 * auth.login_failed with the same shape and fingerprint, and both feed the
 * per-ip burst detector (auth.login_burst).
 *
 * The attempted username is only hashed (Redactor::hashUsername) and used to
 * resolve whether such a user exists; it is never stored.
 */
final class FailedLogin {

	private BurstDetector $detector;

	/**
	 * Constructor.
	 *
	 * @param Logger             $logger   Logger.
	 * @param BurstDetector|null $detector Detector (default: transients, 10 per 5 minutes, filterable).
	 */
	public function __construct( private Logger $logger, ?BurstDetector $detector = null ) {
		$this->detector = $detector ?? self::defaultDetector();
	}

	/**
	 * Detector configured from the noformat_logger/login_burst filter.
	 */
	public static function defaultDetector(): BurstDetector {
		$settings = [
			'threshold' => BurstDetector::DEFAULT_THRESHOLD,
			'window'    => BurstDetector::DEFAULT_WINDOW,
		];
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the failed-login burst rule: threshold failures per ip hash within window seconds.
			 *
			 * @param array{threshold: int, window: int} $settings Settings.
			 */
			$filtered = apply_filters( 'noformat_logger/login_burst', $settings );
			if ( is_array( $filtered ) ) {
				$settings = array_merge( $settings, $filtered );
			}
		}
		return new BurstDetector( new TransientBurstStore(), (int) $settings['threshold'], (int) $settings['window'] );
	}

	/**
	 * Record one failed login.
	 *
	 * @param string|null $username Attempted username or email (hashed, never stored); null when unknown.
	 * @param string      $reason   Error code, e.g. incorrect_password.
	 * @param int|null    $now      Unix time (tests).
	 */
	public function record( ?string $username, string $reason, ?int $now = null ): void {
		$now          = $now ?? time();
		$username     = null === $username ? null : trim( $username );
		$usernameHash = null === $username || '' === $username ? null : $this->logger->redactor()->hashUsername( $username );
		$userId       = null === $username || '' === $username ? 0 : self::resolveUserId( $username );
		$ipHash       = $this->logger->context()->ipHash();
		$reason       = self::cleanCode( $reason );

		$this->logger->log(
			Event::make( Category::SECURITY, 'auth.login_failed', Severity::WARNING )
				->object( 'user', $userId > 0 ? $userId : null )
				->summary( $userId > 0 ? sprintf( 'Failed login for user #%d', $userId ) : 'Failed login for an unknown username' )
				->context(
					[
						'username_hash' => $usernameHash,
						'ip_hash'       => $ipHash,
						'user_exists'   => $userId > 0,
						'user_id'       => $userId > 0 ? $userId : null,
						'reason'        => $reason,
					]
				)
				->fingerprint( 'auth.login_failed', $usernameHash ?? '-', $ipHash ?? '-' )
		);

		if ( null === $ipHash ) {
			return;
		}
		$count = $this->detector->hit( 'login_' . $ipHash, $now );
		if ( null === $count ) {
			return;
		}
		$this->logger->log(
			Event::make( Category::SECURITY, 'auth.login_burst', Severity::ERROR )
				->summary( sprintf( 'Burst of failed logins: %d from one client in %d seconds', $count, $this->detector->window() ) )
				->context(
					[
						'ip_hash'        => $ipHash,
						'failures'       => $count,
						'threshold'      => $this->detector->threshold(),
						'window_seconds' => $this->detector->window(),
					]
				)
				->fingerprint( 'auth.login_burst', $ipHash )
		);
	}

	/**
	 * User id for a login or email, 0 when none. No hooks, no output.
	 *
	 * @param string $username Login or email.
	 */
	public static function resolveUserId( string $username ): int {
		if ( '' === $username || ! function_exists( 'get_user_by' ) ) {
			return 0;
		}
		$user = get_user_by( 'login', $username );
		if ( ! $user instanceof \WP_User && str_contains( $username, '@' ) && function_exists( 'is_email' ) && is_email( $username ) ) {
			$user = get_user_by( 'email', $username );
		}
		return $user instanceof \WP_User ? (int) $user->ID : 0;
	}

	/**
	 * Error code: [a-z0-9_-], max 64.
	 *
	 * @param string $code Code.
	 */
	public static function cleanCode( string $code ): string {
		$code = substr( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $code ) ), 0, 64 );
		return '' === $code ? 'unknown' : $code;
	}
}
