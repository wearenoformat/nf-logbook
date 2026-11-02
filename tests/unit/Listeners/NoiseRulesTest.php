<?php
/**
 * Machine-written state is debug + technical: write-on-read rule and state-key patterns.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Listeners;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Noformat\Logger\Config;
use Noformat\Logger\Event;
use Noformat\Logger\Listeners\MetaListener;
use Noformat\Logger\Listeners\OptionListener;
use Noformat\Logger\Severity;

final class NoiseRulesTest extends ListenerTestCase {

	private OptionListener $options;

	private MetaListener $meta;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_key' )->alias( static fn( $key ) => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) );
		$this->options = new OptionListener();
		$this->options->register( $this->logger, $this->config );
		$this->meta = ( new MetaListener() )->bind( $this->logger, $this->config );
	}

	protected function tearDown(): void {
		unset( $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_GET['_wpnonce'], $_GET['rest_route'], $_REQUEST['action'], $_POST['action'] );
		parent::tearDown();
	}

	/**
	 * Simulate a web request.
	 *
	 * @param string $method HTTP method.
	 * @param string $uri    Request URI.
	 */
	private function request( string $method, string $uri = '/' ): void {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_SERVER['REQUEST_URI']    = $uri;
	}

	private function last(): Event {
		self::assertNotEmpty( $this->events );
		return $this->events[ count( $this->events ) - 1 ];
	}

	private function assertTechnical( string $reason, string $message = '' ): void {
		$event = $this->last();
		self::assertSame( Severity::DEBUG, $event->getSeverity(), $message );
		self::assertTrue( $event->getContext()['technical'] ?? false, $message );
		self::assertSame( $reason, $event->getContext()['technical_reason'] ?? null, $message );
	}

	private function assertNotTechnical( Severity $severity, string $message = '' ): void {
		$event = $this->last();
		self::assertSame( $severity, $event->getSeverity(), $message );
		self::assertArrayNotHasKey( 'technical', $event->getContext(), $message );
	}

	public function test_get_option_write_is_technical(): void {
		$this->request( 'GET', '/some-page/' );
		$this->options->onUpdated( 'acme_cache_state', 'a', 'b' );
		$this->assertTechnical( 'read_request' );
		$this->request( 'HEAD' );
		$this->options->onAdded( 'acme_other_state', 'x' );
		$this->assertTechnical( 'read_request', 'HEAD' );
	}

	public function test_admin_page_load_and_rest_get_are_read_requests(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->request( 'GET', '/wp-admin/index.php' );
		$this->options->onUpdated( 'acme_cache_state', 'a', 'b' );
		$this->assertTechnical( 'read_request', 'admin page load' );
		Functions\when( 'is_admin' )->justReturn( false );
		$this->request( 'GET', '/wp-json/wp/v2/posts' );
		$_GET['_wpnonce'] = 'abc';
		$this->options->onUpdated( 'acme_cache_state', 'b', 'c' );
		$this->assertTechnical( 'read_request', 'REST GET, even with a cookie nonce' );
	}

	public function test_nonced_admin_get_is_a_deliberate_action(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->request( 'GET', '/wp-admin/edit.php?action=edit&post[]=1' );
		$_GET['_wpnonce'] = 'abc';
		$this->options->onUpdated( 'sticky_posts', [ 1 ], [ 1, 2 ] );
		$this->assertNotTechnical( Severity::INFO, 'bulk edit (GET form with a nonce)' );
	}

	public function test_post_option_write_of_normal_key_is_info(): void {
		$this->request( 'POST', '/wp-admin/admin-ajax.php' );
		$this->options->onUpdated( 'acme_layout', 'a', 'b' );
		$this->assertNotTechnical( Severity::INFO );
	}

	public function test_critical_and_notice_options_are_exempt_on_get(): void {
		$this->request( 'GET' );
		$this->options->onUpdated( 'siteurl', 'https://a.test', 'https://b.test' );
		$this->assertNotTechnical( Severity::CRITICAL, 'siteurl' );
		self::assertTrue( $this->last()->getContext()['critical_option'] ?? false );
		$this->options->onUpdated( 'blogname', 'A', 'B' );
		$this->assertNotTechnical( Severity::NOTICE, 'blogname' );
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		$this->options->onUpdated( 'home', 'https://a.test', 'https://c.test' );
		$this->assertNotTechnical( Severity::CRITICAL, 'home from cron' );
	}

	public function test_cron_meta_write_is_technical(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		$this->request( 'POST', '/wp-cron.php' );
		$this->meta->added( 'post', 7, 'acme_sync_status', 'done' );
		$this->assertTechnical( 'cron' );
		$this->options->onUpdated( 'acme_layout', 'a', 'b' );
		$this->assertTechnical( 'cron', 'option from cron' );
	}

	public function test_heartbeat_write_is_technical_other_ajax_is_not(): void {
		Functions\when( 'wp_doing_ajax' )->justReturn( true );
		$this->request( 'POST', '/wp-admin/admin-ajax.php' );
		$_REQUEST['action'] = 'heartbeat';
		$_POST['action']    = 'heartbeat';
		$this->meta->updated( 'post', 7, 'acme_field', 'x' );
		$this->assertTechnical( 'heartbeat' );
		$this->options->onUpdated( 'acme_layout', 'a', 'b' );
		$this->assertTechnical( 'heartbeat', 'option' );
		$_REQUEST['action'] = 'acme_save_settings';
		$_POST['action']    = 'acme_save_settings';
		$this->options->onUpdated( 'acme_layout', 'b', 'c' );
		$this->assertNotTechnical( Severity::INFO, 'a real ajax save' );
	}

	public function test_state_key_patterns_on_post_are_technical(): void {
		$this->request( 'POST', '/wp-admin/options.php' );
		foreach ( [ 'gsmtp_version_info', 'imagify_optimize_media_batch_x', 'wpseo_notifications', 'gform_version_info', 'gform_sticky_admin_messages', 'acme_dismissed_notices', 'acme_usage_analytics_last' ] as $i => $name ) {
			$this->options->onUpdated( $name, 'a' . $i, 'b' . $i );
			$this->assertTechnical( 'key_pattern', $name );
		}
		$this->meta->updated( 'user', 1, 'wp_yoast_notifications', 'x' );
		$this->assertTechnical( 'key_pattern', 'wp_yoast_notifications user meta' );
		$this->meta->added( 'user', 1, 'aiowps_last_login_time', '1700000000' );
		$this->assertTechnical( 'key_pattern', 'aiowps_last_login_time' );
	}

	public function test_deliberate_settings_save_on_post_is_info(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->request( 'POST', '/wp-admin/options.php' );
		foreach ( [ 'acme_settings_tab', 'aio_wp_security_configs', 'wpseo', 'wpseo_titles', 'imagify_settings', 'cookie_notice_options', 'rg_gforms_currency', 'woocommerce_demo_store_notice' ] as $i => $name ) {
			$this->options->onUpdated( $name, 'a' . $i, 'b' . $i );
			$this->assertNotTechnical( Severity::INFO, $name );
		}
		$this->meta->updated( 'post', 7, '_yoast_wpseo_metadesc', 'New description' );
		$this->assertNotTechnical( Severity::INFO, 'Yoast field an editor types' );
	}

	public function test_meaningful_meta_on_get_is_still_machine_state(): void {
		$this->request( 'GET' );
		$this->meta->updated( 'post', 7, '_yoast_wpseo_metadesc', 'Rewritten on view' );
		$this->assertTechnical( 'read_request' );
	}

	public function test_patterns_are_filterable(): void {
		Filters\expectApplied( 'noformat_logger/technical_key_patterns' )->andReturnUsing(
			static fn( array $list ): array => array_merge( $list, [ 'acme_state_*', '/^bad(/', '!gsmtp_version_info' ] )
		);
		$config = new Config();
		self::assertTrue( $config->isTechnicalKey( 'acme_state_x' ) );
		self::assertFalse( $config->isTechnicalKey( 'gsmtp_version_info' ), 'exemption wins' );
		self::assertFalse( $config->isTechnicalKey( 'bad(' ), 'an invalid regex never matches' );
		self::assertTrue( $config->isTechnicalKey( 'wp_yoast_notifications' ) );
		self::assertFalse( $config->isTechnicalKey( '' ) );
	}
}
