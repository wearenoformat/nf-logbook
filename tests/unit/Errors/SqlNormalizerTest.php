<?php
/**
 * SQL literal stripping tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Errors;

use Noformat\Logger\Errors\DbErrorListener;
use Noformat\Logger\Errors\Support\SqlNormalizer;
use Noformat\Logger\Tests\TestCase;

final class SqlNormalizerTest extends TestCase {

	public function test_strips_literals(): void {
		self::assertSame(
			'SELECT * FROM wp_users WHERE user_email = ? AND ID = ? LIMIT ?, ?',
			SqlNormalizer::strip( "SELECT * FROM wp_users WHERE user_email = 'bob@example.com' AND ID = 12 LIMIT 0, 10" )
		);
		self::assertSame( 'UPDATE `wp_options` SET option_value = ? WHERE option_name = ?', SqlNormalizer::strip( "UPDATE `wp_options` SET option_value = 'it\\'s \"x\"' WHERE option_name = \"blogname\"" ) );
		self::assertSame( 'SELECT * FROM t WHERE id IN (?)', SqlNormalizer::strip( 'SELECT * FROM t WHERE id IN (1, 2, 3)' ) );
		self::assertSame( 'INSERT INTO t (a, b) VALUES (?)', SqlNormalizer::strip( "INSERT INTO t (a, b) VALUES (1, 'x'), (2, 'y')" ) );
		self::assertSame( 'SELECT wp_2_posts.ID FROM wp_2_posts WHERE x = ? AND y = ?', SqlNormalizer::strip( "SELECT wp_2_posts.ID FROM wp_2_posts WHERE x = 0xFF AND y = -3.5" ) );
		self::assertSame( 'SELECT ?', SqlNormalizer::strip( "SELECT 'unterminated FROM t" ) );
		self::assertStringNotContainsString( 'secret', SqlNormalizer::strip( "SELECT 'oops secret" ) );
	}

	public function test_strips_values_from_error_text(): void {
		self::assertSame( "Duplicate entry '?' for key 'user_email'", SqlNormalizer::stripError( "Duplicate entry 'bob@example.com' for key 'user_email'" ) );
		self::assertSame(
			"You have an error in your SQL syntax; check the manual near 'WHERE x = ? AND y = ?' at line 1",
			SqlNormalizer::stripError( "You have an error in your SQL syntax; check the manual near 'WHERE x = 'abc' AND y = 5' at line 1" )
		);
		self::assertSame( "Table 'wp.wp_missing' doesn't exist", SqlNormalizer::stripError( "Table 'wp.wp_missing' doesn't exist" ), 'identifiers stay' );
	}

	public function test_db_event_and_caller(): void {
		$event = DbErrorListener::buildEvent( "Unknown column 'foo' in 'field list'", "SELECT foo FROM wp_posts WHERE post_title = 'Secret plan'", 'wpdb::get_results' );
		self::assertSame( 'db.error', $event->getAction() );
		self::assertSame( 'critical', $event->getSeverity()->value );
		self::assertSame( 'SELECT foo FROM wp_posts WHERE post_title = ?', $event->getContext()['query'] );
		self::assertStringNotContainsString( 'Secret', (string) json_encode( $event->toArray() ) );
		$other = DbErrorListener::buildEvent( "Unknown column 'foo' in 'field list'", "SELECT foo FROM wp_posts WHERE post_title = 'Other'", null );
		self::assertSame( $event->getFingerprint(), $other->getFingerprint(), 'literal values do not split fingerprints' );

		self::assertSame( 'wpdb::get_results', DbErrorListener::caller( '$db->get_results("SELECT \'x\'")' ) );
		self::assertSame(
			"WP_Query->get_posts > wpdb->get_results > include() > wpdb::query",
			DbErrorListener::caller( '$db->query("x")', [ [ 'SELECT 1', "require('wp-blog-header.php'), WP_Query->get_posts, wpdb->get_results, include('/srv/a.php')", 0.1 ] ] )
		);
		self::assertNull( DbErrorListener::caller( '' ) );
	}
}
