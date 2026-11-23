<?php
/**
 * DbErrorListener ignore-list tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Errors;

use Brain\Monkey\Filters;
use Noformat\Logger\Config;
use Noformat\Logger\Context\RequestContext;
use Noformat\Logger\Errors\DbErrorListener;
use Noformat\Logger\Logger;
use Noformat\Logger\Privacy\Redactor;
use Noformat\Logger\Storage\EventRepository;
use Noformat\Logger\Storage\FingerprintStore;
use Noformat\Logger\Storage\Meta;
use Noformat\Logger\Tests\TestCase;

final class DbErrorListenerTest extends TestCase {

	/** WP Rocket's beacon table refusing a scanner URL, as seen on a WP Engine site. */
	private const ROCKET_ERROR = 'WordPress database error: Processing the value for the following field failed: url. The supplied value may be too long or contains invalid data.';
	private const ROCKET_QUERY = 'SHOW FULL COLUMNS FROM `wp_wpr_preconnect_external_domains` /* From [example.com/wp-admin/admin-ajax.php] in [/nas/content/live/example/wp-content/plugins/wp-rocket/inc/Dependencies/BerlinDB/Database/Query.php:2004] */';

	private mixed $previousWpdb = null;

	protected function setUp(): void {
		parent::setUp();
		$this->previousWpdb = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previousWpdb;
		parent::tearDown();
	}

	/**
	 * Run the listener against a wpdb stub; returns the number of events buffered.
	 *
	 * @param string               $error    $wpdb->last_error.
	 * @param string               $query    $wpdb->last_query.
	 * @param array<string, mixed> $settings Config settings.
	 */
	private function logged( string $error, string $query, array $settings = [] ): int {
		$GLOBALS['wpdb'] = (object) [
			'last_error' => $error,
			'last_query' => $query,
			'prefix'     => 'wp_',
			'func_call'  => '$db->query("' . $query . '")',
		];
		$config = new Config( $settings );
		$meta   = new Meta();
		$logger = new Logger(
			$config,
			new RequestContext( null, 'test-salt' ),
			new Redactor( $config->sensitiveKeys(), $config->safeKeys(), 'test-salt' ),
			new EventRepository( $meta ),
			new FingerprintStore( $config ),
			$meta
		);
		$listener = new DbErrorListener();
		$listener->register( $logger, $config );
		$listener->check();
		return $logger->pending();
	}

	public function test_wp_rocket_refusing_a_visitor_url_is_not_logged(): void {
		self::assertSame( 0, $this->logged( self::ROCKET_ERROR, self::ROCKET_QUERY ) );
		self::assertSame( 0, $this->logged( self::ROCKET_ERROR, 'INSERT INTO `wp_wpr_above_the_fold` (`url`, `is_mobile`) VALUES (\'http://x\', 0)' ), 'any wpr_* table' );
	}

	public function test_other_database_errors_are_still_logged(): void {
		self::assertSame( 1, $this->logged( "Unknown column 'foo' in 'field list'", 'SELECT foo FROM `wp_wpr_preconnect_external_domains`' ), 'a real SQL error on a WP Rocket table' );
		self::assertSame( 1, $this->logged( self::ROCKET_ERROR, 'SHOW FULL COLUMNS FROM `wp_posts`' ), 'a value refused by a core table' );
		self::assertSame( 1, $this->logged( self::ROCKET_ERROR, '' ), 'no query to tell where it came from' );
	}

	public function test_ignore_list_is_a_setting_and_a_filter(): void {
		self::assertSame( 1, $this->logged( self::ROCKET_ERROR, self::ROCKET_QUERY, [ 'ignored_db_errors' => [] ] ), 'emptied by setting' );

		Filters\expectApplied( 'noformat_logger/ignored_db_errors' )->andReturn( [ '/^Table \'\S+\' doesn\'t exist/' ] );
		self::assertSame( 0, $this->logged( "Table 'db.wp_legacy' doesn't exist", 'SELECT * FROM wp_legacy' ), 'regex entry from the filter' );
	}

	public function test_config_matches_stored_error_and_query(): void {
		$config = new Config();
		self::assertTrue( $config->isIgnoredDbError( self::ROCKET_ERROR, self::ROCKET_QUERY ) );
		self::assertFalse( $config->isIgnoredDbError( self::ROCKET_ERROR, 'SHOW FULL COLUMNS FROM `wp_options`' ) );
		self::assertFalse( $config->isIgnoredDbError( 'Deadlock found when trying to get lock', 'UPDATE `wp_wpr_rocket_cache` SET status = ?' ) );
	}
}
