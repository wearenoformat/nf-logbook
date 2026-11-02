<?php
/**
 * Probe pattern matching.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\System;

use Brain\Monkey\Filters;
use Noformat\Logger\Security\ProbeListener;
use Noformat\Logger\Tests\TestCase;

final class ProbeListenerTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string|null}>
	 */
	public static function paths(): array {
		return [
			'env'               => [ '/.env', 'env_file' ],
			'env variant'       => [ '/app/.env.production', 'env_file' ],
			'encoded env'       => [ '/%2Eenv', 'env_file' ],
			'wp-config backup'  => [ '/wp-config.php.bak', 'wp_config' ],
			'git config'        => [ '/.git/config', 'vcs' ],
			'sql dump'          => [ '/backup/db.sql.gz', 'sql_dump' ],
			'bak'               => [ '/index.php.bak', 'backup_file' ],
			'content zip'       => [ '/wp-content/backup-2024.zip', 'archive' ],
			'root zip'          => [ '/site.zip', 'archive' ],
			'phpmyadmin'        => [ '/phpMyAdmin/index.php', 'phpmyadmin' ],
			'pma'               => [ '/pma/', 'phpmyadmin' ],
			'adminer'           => [ '/adminer-4.8.1.php', 'adminer' ],
			'xmlrpc in subdir'  => [ '/blog/xmlrpc.php', 'xmlrpc' ],
			'login in subdir'   => [ '/wp/wp-login.php', 'wp_login' ],
			'vendor'            => [ '/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php', 'vendor' ],
			'php in uploads'    => [ '/wp-content/uploads/2024/05/x.php', 'uploads_php' ],
			'wlwmanifest'       => [ '/blog/wp-includes/wlwmanifest.xml', 'wlwmanifest' ],
			'normal page'       => [ '/about-us/', null ],
			'environment page'  => [ '/environment/', null ],
			'image in uploads'  => [ '/wp-content/uploads/2024/05/x.jpg', null ],
			'plugin zip (deep)' => [ '/downloads/files/plugin.zip', null ],
			'root'              => [ '/', null ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'paths' )]
	public function test_patterns( string $path, ?string $expected ): void {
		self::assertSame( $expected, ProbeListener::match( $path, ProbeListener::patterns() ) );
	}

	public function test_filter_adds_and_sanitises_patterns(): void {
		Filters\expectApplied( 'noformat_logger/probe_patterns' )->andReturnUsing(
			static function ( array $patterns ): array {
				$patterns['Laravel Log'] = 'storage/logs/laravel\.log$';
				$patterns['9bad']        = 'x';
				$patterns['empty']       = '';
				return $patterns;
			}
		);
		$patterns = ProbeListener::patterns();
		self::assertArrayHasKey( 'laravel_log', $patterns );
		self::assertArrayNotHasKey( '9bad', $patterns );
		self::assertArrayNotHasKey( 'empty', $patterns );
		self::assertSame( 'laravel_log', ProbeListener::match( '/storage/logs/laravel.log', $patterns ) );
	}

	public function test_invalid_pattern_is_harmless(): void {
		self::assertNull( ProbeListener::match( '/.env', [ 'broken' => '(unclosed' ] ) );
		self::assertNull( ProbeListener::match( '', ProbeListener::defaultPatterns() ) );
	}
}
