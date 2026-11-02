<?php
/**
 * Option skip and severity rules.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Listeners\Support\System;

use Noformat\Logger\Config;
use Noformat\Logger\Severity;
use Noformat\Logger\Source;

defined( 'ABSPATH' ) || exit;

/**
 * Pure decisions for OptionListener: which option writes are skipped and which
 * severity a logged write gets (PLAN §4 Settings, §5 critical list).
 */
final class OptionRules {

	/**
	 * Options owned by other listeners (widgets: WidgetListener; theme switch: PluginThemeListener).
	 *
	 * @var list<string>
	 */
	public const OWNED_ELSEWHERE = [
		'widget_*',
		'sidebars_widgets',
		'template',
		'stylesheet',
		'current_theme',
		'theme_switch_menu_locations',
	];

	/**
	 * Keys dropped from theme_mods_* before diffing: nav_menu_locations belongs to
	 * MenuListener; custom_css_post_id is a cache pointer WordPress writes lazily
	 * on the first frontend render after a theme switch.
	 *
	 * @var list<string>
	 */
	public const THEME_MOD_SKIP_KEYS = [
		'nav_menu_locations',
		'custom_css_post_id',
	];

	/**
	 * Default notice-level options (glob patterns allowed).
	 *
	 * @var list<string>
	 */
	public const NOTICE_OPTIONS = [
		'users_can_register',
		'default_role',
		'permalink_structure',
		'category_base',
		'tag_base',
		'blog_public',
		'default_comment_status',
		'default_ping_status',
		'comment_registration',
		'comment_moderation',
		'comment_previously_approved',
		'require_name_email',
		'timezone_string',
		'gmt_offset',
		'WPLANG',
		'page_on_front',
		'show_on_front',
		'page_for_posts',
		'posts_per_page',
		'blogname',
		'blogdescription',
		'mailserver_*',
		'*_email',
		'upload_path',
		'upload_url_path',
		'wp_page_for_privacy_policy',
		'auto_update_plugins',
		'auto_update_themes',
		'auto_update_core_*',
		'default_category',
		'theme_mods_*',
	];

