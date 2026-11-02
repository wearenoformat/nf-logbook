<?php
/**
 * MetaListener tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Listeners;

use Brain\Monkey\Functions;
use Noformat\Logger\Listeners\AcfListener;
use Noformat\Logger\Listeners\MetaListener;
use Noformat\Logger\Severity;

final class MetaListenerTest extends ListenerTestCase {

	private MetaListener $listener;

	/**
	 * Post types by id.
	 *
	 * @var array<int, string>
	 */
	private array $types = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_post_type' )->alias( fn( $id ) => $this->types[ (int) $id ] ?? 'post' );
		$this->listener = ( new MetaListener() )->bind( $this->logger, $this->config );
	}

	public function test_skip_rules(): void {
		self::assertFalse( $this->listener->shouldLog( 'post', 1, '_edit_lock' ), 'Config ignored key' );
		self::assertFalse( $this->listener->shouldLog( 'user', 1, 'wp_capabilities' ), 'UserListener owns roles' );
		self::assertFalse( $this->listener->shouldLog( 'user', 1, 'wp_2_user_level' ) );
		self::assertFalse( $this->listener->shouldLog( 'post', 1, '_wp_attachment_metadata' ), 'MediaListener owns it' );
		self::assertFalse( $this->listener->shouldLog( 'post', 1, '_wp_attached_file' ), 'file path' );
		self::assertFalse( $this->listener->shouldLog( 'post', 1, '_menu_item_url' ) );
		self::assertFalse( $this->listener->shouldLog( 'comment', 1, 'akismet_history' ) );
		self::assertTrue( $this->listener->shouldLog( 'post', 1, '_wp_page_template' ) );
		self::assertTrue( $this->listener->shouldLog( 'post', 1, '_thumbnail_id' ) );
		$this->types[2] = 'revision';
		self::assertFalse( $this->listener->shouldLog( 'post', 2, 'footnotes' ) );
		$this->types[3] = 'nav_menu_item';
		self::assertFalse( $this->listener->shouldLog( 'post', 3, 'anything' ) );
	}

	public function test_severity(): void {
		self::assertSame( Severity::INFO, $this->listener->severityFor( '_wp_page_template' ) );
		self::assertSame( Severity::INFO, $this->listener->severityFor( '_thumbnail_id' ) );
		self::assertSame( Severity::INFO, $this->listener->severityFor( 'color' ) );
		self::assertSame( Severity::DEBUG, $this->listener->severityFor( '_some_internal' ) );
		self::assertSame( Severity::INFO, $this->listener->severityFor( '_yoast_wpseo_metadesc' ) );
		self::assertSame( Severity::DEBUG, $this->listener->severityFor( '_yoast_wpseo_content_score' ) );
		self::assertSame( Severity::DEBUG, $this->listener->severityFor( '_yoast_wpseo_estimated-reading-time-minutes' ) );
	}

	public function test_technical_keys_are_debug_and_flagged(): void {
		foreach ( [ 'aiowps_last_login_time', 'wc_last_login', 'wpseo_keyword_analysis_disable', 'screen_layout_post', 'edit_post_per_page', '_yoast_wpseo_estimated-reading-time-minutes', 'imagify_data', 'managenav-menuscolumnshidden' ] as $key ) {
			self::assertTrue( $this->listener->isTechnical( $key ), $key );
			self::assertSame( Severity::DEBUG, $this->listener->severityFor( $key ), $key );
		}
		foreach ( [ 'wpseo_title', 'wpseo_metadesc', 'description', '_yoast_wpseo_title', 'last_login_note', 'gform_1_unique_id' ] as $key ) {
			self::assertFalse( $this->listener->isTechnical( $key ), $key );
		}
		self::assertSame( Severity::INFO, $this->listener->severityFor( 'wpseo_title' ) );
		$this->listener->added( 'user', 4, 'aiowps_last_login_time', '1700000000' );
		self::assertSame( [ 'meta.added' ], $this->actions() );
		self::assertSame( Severity::DEBUG, $this->events[0]->getSeverity() );
		self::assertTrue( $this->events[0]->getContext()['technical'] ?? false );
	}

	public function test_empty_added_value_is_not_logged(): void {
		$this->listener->added( 'post', 4, 'wpseo_title', '' );
		$this->listener->added( 'post', 4, 'subtitle', '0' );
		self::assertSame( [ 'meta.added' ], $this->actions() );
		self::assertSame( '0', $this->events[0]->getChanges()[0]['new'] );
	}

	public function test_technical_keys_do_not_overlap_the_ignored_keys(): void {
		foreach ( MetaListener::TECHNICAL_KEYS as $pattern ) {
			self::assertNotContains( $pattern, \Noformat\Logger\Config::defaultIgnoredMetaKeys(), $pattern );
		}
		Functions\when( 'apply_filters' )->alias( static fn( string $hook, mixed $value ): mixed => 'noformat_logger/technical_meta_keys' === $hook ? [ 'my_cache_*' ] : $value );
		$listener = ( new MetaListener() )->bind( $this->logger, $this->config );
		self::assertTrue( $listener->isTechnical( 'my_cache_x' ) );
		self::assertFalse( $listener->isTechnical( 'aiowps_x' ) );
	}

	public function test_update_uses_captured_old_value(): void {
		Functions\when( 'get_metadata_raw' )->justReturn( [ 'default' ] );
		$this->listener->captureOld( 'post', null, 12, '_wp_page_template' );
		$this->listener->updated( 'post', 12, '_wp_page_template', 'templates/wide.php' );
		self::assertSame( [ 'meta.updated' ], $this->actions() );
		$event = $this->events[0];
		self::assertSame( [ [ 'field' => '_wp_page_template', 'old' => 'default', 'new' => 'templates/wide.php' ] ], $event->getChanges() );
		self::assertSame( 'post', $event->getObject()['type'] ?? null );
		self::assertSame( '_wp_page_template', $event->getObject()['subtype'] ?? null );
		self::assertSame( 'post', $event->getContext()['post_type'] );
		// Second row of the same multi-row update is not logged twice.
		$this->listener->updated( 'post', 12, '_wp_page_template', 'templates/wide.php' );
		self::assertCount( 1, $this->events );
	}

	public function test_unchanged_value_not_logged_and_serialized_diffed_by_key(): void {
		Functions\when( 'get_metadata_raw' )->justReturn( [ [ 'a' => 1, 'b' => 2 ] ] );
		$this->listener->captureOld( 'post', null, 5, 'settings' );
		$this->listener->updated( 'post', 5, 'settings', [ 'a' => 1, 'b' => 2 ] );
		self::assertSame( [], $this->actions() );
		$this->listener->captureOld( 'post', null, 5, 'settings' );
		$this->listener->updated( 'post', 5, 'settings', [ 'a' => 1, 'b' => 3 ] );
		self::assertSame( [ [ 'field' => 'settings.b', 'old' => 2, 'new' => 3 ] ], $this->events[0]->getChanges() );
	}

	public function test_sensitive_and_free_text_values(): void {
		$this->listener->added( 'user', 4, 'billing_phone', '+41 79 000 00 00' );
		$this->listener->added( 'user', 4, 'description', 'I live at Main Street 1' );
		self::assertSame( [ 'meta.added', 'meta.added' ], $this->actions() );
		self::assertTrue( $this->events[0]->getChanges()[0]['new']['redacted'] );
		self::assertArrayHasKey( 'sha256_8', $this->events[1]->getChanges()[0]['new'] );
		self::assertStringNotContainsString( '+41', $this->json() );
		self::assertStringNotContainsString( 'Main Street', $this->json() );
	}

	public function test_acf_save_suppresses_meta_events(): void {
		AcfListener::markSaving( 12, true );
		AcfListener::markSaving( 'term_3', true );
		try {
			self::assertFalse( $this->listener->shouldLog( 'post', 12, 'hero_title' ) );
			self::assertFalse( $this->listener->shouldLog( 'term', 3, 'color' ) );
			self::assertTrue( $this->listener->shouldLog( 'post', 13, 'hero_title' ) );
			self::assertTrue( $this->listener->shouldLog( 'user', 12, 'hero_title' ) );
		} finally {
			AcfListener::markSaving( 12, false );
			AcfListener::markSaving( 'term_3', false );
		}
		self::assertTrue( $this->listener->shouldLog( 'post', 12, 'hero_title' ) );
	}

	public function test_object_deletion_suppresses_meta_cleanup(): void {
		$this->listener->markDeleting( 'user', 8, true );
		$this->listener->deleted( 'user', [ 1, 2 ], 8, 'nickname', '' );
		self::assertSame( [], $this->actions() );
		$this->listener->markDeleting( 'user', 8, false );
		Functions\when( 'get_metadata_raw' )->justReturn( [ 'blue' ] );
		$this->listener->beforeDelete( 'user', [ 3 ], 8, 'favorite_color', '' );
		$this->listener->deleted( 'user', [ 3 ], 8, 'favorite_color', '' );
		self::assertSame( [ 'meta.deleted' ], $this->actions() );
		$change = $this->events[0]->getChanges()[0];
		self::assertSame( 'favorite_color', $change['field'] );
		self::assertSame( 4, $change['old']['length'], 'user meta is a content summary' );
		self::assertNull( $change['new'] );
		self::assertStringNotContainsString( 'blue', $this->json() );
	}

	public function test_user_meta_with_an_arbitrary_key_is_a_content_summary(): void {
		$canary = 'Demo Editor w7wh %%sep%% %%sitename%%';
		Functions\when( 'get_metadata_raw' )->justReturn( [ 'Old Name %%sep%%' ] );
		$this->listener->captureOld( 'user', null, 7, 'wpseo_title' );
		$this->listener->updated( 'user', 7, 'wpseo_title', $canary );
		$this->listener->added( 'user', 7, 'my_plugin_profile', [ 'ip' => '203.0.113.0', 'city' => 'Zurich' ] );
		$this->listener->added( 'user', 7, 'twitter', 'janedoe_zurich' );
		self::assertSame( [ 'meta.updated', 'meta.added', 'meta.added' ], $this->actions() );
		$event  = $this->events[0];
		$change = $event->getChanges()[0];
		self::assertSame( 'wpseo_title', $change['field'] );
		self::assertSame( [ 'sha256_8', 'length' ], array_keys( $change['old'] ) );
		self::assertSame( strlen( $canary ), $change['new']['length'] );
		self::assertSame( strlen( $canary ) - 16, $change['new']['length_delta'] );
		self::assertTrue( $change['new']['changed'] );
		self::assertSame( 'Meta "wpseo_title" updated on user #7', $event->getSummary() );
		self::assertSame( 'wpseo_title', $event->getContext()['meta_key'] );
		self::assertTrue( $this->events[1]->getChanges()[0]['new']['redacted'], 'non-scalar value: marker, never the structure' );
		self::assertSame( 'my_plugin_profile', $this->events[1]->getChanges()[0]['field'] );
		self::assertSame( 14, $this->events[2]->getChanges()[0]['new']['length'] );
		foreach ( [ 'Demo Editor', 'Old Name', '203.0.113', 'Zurich', 'janedoe', '"ip"', '"city"' ] as $needle ) {
			self::assertStringNotContainsString( $needle, $this->json(), $needle );
		}
	}

	public function test_user_meta_unchanged_value_is_not_logged(): void {
		Functions\when( 'get_metadata_raw' )->justReturn( [ 'same' ] );
		$this->listener->captureOld( 'user', null, 7, 'twitter' );
		$this->listener->updated( 'user', 7, 'twitter', 'same' );
		self::assertSame( [], $this->actions() );
	}

	public function test_user_structural_keys_are_diffed(): void {
		self::assertContains( 'admin_color', MetaListener::USER_STRUCTURAL_KEYS );
		Functions\when( 'get_metadata_raw' )->justReturn( [ 'fresh' ] );
		$this->listener->captureOld( 'user', null, 7, 'admin_color' );
		$this->listener->updated( 'user', 7, 'admin_color', 'midnight' );
		$this->listener->added( 'user', 7, 'locale', 'de_CH' );
		self::assertSame( [ [ 'field' => 'admin_color', 'old' => 'fresh', 'new' => 'midnight' ] ], $this->events[0]->getChanges() );
		self::assertSame( [ [ 'field' => 'locale', 'old' => null, 'new' => 'de_CH' ] ], $this->events[1]->getChanges() );
	}

	public function test_non_viewable_post_meta_is_a_content_summary(): void {
		Functions\when( 'is_post_type_viewable' )->alias( static fn( $type ) => 'flamingo_inbound' !== $type );
		$this->types[30] = 'flamingo_inbound';
		$this->listener->added( 'post', 30, 'your-message', 'Hello, I am Jane and I need help' );
		$this->listener->added( 'post', 30, '_field_settings', [ 'subject' => 'Help from Jane' ] );
		self::assertSame( [ 'meta.added', 'meta.added' ], $this->actions() );
		self::assertSame( 32, $this->events[0]->getChanges()[0]['new']['length'] );
		self::assertTrue( $this->events[1]->getChanges()[0]['new']['redacted'] );
		self::assertSame( 'flamingo_inbound', $this->events[0]->getContext()['post_type'] );
		self::assertStringNotContainsString( 'Jane', $this->json() );
	}

	public function test_post_meta_of_unknown_type_is_a_content_summary(): void {
		Functions\when( 'get_post_type' )->justReturn( false );
		$this->listener->added( 'post', 31, 'note', 'Secret note' );
		self::assertArrayHasKey( 'sha256_8', $this->events[0]->getChanges()[0]['new'] );
		self::assertStringNotContainsString( 'Secret', $this->json() );
	}

	public function test_viewable_post_meta_keeps_full_diffs(): void {
		$this->types[32] = 'page';
		$this->listener->added( 'post', 32, '_thumbnail_id', '55' );
		$this->listener->added( 'post', 32, 'subtitle', 'Our services' );
		self::assertSame( [ [ 'field' => '_thumbnail_id', 'old' => null, 'new' => '55' ] ], $this->events[0]->getChanges() );
		self::assertSame( 'Our services', $this->events[1]->getChanges()[0]['new'] );
	}

	public function test_comment_meta_is_a_content_summary(): void {
		$this->listener->added( 'comment', 9, 'rating_note', 'Call me on Monday, Jane' );
		self::assertSame( 23, $this->events[0]->getChanges()[0]['new']['length'] );
		self::assertStringNotContainsString( 'Jane', $this->json() );
	}

	public function test_term_meta_keeps_full_diffs(): void {
		$this->listener->added( 'term', 4, 'color', 'red' );
		self::assertSame( [ [ 'field' => 'color', 'old' => null, 'new' => 'red' ] ], $this->events[0]->getChanges() );
	}

	public function test_default_meta_of_a_new_user_is_not_logged(): void {
		$user = (object) [ 'ID' => 9 ];
		$this->listener->markCreatingUser( $user, false );
		self::assertFalse( $this->listener->shouldLog( 'user', 9, 'nickname' ) );
		self::assertFalse( $this->listener->shouldLog( 'user', 9, 'admin_color' ) );
		self::assertTrue( $this->listener->shouldLog( 'user', 10, 'nickname' ), 'other users unaffected' );
		self::assertTrue( $this->listener->shouldLog( 'post', 9, 'nickname' ), 'other meta types unaffected' );
		$this->listener->creatingUserDone( 9 );
		self::assertTrue( $this->listener->shouldLog( 'user', 9, 'favorite_color' ), 'meta written after user_register is logged' );

		$this->listener->markCreatingUser( $user, true );
		self::assertTrue( $this->listener->shouldLog( 'user', 9, 'nickname' ), 'profile updates are logged' );
	}

	public function test_tracking_is_bounded_and_cleared_on_add(): void {
		Functions\when( 'get_metadata_raw' )->justReturn( [] );
		// update_metadata() that became an add: the captured value must not linger.
		$this->listener->captureOld( 'post', null, 5, 'color' );
		self::assertSame( 1, $this->listener->tracked() );
		$this->listener->added( 'post', 5, 'color', 'red' );
		self::assertSame( 0, $this->listener->tracked() );

		// Unchanged values never reach updated_*_meta: FIFO cap.
		for ( $i = 0; $i < MetaListener::MAX_TRACKED + 50; $i++ ) {
			$this->listener->captureOld( 'post', null, 1000 + $i, 'color' );
		}
		self::assertSame( MetaListener::MAX_TRACKED, $this->listener->tracked() );
	}
}
