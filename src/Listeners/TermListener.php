<?php
/**
 * TermListener.
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
use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Taxonomies and terms: created_term, edit_terms (pre), edited_term, delete_term, set_object_terms, deleted_term_relationships (PLAN §4).
 *
 * Term names are logged in clear only for taxonomies on an allow-list
 * (category, post_tag, post_format and public taxonomies); other taxonomies
 * get a salted hash, because a private taxonomy may hold personal data.
 */
final class TermListener implements Listener {

	use Hooks;

	/**
	 * Taxonomies not logged here (MenuListener owns nav_menu; the rest is block-theme plumbing).
	 *
	 * @var list<string>
	 */
	public const SKIP_TAXONOMIES = [ 'nav_menu', 'wp_theme', 'wp_template_part_area', 'wp_pattern_category' ];

	/**
	 * Taxonomies whose term names may be logged in clear (public taxonomies are added at runtime).
	 *
	 * @var list<string>
	 */
	public const NAME_TAXONOMIES = [ 'category', 'post_tag', 'post_format', 'link_category' ];

	/**
	 * Snapshots from edit_terms, by term id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $snapshots = [];

	/**
	 * Removals from wp_remove_object_terms() not (yet) covered by set_object_terms ("object:taxonomy" => tt ids).
	 *
	 * @var array<string, list<int>>
	 */
	private array $removals = [];

	/**
	 * A term deletion is reassigning objects (term.deleted carries the count instead).
	 *
	 * @var bool
	 */
	private bool $termDeletion = false;

