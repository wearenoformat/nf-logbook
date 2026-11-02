<?php
/**
 * MenuListener tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Listeners;

use Brain\Monkey\Functions;
use Noformat\Logger\Listeners\MenuListener;

final class MenuListenerTest extends ListenerTestCase {

	public function test_location_diff(): void {
		$old = [ 'nav_menu_locations' => [ 'primary' => 3, 'footer' => 4 ], 'custom_logo' => 9 ];
		$new = serialize( [ 'nav_menu_locations' => [ 'primary' => 5, 'social' => 6 ], 'custom_logo' => 10 ] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		self::assertSame(
			[
				[ 'field' => 'location.primary', 'old' => 3, 'new' => 5 ],
				[ 'field' => 'location.footer', 'old' => 4, 'new' => null ],
				[ 'field' => 'location.social', 'old' => null, 'new' => 6 ],
			],
			MenuListener::locationDiff( $old, $new )
		);
		self::assertSame( [], MenuListener::locationDiff( [ 'custom_logo' => 1 ], [ 'custom_logo' => 2 ] ), 'other theme mods ignored' );
	}

	public function test_locations_event_only_for_theme_mods(): void {
		$listener = ( new MenuListener() )->bind( $this->logger, $this->config );
		$listener->optionUpdated( 'blogname', [], [] );
		$listener->optionUpdated( 'theme_mods_twentytwentyfour', [], [ 'nav_menu_locations' => [ 'primary' => 2 ] ] );
		self::assertSame( [ 'menu.locations_changed' ], $this->actions() );
		self::assertSame( 'twentytwentyfour', $this->events[0]->getObject()['id'] ?? null );
	}

	public function test_url_host_only(): void {
		self::assertSame( 'example.com', MenuListener::urlHost( 'https://Example.com/path?token=abc#x' ) );
		self::assertNull( MenuListener::urlHost( '#' ) );
		self::assertNull( MenuListener::urlHost( '' ) );
	}

	public function test_item_diff(): void {
		$old = [ 'type' => 'custom', 'position' => 1, 'title_hash' => 'aaaa1111', 'classes' => [] ];
		$new = [ 'type' => 'custom', 'position' => 2, 'title_hash' => 'bbbb2222', 'classes' => [ 'cta' ] ];
		self::assertSame( [ 'position', 'title_hash', 'classes' ], array_column( MenuListener::itemDiff( $old, $new ), 'field' ) );
		self::assertSame( [ 'type' ], array_column( MenuListener::itemDiff( [], [ 'type' => 'custom', 'parent_id' => 0 ] ), 'field' ), 'new item: empty fields omitted' );
	}

	public function test_item_snapshot_and_update(): void {
		$meta = [
			'_menu_item_type'      => [ 'custom' ],
			'_menu_item_url'       => [ 'https://shop.example.org/a?b=c' ],
			'_menu_item_object'    => [ 'custom' ],
			'_menu_item_object_id' => [ '30' ],
			'_menu_item_classes'   => [ serialize( [ '' ] ) ], // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		];
		$post = self::post( [ 'ID' => 30, 'post_type' => 'nav_menu_item', 'post_title' => 'Shop', 'menu_order' => 1, 'post_status' => 'publish' ] );
		Functions\when( 'get_post_type' )->justReturn( 'nav_menu_item' );
		Functions\when( 'get_post' )->alias( static function () use ( &$post ) {
			return $post;
		} );
		Functions\when( 'get_post_meta' )->alias( static function () use ( &$meta ) {
			return $meta;
		} );
		$listener = ( new MenuListener() )->bind( $this->logger, $this->config );
		$listener->snapshotItem( null, 30, '_menu_item_url' );
		$listener->snapshotItem( null, 30, '_menu_item_type' );
		$post->menu_order = 2;
		$listener->itemUpdated( 7, 30 );
		self::assertSame( [ 'menu.item_updated' ], $this->actions() );
		self::assertSame( [ [ 'field' => 'position', 'old' => 1, 'new' => 2 ] ], $this->events[0]->getChanges() );
		self::assertSame( 7, $this->events[0]->getContext()['menu_id'] );
		// Unchanged save: no event.
		$listener->snapshotItem( null, 30, '_menu_item_url' );
		$listener->itemUpdated( 7, 30 );
		self::assertCount( 1, $this->events );
		self::assertStringNotContainsString( 'b=c', $this->json() );
		self::assertStringNotContainsString( 'Shop', $this->json() );
	}
}
