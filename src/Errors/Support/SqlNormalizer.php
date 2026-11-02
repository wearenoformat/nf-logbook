<?php
/**
 * SQL literal stripping.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Errors\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces every literal value in a SQL string by "?" so stored queries never
 * contain user data: quoted strings, numbers, hex/binary literals; IN lists
 * collapse to "IN (?)". Identifiers in backticks are kept.
 */
final class SqlNormalizer {

	public const MAX_LENGTH = 1024;

	/**
	 * Strip literals.
	 *
	 * @param string $sql SQL.
	 */
	public static function strip( string $sql ): string {
		$sql = (string) preg_replace( "/'(?:[^'\\\\]|\\\\.|'')*'/s", '?', $sql );
		$sql = (string) preg_replace( '/"(?:[^"\\\\]|\\\\.|"")*"/s', '?', $sql );
		$sql = (string) preg_replace( "/'[^']*$/s", '?', $sql );
		$sql = (string) preg_replace( '/\b(?:0x[0-9a-f]+|x\?|b\?)/i', '?', $sql );
		$sql = (string) preg_replace( '/(?<![\w`$.])-?\d+(?:\.\d+)?(?:e[+\-]?\d+)?(?![\w`])/i', '?', $sql );
		$sql = (string) preg_replace( '/\s+/', ' ', $sql );
		$sql = (string) preg_replace( '/\bIN\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', 'IN (?)', $sql );
		$sql = (string) preg_replace( '/\bVALUES\s*\(\s*\?(?:\s*,\s*\?)*\s*\)(?:\s*,\s*\(\s*\?(?:\s*,\s*\?)*\s*\))*/i', 'VALUES (?)', $sql );
		return Text::clip( trim( $sql ), self::MAX_LENGTH );
	}

	/**
	 * Remove literal values from a database error text while keeping identifiers
	 * (table names) that help diagnosis.
	 *
	 * @param string $error Error text.
	 */
	public static function stripError( string $error ): string {
		if ( str_contains( $error, '<' ) ) {
			// The SQLite drop-in stores an HTML report (query, query log, backtrace); keep the error message only.
			if ( preg_match( '#class="error_message"[^>]*>(.*?)</div>#s', $error, $m ) ) {
				$error = $m[1];
			}
			$error = Text::plain( $error );
		}
		$error = (string) preg_replace( "/Duplicate entry '.*?' for key/s", "Duplicate entry '?' for key", $error );
		$error = (string) preg_replace_callback(
			"/near '(.*)' at line/s",
			static fn( array $m ): string => "near '" . self::strip( $m[1] ) . "' at line",
			$error
		);
		$error = (string) preg_replace( "/(Incorrect \w+ value: )'.*?'/s", "$1'?'", $error );
		$error = (string) preg_replace( "/(Data truncated for column '[^']*' at row) \d+/", '$1 ?', $error );
		return Text::clip( Text::scrub( $error ), 512 );
	}
}
