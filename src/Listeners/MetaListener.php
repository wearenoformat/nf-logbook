<?php
/**
 * MetaListener.
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
use Noformat\Logger\Listeners\Support\PersonalValues;
use Noformat\Logger\Logger;
use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Post/term/user/comment meta: update_{type}_metadata (pre), added/updated/deleted_{type}_meta; ignored keys from Config::isIgnoredMetaKey() (PLAN §4).
 *
 * Old values are captured in the pre filters (which return their input
 * unchanged, so the write proceeds). Values on users (except preference keys),
 * comments and posts of non-viewable post types are content summaries
 * (PersonalValues); term meta and viewable post meta are diffed. Underscore-prefixed (internal) keys are
 * logged at debug severity unless listed as meaningful; technical keys
 * (TECHNICAL_KEYS and Config::isTechnicalKey()) and every write during a read
 * request, cron or heartbeat (RequestContext::machineWriteReason()) at debug
 * with context.technical.
 */
final class MetaListener implements Listener {

	use Hooks;

	/**
	 * Meta types handled.
	 *
	 * @var list<string>
	 */
	public const TYPES = [ 'post', 'term', 'user', 'comment' ];

	/**
	 * Internal keys that are still meaningful (logged at info).
	 *
	 * @var list<string>
	 */
	public const MEANINGFUL_KEYS = [
		'_wp_page_template',
		'_thumbnail_id',
		'_wp_attachment_image_alt',
		// Yoast SEO fields an editor types (scores and timestamps stay debug).
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
		'_yoast_wpseo_focuskw',
		'_yoast_wpseo_canonical',
		'_yoast_wpseo_bctitle',
		'_yoast_wpseo_meta-robots-noindex',
		'_yoast_wpseo_meta-robots-nofollow',
		'_yoast_wpseo_opengraph-title',
		'_yoast_wpseo_opengraph-description',
		'_yoast_wpseo_twitter-title',
		'_yoast_wpseo_twitter-description',
		'_yoast_wpseo_is_cornerstone',
	];

	/**
	 * Keys owned by other listeners or unsafe to log, on top of Config's ignored_meta_keys.
	 *
	 * @var list<string>
	 */
	public const OWNED_KEYS = [
		'_wp_attached_file',        // File path; MediaListener.
		'_wp_attachment_metadata',  // MediaListener.
		'_menu_item_*',             // MenuListener.
		'akismet_*',                // Comment author data (IP, email) in history.
		'_wp_trash_meta_*',
		'_wp_old_*',
		'_wp_desired_post_slug',
		'_customize_*',
		'_edit_*',
	];

