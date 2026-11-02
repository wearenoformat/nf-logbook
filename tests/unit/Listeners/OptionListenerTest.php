<?php
/**
 * OptionListener tests: ACF option page writes and widget options are skipped.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Listeners;

use Noformat\Logger\Listeners\AcfListener;
use Noformat\Logger\Listeners\OptionListener;

final class OptionListenerTest extends ListenerTestCase {

	private OptionListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->listener = new OptionListener();
		$this->listener->register( $this->logger, $this->config );
	}

	protected function tearDown(): void {
		AcfListener::markSaving( 'options', false );
		AcfListener::markSaving( 'site_settings', false );
		AcfListener::markSaving( 12, false );
		parent::tearDown();
	}

	public function test_plain_option_is_logged(): void {
		$this->listener->onUpdated( 'options_hero', 'a', 'b' );
		self::assertSame( [ 'option.updated' ], $this->actions(), 'options_* outside an ACF save is a normal option' );
	}

	public function test_acf_default_options_page_is_skipped_while_saving(): void {
		AcfListener::markSaving( 'options', true );
		$this->listener->onUpdated( 'options_hero', 'a', 'b' );
		$this->listener->onAdded( '_options_hero', 'field_abc' );
		$this->listener->onUpdated( 'my_setting', 'a', 'b' );
		self::assertSame( [ 'option.updated' ], $this->actions() );
		self::assertSame( 'my_setting', $this->events[0]->getObject()['id'] ?? null );
	}

	public function test_acf_custom_options_page_id_with_underscore(): void {
		AcfListener::markSaving( 'site_settings', true );
		self::assertTrue( AcfListener::isSavingOption( 'site_settings_footer_text' ) );
		self::assertTrue( AcfListener::isSavingOption( '_site_settings_footer_text' ) );
		self::assertFalse( AcfListener::isSavingOption( 'site_footer' ) );
		$this->listener->onUpdated( 'site_settings_footer_text', 'a', 'b' );
		self::assertSame( [], $this->actions() );
	}

	public function test_post_save_does_not_hide_options(): void {
		AcfListener::markSaving( 12, true );
		self::assertFalse( AcfListener::isSavingOption( 'options_hero' ) );
		self::assertFalse( AcfListener::isSavingOption( '12_hero' ), 'post ids are not option pages' );
	}

	public function test_widget_options_stay_with_widget_listener(): void {
		$this->listener->onUpdated( 'sidebars_widgets', [ 'a' => [] ], [ 'a' => [ 'block-2' ] ] );
		$this->listener->onUpdated( 'widget_block', [], [ 2 => [ 'content' => 'x' ] ] );
		$this->listener->onAdded( 'widget_text', [] );
		self::assertSame( [], $this->actions() );
	}
}
