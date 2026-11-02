<?php
/**
 * Tools → Noformat Logger: tabs, URLs and legacy redirects.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Admin;

use Brain\Monkey\Functions;
use Noformat\Logger\Admin\Access;
use Noformat\Logger\Admin\Menu;
use Noformat\Logger\Config;
use Noformat\Logger\Plugin;
use Noformat\Logger\Tests\TestCase;

final class MenuTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'sanitize_key' )->alias( static fn( $key ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) );
		Functions\when( 'admin_url' )->alias( static fn( string $path = '' ) => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( array $args, string $url ) => $url . '?' . http_build_query( $args ) );
	}

	/**
	 * @param string[] $caps Capabilities of the current user.
	 */
	private function user( array $caps ): void {
		Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => in_array( $cap, $caps, true ) );
	}

	public function test_url_points_to_the_tools_page_and_tab(): void {
		self::assertSame( 'https://example.test/wp-admin/tools.php?page=noformat-logger&tab=system', Menu::url( Menu::SYSTEM ) );
		self::assertSame( 'https://example.test/wp-admin/tools.php?page=noformat-logger&tab=content&window=all', Menu::url( Menu::CONTENT, [ 'window' => 'all' ] ) );
		self::assertSame( 'https://example.test/wp-admin/tools.php', Menu::formAction() );
		self::assertStringContainsString( 'name="tab" value="system"', Menu::formFields( Menu::SYSTEM ) );
		self::assertStringContainsString( 'name="page" value="noformat-logger"', Menu::formFields( Menu::SYSTEM ) );
	}

	public function test_tabs_follow_the_access_settings(): void {
		$this->user( [ 'manage_options' ] );
		self::assertSame( [ 'content', 'system', 'status', 'settings' ], Access::tabs( new Config() ) );
		self::assertSame( [ 'content', 'status', 'settings' ], Access::tabs( new Config( [ 'system_log_visible_to' => 'capability_only' ] ) ) );
		self::assertSame( [ 'system', 'status', 'settings' ], Access::tabs( new Config( [ 'events_visible_to' => 'capability_only' ] ) ) );
		self::assertSame( [ 'status', 'settings' ], Access::tabs( new Config( [ 'events_visible_to' => 'capability_only', 'system_log_visible_to' => 'capability_only' ] ) ) );

		$this->user( [ Plugin::CAP_VIEW_EVENTS ] );
		self::assertSame( [ 'content', 'system', 'status' ], Access::tabs( new Config( [ 'events_visible_to' => 'capability_only' ] ) ), 'capability holders never get Settings' );

		$this->user( [ 'edit_posts' ] );
		self::assertSame( [], Access::tabs( new Config() ), 'editors get no page' );
	}

	public function test_resolve_tab(): void {
		$all = [ 'content', 'system', 'status', 'settings' ];
		self::assertSame( 'content', Menu::resolveTab( '', $all ), 'default is the first visible tab' );
		self::assertSame( 'system', Menu::resolveTab( 'system', $all ) );
		self::assertSame( 'content', Menu::resolveTab( 'bogus', $all ), 'unknown values fall back to the default' );
		self::assertSame( 'content', Menu::resolveTab( [ 'system' ], $all ) );
		self::assertSame( 'status', Menu::resolveTab( '', [ 'status', 'settings' ] ) );
		self::assertNull( Menu::resolveTab( 'system', [ 'content', 'status' ] ), 'a hidden tab is refused' );
		self::assertNull( Menu::resolveTab( 'settings', [ 'content', 'system', 'status' ] ) );
		self::assertNull( Menu::resolveTab( '', [] ) );
	}

	public function test_legacy_urls(): void {
		self::assertSame( 'content', Menu::legacyTab( 'admin.php', 'noformat-logger', [] ) );
		self::assertSame( 'system', Menu::legacyTab( 'admin.php', 'noformat-logger-system', [] ) );
		self::assertSame( 'status', Menu::legacyTab( 'admin.php', 'noformat-logger-status', [] ) );
		self::assertSame( 'settings', Menu::legacyTab( 'admin.php', 'noformat-logger-settings', [] ) );
		self::assertNull( Menu::legacyTab( 'admin.php', 'other-plugin', [] ) );
		self::assertNull( Menu::legacyTab( 'tools.php', 'noformat-logger', [ 'tab' => 'system' ] ), 'the current URL is not a legacy one' );
		self::assertNull( Menu::legacyTab( 'tools.php', 'noformat-logger', [] ) );
		self::assertSame( 'content', Menu::legacyTab( 'tools.php', 'noformat-logger', [ 'tab' => 'events' ] ) );
		self::assertSame( 'system', Menu::legacyTab( 'tools.php', 'noformat-logger', [ 'tab' => 'events', 'category' => 'security' ] ) );
	}

	public function test_plugin_row_settings_link_for_administrators_only(): void {
		$this->user( [ 'manage_options' ] );
		$links = Menu::actionLinks( [ 'deactivate' => '<a>Deactivate</a>' ] );
		self::assertCount( 2, $links );
		self::assertStringContainsString( 'tools.php?page=noformat-logger&tab=settings', (string) reset( $links ) );
		$this->user( [ Plugin::CAP_VIEW_EVENTS ] );
		self::assertCount( 1, Menu::actionLinks( [ 'deactivate' => '<a>Deactivate</a>' ] ) );
	}

	public function test_tab_bar_marks_the_current_tab(): void {
		$html = Menu::tabBar( 'system', [ 'content', 'system', 'status' ] );
		self::assertSame( 3, substr_count( $html, '<a class="nflog-tab' ) );
		self::assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
		self::assertMatchesRegularExpression( '#class="nflog-tab is-active" href="[^"]*tab=system" aria-current="page">System log<#', $html );
		self::assertStringNotContainsString( 'tab=settings', $html );
	}
}