	/**
	 * Name of the roles option for the current blog ("wp_user_roles" with the default prefix).
	 */
	public static function rolesOption(): string {
		global $wpdb;
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_blog_prefix' ) ) {
			return $wpdb->get_blog_prefix() . 'user_roles';
		}
		return 'wp_user_roles';
	}

	/**
	 * Options whose change is always critical (PLAN §5), after the noformat_logger/critical_options filter.
	 *
	 * @return list<string>
	 */
	public static function criticalOptions(): array {
		$list = [ 'siteurl', 'home', 'admin_email', self::rolesOption() ];
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the option names (glob patterns allowed) whose change is logged as critical.
			 *
			 * @param list<string> $list Option names.
			 */
			$list = apply_filters( 'noformat_logger/critical_options', $list );
		}
		return is_array( $list ) ? array_values( array_map( 'strval', $list ) ) : [];
	}

	/**
	 * Option name (or glob) → severity value map, after the noformat_logger/option_severity filter.
	 *
	 * @return array<string, string>
	 */
	public static function severityMap(): array {
		$map = [];
		foreach ( self::NOTICE_OPTIONS as $name ) {
			$map[ $name ] = Severity::NOTICE->value;
		}
		foreach ( self::criticalOptions() as $name ) {
			$map[ $name ] = Severity::CRITICAL->value;
		}
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the option severity map: option name (glob patterns allowed) => severity
			 * ("critical", "error", "warning", "notice", "info", "debug"). Unlisted options are info.
			 *
			 * @param array<string, string> $map Map.
			 */
			$map = apply_filters( 'noformat_logger/option_severity', $map );
		}
		$out = [];
		foreach ( is_array( $map ) ? $map : [] as $name => $severity ) {
			$value = $severity instanceof Severity ? $severity->value : ( is_string( $severity ) ? strtolower( $severity ) : '' );
			if ( null !== Severity::tryFrom( $value ) ) {
				$out[ (string) $name ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Why an option write is not logged (null: log it).
	 *
	 * @param string $name        Option name.
	 * @param Config $config      Config (ignore list).
	 * @param Source $source      Request source.
	 * @param bool   $customizing A Customizer save is running.
	 */
	public static function skipReason( string $name, Config $config, Source $source, bool $customizing ): ?string {
		if ( '' === $name ) {
			return 'empty';
		}
		if ( $config->isIgnoredOption( $name ) ) {
			return 'ignored';
		}
		if ( Config::matchesGlob( $name, self::OWNED_ELSEWHERE ) ) {
			return 'owned_elsewhere';
		}
		if ( self::isTermHierarchyCache( $name ) ) {
			return 'term_cache';
		}
		if ( $customizing && str_starts_with( $name, 'theme_mods_' ) ) {
			return 'customizer';
		}
		if ( 'active_plugins' === $name && in_array( $source, [ Source::ADMIN, Source::CLI ], true ) ) {
			return 'plugin_screen';
		}
		return null;
	}

	/**
	 * Whether an option is WordPress' term hierarchy cache `{taxonomy}_children`
	 * (rebuilt by _get_term_hierarchy() whenever a hierarchical term changes;
	 * TermListener logs the change itself).
	 *
	 * @param string $name Option name.
	 */
	public static function isTermHierarchyCache( string $name ): bool {
		if ( ! str_ends_with( $name, '_children' ) || strlen( $name ) <= strlen( '_children' ) || ! function_exists( 'taxonomy_exists' ) ) {
			return false;
		}
		return taxonomy_exists( substr( $name, 0, -strlen( '_children' ) ) );
	}

	/**
	 * Severity of a logged option write.
	 *
	 * @param string $name     Option name.
	 * @param mixed  $newValue New value (null when deleted).
	 */
	public static function severity( string $name, mixed $newValue = null ): Severity {
		if ( 'active_plugins' === $name ) {
			// Only reached when changed outside the plugins screen / WP-CLI (PLAN §5).
			return Severity::CRITICAL;
		}
		if ( 'default_role' === $name && 'administrator' === $newValue ) {
			return Severity::CRITICAL;
		}
		$map = self::severityMap();
		if ( isset( $map[ $name ] ) ) {
			return Severity::from( $map[ $name ] );
		}
		$best = null;
		foreach ( $map as $pattern => $severity ) {
			if ( str_contains( $pattern, '*' ) && Config::matchesGlob( $name, [ $pattern ] ) ) {
				$candidate = Severity::from( $severity );
				if ( null === $best || $candidate->priority() < $best->priority() ) {
					$best = $candidate;
				}
			}
		}
		return $best ?? Severity::INFO;
	}

	/**
	 * Drop THEME_MOD_SKIP_KEYS from a theme_mods_* value.
	 *
	 * @param mixed $value Option value.
	 */
	public static function stripThemeMods( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			foreach ( self::THEME_MOD_SKIP_KEYS as $key ) {
				unset( $value[ $key ] );
			}
		}
		return $value;
	}

	/**
	 * Added and removed entries of active_plugins.
	 *
	 * @param mixed $oldValue Old list.
	 * @param mixed $newValue New list.
	 * @return array{added: list<string>, removed: list<string>}
	 */
	public static function pluginListDiff( mixed $oldValue, mixed $newValue ): array {
		$old = is_array( $oldValue ) ? array_map( 'strval', array_values( $oldValue ) ) : [];
		$new = is_array( $newValue ) ? array_map( 'strval', array_values( $newValue ) ) : [];
		return [
			'added'   => array_values( array_diff( $new, $old ) ),
			'removed' => array_values( array_diff( $old, $new ) ),
		];
	}
}
