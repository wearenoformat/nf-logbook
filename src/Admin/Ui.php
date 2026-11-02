<?php
/**
 * Small rendering helpers shared by the admin screens.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Admin;

use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Design-system markup (see assets/admin.css). Every method returns escaped HTML.
 */
final class Ui {

	/**
	 * Severity badge (text always present: no colour-only meaning).
	 *
	 * @param Severity    $severity Severity.
	 * @param string|null $label    Text instead of the severity name.
	 */
	public static function badge( Severity $severity, ?string $label = null ): string {
		return '<span class="nflog-badge nflog-badge--' . esc_attr( $severity->value ) . '">' . esc_html( $label ?? self::severityLabel( $severity ) ) . '</span>';
	}

	/**
	 * Translated severity name.
	 *
	 * @param Severity $severity Severity.
	 */
	public static function severityLabel( Severity $severity ): string {
		return match ( $severity ) {
			Severity::CRITICAL => __( 'Critical', 'noformat-logger' ),
			Severity::ERROR    => __( 'Error', 'noformat-logger' ),
			Severity::WARNING  => __( 'Warning', 'noformat-logger' ),
			Severity::NOTICE   => __( 'Notice', 'noformat-logger' ),
			Severity::INFO     => __( 'Info', 'noformat-logger' ),
			Severity::DEBUG    => __( 'Debug', 'noformat-logger' ),
		};
	}

	/**
	 * Source label for chips ("Admin", "REST", "WP-CLI" …).
	 *
	 * @param string $source Source value.
	 */
	public static function sourceLabel( string $source ): string {
		return match ( $source ) {
			'admin'        => __( 'Admin', 'noformat-logger' ),
			'rest'         => __( 'REST', 'noformat-logger' ),
			'ajax'         => __( 'Ajax', 'noformat-logger' ),
			'xmlrpc'       => __( 'XML-RPC', 'noformat-logger' ),
			'cron'         => __( 'Cron', 'noformat-logger' ),
			'cli'          => __( 'WP-CLI', 'noformat-logger' ),
			'frontend'     => __( 'Frontend', 'noformat-logger' ),
			'app_password' => __( 'App password', 'noformat-logger' ),
			'webhook'      => __( 'Webhook', 'noformat-logger' ),
			'studio'       => __( 'Studio', 'noformat-logger' ),
			'build'        => __( 'Build', 'noformat-logger' ),
			''             => __( 'Unknown', 'noformat-logger' ),
			default        => $source,
		};
	}

	/**
	 * Link styled as a button.
	 *
	 * @param string $url     URL.
	 * @param string $label   Text.
	 * @param string $variant primary|secondary|ghost.
	 * @param string $icon    Optional icon name (see icon()).
	 * @param string $extra   Extra attributes (already escaped).
	 */
	public static function button( string $url, string $label, string $variant = 'secondary', string $icon = '', string $extra = '' ): string {
		return '<a class="nflog-btn nflog-btn--' . esc_attr( $variant ) . '" href="' . esc_url( $url ) . '"' . ( '' === $extra ? '' : ' ' . $extra ) . '>' . ( '' === $icon ? '' : self::icon( $icon ) ) . '<span>' . esc_html( $label ) . '</span></a>';
	}

	/**
	 * Toggle chip (link).
	 *
	 * @param string $url    URL toggling the filter.
	 * @param string $label  Text.
	 * @param bool   $active Active state.
	 * @param string $extra  Extra HTML inside (escaped).
	 */
	public static function chip( string $url, string $label, bool $active, string $extra = '' ): string {
		return '<a class="nflog-chip' . ( $active ? ' is-active' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $active ? ' aria-pressed="true"' : ' aria-pressed="false"' ) . ' role="button">' . esc_html( $label ) . $extra . '</a>';
	}

	/**
	 * Inline icon (stroke icons, 16px, currentColor).
	 *
	 * @param string $name download|search|chevron|lock|filter|refresh|copy|external|arrow|check|alert|clock|shield.
	 */
	public static function icon( string $name ): string {
		$paths = [
			'download' => '<path d="M8 2.5v7.5m0 0L5 7m3 3l3-3M3 12.5h10"/>',
			'search'   => '<circle cx="7" cy="7" r="4.25"/><path d="M10.2 10.2L13.5 13.5"/>',
			'chevron'  => '<path d="M5 6.5l3 3 3-3"/>',
			'lock'     => '<rect x="3.5" y="7" width="9" height="6.5" rx="1.5"/><path d="M5.5 7V5.25a2.5 2.5 0 015 0V7"/>',
			'filter'   => '<path d="M2.5 4h11M4.5 8h7M6.5 12h3"/>',
			'refresh'  => '<path d="M13 8a5 5 0 11-1.5-3.55M13 2.5v2.5h-2.5"/>',
			'copy'     => '<rect x="5.5" y="5.5" width="8" height="8" rx="1.5"/><path d="M10.5 5.5V4A1.5 1.5 0 009 2.5H4A1.5 1.5 0 002.5 4v5A1.5 1.5 0 004 10.5h1.5"/>',
			'external' => '<path d="M9 2.5h4.5V7M13.5 2.5L7.5 8.5M11.5 9.5v3a1 1 0 01-1 1h-7a1 1 0 01-1-1v-7a1 1 0 011-1h3"/>',
			'arrow'    => '<path d="M3 8h10m0 0L9.5 4.5M13 8l-3.5 3.5"/>',
			'check'    => '<path d="M3.5 8.5l3 3 6-7"/>',
			'alert'    => '<path d="M8 2.5l6 10.5H2L8 2.5z"/><path d="M8 6.5v3M8 11.25v.01"/>',
			'clock'    => '<circle cx="8" cy="8" r="5.5"/><path d="M8 5v3.25l2 1.25"/>',
			'shield'   => '<path d="M8 2l5 2v4c0 3-2.2 5.2-5 6-2.8-.8-5-3-5-6V4l5-2z"/>',
			'pulse'    => '<path d="M1.5 8.5h3l1.5-4 3 8 2-5.5 1 1.5h2.5"/>',
		];
		$path  = $paths[ $name ] ?? '';
		return '<svg class="nflog-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
	}

