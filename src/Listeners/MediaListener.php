<?php
/**
 * MediaListener.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Listeners;

use Noformat\Logger\Category;
use Noformat\Logger\Config;
use Noformat\Logger\Event;
use Noformat\Logger\Listener;
use Noformat\Logger\Listeners\Support\Hooks;
use Noformat\Logger\Logger;
use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Media: add_attachment, attachment_updated (edit_attachment), wp_update_attachment_metadata, delete_attachment (PLAN §4).
 *
 * Never logs file names or paths: only a salted hash of the base name. Titles
 * and slugs default to the file name, so they are hashed as well. An
 * upload is emitted on shutdown so the dimensions generated in the same
 * request end up in the media.uploaded event instead of a separate one.
 */
final class MediaListener implements Listener {

	use Hooks;

	/**
	 * Uploads in this request, by attachment id.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $uploads = [];

	/**
	 * Availability.
	 */
	public static function isAvailable(): bool {
		return true;
	}

	/**
	 * Add hooks.
	 *
	 * @param Logger $logger Logger.
	 * @param Config $config Config.
	 */
	public function register( Logger $logger, Config $config ): void {
		$this->bind( $logger, $config );
		$this->onAction( 'add_attachment', 'uploaded', 10, 1 );
		$this->onAction( 'attachment_updated', 'updated', 10, 3 );
		$this->onFilter( 'wp_update_attachment_metadata', 'metadataUpdated', 99, 2 );
		$this->onAction( 'delete_attachment', 'deleted', 10, 2 );
		$this->onAction( 'shutdown', 'flush', 1, 0 );
	}

	/**
	 * Attachment added: collect, emit on flush.
	 *
	 * @param mixed $attachmentId Attachment id.
	 */
	public function uploaded( mixed $attachmentId ): void {
		$id   = self::intId( $attachmentId );
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! is_object( $post ) ) {
			return;
		}
		$file = function_exists( 'get_attached_file' ) ? get_attached_file( $id, true ) : '';
		$file = is_string( $file ) ? $file : '';
		$size = null;
		if ( '' !== $file && @is_file( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir.
			$bytes = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable file tolerated.
			$size  = false === $bytes ? null : $bytes;
		}
		$this->uploads[ $id ] = [
			'mime'      => (string) $post->post_mime_type,
			'parent_id' => (int) $post->post_parent,
			'filesize'  => $size,
			'file_hash' => '' === $file ? null : $this->hash8( wp_basename( $file ) ),
			'label'     => $this->hash8( $post->post_title ),
		];
		if ( count( $this->uploads ) > 50 ) {
			$this->flush();
		}
	}

	/**
	 * Attachment edited (title, caption, description, parent, slug).
	 *
	 * @param mixed $attachmentId Attachment id.
	 * @param mixed $after        Post after.
	 * @param mixed $before       Post before.
	 */
	public function updated( mixed $attachmentId, mixed $after, mixed $before ): void {
		$id = self::intId( $attachmentId );
		if ( $id <= 0 || ! is_object( $after ) || ! is_object( $before ) ) {
			return;
		}
		$changes = $this->diff( $before, $after );
		if ( [] === $changes ) {
			return;
		}
		$mime = (string) ( $after->post_mime_type ?? '' );
		$this->emit(
			Event::make( Category::MEDIA, 'media.updated', Severity::INFO )
				->object( 'attachment', $id, '' === $mime ? null : $mime, $this->hash8( $after->post_title ?? '' ) )
				->summary( sprintf( 'Attachment #%d updated: %s', $id, implode( ', ', array_column( $changes, 'field' ) ) ) )
				->changes( $changes )
		);
	}

	/**
	 * Attachment metadata written (generation or regeneration). Returns nothing; the filter passes $data through.
	 *
	 * @param mixed $data         Metadata.
	 * @param mixed $attachmentId Attachment id.
	 */
	public function metadataUpdated( mixed $data, mixed $attachmentId ): void {
		$id = self::intId( $attachmentId );
		if ( $id <= 0 || ! is_array( $data ) ) {
			return;
		}
		$summary = self::metadataSummary( $data );
		if ( isset( $this->uploads[ $id ] ) ) {
			$this->uploads[ $id ] = array_merge( $this->uploads[ $id ], array_filter( $summary, static fn( $v ): bool => null !== $v ) );
			return;
		}
		$mime = function_exists( 'get_post_mime_type' ) ? get_post_mime_type( $id ) : '';
		$this->emit(
			Event::make( Category::MEDIA, 'media.metadata_updated', Severity::INFO )
				->object( 'attachment', $id, is_string( $mime ) && '' !== $mime ? $mime : null )
				->summary( sprintf( 'Attachment #%d metadata updated (%d sizes)', $id, (int) $summary['sizes_count'] ) )
				->context( $summary )
				->fingerprint( 'media.metadata_updated', $id )
		);
	}

