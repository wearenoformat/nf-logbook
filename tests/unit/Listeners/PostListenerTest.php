<?php
/**
 * PostListener tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Listeners;

use Brain\Monkey\Functions;
use Noformat\Logger\Listeners\PostListener;
use Noformat\Logger\Severity;

final class PostListenerTest extends ListenerTestCase {

	/**
	 * Posts returned by get_post().
	 *
	 * @var array<int, object>
	 */
	private array $posts = [];

	private PostListener $listener;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_post' )->alias( fn( $id ) => $this->posts[ (int) $id ] ?? null );
		$this->listener = ( new PostListener() )->bind( $this->logger, $this->config );
	}

	public function test_status_mapping(): void {
		self::assertSame( 'post.published', PostListener::statusAction( 'draft', 'publish' )[0] ?? null );
		self::assertSame( 'post.published', PostListener::statusAction( 'new', 'publish' )[0] ?? null );
		self::assertSame( 'post.published', PostListener::statusAction( 'future', 'publish' )[0] ?? null );
		self::assertSame( Severity::NOTICE, PostListener::statusAction( 'publish', 'draft' )[1] ?? null );
		foreach ( [ 'draft', 'pending', 'private', 'future' ] as $to ) {
			self::assertSame( 'post.unpublished', PostListener::statusAction( 'publish', $to )[0] ?? null, $to );
		}
		self::assertSame( 'post.status_changed', PostListener::statusAction( 'draft', 'pending' )[0] ?? null );
		self::assertNull( PostListener::statusAction( 'new', 'draft' ), 'creation is post.created' );
		self::assertNull( PostListener::statusAction( 'auto-draft', 'draft' ) );
		self::assertNull( PostListener::statusAction( 'new', 'auto-draft' ) );
		self::assertNull( PostListener::statusAction( 'publish', 'trash' ), 'trash has its own hook' );
		self::assertNull( PostListener::statusAction( 'trash', 'draft' ) );
		self::assertNull( PostListener::statusAction( 'draft', 'draft' ) );
	}

	public function test_create_and_publish_in_one_request(): void {
		$post                = self::post( [ 'ID' => 5, 'post_title' => 'Secret launch', 'post_status' => 'publish', 'post_content' => 'Hello world' ] );
		$this->posts[5]      = $post;
		$this->listener->transition( 'publish', 'new', $post );
		$this->listener->afterInsert( 5, $post, false, null );
		$this->listener->flush();

		self::assertSame( [ 'post.created', 'post.published' ], $this->actions() );
		$created = $this->events[0];
		self::assertSame( 'post', $created->getObject()['type'] ?? null );
		self::assertSame( '5', $created->getObject()['id'] ?? null );
		self::assertSame( 'post', $created->getObject()['subtype'] ?? null );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{8}$/', (string) ( $created->getObject()['label_hash'] ?? '' ) );
		self::assertSame( 11, $created->getContext()['content']['length'] );
		self::assertSame( Severity::NOTICE, $this->events[1]->getSeverity() );
		self::assertStringNotContainsString( 'Secret launch', $this->json() );
		self::assertStringNotContainsString( 'Hello world', $this->json() );
	}

	public function test_gutenberg_first_save_from_auto_draft_is_created(): void {
		$this->posts[7] = self::post( [ 'ID' => 7, 'post_status' => 'auto-draft' ] );
		$this->listener->afterInsert( 7, $this->posts[7], false, null ); // post-new.php
		self::assertSame( [], $this->actions() );
		$this->listener->snapshot( 7 );
		$this->posts[7] = self::post( [ 'ID' => 7, 'post_status' => 'draft', 'post_title' => 'X' ] );
		$this->listener->transition( 'draft', 'auto-draft', $this->posts[7] );
		$this->listener->afterInsert( 7, $this->posts[7], true, null );
		$this->listener->flush();
		self::assertSame( [ 'post.created' ], $this->actions() );
	}

	public function test_one_update_event_per_post_and_request(): void {
		$before         = self::post( [ 'ID' => 9, 'post_title' => 'Old', 'post_name' => 'old', 'post_status' => 'publish', 'post_content' => 'aaa' ] );
		$this->posts[9] = $before;
		$this->listener->snapshot( 9 );
		$middle         = self::post( [ 'ID' => 9, 'post_title' => 'New', 'post_name' => 'old', 'post_status' => 'publish', 'post_content' => 'aaa' ] );
		$this->posts[9] = $middle;
		$this->listener->afterInsert( 9, $middle, true, $before );
		// A save_post callback saves again in the same request.
		$this->listener->snapshot( 9 );
		$after          = self::post( [ 'ID' => 9, 'post_title' => 'New', 'post_name' => 'new', 'post_status' => 'publish', 'post_content' => 'aaaa', 'menu_order' => 3 ] );
		$this->posts[9] = $after;
		$this->listener->afterInsert( 9, $after, true, $middle );
		$this->listener->flush();

		self::assertSame( [ 'post.updated' ], $this->actions() );
		$changes = [];
		foreach ( $this->events[0]->getChanges() as $change ) {
			$changes[ $change['field'] ] = $change;
		}
		self::assertSame( [ 'title', 'slug', 'menu_order', 'content' ], array_keys( $changes ) );
		self::assertSame( 'Old', $changes['title']['old'] );
		self::assertSame( 'new', $changes['slug']['new'] );
		self::assertSame( 1, $changes['content']['new']['length_delta'] );
		self::assertArrayHasKey( 'sha256_8', $changes['content']['old'] );
		self::assertStringContainsString( 'Post #9 (post) updated: title, slug, menu_order, content', $this->events[0]->getSummary() );
	}

	public function test_no_change_no_event_and_status_only_save(): void {
		$before          = self::post( [ 'ID' => 3, 'post_status' => 'publish' ] );
		$this->posts[3]  = $before;
		$this->listener->snapshot( 3 );
		$this->listener->afterInsert( 3, $before, true, $before );
		$this->listener->flush();
		self::assertSame( [], $this->actions() );

		$this->listener->snapshot( 3 );
		$after          = self::post( [ 'ID' => 3, 'post_status' => 'draft' ] );
		$this->posts[3] = $after;
		$this->listener->transition( 'draft', 'publish', $after );
		$this->listener->afterInsert( 3, $after, true, $before );
		$this->listener->flush();
		self::assertSame( [ 'post.unpublished' ], $this->actions() );
		self::assertSame( [ [ 'field' => 'status', 'old' => 'publish', 'new' => 'draft' ] ], $this->events[0]->getChanges() );
	}

	public function test_skipped_types_and_autosave_like_types(): void {
		foreach ( [ 'nav_menu_item', 'revision', 'acf-field-group', 'customize_changeset', 'attachment' ] as $i => $type ) {
			$post                  = self::post( [ 'ID' => 100 + $i, 'post_type' => $type ] );
			$this->posts[ 100 + $i ] = $post;
			$this->listener->transition( 'publish', 'draft', $post );
			$this->listener->afterInsert( 100 + $i, $post, false, null );
			$this->listener->beforeDelete( 100 + $i, $post );
			$this->listener->deleted( 100 + $i );
		}
		$this->listener->flush();
		self::assertSame( [], $this->actions() );
	}

	public function test_trash_logs_trashed_not_updated(): void {
		$before          = self::post( [ 'ID' => 4, 'post_status' => 'publish', 'post_name' => 'a' ] );
		$this->posts[4]  = $before;
		$this->listener->trashed( 4, 'publish' );
		$this->listener->snapshot( 4 );
		$after          = self::post( [ 'ID' => 4, 'post_status' => 'trash', 'post_name' => 'a__trashed' ] );
		$this->posts[4] = $after;
		$this->listener->transition( 'trash', 'publish', $after );
		$this->listener->afterInsert( 4, $after, true, $before );
		$this->listener->flush();
		self::assertSame( [ 'post.trashed' ], $this->actions() );
		self::assertSame( 'publish', $this->events[0]->getContext()['previous_status'] );
		self::assertSame( Severity::NOTICE, $this->events[0]->getSeverity() );
	}

	public function test_delete_requires_before_delete(): void {
		$this->listener->deleted( 11 );
		self::assertSame( [], $this->actions() );
		$this->listener->beforeDelete( 11, self::post( [ 'ID' => 11, 'post_type' => 'page', 'post_title' => 'About' ] ) );
		$this->listener->deleted( 11 );
		self::assertSame( [ 'post.deleted' ], $this->actions() );
		self::assertSame( 'page', $this->events[0]->getObject()['subtype'] ?? null );
	}

	public function test_full_content_diff_is_opt_in(): void {
		$this->useSettings( [ 'full_content_diff' => true ] );
		$listener = ( new PostListener() )->bind( $this->logger, $this->config );
		$changes  = $listener->diff( PostListener::state( self::post( [ 'post_content' => 'a' ] ) ), PostListener::state( self::post( [ 'post_content' => 'b' ] ) ) );
		self::assertSame( [ [ 'field' => 'content', 'old' => 'a', 'new' => 'b' ] ], $changes );
	}

	public function test_skip_list_is_filterable(): void {
		\Brain\Monkey\Filters\expectApplied( 'noformat_logger/post_listener_skip_types' )->once()->andReturn( [ 'product' ] );
		self::assertTrue( $this->listener->skips( 'product' ) );
		self::assertFalse( $this->listener->skips( 'nav_menu_item' ) );
	}

	public function test_titles_of_non_viewable_post_types_are_hashed(): void {
		Functions\when( 'is_post_type_viewable' )->alias( static fn( $type ) => 'flamingo_contact' !== $type );
		$listener        = ( new PostListener() )->bind( $this->logger, $this->config );
		$before          = self::post( [ 'ID' => 21, 'post_type' => 'flamingo_contact', 'post_title' => 'jane@example.com', 'post_name' => 'jane-example-com', 'post_status' => 'publish' ] );
		$this->posts[21] = $before;
		$listener->snapshot( 21 );
		$after           = self::post( [ 'ID' => 21, 'post_type' => 'flamingo_contact', 'post_title' => 'john@example.com', 'post_name' => 'john-example-com', 'post_status' => 'publish' ] );
		$this->posts[21] = $after;
		$listener->afterInsert( 21, $after, true, $before );
		$listener->flush();

		$changes = array_column( $this->events[0]->getChanges(), null, 'field' );
		self::assertTrue( $changes['title']['old']['redacted'] );
		self::assertTrue( $changes['slug']['new']['redacted'] );
		self::assertNotSame( $changes['title']['old']['sha256_8'], $changes['title']['new']['sha256_8'], 'a change stays visible' );
		self::assertStringNotContainsString( 'jane', $this->json() );
		self::assertStringNotContainsString( 'john', $this->json() );
		self::assertTrue( $listener->labelsInClear( 'page' ) );
		self::assertFalse( $listener->labelsInClear( 'flamingo_contact' ) );
		self::assertFalse( $listener->labelsInClear( '' ) );
	}
}
