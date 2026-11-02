<?php
/**
 * MailListener.
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
 * Mail failures via `wp_mail_failed` (PLAN §4): error message (addresses
 * scrubbed), recipient count, subject hash. Never addresses, subject or body.
 */
final class MailListener implements Listener {

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
		add_action( 'wp_mail_failed', [ $this, 'onFailed' ], 10, 1 );
	}

	/**
	 * `wp_mail_failed`.
	 *
	 * @param mixed $error WP_Error.
	 */
	public function onFailed( mixed $error = null ): void {
		$logger = $this->logger;
		if ( null === $logger || ! $error instanceof \WP_Error ) {
			return;
		}
		$logger->guard(
			'mail.failed',
			function () use ( $logger, $error ): void {
				$data    = $error->get_error_data();
				$data    = is_array( $data ) ? $data : [];
				$subject = isset( $data['subject'] ) && is_scalar( $data['subject'] ) ? (string) $data['subject'] : null;
				$logger->log(
					self::buildEvent(
						(string) $error->get_error_code(),
						(string) $error->get_error_message(),
						isset( $data['to'] ) ? (array) $data['to'] : [],
						null === $subject ? null : $logger->redactor()->hash8( $subject ),
						isset( $data['phpmailer_exception_code'] ) && is_numeric( $data['phpmailer_exception_code'] ) ? (int) $data['phpmailer_exception_code'] : null,
						! empty( $data['attachments'] )
					)
				);
			}
		);
	}

	/**
	 * Build the event (pure).
	 *
	 * @param string       $code          WP_Error code.
	 * @param string       $message       Error message.
	 * @param array<mixed> $to           Recipients (only counted).
	 * @param string|null  $subjectHash   hash8 of the subject.
	 * @param int|null     $exceptionCode PHPMailer exception code.
	 * @param bool         $attachments   Had attachments.
	 */
	public static function buildEvent( string $code, string $message, array $to, ?string $subjectHash, ?int $exceptionCode, bool $attachments ): Event {
		$recipients = 0;
		foreach ( $to as $entry ) {
			$recipients += is_string( $entry ) ? count( array_filter( array_map( 'trim', explode( ',', $entry ) ) ) ) : 1;
		}
		$clean = Text::clip( Text::scrub( $message ), 512 );
		return Event::make( Category::MAIL, 'mail.failed', Severity::ERROR )
			->summary( 'Mail delivery failed: ' . $clean )
			->context(
				[
					'error_code'      => '' === $code ? null : $code,
					'error'           => $clean,
					'exception_code'  => $exceptionCode,
					'recipient_count' => $recipients,
					'subject_hash'    => $subjectHash,
					'attachments'     => $attachments,
				]
			)
			->fingerprint( 'mail.failed', $code, (string) $exceptionCode );
	}
}
