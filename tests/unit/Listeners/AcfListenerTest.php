<?php
/**
 * AcfListener tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Listeners;

use Brain\Monkey\Functions;
use Noformat\Logger\Listeners\AcfListener;
use Noformat\Logger\Severity;

final class AcfListenerTest extends ListenerTestCase {

	public function test_decode_id(): void {
		Functions\when( 'taxonomy_exists' )->alias( static fn( $t ) => 'category' === $t );
		self::assertSame( [ 'type' => 'post', 'id' => 12, 'acf_id' => '12' ], AcfListener::decodeId( 12 ) );
		self::assertSame( [ 'type' => 'term', 'id' => 3, 'acf_id' => 'term_3' ], AcfListener::decodeId( 'term_3' ) );
		self::assertSame( [ 'type' => 'user', 'id' => 2, 'acf_id' => 'user_2' ], AcfListener::decodeId( 'user_2' ) );
		self::assertSame( [ 'type' => 'comment', 'id' => 5, 'acf_id' => 'comment_5' ], AcfListener::decodeId( 'comment_5' ) );
		self::assertSame( [ 'type' => 'term', 'id' => 7, 'acf_id' => 'category_7' ], AcfListener::decodeId( 'category_7' ) );
		self::assertSame( 'options', AcfListener::decodeId( 'options' )['type'] );
		self::assertSame( 'options', AcfListener::decodeId( 'option' )['id'] );
		self::assertSame( 'site-settings', AcfListener::decodeId( 'site-settings' )['id'] );
	}

	public function test_saving_flag_is_scoped_to_the_save(): void {
		Functions\when( 'get_fields' )->justReturn( [ 'hero' => 'A' ] );
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		$listener = ( new AcfListener() )->bind( $this->logger, $this->config );
		self::assertFalse( AcfListener::isSaving( 'post', 12 ) );
		$listener->beforeSave( 12 );
		self::assertTrue( AcfListener::isSaving( 'post', 12 ) );
		self::assertTrue( AcfListener::isSaving( 'acf', '12' ) );
		self::assertFalse( AcfListener::isSaving( 'post', 13 ) );
		$listener->afterSave( 12 );
		self::assertFalse( AcfListener::isSaving( 'post', 12 ) );
		self::assertSame( [], $this->actions(), 'no change, no event' );
	}

	public function test_one_grouped_event_per_save(): void {
		Functions\when( 'get_post_type' )->justReturn( 'page' );
		$listener = ( new AcfListener() )->bind( $this->logger, $this->config );
		$listener->emitSave( 12, [ 'hero' => 'A', 'contact_email' => 'a@b.c', 'rows' => [ [ 'x' => 1 ] ] ], [ 'hero' => 'B', 'contact_email' => 'x@y.z', 'rows' => [ [ 'x' => 2 ] ] ] );
		self::assertSame( [ 'acf.fields_saved' ], $this->actions() );
		$event = $this->events[0];
		self::assertSame( 'page', $event->getObject()['subtype'] ?? null );
		self::assertSame( [ 'hero', 'contact_email', 'rows.0.x' ], array_column( $event->getChanges(), 'field' ) );
		self::assertTrue( $event->getChanges()[1]['new']['redacted'] );
		self::assertSame( 3, $event->getContext()['fields_changed'] );
		self::assertStringNotContainsString( 'x@y.z', $this->json() );
	}

	public function test_user_fields_are_content_summaries(): void {
		$listener = ( new AcfListener() )->bind( $this->logger, $this->config );
		$listener->emitSave( 'user_7', [ 'closing_line' => 'Regards, Jane Doe', 'links' => [ 'x' ], 'locale' => 'en_US' ], [ 'closing_line' => 'Cheers, Jane Doe', 'links' => [ 'y' ], 'locale' => 'de_CH' ] );
		self::assertSame( [ 'acf.fields_saved' ], $this->actions() );
		$changes = $this->events[0]->getChanges();
		self::assertSame( [ 'locale', 'closing_line', 'links' ], array_column( $changes, 'field' ) );
		self::assertSame( 'de_CH', $changes[0]['new'], 'preference keys keep full diffs' );
		self::assertSame( 16, $changes[1]['new']['length'] );
		self::assertSame( -1, $changes[1]['new']['length_delta'] );
		self::assertTrue( $changes[2]['new']['redacted'] );
		self::assertSame( 3, $this->events[0]->getContext()['fields_changed'] );
		self::assertStringNotContainsString( 'Jane', $this->json() );
	}

	public function test_non_viewable_post_fields_are_content_summaries(): void {
		Functions\when( 'get_post_type' )->justReturn( 'gf_entry' );
		Functions\when( 'is_post_type_viewable' )->justReturn( false );
		$listener = ( new AcfListener() )->bind( $this->logger, $this->config );
		$listener->emitSave( 40, [], [ 'message' => 'Hi from Jane', 'rows' => [ [ 'x' => 'Jane' ] ] ] );
		$changes = $this->events[0]->getChanges();
		self::assertSame( [ 'message', 'rows' ], array_column( $changes, 'field' ) );
		self::assertSame( 12, $changes[0]['new']['length'] );
		self::assertTrue( $changes[1]['new']['redacted'] );
		self::assertNull( $changes[0]['old'] );
		self::assertStringNotContainsString( 'Jane', $this->json() );
	}

	public function test_options_page_is_notice(): void {
		$listener = ( new AcfListener() )->bind( $this->logger, $this->config );
		$listener->emitSave( 'options', [ 'phone_label' => 'a' ], [ 'phone_label' => 'b' ] );
		self::assertSame( [ 'acf.options_saved' ], $this->actions() );
		self::assertSame( Severity::NOTICE, $this->events[0]->getSeverity() );
		self::assertSame( 'options', $this->events[0]->getObject()['type'] ?? null );
	}

	public function test_field_group_changed(): void {
		Functions\when( 'acf_get_fields' )->justReturn( [ [], [], [] ] );
		$listener = ( new AcfListener() )->bind( $this->logger, $this->config );
		$listener->groupChanged( 'updated', [ 'ID' => 44, 'key' => 'group_abc', 'title' => 'Patient data', 'active' => true ] );
		$listener->groupChanged( 'updated', [ 'ID' => 44, 'key' => 'group_abc', 'title' => 'Patient data', 'active' => true ] );
		self::assertSame( 1, $this->logger->pending(), 'aggregated within the request' );
		$event = $this->events[0];
		self::assertSame( 'acf-field-group', $event->getObject()['subtype'] ?? null );
		self::assertSame( 3, $event->getContext()['fields_count'] );
		self::assertSame( 'group_abc', $event->getContext()['field_group'] );
		self::assertStringNotContainsString( 'Patient', $this->json() );
	}
}
