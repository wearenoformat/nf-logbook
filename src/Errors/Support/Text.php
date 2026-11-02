<?php
/**
 * Text helpers for error payloads.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Errors\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Message scrubbing (personal data), truncation and fingerprint normalisation.
 */
final class Text {

	public const MAX_MESSAGE = 1024;

	/**
	 * Query parameter names whose values are credentials (pre-signed S3 URLs,
	 * API tokens, session ids): `name=value` becomes `name=[redacted]`.
	 */
	public const SECRET_PARAM_PATTERN = '/(^|[?&;])(signature|sig|awsaccesskeyid|x-amz-[a-z0-9_-]*|token|key|secret|auth|password|pass|session|sid|api_key|apikey|access_key|credential)=([^&#\s"\'<>\\\\]*)/i';

	/**
	 * Remove personal data and credentials that messages and values commonly
	 * carry: credential query parameters (SECRET_PARAM_PATTERN) are redacted,
	 * e-mail addresses and IP addresses (IPv4 and IPv6) become placeholders.
	 *
	 * @param string $text Text.
	 */
	public static function scrub( string $text ): string {
		$text = self::scrubQueryParams( $text );
		$text = (string) preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $text );
		$text = (string) preg_replace( '/(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])/', '[ip]', $text );
		return (string) preg_replace_callback(
			'/(?<![\w:])[0-9a-f]*:[0-9a-f:]*:[0-9a-f:]*(?![\w:])/i',
			static fn( array $m ): string => false === filter_var( $m[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? $m[0] : '[ip]',
			$text
		);
	}

	/**
	 * Rewrite credential query parameters inside any string (URLs, query strings,
	 * `&amp;`-encoded HTML) to `name=[redacted]`; the rest of the URL is kept.
	 *
	 * @param string $text Text.
	 */
	public static function scrubQueryParams( string $text ): string {
		if ( ! str_contains( $text, '=' ) ) {
			return $text;
		}
		return (string) preg_replace( self::SECRET_PARAM_PATTERN, '$1$2=[redacted]', $text );
	}

	/**
	 * Clip to a byte length on a UTF-8 boundary, marking the cut.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max bytes.
	 */
	public static function clip( string $text, int $max = self::MAX_MESSAGE ): string {
		if ( strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = substr( $text, 0, $max - 3 );
		if ( function_exists( 'mb_strcut' ) ) {
			$cut = mb_strcut( $text, 0, $max - 3, 'UTF-8' );
		}
		return $cut . '...';
	}

	/**
	 * Normalise a message for fingerprinting: numbers, hex and quoted values are
	 * stripped so "Undefined offset: 3" and "Undefined offset: 7" collapse.
	 *
	 * @param string $message Message (already relativised).
	 */
	public static function normalize( string $message ): string {
		$message = strtolower( $message );
		$message = (string) preg_replace( '/0x[0-9a-f]+/', '#', $message );
		$message = (string) preg_replace( '/\b[0-9a-f]{8,}\b/', '#', $message );
		$message = (string) preg_replace( '/\d+/', '#', $message );
		$message = (string) preg_replace( '/"[^"]*"|\'[^\']*\'/', '?', $message );
		$message = (string) preg_replace( '/\s+/', ' ', $message );
		return trim( substr( $message, 0, 512 ) );
	}

	/**
	 * Strip tags and collapse whitespace (WordPress deprecation messages contain HTML).
	 *
	 * @param string $text Text.
	 */
	public static function plain( string $text ): string {
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $text ) : strip_tags( $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- fallback without WordPress.
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}
}
