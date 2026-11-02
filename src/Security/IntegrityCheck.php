<?php
/**
 * IntegrityCheck.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Security;

use Noformat\Logger\Category;
use Noformat\Logger\Config;
use Noformat\Logger\Event;
use Noformat\Logger\Listener;
use Noformat\Logger\Logger;
use Noformat\Logger\Plugin;
use Noformat\Logger\Severity;
use Noformat\Logger\Storage\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Core and plugin file integrity (PLAN §4) plus a local hash chain check.
 *
 * Core: MD5 of every file listed by api.wordpress.org/core/checksums for the
 * running version and locale (falling back to en_US), wp-content/ excluded;
 * unknown *.php files under wp-admin/ and wp-includes/ are reported too.
 * The checksum list is cached in meta per version/locale. Work is bounded by a
 * time budget (30 s, filter `noformat_logger/integrity_budget`); an unfinished
 * core pass stores a cursor and resumes on the next run.
 *
 * Plugins: wordpress.org plugins (per the update_plugins transient, else the
 * directory name) are compared against downloads.wordpress.org/plugin-checksums
 * (sha256 preferred, md5 fallback). A 404 is cached per slug@version.
 *
 * Events: one `integrity.mismatch` (critical) per scope (core, plugin:<slug>)
 * when its set of modified/missing/unknown paths changes, listing up to 50
 * paths; one `integrity.mismatch` (info, `state: resolved`) when a scope
 * becomes clean again. `chain.break_detected` (critical) once per break when
 * the last 1 000 stored events fail verification.
 *
 * Only ever runs from cron or WP-CLI (Scheduler, `wp nflog integrity`).
 */
final class IntegrityCheck implements Listener {

	public const CORE_CACHE_KEY   = 'integrity_core_checksums';
	public const PROGRESS_KEY     = 'integrity_progress';
	public const STATE_KEY        = 'integrity_state';
	public const LAST_KEY         = 'integrity_last';
	public const PLUGIN_NA_KEY    = 'integrity_plugin_unavailable';
	public const CHAIN_REPORT_KEY = 'chain_break_reported';

	public const MAX_PATHS    = 50;
	public const CHAIN_WINDOW = 1000;

	private const CORE_API   = 'https://api.wordpress.org/core/checksums/1.0/';
	private const PLUGIN_API = 'https://downloads.wordpress.org/plugin-checksums/';

	private ?Logger $logger = null;

	/**
	 * Availability.
	 */
	public static function isAvailable(): bool {
		return true;
	}

	/**
	 * No request-time hooks (cron and WP-CLI only).
	 *
	 * @param Logger $logger Logger.
	 * @param Config $config Config.
	 */
	public function register( Logger $logger, Config $config ): void {
		unset( $config );
		$this->logger = $logger;
	}

