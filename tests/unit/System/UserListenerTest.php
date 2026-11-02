<?php
/**
 * UserListener: role event folding and elevation criticality.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\System;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Noformat\Logger\Config;
use Noformat\Logger\Event;
use Noformat\Logger\Listeners\UserListener;
use Noformat\Logger\Severity;
use Noformat\Logger\Tests\TestCase;

final class UserListenerTest extends TestCase {

	/** @var list<Event> */
	private array $events = [];

	private UserListener $listener;

	protected function setUp(): void {
		parent::setUp();
		BurstDetectorTest::stubUserClass();
		$this->events = [];
		Filters\expectApplied( 'noformat_logger/event' )->zeroOrMoreTimes()->andReturnUsing(
			function ( Event $event ): Event {
				$this->events[] = $event;
				return $event;
			}
		);
		$this->listener = new UserListener();
		$this->listener->register( BurstDetectorTest::logger(), new Config() );
	}

	/**
	 * @return list<string>
	 */
	private function actions(): array {
		return array_map( static fn( Event $e ): string => $e->getAction(), $this->events );
	}

	public function test_set_role_yields_one_critical_role_changed(): void {
		// Order WP_User::set_role() fires its hooks in.
		$this->listener->onRemoveRole( 5, 'editor' );
		$this->listener->onAddRole( 5, 'administrator' );
		$this->listener->onSetRole( 5, 'administrator', [ 'editor' ] );
		self::assertSame( [], $this->events, 'deferred until shutdown' );
		$this->listener->flushPending();
		self::assertSame( [ 'user.role_changed' ], $this->actions() );
		$event = $this->events[0];
		self::assertSame( Severity::CRITICAL, $event->getSeverity() );
		self::assertSame( 'User #5 role changed from editor to administrator', $event->getSummary() );
		self::assertSame( 'role', $event->getChanges()[0]['field'] );
		self::assertSame( 'administrator', $event->getChanges()[0]['new'] );
	}

	public function test_demotion_is_notice(): void {
		$this->listener->onSetRole( 5, 'subscriber', [ 'administrator' ] );
		$this->listener->flushPending();
		self::assertSame( Severity::NOTICE, $this->events[0]->getSeverity() );
	}

	public function test_add_role_alone_is_logged(): void {
		$this->listener->onAddRole( 8, 'administrator' );
		$this->listener->flushPending();
		self::assertSame( [ 'user.role_added' ], $this->actions() );
		self::assertSame( Severity::CRITICAL, $this->events[0]->getSeverity() );
	}

	public function test_role_set_during_creation_is_folded_into_created(): void {
		$user        = new \WP_User();
		$user->ID    = 7;
		$user->roles = [ 'administrator' ];
		Functions\when( 'get_userdata' )->justReturn( $user );
		$this->listener->onSetRole( 7, 'administrator', [] );
		$this->listener->onRegister( 7 );
		$this->listener->flushPending();
		self::assertSame( [ 'user.created' ], $this->actions() );
		self::assertSame( Severity::CRITICAL, $this->events[0]->getSeverity(), 'new administrator' );
		self::assertSame( 'User #7 created with role administrator', $this->events[0]->getSummary() );
	}

	public function test_profile_update_lists_field_names(): void {
		$old       = new \WP_User();
		$old->ID   = 3;
		$old->data = (object) [
			'user_email'   => 'old@example.com',
			'display_name' => 'Old Name',
			'user_pass'    => 'h1',
		];
		$new        = new \WP_User();
		$new->ID    = 3;
		$new->roles = [ 'editor' ];
		$new->data  = (object) [
			'user_email'   => 'new@example.com',
			'display_name' => 'Old Name',
			'user_pass'    => 'h2',
		];
		Functions\when( 'get_userdata' )->justReturn( $new );
		$this->listener->onProfileUpdate( 3, $old );
		self::assertSame( [ 'user.updated' ], $this->actions() );
		self::assertSame( [ 'user_pass', 'user_email' ], $this->events[0]->getContext()['changed_fields'] );
		$json = (string) json_encode( $this->events[0]->toArray() );
		self::assertStringNotContainsString( 'example.com', $json );
		self::assertStringNotContainsString( 'Old Name', $json );
	}

	public function test_app_password_name_is_hashed(): void {
		$this->listener->onAppPasswordCreated(
			4,
			[
				'uuid'   => '6f1c-uuid',
				'app_id' => '',
				'name'   => 'Janes laptop',
			]
		);
		self::assertSame( [ 'user.app_password_created' ], $this->actions() );
		$context = $this->events[0]->getContext();
		self::assertSame( '6f1c-uuid', $context['uuid'] );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{8}$/', (string) $context['name_hash'] );
		self::assertStringNotContainsString( 'laptop', (string) json_encode( $this->events[0]->toArray() ) );
	}
}
