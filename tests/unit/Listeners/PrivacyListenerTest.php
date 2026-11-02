<?php
/**
 * PrivacyListener tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Listeners;

use Brain\Monkey\Functions;
use Noformat\Logger\Listeners\PrivacyListener;

final class PrivacyListenerTest extends ListenerTestCase {

	public function test_personal_data_export_references_user_id_only(): void {
		Functions\when( 'wp_get_user_request' )->justReturn(
			(object) [
				'user_id'     => 6,
				'email'       => 'jane@example.org',
				'action_name' => 'export_personal_data',
			]
		);
		$listener = ( new PrivacyListener() )->bind( $this->logger, $this->config );
		$listener->personalData( 'privacy.personal_data_exported', 'exported', 99 );
		self::assertSame( [ 'privacy.personal_data_exported' ], $this->actions() );
		self::assertSame( [ 'type' => 'user', 'id' => '6', 'subtype' => null, 'label_hash' => null ], $this->events[0]->getObject() );
		self::assertSame( 99, $this->events[0]->getContext()['request_id'] );
		self::assertStringNotContainsString( 'jane@example.org', $this->json() );
	}

	public function test_request_without_account(): void {
		Functions\when( 'wp_get_user_request' )->justReturn( (object) [ 'user_id' => 0, 'action_name' => 'remove_personal_data' ] );
		$listener = ( new PrivacyListener() )->bind( $this->logger, $this->config );
		$listener->personalData( 'privacy.personal_data_erased', 'erased', 5 );
		self::assertSame( 'user_request', $this->events[0]->getObject()['type'] ?? null );
		self::assertFalse( $this->events[0]->getContext()['has_account'] );
	}

	public function test_export_started(): void {
		Functions\when( 'post_type_exists' )->justReturn( true );
		Functions\when( 'wp_count_posts' )->justReturn( (object) [ 'publish' => 3, 'draft' => 2 ] );
		$listener = ( new PrivacyListener() )->bind( $this->logger, $this->config );
		$listener->exportStarted( [ 'content' => 'page', 'author' => 3, 'status' => false ] );
		$context = $this->events[0]->getContext();
		self::assertSame( 'page', $context['content'] );
		self::assertSame( [ 'author' ], $context['filters'] );
		self::assertSame( 5, $context['posts_count'] );
	}
}
