<?php
/**
 * PersonalValues.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Listeners\Support;

use Noformat\Logger\Privacy\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Which stored values are personal, and how they are reported (CONTRACT §2).
 *
 * Values stored on user profiles, on comments and on posts of non-viewable
 * post types (form submissions, CRM contacts) are personal by default: a key
 * name says nothing about what a plugin keeps under it (`wpseo_title` holds a
 * display name, `your-message` a visitor's text). Such values are reported as
 * a content summary (salted hash + length), non-scalar values and values of
 * sensitive keys as a redaction marker; the text never leaves the site.
 * User preference keys (USER_STRUCTURAL_KEYS) keep full diffs; term meta and
 * meta of viewable post types are structural and keep full diffs too.
 */
final class PersonalValues {

	/**
	 * User meta that holds preferences, not personal data: diffed in clear.
	 *
	 * @var list<string>
	 */
	public const USER_STRUCTURAL_KEYS = [
		'locale',
		'rich_editing',
		'syntax_highlighting',
		'comment_shortcuts',
		'admin_color',
		'use_ssl',
		'show_admin_bar_front',
	];

	/**
	 * Whether the values of a key on an object must be summarised instead of diffed.
	 *
	 * @param string     $type     Object type: post, term, user, comment (ACF: also options, widget, block).
	 * @param int|string $objectId Object id (0 when unknown, e.g. delete for all objects).
	 * @param string     $key      Meta key or field name.
	 */
	public static function isPersonal( string $type, int|string $objectId, string $key ): bool {
		switch ( $type ) {
			case 'user':
				return ! in_array( $key, self::USER_STRUCTURAL_KEYS, true );
			case 'comment':
				return true;
			case 'post':
				return ! self::isViewablePost( $objectId );
			default:
				return false;
		}
	}

	/**
	 * Whether a post belongs to a publicly viewable post type (false when it cannot be determined).
	 *
	 * @param int|string $postId Post id.
	 */
	public static function isViewablePost( int|string $postId ): bool {
		$id = is_int( $postId ) ? $postId : ( ctype_digit( $postId ) ? (int) $postId : 0 );
		if ( $id <= 0 || ! function_exists( 'get_post_type' ) || ! function_exists( 'is_post_type_viewable' ) ) {
			return false;
		}
		$postType = get_post_type( $id );
		if ( ! is_string( $postType ) || '' === $postType ) {
			return false;
		}
		return (bool) is_post_type_viewable( $postType );
	}

	/**
	 * One summarised change, or null when the value did not change.
	 *
	 * @param Redactor $redactor Redactor.
	 * @param string   $field    Field name (meta key).
	 * @param mixed    $oldValue Old value (null: none).
	 * @param mixed    $newValue New value (null: none).
	 * @return array{field: string, old: mixed, new: mixed}|null
	 */
	public static function change( Redactor $redactor, string $field, mixed $oldValue, mixed $newValue ): ?array {
		$old = Redactor::maybeUnserialize( $oldValue );
		$new = Redactor::maybeUnserialize( $newValue );
		if ( self::same( $old, $new ) ) {
			return null;
		}
		return [
			'field' => $field,
			'old'   => self::summary( $redactor, $field, $old, null ),
			'new'   => self::summary( $redactor, $field, $new, $old ),
		];
	}

	/**
	 * Content summary (scalar) or redaction marker (non-scalar, sensitive key) for one side.
	 *
	 * @param Redactor $redactor Redactor.
	 * @param string   $field    Field name.
	 * @param mixed    $value    Unserialized value.
	 * @param mixed    $previous Unserialized previous value (for the length delta), null for none.
	 * @return array<string, mixed>|null
	 */
	public static function summary( Redactor $redactor, string $field, mixed $value, mixed $previous = null ): ?array {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_scalar( $value ) || ( '' !== $field && $redactor->isSensitiveKey( $field ) ) ) {
			return $redactor->marker( $value );
		}
		return $redactor->contentSummary( (string) $value, is_scalar( $previous ) ? (string) $previous : null );
	}

	/**
	 * Value equality as stored meta sees it ("5" equals 5).
	 *
	 * @param mixed $a Value.
	 * @param mixed $b Value.
	 */
	private static function same( mixed $a, mixed $b ): bool {
		if ( null === $a || null === $b ) {
			return $a === $b;
		}
		if ( is_scalar( $a ) && is_scalar( $b ) ) {
			return ( is_bool( $a ) || is_bool( $b ) ) ? $a === $b : (string) $a === (string) $b;
		}
		try {
			return serialize( $a ) === serialize( $b ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- comparison only, never stored.
		} catch ( \Throwable $e ) {
			return false; // Unserializable value (closure): treat as changed.
		}
	}
}
