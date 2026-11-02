<?php
/**
 * File editor observation: attempt (warning) vs edit (critical).
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\System;

use Brain\Monkey\Filters;
use Noformat\Logger\Config;
use Noformat\Logger\Event;
use Noformat\Logger\Listeners\PluginThemeListener;
use Noformat\Logger\Severity;
use Noformat\Logger\Tests\TestCase;

final class PluginThemeListenerTest extends TestCase {

	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/nflog-unit-plugins' );
		}
		$this->dir = WP_PLUGIN_DIR . '/nflog-unit-editor';
		if ( ! is_dir( $this->dir ) && ! @mkdir( $this->dir, 0777, true ) ) { // phpcs:ignore
			self::markTestSkipped( 'WP_PLUGIN_DIR not writable' );
		}
		file_put_contents( $this->dir . '/readme.txt', 'one' );
	}

	protected function tearDown(): void {
		$_POST = [];
		@unlink( $this->dir . '/readme.txt' ); // phpcs:ignore
		@rmdir( $this->dir ); // phpcs:ignore
		parent::tearDown();
	}

	/**
	 * @return list<Event>
	 */
	private function run_editor( bool $change ): array {
		$events = [];
		Filters\expectApplied( 'noformat_logger/event' )->zeroOrMoreTimes()->andReturnUsing(
			static function ( Event $event ) use ( &$events ): Event {
				$events[] = $event;
				return $event;
			}
		);
		$_POST    = [
			'file'       => 'nflog-unit-editor/readme.txt',
			'plugin'     => 'nflog-unit-editor/nflog-unit-editor.php',
			'newcontent' => 'secret content',
		];
		$listener = new PluginThemeListener();
		$listener->register( BurstDetectorTest::logger(), new Config() );
		$listener->onEditorAjax();
		if ( $change ) {
			file_put_contents( $this->dir . '/readme.txt', 'two' );
		}
		$listener->onEditorShutdown();
		return $events;
	}

	public function test_unchanged_file_is_an_attempt(): void {
		$events = $this->run_editor( false );
		self::assertCount( 1, $events );
		self::assertSame( 'editor.file_edited', $events[0]->getAction() );
		self::assertSame( Severity::WARNING, $events[0]->getSeverity() );
		self::assertTrue( $events[0]->getContext()['attempt'] );
		self::assertSame( 'nflog-unit-editor', $events[0]->getObject()['id'] ?? null );
	}

	public function test_changed_file_is_critical(): void {
		$events = $this->run_editor( true );
		self::assertCount( 1, $events );
		self::assertSame( Severity::CRITICAL, $events[0]->getSeverity() );
		self::assertTrue( $events[0]->getContext()['changed'] );
		self::assertSame( 'nflog-unit-editor/readme.txt', $events[0]->getContext()['file'] );
		self::assertStringNotContainsString( 'secret content', (string) json_encode( $events[0]->toArray() ) );
	}
}
