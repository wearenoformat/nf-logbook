<?php
/**
 * Content log / System log split.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Admin;

use Brain\Monkey\Filters;
use Noformat\Logger\Admin\Scope;
use Noformat\Logger\Category;
use Noformat\Logger\Tests\TestCase;

final class ScopeTest extends TestCase {

	public function test_every_category_belongs_to_exactly_one_scope(): void {
		$content = Scope::content();
		$system  = Scope::system();
		self::assertSame( [ 'content', 'media', 'taxonomy', 'menu', 'fields', 'user', 'comment', 'settings', 'theme', 'plugin', 'core', 'commerce', 'privacy' ], $content );
		self::assertSame( [ 'security', 'error', 'stability', 'mail', 'http', 'cron', 'build', 'deploy', 'system' ], $system );
		self::assertSame( [], array_intersect( $content, $system ) );
		self::assertEqualsCanonicalizing( Category::values(), array_merge( $content, $system ) );
		self::assertSame( Scope::SYSTEM, Scope::of( 'security' ) );
		self::assertSame( Scope::CONTENT, Scope::of( 'menu' ) );
	}

	public function test_filter_moves_categories_and_ignores_unknown_values(): void {
		Filters\expectApplied( 'noformat_logger/content_categories' )->andReturnUsing(
			static fn( array $list ): array => array_merge( array_diff( $list, [ 'core' ] ), [ 'security', 'nonsense' ] )
		);
		self::assertContains( 'security', Scope::content() );
		self::assertNotContains( 'core', Scope::content() );
		self::assertNotContains( 'nonsense', Scope::content() );
		self::assertContains( 'core', Scope::system() );
	}

	public function test_type_filters(): void {
		self::assertSame(
			[
				'categories'     => [ 'content' ],
				'object_subtype' => 'page',
			],
			Scope::typeFilters( 'pages' )
		);
		self::assertSame( 'page', Scope::typeFilters( 'posts' )['exclude_object_subtype'] );
		self::assertSame( [ 'plugin', 'theme', 'core' ], Scope::typeFilters( 'extensions' )['categories'] );
		self::assertSame( [], Scope::typeFilters( 'bogus' ) );
	}
}
