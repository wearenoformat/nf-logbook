<?php
/**
 * Render-time object and actor labels.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Admin;

use Brain\Monkey\Functions;
use Noformat\Logger\Admin\Resolver;
use Noformat\Logger\Tests\TestCase;

final class ResolverTest extends TestCase {

	/** @var list<string> */
	private array $caps = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();
		Functions\when( 'current_user_can' )->alias( fn( string $cap, mixed ...$args ): bool => in_array( $cap, $this->caps, true ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_post' )->alias(
			static fn( int $id ): ?object => 5 === $id ? (object) [
				'ID'         => 5,
				'post_title' => '<script>alert(1)</script> Hello',
			] : null
		);
		Functions\when( 'get_edit_post_link' )->alias( static fn( int $id ): string => 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit' );
		Functions\when( 'get_userdata' )->alias( static fn( int $id ): object|false => 3 === $id ? (object) [ 'display_name' => 'Jane <b>Doe</b>' ] : false );
		Functions\when( 'get_edit_user_link' )->alias( static fn( int $id ): string => 'https://example.test/wp-admin/user-edit.php?user_id=' . $id );
		Functions\when( 'get_term' )->alias( static fn( int $id ): ?object => 9 === $id ? (object) [ 'name' => 'News & Notes' ] : null );
		// Render-time resolution must never write anything.
		foreach ( [ 'update_option', 'add_option', 'delete_option', 'update_post_meta', 'add_post_meta', 'update_user_meta', 'update_term_meta', 'set_transient', 'set_site_transient', 'wp_insert_post', 'wp_update_post' ] as $writer ) {
			Functions\expect( $writer )->never();
		}
	}

	/**
	 * Row fixture.
	 *
	 * @param array<string, mixed> $fields Fields.
	 * @return array<string, mixed>
	 */
	private static function row( array $fields ): array {
		return array_merge(
			[
				'object_type'    => null,
				'object_id'      => null,
				'object_subtype' => null,
				'actor_type'     => 'system',
				'actor_id'       => null,
				'actor_role'     => null,
			],
			$fields
		);
	}

	public function test_post_title_with_edit_link_only_when_editable(): void {
		$resolver = new Resolver();
		$row      = self::row(
			[
				'object_type'    => 'post',
				'object_id'      => '5',
				'object_subtype' => 'page',
			]
		);
		$this->caps = [];
		$plain      = $resolver->object( $row );
		self::assertStringContainsString( 'post (page)', $plain );
		self::assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt; Hello', $plain );
		self::assertStringNotContainsString( '<script>', $plain );
		self::assertStringNotContainsString( '<a ', $plain );

		$this->caps = [ 'edit_post' ];
		$linked     = $resolver->object( $row );
		self::assertMatchesRegularExpression( '#<a href="https://example.test/wp-admin/post.php\?post=5&(amp;)?action=edit">&lt;script&gt;alert\(1\)&lt;/script&gt; Hello</a>#', $linked );

		$gone = $resolver->object( self::row( [ 'object_type' => 'post', 'object_id' => '6' ] ) );
		self::assertStringContainsString( '#6', $gone );
		self::assertStringContainsString( 'deleted', $gone );
	}

	public function test_user_names_need_list_users(): void {
		$this->caps = [];
		$resolver   = new Resolver();
		$actor      = $resolver->actor(
			self::row(
				[
					'actor_type' => 'user',
					'actor_id'   => '3',
					'actor_role' => 'editor',
				]
			)
		);
		self::assertStringContainsString( 'user #3', $actor );
		self::assertStringNotContainsString( 'Jane', $actor );

		$this->caps = [ 'list_users' ];
		$resolver   = new Resolver();
		$actor      = $resolver->actor(
			self::row(
				[
					'actor_type' => 'user',
					'actor_id'   => '3',
					'actor_role' => 'editor',
				]
			)
		);
		self::assertStringContainsString( '<a href="https://example.test/wp-admin/user-edit.php?user_id=3">Jane &lt;b&gt;Doe&lt;/b&gt;</a>', $actor );
		self::assertStringContainsString( 'editor', $actor );
		$object = $resolver->object( self::row( [ 'object_type' => 'user', 'object_id' => '3' ] ) );
		self::assertStringContainsString( 'Jane &lt;b&gt;Doe&lt;/b&gt;', $object );
	}

	public function test_words_terms_slugs_and_ids(): void {
		$resolver = new Resolver();
		foreach ( [ 'system', 'cron', 'cli', 'anonymous' ] as $type ) {
			self::assertSame( '<span class="nflog-actor-word">' . $type . '</span>', $resolver->actor( self::row( [ 'actor_type' => $type ] ) ) );
		}
		self::assertStringContainsString( 'News &amp; Notes', $resolver->object( self::row( [ 'object_type' => 'term', 'object_id' => '9', 'object_subtype' => 'category' ] ) ) );
		self::assertStringContainsString( '<code>akismet</code>', $resolver->object( self::row( [ 'object_type' => 'plugin', 'object_id' => 'akismet' ] ) ) );
		self::assertStringContainsString( '<code>twentytwentyfour</code>', $resolver->object( self::row( [ 'object_type' => 'theme', 'object_id' => 'twentytwentyfour' ] ) ) );
		self::assertStringContainsString( '#12', $resolver->object( self::row( [ 'object_type' => 'comment', 'object_id' => '12' ] ) ) );
		self::assertStringContainsString( '<code>&lt;img src=x&gt;</code>', $resolver->object( self::row( [ 'object_type' => 'option', 'object_id' => '<img src=x>' ] ) ) );
		self::assertStringContainsString( '—', $resolver->object( self::row( [] ) ) );
	}

	public function test_prime_warms_caches_without_storing(): void {
		$this->caps = [ 'list_users' ];
		Functions\expect( '_prime_post_caches' )->once()->with( [ 5, 8 ], false, false );
		Functions\expect( 'cache_users' )->once()->with( [ 3, 4 ] );
		( new Resolver() )->prime(
			[
				self::row( [ 'object_type' => 'post', 'object_id' => '5', 'actor_type' => 'user', 'actor_id' => '3' ] ),
				self::row( [ 'object_type' => 'attachment', 'object_id' => '8' ] ),
				self::row( [ 'object_type' => 'user', 'object_id' => '4', 'actor_type' => 'user', 'actor_id' => '3' ] ),
				self::row( [ 'object_type' => 'option', 'object_id' => 'blogname' ] ),
			]
		);
	}

	public function test_prime_skips_users_without_list_users(): void {
		$this->caps = [];
		Functions\expect( '_prime_post_caches' )->never();
		Functions\expect( 'cache_users' )->never();
		( new Resolver() )->prime( [ self::row( [ 'actor_type' => 'user', 'actor_id' => '3' ] ) ] );
	}
}
