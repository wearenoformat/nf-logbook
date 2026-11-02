<?php
/**
 * CommentListener.
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
 * Comments: wp_insert_comment, edit_comment, transition_comment_status, delete_comment (PLAN §4).
 *
 * Author name, email, URL, IP, user agent and the text are never copied; the
 * text is a content summary (hash + length). Spam is emitted from
 * transition_comment_status (which spam_comment always precedes) so a spam
 * action is logged once. New spam comments are aggregated per post.
 */
final class CommentListener implements Listener {

	use Hooks;

	/**
	 * Author fields whose change is reported by name only.
	 *
	 * @var list<string>
	 */
	public const AUTHOR_FIELDS = [ 'comment_author', 'comment_author_email', 'comment_author_url', 'comment_author_IP', 'comment_agent' ];

	/**
	 * Comments before wp_update_comment(), by id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $before = [];

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
		$this->onAction( 'wp_insert_comment', 'created', 10, 2 );
		$this->onFilter( 'wp_update_comment_data', 'snapshot', 10, 2 );
		$this->onAction( 'edit_comment', 'edited', 10, 2 );
		$this->onAction( 'transition_comment_status', 'transition', 10, 3 );
		$this->onAction( 'delete_comment', 'deleted', 10, 2 );
	}

	/**
	 * Map comment_approved to a status word.
	 *
	 * @param mixed $approved comment_approved value.
	 */
	public static function statusName( mixed $approved ): string {
		$approved = is_scalar( $approved ) ? (string) $approved : '';
		return match ( $approved ) {
			'1', 'approve', 'approved' => 'approved',
			'0', 'hold', 'unapproved' => 'unapproved',
			'spam' => 'spam',
			'trash' => 'trash',
			'post-trashed' => 'post-trashed',
			default => '' === $approved ? 'unknown' : $approved,
		};
	}

	/**
	 * Comment created.
	 *
	 * @param mixed $commentId Comment id.
	 * @param mixed $comment   Comment.
	 */
	public function created( mixed $commentId, mixed $comment ): void {
		$id = self::intId( $commentId );
		if ( $id <= 0 || ! is_object( $comment ) ) {
			return;
		}
		$status = self::statusName( $comment->comment_approved ?? '' );
		$type   = self::type( $comment );
		$postId = (int) ( $comment->comment_post_ID ?? 0 );
		$event  = Event::make( Category::COMMENT, 'comment.created', Severity::INFO )
			->object( 'comment', $id, $type )
			->summary( sprintf( 'Comment #%d (%s) on post #%d created, %s', $id, $type, $postId, $status ) )
			->context( $this->context( $comment ) + [ 'content' => $this->contentSummary( $comment->comment_content ?? '' ) ] );
		if ( 'spam' === $status ) {
			$event->fingerprint( 'comment.created.spam', $postId );
		}
		$this->emit( $event );
	}

	/**
	 * Snapshot the stored comment before an update (filter passes data through).
	 *
	 * @param mixed $data    New data.
	 * @param mixed $comment Stored comment (array).
	 */
	public function snapshot( mixed $data, mixed $comment ): void {
		unset( $data );
		if ( is_array( $comment ) && isset( $comment['comment_ID'] ) ) {
			$this->before[ (int) $comment['comment_ID'] ] = $comment;
		}
	}

	/**
	 * Comment edited (status changes are logged by transition()).
	 *
	 * @param mixed $commentId Comment id.
	 * @param mixed $data      Data written.
	 */
	public function edited( mixed $commentId, mixed $data = [] ): void {
		$id = self::intId( $commentId );
		if ( $id <= 0 ) {
			return;
		}
		unset( $data );
		$oldValue = $this->before[ $id ] ?? null;
		unset( $this->before[ $id ] );
		$comment = get_comment( $id );
		if ( ! is_object( $comment ) ) {
			return;
		}
		$newValue = get_object_vars( $comment );
		$changes  = is_array( $oldValue ) ? $this->diff( $oldValue, $newValue ) : [];
		$authors  = is_array( $oldValue ) ? self::changedAuthorFields( $oldValue, $newValue ) : [];
		if ( is_array( $oldValue ) && [] === $changes && [] === $authors ) {
			return;
		}
		$type    = self::type( $comment );
		$context = $this->context( $comment );
		if ( [] !== $authors ) {
			$context['identity_fields_changed'] = $authors;
		}
		if ( ! is_array( $oldValue ) ) {
			$context['baseline_missing'] = true;
		}
		$this->emit(
			Event::make( Category::COMMENT, 'comment.updated', Severity::INFO )
				->object( 'comment', $id, $type )
				->summary( sprintf( 'Comment #%d (%s) updated', $id, $type ) )
				->changes( $changes )
				->context( $context )
		);
	}

