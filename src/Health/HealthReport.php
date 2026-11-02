<?php
/**
 * HealthReport.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Health;

use Noformat\Logger\Category;
use Noformat\Logger\Config;
use Noformat\Logger\Event;
use Noformat\Logger\Health\Support\CronInspector;
use Noformat\Logger\Health\Support\UpdatesTracker;
use Noformat\Logger\Listener;
use Noformat\Logger\Logger;
use Noformat\Logger\Plugin;
use Noformat\Logger\Security\ConfigAudit;
use Noformat\Logger\Severity;
use Noformat\Logger\Storage\Meta;
use Noformat\Logger\Storage\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Health snapshot: versions, pending updates, debug flags, cron, integrity,
 * Site Health results (PLAN §4, CONTRACT §3 /health).
 *
 * The snapshot computes the cheap parts live and takes the heavy parts (updates,
 * users, content counts, errors_24h, Site Health) from the cache written by
 * refreshCache() every 15 minutes (cron). Without a cache the heavy parts except
 * Site Health are computed inline. Site Health direct tests only run from cron
 * or WP-CLI.
 *
 * refreshCache() also emits `cron.overdue` (warning, per hook, when a recurring
 * event is more than 2× its interval late) and `updates.pending` (notice;
 * warning once an update has been pending ≥ 7 days), each once per state change.
 *
 * Contains no names, e-mails or filesystem paths: only slugs, ids, versions and counts.
 */
final class HealthReport implements Listener {

	public const CACHE_KEY        = 'health_cache';
	public const LAST_CRON_KEY    = 'last_cron_run';
	public const CRON_ALERTS_KEY  = 'health_cron_alerts';
	public const UPDATES_SEEN_KEY = 'updates_first_seen';
	public const UPDATES_DIGEST   = 'updates_digest';

	/** Cache older than this is treated as missing. */
	public const CACHE_TTL = 3600;

	private ?Logger $logger = null;

	/**
	 * Availability.
	 */
	public static function isAvailable(): bool {
		return true;
	}

