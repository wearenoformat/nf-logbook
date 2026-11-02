<?php
/**
 * PathMapper and text helper tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Errors;

use Noformat\Logger\Errors\Support\PathMapper;
use Noformat\Logger\Errors\Support\Text;
use Noformat\Logger\Tests\TestCase;

final class PathMapperTest extends TestCase {

	private function mapper(): PathMapper {
		return new PathMapper(
			[
				'/srv/www/'                     => '',
				'/srv/content'                  => 'wp-content',
				'/srv/content/plugins'          => 'wp-content/plugins',
				'/srv/content/mu-plugins'       => 'wp-content/mu-plugins',
				'/srv/content/themes'           => 'wp-content/themes',
				'/home/dev/repos/my-plugin'     => 'wp-content/plugins/my-plugin',
				'/home/dev/repos/noformat-log/' => 'wp-content/plugins/nf-logbook',
			],
			'/home/dev/repos/noformat-log/src'
		);
	}

	public function test_relative_paths(): void {
		$m = $this->mapper();
		self::assertSame( 'wp-includes/functions.php', $m->relative( '/srv/www/wp-includes/functions.php' ) );
		self::assertSame( 'wp-content/plugins/akismet/akismet.php', $m->relative( '/srv/content/plugins/akismet/akismet.php' ) );
		self::assertSame( 'wp-content/plugins/my-plugin/inc/a.php', $m->relative( '/home/dev/repos/my-plugin/inc/a.php' ), 'symlinked plugin' );
		self::assertSame( 'wp-content/themes/twentytwentyfour/functions.php', $m->relative( '/srv/content/themes/twentytwentyfour/functions.php' ) );
		self::assertSame( '[outside]/secret.php', $m->relative( '/etc/php/secret.php' ) );
		self::assertSame( 'wp-includes/x.php', $m->relative( 'wp-includes/x.php' ) );
		self::assertSame( '', $m->relative( '' ) );
	}

	public function test_relative_in_text(): void {
		$m   = $this->mapper();
		$msg = 'include(/srv/content/plugins/foo/missing.php): Failed to open stream in /srv/www/wp-settings.php on line 3; see /opt/other/lib.php and https://example.com/a/b';
		$out = $m->relativeInText( $msg );
		self::assertStringContainsString( 'include(wp-content/plugins/foo/missing.php)', $out );
		self::assertStringContainsString( 'in wp-settings.php on line 3', $out );
		self::assertStringContainsString( '[outside]/lib.php', $out );
		self::assertStringContainsString( 'https://example.com/a/b', $out, 'URLs untouched' );
		self::assertStringNotContainsString( '/srv/', $out );
		self::assertStringNotContainsString( '/opt/', $out );
	}

	public function test_phar_paths(): void {
		$m = $this->mapper();
		self::assertSame( '[phar:wp]/vendor/wp-cli/export-command/src/WP_Export_Query.php', $m->relative( 'phar:///usr/local/bin/wp/vendor/wp-cli/export-command/src/WP_Export_Query.php' ) );
		self::assertSame( '[phar:wp-cli.phar]/php/boot-phar.php', $m->relative( 'phar:///home/alice/bin/wp-cli.phar/php/boot-phar.php' ) );
		self::assertSame( 'wp-content/plugins/akismet/lib.phar/src/A.php', $m->relative( 'phar:///srv/content/plugins/akismet/lib.phar/src/A.php' ), 'archive inside a root' );
		self::assertSame( '[outside]/file.php', $m->relative( 'phar:///opt/x/file.php' ) );
		self::assertSame( '[outside]/a.php', $m->relative( 'zip:///opt/x/a.php' ) );
		self::assertSame( 'wp-cli', PathMapper::component( '[phar:wp]/vendor/wp-cli/export-command/src/WP_Export_Query.php' ) );
		self::assertSame( 'phar:tool.phar', PathMapper::component( '[phar:tool.phar]/src/a.php' ) );
		self::assertSame( 'plugin:akismet', PathMapper::component( 'wp-content/plugins/akismet/lib.phar/src/A.php' ) );

		$out = $m->relativeInText( 'PHP DEPRECATED in phar:///usr/local/bin/wp/vendor/wp-cli/export-command/src/WP_Export_Query.php:248: strtotime()' );
		self::assertSame( 'PHP DEPRECATED in [phar:wp]/vendor/wp-cli/export-command/src/WP_Export_Query.php:248: strtotime()', $out );
		self::assertStringNotContainsString( '/usr/', $m->relativeInText( 'x phar:///home/alice/bin/wp-cli.phar/php/a.php y' ) );
	}

	public function test_component_detection(): void {
		self::assertSame( 'core', PathMapper::component( 'wp-includes/functions.php' ) );
		self::assertSame( 'core', PathMapper::component( 'wp-admin/includes/file.php' ) );
		self::assertSame( 'core', PathMapper::component( 'wp-settings.php' ) );
		self::assertSame( 'plugin:akismet', PathMapper::component( 'wp-content/plugins/akismet/class.akismet.php' ) );
		self::assertSame( 'plugin:hello', PathMapper::component( 'wp-content/plugins/hello.php' ) );
		self::assertSame( 'theme:twentytwentyfour', PathMapper::component( 'wp-content/themes/twentytwentyfour/functions.php' ) );
		self::assertSame( 'mu-plugin:loader', PathMapper::component( 'wp-content/mu-plugins/loader.php' ) );
		self::assertSame( 'mu-plugin:suite', PathMapper::component( 'wp-content/mu-plugins/suite/a.php' ) );
		self::assertSame( 'drop-in:db', PathMapper::component( 'wp-content/db.php' ) );
		self::assertSame( 'wp-content', PathMapper::component( 'wp-content/uploads/x.php' ) );
		self::assertSame( 'unknown', PathMapper::component( '[outside]/x.php' ) );
	}

	public function test_own_files(): void {
		$m = $this->mapper();
		self::assertTrue( $m->isOwn( '/home/dev/repos/noformat-log/src/Logger.php' ) );
		self::assertFalse( $m->isOwn( '/home/dev/repos/noformat-log/nf-logbook.php' ) );
		self::assertFalse( $m->isOwn( '/srv/www/wp-includes/load.php' ) );
	}

	public function test_scrub_and_clip(): void {
		self::assertSame( 'Invalid address: [email] from [ip]', Text::scrub( 'Invalid address: john.doe@example.com from 192.168.1.20' ) );
		self::assertSame( 'v [ip] x', Text::scrub( 'v 2001:db8::1 x' ) );
		self::assertSame( 'version 6.4.3', Text::scrub( 'version 6.4.3' ), 'versions are not IPs' );
		$long = str_repeat( 'é', 1000 );
		$cut  = Text::clip( $long, 101 );
		self::assertLessThanOrEqual( 101, strlen( $cut ) );
		self::assertTrue( mb_check_encoding( $cut, 'UTF-8' ) );
		self::assertStringEndsWith( '...', $cut );
	}

	public function test_normalize_strips_numbers_hex_and_quoted(): void {
		self::assertSame( Text::normalize( 'Undefined array key 3' ), Text::normalize( 'Undefined array key 17' ) );
		self::assertSame( Text::normalize( 'Undefined array key "foo"' ), Text::normalize( 'Undefined array key "bar"' ) );
		self::assertSame( Text::normalize( 'Object 0x7f3a2b at deadbeef01' ), Text::normalize( 'Object 0x11 at 0123456789abcdef' ) );
		self::assertNotSame( Text::normalize( 'Undefined variable $a' ), Text::normalize( 'Undefined index x' ) );
	}
}
