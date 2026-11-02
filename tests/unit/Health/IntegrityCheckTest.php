<?php
/**
 * IntegrityCheck comparison tests (temp files, fake checksum lists).
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Health;

use Noformat\Logger\Security\IntegrityCheck;
use Noformat\Logger\Tests\TestCase;

final class IntegrityCheckTest extends TestCase {

	private string $root = '';

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/nflog-integrity-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/wp-includes/sub', 0777, true );
		mkdir( $this->root . '/wp-admin', 0777, true );
		mkdir( $this->root . '/wp-content/plugins', 0777, true );
		file_put_contents( $this->root . '/index.php', 'index' );
		file_put_contents( $this->root . '/wp-includes/load.php', 'load' );
		file_put_contents( $this->root . '/wp-includes/sub/a.php', 'changed' );
		file_put_contents( $this->root . '/wp-includes/sub/evil.php', 'evil' );
		file_put_contents( $this->root . '/wp-admin/admin.php', 'admin' );
		file_put_contents( $this->root . '/wp-includes/style.css', 'not php' );
	}

	protected function tearDown(): void {
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->root );
		parent::tearDown();
	}

	/**
	 * Fake core checksum list.
	 *
	 * @return array<string, string>
	 */
	private function checksums(): array {
		return [
			'index.php'                  => md5( 'index' ),
			'wp-includes/load.php'       => md5( 'load' ),
			'wp-includes/sub/a.php'      => md5( 'original' ),
			'wp-admin/admin.php'         => md5( 'admin' ),
			'wp-admin/gone.php'          => md5( 'gone' ),
			'wp-content/plugins/x.php'   => md5( 'skipped' ),
			'wp-includes/style.css'      => md5( 'not php' ),
		];
	}

	public function test_compare_reports_modified_and_missing(): void {
		$result = IntegrityCheck::compare( $this->root, $this->checksums(), 'md5', null, 0, [ 'wp-content/' ] );
		self::assertSame( [ 'wp-includes/sub/a.php' ], $result['modified'] );
		self::assertSame( [ 'wp-admin/gone.php' ], $result['missing'] );
		self::assertSame( 6, $result['checked'], 'wp-content skipped' );
		self::assertNull( $result['next_offset'] );
	}

	public function test_compare_stops_at_deadline_and_resumes(): void {
		$first = IntegrityCheck::compare( $this->root, $this->checksums(), 'md5', microtime( true ) - 1, 0 );
		self::assertSame( 0, $first['next_offset'] );
		$resumed = IntegrityCheck::compare( $this->root, $this->checksums(), 'md5', null, (int) $first['next_offset'], [ 'wp-content/' ] );
		self::assertSame( [ 'wp-includes/sub/a.php' ], $resumed['modified'] );
	}

	public function test_unknown_php_files(): void {
		$unknown = IntegrityCheck::unknownFiles( $this->root, $this->checksums(), [ 'wp-admin', 'wp-includes' ] );
		self::assertSame( [ 'wp-includes/sub/evil.php' ], $unknown['paths'] );
		self::assertSame( 1, $unknown['total'] );
	}

	public function test_plugin_checksums_prefer_sha256(): void {
		file_put_contents( $this->root . '/wp-content/plugins/p.php', 'plugin' );
		$list = IntegrityCheck::pluginChecksums(
			[
				'p.php'    => [
					'md5'    => md5( 'plugin' ),
					'sha256' => [ hash( 'sha256', 'older' ), hash( 'sha256', 'plugin' ) ],
				],
				'gone.php' => [
					'md5'    => md5( 'x' ),
					'sha256' => hash( 'sha256', 'x' ),
				],
			]
		);
		self::assertSame( 'sha256', $list['algo'] );
		$result = IntegrityCheck::compare( $this->root . '/wp-content/plugins', $list['checksums'], $list['algo'] );
		self::assertSame( [], $result['modified'], 'any listed hash matches' );
		self::assertSame( [ 'gone.php' ], $result['missing'] );
		$md5 = IntegrityCheck::pluginChecksums( [ 'p.php' => [ 'md5' => md5( 'plugin' ) ] ] );
		self::assertSame( 'md5', $md5['algo'] );
	}

	public function test_scope_changes_emit_once_per_state(): void {
		$core    = [
			'status'   => 'mismatch',
			'modified' => [ 'wp-includes/sub/a.php' ],
			'missing'  => [],
			'unknown'  => [ 'wp-includes/sub/evil.php' ],
		];
		$plugins = [
			'verified'   => [ 'akismet' ],
			'mismatches' => [],
		];
		$scopes  = IntegrityCheck::scopes( $core, $plugins );
		$changes = IntegrityCheck::changes( [], $scopes );
		self::assertCount( 1, $changes, 'clean plugin scope does not emit on first sight' );
		self::assertSame( 'core', $changes[0]['scope'] );
		self::assertSame( 'detected', $changes[0]['state'] );
		self::assertSame( 2, $changes[0]['total'] );
		$event = IntegrityCheck::eventFor( $changes[0] );
		self::assertSame( 'integrity.mismatch', $event->getAction() );
		self::assertSame( 'critical', $event->getSeverity()->value );

		$stored = array_map( static fn( array $s ): string => $s['digest'], $scopes );
		self::assertSame( [], IntegrityCheck::changes( $stored, $scopes ), 'same state: nothing' );

		$clean = IntegrityCheck::scopes( array_merge( $core, [ 'status' => 'ok', 'modified' => [], 'unknown' => [] ] ), $plugins );
		$fixed = IntegrityCheck::changes( $stored, $clean );
		self::assertSame( 'resolved', $fixed[0]['state'] );
		self::assertSame( 'info', IntegrityCheck::eventFor( $fixed[0] )->getSeverity()->value );

		$unavailable = IntegrityCheck::scopes( [ 'status' => 'unavailable' ], [] );
		self::assertSame( [], IntegrityCheck::changes( $stored, $unavailable ), 'unknown state never resolves' );
	}
}
