<?php
/**
 * Machine writes are not recorded: no person behind an option or user meta
 * write, and vendor state keys whoever the actor is. Critical settings,
 * security user meta and WP-CLI stay recorded; record_machine_writes opts back in.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Listeners;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Noformat\Logger\Listeners\MetaListener;
use Noformat\Logger\Listeners\OptionListener;
use Noformat\Logger\Listeners\Support\MachineWrites;
use Noformat\Logger\Severity;
use Noformat\Logger\Source;

final class MachineWritesTest extends ListenerTestCase {

	private OptionListener $options;

	private MetaListener $meta;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_key' )->alias( static fn( $key ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) );
		$this->bindListeners();
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_REQUEST['action'], $_POST['action'] );
		parent::tearDown();
	}

	/**
	 * (Re)bind both listeners to the current logger and config.
	 */
	private function bindListeners(): void {
		$this->options = new OptionListener();
		$this->options->register( $this->logger, $this->config );
		$this->meta = ( new MetaListener() )->bind( $this->logger, $this->config );
	}

	/**
	 * A visitor's (or a logged-in user's) web request after init.
	 *
	 * @param string $method HTTP method.
	 * @param string $uri    Request URI.
	 */
	private function request( string $method, string $uri ): void {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_SERVER['REQUEST_URI']    = $uri;
		Functions\when( 'did_action' )->alias( static fn( $hook ) => 'init' === $hook ? 1 : 0 );
	}

	/**
	 * No logged-in user (wp_get_current_user() returns user 0).
	 */
	private function visitor(): void {
		if ( ! class_exists( 'WP_User', false ) ) {
			eval( 'class WP_User { public $ID = 0; public $roles = []; public $data; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
		Functions\when( 'wp_get_current_user' )->justReturn( new \WP_User() );
	}

	public function test_decision(): void {
		self::assertSame( 'machine_state', MachineWrites::skipReason( 'key_pattern', true, true, false, false ) );
		self::assertSame( 'machine_state', MachineWrites::skipReason( 'read_request', false, true, false, false ) );
		self::assertSame( 'no_person', MachineWrites::skipReason( null, true, false, false, false ) );
		self::assertNull( MachineWrites::skipReason( null, true, false, true, false ), 'WP-CLI is a person' );
		self::assertNull( MachineWrites::skipReason( null, false, false, false, false ), 'always recorded' );
		self::assertNull( MachineWrites::skipReason( null, true, true, false, false ), 'a logged-in user' );
		self::assertNull( MachineWrites::skipReason( 'key_pattern', true, false, false, true ), 'record_machine_writes' );
	}

	public function test_example_1_vendor_state_with_a_logged_in_admin_is_not_recorded(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( true );
		$this->request( 'POST', '/wp-admin/admin-ajax.php' );
		$this->loggedIn( 1 );
		$_REQUEST['action'] = 'wpml_ate_pull';
		$this->options->onUpdated( 'wpml_pull_delivery_state', [ 'status' => 'idle' ], [ 'status' => 'running' ] );
		$this->options->onUpdated( 'wpml_ate_retranslation_state', 'a', 'b' );
		$this->options->onUpdated( 'wpml_single_url_resolution_generation', 1, 2 );
		self::assertSame( [], $this->actions() );
	}

	public function test_example_2_visitor_wpml_worker_status_is_not_recorded(): void {
		$this->request( 'POST', '/wp-json/wpml/v1/single-url-worker' );
		$this->visitor();
		$this->options->onUpdated( 'wpml_single_url_resolution_worker_status', [ 'running' => false ], [ 'running' => true ] );
		self::assertSame( [], $this->actions() );
		$this->options->onUpdated( 'acme_layout', 'a', 'b' );
		self::assertSame( [], $this->actions(), 'any option without a person (unauthenticated REST)' );
	}

	public function test_example_3_visitor_totp_step_on_login_is_not_recorded(): void {
		$this->request( 'POST', '/wp-login.php' );
		$this->visitor();
		$this->meta->updated( 'user', 3, 'wp_2fa_last_totp_step', '58901234' );
		$this->meta->updated( 'user', 3, 'wp_2fa_nonce', 'abc' );
		$this->meta->added( 'user', 3, 'acme_last_seen_page', '/pricing/' );
		self::assertSame( [], $this->actions(), 'user meta without a person' );
	}

	public function test_example_4_visitor_wp_engine_notices_are_not_recorded(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( true );
		$this->request( 'POST', '/wp-admin/admin-ajax.php' );
		$this->visitor();
		$_REQUEST['action'] = 'nopriv_something';
		$this->options->onUpdated( 'wpe_notices', [], [ [ 'id' => 1 ] ] );
		$this->options->onUpdated( 'wpe_notices_expiration', 1700000000, 1700000900 );
		self::assertSame( [], $this->actions() );
	}

	public function test_wp_engine_and_wpml_state_keys_are_technical_but_settings_are_not(): void {
		foreach ( [ 'wpml_pull_delivery_state', 'wpml_single_url_resolution_worker_status', 'wpml_ate_reconnect_state', 'wpml_config_import_state', 'wpml_word_count_requested_types_status', 'wpml_ate_unreachable_since', 'wpml-stats-last-sent', 'wpe_notices', 'wpe_notices_expiration', 'wpe_feature_flags', 'wpe_feature_flags_expiration', 'wpe_site_transient_update_plugins', 'wpe-health-check-site-status-result', 'wpesu-some-plugin-expiry', 'wpe_cache_last_cleared' ] as $key ) {
			self::assertTrue( $this->config->isTechnicalKey( $key ), $key );
		}
		foreach ( [ 'icl_sitepress_settings', 'wpml_language_switcher_settings', 'wpml_config_files_arr', 'wpe_cache_config', 'wp_2fa_policy', 'wp_2fa_settings' ] as $key ) {
			self::assertFalse( $this->config->isTechnicalKey( $key ), $key );
		}
	}

	public function test_visitor_and_cron_writes_to_critical_and_notice_options_are_recorded(): void {
		$this->request( 'POST', '/' );
		$this->visitor();
		$this->options->onUpdated( 'siteurl', 'https://a.test', 'https://evil.test' );
		$this->options->onUpdated( 'admin_email', 'a@a.test', 'b@evil.test' );
		$this->options->onUpdated( 'users_can_register', '0', '1' );
		$this->options->onUpdated( 'default_role', 'subscriber', 'administrator' );
		self::assertSame( [ 'option.updated', 'option.updated', 'option.updated', 'option.updated' ], $this->actions() );
		self::assertSame( Severity::CRITICAL, $this->events[0]->getSeverity() );
		self::assertSame( Severity::NOTICE, $this->events[2]->getSeverity() );
		self::assertSame( Severity::CRITICAL, $this->events[3]->getSeverity() );
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		$this->options->onUpdated( 'home', 'https://a.test', 'https://evil.test' );
		self::assertCount( 5, $this->events, 'home from cron' );
		self::assertSame( Severity::CRITICAL, $this->events[4]->getSeverity() );
		self::assertArrayNotHasKey( 'technical', $this->events[4]->getContext() );
	}

	public function test_code_before_init_is_not_a_person_but_critical_options_are_recorded(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->options->onUpdated( 'acme_db_version', '1', '2' );
		self::assertSame( [], $this->actions() );
		$this->options->onUpdated( 'siteurl', 'https://a.test', 'https://b.test' );
		self::assertSame( [ 'option.updated' ], $this->actions() );
	}

	public function test_a_write_while_the_current_user_is_determined_does_not_recurse(): void {
		$this->request( 'POST', '/wp-json/acme/v1/save' );
		$this->loggedIn( 1 );
		$user  = wp_get_current_user();
		$inner = null;
		Functions\when( 'wp_get_current_user' )->alias(
			function () use ( $user, &$inner ) {
				$inner = $this->logger->context()->hasPerson(); // A determine_current_user callback writing an option.
				return $user;
			}
		);
		self::assertTrue( $this->logger->context()->hasPerson() );
		self::assertFalse( $inner, 're-entered: no person yet' );
	}

	public function test_logged_in_admin_changing_ordinary_settings_is_recorded(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->request( 'POST', '/wp-admin/options.php' );
		$this->loggedIn( 1 );
		$this->options->onUpdated( 'acme_layout', 'a', 'b' );
		$this->options->onUpdated( 'icl_sitepress_settings', [ 'urls' => 1 ], [ 'urls' => 2 ] );
		$this->options->onUpdated( 'wpe_cache_config', [ 'ttl' => 600 ], [ 'ttl' => 900 ] );
		self::assertSame( [ 'option.updated', 'option.updated', 'option.updated' ], $this->actions() );
		self::assertSame( Severity::INFO, $this->events[0]->getSeverity() );
		self::assertTrue( $this->logger->context()->hasPerson() );
		$this->meta->updated( 'user', 1, 'acme_pref', 'dark' );
		self::assertCount( 4, $this->events, 'user meta by a logged-in user' );
	}

	public function test_wp_cli_writes_are_recorded(): void {
		$this->logger->context()->overrideSource( Source::CLI );
		$this->options->onUpdated( 'acme_layout', 'a', 'b' );
		$this->meta->updated( 'user', 3, 'acme_pref', 'dark' );
		self::assertSame( [ 'option.updated', 'meta.updated' ], $this->actions() );
		self::assertSame( Severity::INFO, $this->events[0]->getSeverity() );
		$this->options->onUpdated( 'wpml_pull_delivery_state', 'a', 'b' );
		self::assertCount( 2, $this->events, 'vendor state stays out, also from WP-CLI' );
	}

	public function test_two_factor_enablement_without_a_person_is_recorded(): void {
		$this->request( 'POST', '/wp-login.php' );
		$this->visitor();
		$this->meta->added( 'user', 3, 'wp_2fa_enabled_methods', 'totp' );
		$this->meta->updated( 'user', 3, 'wp_2fa_2fa_status', 'has_enabled_methods' );
		$this->meta->deleted( 'user', [ 9 ], 3, 'wp_2fa_enabled_methods', '' );
		self::assertSame( [ 'meta.added', 'meta.updated', 'meta.deleted' ], $this->actions() );
		self::assertSame( 'wp_2fa_enabled_methods', $this->events[0]->getContext()['meta_key'] );
		self::assertSame( Severity::INFO, $this->events[0]->getSeverity() );
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		$this->meta->updated( 'user', 3, 'wp_2fa_is_locked', '1' );
		self::assertCount( 4, $this->events, 'a lock set by cron' );
		self::assertArrayNotHasKey( 'technical', $this->events[3]->getContext() );
		$this->meta->updated( 'user', 3, 'acme_pref', 'x' );
		self::assertCount( 4, $this->events, 'ordinary user meta from cron stays out' );
	}

	public function test_visitor_post_meta_is_still_recorded(): void {
		$this->request( 'POST', '/checkout/' );
		$this->visitor();
		$this->meta->added( 'post', 40, 'delivery_note', 'Leave at the door' );
		self::assertSame( [ 'meta.added' ], $this->actions() );
	}

	public function test_record_machine_writes_opts_back_in(): void {
		$this->useSettings( [ 'record_machine_writes' => true ] );
		$this->bindListeners();
		$this->request( 'POST', '/wp-json/wpml/v1/single-url-worker' );
		$this->visitor();
		$this->options->onUpdated( 'acme_layout', 'a', 'b' );
		$this->options->onUpdated( 'wpml_single_url_resolution_worker_status', 'a', 'b' );
		$this->meta->updated( 'user', 3, 'wp_2fa_last_totp_step', '58901234' );
		self::assertSame( [ 'option.updated', 'option.updated', 'meta.updated' ], $this->actions() );
		self::assertSame( Severity::INFO, $this->events[0]->getSeverity(), 'no person: recorded as in 1.3' );
		self::assertSame( Severity::DEBUG, $this->events[1]->getSeverity(), 'vendor state: debug as in 1.3' );
		self::assertSame( 'key_pattern', $this->events[1]->getContext()['technical_reason'] ?? null );
		self::assertSame( 'key_pattern', $this->events[2]->getContext()['technical_reason'] ?? null );
	}

	public function test_record_machine_writes_constant_style_value_is_coerced(): void {
		$this->useSettings( [ 'record_machine_writes' => 'yes' ] );
		self::assertTrue( $this->config->bool( 'record_machine_writes' ) );
		$this->useSettings( [] );
		self::assertFalse( $this->config->bool( 'record_machine_writes' ) );
	}

	public function test_site_owner_filters_still_apply(): void {
		Filters\expectApplied( 'noformat_logger/technical_key_patterns' )->andReturnUsing(
			static fn( array $list ): array => array_merge( [ '!wpml_pull_delivery_state' ], $list )
		);
		Filters\expectApplied( 'noformat_logger/security_meta_keys' )->andReturnUsing(
			static fn( array $list ): array => array_merge( $list, [ 'acme_mfa_*' ] )
		);
		$this->useSettings( [] );
		$this->bindListeners();
		Functions\when( 'is_admin' )->justReturn( true );
		$this->request( 'POST', '/wp-admin/admin-ajax.php' );
		$this->loggedIn( 1 );
		$this->options->onUpdated( 'wpml_pull_delivery_state', 'a', 'b' );
		self::assertSame( [ 'option.updated' ], $this->actions(), 'an exemption makes a vendor key recordable again' );
		$this->visitor();
		$this->meta->updated( 'user', 3, 'acme_mfa_enabled', '1' );
		self::assertSame( [ 'option.updated', 'meta.updated' ], $this->actions(), 'an added security key is recorded without a person' );
	}
}
