<?php
/**
 * ConfigAudit.
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
 * Risky configuration audit (PLAN §4), run daily by cron and on demand.
 *
 * One `config.risky_setting` event per finding when it appears or its severity /
 * detail changes, and one (info, `state: resolved`) when it disappears. The
 * previous state lives in the meta key `config_audit_state`. Checks that need
 * an HTTP request to the site itself (debug.log, readme.html) only run from
 * cron or WP-CLI; elsewhere their previous state is carried over.
 *
 * Finding ids are stable and documented in docs/EVENTS.md (Config audit findings).
 */
final class ConfigAudit implements Listener {

	public const STATE_KEY = 'config_audit_state';

	/** Finding ids whose check needs an HTTP request to the site. */
	public const HTTP_CHECKS = [ 'debug_log_public', 'readme_exposed' ];

	private ?Logger $logger = null;

	/**
	 * Availability.
	 */
	public static function isAvailable(): bool {
		return true;
	}

	/**
	 * No request-time hooks: the audit runs from cron (Scheduler) and WP-CLI.
	 *
	 * @param Logger $logger Logger.
	 * @param Config $config Config.
	 */
	public function register( Logger $logger, Config $config ): void {
		unset( $config );
		$this->logger = $logger;
	}

	/**
	 * Run the audit, emit state changes, persist the new state.
	 *
	 * @return array{implemented: bool, ran_at: string, findings: list<array{id: string, severity: string, state: string, detail: array<string, mixed>}>, changes: int, http_checks: bool}
	 */
	public function run(): array {
		$http     = $this->httpAllowed();
		$meta     = $this->meta();
		$previous = [];
		try {
			$previous = null === $meta ? [] : ( $meta->getJson( self::STATE_KEY ) ?? [] );
		} catch ( \Throwable $e ) {
			$this->logger()?->recordFailure( 'config_audit.state', $e );
		}
		$findings = $this->collect( $http );
		if ( ! $http ) {
			$findings = self::carryOver( $findings, $previous, self::HTTP_CHECKS );
		}
		$diff   = self::diff( $previous, $findings );
		$logger = $this->logger();
		foreach ( $diff as $change ) {
			$logger?->log( self::eventFor( $change ) );
		}
		try {
			$meta?->setJson( self::STATE_KEY, self::state( $findings ) );
		} catch ( \Throwable $e ) {
			$logger?->recordFailure( 'config_audit.save', $e );
		}
		$logger?->flush();
		return [
			'implemented' => true,
			'ran_at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'findings'    => array_values( $findings ),
			'changes'     => count( $diff ),
			'http_checks' => $http,
		];
	}

	/**
	 * Last stored findings (for the health snapshot).
	 *
	 * @return array<string, array{severity: string, digest: string, detail: array<string, mixed>}>|null
	 */
	public function lastState(): ?array {
		try {
			$state = $this->meta()?->getJson( self::STATE_KEY );
		} catch ( \Throwable $e ) {
			return null;
		}
		/** Stored shape. @var array<string, array{severity: string, digest: string, detail: array<string, mixed>}>|null $state */
		return $state;
	}

	/**
	 * Diff previous stored state against current findings (pure).
	 *
	 * @param array<string|int, mixed>                                                                        $previous Stored state: id => {severity, digest, …}.
	 * @param array<string, array{id: string, severity: string, state: string, detail: array<string, mixed>}> $findings Current findings by id.
	 * @return list<array{id: string, state: string, severity: string, detail: array<string, mixed>, previous_severity: string|null}>
	 */
	public static function diff( array $previous, array $findings ): array {
		$out = [];
		foreach ( $findings as $id => $finding ) {
			$old = isset( $previous[ $id ] ) && is_array( $previous[ $id ] ) ? $previous[ $id ] : null;
			if ( null === $old ) {
				$out[] = [
					'id'                => (string) $id,
					'state'             => 'present',
					'severity'          => $finding['severity'],
					'detail'            => $finding['detail'],
					'previous_severity' => null,
				];
				continue;
			}
			if ( ( $old['severity'] ?? '' ) !== $finding['severity'] || ( $old['digest'] ?? '' ) !== self::digest( $finding ) ) {
				$out[] = [
					'id'                => (string) $id,
					'state'             => 'changed',
					'severity'          => $finding['severity'],
					'detail'            => $finding['detail'],
					'previous_severity' => isset( $old['severity'] ) ? (string) $old['severity'] : null,
				];
			}
		}
		foreach ( $previous as $id => $old ) {
			if ( ! isset( $findings[ $id ] ) ) {
				$out[] = [
					'id'                => (string) $id,
					'state'             => 'resolved',
					'severity'          => Severity::INFO->value,
					'detail'            => [],
					'previous_severity' => is_array( $old ) && isset( $old['severity'] ) ? (string) $old['severity'] : null,
				];
			}
		}
		return $out;
	}

