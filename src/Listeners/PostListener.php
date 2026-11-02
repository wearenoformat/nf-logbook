<?php
/**
 * PostListener.
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
 * Posts, pages and custom post types: pre_post_update snapshot, wp_after_insert_post, transition_post_status, trash/untrash/delete, revision restore (PLAN §4).
 *
 * Saves are collected per post during the request and emitted once on
 * `shutdown` (priority 1, before the Logger flushes at 1000), so one request
 * yields at most one post.created/post.updated per post however many times
 * wp_insert_post() ran for it (REST + meta box save, save_post re-saves…),
 * followed by its status events in order.
 */
final class PostListener implements Listener {

	use Hooks;

	/** Pending posts kept before an early flush (long CLI runs). */
	public const MAX_PENDING = 50;

	/**
	 * Post types never logged here (other listeners own them, or noise).
	 *
	 * @var list<string>
	 */
	public const SKIP_TYPES = [
		'revision',
		'nav_menu_item',
		'acf-field-group',
		'acf-field',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'attachment',
	];

	/**
	 * Structural fields compared as plain values, in output order.
	 *
	 * @var list<string>
	 */
	public const FIELDS = [ 'title', 'slug', 'author_id', 'parent_id', 'menu_order', 'date', 'comment_status', 'ping_status', 'post_type', 'password' ];

	/**
	 * Long text fields reported as content summaries.
	 *
	 * @var list<string>
	 */
	public const TEXT_FIELDS = [ 'content', 'excerpt' ];

	/**
	 * Snapshots taken in pre_post_update, by post id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $snapshots = [];

	/**
	 * Saves collected in this request, by post id.
	 *
	 * @var array<int, array{type: string, baseline: array<string, mixed>|null, baselineSet: bool, created: bool, touched: bool, statuses: list<array{0: string, 1: string}>}>
	 */
	private array $pending = [];

	/**
	 * Posts between before_delete_post and deleted_post.
	 *
	 * @var array<int, array{type: string, label: string|null}>
	 */
	private array $deleting = [];

	/**
	 * Resolved skip list.
	 *
	 * @var list<string>|null
	 */
	private ?array $skipTypes = null;

	/**
	 * Post type => whether titles and slugs may be logged in clear.
	 *
	 * @var array<string, bool>
	 */
	private array $viewable = [];

