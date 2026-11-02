<?php
/**
 * MenuListener.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Listeners;

use Noformat\Logger\Category;
use Noformat\Logger\Config;
use Noformat\Logger\Event;
use Noformat\Logger\Listener;
use Noformat\Logger\Listeners\Support\Hooks;
use Noformat\Logger\Logger;
use Noformat\Logger\Privacy\Redactor;
use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Menus: wp_create_nav_menu, wp_update_nav_menu, wp_delete_nav_menu, wp_update_nav_menu_item, nav_menu_locations in theme mods (PLAN §4).
 *
 * Menu items are snapshotted on the first write to one of their _menu_item_*
 * meta keys, so saving a whole menu only logs the items that really changed.
 */
final class MenuListener implements Listener {

	use Hooks;

	/**
	 * Menu name snapshots (edit_terms), by term id.
	 *
	 * @var array<int, string>
	 */
	private array $menuNames = [];

	/**
	 * Item snapshots, by item id; null state = new item.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private array $items = [];

	/**
	 * Availability.
	 */
	public static function isAvailable(): bool {
		return true;
	}

	/**
	 * Add hooks.
	 *
	 * @param Logger $logger Logger.
	 * @param Config $config Config.
	 */
	public function register( Logger $logger, Config $config ): void {
		$this->bind( $logger, $config );
		$this->onAction( 'wp_create_nav_menu', 'created', 10, 2 );
		$this->onAction( 'edit_terms', 'snapshotMenu', 10, 2 );
		$this->onAction( 'wp_update_nav_menu', 'updated', 10, 2 );
		$this->onAction( 'wp_delete_nav_menu', 'deleted', 10, 1 );
		$this->onFilter( 'update_post_metadata', 'snapshotItem', 1, 3 );
		$this->onAction( 'wp_update_nav_menu_item', 'itemUpdated', 10, 3 );
		$this->onAction( 'before_delete_post', 'itemDeleted', 10, 2 );
		$this->onAction( 'updated_option', 'optionUpdated', 10, 3 );
		$this->onAction( 'added_option', fn( $option, $value ) => $this->optionUpdated( $option, [], $value ), 10, 2 );
	}

	/**
	 * Menu created.
	 *
	 * @param mixed $menuId   Term id.
	 * @param mixed $menuData Menu data.
	 */
	public function created( mixed $menuId, mixed $menuData = [] ): void {
		$id   = self::intId( $menuId );
		$name = is_array( $menuData ) && isset( $menuData['menu-name'] ) ? (string) $menuData['menu-name'] : '';
		if ( $id <= 0 ) {
			return;
		}
		$this->emit(
			Event::make( Category::MENU, 'menu.created', Severity::NOTICE )
				->object( 'menu', $id, null, $this->hash8( $name ) )
				->summary( sprintf( 'Menu #%d created', $id ) )
				->context( [ 'menu_name' => $name ] )
		);
	}

	/**
	 * Snapshot a menu before wp_update_term().
	 *
	 * @param mixed $termId   Term id.
	 * @param mixed $taxonomy Taxonomy.
	 */
	public function snapshotMenu( mixed $termId, mixed $taxonomy ): void {
		$id = self::intId( $termId );
		if ( 'nav_menu' !== $taxonomy || $id <= 0 || isset( $this->menuNames[ $id ] ) ) {
			return;
		}
		$term = get_term( $id, 'nav_menu' );
		if ( is_object( $term ) && isset( $term->name ) ) {
			$this->menuNames[ $id ] = (string) $term->name;
		}
	}

	/**
	 * Menu updated (logged when its name changed).
	 *
	 * @param mixed $menuId   Term id.
	 * @param mixed $menuData Menu data (empty when only items were saved).
	 */
	public function updated( mixed $menuId, mixed $menuData = [] ): void {
		$id = self::intId( $menuId );
		if ( $id <= 0 || ! is_array( $menuData ) || ! isset( $menuData['menu-name'] ) || ! isset( $this->menuNames[ $id ] ) ) {
			return;
		}
		$oldValue = $this->menuNames[ $id ];
		$newValue = (string) $menuData['menu-name'];
		unset( $this->menuNames[ $id ] );
		if ( $oldValue === $newValue ) {
			return;
		}
		$this->emit(
			Event::make( Category::MENU, 'menu.updated', Severity::NOTICE )
				->object( 'menu', $id, null, $this->hash8( $newValue ) )
				->summary( sprintf( 'Menu #%d renamed', $id ) )
				->change( 'menu_name', $oldValue, $newValue )
		);
	}

