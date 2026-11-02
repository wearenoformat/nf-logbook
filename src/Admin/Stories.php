<?php
/**
 * Content log stories.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Groups the events of one request (one save) into a story: one primary event
 * whose sentence is shown, and secondary events listed under "What changed".
 *
 * Primary by rank: content, user, menu, media, comment, settings, plugin,
 * theme and core actions outrank the side effects of a save (meta.*,
 * term.assigned, acf.fields_saved, media.metadata_updated); within equal rank
 * the most severe, then the earliest id. Pure: rows in, arrays out.
 */
final class Stories {

	/** Side-effect actions (exact). */
	public const SECONDARY_ACTIONS = [ 'term.assigned', 'acf.fields_saved', 'media.metadata_updated' ];

	/** Side-effect action prefixes. */
	public const SECONDARY_PREFIXES = [ 'meta.' ];

	/** User meta that is part of the profile form: merged into a user.updated sentence. */
	public const PROFILE_META = [
		'description',
		'nickname',
		'first_name',
		'last_name',
		'locale',
		'rich_editing',
		'syntax_highlighting',
		'admin_color',
		'comment_shortcuts',
		'show_admin_bar_front',
	];

	/**
	 * Whether an action is a side effect of a save (ranks below primary actions).
	 *
	 * @param string $action Action.
	 */
	public static function isSecondary( string $action ): bool {
		if ( in_array( $action, self::SECONDARY_ACTIONS, true ) ) {
			return true;
		}
		foreach ( self::SECONDARY_PREFIXES as $prefix ) {
			if ( str_starts_with( $action, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Index of the primary row.
	 *
	 * @param list<array<string, mixed>> $rows Rows of one story (non-empty).
	 */
	public static function primaryIndex( array $rows ): int {
		$best    = 0;
		$bestKey = null;
		foreach ( $rows as $i => $row ) {
			$key = [ self::isSecondary( (string) ( $row['action'] ?? '' ) ) ? 1 : 0, (int) ( $row['severity'] ?? 99 ), (int) ( $row['id'] ?? PHP_INT_MAX ) ];
			if ( null === $bestKey || $key < $bestKey ) {
				$best    = $i;
				$bestKey = $key;
			}
		}
		return $best;
	}

	/**
	 * Build a story from its rows.
	 *
	 * The primary row is returned as a render-time copy: for a user.updated the
	 * profile meta of the same user in the story is merged into
	 * context.changed_fields (those secondaries are "absorbed": listed in the
	 * panel, not counted in the suffix).
	 *
	 * @param list<array<string, mixed>> $rows   Rows of one request (non-empty).
	 * @param int|null                   $events Events in the story (rows may be capped).
	 * @return array{primary: array<string, mixed>, secondary: list<array<string, mixed>>, absorbed: list<int>, events: int}
	 */
	public static function build( array $rows, ?int $events = null ): array {
		$rows      = array_values( $rows );
		$index     = self::primaryIndex( $rows );
		$primary   = $rows[ $index ];
		$secondary = [];
		foreach ( $rows as $i => $row ) {
			if ( $i !== $index ) {
				$secondary[] = $row;
			}
		}
		$absorbed = [];
		if ( 'user.updated' === ( $primary['action'] ?? '' ) ) {
			$context = Humanizer::decode( $primary['context'] ?? null );
			$fields  = is_array( $context['changed_fields'] ?? null ) ? array_values( array_filter( $context['changed_fields'], 'is_string' ) ) : [];
			foreach ( $secondary as $row ) {
				$key = self::metaKey( $row );
				if ( str_starts_with( (string) ( $row['action'] ?? '' ), 'meta.' ) && 'user' === ( $row['object_type'] ?? '' ) && (string) ( $row['object_id'] ?? '' ) === (string) ( $primary['object_id'] ?? '' ) && in_array( $key, self::PROFILE_META, true ) ) {
					$absorbed[] = (int) ( $row['id'] ?? 0 );
					if ( ! in_array( $key, $fields, true ) ) {
						$fields[] = $key;
					}
				}
			}
			if ( [] !== $absorbed ) {
				$context['changed_fields'] = $fields;
				$primary['context']        = $context;
			}
		}
		return [
			'primary'   => $primary,
			'secondary' => $secondary,
			'absorbed'  => $absorbed,
			'events'    => max( count( $rows ), (int) $events ),
		];
	}

	/**
	 * Group rows (any order) by request id into stories, newest last event first.
	 *
	 * @param list<array<string, mixed>> $rows Rows.
	 * @return list<array{primary: array<string, mixed>, secondary: list<array<string, mixed>>, absorbed: list<int>, events: int}>
	 */
	public static function group( array $rows ): array {
		$groups = [];
		$latest = [];
		usort( $rows, static fn( array $a, array $b ): int => (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );
		foreach ( $rows as $row ) {
			$request          = (string) ( $row['request_id'] ?? '' );
			$key              = '' === $request ? '#' . (int) ( $row['id'] ?? 0 ) : $request;
			$groups[ $key ][] = $row;
			$latest[ $key ]   = max( $latest[ $key ] ?? [ 0, 0 ], [ (int) ( $row['occurred_ms'] ?? 0 ), (int) ( $row['id'] ?? 0 ) ] );
		}
		$keys = array_keys( $groups );
		usort( $keys, static fn( int|string $a, int|string $b ): int => $latest[ $b ] <=> $latest[ $a ] );
		return array_map( static fn( int|string $key ): array => self::build( $groups[ $key ] ), $keys );
	}

	/**
	 * Counted parts of the secondaries for the suffix: "5 fields", "1 category", "2 other changes".
	 *
	 * @param array{primary: array<string, mixed>, secondary: list<array<string, mixed>>, absorbed: list<int>, events: int} $story Story.
	 * @return list<string>
	 */
	public static function suffixParts( array $story ): array {
		$fields = [];
		$terms  = [];
		$files  = 0;
		$other  = 0;
		foreach ( $story['secondary'] as $row ) {
			if ( in_array( (int) ( $row['id'] ?? 0 ), $story['absorbed'], true ) ) {
				continue;
			}
			$action = (string) ( $row['action'] ?? '' );
			if ( str_starts_with( $action, 'meta.' ) ) {
				$fields[ (string) ( $row['object_type'] ?? '' ) . ':' . (string) ( $row['object_id'] ?? '' ) . ':' . self::metaKey( $row ) ] = 1;
			} elseif ( 'acf.fields_saved' === $action ) {
				$context                                      = Humanizer::decode( $row['context'] ?? null );
				$count                                        = isset( $context['fields_changed'] ) && is_numeric( $context['fields_changed'] ) ? max( 1, (int) $context['fields_changed'] ) : 1;
				$fields[ 'acf#' . (int) ( $row['id'] ?? 0 ) ] = $count;
			} elseif ( 'term.assigned' === $action ) {
				$context            = Humanizer::decode( $row['context'] ?? null );
				$taxonomy           = is_scalar( $row['object_subtype'] ?? null ) ? (string) $row['object_subtype'] : '';
				$count              = ( is_array( $context['added'] ?? null ) ? count( $context['added'] ) : 0 ) + ( is_array( $context['removed'] ?? null ) ? count( $context['removed'] ) : 0 );
				$terms[ $taxonomy ] = ( $terms[ $taxonomy ] ?? 0 ) + max( 1, $count );
			} elseif ( self::isFieldEdit( $story['primary'], $row ) ) {
				foreach ( self::editedFields( $row ) as $field ) {
					$fields[ 'post:' . (string) ( $row['object_id'] ?? '' ) . ':' . $field ] = 1;
				}
			} elseif ( 'media.metadata_updated' === $action ) {
				++$files;
			} else {
				++$other;
			}
		}
		$parts  = [];
		$nField = array_sum( $fields );
		if ( $nField > 0 ) {
			/* translators: %s: number of fields */
			$parts[] = sprintf( _n( '%s field', '%s fields', $nField, 'noformat-logger' ), number_format_i18n( $nField ) );
		}
		foreach ( $terms as $taxonomy => $count ) {
			/* translators: 1: number, 2: taxonomy name (category, categories, tag, tags, …) */
			$parts[] = sprintf( __( '%1$s %2$s', 'noformat-logger' ), number_format_i18n( $count ), Humanizer::taxonomyLabel( (string) $taxonomy, 1 !== $count ) );
		}
		if ( $files > 0 ) {
			/* translators: %s: number of files */
			$parts[] = sprintf( _n( '%s file', '%s files', $files, 'noformat-logger' ), number_format_i18n( $files ) );
		}
		if ( $other > 0 ) {
			/* translators: %s: number of other changes */
			$parts[] = sprintf( _n( '%s other change', '%s other changes', $other, 'noformat-logger' ), number_format_i18n( $other ) );
		}
		$missing = $story['events'] - 1 - count( $story['secondary'] );
		if ( $missing > 0 ) {
			/* translators: %s: number of changes not loaded */
			$parts[] = sprintf( _n( '%s more change', '%s more changes', $missing, 'noformat-logger' ), number_format_i18n( $missing ) );
		}
		return $parts;
	}

	/**
	 * The muted suffix: "and 5 fields changed", "and 2 fields, 1 category changed",
	 * "and 1 field, 1 other change" ('' without secondaries).
	 *
	 * @param array{primary: array<string, mixed>, secondary: list<array<string, mixed>>, absorbed: list<int>, events: int} $story Story.
	 */
	public static function suffix( array $story ): string {
		$parts = self::suffixParts( $story );
		if ( [] === $parts ) {
			return '';
		}
		$list = implode( _x( ', ', 'list separator', 'noformat-logger' ), $parts );
		return self::onlyThings( $story )
			/* translators: %s: list such as "5 fields" or "2 fields, 1 category" */
			? sprintf( __( 'and %s changed', 'noformat-logger' ), $list )
			/* translators: %s: list such as "2 fields, 1 other change" */
			: sprintf( __( 'and %s', 'noformat-logger' ), $list );
	}

	/**
	 * Whether the suffix counts only fields, terms and files (no "other change" / "more changes").
	 *
	 * @param array{primary: array<string, mixed>, secondary: list<array<string, mixed>>, absorbed: list<int>, events: int} $story Story.
	 */
	private static function onlyThings( array $story ): bool {
		if ( $story['events'] - 1 > count( $story['secondary'] ) ) {
			return false;
		}
		foreach ( $story['secondary'] as $row ) {
			if ( ! self::isSecondary( (string) ( $row['action'] ?? '' ) ) && ! self::isFieldEdit( $story['primary'], $row ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a secondary is a post.updated of the primary's own object
	 * (published and retitled in one save): counted as its fields.
	 *
	 * @param array<string, mixed> $primary Primary row.
	 * @param array<string, mixed> $row     Secondary row.
	 */
	public static function isFieldEdit( array $primary, array $row ): bool {
		return 'post.updated' === ( $row['action'] ?? '' )
			&& (string) ( $row['object_type'] ?? '' ) === (string) ( $primary['object_type'] ?? '' )
			&& (string) ( $row['object_id'] ?? '' ) === (string) ( $primary['object_id'] ?? '' );
	}

	/**
	 * Changed field names of a post.updated row, without the date bump (at least one entry).
	 *
	 * @param array<string, mixed> $row Row.
	 * @return list<string>
	 */
	public static function editedFields( array $row ): array {
		$fields = [];
		foreach ( Humanizer::decode( $row['changes'] ?? null ) as $change ) {
			if ( is_array( $change ) && is_string( $change['field'] ?? null ) && ! in_array( $change['field'], [ 'date', 'modified' ], true ) ) {
				$fields[] = $change['field'];
			}
		}
		return [] === $fields ? [ '#' . (int) ( $row['id'] ?? 0 ) ] : $fields;
	}

	/**
	 * Request ids worth a login lookup: an anonymous actor changed a user object.
	 *
	 * @param list<array<string, mixed>> $rows Rows.
	 * @return list<string>
	 */
	public static function loginCandidates( array $rows ): array {
		$out = [];
		foreach ( $rows as $row ) {
			if ( 'anonymous' === ( $row['actor_type'] ?? '' ) && 'user' === ( $row['object_type'] ?? '' ) && '' !== (string) ( $row['request_id'] ?? '' ) ) {
				$out[ (string) $row['request_id'] ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * Attribute anonymous user-object events to the user who logged in during
	 * the same request (wp_login writes meta before the current user is set).
	 * Render time only.
	 *
	 * @param list<array<string, mixed>> $rows   Rows.
	 * @param array<string, list<int>>   $logins Request id => user ids that logged in (EventRepository::loginsInRequests()).
	 * @return list<array<string, mixed>>
	 */
	public static function attributeLogins( array $rows, array $logins ): array {
		foreach ( $rows as $i => $row ) {
			$request = (string) ( $row['request_id'] ?? '' );
			$object  = Resolver::numericId( $row['object_id'] ?? null );
			if ( 'anonymous' === ( $row['actor_type'] ?? '' ) && 'user' === ( $row['object_type'] ?? '' ) && null !== $object && in_array( $object, $logins[ $request ] ?? [], true ) ) {
				$rows[ $i ]['actor_type'] = 'user';
				$rows[ $i ]['actor_id']   = (string) $object;
			}
		}
		return $rows;
	}

	/**
	 * Meta key of a meta row.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	public static function metaKey( array $row ): string {
		$context = Humanizer::decode( $row['context'] ?? null );
		$key     = $context['meta_key'] ?? ( $row['object_subtype'] ?? '' ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- context key, not a query.
		return is_scalar( $key ) ? (string) $key : '';
	}
}
