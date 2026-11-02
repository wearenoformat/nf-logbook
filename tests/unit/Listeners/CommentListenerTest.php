<?php
/**
 * CommentListener tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Listeners;

use Brain\Monkey\Functions;
use Noformat\Logger\Listeners\CommentListener;

final class CommentListenerTest extends ListenerTestCase {

	private static function comment( array $fields = [] ): object {
		return (object) array_merge(
			[
				'comment_ID'           => '15',
				'comment_post_ID'      => '3',
				'comment_parent'       => '0',
				'comment_type'         => 'comment',
				'comment_approved'     => '0',
				'comment_author'       => 'Jane Doe',
				'comment_author_email' => 'jane@example.org',
				'comment_author_url'   => 'https://jane.example.org',
				'comment_author_IP'    => '203.0.113.9',
				'comment_agent'        => 'Mozilla/5.0 JaneBrowser',
				'comment_content'      => 'Call me at 555-1234',
				'comment_date'         => '2026-10-28 10:00:00',
				'comment_karma'        => '0',
				'user_id'              => '0',
			],
			$fields
		);
	}

	public function test_status_names(): void {
		self::assertSame( 'approved', CommentListener::statusName( '1' ) );
		self::assertSame( 'unapproved', CommentListener::statusName( 0 ) );
		self::assertSame( 'unapproved', CommentListener::statusName( 'hold' ) );
		self::assertSame( 'spam', CommentListener::statusName( 'spam' ) );
		self::assertSame( 'trash', CommentListener::statusName( 'trash' ) );
	}

	public function test_created_never_copies_author_fields(): void {
		$listener = ( new CommentListener() )->bind( $this->logger, $this->config );
		$listener->created( 15, self::comment() );
		self::assertSame( [ 'comment.created' ], $this->actions() );
		$context = $this->events[0]->getContext();
		self::assertSame( 3, $context['post_id'] );
		self::assertSame( 'unapproved', $context['status'] );
		self::assertSame( 19, $context['content']['length'] );
		$json = $this->json();
		foreach ( [ 'Jane', 'jane@example.org', '203.0.113.9', 'JaneBrowser', '555-1234' ] as $needle ) {
			self::assertStringNotContainsString( $needle, $json );
		}
	}

	public function test_spam_logged_once_and_delete_transition_ignored(): void {
		$listener = ( new CommentListener() )->bind( $this->logger, $this->config );
		$listener->transition( 'spam', 'unapproved', self::comment() );
		$listener->transition( 'delete', 'spam', self::comment() );
		$listener->transition( 'approved', 'approved', self::comment() );
		self::assertSame( [ 'comment.spammed' ], $this->actions() );
		self::assertSame( [ [ 'field' => 'status', 'old' => 'unapproved', 'new' => 'spam' ] ], $this->events[0]->getChanges() );
	}

	public function test_new_spam_is_aggregated_per_post(): void {
		$listener = ( new CommentListener() )->bind( $this->logger, $this->config );
		$listener->created( 20, self::comment( [ 'comment_ID' => '20', 'comment_approved' => 'spam' ] ) );
		$listener->created( 21, self::comment( [ 'comment_ID' => '21', 'comment_approved' => 'spam' ] ) );
		self::assertSame( 1, $this->logger->pending() );
	}

	public function test_edit_reports_author_field_names_only(): void {
		$old = (array) self::comment();
		Functions\when( 'get_comment' )->justReturn( self::comment( [ 'comment_author_email' => 'new@example.org', 'comment_content' => 'Edited' ] ) );
		$listener = ( new CommentListener() )->bind( $this->logger, $this->config );
		$listener->snapshot( [], $old );
		$listener->edited( 15 );
		self::assertSame( [ 'comment.updated' ], $this->actions() );
		self::assertSame( [ 'content' ], array_column( $this->events[0]->getChanges(), 'field' ) );
		self::assertSame( [ 'comment_author_email' ], $this->events[0]->getContext()['identity_fields_changed'] );
		self::assertStringNotContainsString( 'new@example.org', $this->json() );
	}
}