	/**
	 * Menu deleted.
	 *
	 * @param mixed $menuId Term id.
	 */
	public function deleted( mixed $menuId ): void {
		$id = self::intId( $menuId );
		if ( $id <= 0 ) {
			return;
		}
		$this->emit(
			Event::make( Category::MENU, 'menu.deleted', Severity::NOTICE )
				->object( 'menu', $id )
				->summary( sprintf( 'Menu #%d deleted', $id ) )
		);
	}

	/**
	 * First _menu_item_* write for an item in this request: snapshot it.
	 *
	 * @param mixed $check    Short-circuit value.
	 * @param mixed $objectId Post id.
	 * @param mixed $metaKey  Meta key.
	 */
	public function snapshotItem( mixed $check, mixed $objectId, mixed $metaKey ): void {
		$id = self::intId( $objectId );
		if ( null !== $check || $id <= 0 || ! is_string( $metaKey ) || ! str_starts_with( $metaKey, '_menu_item_' ) || array_key_exists( $id, $this->items ) ) {
			return;
		}
		if ( 'nav_menu_item' !== get_post_type( $id ) ) {
			return;
		}
		$meta               = get_post_meta( $id );
		$this->items[ $id ] = is_array( $meta ) && isset( $meta['_menu_item_type'] ) ? $this->itemState( $id ) : null;
	}

	/**
	 * Item saved: diff against the snapshot.
	 *
	 * @param mixed $menuId Menu id.
	 * @param mixed $itemId Item id.
	 */
	public function itemUpdated( mixed $menuId, mixed $itemId ): void {
		$id = self::intId( $itemId );
		if ( $id <= 0 ) {
			return;
		}
		$created  = ! array_key_exists( $id, $this->items ) ? false : null === $this->items[ $id ];
		$oldValue = $this->items[ $id ] ?? null;
		unset( $this->items[ $id ] );
		if ( null === $oldValue && ! $created ) {
			return; // Nothing was written for this item (no _menu_item_* update).
		}
		$newValue = $this->itemState( $id );
		$changes  = self::itemDiff( $oldValue ?? [], $newValue );
		if ( [] === $changes && ! $created ) {
			return;
		}
		$type = is_string( $newValue['type'] ?? null ) ? $newValue['type'] : null;
		$this->emit(
			Event::make( Category::MENU, 'menu.item_updated', Severity::NOTICE )
				->object( 'menu_item', $id, $type, $newValue['title_hash'] ?? null )
				->summary( sprintf( 'Menu item #%d %s in menu #%d', $id, $created ? 'added' : 'updated', self::intId( $menuId ) ) )
				->changes( $changes )
				->context(
					[
						'menu_id' => self::intId( $menuId ),
						'created' => $created,
					]
				)
		);
	}

	/**
	 * Menu item deleted (aggregated per menu and request).
	 *
	 * @param mixed $postId Post id.
	 * @param mixed $post   Post.
	 */
	public function itemDeleted( mixed $postId, mixed $post = null ): void {
		$id   = self::intId( $postId );
		$post = is_object( $post ) ? $post : get_post( $id );
		if ( $id <= 0 || ! is_object( $post ) || 'nav_menu_item' !== ( $post->post_type ?? '' ) ) {
			return;
		}
		$menus  = wp_get_object_terms( $id, 'nav_menu', [ 'fields' => 'ids' ] );
		$menuId = is_array( $menus ) && isset( $menus[0] ) ? (int) $menus[0] : 0;
		$this->emit(
			Event::make( Category::MENU, 'menu.item_deleted', Severity::NOTICE )
				->object( 'menu_item', $id, null, $this->hash8( $post->post_title ?? '' ) )
				->summary( sprintf( 'Menu item #%d deleted from menu #%d', $id, $menuId ) )
				->context( [ 'menu_id' => $menuId ] )
				->fingerprint( 'menu.item_deleted', $menuId, $this->requestId() )
		);
	}

	/**
	 * Theme mods updated: log nav_menu_locations changes only.
	 *
	 * @param mixed $option Option name.
	 * @param mixed $oldValue    Old value.
	 * @param mixed $newValue    New value.
	 */
	public function optionUpdated( mixed $option, mixed $oldValue, mixed $newValue ): void {
		if ( ! is_string( $option ) || ! str_starts_with( $option, 'theme_mods_' ) ) {
			return;
		}
		$changes = self::locationDiff( $oldValue, $newValue );
		if ( [] === $changes ) {
			return;
		}
		$theme = substr( $option, strlen( 'theme_mods_' ) );
		$this->emit(
			Event::make( Category::MENU, 'menu.locations_changed', Severity::NOTICE )
				->object( 'theme', $theme )
				->summary( sprintf( 'Menu locations changed for theme %s: %s', $theme, implode( ', ', array_map( static fn( array $c ): string => substr( $c['field'], 9 ), $changes ) ) ) )
				->changes( $changes )
		);
	}