	/**
	 * Status transition (approved, unapproved, spam, trash).
	 *
	 * @param mixed $newStatus New status.
	 * @param mixed $oldStatus Old status.
	 * @param mixed $comment   Comment.
	 */
	public function transition( mixed $newStatus, mixed $oldStatus, mixed $comment ): void {
		if ( ! is_object( $comment ) || ! is_string( $newStatus ) || ! is_string( $oldStatus ) ) {
			return;
		}
		$newValue = self::statusName( $newStatus );
		$oldValue = self::statusName( $oldStatus );
		if ( $newValue === $oldValue || 'delete' === $newStatus ) {
			return;
		}
		$id     = self::intId( $comment->comment_ID ?? 0 );
		$type   = self::type( $comment );
		$action = 'spam' === $newValue ? 'comment.spammed' : 'comment.status_changed';
		$this->emit(
			Event::make( Category::COMMENT, $action, Severity::INFO )
				->object( 'comment', $id, $type )
				->summary( sprintf( 'Comment #%d (%s) status: %s → %s', $id, $type, $oldValue, $newValue ) )
				->change( 'status', $oldValue, $newValue )
				->context( $this->context( $comment ) )
		);
	}

	/**
	 * Comment deleted permanently.
	 *
	 * @param mixed $commentId Comment id.
	 * @param mixed $comment   Comment.
	 */
	public function deleted( mixed $commentId, mixed $comment = null ): void {
		$id      = self::intId( $commentId );
		$comment = is_object( $comment ) ? $comment : get_comment( $id );
		if ( $id <= 0 || ! is_object( $comment ) ) {
			return;
		}
		$type = self::type( $comment );
		$this->emit(
			Event::make( Category::COMMENT, 'comment.deleted', Severity::INFO )
				->object( 'comment', $id, $type )
				->summary( sprintf( 'Comment #%d (%s) deleted', $id, $type ) )
				->context( $this->context( $comment ) )
		);
	}

	/**
	 * Structural changes (no author fields, no status).
	 *
	 * @param array<string, mixed> $oldValue Old row.
	 * @param array<string, mixed> $newValue New row.
	 * @return list<array{field: string, old: mixed, new: mixed}>
	 */
	public function diff( array $oldValue, array $newValue ): array {
		$changes = [];
		$map     = [
			'post_id'   => 'comment_post_ID',
			'parent_id' => 'comment_parent',
			'type'      => 'comment_type',
			'user_id'   => 'user_id',
			'date'      => 'comment_date',
			'karma'     => 'comment_karma',
		];
		foreach ( $map as $field => $column ) {
			$a = (string) ( $oldValue[ $column ] ?? '' );
			$b = (string) ( $newValue[ $column ] ?? '' );
			if ( $a !== $b ) {
				$changes[] = [
					'field' => $field,
					'old'   => $a,
					'new'   => $b,
				];
			}
		}
		$a = (string) ( $oldValue['comment_content'] ?? '' );
		$b = (string) ( $newValue['comment_content'] ?? '' );
		if ( $a !== $b ) {
			$changes[] = [
				'field' => 'content',
				'old'   => $this->contentSummary( $a ),
				'new'   => $this->contentSummary( $b, $a ),
			];
		}
		return $changes;
	}

	/**
	 * Names of author fields that changed (values never logged).
	 *
	 * @param array<string, mixed> $oldValue Old row.
	 * @param array<string, mixed> $newValue New row.
	 * @return list<string>
	 */
	public static function changedAuthorFields( array $oldValue, array $newValue ): array {
		$changed = [];
		foreach ( self::AUTHOR_FIELDS as $field ) {
			if ( (string) ( $oldValue[ $field ] ?? '' ) !== (string) ( $newValue[ $field ] ?? '' ) ) {
				$changed[] = $field;
			}
		}
		return $changed;
	}

	/**
	 * Safe context: ids, type, status only.
	 *
	 * @param object $comment Comment.
	 * @return array<string, mixed>
	 */
	private function context( object $comment ): array {
		$userId = (int) ( $comment->user_id ?? 0 );
		return [
			'post_id'        => (int) ( $comment->comment_post_ID ?? 0 ),
			'parent_id'      => (int) ( $comment->comment_parent ?? 0 ),
			'comment_type'   => self::type( $comment ),
			'status'         => self::statusName( $comment->comment_approved ?? '' ),
			'author_user_id' => $userId > 0 ? $userId : null,
		];
	}

	/**
	 * Comment type ("comment" when empty).
	 *
	 * @param object $comment Comment.
	 */
	private static function type( object $comment ): string {
		$type = (string) ( $comment->comment_type ?? '' );
		return '' === $type ? 'comment' : $type;
	}
}
