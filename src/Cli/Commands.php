<?php
/**
 * WP-CLI commands.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Cli;

use Noformat\Logger\Category;
use Noformat\Logger\Cron\Scheduler;
use Noformat\Logger\Event;
use Noformat\Logger\Plugin;
use Noformat\Logger\Rest\Controller;
use Noformat\Logger\Rest\KeyStore;
use Noformat\Logger\Severity;
use Noformat\Logger\Storage\EventRepository;
use Noformat\Logger\Storage\Meta;
use Noformat\Logger\Update\Updater;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Inspect and operate the Noformat Logbook.
 */
final class Commands {

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {
	}

	/**
	 * Register `wp nflog`.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function register( Plugin $plugin ): void {
		WP_CLI::add_command( 'nflog', new self( $plugin ) );
	}

	/**
	 * Show plugin, storage, key and module status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param list<string>          $args  Positional.
	 * @param array<string, string> $assoc Options.
	 */
	public function status( array $args, array $assoc ): void {
		unset( $args );
		$manifest              = $this->plugin->manifest();
		$manifest['cron_next'] = Scheduler::nextRuns();
		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		$rows = [
			[
				'field' => 'version',
				'value' => $manifest['producer']['version'],
			],
			[
				'field' => 'schema',
				'value' => $manifest['schema']['database'] . ' (expected ' . $manifest['schema']['expected'] . ')',
			],
			[
				'field' => 'storage',
				'value' => $manifest['storage']['available'] ? 'available' : 'UNAVAILABLE',
			],
			[
				'field' => 'events',
				'value' => (string) $manifest['storage']['events'],
			],
			[
				'field' => 'chain_head',
				'value' => $manifest['chain_head']['id'] . ':' . $manifest['chain_head']['hash'],
			],
			[
				'field' => 'ack_cursor',
				'value' => (string) $manifest['ack_cursor'],
			],
			[
				'field' => 'last_pull',
				'value' => is_array( $manifest['last_pull'] ) ? gmdate( 'c', (int) $manifest['last_pull']['at'] ) : 'never',
			],
			[
				'field' => 'failures',
				'value' => (string) $manifest['failures']['count'],
			],
			[
				'field' => 'key_id',
				'value' => (string) ( $manifest['key']['current_id'] ?? '-' ),
			],
			[
				'field' => 'key_source',
				'value' => $manifest['key']['source'],
			],
		];
		foreach ( $manifest['cron_next'] as $hook => $next ) {
			$rows[] = [
				'field' => 'cron ' . $hook,
				'value' => null === $next ? 'not scheduled' : gmdate( 'c', $next ),
			];
		}
		foreach ( $manifest['modules'] as $slug => $status ) {
			$state  = $status['registered'] ? 'registered' : ( $status['enabled'] ? ( $status['available'] ? 'error' : 'unavailable' ) : 'disabled' );
			$rows[] = [
				'field' => 'module ' . $slug,
				'value' => $state,
			];
		}
		WP_CLI\Utils\format_items( 'table', $rows, [ 'field', 'value' ] );
	}