	/**
	 * Location → menu id changes between two theme_mods values.
	 *
	 * @param mixed $oldValue Old theme mods.
	 * @param mixed $newValue New theme mods.
	 * @return list<array{field: string, old: int|null, new: int|null}>
	 */
	public static function locationDiff( mixed $oldValue, mixed $newValue ): array {
		$oldValue = Redactor::maybeUnserialize( $oldValue );
		$newValue = Redactor::maybeUnserialize( $newValue );
		$before   = is_array( $oldValue ) && is_array( $oldValue['nav_menu_locations'] ?? null ) ? $oldValue['nav_menu_locations'] : [];
		$after    = is_array( $newValue ) && is_array( $newValue['nav_menu_locations'] ?? null ) ? $newValue['nav_menu_locations'] : [];
		$changes  = [];
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $location ) {
			$a = isset( $before[ $location ] ) ? (int) $before[ $location ] : null;
			$b = isset( $after[ $location ] ) ? (int) $after[ $location ] : null;
			$a = 0 === $a ? null : $a;
			$b = 0 === $b ? null : $b;
			if ( $a !== $b ) {
				$changes[] = [
					'field' => 'location.' . $location,
					'old'   => $a,
					'new'   => $b,
				];
			}
		}
		return $changes;
	}

	/**
	 * Comparable item state from the database (no labels, no full URLs).
	 *
	 * @param int $id Item id.
	 * @return array<string, mixed>
	 */
	public function itemState( int $id ): array {
		$post    = get_post( $id );
		$meta    = get_post_meta( $id );
		$meta    = is_array( $meta ) ? $meta : [];
		$get     = static function ( string $key ) use ( $meta ): mixed {
			$values = $meta[ $key ] ?? [];
			return Redactor::maybeUnserialize( is_array( $values ) ? ( $values[0] ?? '' ) : $values );
		};
		$url     = (string) ( is_scalar( $get( '_menu_item_url' ) ) ? $get( '_menu_item_url' ) : '' );
		$classes = $get( '_menu_item_classes' );
		$classes = is_array( $classes ) ? array_values( array_filter( array_map( 'strval', $classes ), static fn( string $c ): bool => '' !== $c ) ) : [];
		return [
			'type'       => (string) $get( '_menu_item_type' ),
			'object'     => (string) $get( '_menu_item_object' ),
			'object_id'  => (int) $get( '_menu_item_object_id' ),
			'parent_id'  => (int) $get( '_menu_item_menu_item_parent' ),
			'position'   => is_object( $post ) ? (int) $post->menu_order : 0,
			'title_hash' => is_object( $post ) ? $this->hash8( $post->post_title ) : null,
			'url_host'   => self::urlHost( $url ),
			'url_hash'   => '' === $url ? null : $this->hash8( $url ),
			'target'     => (string) $get( '_menu_item_target' ),
			'classes'    => $classes,
			'status'     => is_object( $post ) ? (string) $post->post_status : '',
		];
	}

	/**
	 * Changes between item states.
	 *
	 * @param array<string, mixed> $oldValue Old ([] for new items).
	 * @param array<string, mixed> $newValue New.
	 * @return list<array{field: string, old: mixed, new: mixed}>
	 */
	public static function itemDiff( array $oldValue, array $newValue ): array {
		$changes = [];
		foreach ( $newValue as $field => $value ) {
			$before = $oldValue[ $field ] ?? null;
			if ( $before !== $value && ! ( [] === $oldValue && ( null === $value || '' === $value || 0 === $value || [] === $value ) ) ) {
				$changes[] = [
					'field' => (string) $field,
					'old'   => $before,
					'new'   => $value,
				];
			}
		}
		return $changes;
	}

	/**
	 * Host of a URL (no path, no query); null for relative URLs.
	 *
	 * @param string $url URL.
	 */
	public static function urlHost( string $url ): ?string {
		if ( '' === $url ) {
			return null;
		}
		$host = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_HOST ) : parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- fallback outside WordPress.
		return is_string( $host ) && '' !== $host ? strtolower( $host ) : null;
	}
}
