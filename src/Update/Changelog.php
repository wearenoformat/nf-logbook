<?php
/**
 * Changelog Markdown to HTML for the "View details" modal.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Update;

defined( 'ABSPATH' ) || exit;

/**
 * The small Markdown subset CHANGELOG.md uses: headings, bullet lists,
 * paragraphs, `code`, **bold** and [links](https://…). Everything is escaped
 * first; WordPress runs the sections through wp_kses() again before display.
 */
final class Changelog {

	/**
	 * Markdown to HTML.
	 *
	 * @param string $markdown Markdown.
	 */
	public static function toHtml( string $markdown ): string {
		$html = '';
		$list = false;
		$para = [];
		foreach ( preg_split( '/\R/', $markdown ) ? preg_split( '/\R/', $markdown ) : [] as $line ) {
			$trim = trim( $line );
			if ( 1 === preg_match( '/^(#{1,6})\s+(.+)$/', $trim, $m ) ) {
				$html .= self::paragraph( $para );
				$para  = [];
				if ( $list ) {
					$html .= '</ul>';
					$list  = false;
				}
				$level = min( 6, strlen( $m[1] ) + 2 );
				$html .= '<h' . $level . '>' . self::inline( $m[2] ) . '</h' . $level . '>';
				continue;
			}
			if ( 1 === preg_match( '/^[-*]\s+(.+)$/', $trim, $m ) ) {
				$html .= self::paragraph( $para );
				$para  = [];
				if ( ! $list ) {
					$html .= '<ul>';
					$list  = true;
				}
				$html .= '<li>' . self::inline( $m[1] ) . '</li>';
				continue;
			}
			if ( '' === $trim ) {
				$html .= self::paragraph( $para );
				$para  = [];
				if ( $list ) {
					$html .= '</ul>';
					$list  = false;
				}
				continue;
			}
			if ( $list && 1 === preg_match( '/^\s+/', $line ) ) {
				// Continuation of the previous bullet.
				$html = (string) preg_replace( '#</li>$#', ' ' . self::inline( $trim ) . '</li>', $html );
				continue;
			}
			$para[] = self::inline( $trim );
		}
		$html .= self::paragraph( $para );
		return $html . ( $list ? '</ul>' : '' );
	}

	/**
	 * A paragraph from collected lines ('' when there are none).
	 *
	 * @param list<string> $lines Inline HTML lines.
	 */
	private static function paragraph( array $lines ): string {
		return [] === $lines ? '' : '<p>' . implode( ' ', $lines ) . '</p>';
	}

	/**
	 * Inline markup on one escaped line.
	 *
	 * @param string $text Text.
	 */
	private static function inline( string $text ): string {
		$text = htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$text = (string) preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
		$text = (string) preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
		return (string) preg_replace( '/\[([^\]]+)\]\((https:\/\/[^\s)"]+)\)/', '<a href="$2">$1</a>', $text );
	}
}
