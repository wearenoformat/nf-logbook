<?php
/**
 * Pure helpers of the system-group listeners.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\System;

use Brain\Monkey\Functions;
use Noformat\Logger\Listeners\CoreListener;
use Noformat\Logger\Listeners\PluginThemeListener;
use Noformat\Logger\Security\RestProbeListener;
use Noformat\Logger\Security\XmlRpcListener;
use Noformat\Logger\Tests\TestCase;

final class SystemListenerHelpersTest extends TestCase {

	public function test_plugin_slug(): void {
		self::assertSame( 'akismet', PluginThemeListener::pluginSlug( 'akismet/akismet.php' ) );
		self::assertSame( 'hello', PluginThemeListener::pluginSlug( 'hello.php' ) );
		self::assertSame( 'woo', PluginThemeListener::pluginSlug( 'woo/includes/x.php' ) );
	}

	public function test_editor_target(): void {
		Functions\when( 'wp_get_theme' )->justReturn(
			new class() {
				public function exists(): bool {
					return false;
				}
			}
		);
		$plugin = PluginThemeListener::editorTarget(
			[
				'file'       => 'akismet/class.akismet.php',
				'plugin'     => 'akismet/akismet.php',
				'newcontent' => '<?php evil();',
			]
		);
		self::assertNotNull( $plugin );
		self::assertSame( 'plugin', $plugin['kind'] );
		self::assertSame( 'akismet', $plugin['slug'] );
		self::assertSame( 'akismet/class.akismet.php', $plugin['file'] );
		self::assertStringNotContainsString( 'evil', (string) json_encode( $plugin ) );

		$theme = PluginThemeListener::editorTarget(
			[
				'file'  => 'style.css',
				'theme' => 'twentytwentyfour',
			]
		);
		self::assertSame( 'theme', $theme['kind'] ?? null );
		self::assertSame( 'twentytwentyfour', $theme['slug'] ?? null );

		self::assertNull( PluginThemeListener::editorTarget( [ 'file' => '../../wp-config.php', 'plugin' => 'x/x.php' ] ) );
		self::assertNull( PluginThemeListener::editorTarget( [ 'file' => 'a.php' ] ) );
		self::assertNull( PluginThemeListener::editorTarget( [ 'file' => 'a.php', 'theme' => '../x' ] ) );
	}

	public function test_auto_update_summary(): void {
		$results = [
			'plugin'      => [
				(object) [
					'item'   => (object) [ 'slug' => 'akismet', 'plugin' => 'akismet/akismet.php' ],
					'result' => true,
					'name'   => 'Akismet',
				],
				(object) [
					'item'   => (object) [ 'slug' => 'broken' ],
					'result' => null,
				],
			],
			'theme'       => [
				(object) [
					'item'   => (object) [ 'theme' => 'twentytwentyfour' ],
					'result' => true,
				],
			],
			'core'        => [
				(object) [
					'item'   => (object) [ 'current' => '6.7.1' ],
					'result' => '6.7.1',
				],
			],
			'translation' => [],
		];
		$summary = CoreListener::summarise( $results );
		self::assertSame( [ 'plugin', 'theme', 'core' ], array_keys( $summary ) );
		self::assertSame( 2, $summary['plugin']['count'] );
		self::assertSame( 1, $summary['plugin']['failed'] );
		self::assertSame( [ 'akismet', 'broken' ], $summary['plugin']['slugs'] );
		self::assertSame( [ 'broken' ], $summary['plugin']['failed_slugs'] );
		self::assertSame( [ '6.7.1' ], $summary['core']['slugs'] );
		self::assertStringNotContainsString( 'Akismet', (string) json_encode( $summary ), 'display names are not copied' );
	}

	public function test_xmlrpc_body_parsing(): void {
		$list = '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName><params></params></methodCall>';
		self::assertSame( 'system.listMethods', XmlRpcListener::parseBody( $list )['method'] ?? null );

		$multi = '<methodCall><methodName>system.multicall</methodName><params><param><value><array><data>'
			. str_repeat( '<value><struct><member><name>methodName</name><value><string>wp.getUsersBlogs</string></value></member></struct></value>', 3 )
			. '<value><struct><member><name>methodName</name><value>pingback.ping</value></member></struct></value>'
			. '</data></array></value></param></params></methodCall>';
		$parsed = XmlRpcListener::parseBody( $multi );
		self::assertSame( 'system.multicall', $parsed['method'] ?? null );
		self::assertSame(
			[
				'wp.getUsersBlogs' => 3,
				'pingback.ping'    => 1,
			],
			$parsed['inner'] ?? null
		);

		self::assertNull( XmlRpcListener::parseBody( 'not xml' ) );
		self::assertSame( '(invalid)', XmlRpcListener::parseBody( '<methodName>a b<script></methodName>' )['method'] ?? '(invalid)' );
		self::assertSame( '(invalid)', XmlRpcListener::cleanMethod( 'drop table' ) );
	}

	public function test_rest_route_normalisation(): void {
		self::assertSame( '/wp/v2/users', RestProbeListener::normaliseRoute( '/wp/v2/users/' ) );
		self::assertSame( '/wp/v2/users/{id}', RestProbeListener::normaliseRoute( '/wp/v2/users/12?context=edit' ) );
		self::assertTrue( RestProbeListener::isUsersRoute( '/wp/v2/users' ) );
		self::assertTrue( RestProbeListener::isUsersRoute( '/wp/v2/users/{id}' ) );
		self::assertTrue( RestProbeListener::isUsersRoute( RestProbeListener::normaliseRoute( '/WP/V2/Users/me' ) ) );
		self::assertFalse( RestProbeListener::isUsersRoute( '/wp/v2/usersx' ) );
		self::assertFalse( RestProbeListener::isUsersRoute( '/wp/v2/posts' ) );
	}
}