	/**
	 * State to persist (pure).
	 *
	 * @param array<string, array{id: string, severity: string, state: string, detail: array<string, mixed>}> $findings Findings.
	 * @return array<string, array{severity: string, digest: string, detail: array<string, mixed>}>
	 */
	public static function state( array $findings ): array {
		$out = [];
		foreach ( $findings as $id => $finding ) {
			$out[ $id ] = [
				'severity' => $finding['severity'],
				'digest'   => self::digest( $finding ),
				'detail'   => $finding['detail'],
			];
		}
		return $out;
	}

	/**
	 * Keep the previous state of checks that were skipped this run (pure).
	 *
	 * @param array<string, array{id: string, severity: string, state: string, detail: array<string, mixed>}> $findings Current findings.
	 * @param array<string|int, mixed>                                                                        $previous Stored state.
	 * @param list<string>                                                                                    $ids      Skipped check ids.
	 * @return array<string, array{id: string, severity: string, state: string, detail: array<string, mixed>}>
	 */
	public static function carryOver( array $findings, array $previous, array $ids ): array {
		foreach ( $ids as $id ) {
			if ( ! isset( $findings[ $id ] ) && isset( $previous[ $id ] ) && is_array( $previous[ $id ] ) ) {
				$findings[ $id ] = [
					'id'       => $id,
					'severity' => (string) ( $previous[ $id ]['severity'] ?? Severity::WARNING->value ),
					'state'    => 'present',
					'detail'   => is_array( $previous[ $id ]['detail'] ?? null ) ? $previous[ $id ]['detail'] : [],
				];
			}
		}
		return $findings;
	}

	/**
	 * Event for one state change (pure).
	 *
	 * @param array{id: string, state: string, severity: string, detail: array<string, mixed>, previous_severity: string|null} $change Change.
	 */
	public static function eventFor( array $change ): Event {
		$severity = Severity::parse( $change['severity'] ) ?? Severity::WARNING;
		return Event::make( Category::SECURITY, 'config.risky_setting', $severity )
			->object( 'config', $change['id'] )
			->summary( sprintf( 'Risky configuration %s: %s', $change['state'], $change['id'] ) )
			->context(
				[
					'finding'           => $change['id'],
					'state'             => $change['state'],
					'detail'            => $change['detail'],
					'previous_severity' => $change['previous_severity'],
				]
			);
	}