	/**
	 * Monogram avatar (CLI, Cron, System, visitors, users without a picture).
	 *
	 * @param string $name Display name.
	 * @param string $kind Actor kind (cli, cron, system, anonymous, app, user …).
	 */
	public static function monogram( string $name, string $kind ): string {
		$text = match ( $kind ) {
			'cli'       => '>_',
			'cron'      => self::icon( 'clock' ),
			'system'    => self::icon( 'pulse' ),
			'anonymous' => '?',
			'app'       => self::icon( 'shield' ),
			default     => esc_html( self::initials( $name ) ),
		};
		$tone = 'user' === $kind || 'user_hidden' === $kind || 'user_deleted' === $kind ? 'tone-' . ( abs( crc32( $name ) ) % 6 ) : 'tone-' . $kind;
		return '<span class="nflog-avatar nflog-avatar--mono nflog-avatar--' . esc_attr( $tone ) . '" aria-hidden="true">' . $text . '</span>';
	}

	/**
	 * Up to two initials.
	 *
	 * @param string $name Name.
	 */
	public static function initials( string $name ): string {
		$words = preg_split( '/[\s\-_.]+/u', trim( $name ) );
		$words = array_values( array_filter( false === $words ? [] : $words, static fn( string $w ): bool => '' !== $w ) );
		$first = static fn( string $w ): string => function_exists( 'mb_substr' ) ? mb_strtoupper( mb_substr( $w, 0, 1 ) ) : strtoupper( substr( $w, 0, 1 ) );
		if ( [] === $words ) {
			return '?';
		}
		return $first( $words[0] ) . ( isset( $words[1] ) ? $first( $words[ count( $words ) - 1 ] ) : '' );
	}

	/**
	 * Empty state block.
	 *
	 * @param string $title    Heading.
	 * @param string $guidance One line of guidance.
	 * @param string $action   Optional action HTML (escaped).
	 */
	public static function empty( string $title, string $guidance, string $action = '' ): string {
		return '<div class="nflog-empty"><div class="nflog-empty__icon">' . self::icon( 'search' ) . '</div><p class="nflog-empty__title">' . esc_html( $title ) . '</p><p class="nflog-empty__text">' . esc_html( $guidance ) . '</p>' . $action . '</div>';
	}

	/**
	 * Page header: title, subtitle and an actions slot.
	 *
	 * @param string $title    Title.
	 * @param string $subtitle Subtitle.
	 * @param string $actions  Actions HTML (escaped).
	 */
	public static function header( string $title, string $subtitle, string $actions = '' ): string {
		return '<header class="nflog-header"><div class="nflog-header__text"><h1 class="nflog-title">' . esc_html( $title ) . '</h1>'
			. ( '' === $subtitle ? '' : '<p class="nflog-subtitle">' . esc_html( $subtitle ) . '</p>' ) . '</div>'
			. ( '' === $actions ? '' : '<div class="nflog-header__actions">' . $actions . '</div>' ) . '</header>';
	}

	/**
	 * Notice inside the design system.
	 *
	 * @param string $text Text.
	 * @param string $tone info|success|warning|error.
	 */
	public static function notice( string $text, string $tone = 'info' ): string {
		return '<div class="nflog-notice nflog-notice--' . esc_attr( $tone ) . '" role="' . ( 'error' === $tone ? 'alert' : 'status' ) . '">' . self::icon( 'error' === $tone || 'warning' === $tone ? 'alert' : 'check' ) . '<p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Local time of an event.
	 *
	 * @param int    $ms     Unix ms.
	 * @param string $format Date format.
	 */
	public static function localTime( int $ms, string $format ): string {
		$out = wp_date( $format, intdiv( $ms, 1000 ) );
		return is_string( $out ) ? $out : gmdate( $format, intdiv( $ms, 1000 ) );
	}

	/**
	 * <time> element with the exact UTC time in the title.
	 *
	 * @param int    $ms     Unix ms.
	 * @param string $format Visible format (site time).
	 */
	public static function time( int $ms, string $format ): string {
		$utc = gmdate( 'Y-m-d\TH:i:s', intdiv( $ms, 1000 ) ) . sprintf( '.%03dZ', $ms % 1000 );
		return '<time datetime="' . esc_attr( $utc ) . '" title="' . esc_attr( $utc ) . '">' . esc_html( self::localTime( $ms, $format ) ) . '</time>';
	}
}