	/**
	 * Post types whose meta is not logged (owned elsewhere or noise).
	 *
	 * @var list<string>
	 */
	public const SKIP_POST_TYPES = [ 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request', 'acf-field-group', 'acf-field' ];

	/**
	 * User meta that holds preferences, not personal data: the only user meta
	 * diffed in clear. Every other user meta value (and every comment meta value,
	 * and post meta of non-viewable post types) is a content summary
	 * (PersonalValues, CONTRACT §2).
	 *
	 * @var list<string>
	 */
	public const USER_STRUCTURAL_KEYS = PersonalValues::USER_STRUCTURAL_KEYS;

	/**
	 * Technical keys: machine-written, never content (login timestamps, screen
	 * preferences, caches). Logged at debug severity with context.technical, so
	 * they are dropped unless log_debug is on. Exact names or `*` globs; keys
	 * already in Config::defaultIgnoredMetaKeys() (never stored) are not repeated.
	 * Filter: noformat_logger/technical_meta_keys.
	 *
	 * @var list<string>
	 */
	public const TECHNICAL_KEYS = [
		'aiowps_*',
		'*_last_login*',
		'show_welcome_panel',
		'default_password_nag',
		'_imagify_*',
		'imagify_*',
		'wpe_*',
		'_wpe_*',
		'rank_math_internal_links_processed',
		'_yoast_wpseo_estimated-reading-time-minutes',
		'_yoast_wpseo_wordproof_timestamp',
		'wpseo_keyword_analysis_disable',
		'wpseo_content_analysis_disable',
		'wpseo_inclusive_language_analysis_disable',
		'screen_layout_*',
		'edit_*_per_page',
		'managenav-menuscolumnshidden',
	];

	/** Max captured old values / consumed slots kept (FIFO), for long CLI and cron runs. */
	public const MAX_TRACKED = 500;

	/**
	 * Old values captured in the pre filters ("type:id:key" => value).
	 *
	 * @var array<string, mixed>
	 */
	private array $old = [];

	/**
	 * Keys whose captured value was already used in this write (multi-row updates).
	 *
	 * @var array<string, true>
	 */
	private array $consumed = [];

	/**
	 * Objects being deleted ("type:id" => true): their meta clean-up is not logged.
	 *
	 * @var array<string, true>
	 */
	private array $deleting = [];

	/**
	 * Users being created by wp_insert_user() (id => true): the default meta it
	 * writes (nickname, first/last name, admin colour, …) belongs to user.created.
	 *
	 * @var array<int, true>
	 */
	private array $creatingUsers = [];

	/**
	 * Resolved extra ignore list.
	 *
	 * @var list<string>|null
	 */
	private ?array $owned = null;

	/**
	 * Resolved technical list.
	 *
	 * @var list<string>|null
	 */
	private ?array $technical = null;

	/**
	 * Resolved meaningful list.
	 *
	 * @var list<string>|null
	 */
	private ?array $meaningful = null;

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
		foreach ( self::TYPES as $type ) {
			$this->onFilter( "update_{$type}_metadata", fn( $check, $id, $key ) => $this->captureOld( $type, $check, $id, $key ), 1, 3 );
			$this->onFilter( "update_{$type}_metadata_by_mid", fn( $check, $metaId ) => $this->captureOldByMid( $type, $check, $metaId ), 1, 2 );
			$this->onAction( "added_{$type}_meta", fn( $mid, $id, $key, $value ) => $this->added( $type, $id, $key, $value ), 10, 4 );
			$this->onAction( "updated_{$type}_meta", fn( $mid, $id, $key, $value ) => $this->updated( $type, $id, $key, $value ), 10, 4 );
			$this->onAction( "delete_{$type}_meta", fn( $ids, $id, $key, $value ) => $this->beforeDelete( $type, $ids, $id, $key, $value ), 10, 4 );
			$this->onAction( "deleted_{$type}_meta", fn( $ids, $id, $key, $value ) => $this->deleted( $type, $ids, $id, $key, $value ), 10, 4 );
		}
		// Object deletions remove all their meta: do not log each row.
		$this->onAction( 'before_delete_post', fn( $id ) => $this->markDeleting( 'post', $id, true ), 1, 1 );
		$this->onAction( 'delete_attachment', fn( $id ) => $this->markDeleting( 'post', $id, true ), 1, 1 );
		$this->onAction( 'deleted_post', fn( $id ) => $this->markDeleting( 'post', $id, false ), 99, 1 );
		$this->onAction( 'delete_user', fn( $id ) => $this->markDeleting( 'user', $id, true ), 1, 1 );
		$this->onAction( 'deleted_user', fn( $id ) => $this->markDeleting( 'user', $id, false ), 99, 1 );
		$this->onAction( 'delete_comment', fn( $id ) => $this->markDeleting( 'comment', $id, true ), 1, 1 );
		$this->onAction( 'deleted_comment', fn( $id ) => $this->markDeleting( 'comment', $id, false ), 99, 1 );
		$this->onAction( 'pre_delete_term', fn( $id ) => $this->markDeleting( 'term', $id, true ), 1, 1 );
		$this->onAction( 'delete_term', fn( $id ) => $this->markDeleting( 'term', $id, false ), 99, 1 );
		// A new user's default meta is part of user.created (UserListener), not separate meta.added events.
		$this->onFilter( 'insert_user_meta', fn( $meta, $user, $update ) => $this->markCreatingUser( $user, $update ), 1, 3 );
		$this->onAction( 'user_register', fn( $id ) => $this->creatingUserDone( $id ), 0, 1 );
	}

	/**
	 * Filter insert_user_meta (observe only): wp_insert_user() is about to write the default meta of a new user.
	 *
	 * @param mixed $user   WP_User (or object with ID).
	 * @param mixed $update Whether an existing user is updated.
	 */
	public function markCreatingUser( mixed $user, mixed $update ): void {
		if ( true === (bool) $update || ! is_object( $user ) || ! isset( $user->ID ) ) {
			return;
		}
		$id = self::intId( $user->ID );
		if ( $id > 0 ) {
			$this->creatingUsers[ $id ] = true;
		}
	}

	/**
	 * Action user_register (priority 0): the core part of the creation is over; meta written later is logged.
	 *
	 * @param mixed $id User id.
	 */
	public function creatingUserDone( mixed $id ): void {
		unset( $this->creatingUsers[ self::intId( $id ) ] );
	}

