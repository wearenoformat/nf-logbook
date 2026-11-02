<?php
/**
 * Who may read the event log in wp-admin.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Admin;

use Noformat\Logger\Config;
use Noformat\Logger\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Access rules for the Content log, the System log, the export and the dashboard widget (PLAN D11).
 *
 * Two settings with the same two values:
 * - `events_visible_to` (constant NOFORMAT_LOGGER_EVENTS_VISIBLE_TO): Content log,
 *   its export and the dashboard widget;
 * - `system_log_visible_to` (constant NOFORMAT_LOGGER_SYSTEM_LOG_VISIBLE_TO): the
 *   developer console (System log), its export, the live tail and the widget's system line.
 *
 * `administrators` (default): users with `manage_options` or `noformat_logger_view_events`;
 * `capability_only`: only users with `noformat_logger_view_events` (client sites).
 * Any other value fails closed (capability only).
 */
final class Access {

	public const ADMINISTRATORS  = 'administrators';
	public const CAPABILITY_ONLY = 'capability_only';

	/**
	 * Resolved mode of the Content log setting.
	 *
	 * @param Config $config Config.
	 */
	public static function mode( Config $config ): string {
		return self::resolve( $config->get( 'events_visible_to', self::ADMINISTRATORS ) );
	}

	/**
	 * Resolved mode of the System log setting.
	 *
	 * @param Config $config Config.
	 */
	public static function systemMode( Config $config ): string {
		return self::resolve( $config->get( 'system_log_visible_to', self::ADMINISTRATORS ) );
	}

	/**
	 * Whether the current user may read the Content log (and its export and the widget).
	 *
	 * @param Config $config Config.
	 */
	public static function canViewEvents( Config $config ): bool {
		return self::allowed( self::mode( $config ) );
	}

	/**
	 * Whether the current user may read the System log (console, export, live tail).
	 *
	 * @param Config $config Config.
	 */
	public static function canViewSystemLog( Config $config ): bool {
		return self::allowed( self::systemMode( $config ) );
	}

	/**
	 * Capability for Tools → Noformat Logbook (the Status tab stays reachable for administrators).
	 */
	public static function menuCapability(): string {
		return current_user_can( 'manage_options' ) ? 'manage_options' : Plugin::CAP_VIEW_EVENTS;
	}

	/**
	 * Tabs of Tools → Noformat Logbook the current user may open, in display order.
	 *
	 * Content log per `events_visible_to`, System log per `system_log_visible_to`,
	 * Status for everyone who may open the page (key actions need manage_options),
	 * Settings for manage_options. Empty when the user may open nothing.
	 *
	 * @param Config $config Config.
	 * @return list<string> Menu::CONTENT, Menu::SYSTEM, Menu::STATUS, Menu::SETTINGS.
	 */
	public static function tabs( Config $config ): array {
		$admin = current_user_can( 'manage_options' );
		if ( ! $admin && ! current_user_can( Plugin::CAP_VIEW_EVENTS ) ) {
			return [];
		}
		$tabs = [];
		if ( self::canViewEvents( $config ) ) {
			$tabs[] = Menu::CONTENT;
		}
		if ( self::canViewSystemLog( $config ) ) {
			$tabs[] = Menu::SYSTEM;
		}
		$tabs[] = Menu::STATUS;
		if ( $admin ) {
			$tabs[] = Menu::SETTINGS;
		}
		return $tabs;
	}

	/**
	 * Mode from a raw setting value.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function resolve( mixed $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return self::ADMINISTRATORS === $value ? self::ADMINISTRATORS : self::CAPABILITY_ONLY;
	}

	/**
	 * Current user passes a mode.
	 *
	 * @param string $mode Mode.
	 */
	private static function allowed( string $mode ): bool {
		if ( current_user_can( Plugin::CAP_VIEW_EVENTS ) ) {
			return true;
		}
		return self::ADMINISTRATORS === $mode && current_user_can( 'manage_options' );
	}
}