	/**
	 * Resolved skip list.
	 *
	 * @var list<string>|null
	 */
	private ?array $skip = null;

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
		$this->onAction( 'created_term', 'created', 10, 3 );
		$this->onAction( 'edit_terms', 'snapshot', 10, 2 );
		$this->onAction( 'edited_term', 'edited', 10, 3 );
		$this->onAction( 'delete_term', 'deleted', 10, 5 );
		$this->onAction( 'set_object_terms', 'assigned', 10, 6 );
		// wp_remove_object_terms() does not fire set_object_terms: queue, emit on shutdown unless set_object_terms covered it.
		$this->onAction( 'deleted_term_relationships', 'relationshipsDeleted', 10, 3 );
		$this->onAction( 'pre_delete_term', fn() => $this->deletingTerm( true ), 10, 0 );
		$this->onAction( 'delete_term', fn() => $this->deletingTerm( false ), 1, 0 );
		$this->onAction( 'shutdown', 'flush', 1, 0 );
	}

	/**
	 * Whether a taxonomy is skipped (filter: noformat_logger/term_listener_skip_taxonomies).
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	public function skips( string $taxonomy ): bool {
		if ( null === $this->skip ) {
			$this->skip = self::filteredList( 'noformat_logger/term_listener_skip_taxonomies', self::SKIP_TAXONOMIES );
		}
		return '' === $taxonomy || in_array( $taxonomy, $this->skip, true );
	}

	/**
	 * Whether term names of a taxonomy may be logged in clear (filter: noformat_logger/term_name_taxonomies).
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	public function namesInClear( string $taxonomy ): bool {
		$list = self::NAME_TAXONOMIES;
		if ( function_exists( 'get_taxonomy' ) ) {
			$object = get_taxonomy( $taxonomy );
			if ( is_object( $object ) && ! empty( $object->public ) ) {
				$list[] = $taxonomy;
			}
		}
		return in_array( $taxonomy, self::filteredList( 'noformat_logger/term_name_taxonomies', $list ), true );
	}

	/**
	 * The loggable form of a term name: clear text or a redaction marker.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param mixed  $name     Name.
	 */
	public function nameValue( string $taxonomy, mixed $name ): mixed {
		if ( null === $name || null === $this->logger ) {
			return null;
		}
		return $this->namesInClear( $taxonomy ) ? (string) $name : $this->logger->redactor()->marker( (string) $name );
	}

	/**
	 * Term created.
	 *
	 * @param mixed $termId   Term id.
	 * @param mixed $ttId     Term taxonomy id.
	 * @param mixed $taxonomy Taxonomy.
	 */
	public function created( mixed $termId, mixed $ttId, mixed $taxonomy ): void {
		$id = self::intId( $termId );
		if ( $id <= 0 || ! is_string( $taxonomy ) || $this->skips( $taxonomy ) ) {
			return;
		}
		$term  = self::state( get_term( $id, $taxonomy ) );
		$event = Event::make( Category::TAXONOMY, 'term.created', Severity::INFO )
			->object( 'term', $id, $taxonomy, $this->hash8( $term['name'] ?? '' ) )
			->summary( sprintf( 'Term #%d (%s) created', $id, $taxonomy ) )
			->context(
				[
					'term_taxonomy_id' => self::intId( $ttId ),
					'slug'             => $term['slug'] ?? null,
					'parent_id'        => $term['parent_id'] ?? 0,
					'term_name'        => $this->nameValue( $taxonomy, $term['name'] ?? null ),
				]
			);
		$this->emit( $event );
	}

	/**
	 * Before an update: snapshot.
	 *
	 * @param mixed $termId   Term id.
	 * @param mixed $taxonomy Taxonomy.
	 */
	public function snapshot( mixed $termId, mixed $taxonomy ): void {
		$id = self::intId( $termId );
		if ( $id <= 0 || ! is_string( $taxonomy ) || $this->skips( $taxonomy ) || isset( $this->snapshots[ $id ] ) ) {
			return;
		}
		$this->snapshots[ $id ] = self::state( get_term( $id, $taxonomy ) );
	}

	/**
	 * Term updated.
	 *
	 * @param mixed $termId   Term id.
	 * @param mixed $ttId     Term taxonomy id.
	 * @param mixed $taxonomy Taxonomy.
	 */
	public function edited( mixed $termId, mixed $ttId, mixed $taxonomy ): void {
		$id = self::intId( $termId );
		if ( $id <= 0 || ! is_string( $taxonomy ) || $this->skips( $taxonomy ) || ! isset( $this->snapshots[ $id ] ) ) {
			return;
		}
		unset( $ttId );
		$oldValue = $this->snapshots[ $id ];
		unset( $this->snapshots[ $id ] );
		if ( function_exists( 'clean_term_cache' ) ) {
			clean_term_cache( $id, $taxonomy );
		}
		$newValue = self::state( get_term( $id, $taxonomy ) );
		$changes  = $this->diff( $taxonomy, $oldValue, $newValue );
		if ( [] === $changes ) {
			return;
		}
		$this->emit(
			Event::make( Category::TAXONOMY, 'term.updated', Severity::INFO )
				->object( 'term', $id, $taxonomy, $this->hash8( $newValue['name'] ?? '' ) )
				->summary( sprintf( 'Term #%d (%s) updated: %s', $id, $taxonomy, implode( ', ', array_column( $changes, 'field' ) ) ) )
				->changes( $changes )
		);
	}

	/**
	 * Term deleted.
	 *
	 * @param mixed $termId      Term id.
	 * @param mixed $ttId        Term taxonomy id.
	 * @param mixed $taxonomy    Taxonomy.
	 * @param mixed $deletedTerm Deleted term object.
	 * @param mixed $objectIds   Objects that had the term.
	 */
	public function deleted( mixed $termId, mixed $ttId, mixed $taxonomy, mixed $deletedTerm = null, mixed $objectIds = [] ): void {
		$id = self::intId( $termId );
		if ( $id <= 0 || ! is_string( $taxonomy ) || $this->skips( $taxonomy ) ) {
			return;
		}
		unset( $ttId );
		$name = is_object( $deletedTerm ) ? ( $deletedTerm->name ?? '' ) : '';
		$this->emit(
			Event::make( Category::TAXONOMY, 'term.deleted', Severity::INFO )
				->object( 'term', $id, $taxonomy, $this->hash8( $name ) )
				->summary( sprintf( 'Term #%d (%s) deleted', $id, $taxonomy ) )
				->context( [ 'object_count' => is_array( $objectIds ) ? count( $objectIds ) : 0 ] )
		);
	}

	/**
	 * Terms assigned to an object.
	 *
	 * @param mixed $objectId Object id.
	 * @param mixed $terms    Terms as passed.
	 * @param mixed $ttIds    New term taxonomy ids.
	 * @param mixed $taxonomy Taxonomy.
	 * @param mixed $append   Append mode.
	 * @param mixed $oldTtIds Previous term taxonomy ids.
	 */
	public function assigned( mixed $objectId, mixed $terms, mixed $ttIds, mixed $taxonomy, mixed $append = false, mixed $oldTtIds = [] ): void {
		$id = self::intId( $objectId );
		if ( $id <= 0 || ! is_string( $taxonomy ) || $this->skips( $taxonomy ) ) {
			return;
		}
		unset( $terms, $this->removals[ $id . ':' . $taxonomy ] );
		if ( $this->termDeletion ) {
			return;
		}
		$diff = self::assignmentDiff( is_array( $ttIds ) ? $ttIds : [], is_array( $oldTtIds ) ? $oldTtIds : [], (bool) $append );
		if ( [] === $diff['added'] && [] === $diff['removed'] ) {
			return;
		}
		$map         = $this->termIdsFor( array_merge( $diff['added'], $diff['removed'] ), $taxonomy );
		$toTermIds   = static fn( array $tt ): array => array_values( array_map( static fn( int $t ): int => $map[ $t ] ?? $t, $tt ) );
		$added       = $toTermIds( $diff['added'] );
		$removed     = $toTermIds( $diff['removed'] );
		$objectType  = $this->objectTypeFor( $taxonomy );
		$postType    = 'post' === $objectType && function_exists( 'get_post_type' ) ? get_post_type( $id ) : null;
		$skipObjects = [ 'revision', 'nav_menu_item', 'customize_changeset', 'wp_template', 'wp_template_part' ];
		if ( is_string( $postType ) && in_array( $postType, $skipObjects, true ) ) {
			return;
		}
		$this->emit(
			Event::make( Category::TAXONOMY, 'term.assigned', Severity::INFO )
				->object( $objectType, $id, $taxonomy )
				->summary( sprintf( 'Terms (%s) of %s #%d changed: +%d −%d', $taxonomy, $objectType, $id, count( $added ), count( $removed ) ) )
				->context(
					[
						'added'     => $added,
						'removed'   => $removed,
						'append'    => (bool) $append,
						'post_type' => is_string( $postType ) ? $postType : null,
						'id_kind'   => count( $map ) === count( array_unique( array_merge( $diff['added'], $diff['removed'] ) ) ) ? 'term_id' : 'mixed',
					]
				)
		);
	}

	/**
	 * Relationships removed (wp_remove_object_terms, also called by wp_set_object_terms).
	 *
	 * @param mixed $objectId Object id.
	 * @param mixed $ttIds    Removed term taxonomy ids.
	 * @param mixed $taxonomy Taxonomy.
	 */
	public function relationshipsDeleted( mixed $objectId, mixed $ttIds, mixed $taxonomy ): void {
		$id = self::intId( $objectId );
		if ( $id <= 0 || ! is_string( $taxonomy ) || $this->termDeletion || $this->skips( $taxonomy ) || ! is_array( $ttIds ) ) {
			return;
		}
		$key                    = $id . ':' . $taxonomy;
		$this->removals[ $key ] = array_values( array_unique( array_merge( $this->removals[ $key ] ?? [], array_map( 'intval', $ttIds ) ) ) );
	}

	/**
	 * Emit queued removals whose object still exists (object deletions are not assignment changes).
	 */
	public function flush(): void {
		$removals       = $this->removals;
		$this->removals = [];
		foreach ( $removals as $key => $ttIds ) {
			[ $id, $taxonomy ] = explode( ':', $key, 2 );
			if ( 'post' === $this->objectTypeFor( $taxonomy ) && function_exists( 'get_post' ) && ! is_object( get_post( (int) $id ) ) ) {
				continue;
			}
			$this->assigned( (int) $id, [], [], $taxonomy, false, $ttIds );
		}
	}

	/**
	 * Term deletion in progress.
	 *
	 * @param bool $deleting Deleting.
	 */
	public function deletingTerm( bool $deleting ): void {
		$this->termDeletion = $deleting;
	}

	/**
	 * Added/removed term taxonomy ids.
	 *
	 * @param array<int|string, mixed> $newValue    New tt ids.
	 * @param array<int|string, mixed> $oldValue    Old tt ids.
	 * @param bool                     $append Append mode (nothing removed).
	 * @return array{added: list<int>, removed: list<int>}
	 */
	public static function assignmentDiff( array $newValue, array $oldValue, bool $append ): array {
		$newValue = array_values( array_unique( array_map( 'intval', $newValue ) ) );
		$oldValue = array_values( array_unique( array_map( 'intval', $oldValue ) ) );
		$added    = array_values( array_diff( $newValue, $oldValue ) );
		$removed  = $append ? [] : array_values( array_diff( $oldValue, $newValue ) );
		sort( $added );
		sort( $removed );
		return [
			'added'   => $added,
			'removed' => $removed,
		];
	}

	/**
	 * Comparable term state.
	 *
	 * @param mixed $term Term object.
	 * @return array<string, mixed>
	 */
	public static function state( mixed $term ): array {
		if ( ! is_object( $term ) || ! isset( $term->term_id ) ) {
			return [];
		}
		return [
			'name'        => (string) ( $term->name ?? '' ),
			'slug'        => (string) ( $term->slug ?? '' ),
			'parent_id'   => (int) ( $term->parent ?? 0 ),
			'description' => (string) ( $term->description ?? '' ),
		];
	}

	/**
	 * Changes between two term states.
	 *
	 * @param string               $taxonomy Taxonomy.
	 * @param array<string, mixed> $oldValue      Old.
	 * @param array<string, mixed> $newValue      New.
	 * @return list<array{field: string, old: mixed, new: mixed}>
	 */
	public function diff( string $taxonomy, array $oldValue, array $newValue ): array {
		if ( [] === $oldValue || [] === $newValue ) {
			return [];
		}
		$changes = [];
		if ( $oldValue['name'] !== $newValue['name'] ) {
			$changes[] = [
				'field' => 'term_name',
				'old'   => $this->nameValue( $taxonomy, $oldValue['name'] ),
				'new'   => $this->nameValue( $taxonomy, $newValue['name'] ),
			];
		}
		foreach ( [ 'slug', 'parent_id' ] as $field ) {
			if ( (string) $oldValue[ $field ] !== (string) $newValue[ $field ] ) {
				$changes[] = [
					'field' => $field,
					'old'   => $oldValue[ $field ],
					'new'   => $newValue[ $field ],
				];
			}
		}
		if ( $oldValue['description'] !== $newValue['description'] ) {
			$changes[] = [
				'field' => 'description',
				'old'   => $this->contentSummary( $oldValue['description'] ),
				'new'   => $this->contentSummary( $newValue['description'], $oldValue['description'] ),
			];
		}
		return $changes;
	}

	/**
	 * Map term taxonomy ids to term ids with one query.
	 *
	 * @param list<int> $ttIds    Term taxonomy ids.
	 * @param string    $taxonomy Taxonomy.
	 * @return array<int, int>
	 */
	private function termIdsFor( array $ttIds, string $taxonomy ): array {
		if ( [] === $ttIds || ! function_exists( 'get_terms' ) ) {
			return [];
		}
		$terms = get_terms(
			[
				'taxonomy'               => $taxonomy,
				'term_taxonomy_id'       => $ttIds,
				'hide_empty'             => false,
				'update_term_meta_cache' => false,
			]
		);
		$map   = [];
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( is_object( $term ) ) {
					$map[ (int) $term->term_taxonomy_id ] = (int) $term->term_id;
				}
			}
		}
		return $map;
	}

	/**
	 * Object type a taxonomy applies to ("post" for post types, else the first object type).
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	private function objectTypeFor( string $taxonomy ): string {
		if ( 'link_category' === $taxonomy ) {
			return 'link';
		}
		$object = function_exists( 'get_taxonomy' ) ? get_taxonomy( $taxonomy ) : null;
		$types  = is_object( $object ) ? (array) $object->object_type : [];
		foreach ( $types as $type ) {
			if ( is_string( $type ) && function_exists( 'post_type_exists' ) && post_type_exists( $type ) ) {
				return 'post';
			}
		}
		$first = $types[0] ?? 'post';
		return is_string( $first ) && '' !== $first ? $first : 'post';
	}
}
