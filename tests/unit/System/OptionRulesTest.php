<?php
/**
 * OptionRules tests: severity map, skip rules, theme mods, active_plugins diff.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\System;

use Brain\Monkey\Filters;
use Noformat\Logger\Config;
use Noformat\Logger\Listeners\Support\System\OptionRules;
use Noformat\Logger\Severity;
use Noformat\Logger\Source;
use Noformat\Logger\Tests\TestCase;

final class OptionRulesTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: mixed, 2: Severity}>
	 */
	public static function severities(): array {
		return [
			'siteurl'                 => [ 'siteurl', 'http://x', Severity::CRITICAL ],
			'home'                    => [ 'home', 'http://x', Severity::CRITICAL ],
			'admin_email'             => [ 'admin_email', 'a@b.c', Severity::CRITICAL ],
			'roles option'            => [ 'wp_user_roles', [], Severity::CRITICAL ],
			'active_plugins by code'  => [ 'active_plugins', [], Severity::CRITICAL ],
			'default_role admin'      => [ 'default_role', 'administrator', Severity::CRITICAL ],
			'default_role subscriber' => [ 'default_role', 'subscriber', Severity::NOTICE ],
			'users_can_register'      => [ 'users_can_register', '1', Severity::NOTICE ],
			'blogname'                => [ 'blogname', 'x', Severity::NOTICE ],
			'permalink_structure'     => [ 'permalink_structure', '/%postname%/', Severity::NOTICE ],
			'mailserver glob'         => [ 'mailserver_url', 'x', Severity::NOTICE ],
			'email glob'              => [ 'new_admin_email', 'x', Severity::NOTICE ],
			'WPLANG'                  => [ 'WPLANG', 'de_DE', Severity::NOTICE ],
			'theme mods'              => [ 'theme_mods_twentytwentyfour', [], Severity::NOTICE ],
			'other'                   => [ 'my_plugin_setting', 'x', Severity::INFO ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'severities' )]
	public function test_severity_map( string $name, mixed $value, Severity $expected ): void {
		self::assertSame( $expected, OptionRules::severity( $name, $value ) );
	}

	public function test_option_severity_filter(): void {
		Filters\expectApplied( 'noformat_logger/option_severity' )->andReturnUsing(
			static function ( array $map ): array {
				$map['my_plugin_*'] = 'warning';
				$map['blogname']    = 'bogus';
				return $map;
			}
		);
		self::assertSame( Severity::WARNING, OptionRules::severity( 'my_plugin_license' ) );
		self::assertSame( Severity::INFO, OptionRules::severity( 'blogname' ), 'invalid severities are dropped' );
	}

	public function test_critical_options_filter(): void {
		Filters\expectApplied( 'noformat_logger/critical_options' )->andReturnUsing(
			static fn( array $list ): array => array_merge( $list, [ 'woocommerce_*' ] )
		);
		self::assertSame( Severity::CRITICAL, OptionRules::severity( 'woocommerce_currency' ) );
		self::assertSame( Severity::CRITICAL, OptionRules::severity( 'siteurl' ) );
	}

	/**
	 * @return array<string, array{0: string, 1: Source, 2: bool, 3: string|null}>
	 */
	public static function skips(): array {
		return [
			'own options'               => [ 'nflog_settings', Source::ADMIN, false, 'ignored' ],
			'transient'                 => [ '_transient_feed_x', Source::FRONTEND, false, 'ignored' ],
			'cron'                      => [ 'cron', Source::CRON, false, 'ignored' ],
			'rewrite_rules'             => [ 'rewrite_rules', Source::ADMIN, false, 'ignored' ],
			'widget'                    => [ 'widget_block', Source::ADMIN, false, 'owned_elsewhere' ],
			'sidebars'                  => [ 'sidebars_widgets', Source::ADMIN, false, 'owned_elsewhere' ],
			'menu locations on switch'  => [ 'theme_switch_menu_locations', Source::ADMIN, false, 'owned_elsewhere' ],
			'template'                  => [ 'template', Source::ADMIN, false, 'owned_elsewhere' ],
			'stylesheet'                => [ 'stylesheet', Source::CLI, false, 'owned_elsewhere' ],
			'theme mods in customizer'  => [ 'theme_mods_tt4', Source::AJAX, true, 'customizer' ],
			'theme mods outside'        => [ 'theme_mods_tt4', Source::ADMIN, false, null ],
			'blogname in customizer'    => [ 'blogname', Source::AJAX, true, null ],
			'active_plugins admin'      => [ 'active_plugins', Source::ADMIN, false, 'plugin_screen' ],
			'active_plugins cli'        => [ 'active_plugins', Source::CLI, false, 'plugin_screen' ],
			'active_plugins frontend'   => [ 'active_plugins', Source::FRONTEND, false, null ],
			'active_plugins rest'       => [ 'active_plugins', Source::REST, false, null ],
			'regular'                   => [ 'blogdescription', Source::ADMIN, false, null ],
			'empty'                     => [ '', Source::ADMIN, false, 'empty' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'skips' )]
	public function test_skip_rules( string $name, Source $source, bool $customizing, ?string $expected ): void {
		self::assertSame( $expected, OptionRules::skipReason( $name, new Config(), $source, $customizing ) );
	}

	public function test_theme_mods_drop_menu_locations(): void {
		$value = [
			'nav_menu_locations' => [ 'primary' => 3 ],
			'custom_logo'        => 12,
			'custom_css_post_id' => -1,
		];
		self::assertSame( [ 'custom_logo' => 12 ], OptionRules::stripThemeMods( $value ) );
		self::assertSame( 'x', OptionRules::stripThemeMods( 'x' ) );
	}

	public function test_plugin_list_diff(): void {
		$diff = OptionRules::pluginListDiff( [ 'a/a.php', 'b/b.php' ], [ 'b/b.php', 'c/c.php' ] );
		self::assertSame( [ 'c/c.php' ], $diff['added'] );
		self::assertSame( [ 'a/a.php' ], $diff['removed'] );
		self::assertSame( [ 'added' => [ 'x.php' ], 'removed' => [] ], OptionRules::pluginListDiff( false, [ 'x.php' ] ) );
	}

	public function test_term_hierarchy_cache_is_skipped(): void {
		\Brain\Monkey\Functions\when( 'taxonomy_exists' )->alias( static fn( $t ) => in_array( $t, [ 'category', 'product_cat' ], true ) );
		$config = new Config();
		self::assertSame( 'term_cache', OptionRules::skipReason( 'category_children', $config, Source::ADMIN, false ) );
		self::assertSame( 'term_cache', OptionRules::skipReason( 'product_cat_children', $config, Source::CLI, false ) );
		self::assertNull( OptionRules::skipReason( 'my_plugin_children', $config, Source::ADMIN, false ), 'not a taxonomy' );
		self::assertNull( OptionRules::skipReason( '_children', $config, Source::ADMIN, false ) );
	}
}
