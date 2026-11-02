<?php
/**
 * Event viewer access rule.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Admin;

use Brain\Monkey\Functions;
use Noformat\Logger\Admin\Access;
use Noformat\Logger\Config;
use Noformat\Logger\Plugin;
use Noformat\Logger\Tests\TestCase;

final class AccessTest extends TestCase {

	/**
	 * Current user capabilities.
	 *
	 * @param list<string> $caps Caps.
	 */
	private function user( array $caps ): void {
		Functions\when( 'current_user_can' )->alias( static fn( string $cap ): bool => in_array( $cap, $caps, true ) );
	}

	public function test_default_is_administrators(): void {
		$config = new Config();
		self::assertSame( Access::ADMINISTRATORS, Access::mode( $config ) );

		$this->user( [ 'manage_options' ] );
		self::assertTrue( Access::canViewEvents( $config ), 'administrator' );
		$this->user( [ Plugin::CAP_VIEW_EVENTS ] );
		self::assertTrue( Access::canViewEvents( $config ), 'capability holder' );
		$this->user( [ 'edit_posts', 'list_users' ] );
		self::assertFalse( Access::canViewEvents( $config ), 'editor' );
	}

	public function test_capability_only(): void {
		$config = new Config( [ 'events_visible_to' => 'capability_only' ] );
		self::assertSame( Access::CAPABILITY_ONLY, Access::mode( $config ) );

		$this->user( [ 'manage_options', 'list_users', 'edit_posts' ] );
		self::assertFalse( Access::canViewEvents( $config ), 'administrator without the capability' );
		$this->user( [ Plugin::CAP_VIEW_EVENTS ] );
		self::assertTrue( Access::canViewEvents( $config ) );
		$this->user( [ 'manage_options', Plugin::CAP_VIEW_EVENTS ] );
		self::assertTrue( Access::canViewEvents( $config ) );
	}

	public function test_constant_overrides_option_and_unknown_values_fail_closed(): void {
		$pinned = new Config( [ 'events_visible_to' => 'administrators' ], [ 'events_visible_to' => 'capability_only' ] );
		self::assertSame( Access::CAPABILITY_ONLY, Access::mode( $pinned ) );
		self::assertTrue( $pinned->isPinned( 'events_visible_to' ) );

		foreach ( [ 'admins', 'everyone', '', 1, true ] as $value ) {
			self::assertSame( Access::CAPABILITY_ONLY, Access::mode( new Config( [ 'events_visible_to' => $value ] ) ), var_export( $value, true ) );
		}
		self::assertSame( Access::ADMINISTRATORS, Access::mode( new Config( [ 'events_visible_to' => ' Administrators ' ] ) ) );
		self::assertSame( Access::ADMINISTRATORS, Access::mode( new Config( [ 'events_visible_to' => null ] ) ), 'null = unset = default' );
	}

	public function test_settings_form_only_stores_known_values(): void {
		$this->user( [ 'manage_options' ] );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		$plugin = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
		$page   = new \Noformat\Logger\Admin\SettingsPage( $plugin );
		self::assertSame( Access::CAPABILITY_ONLY, $page->sanitize( [ 'events_visible_to' => 'capability_only' ] )['events_visible_to'] );
		self::assertSame( Access::ADMINISTRATORS, $page->sanitize( [ 'events_visible_to' => 'everyone' ] )['events_visible_to'] );
		self::assertSame( Access::ADMINISTRATORS, $page->sanitize( [] )['events_visible_to'] );

		$this->user( [ Plugin::CAP_VIEW_EVENTS ] );
		self::assertSame( [], $page->sanitize( [ 'events_visible_to' => 'administrators' ] ), 'non-administrators cannot change settings' );
	}

	public function test_menu_capability(): void {
		$this->user( [ 'manage_options' ] );
		self::assertSame( 'manage_options', Access::menuCapability() );
		$this->user( [ Plugin::CAP_VIEW_EVENTS ] );
		self::assertSame( Plugin::CAP_VIEW_EVENTS, Access::menuCapability() );
	}

	public function test_system_log_setting_is_independent(): void {
		$config = new Config();
		self::assertSame( Access::ADMINISTRATORS, Access::systemMode( $config ) );
		$this->user( [ 'manage_options' ] );
		self::assertTrue( Access::canViewSystemLog( $config ), 'administrator by default' );

		$client = new Config( [ 'system_log_visible_to' => 'capability_only' ] );
		self::assertSame( Access::ADMINISTRATORS, Access::mode( $client ), 'content log unaffected' );
		self::assertTrue( Access::canViewEvents( $client ), 'client admin keeps the content log' );
		self::assertFalse( Access::canViewSystemLog( $client ), 'client admin loses the developer view' );
		$this->user( [ Plugin::CAP_VIEW_EVENTS ] );
		self::assertTrue( Access::canViewSystemLog( $client ), 'capability holder keeps it' );
		$this->user( [ 'edit_posts' ] );
		self::assertFalse( Access::canViewSystemLog( $config ), 'editors never see it' );

		$pinned = new Config( [ 'system_log_visible_to' => 'administrators' ], [ 'system_log_visible_to' => 'capability_only' ] );
		self::assertSame( Access::CAPABILITY_ONLY, Access::systemMode( $pinned ), 'constant wins' );
		self::assertTrue( $pinned->isPinned( 'system_log_visible_to' ) );
		self::assertSame( Access::CAPABILITY_ONLY, Access::systemMode( new Config( [ 'system_log_visible_to' => 'everyone' ] ) ), 'unknown fails closed' );
	}

	public function test_settings_form_stores_the_system_log_setting(): void {
		$this->user( [ 'manage_options' ] );
		Functions\when( 'get_option' )->justReturn( [] );
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		$plugin = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
		$page   = new \Noformat\Logger\Admin\SettingsPage( $plugin );
		self::assertSame( Access::CAPABILITY_ONLY, $page->sanitize( [ 'system_log_visible_to' => 'capability_only' ] )['system_log_visible_to'] );
		self::assertSame( Access::ADMINISTRATORS, $page->sanitize( [ 'system_log_visible_to' => 'root' ] )['system_log_visible_to'] );
		self::assertSame( Access::ADMINISTRATORS, $page->sanitize( [] )['system_log_visible_to'] );
	}
}