	/**
	 * Attachment deleted.
	 *
	 * @param mixed $attachmentId Attachment id.
	 * @param mixed $post         Post.
	 */
	public function deleted( mixed $attachmentId, mixed $post = null ): void {
		$id   = self::intId( $attachmentId );
		$post = is_object( $post ) ? $post : get_post( $id );
		if ( $id <= 0 || ! is_object( $post ) ) {
			return;
		}
		unset( $this->uploads[ $id ] );
		$mime = (string) ( $post->post_mime_type ?? '' );
		$this->emit(
			Event::make( Category::MEDIA, 'media.deleted', Severity::NOTICE )
				->object( 'attachment', $id, '' === $mime ? null : $mime, $this->hash8( $post->post_title ?? '' ) )
				->summary( sprintf( 'Attachment #%d deleted', $id ) )
				->context( [ 'parent_id' => (int) ( $post->post_parent ?? 0 ) ] )
		);
	}

	/**
	 * Emit collected uploads.
	 */
	public function flush(): void {
		$uploads       = $this->uploads;
		$this->uploads = [];
		foreach ( $uploads as $id => $info ) {
			$mime  = (string) $info['mime'];
			$label = $info['label'];
			unset( $info['label'] );
			$this->emit(
				Event::make( Category::MEDIA, 'media.uploaded', Severity::INFO )
					->object( 'attachment', $id, '' === $mime ? null : $mime, is_string( $label ) ? $label : null )
					->summary( sprintf( 'Attachment #%d uploaded (%s)', $id, '' === $mime ? 'unknown type' : $mime ) )
					->context( array_filter( $info, static fn( $v ): bool => null !== $v ) )
			);
		}
	}

	/**
	 * Safe subset of attachment metadata: dimensions, size count, file size. Never file, path or EXIF.
	 *
	 * @param array<string|int, mixed> $data Metadata.
	 * @return array{width: int|null, height: int|null, sizes_count: int, filesize: int|null}
	 */
	public static function metadataSummary( array $data ): array {
		return [
			'width'       => isset( $data['width'] ) && is_numeric( $data['width'] ) ? (int) $data['width'] : null,
			'height'      => isset( $data['height'] ) && is_numeric( $data['height'] ) ? (int) $data['height'] : null,
			'sizes_count' => isset( $data['sizes'] ) && is_array( $data['sizes'] ) ? count( $data['sizes'] ) : 0,
			'filesize'    => isset( $data['filesize'] ) && is_numeric( $data['filesize'] ) ? (int) $data['filesize'] : null,
		];
	}

	/**
	 * Attachment field changes.
	 *
	 * @param object $before Before.
	 * @param object $after  After.
	 * @return list<array{field: string, old: mixed, new: mixed}>
	 */
	public function diff( object $before, object $after ): array {
		$changes = [];
		$plain   = [
			'title'     => 'post_title',
			'slug'      => 'post_name',
			'parent_id' => 'post_parent',
			'author_id' => 'post_author',
		];
		foreach ( $plain as $field => $property ) {
			$a = (string) ( $before->{$property} ?? '' );
			$b = (string) ( $after->{$property} ?? '' );
			if ( $a !== $b ) {
				$numeric   = in_array( $field, [ 'parent_id', 'author_id' ], true );
				$changes[] = [
					'field' => $field,
					'old'   => $numeric ? (int) $a : $this->opaque( $a ),
					'new'   => $numeric ? (int) $b : $this->opaque( $b ),
				];
			}
		}
		foreach ( [
			'caption'     => 'post_excerpt',
			'description' => 'post_content',
		] as $field => $property ) {
			$a = (string) ( $before->{$property} ?? '' );
			$b = (string) ( $after->{$property} ?? '' );
			if ( $a !== $b ) {
				$changes[] = [
					'field' => $field,
					'old'   => $this->contentSummary( $a ),
					'new'   => $this->contentSummary( $b, $a ),
				];
			}
		}
		return $changes;
	}

	/**
	 * Redaction marker for values derived from file names (title, slug).
	 *
	 * @param string $value Value.
	 */
	private function opaque( string $value ): mixed {
		if ( '' === $value || null === $this->logger ) {
			return '' === $value ? '' : null;
		}
		return $this->logger->redactor()->marker( $value );
	}
}