	/**
	 * Manage the API key.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : generate, rotate or revoke.
	 * ---
	 * options:
	 *   - generate
	 *   - rotate
	 *   - revoke
	 * ---
	 *
	 * [--print-config]
	 * : Print the two define() lines for wp-config.php. With constants already set, generates a fresh pair without storing it.
	 *
	 * [--force]
	 * : generate: replace existing keys immediately (no grace period).
	 *
	 * [--all]
	 * : revoke: also revoke the current key.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nflog key generate --print-config
	 *     wp nflog key rotate
	 *
	 * @param list<string>               $args  Positional.
	 * @param array<string, string|bool> $assoc Options.
	 */
	public function key( array $args, array $assoc ): void {
		$action = $args[0] ?? '';
		$keys   = $this->plugin->keys();
		$print  = (bool) WP_CLI\Utils\get_flag_value( $assoc, 'print-config', false );
		try {
			if ( $keys->constantsDefined() ) {
				if ( 'generate' === $action && $print ) {
					$pair = KeyStore::newPair();
					WP_CLI::warning( 'Constants manage the key; this pair is NOT stored. Put it in wp-config.php to rotate:' );
					WP_CLI::line( KeyStore::configLines( $pair['id'], $pair['secret'] ) );
					WP_CLI::line( "define( 'NOFORMAT_LOGGER_PREVIOUS_KEY_ID', '" . (string) $keys->currentId() . "' ); // optional, during the switch" );
					return;
				}
				WP_CLI::error( 'The key is managed by wp-config.php constants. Use `wp nflog key generate --print-config` to create a new pair.' );
			}
			switch ( $action ) {
				case 'generate':
					$pair = $keys->generate( (bool) WP_CLI\Utils\get_flag_value( $assoc, 'force', false ) );
					$this->plugin->logKeyChange( 'generated', $pair['id'] );
					$this->printPair( $pair, $print );
					break;
				case 'rotate':
					$pair = $keys->rotate();
					$this->plugin->logKeyChange( 'rotated', $pair['id'], $pair['previous_id'] );
					$this->printPair( $pair, $print );
					if ( null !== $pair['previous_id'] ) {
						WP_CLI::line( 'Previous key ' . $pair['previous_id'] . ' stays valid for 24 hours.' );
					}
					break;
				case 'revoke':
					$all    = (bool) WP_CLI\Utils\get_flag_value( $assoc, 'all', false );
					$before = $keys->describe();
					$keys->revoke( $all );
					$this->plugin->logKeyChange( 'revoked', $all ? $before['id'] : $before['previous_id'], $all ? $before['previous_id'] : null, $all );
					WP_CLI::success( 'Revoked.' );
					break;
				default:
					WP_CLI::error( 'Unknown action. Use generate, rotate or revoke.' );
			}
		} catch ( \RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Print the most recent events.
	 *
	 * ## OPTIONS
	 *
	 * [--lines=<n>]
	 * : Number of events.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--follow]
	 * : Keep polling for new events.
	 *
	 * [--severity=<severity>]
	 * : Minimum severity (critical, error, warning, notice, info, debug).
	 *
	 * [--category=<category>]
	 * : Comma separated categories.
	 *
	 * @param list<string>               $args  Positional.
	 * @param array<string, string|bool> $assoc Options.
	 */
	public function tail( array $args, array $assoc ): void {
		unset( $args );
		$filters = $this->filters( $assoc );
		$repo    = $this->plugin->repository();
		$last    = 0;
		foreach ( $repo->latest( max( 1, (int) ( $assoc['lines'] ?? 20 ) ), $filters ) as $row ) {
			$this->printRow( $row );
			$last = (int) $row['id'];
		}
		if ( ! WP_CLI\Utils\get_flag_value( $assoc, 'follow', false ) ) {
			return;
		}
		while ( true ) { // @phpstan-ignore while.alwaysTrue
			sleep( 2 );
			$result = $repo->after( $last, 500, $filters );
			foreach ( $result['rows'] as $row ) {
				$this->printRow( $row );
				$last = (int) $row['id'];
			}
		}
	}

	/**
	 * List events after a cursor.
	 *
	 * ## OPTIONS
	 *
	 * [--after=<id>]
	 * : Exclusive cursor.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--limit=<n>]
	 * : Max 500.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--severity=<severity>]
	 * : Minimum severity.
	 *
	 * [--category=<category>]
	 * : Comma separated categories.
	 *
	 * [--format=<format>]
	 * : Output format. "json" prints the contract JSON (WP-CLI turns a bare --json into --format=json).
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * @param list<string>               $args  Positional.
	 * @param array<string, string|bool> $assoc Options.
	 */
	public function events( array $args, array $assoc ): void {
		unset( $args );
		$result = $this->plugin->repository()->after( (int) ( $assoc['after'] ?? 0 ), (int) ( $assoc['limit'] ?? 50 ), $this->filters( $assoc ) );
		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			$events = array_map( static fn( array $row ) => EventRepository::rowToArray( $row, home_url() ), $result['rows'] );
			WP_CLI::line( (string) wp_json_encode( $events, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		foreach ( $result['rows'] as $row ) {
			$this->printRow( $row );
		}
		if ( $result['has_more'] ) {
			WP_CLI::line( '… more events available' );
		}
	}

	/**
	 * Prune old events (same rules as the daily cron).
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Override retention_days for this run.
	 *
	 * [--dry-run]
	 * : Only report what would be deleted.
	 *
	 * @param list<string>               $args  Positional.
	 * @param array<string, string|bool> $assoc Options.
	 */
	public function prune( array $args, array $assoc ): void {
		unset( $args );
		$config = $this->plugin->config();
		$result = $this->plugin->repository()->prune(
			isset( $assoc['days'] ) ? max( 0, (int) $assoc['days'] ) : $config->int( 'retention_days' ),
			$config->int( 'hard_max_days' ),
			$config->int( 'max_rows' ),
			$this->plugin->meta()->getInt( Meta::ACK_CURSOR ),
			(bool) WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', false )
		);
		WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT ) );
		WP_CLI::success( sprintf( '%s %d events (through #%d).', $result['dry_run'] ? 'Would delete' : 'Deleted', $result['rows'], $result['boundary_id'] ) );
	}

	/**
	 * Recompute the hash chain.
	 *
	 * ## OPTIONS
	 *
	 * [--from=<id>]
	 * : First id.
	 *
	 * [--to=<id>]
	 * : Last id.
	 *
	 * @param list<string>               $args  Positional.
	 * @param array<string, string|bool> $assoc Options.
	 */
	public function verify( array $args, array $assoc ): void {
		unset( $args );
		$from   = isset( $assoc['from'] ) ? (int) $assoc['from'] : null;
		$to     = isset( $assoc['to'] ) ? (int) $assoc['to'] : null;
		$result = $this->plugin->repository()->verify( $from, $to, PHP_INT_MAX );
		WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		if ( ! $result['ok'] ) {
			WP_CLI::error( 'Hash chain break detected.' );
		}
		WP_CLI::success( sprintf( 'Chain intact: %d events verified.', $result['checked'] ) );
	}

	/**
	 * Run the core/plugin integrity check and verify the recent hash chain.
	 *
	 * Prints the result as JSON. Exits non-zero when core or plugin files do not
	 * match their checksums or the hash chain is broken, so it can gate CI and
	 * monitoring.
	 *
	 * ## OPTIONS
	 *
	 * [--[no-]fail]
	 * : Exit non-zero on a problem (default). --no-fail always exits 0 and only prints the result.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nflog integrity
	 *     wp nflog integrity --no-fail
	 *
	 * @param list<string>               $args  Positional.
	 * @param array<string, string|bool> $assoc Options.
	 */
	public function integrity( array $args, array $assoc ): void {
		unset( $args );
		$result = $this->plugin->integrity()->run();
		WP_CLI::line( (string) wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$problems = self::integrityProblems( $result );
		if ( [] === $problems ) {
			WP_CLI::success( 'Integrity check passed.' );
			return;
		}
		// WP-CLI turns --no-fail into fail=false.
		$fail = (bool) WP_CLI\Utils\get_flag_value( $assoc, 'fail', true );
		if ( $fail ) {
			WP_CLI::error( 'Integrity problems: ' . implode( ', ', $problems ) . '.' );
		}
		WP_CLI::warning( 'Integrity problems: ' . implode( ', ', $problems ) . '.' );
	}

	/**
	 * Failing parts of an integrity result.
	 *
	 * @param array<string, mixed> $result IntegrityCheck::run() result.
	 * @return list<string> e.g. ["core mismatch", "chain break"].
	 */
	public static function integrityProblems( array $result ): array {
		$problems = [];
		if ( 'mismatch' === ( $result['core']['status'] ?? null ) ) {
			$problems[] = 'core mismatch';
		}
		if ( 'mismatch' === ( $result['plugins']['status'] ?? null ) ) {
			$problems[] = 'plugin mismatch';
		}
		if ( isset( $result['chain'] ) && is_array( $result['chain'] ) && false === ( $result['chain']['ok'] ?? null ) ) {
			$problems[] = 'chain break';
		}
		return $problems;
	}

	/**
	 * Print the health snapshot (same JSON as GET /v1/health).
	 *
	 * @param list<string>               $args  Positional.
	 * @param array<string, string|bool> $assoc Options.
	 */
	public function health( array $args, array $assoc ): void {
		unset( $args, $assoc );
		WP_CLI::line( (string) wp_json_encode( $this->plugin->health()->snapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Check for a plugin update now and print the result (as on the Status tab).
	 *
	 * Forgets the cached release, asks GitHub (or NOFORMAT_LOGGER_UPDATE_MANIFEST)
	 * once and refreshes WordPress' plugin update list. A failed check is a
	 * warning, never an error: the site keeps working without updates.
	 *
	 * ## OPTIONS
	 *
	 * [--cached]
	 * : Print the cached answer without checking.
	 *
	 * [--format=<format>]
	 * : line or json.
	 * ---
	 * default: line
	 * options:
	 *   - line
	 *   - json
	 * ---
	 *
	 * @subcommand update-check
	 *
	 * @param list<string>               $args  Positional.
	 * @param array<string, string|bool> $assoc Options.
	 */
	public function updateCheck( array $args, array $assoc ): void {
		unset( $args );
		$updater = new Updater( $this->plugin );
		$client  = $updater->client();
		$state   = ! empty( $assoc['cached'] ) ? ( $client->cached() ?? [] ) : $updater->checkNow();
		$summary = Updater::summary( $state, NOFORMAT_LOGGER_VERSION, $client->sourceLabel() );
		if ( 'json' === ( $assoc['format'] ?? 'line' ) ) {
			WP_CLI::line( (string) wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		WP_CLI::line( Updater::line( $summary, 0 === $summary['checked_at'] ? 'never' : gmdate( 'Y-m-d H:i:s', $summary['checked_at'] ) . ' UTC' ) );
		if ( '' !== $summary['error'] ) {
			WP_CLI::warning( 'Update check failed: ' . $summary['error'] );
		} elseif ( $summary['update_available'] ) {
			WP_CLI::log( 'Run `wp plugin update nf-logbook` to install ' . $summary['latest'] . '.' );
		}
	}

	/**
	 * Record a deploy marker.
	 *
	 * ## OPTIONS
	 *
	 * <label>
	 * : Free text, e.g. "Release 2.3".
	 *
	 * [--ref=<ref>]
	 * : Git ref or tag.
	 *
	 * @param list<string>               $args  Positional.
	 * @param array<string, string|bool> $assoc Options.
	 */
	public function marker( array $args, array $assoc ): void {
		$label = trim( sanitize_text_field( (string) ( $args[0] ?? '' ) ) );
		if ( '' === $label ) {
			WP_CLI::error( 'A label is required.' );
		}
		$ref    = isset( $assoc['ref'] ) ? substr( sanitize_text_field( (string) $assoc['ref'] ), 0, 64 ) : null;
		$stored = $this->plugin->logger()->logNow( Controller::markerEvent( $label, $ref ) );
		$this->reportStored( $stored );
	}

	/**
	 * Write a test event.
	 *
	 * ## OPTIONS
	 *
	 * [--severity=<severity>]
	 * : Severity.
	 * ---
	 * default: info
	 * ---
	 *
	 * @subcommand test-event
	 *
	 * @param list<string>               $args  Positional.
	 * @param array<string, string|bool> $assoc Options.
	 */
	public function testEvent( array $args, array $assoc ): void {
		unset( $args );
		$severity = Severity::parse( (string) ( $assoc['severity'] ?? 'info' ) ) ?? Severity::INFO;
		$stored   = $this->plugin->logger()->logNow(
			Event::make( Category::SYSTEM, 'logger.test_event', $severity )
				->summary( 'Test event from WP-CLI' )
				->context( [ 'php' => PHP_VERSION ] )
		);
		$this->reportStored( $stored );
	}

	/**
	 * Copy the early error loader into wp-content/mu-plugins (and remove the pre-1.2 noformat-logger-loader.php).
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Overwrite an existing file.
	 *
	 * @subcommand install-mu-loader
	 *
	 * @param list<string>               $args  Positional.
	 * @param array<string, string|bool> $assoc Options.
	 */
	public function installMuLoader( array $args, array $assoc ): void {
		unset( $args );
		$source = NOFORMAT_LOGGER_DIR . 'mu-loader/nf-logbook-loader.php';
		$dir    = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
		$target = rtrim( $dir, '/' ) . '/nf-logbook-loader.php';
		if ( file_exists( $target ) && ! WP_CLI\Utils\get_flag_value( $assoc, 'force', false ) ) {
			WP_CLI::error( $target . ' exists; use --force to overwrite.' );
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			WP_CLI::error( 'Cannot create ' . $dir );
		}
		if ( ! copy( $source, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- CLI context, explicit operator action.
			WP_CLI::error( 'Copy failed.' );
		}
		// The loader was called noformat-logger-loader.php before the rename to Noformat Logbook (1.2.0).
		$legacy = rtrim( $dir, '/' ) . '/noformat-logger-loader.php';
		if ( file_exists( $legacy ) ) {
			wp_delete_file( $legacy ); // Returns a value only from WordPress 6.7: check the file instead.
			clearstatcache( true, $legacy );
			if ( is_file( $legacy ) ) {
				WP_CLI::warning( 'Could not remove the old loader ' . $legacy . '; delete it by hand.' );
			} else {
				WP_CLI::log( 'Removed the old loader ' . $legacy );
			}
		}
		WP_CLI::success( 'Installed ' . $target );
	}

	/**
	 * Print a stored event result.
	 *
	 * @param Event|null $stored Stored event.
	 */
	private function reportStored( ?Event $stored ): void {
		if ( null === $stored ) {
			WP_CLI::error( 'Event was not stored (dropped, aggregated or storage unavailable). See `wp nflog status`.' );
		}
		WP_CLI::success( sprintf( 'Stored event #%d (%s).', (int) $stored->getId(), substr( (string) $stored->getHash(), 0, 16 ) ) );
	}

	/**
	 * Filters from options.
	 *
	 * @param array<string, string|bool> $assoc Options.
	 * @return array<string, mixed>
	 */
	private function filters( array $assoc ): array {
		$filters = [];
		if ( isset( $assoc['severity'] ) ) {
			$severity = Severity::parse( (string) $assoc['severity'] );
			if ( null === $severity ) {
				WP_CLI::error( 'Unknown severity.' );
			}
			$filters['min_severity'] = $severity;
		}
		if ( isset( $assoc['category'] ) ) {
			$filters['categories'] = array_values( array_filter( array_map( 'trim', explode( ',', (string) $assoc['category'] ) ) ) );
		}
		return $filters;
	}

	/**
	 * One line per event.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private function printRow( array $row ): void {
		$event = EventRepository::rowToArray( $row );
		WP_CLI::line(
			sprintf(
				'#%d %s %-8s %-10s %-28s %s%s',
				$event['id'],
				$event['occurred_at'],
				$event['severity'],
				$event['category'],
				$event['action'],
				$event['summary'],
				$event['count'] > 1 ? ' (x' . $event['count'] . ')' : ''
			)
		);
	}

	/**
	 * Print a new key.
	 *
	 * @param array{id: string, secret: string} $pair  Pair.
	 * @param bool                              $printConfig Print config lines.
	 */
	private function printPair( array $pair, bool $printConfig ): void {
		WP_CLI::line( 'Key id: ' . $pair['id'] );
		WP_CLI::line( 'Secret: ' . $pair['secret'] . '   (shown once)' );
		if ( $printConfig ) {
			WP_CLI::line( '' );
			WP_CLI::line( KeyStore::configLines( $pair['id'], $pair['secret'] ) );
		}
		WP_CLI::success( 'Key stored (encrypted). Moving it to wp-config.php constants is recommended.' );
	}
}