	/**
	 * Run the check.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$budget = 30;
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Time budget of one integrity run in seconds.
			 *
			 * @param int $budget Seconds (default 30).
			 */
			$budget = (int) apply_filters( 'noformat_logger/integrity_budget', $budget );
		}
		$deadline = microtime( true ) + max( 1, $budget );

		$core    = $this->checkCore( $deadline );
		$plugins = 'partial' === $core['status']
			? [
				'status'     => 'deferred',
				'checked'    => 0,
				'verified'   => [],
				'skipped'    => [],
				'mismatches' => [],
			]
			: $this->checkPlugins( $deadline );
		$chain   = $this->checkChain();

		$this->emit( $core, $plugins );

		$result = [
			'implemented' => true,
			'ran_at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'core'        => $core,
			'plugins'     => $plugins,
			'chain'       => $chain,
		];
		try {
			$this->meta()?->setJson( self::LAST_KEY, self::summarize( $result ) );
		} catch ( \Throwable $e ) {
			$this->logger()?->recordFailure( 'integrity.save', $e );
		}
		$this->logger()?->flush();
		return $result;
	}

	/**
	 * Last run summary (for the health snapshot).
	 *
	 * @return array<string|int, mixed>|null
	 */
	public function lastSummary(): ?array {
		try {
			return $this->meta()?->getJson( self::LAST_KEY );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Compare files under a root against expected checksums (pure apart from reading files).
	 *
	 * @param string                             $root      Root directory.
	 * @param array<string, string|list<string>> $checksums Relative path => expected hash(es).
	 * @param string                             $algo      md5|sha256.
	 * @param float|null                         $deadline  microtime() deadline.
	 * @param int                                $offset    Resume offset (index into the sorted path list).
	 * @param list<string>                       $skip      Path prefixes to skip.
	 * @return array{checked: int, modified: list<string>, missing: list<string>, next_offset: int|null}
	 */
	public static function compare( string $root, array $checksums, string $algo, ?float $deadline = null, int $offset = 0, array $skip = [] ): array {
		$root  = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
		$paths = array_keys( $checksums );
		sort( $paths, SORT_STRING );
		$out   = [
			'checked'     => 0,
			'modified'    => [],
			'missing'     => [],
			'next_offset' => null,
		];
		$total = count( $paths );
		for ( $i = $offset; $i < $total; $i++ ) {
			if ( null !== $deadline && 0 === $i % 50 && microtime( true ) > $deadline ) {
				$out['next_offset'] = $i;
				break;
			}
			$path = (string) $paths[ $i ];
			foreach ( $skip as $prefix ) {
				if ( str_starts_with( $path, $prefix ) ) {
					continue 2;
				}
			}
			if ( str_contains( $path, '..' ) ) {
				continue;
			}
			++$out['checked'];
			$file = $root . $path;
			if ( ! is_file( $file ) ) {
				$out['missing'][] = $path;
				continue;
			}
			$actual   = hash_file( $algo, $file );
			$expected = array_map( 'strtolower', array_map( 'strval', (array) $checksums[ $path ] ) );
			if ( false === $actual || ! in_array( strtolower( $actual ), $expected, true ) ) {
				$out['modified'][] = $path;
			}
		}
		return $out;
	}

	/**
	 * *.php files under the given directories that are not in the checksum list.
	 *
	 * @param string               $root      Root.
	 * @param array<string, mixed> $checksums Relative path => hash.
	 * @param list<string>         $dirs      Relative directories to scan recursively.
	 * @return array{paths: list<string>, total: int}
	 */
	public static function unknownFiles( string $root, array $checksums, array $dirs ): array {
		$root  = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
		$paths = [];
		$total = 0;
		foreach ( $dirs as $dir ) {
			$base = $root . trim( $dir, '/' );
			if ( ! is_dir( $base ) ) {
				continue;
			}
			try {
				$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ) );
				foreach ( $iterator as $file ) {
					if ( ! $file instanceof \SplFileInfo || ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
						continue;
					}
					$relative = substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $root ) );
					if ( ! isset( $checksums[ $relative ] ) ) {
						++$total;
						if ( count( $paths ) < self::MAX_PATHS ) {
							$paths[] = $relative;
						}
					}
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}
		sort( $paths, SORT_STRING );
		return [
			'paths' => $paths,
			'total' => $total,
		];
	}

	/**
	 * Expected hashes from a plugin-checksums file entry list (sha256 preferred).
	 *
	 * @param array<string, mixed> $files "files" map of the plugin checksum JSON.
	 * @return array{algo: string, checksums: array<string, list<string>>}
	 */
	public static function pluginChecksums( array $files ): array {
		$sha = [];
		$md5 = [];
		foreach ( $files as $path => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( isset( $entry['sha256'] ) ) {
				$sha[ (string) $path ] = array_values( array_map( 'strval', (array) $entry['sha256'] ) );
			}
			if ( isset( $entry['md5'] ) ) {
				$md5[ (string) $path ] = array_values( array_map( 'strval', (array) $entry['md5'] ) );
			}
		}
		if ( [] !== $sha && count( $sha ) >= count( $md5 ) ) {
			return [
				'algo'      => 'sha256',
				'checksums' => $sha,
			];
		}
		return [
			'algo'      => 'md5',
			'checksums' => $md5,
		];
	}

	/**
	 * Per-scope digests of a result (pure): scope => {digest, paths, total}.
	 *
	 * @param array<string, mixed> $core    Core result.
	 * @param array<string, mixed> $plugins Plugins result.
	 * @return array<string, array{digest: string, paths: list<string>, total: int}>
	 */
	public static function scopes( array $core, array $plugins ): array {
		$out = [];
		if ( in_array( $core['status'] ?? '', [ 'ok', 'mismatch' ], true ) ) {
			$paths       = array_merge(
				array_map( static fn( $p ) => 'modified:' . $p, (array) ( $core['modified'] ?? [] ) ),
				array_map( static fn( $p ) => 'missing:' . $p, (array) ( $core['missing'] ?? [] ) ),
				array_map( static fn( $p ) => 'unknown:' . $p, (array) ( $core['unknown'] ?? [] ) )
			);
			$out['core'] = self::scope( $paths );
		}
		foreach ( (array) ( $plugins['verified'] ?? [] ) as $slug ) {
			$out[ 'plugin:' . $slug ] = self::scope( [] );
		}
		foreach ( (array) ( $plugins['mismatches'] ?? [] ) as $slug => $mismatch ) {
			$paths                    = array_merge(
				array_map( static fn( $p ) => 'modified:' . $p, (array) ( $mismatch['modified'] ?? [] ) ),
				array_map( static fn( $p ) => 'missing:' . $p, (array) ( $mismatch['missing'] ?? [] ) )
			);
			$out[ 'plugin:' . $slug ] = self::scope( $paths );
		}
		return $out;
	}

	/**
	 * Changes between stored scope digests and current scopes (pure).
	 * Scopes not checked this run keep their stored digest.
	 *
	 * @param array<string|int, mixed>                                              $stored  scope => digest ('' = clean).
	 * @param array<string, array{digest: string, paths: list<string>, total: int}> $current Current scopes.
	 * @return list<array{scope: string, state: string, paths: list<string>, total: int}>
	 */
	public static function changes( array $stored, array $current ): array {
		$out = [];
		foreach ( $current as $scope => $info ) {
			$before = isset( $stored[ $scope ] ) ? (string) $stored[ $scope ] : '';
			if ( $before === $info['digest'] ) {
				continue;
			}
			$out[] = [
				'scope' => $scope,
				'state' => '' === $info['digest'] ? 'resolved' : ( '' === $before ? 'detected' : 'changed' ),
				'paths' => array_slice( $info['paths'], 0, self::MAX_PATHS ),
				'total' => $info['total'],
			];
		}
		return $out;
	}

	/**
	 * Event for one scope change (pure).
	 *
	 * @param array{scope: string, state: string, paths: list<string>, total: int} $change Change.
	 */
	public static function eventFor( array $change ): Event {
		$resolved = 'resolved' === $change['state'];
		$isCore   = 'core' === $change['scope'];
		$slug     = $isCore ? null : substr( $change['scope'], 7 );
		$event    = Event::make( Category::SECURITY, 'integrity.mismatch', $resolved ? Severity::INFO : Severity::CRITICAL )
			->object( $isCore ? 'core' : 'plugin', $isCore ? 'WordPress' : $slug )
			->summary(
				$resolved
					? sprintf( 'File integrity restored: %s', $isCore ? 'WordPress core' : 'plugin ' . $slug )
					: sprintf( 'File integrity mismatch in %s: %d file(s)', $isCore ? 'WordPress core' : 'plugin ' . $slug, $change['total'] )
			)
			->context(
				[
					'scope'      => $change['scope'],
					'state'      => $change['state'],
					'paths'      => $change['paths'],
					'path_count' => $change['total'],
				]
			);
		return $event;
	}

	/**
	 * Scope from a path list.
	 *
	 * @param list<string> $paths Prefixed paths.
	 * @return array{digest: string, paths: list<string>, total: int}
	 */
	private static function scope( array $paths ): array {
		sort( $paths, SORT_STRING );
		return [
			'digest' => [] === $paths ? '' : substr( hash( 'sha256', implode( "\n", $paths ) ), 0, 32 ),
			'paths'  => $paths,
			'total'  => count( $paths ),
		];
	}

	/**
	 * Core check with resume.
	 *
	 * @param float $deadline Deadline.
	 * @return array<string, mixed>
	 */
	private function checkCore( float $deadline ): array {
		global $wp_version;
		$version = is_string( $wp_version ) ? $wp_version : '';
		$locale  = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
		$result  = [
			'status'        => 'unavailable',
			'version'       => $version,
			'locale'        => $locale,
			'checked'       => 0,
			'modified'      => [],
			'missing'       => [],
			'unknown'       => [],
			'unknown_total' => 0,
		];
		$list    = $this->coreChecksums( $version, $locale );
		if ( null === $list ) {
			$result['error'] = 'checksums_unavailable';
			return $result;
		}
		$result['locale'] = $list['locale'];
		$checksums        = $list['checksums'];
		$meta             = $this->meta();
		$progress         = null;
		try {
			$progress = $meta?->getJson( self::PROGRESS_KEY );
		} catch ( \Throwable $e ) {
			$progress = null;
		}
		$offset   = 0;
		$modified = [];
		$missing  = [];
		$checked  = 0;
		if ( is_array( $progress ) && ( $progress['version'] ?? '' ) === $version && ( $progress['locale'] ?? '' ) === $list['locale'] ) {
			$offset   = (int) ( $progress['offset'] ?? 0 );
			$modified = array_values( array_map( 'strval', (array) ( $progress['modified'] ?? [] ) ) );
			$missing  = array_values( array_map( 'strval', (array) ( $progress['missing'] ?? [] ) ) );
			$checked  = (int) ( $progress['checked'] ?? 0 );
		}
		$pass     = self::compare( ABSPATH, $checksums, 'md5', $deadline, $offset, [ 'wp-content/' ] );
		$modified = array_merge( $modified, $pass['modified'] );
		$missing  = array_merge( $missing, $pass['missing'] );
		$checked += $pass['checked'];

		if ( null !== $pass['next_offset'] ) {
			try {
				$meta?->setJson(
					self::PROGRESS_KEY,
					[
						'version'  => $version,
						'locale'   => $list['locale'],
						'offset'   => $pass['next_offset'],
						'checked'  => $checked,
						'modified' => $modified,
						'missing'  => $missing,
					]
				);
			} catch ( \Throwable $e ) {
				$this->logger()?->recordFailure( 'integrity.progress', $e );
			}
			return array_merge(
				$result,
				[
					'status'      => 'partial',
					'checked'     => $checked,
					'modified'    => array_slice( $modified, 0, self::MAX_PATHS ),
					'missing'     => array_slice( $missing, 0, self::MAX_PATHS ),
					'next_offset' => $pass['next_offset'],
					'total'       => count( $checksums ),
				]
			);
		}
		try {
			$meta?->delete( self::PROGRESS_KEY );
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		$unknown = self::unknownFiles( ABSPATH, $checksums, [ 'wp-admin', 'wp-includes' ] );
		sort( $modified, SORT_STRING );
		sort( $missing, SORT_STRING );
		$clean = [] === $modified && [] === $missing && 0 === $unknown['total'];
		return array_merge(
			$result,
			[
				'status'         => $clean ? 'ok' : 'mismatch',
				'checked'        => $checked,
				'modified'       => array_slice( $modified, 0, self::MAX_PATHS ),
				'missing'        => array_slice( $missing, 0, self::MAX_PATHS ),
				'modified_total' => count( $modified ),
				'missing_total'  => count( $missing ),
				'unknown'        => $unknown['paths'],
				'unknown_total'  => $unknown['total'],
			]
		);
	}

	/**
	 * Core checksum list (meta cache per version + locale).
	 *
	 * @param string $version Version.
	 * @param string $locale  Locale.
	 * @return array{locale: string, checksums: array<string, string>}|null
	 */
	private function coreChecksums( string $version, string $locale ): ?array {
		if ( '' === $version ) {
			return null;
		}
		$meta = $this->meta();
		try {
			$cached = $meta?->getJson( self::CORE_CACHE_KEY );
			if ( is_array( $cached ) && ( $cached['version'] ?? '' ) === $version && in_array( $cached['requested'] ?? '', [ $locale ], true ) && is_array( $cached['checksums'] ?? null ) ) {
				/** Cached map. @var array<string, string> $map */
				$map = $cached['checksums'];
				return [
					'locale'    => (string) $cached['locale'],
					'checksums' => $map,
				];
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		foreach ( array_unique( [ $locale, 'en_US' ] ) as $try ) {
			$url      = add_query_arg(
				[
					'version' => $version,
					'locale'  => $try,
				],
				self::CORE_API
			);
			$response = wp_remote_get( $url, [ 'timeout' => 15 ] );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) || ! is_array( $body['checksums'] ?? null ) || [] === $body['checksums'] ) {
				continue;
			}
			$map = [];
			foreach ( $body['checksums'] as $path => $md5 ) {
				if ( is_string( $md5 ) ) {
					$map[ (string) $path ] = $md5;
				}
			}
			try {
				$meta?->setJson(
					self::CORE_CACHE_KEY,
					[
						'version'   => $version,
						'requested' => $locale,
						'locale'    => $try,
						'checksums' => $map,
					]
				);
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return [
				'locale'    => $try,
				'checksums' => $map,
			];
		}
		return null;
	}

	/**
	 * Plugin checks.
	 *
	 * @param float $deadline Deadline.
	 * @return array<string, mixed>
	 */
	private function checkPlugins( float $deadline ): array {
		$out = [
			'status'     => 'ok',
			'checked'    => 0,
			'verified'   => [],
			'skipped'    => [],
			'mismatches' => [],
		];
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins   = get_plugins();
		$transient = get_site_transient( 'update_plugins' );
		$meta      = $this->meta();
		$na        = [];
		try {
			$na = $meta?->getJson( self::PLUGIN_NA_KEY ) ?? [];
		} catch ( \Throwable $e ) {
			$na = [];
		}
		$naChanged = false;
		foreach ( $plugins as $file => $data ) {
			$file    = (string) $file;
			$version = (string) ( $data['Version'] ?? '' );
			if ( ! str_contains( $file, '/' ) || '' === $version ) {
				continue;
			}
			$slug = self::wporgSlug( $file, $transient );
			if ( null === $slug ) {
				$out['skipped'][] = dirname( $file );
				continue;
			}
			if ( microtime( true ) > $deadline ) {
				$out['status']    = 'partial';
				$out['skipped'][] = $slug;
				continue;
			}
			$key = $slug . '@' . $version;
			if ( isset( $na[ $key ] ) ) {
				$out['skipped'][] = $slug;
				continue;
			}
			$response = wp_remote_get( self::PLUGIN_API . rawurlencode( $slug ) . '/' . rawurlencode( $version ) . '.json', [ 'timeout' => 15 ] );
			if ( is_wp_error( $response ) ) {
				$out['skipped'][] = $slug;
				$out['status']    = 'partial';
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 404 === $code ) {
				$na[ $key ]       = time();
				$naChanged        = true;
				$out['skipped'][] = $slug;
				continue;
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( 200 !== $code || ! is_array( $body ) || ! is_array( $body['files'] ?? null ) ) {
				$out['skipped'][] = $slug;
				continue;
			}
			$list = self::pluginChecksums( $body['files'] );
			$dir  = WP_PLUGIN_DIR . '/' . dirname( $file );
			$pass = self::compare( $dir, $list['checksums'], $list['algo'] );
			++$out['checked'];
			if ( [] === $pass['modified'] && [] === $pass['missing'] ) {
				$out['verified'][] = $slug;
				continue;
			}
			$prefix                     = 'wp-content/plugins/' . dirname( $file ) . '/';
			$out['mismatches'][ $slug ] = [
				'version'  => $version,
				'algo'     => $list['algo'],
				'modified' => array_map( static fn( string $p ): string => $prefix . $p, array_slice( $pass['modified'], 0, self::MAX_PATHS ) ),
				'missing'  => array_map( static fn( string $p ): string => $prefix . $p, array_slice( $pass['missing'], 0, self::MAX_PATHS ) ),
			];
		}
		if ( $naChanged ) {
			try {
				$meta?->setJson( self::PLUGIN_NA_KEY, array_slice( $na, -500, null, true ) );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		$out['skipped'] = array_values( array_unique( $out['skipped'] ) );
		if ( [] !== $out['mismatches'] ) {
			$out['status'] = 'mismatch';
		}
		return $out;
	}

	/**
	 * The wordpress.org slug of a plugin, or null when it is not hosted there.
	 *
	 * @param string $file      Plugin file.
	 * @param mixed  $transient update_plugins transient.
	 */
	private static function wporgSlug( string $file, mixed $transient ): ?string {
		if ( is_object( $transient ) ) {
			foreach ( [ 'response', 'no_update' ] as $group ) {
				$items = isset( $transient->{$group} ) && is_array( $transient->{$group} ) ? $transient->{$group} : [];
				if ( isset( $items[ $file ] ) ) {
					$item = (array) $items[ $file ];
					$id   = (string) ( $item['id'] ?? '' );
					if ( '' !== $id && ! str_starts_with( $id, 'w.org/plugins/' ) ) {
						return null;
					}
					return isset( $item['slug'] ) ? sanitize_key( (string) $item['slug'] ) : dirname( $file );
				}
			}
		}
		$slug = dirname( $file );
		return 'noformat-logger' === $slug ? null : sanitize_key( $slug );
	}

	/**
	 * Verify the most recent events of the local hash chain.
	 *
	 * @return array<string, mixed>|null
	 */
	private function checkChain(): ?array {
		$plugin = Plugin::instance();
		if ( null === $plugin ) {
			return null;
		}
		try {
			$repository = $plugin->repository();
			$stats      = $repository->stats();
			if ( 0 === $stats['count'] ) {
				return [
					'ok'      => true,
					'checked' => 0,
				];
			}
			$from    = max( $stats['min_id'], $stats['max_id'] - self::CHAIN_WINDOW + 1 );
			$result  = $repository->verify( $from, null, self::CHAIN_WINDOW );
			$summary = [
				'ok'      => (bool) $result['ok'],
				'checked' => (int) $result['checked'],
				'from'    => $from,
				'to'      => (int) $result['to'],
				'break'   => is_array( $result['break'] ?? null ) ? [
					'id'     => $result['break']['id'] ?? null,
					'reason' => $result['break']['reason'] ?? null,
				] : null,
			];
			if ( ! $summary['ok'] ) {
				$breakId  = (int) ( $summary['break']['id'] ?? 0 );
				$reported = $plugin->meta()->getInt( self::CHAIN_REPORT_KEY );
				if ( $reported !== $breakId || 0 === $breakId ) {
					$plugin->meta()->set( self::CHAIN_REPORT_KEY, (string) $breakId );
					$this->logger()?->log(
						Event::make( Category::SECURITY, 'chain.break_detected', Severity::CRITICAL )
							->object( 'event', $breakId > 0 ? $breakId : null )
							->summary( sprintf( 'Hash chain break detected at event #%d (%s)', $breakId, (string) ( $summary['break']['reason'] ?? 'unknown' ) ) )
							->context(
								[
									'from'     => $from,
									'to'       => $summary['to'],
									'break_id' => $breakId,
									'reason'   => $summary['break']['reason'] ?? null,
									'checked'  => $summary['checked'],
								]
							)
							->fingerprint( 'chain.break_detected', $breakId )
					);
				}
			}
			return $summary;
		} catch ( \Throwable $e ) {
			$this->logger()?->recordFailure( 'integrity.chain', $e );
			return null;
		}
	}

	/**
	 * Emit per-scope state changes and persist the digests.
	 *
	 * @param array<string, mixed> $core    Core.
	 * @param array<string, mixed> $plugins Plugins.
	 */
	private function emit( array $core, array $plugins ): void {
		$meta   = $this->meta();
		$logger = $this->logger();
		if ( null === $meta || null === $logger ) {
			return;
		}
		try {
			$stored  = $meta->getJson( self::STATE_KEY ) ?? [];
			$current = self::scopes( $core, $plugins );
			$changes = self::changes( $stored, $current );
			foreach ( $changes as $change ) {
				$logger->log( self::eventFor( $change ) );
			}
			if ( [] !== $changes ) {
				foreach ( $current as $scope => $info ) {
					$stored[ $scope ] = $info['digest'];
				}
				$meta->setJson( self::STATE_KEY, $stored );
			}
		} catch ( \Throwable $e ) {
			$logger->recordFailure( 'integrity.emit', $e );
		}
	}

	/**
	 * Compact summary stored for the health snapshot.
	 *
	 * @param array<string, mixed> $result Result.
	 * @return array<string, mixed>
	 */
	private static function summarize( array $result ): array {
		$core    = (array) $result['core'];
		$plugins = (array) $result['plugins'];
		return [
			'ran_at'  => $result['ran_at'],
			'core'    => [
				'status'   => $core['status'] ?? null,
				'version'  => $core['version'] ?? null,
				'checked'  => $core['checked'] ?? 0,
				'modified' => (int) ( $core['modified_total'] ?? count( (array) ( $core['modified'] ?? [] ) ) ),
				'missing'  => (int) ( $core['missing_total'] ?? count( (array) ( $core['missing'] ?? [] ) ) ),
				'unknown'  => (int) ( $core['unknown_total'] ?? 0 ),
			],
			'plugins' => [
				'status'     => $plugins['status'] ?? null,
				'checked'    => $plugins['checked'] ?? 0,
				'mismatched' => array_keys( (array) ( $plugins['mismatches'] ?? [] ) ),
			],
			'chain'   => is_array( $result['chain'] ) ? [
				'ok'      => $result['chain']['ok'] ?? null,
				'checked' => $result['chain']['checked'] ?? 0,
			] : null,
		];
	}

	/**
	 * Logger (registered, or the running plugin's).
	 */
	private function logger(): ?Logger {
		return $this->logger ?? Plugin::instance()?->logger();
	}

	/**
	 * Meta store of the running plugin.
	 */
	private function meta(): ?Meta {
		return Plugin::instance()?->meta();
	}
}
