<?php
/**
 * Content log / System log category split.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Admin;

use Noformat\Logger\Category;

defined( 'ABSPATH' ) || exit;

/**
 * Which categories the Content log (site administrators and editors: who
 * changed what) and the System log (developers: errors, security, HTTP, mail,
 * cron, deploys) show. Every category belongs to exactly one of the two: the
 * System log is everything the Content log does not show.
 *
 * Filter `noformat_logger/content_categories` (list of category values) moves
 * categories between the two; unknown values are ignored.
 */
final class Scope {

	public const CONTENT = 'content';
	public const SYSTEM  = 'system';

	/** Default Content log categories. */
	public const CONTENT_DEFAULT = [
		'content',
		'media',
		'taxonomy',
		'menu',
		'fields',
		'comment',
		'user',
		'settings',
		'theme',
		'plugin',
		'core',
		'commerce',
		'privacy',
	];

	/**
	 * Content log type chips: slug => [categories, object_subtype, exclude_object_subtype].
	 *
	 * "other" also takes any category moved into the Content log by the filter.
	 */
	public const TYPES = [
		'pages'      => [ [ 'content' ], 'page', null ],
		'posts'      => [ [ 'content' ], null, 'page' ],
		'media'      => [ [ 'media' ], null, null ],
		'menus'      => [ [ 'menu' ], null, null ],
		'fields'     => [ [ 'fields' ], null, null ],
		'users'      => [ [ 'user' ], null, null ],
		'comments'   => [ [ 'comment' ], null, null ],
		'settings'   => [ [ 'settings' ], null, null ],
		'extensions' => [ [ 'plugin', 'theme', 'core' ], null, null ],
		'other'      => [ [ 'taxonomy', 'commerce', 'privacy' ], null, null ],
	];

	/**
	 * Content log categories (after the filter), in catalogue order.
	 *
	 * @return list<string>
	 */
	public static function content(): array {
		$list = self::CONTENT_DEFAULT;
		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'noformat_logger/content_categories', $list );
			$list     = is_array( $filtered ) ? array_map( 'strval', array_filter( $filtered, 'is_scalar' ) ) : $list;
		}
		return array_values( array_intersect( Category::values(), $list ) );
	}

	/**
	 * System log categories: every category not in the Content log.
	 *
	 * @return list<string>
	 */
	public static function system(): array {
		return array_values( array_diff( Category::values(), self::content() ) );
	}

	/**
	 * Categories of a scope.
	 *
	 * @param string $scope Scope::CONTENT or Scope::SYSTEM.
	 * @return list<string>
	 */
	public static function categories( string $scope ): array {
		return self::SYSTEM === $scope ? self::system() : self::content();
	}

	/**
	 * Scope a category belongs to.
	 *
	 * @param string $category Category value.
	 */
	public static function of( string $category ): string {
		return in_array( $category, self::content(), true ) ? self::CONTENT : self::SYSTEM;
	}

	/**
	 * Repository filters for a Content log type chip (empty for unknown types).
	 *
	 * @param string $type Type slug.
	 * @return array<string, mixed>
	 */
	public static function typeFilters( string $type ): array {
		if ( ! isset( self::TYPES[ $type ] ) ) {
			return [];
		}
		[ $categories, $subtype, $exclude ] = self::TYPES[ $type ];
		$content                            = self::content();
		if ( 'other' === $type ) {
			$known = [];
			foreach ( self::TYPES as $slug => $definition ) {
				if ( 'other' !== $slug ) {
					$known = array_merge( $known, $definition[0] );
				}
			}
			$categories = array_merge( $categories, array_diff( $content, $known ) );
		}
		$categories = array_values( array_intersect( $categories, $content ) );
		$filters    = [ 'categories' => [] === $categories ? [ '__none__' ] : $categories ];
		if ( null !== $subtype ) {
			$filters['object_subtype'] = $subtype;
		}
		if ( null !== $exclude ) {
			$filters['exclude_object_subtype'] = $exclude;
		}
		return $filters;
	}
}
