<?php
/**
 * MediaListener tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Listeners;

use Brain\Monkey\Functions;
use Noformat\Logger\Listeners\MediaListener;

final class MediaListenerTest extends ListenerTestCase {

	public function test_metadata_summary_never_contains_file_or_exif(): void {
		$summary = MediaListener::metadataSummary(
			[
				'width'      => 640,
				'height'     => '480',
				'file'       => '2026/10/jane-doe-passport.jpg',
				'filesize'   => 1234,
				'sizes'      => [ 'thumbnail' => [ 'file' => 'x-150x150.jpg' ], 'medium' => [] ],
				'image_meta' => [ 'credit' => 'Jane Doe', 'camera' => 'X' ],
			]
		);
		self::assertSame( [ 'width' => 640, 'height' => 480, 'sizes_count' => 2, 'filesize' => 1234 ], $summary );
	}

	public function test_upload_is_deferred_and_merged_with_dimensions(): void {
		$file = tempnam( sys_get_temp_dir(), 'nflog' );
		file_put_contents( $file, str_repeat( 'x', 42 ) );
		Functions\when( 'get_post' )->justReturn( self::post( [ 'ID' => 70, 'post_type' => 'attachment', 'post_mime_type' => 'image/png', 'post_title' => 'jane-doe-passport', 'post_parent' => 3 ] ) );
		Functions\when( 'get_attached_file' )->justReturn( $file );
		$listener = ( new MediaListener() )->bind( $this->logger, $this->config );
		$listener->uploaded( 70 );
		$listener->metadataUpdated( [ 'width' => 10, 'height' => 20, 'sizes' => [], 'file' => 'jane-doe-passport.png' ], 70 );
		self::assertSame( [], $this->actions(), 'nothing until flush' );
		$listener->flush();
		unlink( $file );

		self::assertSame( [ 'media.uploaded' ], $this->actions() );
		$event = $this->events[0];
		self::assertSame( 'image/png', $event->getObject()['subtype'] ?? null );
		$context = $event->getContext();
		self::assertSame( 42, $context['filesize'] );
		self::assertSame( 10, $context['width'] );
		self::assertSame( 3, $context['parent_id'] );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{8}$/', $context['file_hash'] );
		self::assertStringNotContainsString( 'jane', $this->json() );
		self::assertStringNotContainsString( sys_get_temp_dir(), $this->json() );
	}

	public function test_regeneration_is_fingerprinted_per_attachment(): void {
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/jpeg' );
		$listener = ( new MediaListener() )->bind( $this->logger, $this->config );
		$listener->metadataUpdated( [ 'sizes' => [ 'a' => [] ] ], 5 );
		$listener->metadataUpdated( [ 'sizes' => [ 'a' => [] ] ], 5 );
		self::assertSame( [ 'media.metadata_updated', 'media.metadata_updated' ], $this->actions() );
		self::assertSame( $this->events[0]->getFingerprint(), $this->events[1]->getFingerprint() );
		self::assertSame( 1, $this->logger->pending(), 'aggregated in the buffer' );
		self::assertSame( 2, $this->events[0]->getCount() );
	}

	public function test_title_and_slug_are_hashed_on_update(): void {
		$listener = ( new MediaListener() )->bind( $this->logger, $this->config );
		$listener->updated(
			8,
			self::post( [ 'post_title' => 'holiday-jane', 'post_name' => 'holiday-jane', 'post_excerpt' => 'cap' ] ),
			self::post( [ 'post_title' => 'IMG_1', 'post_name' => 'img_1', 'post_excerpt' => '' ] )
		);
		self::assertSame( [ 'media.updated' ], $this->actions() );
		self::assertSame( [ 'title', 'slug', 'caption' ], array_column( $this->events[0]->getChanges(), 'field' ) );
		self::assertStringNotContainsString( 'jane', $this->json() );
	}
}