	/**
	 * Track object deletion.
	 *
	 * @param string $type     Meta type.
	 * @param mixed  $id       Object id.
	 * @param bool   $deleting Start (true) or end (false).
	 */
	public function markDeleting( string $type, mixed $id, bool $deleting ): void {
		$key = $type . ':' . self::intId( $id );
		if ( $deleting ) {
			$this->deleting[ $key ] = true;
		} else {
			unset( $this->deleting[ $key ] );
		}
	}

	/**
	 * Whether a meta write is logged at all.
	 *
	 * @param string $type     Meta type.
	 * @param int    $objectId Object id (0 for delete_all).
	 * @param string $key      Meta key.
	 */
	public function shouldLog( string $type, int $objectId, string $key ): bool {
		if ( '' === $key || null === $this->config ) {
			return false;
		}
		if ( $this->config->isIgnoredMetaKey( $key ) || Config::matchesGlob( $key, $this->ownedKeys() ) ) {
			return false;
		}
		if ( 'user' === $type && 1 === preg_match( '/(capabilities|user_level)$/', $key ) ) {
			return false; // UserListener owns roles and capabilities.
		}
		if ( isset( $this->deleting[ $type . ':' . $objectId ] ) ) {
			return false;
		}
		if ( 'user' === $type && isset( $this->creatingUsers[ $objectId ] ) ) {
			return false; // Default meta of a user being created (user.created covers it).
		}
		if ( $objectId > 0 && AcfListener::isSaving( $type, $objectId ) ) {
			return false; // AcfListener emits one grouped event.
		}
		if ( 'post' === $type && $objectId > 0 && function_exists( 'get_post_type' ) ) {
			$postType = get_post_type( $objectId );
			if ( is_string( $postType ) && in_array( $postType, self::SKIP_POST_TYPES, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Severity for a key: internal (underscore) keys are debug unless meaningful.
	 *
	 * @param string $key Meta key.
	 */
	public function severityFor( string $key ): Severity {
		if ( $this->isTechnical( $key ) ) {
			return Severity::DEBUG;
		}
		if ( str_starts_with( $key, '_' ) && ! in_array( $key, $this->meaningfulKeys(), true ) ) {
			return Severity::DEBUG;
		}
		return Severity::INFO;
	}

	/**
	 * Whether a key is machine-written (TECHNICAL_KEYS after the filter).
	 *
	 * @param string $key Meta key.
	 */
	public function isTechnical( string $key ): bool {
		if ( null === $this->technical ) {
			$this->technical = self::filteredList( 'noformat_logger/technical_meta_keys', self::TECHNICAL_KEYS );
		}
		if ( Config::matchesGlob( $key, $this->technical ) ) {
			return true;
		}
		// Shared state-key patterns (Config::isTechnicalKey()); fields an editor types stay visible.
		return null !== $this->config && $this->config->isTechnicalKey( $key ) && ! in_array( $key, $this->meaningfulKeys(), true );
	}

	/**
	 * Pre-update filter: capture the stored value(s).
	 *
	 * @param string $type  Meta type.
	 * @param mixed  $check Short-circuit value (non-null: the write will not happen).
	 * @param mixed  $id    Object id.
	 * @param mixed  $key   Meta key.
	 */
	public function captureOld( string $type, mixed $check, mixed $id, mixed $key ): void {
		$objectId = self::intId( $id );
		if ( null !== $check || ! is_string( $key ) || ! $this->shouldLog( $type, $objectId, $key ) ) {
			return;
		}
		$slot = $type . ':' . $objectId . ':' . $key;
		unset( $this->consumed[ $slot ] );
		$this->rememberOld( $slot, self::single( self::read( $type, $objectId, $key ) ) );
	}

	/**
	 * Pre-update by meta id (update_metadata_by_mid).
	 *
	 * @param string $type   Meta type.
	 * @param mixed  $check  Short-circuit value.
	 * @param mixed  $metaId Meta id.
	 */
	public function captureOldByMid( string $type, mixed $check, mixed $metaId ): void {
		if ( null !== $check || ! function_exists( 'get_metadata_by_mid' ) ) {
			return;
		}
		$meta = get_metadata_by_mid( $type, self::intId( $metaId ) );
		if ( ! is_object( $meta ) || ! isset( $meta->meta_key ) ) {
			return;
		}
		$column   = 'user' === $type ? 'user_id' : $type . '_id';
		$objectId = self::intId( $meta->{$column} ?? 0 );
		$key      = (string) $meta->meta_key;
		if ( $this->shouldLog( $type, $objectId, $key ) ) {
			$slot = $type . ':' . $objectId . ':' . $key;
			unset( $this->consumed[ $slot ] );
			$this->rememberOld( $slot, $meta->meta_value ?? null );
		}
	}

	/**
	 * Meta added.
	 *
	 * @param string $type  Meta type.
	 * @param mixed  $id    Object id.
	 * @param mixed  $key   Meta key.
	 * @param mixed  $value Value.
	 */
	public function added( string $type, mixed $id, mixed $key, mixed $value ): void {
		$objectId = self::intId( $id );
		if ( ! is_string( $key ) || ! $this->shouldLog( $type, $objectId, $key ) ) {
			return;
		}
		// update_metadata() turned into an add: the captured value is never consumed by updated().
		$slot = $type . ':' . $objectId . ':' . $key;
		unset( $this->old[ $slot ], $this->consumed[ $slot ] );
		if ( null === $value || '' === $value ) {
			return; // An empty field created alongside a save (Yoast, form plugins): nothing to report.
		}
		$this->emitChange( 'meta.added', 'added', $type, $objectId, $key, null, $value );
	}

	/**
	 * Meta updated.
	 *
	 * @param string $type  Meta type.
	 * @param mixed  $id    Object id.
	 * @param mixed  $key   Meta key.
	 * @param mixed  $value New value.
	 */
	public function updated( string $type, mixed $id, mixed $key, mixed $value ): void {
		$objectId = self::intId( $id );
		if ( ! is_string( $key ) || ! $this->shouldLog( $type, $objectId, $key ) ) {
			return;
		}
		$slot    = $type . ':' . $objectId . ':' . $key;
		$context = [];
		if ( array_key_exists( $slot, $this->old ) ) {
			$oldValue = $this->old[ $slot ];
			unset( $this->old[ $slot ] );
			$this->consumed[ $slot ] = true;
			self::cap( $this->consumed );
		} elseif ( isset( $this->consumed[ $slot ] ) ) {
			return; // Second row of the same multi-row update.
		} else {
			$oldValue               = null;
			$context['old_unknown'] = true;
		}
		$this->emitChange( 'meta.updated', 'updated', $type, $objectId, $key, $oldValue, $value, $context );
	}

	/**
	 * Before meta deletion: capture the values being removed.
	 *
	 * @param string $type    Meta type.
	 * @param mixed  $metaIds Meta ids.
	 * @param mixed  $id      Object id.
	 * @param mixed  $key     Meta key.
	 * @param mixed  $value   Value filter given to delete_metadata ('' for all).
	 */
	public function beforeDelete( string $type, mixed $metaIds, mixed $id, mixed $key, mixed $value ): void {
		$objectId = self::intId( $id );
		if ( ! is_string( $key ) || $objectId <= 0 || ! $this->shouldLog( $type, $objectId, $key ) ) {
			return;
		}
		unset( $metaIds );
		$this->rememberOld(
			$type . ':' . $objectId . ':' . $key,
			( '' !== $value && null !== $value && false !== $value ) ? $value : self::single( self::read( $type, $objectId, $key ) )
		);
	}

	/**
	 * Meta deleted.
	 *
	 * @param string $type    Meta type.
	 * @param mixed  $metaIds Deleted meta ids.
	 * @param mixed  $id      Object id (0 when deleting a key for all objects).
	 * @param mixed  $key     Meta key.
	 * @param mixed  $value   Value filter given to delete_metadata.
	 */
	public function deleted( string $type, mixed $metaIds, mixed $id, mixed $key, mixed $value ): void {
		$objectId = self::intId( $id );
		if ( ! is_string( $key ) || ! $this->shouldLog( $type, $objectId, $key ) ) {
			return;
		}
		$slot     = $type . ':' . $objectId . ':' . $key;
		$oldValue = array_key_exists( $slot, $this->old ) ? $this->old[ $slot ] : ( '' === $value ? null : $value );
		unset( $this->old[ $slot ] );
		$context = [ 'rows' => is_array( $metaIds ) ? count( $metaIds ) : 1 ];
		if ( 0 === $objectId ) {
			$context['all_objects'] = true;
		}
		$this->emitChange( 'meta.deleted', 'deleted', $type, $objectId, $key, $oldValue, null, $context, true );
	}

	/**
	 * Build and queue a meta event.
	 *
	 * @param string               $action   Action.
	 * @param string               $verb     Summary verb.
	 * @param string               $type     Meta type.
	 * @param int                  $objectId Object id.
	 * @param string               $key      Meta key.
	 * @param mixed                $oldValue      Old value.
	 * @param mixed                $newValue      New value.
	 * @param array<string, mixed> $context  Extra context.
	 * @param bool                 $always   Emit even without a value diff (deletions).
	 */
	public function emitChange( string $action, string $verb, string $type, int $objectId, string $key, mixed $oldValue, mixed $newValue, array $context = [], bool $always = false ): void {
		if ( null === $this->logger ) {
			return;
		}
		if ( PersonalValues::isPersonal( $type, $objectId, $key ) ) {
			// User profiles, comments, non-viewable post types: hash + size, never the text.
			$change  = PersonalValues::change( $this->logger->redactor(), $key, $oldValue, $newValue );
			$changes = null === $change ? [] : [ $change ];
		} else {
			$changes = $this->logger->redactor()->diff( $oldValue, $newValue, $key );
		}
		if ( [] === $changes && ! $always ) {
			return;
		}
		$context['meta_key'] = $key; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- context key, not a query.
		$severity            = $this->severityFor( $key );
		$technical           = $this->isTechnical( $key ) ? 'key_pattern' : $this->logger->context()->machineWriteReason();
		if ( null !== $technical ) {
			$severity                    = Severity::DEBUG;
			$context['technical']        = true;
			$context['technical_reason'] = $technical;
		}
		if ( 'post' === $type && $objectId > 0 && function_exists( 'get_post_type' ) ) {
			$context['post_type'] = (string) get_post_type( $objectId );
		}
		$this->emit(
			Event::make( Category::FIELDS, $action, $severity )
				->object( $type, $objectId > 0 ? $objectId : null, $key )
				->summary( sprintf( 'Meta "%s" %s on %s #%d', $key, $verb, $type, $objectId ) )
				->changes( $changes )
				->context( $context )
		);
	}

	/**
	 * Captured old values currently held (tests, diagnostics).
	 */
	public function tracked(): int {
		return count( $this->old ) + count( $this->consumed );
	}

	/**
	 * Keep an old value for a slot (FIFO-capped: when updated_*_meta never fires,
	 * e.g. the value did not change, the entry would otherwise live forever).
	 *
	 * @param string $slot  "type:id:key".
	 * @param mixed  $value Value.
	 */
	private function rememberOld( string $slot, mixed $value ): void {
		unset( $this->old[ $slot ] );
		$this->old[ $slot ] = $value;
		self::cap( $this->old );
	}

	/**
	 * Drop the oldest entries above MAX_TRACKED.
	 *
	 * @param array<string, mixed> $entries Entries (by reference).
	 */
	private static function cap( array &$entries ): void {
		$excess = count( $entries ) - self::MAX_TRACKED;
		if ( $excess > 0 ) {
			$entries = array_slice( $entries, $excess, null, true );
		}
	}

	/**
	 * Stored values for a key (unserialized), without the get_*_metadata filters.
	 *
	 * @param string $type     Meta type.
	 * @param int    $objectId Object id.
	 * @param string $key      Meta key.
	 * @return list<mixed>
	 */
	private static function read( string $type, int $objectId, string $key ): array {
		if ( $objectId <= 0 ) {
			return [];
		}
		if ( function_exists( 'get_metadata_raw' ) ) {
			$values = get_metadata_raw( $type, $objectId, $key, false );
		} elseif ( function_exists( 'get_metadata' ) ) {
			$values = get_metadata( $type, $objectId, $key, false );
		} else {
			return [];
		}
		return is_array( $values ) ? array_values( $values ) : [];
	}

	/**
	 * One value for a single row, the list for several, null for none.
	 *
	 * @param list<mixed> $values Values.
	 */
	private static function single( array $values ): mixed {
		$count = count( $values );
		if ( 0 === $count ) {
			return null;
		}
		return 1 === $count ? $values[0] : $values;
	}

	/**
	 * Extra ignore list (filter: noformat_logger/meta_listener_owned_keys).
	 *
	 * @return list<string>
	 */
	private function ownedKeys(): array {
		if ( null === $this->owned ) {
			$this->owned = self::filteredList( 'noformat_logger/meta_listener_owned_keys', self::OWNED_KEYS );
		}
		return $this->owned;
	}

	/**
	 * Meaningful internal keys (filter: noformat_logger/meta_listener_meaningful_keys).
	 *
	 * @return list<string>
	 */
	private function meaningfulKeys(): array {
		if ( null === $this->meaningful ) {
			$this->meaningful = self::filteredList( 'noformat_logger/meta_listener_meaningful_keys', self::MEANINGFUL_KEYS );
		}
		return $this->meaningful;
	}
}
