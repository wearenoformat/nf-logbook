<?php
/**
 * AcfListener.
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
 * ACF: acf/save_post at priority 1 (snapshot) and 20 (diff), option pages, field groups (PLAN §4).
 *
 * One acf/save_post yields one acf.fields_saved (or acf.options_saved) event
 * with a change per field name. While a save runs, MetaListener (and, by
 * request, OptionListener) ask isSaving() and skip the per-key meta writes.
 */
final class AcfListener implements Listener {

	use Hooks;

	/**
	 * ACF objects currently inside acf/save_post ("type:id" => true).
	 *
	 * @var array<string, true>
	 */
	private static array $saving = [];

	/**
	 * Raw values before the save, by ACF id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $snapshots = [];

	/**
	 * Availability: ACF loaded. Plugin re-checks unavailable listeners on
	 * plugins_loaded (priority 0), so ACF loading after this plugin is covered.
	 */
	public static function isAvailable(): bool {
		return class_exists( 'ACF', false ) || function_exists( 'acf_get_field_groups' ) || function_exists( 'get_fields' );
	}

	/**
	 * Add hooks (callbacks check that ACF functions exist before using them).
	 *
	 * @param Logger $logger Logger.
	 * @param Config $config Config.
	 */
	public function register( Logger $logger, Config $config ): void {
		$this->bind( $logger, $config );
		$this->onAction( 'acf/save_post', 'beforeSave', 1, 1 );
		$this->onAction( 'acf/save_post', 'afterSave', 20, 1 );
		$this->onAction( 'acf/update_field_group', fn( $group ) => $this->groupChanged( 'updated', $group ), 10, 1 );
		$this->onAction( 'acf/duplicate_field_group', fn( $group ) => $this->groupChanged( 'duplicated', $group ), 10, 1 );
		$this->onAction( 'acf/trash_field_group', fn( $group ) => $this->groupChanged( 'trashed', $group ), 10, 1 );
		$this->onAction( 'acf/untrash_field_group', fn( $group ) => $this->groupChanged( 'restored', $group ), 10, 1 );
		$this->onAction( 'acf/delete_field_group', fn( $group ) => $this->groupChanged( 'deleted', $group ), 10, 1 );
	}

	/**
	 * Whether ACF is saving this object right now (meta/option writes belong to the grouped event).
	 *
	 * @param string     $type Object type: post, term, user, comment, options (or the ACF id with type "acf").
	 * @param int|string $id   Object id (for options: the option page post id, e.g. "options").
	 */
	public static function isSaving( string $type, int|string $id ): bool {
		if ( [] === self::$saving ) {
			return false;
		}
		if ( 'acf' === $type ) {
			$decoded = self::decodeId( $id );
			$type    = $decoded['type'];
			$id      = $decoded['id'];
		}
		return isset( self::$saving[ $type . ':' . $id ] );
	}