	/**
	 * Run every check.
	 *
	 * @param bool $http Whether HTTP self-checks may run.
	 * @return array<string, array{id: string, severity: string, state: string, detail: array<string, mixed>}>
	 */
	public function collect( bool $http ): array {
		$found = [];
		$add   = static function ( string $id, Severity $severity, array $detail = [] ) use ( &$found ): void {
			$found[ $id ] = [
				'id'       => $id,
				'severity' => $severity->value,
				'state'    => 'present',
				'detail'   => $detail,
			];
		};
		$const = static fn( string $name ): mixed => defined( $name ) ? constant( $name ) : null;

		$debug   = (bool) $const( 'WP_DEBUG' );
		$display = $const( 'WP_DEBUG_DISPLAY' );
		if ( $debug && null !== $display && (bool) $display ) {
			$add( 'debug_display', Severity::ERROR, [ 'wp_debug_display' => true ] );
		}
		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		if ( $debug && 'production' === $env ) {
			$add( 'debug_in_production', Severity::WARNING, [ 'environment' => $env ] );
		}
		if ( true !== $const( 'DISALLOW_FILE_EDIT' ) && true !== $const( 'DISALLOW_FILE_MODS' ) ) {
			$add( 'file_edit_allowed', Severity::ERROR, [ 'disallow_file_edit' => false ] );
		}
		$this->checkRegistration( $add );
		if ( (bool) apply_filters( 'xmlrpc_enabled', true ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, read only.
			$add( 'xmlrpc_enabled', Severity::WARNING );
		}
		$config = self::wpConfigPath();
		if ( null !== $config && wp_is_writable( $config ) ) {
			$add( 'wp_config_writable', Severity::WARNING, [ 'location' => dirname( $config ) === rtrim( ABSPATH, '/\\' ) ? 'abspath' : 'parent' ] );
		}
		$admin = function_exists( 'username_exists' ) ? username_exists( 'admin' ) : false;
		if ( false !== $admin && (int) $admin > 0 ) {
			$add( 'admin_username', Severity::WARNING, [ 'user_id' => (int) $admin ] );
		}
		global $wpdb;
		if ( is_object( $wpdb ) && isset( $wpdb->base_prefix ) && 'wp_' === $wpdb->base_prefix ) {
			$add( 'table_prefix_default', Severity::WARNING );
		}
		$https = function_exists( 'wp_is_using_https' ) ? wp_is_using_https() : ( function_exists( 'is_ssl' ) && is_ssl() );
		if ( $https && true !== (bool) $const( 'FORCE_SSL_ADMIN' ) && ! ( function_exists( 'force_ssl_admin' ) && force_ssl_admin() ) ) {
			$add( 'force_ssl_admin_off', Severity::WARNING );
		}
		if ( true === (bool) $const( 'AUTOMATIC_UPDATER_DISABLED' ) ) {
			$add( 'auto_updater_disabled', Severity::WARNING );
		}
		if ( defined( 'WP_AUTO_UPDATE_CORE' ) && false === $const( 'WP_AUTO_UPDATE_CORE' ) ) {
			$add( 'core_auto_update_disabled', Severity::WARNING );
		}
		if ( false !== has_action( 'wp_head', 'wp_generator' ) && '' !== trim( (string) apply_filters( 'the_generator', '<meta name="generator" content="WordPress" />', 'xhtml' ) ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter, read only.
			$add( 'generator_exposed', Severity::WARNING );
		}
		if ( $http ) {
			$this->checkHttp( $add, $const );
		}
		return $found;
	}

	/**
	 * Open registration with a role above subscriber.
	 *
	 * @param callable $add Adder.
	 */
	private function checkRegistration( callable $add ): void {
		if ( ! (bool) get_option( 'users_can_register' ) ) {
			return;
		}
		$role = (string) get_option( 'default_role', 'subscriber' );
		if ( 'subscriber' === $role ) {
			return;
		}
		$caps     = [];
		$roleInfo = function_exists( 'get_role' ) ? get_role( $role ) : null;
		if ( $roleInfo instanceof \WP_Role ) {
			$caps = array_keys( array_filter( $roleInfo->capabilities ) );
		}
		$extra = array_values( array_diff( $caps, [ 'read', 'level_0' ] ) );
		if ( null === $roleInfo || [] !== $extra ) {
			$add(
				'open_registration_elevated',
				Severity::ERROR,
				[
					'default_role' => $role,
					'capabilities' => count( $extra ),
				]
			);
		}
	}

	/**
	 * HTTP self-checks (cron / CLI only): debug.log and readme.html reachable.
	 *
	 * @param callable $add   Adder.
	 * @param callable $constant Constant reader.
	 */
	private function checkHttp( callable $add, callable $constant ): void {
		$log = $constant( 'WP_DEBUG_LOG' );
		$dir = defined( 'WP_CONTENT_DIR' ) ? (string) WP_CONTENT_DIR : rtrim( ABSPATH, '/' ) . '/wp-content';
		if ( is_string( $log ) && '' !== $log && ! in_array( strtolower( $log ), [ '1', 'true' ], true ) ) {
			$file = $log;
		} else {
			$file = $dir . '/debug.log';
		}
		$real = realpath( $file );
		$root = realpath( ABSPATH );
		if ( false !== $real && false !== $root && str_starts_with( $real, $root ) ) {
			$url = site_url( '/' . ltrim( str_replace( '\\', '/', substr( $real, strlen( $root ) ) ), '/' ) );
			if ( str_starts_with( $real, (string) realpath( $dir ) ) ) {
				$url = content_url( '/' . ltrim( str_replace( '\\', '/', substr( $real, strlen( (string) realpath( $dir ) ) ) ), '/' ) );
			}
			$status = self::headStatus( $url );
			if ( 200 === $status ) {
				$add( 'debug_log_public', Severity::WARNING, [ 'http_status' => $status ] );
			}
		}
		if ( file_exists( rtrim( ABSPATH, '/' ) . '/readme.html' ) ) {
			$status = self::headStatus( site_url( '/readme.html' ) );
			if ( 200 === $status ) {
				$add( 'readme_exposed', Severity::WARNING, [ 'http_status' => $status ] );
			}
		}
	}

	/**
	 * HEAD status of a URL on this site (0 on failure).
	 *
	 * @param string $url URL.
	 */
	private static function headStatus( string $url ): int {
		$response = wp_remote_head(
			$url,
			[
				'timeout'     => 5,
				'redirection' => 0,
				'sslverify'   => false,
			]
		);
		return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Location of wp-config.php (ABSPATH or one level up, as WordPress allows).
	 */
	private static function wpConfigPath(): ?string {
		$root = rtrim( ABSPATH, '/\\' );
		if ( file_exists( $root . '/wp-config.php' ) ) {
			return $root . '/wp-config.php';
		}
		$parent = dirname( $root ) . '/wp-config.php';
		return file_exists( $parent ) && ! file_exists( dirname( $root ) . '/wp-settings.php' ) ? $parent : null;
	}

	/**
	 * Whether HTTP self-checks may run (never on a visitor's page load).
	 */
	private function httpAllowed(): bool {
		return ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) );
	}

	/**
	 * Digest of a finding's severity and detail.
	 *
	 * @param array{severity: string, detail: array<string, mixed>} $finding Finding.
	 */
	private static function digest( array $finding ): string {
		return substr( hash( 'sha256', $finding['severity'] . '|' . (string) wp_json_encode( $finding['detail'] ) ), 0, 16 );
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
