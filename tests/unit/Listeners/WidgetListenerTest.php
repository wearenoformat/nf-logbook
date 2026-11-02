<?php
/**
 * WidgetListener tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Listeners;

use Noformat\Logger\Listeners\WidgetListener;

final class WidgetListenerTest extends ListenerTestCase {

	public function test_sidebar_diff(): void {
		$diff = WidgetListener::sidebarDiff(
			[ 'array_version' => 3, 'sidebar-1' => [ 'block-1', 'block-2' ], 'wp_inactive_widgets' => [] ],
			[ 'array_version' => 3, 'sidebar-1' => [ 'block-2', 'block-1', 'block-3' ], 'wp_inactive_widgets' => [] ]
		);
		self::assertSame( [ 'sidebar-1' ], array_keys( $diff ) );
		self::assertSame( [ 'block-3' ], $diff['sidebar-1']['added'] );
		self::assertSame( [], $diff['sidebar-1']['removed'] );
		self::assertTrue( $diff['sidebar-1']['reordered'] );
	}

	public function test_block_widget_content_is_summarised(): void {
		$listener = ( new WidgetListener() )->bind( $this->logger, $this->config );
		$listener->optionUpdated(
			'widget_block',
			[ 2 => [ 'content' => '<p>Old secret</p>' ], '_multiwidget' => 1 ],
			[ 2 => [ 'content' => '<p>New secret</p>' ], 3 => [ 'content' => '<p>Added</p>' ], '_multiwidget' => 1 ]
		);
		self::assertSame( [ 'widget.updated', 'widget.updated' ], $this->actions() );
		self::assertSame( 'block-2', $this->events[0]->getObject()['id'] ?? null );
		self::assertSame( 'block', $this->events[0]->getObject()['subtype'] ?? null );
		self::assertSame( 'added', $this->events[1]->getContext()['operation'] );
		self::assertStringNotContainsString( 'secret', $this->json() );
	}

	public function test_classic_widget_settings_diffed(): void {
		$listener = ( new WidgetListener() )->bind( $this->logger, $this->config );
		$listener->optionUpdated( 'widget_archives', [ 2 => [ 'title' => 'A', 'count' => 0 ] ], [ 2 => [ 'title' => 'A', 'count' => 1 ] ] );
		self::assertSame( [ [ 'field' => 'count', 'old' => 0, 'new' => 1 ] ], $this->events[0]->getChanges() );
	}

	public function test_sidebars_widgets_event(): void {
		$listener = ( new WidgetListener() )->bind( $this->logger, $this->config );
		$listener->optionUpdated( 'sidebars_widgets', [ 'a' => [ 'x-1' ] ], [ 'a' => [], 'wp_inactive_widgets' => [ 'x-1' ] ] );
		self::assertSame( [ 'sidebar.updated', 'sidebar.updated' ], $this->actions() );
		self::assertSame( [ 'x-1' ], $this->events[0]->getContext()['removed'] );
		self::assertSame( [ 'x-1' ], $this->events[1]->getContext()['added'] );
	}
}
