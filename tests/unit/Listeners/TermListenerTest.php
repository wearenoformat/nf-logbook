<?php
/**
 * TermListener tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Listeners;

use Brain\Monkey\Functions;
use Noformat\Logger\Listeners\TermListener;

final class TermListenerTest extends ListenerTestCase {

	private TermListener $listener;

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_taxonomy' )->alias(
			static fn( $tax ) => (object) [
				'public'      => 'genre' === $tax,
				'object_type' => [ 'post' ],
			]
		);
		Functions\when( 'post_type_exists' )->justReturn( true );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		$this->listener = ( new TermListener() )->bind( $this->logger, $this->config );
	}

	public function test_assignment_diff(): void {
		self::assertSame( [ 'added' => [ 5 ], 'removed' => [ 2 ] ], TermListener::assignmentDiff( [ 1, '5' ], [ 1, 2 ], false ) );
		self::assertSame( [ 'added' => [ 5 ], 'removed' => [] ], TermListener::assignmentDiff( [ 5 ], [ 1, 2 ], true ) );
		self::assertSame( [ 'added' => [], 'removed' => [] ], TermListener::assignmentDiff( [ 2, 1 ], [ 1, 2 ], false ) );
	}

	public function test_assigned_emits_term_ids_and_skips_noop_and_menus(): void {
		Functions\when( 'get_terms' )->justReturn(
			[
				(object) [ 'term_taxonomy_id' => 50, 'term_id' => 5 ],
				(object) [ 'term_taxonomy_id' => 20, 'term_id' => 2 ],
			]
		);
		$this->listener->assigned( 9, [ 'x' ], [ 1 ], 'category', false, [ 1 ] );
		$this->listener->assigned( 9, [ 'x' ], [ 1 ], 'nav_menu', false, [] );
		self::assertSame( [], $this->actions() );
		$this->listener->assigned( 9, [ 'x' ], [ 1, 50 ], 'category', false, [ 1, 20 ] );
		self::assertSame( [ 'term.assigned' ], $this->actions() );
		$event = $this->events[0];
		self::assertSame( [ 5 ], $event->getContext()['added'] );
		self::assertSame( [ 2 ], $event->getContext()['removed'] );
		self::assertSame( 'category', $event->getObject()['subtype'] ?? null );
		self::assertSame( 'post', $event->getObject()['type'] ?? null );
	}

	public function test_names_clear_for_allowlisted_taxonomies_only(): void {
		self::assertSame( 'News', $this->listener->nameValue( 'category', 'News' ) );
		self::assertSame( 'Jazz', $this->listener->nameValue( 'genre', 'Jazz' ), 'public taxonomy' );
		$hidden = $this->listener->nameValue( 'patient_group', 'Jane Doe' );
		self::assertIsArray( $hidden );
		self::assertTrue( $hidden['redacted'] );
	}

	public function test_update_diff(): void {
		$changes = $this->listener->diff(
			'patient_group',
			[ 'name' => 'A', 'slug' => 'a', 'parent_id' => 0, 'description' => '' ],
			[ 'name' => 'B', 'slug' => 'b', 'parent_id' => 3, 'description' => 'long text' ]
		);
		self::assertSame( [ 'term_name', 'slug', 'parent_id', 'description' ], array_column( $changes, 'field' ) );
		self::assertTrue( $changes[0]['new']['redacted'] );
		self::assertSame( 9, $changes[3]['new']['length'] );
	}

	public function test_removals_not_double_logged_and_deletions_ignored(): void {
		Functions\when( 'get_terms' )->justReturn( [] );
		// wp_set_object_terms() removes through wp_remove_object_terms() first: one event.
		$this->listener->relationshipsDeleted( 9, [ 20 ], 'category' );
		$this->listener->assigned( 9, [], [ 1 ], 'category', false, [ 1, 20 ] );
		$this->listener->flush();
		self::assertSame( [ 'term.assigned' ], $this->actions() );

		// wp_remove_object_terms() alone: emitted on flush while the post exists.
		Functions\when( 'get_post' )->alias( static fn( $id ) => 9 === (int) $id ? (object) [ 'ID' => 9 ] : null );
		$this->listener->relationshipsDeleted( 9, [ 20 ], 'category' );
		$this->listener->relationshipsDeleted( 10, [ 20 ], 'category' ); // Post 10 was deleted.
		$this->listener->flush();
		self::assertSame( [ 'term.assigned', 'term.assigned' ], $this->actions() );
		self::assertSame( [ 20 ], $this->events[1]->getContext()['removed'] );

		// Term deletion reassigns objects: no per-object events.
		$this->listener->deletingTerm( true );
		$this->listener->relationshipsDeleted( 9, [ 20 ], 'category' );
		$this->listener->assigned( 9, [], [ 1 ], 'category', false, [ 1, 20 ] );
		$this->listener->deletingTerm( false );
		$this->listener->flush();
		self::assertCount( 2, $this->events );
	}
}