	/**
	 * Whether an option write belongs to a running ACF options page save.
	 *
	 * ACF stores option page fields as `{page}_{field}` and `_{page}_{field}`
	 * (page `options` by default, or a custom `post_id`); OptionListener skips
	 * those while the page is being saved, acf.options_saved covers them.
	 *
	 * @param string $option Option name.
	 */
	public static function isSavingOption( string $option ): bool {
		if ( [] === self::$saving ) {
			return false;
		}
		$name = ltrim( $option, '_' );
		foreach ( array_keys( self::$saving ) as $key ) {
			if ( ! str_starts_with( $key, 'options:' ) ) {
				continue;
			}
			$page = substr( $key, strlen( 'options:' ) );
			if ( '' !== $page && str_starts_with( $name, $page . '_' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Mark/unmark an object as being saved (public for tests and integrations).
	 *
	 * @param int|string $acfId  ACF post id (12, "term_3", "user_2", "options").
	 * @param bool       $saving Saving.
	 */
	public static function markSaving( int|string $acfId, bool $saving ): void {
		$decoded = self::decodeId( $acfId );
		$key     = $decoded['type'] . ':' . $decoded['id'];
		if ( $saving ) {
			self::$saving[ $key ] = true;
		} else {
			unset( self::$saving[ $key ] );
		}
	}

	/**
	 * Decode an ACF post id into a logger object reference.
	 *
	 * @param mixed $acfId ACF post id.
	 * @return array{type: string, id: int|string, acf_id: string}
	 */
	public static function decodeId( mixed $acfId ): array {
		$raw = is_scalar( $acfId ) ? (string) $acfId : '';
		if ( '' !== $raw && ctype_digit( $raw ) ) {
			return [
				'type'   => 'post',
				'id'     => (int) $raw,
				'acf_id' => $raw,
			];
		}
		if ( 1 === preg_match( '/^(term|user|comment|widget|block)_(.+)$/', $raw, $m ) ) {
			return [
				'type'   => $m[1],
				'id'     => ctype_digit( $m[2] ) ? (int) $m[2] : $m[2],
				'acf_id' => $raw,
			];
		}
		// Legacy "{taxonomy}_{term_id}" form.
		if ( 1 === preg_match( '/^(.+)_(\d+)$/', $raw, $m ) && function_exists( 'taxonomy_exists' ) && taxonomy_exists( $m[1] ) ) {
			return [
				'type'   => 'term',
				'id'     => (int) $m[2],
				'acf_id' => $raw,
			];
		}
		return [
			'type'   => 'options',
			'id'     => '' === $raw ? 'options' : ( 'option' === $raw ? 'options' : $raw ),
			'acf_id' => $raw,
		];
	}

	/**
	 * Priority 1 of acf/save_post: snapshot raw values, mark as saving.
	 *
	 * @param mixed $acfId ACF post id.
	 */
	public function beforeSave( mixed $acfId ): void {
		if ( ! is_scalar( $acfId ) ) {
			return;
		}
		$key = (string) $acfId;
		self::markSaving( $key, true );
		if ( ! isset( $this->snapshots[ $key ] ) ) {
			$this->snapshots[ $key ] = self::readFields( $acfId );
		}
	}

	/**
	 * Priority 20 of acf/save_post: diff and emit one grouped event.
	 *
	 * @param mixed $acfId ACF post id.
	 */
	public function afterSave( mixed $acfId ): void {
		if ( ! is_scalar( $acfId ) ) {
			return;
		}
		$key = (string) $acfId;
		try {
			if ( ! isset( $this->snapshots[ $key ] ) ) {
				return;
			}
			$oldValue = $this->snapshots[ $key ];
			unset( $this->snapshots[ $key ] );
			$this->emitSave( $acfId, $oldValue, self::readFields( $acfId ) );
		} finally {
			self::markSaving( $key, false );
		}
	}

	/**
	 * Build and queue the save event (no event when nothing changed).
	 *
	 * @param mixed                $acfId ACF post id.
	 * @param array<string, mixed> $oldValue   Values before.
	 * @param array<string, mixed> $newValue   Values after.
	 */
	public function emitSave( mixed $acfId, array $oldValue, array $newValue ): void {
		if ( null === $this->logger ) {
			return;
		}
		$decoded = self::decodeId( $acfId );
		$changes = $this->changes( $decoded, $oldValue, $newValue );
		if ( [] === $changes ) {
			return;
		}
		$fields = [];
		foreach ( $changes as $change ) {
			$fields[ explode( '.', $change['field'] )[0] ] = true;
		}
		$context = [
			'acf_id'         => $decoded['acf_id'],
			'fields_changed' => count( $fields ),
		];
		if ( 'options' === $decoded['type'] ) {
			$event = Event::make( Category::FIELDS, 'acf.options_saved', Severity::NOTICE )
				->object( 'options', $decoded['id'] )
				->summary( sprintf( 'ACF options page "%s" saved: %d field(s) changed', $decoded['id'], count( $fields ) ) );
		} else {
			$subtype = null;
			if ( 'post' === $decoded['type'] && function_exists( 'get_post_type' ) ) {
				$subtype = (string) get_post_type( (int) $decoded['id'] );
			}
			$event = Event::make( Category::FIELDS, 'acf.fields_saved', Severity::INFO )
				->object( $decoded['type'], $decoded['id'], '' === $subtype ? null : $subtype )
				->summary( sprintf( 'ACF fields saved on %s #%s: %d field(s) changed', $decoded['type'], $decoded['id'], count( $fields ) ) );
		}
		$this->emit( $event->changes( $changes )->context( $context ) );
	}

	/**
	 * Per-field changes: a content summary per top-level field on users,
	 * comments and non-viewable post types (PersonalValues); a dotted diff for
	 * terms, viewable post types, options pages and user preference fields.
	 *
	 * @param array{type: string, id: int|string, acf_id: string} $decoded  Decoded ACF id.
	 * @param array<string, mixed>                                $oldValue Values before.
	 * @param array<string, mixed>                                $newValue Values after.
	 * @return list<array{field: string, old: mixed, new: mixed}>
	 */
	private function changes( array $decoded, array $oldValue, array $newValue ): array {
		if ( null === $this->logger ) {
			return [];
		}
		$redactor = $this->logger->redactor();
		$plainOld = [];
		$plainNew = [];
		$personal = [];
		foreach ( array_keys( $oldValue + $newValue ) as $field ) {
			if ( PersonalValues::isPersonal( $decoded['type'], $decoded['id'], (string) $field ) ) {
				$change = PersonalValues::change( $redactor, (string) $field, $oldValue[ $field ] ?? null, $newValue[ $field ] ?? null );
				if ( null !== $change ) {
					$personal[] = $change;
				}
				continue;
			}
			if ( array_key_exists( $field, $oldValue ) ) {
				$plainOld[ $field ] = $oldValue[ $field ];
			}
			if ( array_key_exists( $field, $newValue ) ) {
				$plainNew[ $field ] = $newValue[ $field ];
			}
		}
		$changes = [] === $plainOld && [] === $plainNew ? [] : $redactor->diff( $plainOld, $plainNew );
		array_push( $changes, ...$personal );
		return $changes;
	}

	/**
	 * Field group saved / trashed / restored / deleted / duplicated.
	 *
	 * @param string $operation Operation.
	 * @param mixed  $group     Field group array.
	 */
	public function groupChanged( string $operation, mixed $group ): void {
		if ( ! is_array( $group ) ) {
			return;
		}
		$id    = self::intId( $group['ID'] ?? 0 );
		$key   = is_string( $group['key'] ?? null ) ? $group['key'] : '';
		$count = null;
		if ( 'deleted' !== $operation && function_exists( 'acf_get_fields' ) ) {
			$fields = acf_get_fields( $group );
			$count  = is_array( $fields ) ? count( $fields ) : null;
		}
		$this->emit(
			Event::make( Category::FIELDS, 'acf.field_group_changed', Severity::NOTICE )
				->object( 'post', $id > 0 ? $id : ( '' === $key ? null : $key ), 'acf-field-group', $this->hash8( $group['title'] ?? '' ) )
				->summary( sprintf( 'ACF field group %s %s', '' === $key ? '#' . $id : $key, $operation ) )
				->context(
					[
						'field_group'  => $key,
						'operation'    => $operation,
						'fields_count' => $count,
						'active'       => isset( $group['active'] ) ? (bool) $group['active'] : null,
					]
				)
				->fingerprint( 'acf.field_group_changed', '' === $key ? (string) $id : $key, $operation, $this->requestId() )
		);
	}

	/**
	 * Raw (unformatted) field values keyed by field name.
	 *
	 * @param mixed $acfId ACF post id.
	 * @return array<string, mixed>
	 */
	private static function readFields( mixed $acfId ): array {
		if ( ! function_exists( 'get_fields' ) ) {
			return [];
		}
		$fields = get_fields( $acfId, false );
		return is_array( $fields ) ? $fields : [];
	}
}