	/** Fields hashed for post types that are not publicly viewable (labels can be e-mails, e.g. Flamingo contacts). */
	public const LABEL_FIELDS = [ 'title', 'slug' ];

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
		$this->onAction( 'pre_post_update', 'snapshot', 10, 1 );
		$this->onAction( 'transition_post_status', 'transition', 10, 3 );
		$this->onAction( 'wp_after_insert_post', 'afterInsert', 10, 4 );
		$this->onAction( 'wp_trash_post', 'trashed', 10, 2 );
		$this->onAction( 'untrashed_post', 'untrashed', 10, 2 );
		$this->onAction( 'before_delete_post', 'beforeDelete', 10, 2 );
		$this->onAction( 'deleted_post', 'deleted', 10, 2 );
		$this->onAction( 'wp_restore_post_revision', 'revisionRestored', 10, 2 );
		$this->onAction( 'shutdown', 'flush', 1, 0 );
	}

	/**
	 * Whether a post type is skipped (filter: noformat_logger/post_listener_skip_types).
	 *
	 * @param string $postType Post type.
	 */
	public function skips( string $postType ): bool {
		if ( null === $this->skipTypes ) {
			$this->skipTypes = self::filteredList( 'noformat_logger/post_listener_skip_types', self::SKIP_TYPES );
		}
		return '' === $postType || in_array( $postType, $this->skipTypes, true );
	}

	/**
	 * Pre-update snapshot (the cached post, before the database write).
	 *
	 * @param mixed $postId Post id.
	 */
	public function snapshot( mixed $postId ): void {
		$id = self::intId( $postId );
		if ( $id <= 0 || isset( $this->snapshots[ $id ] ) || isset( $this->pending[ $id ] ) ) {
			return;
		}
		$post = get_post( $id );
		if ( is_object( $post ) && ! $this->skips( (string) $post->post_type ) ) {
			$this->snapshots[ $id ] = self::state( $post );
		}
	}

	/**
	 * Status transition: recorded, emitted after the save event on flush.
	 *
	 * @param mixed $newStatus New status.
	 * @param mixed $oldStatus Old status.
	 * @param mixed $post      Post.
	 */
	public function transition( mixed $newStatus, mixed $oldStatus, mixed $post ): void {
		if ( ! is_object( $post ) || ! is_string( $newStatus ) || ! is_string( $oldStatus ) || $newStatus === $oldStatus ) {
			return;
		}
		$id   = self::intId( $post );
		$type = (string) ( $post->post_type ?? '' );
		if ( $id <= 0 || $this->skips( $type ) || null === self::statusAction( $oldStatus, $newStatus ) ) {
			return;
		}
		$this->ensure( $id, $type );
		$this->pending[ $id ]['statuses'][] = [ $oldStatus, $newStatus ];
	}

	/**
	 * A post was created or updated (fires once per wp_insert_post / REST save).
	 *
	 * @param mixed $postId     Post id.
	 * @param mixed $post       Post after the save.
	 * @param mixed $update     Whether this is an update.
	 * @param mixed $postBefore Post before the save (null for new posts).
	 */
	public function afterInsert( mixed $postId, mixed $post, mixed $update = false, mixed $postBefore = null ): void {
		$id = self::intId( $postId );
		if ( $id <= 0 || ! is_object( $post ) ) {
			return;
		}
		$type = (string) ( $post->post_type ?? '' );
		if ( $this->skips( $type ) || ( defined( 'DOING_AUTOSAVE' ) && constant( 'DOING_AUTOSAVE' ) ) ) {
			unset( $this->snapshots[ $id ] );
			return;
		}
		if ( 'auto-draft' === ( $post->post_status ?? '' ) ) {
			unset( $this->snapshots[ $id ] );
			return;
		}
		$this->ensure( $id, $type );
		if ( ! $this->pending[ $id ]['baselineSet'] ) {
			$before = $this->snapshots[ $id ] ?? ( is_object( $postBefore ) ? self::state( $postBefore ) : null );
			unset( $this->snapshots[ $id ] );
			$this->pending[ $id ]['baseline']    = $before;
			$this->pending[ $id ]['baselineSet'] = true;
			$this->pending[ $id ]['created']     = ! $update || ( null !== $before && in_array( $before['status'] ?? '', [ 'auto-draft', 'new' ], true ) );
		}
		$this->pending[ $id ]['touched'] = true;
	}

	/**
	 * Emit everything collected (shutdown, or early when the pending list is full).
	 */
	public function flush(): void {
		$pending         = $this->pending;
		$this->pending   = [];
		$this->snapshots = [];
		foreach ( $pending as $id => $entry ) {
			$this->logger?->guard( 'listener.post.flush', fn() => $this->emitEntry( $id, $entry ) );
		}
	}

	/**
	 * Trash.
	 *
	 * @param mixed $postId         Post id.
	 * @param mixed $previousStatus Status before trashing (WP 6.3+).
	 */
	public function trashed( mixed $postId, mixed $previousStatus = null ): void {
		$this->simple( $postId, 'post.trashed', 'trashed', [ 'previous_status' => is_string( $previousStatus ) ? $previousStatus : null ] );
	}

	/**
	 * Untrash.
	 *
	 * @param mixed $postId         Post id.
	 * @param mixed $previousStatus Status the post had before it was trashed.
	 */
	public function untrashed( mixed $postId, mixed $previousStatus = null ): void {
		$this->simple( $postId, 'post.restored', 'restored from trash', [ 'restored_status' => is_string( $previousStatus ) ? $previousStatus : null ] );
	}

	/**
	 * Before permanent deletion: remember type and label hash.
	 *
	 * @param mixed $postId Post id.
	 * @param mixed $post   Post.
	 */
	public function beforeDelete( mixed $postId, mixed $post = null ): void {
		$id   = self::intId( $postId );
		$post = is_object( $post ) ? $post : get_post( $id );
		if ( $id <= 0 || ! is_object( $post ) ) {
			return;
		}
		$type = (string) ( $post->post_type ?? '' );
		if ( $this->skips( $type ) ) {
			return;
		}
		$this->deleting[ $id ] = [
			'type'  => $type,
			'label' => $this->hash8( $post->post_title ?? '' ),
		];
	}

	/**
	 * After permanent deletion.
	 *
	 * @param mixed $postId Post id.
	 */
	public function deleted( mixed $postId ): void {
		$id = self::intId( $postId );
		if ( ! isset( $this->deleting[ $id ] ) ) {
			return;
		}
		$info = $this->deleting[ $id ];
		unset( $this->deleting[ $id ], $this->pending[ $id ], $this->snapshots[ $id ] );
		$this->emit(
			Event::make( Category::CONTENT, 'post.deleted', Severity::NOTICE )
				->object( 'post', $id, $info['type'], $info['label'] )
				->summary( sprintf( 'Post #%d (%s) deleted permanently', $id, $info['type'] ) )
		);
	}

	/**
	 * Revision restored.
	 *
	 * @param mixed $postId     Post id.
	 * @param mixed $revisionId Revision id.
	 */
	public function revisionRestored( mixed $postId, mixed $revisionId ): void {
		$this->simple( $postId, 'post.revision_restored', 'restored to revision #' . self::intId( $revisionId ), [ 'revision_id' => self::intId( $revisionId ) ], Severity::INFO );
	}

	/**
	 * Map a status transition to an action (null: not logged as a status event).
	 *
	 * @param string $oldValue Old status.
	 * @param string $newValue New status.
	 * @return array{0: string, 1: Severity}|null
	 */
	public static function statusAction( string $oldValue, string $newValue ): ?array {
		if ( $oldValue === $newValue || in_array( $newValue, [ 'auto-draft', 'inherit', 'trash', 'new' ], true ) || 'trash' === $oldValue ) {
			return null;
		}
		if ( 'publish' === $newValue ) {
			return [ 'post.published', Severity::NOTICE ];
		}
		if ( 'publish' === $oldValue ) {
			return [ 'post.unpublished', Severity::NOTICE ];
		}
		if ( in_array( $oldValue, [ 'new', 'auto-draft' ], true ) ) {
			return null; // Covered by post.created.
		}
		return [ 'post.status_changed', Severity::INFO ];
	}

	/**
	 * Plain snapshot of the fields we compare.
	 *
	 * @param object $post Post object.
	 * @return array<string, mixed>
	 */
	public static function state( object $post ): array {
		return [
			'post_type'      => (string) ( $post->post_type ?? '' ),
			'title'          => (string) ( $post->post_title ?? '' ),
			'slug'           => (string) ( $post->post_name ?? '' ),
			'status'         => (string) ( $post->post_status ?? '' ),
			'author_id'      => (int) ( $post->post_author ?? 0 ),
			'parent_id'      => (int) ( $post->post_parent ?? 0 ),
			'menu_order'     => (int) ( $post->menu_order ?? 0 ),
			'date'           => (string) ( $post->post_date ?? '' ),
			'comment_status' => (string) ( $post->comment_status ?? '' ),
			'ping_status'    => (string) ( $post->ping_status ?? '' ),
			'password'       => (string) ( $post->post_password ?? '' ),
			'content'        => (string) ( $post->post_content ?? '' ),
			'excerpt'        => (string) ( $post->post_excerpt ?? '' ),
		];
	}

	/**
	 * Field changes between two snapshots (status excluded: it has its own events).
	 *
	 * @param array<string, mixed> $oldValue Old snapshot.
	 * @param array<string, mixed> $newValue New snapshot.
	 * @return list<array{field: string, old: mixed, new: mixed}>
	 */
	public function diff( array $oldValue, array $newValue ): array {
		$changes = [];
		$clear   = $this->labelsInClear( (string) ( $newValue['post_type'] ?? $oldValue['post_type'] ?? '' ) );
		foreach ( self::FIELDS as $field ) {
			$a = $oldValue[ $field ] ?? null;
			$b = $newValue[ $field ] ?? null;
			if ( (string) $a !== (string) $b ) {
				if ( ! $clear && in_array( $field, self::LABEL_FIELDS, true ) ) {
					$a = $this->labelMarker( $a );
					$b = $this->labelMarker( $b );
				}
				$changes[] = [
					'field' => $field,
					'old'   => $a,
					'new'   => $b,
				];
			}
		}
		$full = null !== $this->config && $this->config->bool( 'full_content_diff' );
		foreach ( self::TEXT_FIELDS as $field ) {
			$a = (string) ( $oldValue[ $field ] ?? '' );
			$b = (string) ( $newValue[ $field ] ?? '' );
			if ( $a === $b ) {
				continue;
			}
			$changes[] = [
				'field' => $field,
				'old'   => $full ? $a : $this->contentSummary( $a ),
				'new'   => $full ? $b : $this->contentSummary( $b, $a ),
			];
		}
		return $changes;
	}

	/**
	 * Whether titles and slugs of a post type may be logged in clear: only for
	 * publicly viewable types (is_post_type_viewable(): public, or
	 * publicly_queryable for custom types), whose titles are public anyway.
	 * Private types (form submissions, CRM contacts, Flamingo) get markers.
	 *
	 * @param string $postType Post type.
	 */
	public function labelsInClear( string $postType ): bool {
		if ( '' === $postType ) {
			return false;
		}
		if ( ! isset( $this->viewable[ $postType ] ) ) {
			$this->viewable[ $postType ] = function_exists( 'is_post_type_viewable' ) && (bool) is_post_type_viewable( $postType );
		}
		return $this->viewable[ $postType ];
	}

	/**
	 * Redaction marker for a label of a non-viewable post type.
	 *
	 * @param mixed $value Title or slug.
	 * @return array{redacted: true, sha256_8: string}|null
	 */
	private function labelMarker( mixed $value ): ?array {
		if ( null === $value || '' === $value || null === $this->logger ) {
			return null;
		}
		return $this->logger->redactor()->marker( $value );
	}

	/**
	 * Pending entry for a post.
	 *
	 * @param int    $id   Post id.
	 * @param string $type Post type.
	 */
	private function ensure( int $id, string $type ): void {
		if ( isset( $this->pending[ $id ] ) ) {
			return;
		}
		if ( count( $this->pending ) >= self::MAX_PENDING ) {
			$this->flush();
		}
		$this->pending[ $id ] = [
			'type'        => $type,
			'baseline'    => null,
			'baselineSet' => false,
			'created'     => false,
			'touched'     => false,
			'statuses'    => [],
		];
	}

	/**
	 * Emit the events for one collected post.
	 *
	 * @param int                  $id    Post id.
	 * @param array<string, mixed> $entry Pending entry.
	 */
	private function emitEntry( int $id, array $entry ): void {
		$type     = (string) $entry['type'];
		$post     = get_post( $id );
		$current  = is_object( $post ) ? self::state( $post ) : null;
		$baseline = is_array( $entry['baseline'] ) ? $entry['baseline'] : null;
		$label    = null === $current ? null : $this->hash8( $current['title'] );
		$statuses = is_array( $entry['statuses'] ) ? $entry['statuses'] : [];

		$trash = 'trash' === ( $baseline['status'] ?? '' ) || 'trash' === ( $current['status'] ?? '' );
		foreach ( $statuses as $pair ) {
			$trash = $trash || in_array( 'trash', $pair, true );
		}

		if ( $entry['touched'] && null !== $current && ! $trash ) {
			if ( $entry['created'] ) {
				$this->emit(
					Event::make( Category::CONTENT, 'post.created', Severity::INFO )
						->object( 'post', $id, $type, $label )
						->summary( sprintf( 'Post #%d (%s) created', $id, $type ) )
						->context(
							[
								'status'    => $current['status'],
								'author_id' => $current['author_id'],
								'parent_id' => $current['parent_id'],
								'content'   => $this->contentSummary( $current['content'] ),
							]
						)
				);
			} elseif ( null === $baseline ) {
				$this->emit(
					Event::make( Category::CONTENT, 'post.updated', Severity::INFO )
						->object( 'post', $id, $type, $label )
						->summary( sprintf( 'Post #%d (%s) updated', $id, $type ) )
						->context( [ 'baseline_missing' => true ] )
				);
			} else {
				$changes = $this->diff( $baseline, $current );
				if ( [] !== $changes ) {
					$this->emit(
						Event::make( Category::CONTENT, 'post.updated', Severity::INFO )
							->object( 'post', $id, $type, $label )
							->summary( sprintf( 'Post #%d (%s) updated: %s', $id, $type, implode( ', ', array_column( $changes, 'field' ) ) ) )
							->changes( $changes )
					);
				}
			}
		}

		foreach ( $statuses as $pair ) {
			$mapped = self::statusAction( (string) $pair[0], (string) $pair[1] );
			if ( null === $mapped ) {
				continue;
			}
			$verb = substr( $mapped[0], 5 );
			$this->emit(
				Event::make( Category::CONTENT, $mapped[0], $mapped[1] )
					->object( 'post', $id, $type, $label )
					->summary( sprintf( 'Post #%d (%s) %s: %s → %s', $id, $type, str_replace( '_', ' ', $verb ), $pair[0], $pair[1] ) )
					->change( 'status', $pair[0], $pair[1] )
			);
		}
	}

	/**
	 * One-shot event for a post id (trash, untrash, revision restore).
	 *
	 * @param mixed                $postId   Post id.
	 * @param string               $action   Action.
	 * @param string               $verb     Summary verb.
	 * @param array<string, mixed> $context  Context.
	 * @param Severity             $severity Severity.
	 */
	private function simple( mixed $postId, string $action, string $verb, array $context, Severity $severity = Severity::NOTICE ): void {
		$id   = self::intId( $postId );
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! is_object( $post ) ) {
			return;
		}
		$type = (string) $post->post_type;
		if ( $this->skips( $type ) && 'attachment' !== $type ) {
			return;
		}
		$this->emit(
			Event::make( Category::CONTENT, $action, $severity )
				->object( 'post', $id, $type, $this->hash8( $post->post_title ) )
				->summary( sprintf( 'Post #%d (%s) %s', $id, $type, $verb ) )
				->context( array_filter( $context, static fn( $v ): bool => null !== $v ) )
		);
	}
}