	/**
	 * Record the last WP-Cron run (one meta write per cron request, nothing on page views).
	 *
	 * @param Logger $logger Logger.
	 * @param Config $config Config.
	 */
	public function register( Logger $logger, Config $config ): void {
		unset( $config );
		$this->logger = $logger;
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			add_action(
				'wp_loaded',
				function () use ( $logger ): void {
					$logger->guard( 'health.cron_tick', fn() => Plugin::instance()?->meta()->set( self::LAST_CRON_KEY, (string) time() ) );
				},
				10,
				0
			);
		}
	}

	/**
	 * Snapshot for GET /health and `wp nflog health`.
	 *
	 * @return array<string, mixed>
	 */
	public function snapshot(): array {
		$now    = time();
		$cache  = $this->cache();
		$heavy  = is_array( $cache['data'] ?? null ) && ( $now - (int) ( $cache['generated_at'] ?? 0 ) ) < self::CACHE_TTL ? $cache['data'] : null;
		$cached = null !== $heavy;
		if ( null === $heavy ) {
			$heavy = $this->heavy( false );
		}
		$snapshot = [
			'implemented'  => true,
			'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z', $now ),
			'cache'        => $cached ? [
				'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z', (int) $cache['generated_at'] ),
				'age_seconds'  => $now - (int) $cache['generated_at'],
			] : null,
		];
		$parts    = [
			'versions'         => fn() => $this->versions(),
			'environment'      => fn() => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			'multisite'        => fn() => is_multisite(),
			'active_theme'     => fn() => $this->activeTheme(),
			'plugins'          => fn() => $this->plugins(),
			'updates'          => fn() => is_array( $heavy['updates'] ?? null ) ? array_diff_key( $heavy['updates'], [ 'first_seen' => true ] ) : null,
			'debug'            => fn() => $this->debugFlags(),
			'cron'             => fn() => $this->cron( $now ),
			'uploads_writable' => fn() => $this->uploadsWritable(),
			'ssl'              => fn() => [
				'is_ssl'          => is_ssl(),
				'using_https'     => function_exists( 'wp_is_using_https' ) ? wp_is_using_https() : null,
				'force_ssl_admin' => force_ssl_admin(),
			],
			'users'            => fn() => $heavy['users'] ?? null,
			'content'          => fn() => $heavy['content'] ?? null,
			'errors_24h'       => fn() => $heavy['errors_24h'] ?? null,
			'site_health'      => fn() => $heavy['site_health'] ?? null,
			'integrity'        => fn() => Plugin::instance()?->integrity()->lastSummary(),
			'config_audit'     => fn() => $this->configAuditSummary(),
		];
		foreach ( $parts as $key => $callback ) {
			$snapshot[ $key ] = $this->guard( 'health.' . $key, $callback );
		}
		return $snapshot;
	}

	/**
	 * Cron: refresh the cached heavy parts and emit state-change events.
	 */
	public function refreshCache(): void {
		$now   = time();
		$heavy = $this->heavy( ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) );
		$meta  = Plugin::instance()?->meta();
		if ( null === $meta ) {
			return;
		}
		$this->guard(
			'health.cache',
			fn() => $meta->setJson(
				self::CACHE_KEY,
				[
					'generated_at' => $now,
					'data'         => $heavy,
				]
			)
		);
		$this->guard( 'health.cron_alerts', fn() => $this->emitCronAlerts( $meta, $now ) );
		$this->guard( 'health.updates', fn() => $this->emitUpdates( $meta, is_array( $heavy['updates'] ?? null ) ? $heavy['updates'] : [] ) );
		$this->logger()?->flush();
	}

	/**
	 * Heavy parts.
	 *
	 * @param bool $siteHealth Run Site Health direct tests.
	 * @return array<string, mixed>
	 */
	private function heavy( bool $siteHealth ): array {
		$now = time();
		return [
			'updates'     => $this->guard( 'health.updates', fn() => $this->updates( $now ) ),
			'users'       => $this->guard( 'health.users', fn() => $this->users() ),
			'content'     => $this->guard( 'health.content', fn() => $this->content() ),
			'errors_24h'  => $this->guard( 'health.errors', fn() => $this->errors24h() ),
			'site_health' => $siteHealth ? $this->guard( 'health.site_health', fn() => $this->siteHealth() ) : $this->cachedSiteHealth(),
		];
	}

	/**
	 * Versions.
	 *
	 * @return array<string, mixed>
	 */
	private function versions(): array {
		global $wp_version, $wpdb, $wp_db_version;
		$db = null;
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'db_server_info' ) ) {
			try {
				$info = $wpdb->db_server_info();
				$db   = is_string( $info ) ? substr( $info, 0, 64 ) : null;
			} catch ( \Throwable $e ) {
				$db = null;
			}
		}
		$engine = defined( 'DB_ENGINE' ) && 'sqlite' === constant( 'DB_ENGINE' ) ? 'sqlite' : 'mysql';
		return [
			'php'        => PHP_VERSION,
			'wordpress'  => is_string( $wp_version ) ? $wp_version : null,
			'db_engine'  => $engine,
			'db_server'  => $db,
			'wp_db'      => is_numeric( $wp_db_version ) ? (int) $wp_db_version : null,
			'plugin'     => defined( 'NOFORMAT_LOGGER_VERSION' ) ? NOFORMAT_LOGGER_VERSION : null,
			'schema'     => [
				'event'    => Event::SCHEMA,
				'database' => $this->guard( 'health.schema', static fn() => Schema::installedVersion() ),
				'expected' => Schema::VERSION,
			],
			'php_sapi'   => PHP_SAPI,
			'php_memory' => (string) ini_get( 'memory_limit' ),
		];
	}

	/**
	 * Active theme.
	 *
	 * @return array{stylesheet: string, template: string, version: string|null, update: string|null}
	 */
	private function activeTheme(): array {
		$theme   = wp_get_theme();
		$updates = get_site_transient( 'update_themes' );
		$sheet   = (string) get_stylesheet();
		$update  = null;
		if ( is_object( $updates ) && isset( $updates->response[ $sheet ] ) ) {
			$update = (string) ( ( (array) $updates->response[ $sheet ] )['new_version'] ?? '' );
		}
		$version = $theme->get( 'Version' );
		return [
			'stylesheet' => $sheet,
			'template'   => (string) get_template(),
			'version'    => is_string( $version ) && '' !== $version ? $version : null,
			'update'     => $update,
		];
	}

	/**
	 * Active plugins: slug, version, available update, auto-update flag.
	 *
	 * @return list<array{slug: string, version: string|null, update: string|null, auto_update: bool, network: bool}>
	 */
	private function plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all     = get_plugins();
		$active  = (array) get_option( 'active_plugins', [] );
		$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) : [];
		$updates = get_site_transient( 'update_plugins' );
		$auto    = (array) get_site_option( 'auto_update_plugins', [] );
		$enabled = ! function_exists( 'wp_is_auto_update_enabled_for_type' ) || wp_is_auto_update_enabled_for_type( 'plugin' );
		$out     = [];
		foreach ( array_unique( array_merge( $active, $network ) ) as $file ) {
			$file = (string) $file;
			if ( ! isset( $all[ $file ] ) ) {
				continue;
			}
			$update = null;
			if ( is_object( $updates ) && isset( $updates->response[ $file ] ) ) {
				$update = (string) ( ( (array) $updates->response[ $file ] )['new_version'] ?? '' );
			}
			$version = (string) ( $all[ $file ]['Version'] ?? '' );
			$out[]   = [
				'slug'        => self::pluginSlug( $file ),
				'version'     => '' === $version ? null : $version,
				'update'      => $update,
				'auto_update' => $enabled && in_array( $file, $auto, true ),
				'network'     => in_array( $file, $network, true ),
			];
		}
		usort( $out, static fn( array $a, array $b ): int => strcmp( $a['slug'], $b['slug'] ) );
		return $out;
	}

	/**
	 * Pending updates from the transients (no remote check is forced).
	 *
	 * @param int $now Now.
	 * @return array<string, mixed>
	 */
	private function updates( int $now ): array {
		$core    = get_site_transient( 'update_core' );
		$plugins = get_site_transient( 'update_plugins' );
		$themes  = get_site_transient( 'update_themes' );
		$pending = UpdatesTracker::pending( $core, $plugins, $themes );
		$seen    = [];
		try {
			$seen = Plugin::instance()?->meta()->getJson( self::UPDATES_SEEN_KEY ) ?? [];
		} catch ( \Throwable $e ) {
			$seen = [];
		}
		$first = UpdatesTracker::firstSeen( $pending, $seen, $now );
		$items = UpdatesTracker::annotate( $pending, $first, $now );
		$count = static fn( string $type ): int => count( array_filter( $items, static fn( array $i ): bool => $type === $i['type'] ) );
		$slugs = static fn( string $type ): array => array_values( array_map( static fn( array $i ): string => $i['slug'], array_filter( $items, static fn( array $i ): bool => $type === $i['type'] ) ) );
		$last  = static fn( mixed $t ): ?string => is_object( $t ) && isset( $t->last_checked ) ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $t->last_checked ) : null;
		return [
			'core'         => $count( 'core' ),
			'plugins'      => $count( 'plugin' ),
			'themes'       => $count( 'theme' ),
			'core_version' => $slugs( 'core' ) === [] ? null : ( array_values( array_filter( $items, static fn( array $i ): bool => 'core' === $i['type'] ) )[0]['version'] ?? null ),
			'plugin_slugs' => $slugs( 'plugin' ),
			'theme_slugs'  => $slugs( 'theme' ),
			'items'        => $items,
			'stale'        => count( array_filter( $items, static fn( array $i ): bool => $i['stale'] ) ),
			'last_checked' => [
				'core'    => $last( $core ),
				'plugins' => $last( $plugins ),
				'themes'  => $last( $themes ),
			],
			'first_seen'   => $first,
		];
	}

	/**
	 * Debug and hardening flags.
	 *
	 * @return array<string, mixed>
	 */
	private function debugFlags(): array {
		$const = static fn( string $name ): mixed => defined( $name ) ? constant( $name ) : null;
		$log   = $const( 'WP_DEBUG_LOG' );
		return [
			'wp_debug'           => (bool) $const( 'WP_DEBUG' ),
			'wp_debug_display'   => null === $const( 'WP_DEBUG_DISPLAY' ) ? null : (bool) $const( 'WP_DEBUG_DISPLAY' ),
			'wp_debug_log'       => is_string( $log ) && ! in_array( strtolower( $log ), [ '', '0', '1', 'true', 'false' ], true ) ? 'custom_path' : (bool) $log,
			'script_debug'       => (bool) $const( 'SCRIPT_DEBUG' ),
			'disallow_file_edit' => (bool) $const( 'DISALLOW_FILE_EDIT' ),
			'disallow_file_mods' => (bool) $const( 'DISALLOW_FILE_MODS' ),
			'disable_wp_cron'    => (bool) $const( 'DISABLE_WP_CRON' ),
			'wp_cache'           => (bool) $const( 'WP_CACHE' ),
			'object_cache'       => function_exists( 'wp_using_ext_object_cache' ) && (bool) wp_using_ext_object_cache(),
			'display_errors'     => in_array( strtolower( (string) ini_get( 'display_errors' ) ), [ '1', 'on', 'true', 'stdout', 'stderr' ], true ),
		];
	}

	/**
	 * Cron state.
	 *
	 * @param int $now Now.
	 * @return array<string, mixed>
	 */
	private function cron( int $now ): array {
		$array   = function_exists( '_get_cron_array' ) ? _get_cron_array() : [];
		$array   = is_array( $array ) ? $array : [];
		$overdue = CronInspector::overdue( $array, $now );
		$last    = null;
		try {
			$value = Plugin::instance()?->meta()->get( self::LAST_CRON_KEY );
			$last  = null === $value ? null : (int) $value;
		} catch ( \Throwable $e ) {
			$last = null;
		}
		return [
			'disabled'       => defined( 'DISABLE_WP_CRON' ) && (bool) constant( 'DISABLE_WP_CRON' ),
			'alternate'      => defined( 'ALTERNATE_WP_CRON' ) && (bool) constant( 'ALTERNATE_WP_CRON' ),
			'events'         => array_sum( array_map( static fn( $hooks ): int => is_array( $hooks ) ? array_sum( array_map( static fn( $e ): int => is_array( $e ) ? count( $e ) : 0, $hooks ) ) : 0, $array ) ),
			'last_run_at'    => null === $last ? null : gmdate( 'Y-m-d\TH:i:s\Z', $last ),
			'last_run_age'   => null === $last ? null : $now - $last,
			'overdue_count'  => count( $overdue ),
			'overdue'        => array_slice( $overdue, 0, 20 ),
			'next'           => CronInspector::upcoming( $array, $now, 10 ),
			'alerting_hooks' => array_values( array_map( static fn( array $o ): string => $o['hook'], array_filter( $overdue, static fn( array $o ): bool => $o['alert'] ) ) ),
		];
	}

	/**
	 * Uploads directory writable.
	 */
	private function uploadsWritable(): bool {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return false;
		}
		$dir = (string) $uploads['basedir'];
		return is_dir( $dir ) ? wp_is_writable( $dir ) : wp_is_writable( dirname( $dir ) );
	}

	/**
	 * User counts per role.
	 *
	 * @return array<string, int>
	 */
	private function users(): array {
		$counts = count_users();
		$out    = [];
		foreach ( (array) ( $counts['avail_roles'] ?? [] ) as $role => $count ) {
			if ( (int) $count > 0 ) {
				$out[ (string) $role ] = (int) $count;
			}
		}
		$out['total'] = (int) ( $counts['total_users'] ?? array_sum( $out ) );
		return $out;
	}

	/**
	 * Published content per public post type.
	 *
	 * @return array<string, int>
	 */
	private function content(): array {
		$out = [];
		foreach ( get_post_types( [ 'public' => true ] ) as $type ) {
			$counts       = wp_count_posts( (string) $type );
			$out[ $type ] = 'attachment' === $type ? (int) ( $counts->inherit ?? 0 ) : (int) ( $counts->publish ?? 0 );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Event counts per severity in the last 24 h.
	 *
	 * @return array<string, mixed>|null
	 */
	private function errors24h(): ?array {
		$plugin = Plugin::instance();
		if ( null === $plugin ) {
			return null;
		}
		$nowMs = (int) floor( microtime( true ) * 1000 );
		$since = $nowMs - DAY_IN_SECONDS * 1000;
		return [
			'by_severity' => $plugin->repository()->countsBySeverity( $since ),
			'since'       => Event::formatMs( $since, 'Y-m-d\TH:i:s' ) . 'Z',
		];
	}

	/**
	 * Site Health direct tests: test id and status only.
	 *
	 * @return array<string, mixed>
	 */
	private function siteHealth(): array {
		require_once ABSPATH . 'wp-admin/includes/admin.php';
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}
		$health   = \WP_Site_Health::get_instance();
		$tests    = \WP_Site_Health::get_tests();
		$results  = [];
		$counts   = [
			'good'        => 0,
			'recommended' => 0,
			'critical'    => 0,
		];
		$deadline = microtime( true ) + 20;
		foreach ( (array) ( $tests['direct'] ?? [] ) as $id => $test ) {
			if ( microtime( true ) > $deadline ) {
				break;
			}
			if ( ! is_array( $test ) || ! empty( $test['skip_cron'] ) ) {
				continue;
			}
			$callback = null;
			if ( is_string( $test['test'] ?? null ) && method_exists( $health, 'get_test_' . $test['test'] ) ) {
				$callback = [ $health, 'get_test_' . $test['test'] ];
			} elseif ( isset( $test['test'] ) && is_callable( $test['test'] ) ) {
				$callback = $test['test'];
			}
			if ( null === $callback || ! is_callable( $callback ) ) {
				continue;
			}
			try {
				ob_start();
				$result = apply_filters( 'site_status_test_result', call_user_func( $callback ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, applied as WordPress does.
			} catch ( \Throwable $e ) {
				$result = null;
			} finally {
				ob_end_clean();
			}
			$status = is_array( $result ) && isset( $result['status'] ) ? (string) $result['status'] : 'error';
			$name   = is_array( $result ) && isset( $result['test'] ) && is_string( $result['test'] ) ? $result['test'] : (string) $id;
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
			$results[] = [
				'test'   => sanitize_key( $name ),
				'status' => sanitize_key( $status ),
			];
		}
		return [
			'ran_at'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'counts'  => $counts,
			'results' => $results,
		];
	}

	/**
	 * Site Health part of the previous cache (kept between non-cron refreshes).
	 *
	 * @return array<string, mixed>|null
	 */
	private function cachedSiteHealth(): ?array {
		$cache = $this->cache();
		$value = $cache['data']['site_health'] ?? null;
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Config audit summary (last stored findings).
	 *
	 * @return array<string, mixed>|null
	 */
	private function configAuditSummary(): ?array {
		$plugin = Plugin::instance();
		if ( null === $plugin ) {
			return null;
		}
		$state = $plugin->listener( ConfigAudit::class )->lastState();
		if ( null === $state ) {
			return null;
		}
		$findings = [];
		foreach ( $state as $id => $finding ) {
			$findings[] = [
				'id'       => (string) $id,
				'severity' => (string) ( $finding['severity'] ?? 'warning' ),
			];
		}
		return [
			'count'    => count( $findings ),
			'findings' => $findings,
		];
	}

	/**
	 * Emit cron.overdue for newly alerting hooks.
	 *
	 * @param Meta $meta Meta.
	 * @param int  $now  Now.
	 */
	private function emitCronAlerts( Meta $meta, int $now ): void {
		$array   = _get_cron_array();
		$overdue = CronInspector::overdue( is_array( $array ) ? $array : [], $now );
		$known   = $meta->getJson( self::CRON_ALERTS_KEY ) ?? [];
		$current = [];
		foreach ( $overdue as $item ) {
			if ( ! $item['alert'] ) {
				continue;
			}
			$current[ $item['hook'] ] = isset( $known[ $item['hook'] ] ) ? (int) $known[ $item['hook'] ] : $now;
			if ( ! isset( $known[ $item['hook'] ] ) ) {
				$this->logger()?->log( self::cronOverdueEvent( $item ) );
			}
		}
		if ( $current !== $known ) {
			$meta->setJson( self::CRON_ALERTS_KEY, $current );
		}
	}

	/**
	 * Event `cron.overdue` (pure).
	 *
	 * @param array{hook: string, overdue_seconds: int, interval: int|null, schedule: string|null} $item Overdue item.
	 */
	public static function cronOverdueEvent( array $item ): Event {
		return Event::make( Category::CRON, 'cron.overdue', Severity::WARNING )
			->object( 'cron_hook', strlen( $item['hook'] ) > 64 ? substr( $item['hook'], 0, 64 ) : $item['hook'] )
			->summary( sprintf( 'Cron hook %s is %d seconds overdue (interval %d s)', $item['hook'], $item['overdue_seconds'], (int) $item['interval'] ) )
			->context(
				[
					'hook'            => $item['hook'],
					'overdue_seconds' => $item['overdue_seconds'],
					'interval'        => $item['interval'],
					'schedule'        => $item['schedule'],
					'wp_cron_off'     => defined( 'DISABLE_WP_CRON' ) && (bool) constant( 'DISABLE_WP_CRON' ),
				]
			)
			->fingerprint( 'cron.overdue', $item['hook'] );
	}

	/**
	 * Emit updates.pending when the pending set (or its staleness) changes.
	 *
	 * @param Meta                 $meta    Meta.
	 * @param array<string, mixed> $updates Updates part.
	 */
	private function emitUpdates( Meta $meta, array $updates ): void {
		/** Annotated items. @var list<array{type: string, slug: string, version: string, pending_days: int, stale: bool}> $items */
		$items = is_array( $updates['items'] ?? null ) ? $updates['items'] : [];
		$meta->setJson( self::UPDATES_SEEN_KEY, is_array( $updates['first_seen'] ?? null ) ? $updates['first_seen'] : [] );
		$digest = UpdatesTracker::digest( $items );
		if ( $digest === (string) $meta->get( self::UPDATES_DIGEST, '' ) ) {
			return;
		}
		$meta->set( self::UPDATES_DIGEST, $digest );
		if ( [] === $items ) {
			return;
		}
		$this->logger()?->log( self::updatesEvent( $items ) );
	}

	/**
	 * Event `updates.pending` (pure).
	 *
	 * @param list<array{type: string, slug: string, version: string, pending_days: int, stale: bool}> $items Items.
	 */
	public static function updatesEvent( array $items ): Event {
		$stale  = array_values( array_filter( $items, static fn( array $i ): bool => $i['stale'] ) );
		$counts = [
			'core'   => 0,
			'plugin' => 0,
			'theme'  => 0,
		];
		foreach ( $items as $item ) {
			$counts[ $item['type'] ] = ( $counts[ $item['type'] ] ?? 0 ) + 1;
		}
		return Event::make( Category::CORE, 'updates.pending', [] === $stale ? Severity::NOTICE : Severity::WARNING )
			->summary( sprintf( 'Pending updates: %d core, %d plugins, %d themes%s', $counts['core'], $counts['plugin'], $counts['theme'], [] === $stale ? '' : sprintf( ' (%d pending ≥ %d days)', count( $stale ), UpdatesTracker::STALE_DAYS ) ) )
			->context(
				[
					'core'        => $counts['core'],
					'plugins'     => $counts['plugin'],
					'themes'      => $counts['theme'],
					'stale'       => count( $stale ),
					'stale_after' => UpdatesTracker::STALE_DAYS,
					'items'       => array_slice( $items, 0, 50 ),
				]
			);
	}

	/**
	 * Plugin slug from its file.
	 *
	 * @param string $file Plugin file.
	 */
	public static function pluginSlug( string $file ): string {
		return str_contains( $file, '/' ) ? dirname( $file ) : basename( $file, '.php' );
	}

	/**
	 * Cached heavy parts.
	 *
	 * @return array<string|int, mixed>|null
	 */
	private function cache(): ?array {
		try {
			return Plugin::instance()?->meta()->getJson( self::CACHE_KEY );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Guarded call.
	 *
	 * @param string   $where    Label.
	 * @param callable $callback Callback.
	 */
	private function guard( string $where, callable $callback ): mixed {
		$logger = $this->logger();
		if ( null !== $logger ) {
			return $logger->guard( $where, $callback );
		}
		try {
			return $callback();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Logger (registered, or the running plugin's).
	 */
	private function logger(): ?Logger {
		return $this->logger ?? Plugin::instance()?->logger();
	}
}
