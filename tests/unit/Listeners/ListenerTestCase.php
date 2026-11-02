<?php
/**
 * Shared fixture for listener tests: a database-less Logger that records events.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Listeners;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Noformat\Logger\Config;
use Noformat\Logger\Context\RequestContext;
use Noformat\Logger\Event;
use Noformat\Logger\Logger;
use Noformat\Logger\Privacy\Redactor;
use Noformat\Logger\Storage\EventRepository;
use Noformat\Logger\Storage\FingerprintStore;
use Noformat\Logger\Storage\Meta;
use Noformat\Logger\Tests\TestCase;

abstract class ListenerTestCase extends TestCase {

	/**
	 * Events that reached Logger::log().
	 *
	 * @var list<Event>
	 */
	protected array $events = [];

	protected Logger $logger;

	protected Config $config;

	protected function setUp(): void {
		parent::setUp();
		$this->events = [];
		Filters\expectApplied( 'noformat_logger/event' )->zeroOrMoreTimes()->andReturnUsing(
			function ( $event ) {
				$this->events[] = $event;
				return $event;
			}
		);
		Functions\when( 'wp_basename' )->alias( static fn( $path ) => basename( (string) $path ) );
		// Functions other test files may have defined (function_exists() then holds): give them defaults.
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( (string) $url, $component ) );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_post_mime_type' )->justReturn( '' );
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		Functions\when( 'get_taxonomy' )->justReturn( false );
		Functions\when( 'post_type_exists' )->justReturn( true );
		Functions\when( 'is_post_type_viewable' )->justReturn( true );
		Functions\when( 'clean_term_cache' )->justReturn( null );
		Functions\when( 'is_multisite' )->justReturn( false );
		$this->useSettings( [] );
	}

	/**
	 * Rebuild logger and config with settings.
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	protected function useSettings( array $settings ): void {
		$this->config = new Config( $settings );
		$meta         = new Meta();
		$this->logger = new Logger(
			$this->config,
			new RequestContext( null, 'test-salt' ),
			new Redactor( $this->config->sensitiveKeys(), $this->config->safeKeys(), 'test-salt' ),
			new EventRepository( $meta ),
			new FingerprintStore( $this->config ),
			$meta
		);
	}

	/**
	 * Recorded action names.
	 *
	 * @return list<string>
	 */
	protected function actions(): array {
		return array_map( static fn( Event $e ): string => $e->getAction(), $this->events );
	}

	/**
	 * JSON of all recorded events (for privacy assertions).
	 */
	protected function json(): string {
		return (string) json_encode( array_map( static fn( Event $e ): array => $e->toArray(), $this->events ) );
	}

	/**
	 * Fake WP_Post-like object.
	 *
	 * @param array<string, mixed> $fields Fields.
	 */
	protected static function post( array $fields ): object {
		return (object) array_merge(
			[
				'ID'             => 1,
				'post_type'      => 'post',
				'post_title'     => '',
				'post_name'      => '',
				'post_status'    => 'draft',
				'post_author'    => 1,
				'post_parent'    => 0,
				'menu_order'     => 0,
				'post_date'      => '2026-10-28 10:00:00',
				'comment_status' => 'open',
				'ping_status'    => 'open',
				'post_password'  => '',
				'post_content'   => '',
				'post_excerpt'   => '',
				'post_mime_type' => '',
			],
			$fields
		);
	}
}
