<?php
/**
 * WordPress-native updates from GitHub Releases.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Update;

use Noformat\Logger\Category;
use Noformat\Logger\Event;
use Noformat\Logger\Plugin;
use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Plugs the latest GitHub release into WordPress' own update flow, so the
 * plugin appears in Dashboard → Updates and on the Plugins screen, honours
 * the auto-update toggle and `wp plugin update`, and has a "View details"
 * modal. No update server of our own.
 *
 * - `update_plugins_github.com` (WordPress 5.8+, from the `Update URI` header):
 *   the update array of the latest release. It is returned whether or not the
 *   release is newer; WordPress files it under `response` (update available)
 *   or `no_update`, which is what shows the auto-update toggle and "View details".
 * - `plugins_api`: the details modal.
 * - `upgrader_pre_download`: downloads our package with our headers (HTTPS only)
 *   and refuses it unless its SHA-256 matches the release's manifest.json.
 * - `upgrader_source_selection`: the unpacked folder is renamed to the
 *   installed folder (`nf-logbook`) whatever the zip's top folder is.
 * - `upgrader_process_complete`: clears the cache after our own update and logs
 *   `plugin.updated` when PluginThemeListener (which normally logs it) is off.
 *
 * Every callback degrades to "no update" on failure; nothing here fatals.
 */
final class Updater {

	public const UPDATE_URI   = 'https://github.com/wearenoformat/nf-logbook';
	public const CHECK_NONCE  = 'nflog_update_check';
	public const DOWNLOAD_MAX = 300;

	private ReleaseClient $client;

	/**
	 * Constructor.
	 *
	 * @param Plugin|null        $plugin Plugin (logging, config); null in tests.
	 * @param ReleaseClient|null $client Release client (default: from the constants).
	 */
	public function __construct( private ?Plugin $plugin = null, ?ReleaseClient $client = null ) {
		$this->client = $client ?? ReleaseClient::fromEnvironment();
	}

	/**
	 * Hooks.
	 */
	public function hooks(): void {
		add_filter( 'update_plugins_github.com', [ $this, 'updateFor' ], 10, 4 );
		add_filter( 'plugins_api', [ $this, 'details' ], 20, 3 );
		add_filter( 'upgrader_pre_download', [ $this, 'preDownload' ], 10, 4 );
		add_filter( 'upgrader_source_selection', [ $this, 'sourceSelection' ], 10, 4 );
		add_action( 'upgrader_process_complete', [ $this, 'processComplete' ], 20, 2 );
	}

	/**
	 * The release client.
	 */
	public function client(): ReleaseClient {
		return $this->client;
	}

	/**
	 * Plugin basename (nf-logbook/nf-logbook.php).
	 */
	public static function basename(): string {
		return function_exists( 'plugin_basename' ) ? plugin_basename( NOFORMAT_LOGGER_FILE ) : Release::SLUG . '/nf-logbook.php';
	}

	/**
	 * Folder the plugin must keep (the installed one, normally nf-logbook).
	 */
	public static function folder(): string {
		$dir = dirname( self::basename() );
		return '.' === $dir || '' === $dir ? Release::SLUG : $dir;
	}

	/**
	 * Update_plugins_github.com: our update array.
	 *
	 * @param mixed $update     Filter value (false or another plugin's answer).
	 * @param mixed $pluginData Plugin headers.
	 * @param mixed $pluginFile Plugin basename.
	 * @param mixed $locales    Locales.
	 * @return mixed
	 */
	public function updateFor( mixed $update, mixed $pluginData = [], mixed $pluginFile = '', mixed $locales = [] ): mixed {
		unset( $pluginData, $locales );
		if ( self::basename() !== $pluginFile ) {
			return $update;
		}
		try {
			$release = $this->client->state()['release'];
			if ( null === $release ) {
				return $update;
			}
			return Release::updateArray( $release, self::UPDATE_URI, 'https://github.com/' . $this->client->repo() );
		} catch ( \Throwable $e ) {
			return $update;
		}
	}

	/**
	 * Plugins_api: the "View details" modal.
	 *
	 * @param mixed $result Filter value.
	 * @param mixed $action API action.
	 * @param mixed $args   Arguments (object with slug).
	 * @return mixed
	 */
	public function details( mixed $result, mixed $action = '', mixed $args = null ): mixed {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || Release::SLUG !== ( $args->slug ?? null ) ) {
			return $result;
		}
		try {
			$state = $this->client->state();
			return (object) self::detailsArray( $state['release'], $state['error'], defined( 'NOFORMAT_LOGGER_VERSION' ) ? (string) NOFORMAT_LOGGER_VERSION : '', $this->client->repo() );
		} catch ( \Throwable $e ) {
			return $result;
		}
	}

	/**
	 * Fields of the details modal (pure).
	 *
	 * @param array<string, string>|null $release   Release.
	 * @param string                     $error     Last error.
	 * @param string                     $installed Installed version.
	 * @param string                     $repo      Repository.
	 * @return array<string, mixed>
	 */
	public static function detailsArray( ?array $release, string $error, string $installed, string $repo ): array {
		$description = '<p>Tamper-evident, privacy-preserving log of content changes and error, security and stability signals.</p>'
			. '<p>Updates come from the GitHub releases of <code>' . htmlspecialchars( $repo, ENT_QUOTES ) . '</code>; every package is checked against the SHA-256 in the release\'s manifest.json before it is installed.</p>';
		$changelog   = null === $release || '' === $release['changelog']
			? '<p>' . htmlspecialchars( '' === $error ? 'No release notes.' : 'No release information: ' . $error, ENT_QUOTES ) . '</p>'
			: Changelog::toHtml( $release['changelog'] );
		return [
			'name'           => null === $release ? 'Noformat Logbook' : $release['name'],
			'slug'           => Release::SLUG,
			'version'        => null === $release ? $installed : $release['version'],
			'author'         => '<a href="https://noformat.com">Noformat</a>',
			'author_profile' => 'https://noformat.com',
			'homepage'       => null === $release || '' === $release['url'] ? 'https://github.com/' . $repo : $release['url'],
			'requires'       => $release['requires'] ?? '',
			'tested'         => $release['tested'] ?? '',
			'requires_php'   => $release['requires_php'] ?? '',
			'last_updated'   => $release['released_at'] ?? '',
			'sections'       => [
				'description' => $description,
				'changelog'   => $changelog,
			],
			'download_link'  => $release['package'] ?? '',
			'banners'        => [],
			'external'       => true,
		];
	}

	/**
	 * Upgrader_pre_download: our package, downloaded and checked against the manifest.
	 *
	 * @param mixed $reply     False to let WordPress download, a file path or WP_Error.
	 * @param mixed $package   Package URL.
	 * @param mixed $upgrader  WP_Upgrader.
	 * @param mixed $hookExtra Hook extra.
	 * @return mixed
	 */
	public function preDownload( mixed $reply, mixed $package = '', mixed $upgrader = null, mixed $hookExtra = [] ): mixed {
		unset( $upgrader, $hookExtra );
		if ( false !== $reply || ! is_string( $package ) || '' === $package ) {
			return $reply;
		}
		try {
			$release = $this->client->state()['release'];
			if ( null === $release || $package !== $release['package'] ) {
				return $reply;
			}
			return $this->download( $release );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'nflog_update_download', 'The Noformat Logbook update could not be downloaded: ' . $e->getMessage() );
		}
	}

	/**
	 * Download the release package to a temporary file and verify it.
	 *
	 * @param array<string, string> $release Release.
	 * @return string|\WP_Error Temporary file path.
	 */
	public function download( array $release ): string|\WP_Error {
		$url = $release['package'];
		if ( ! Release::isAllowedUrl( $url, $this->client->allowsLoopback() ) ) {
			return new \WP_Error( 'nflog_update_insecure', 'Refusing to download the Noformat Logbook update: the package URL is not HTTPS.' );
		}
		$file = wp_tempnam( Release::ZIP );
		if ( ! is_string( $file ) || '' === $file ) {
			return new \WP_Error( 'nflog_update_download', 'The Noformat Logbook update could not be downloaded: no temporary file.' );
		}
		$response = $this->client->get(
			$url,
			$this->client->assetHeaders(),
			$this->client->hasToken() && ! $this->client->isManifestMode(),
			[
				'timeout'  => self::DOWNLOAD_MAX,
				'stream'   => true,
				'filename' => $file,
			]
		);
		if ( is_wp_error( $response ) ) {
			wp_delete_file( $file );
			return new \WP_Error( 'nflog_update_download', 'The Noformat Logbook update could not be downloaded: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			wp_delete_file( $file );
			return new \WP_Error( 'nflog_update_download', sprintf( 'The Noformat Logbook update could not be downloaded (HTTP %d).', $code ) );
		}
		$verified = self::verify( $file, $release['sha256'], $release['version'] );
		if ( is_wp_error( $verified ) ) {
			wp_delete_file( $file );
			return $verified;
		}
		return $file;
	}

	/**
	 * SHA-256 of a downloaded package against the manifest.
	 *
	 * @param string $file     File.
	 * @param string $expected Expected hex digest.
	 * @param string $version  Release version (message).
	 * @return true|\WP_Error
	 */
	public static function verify( string $file, string $expected, string $version ): bool|\WP_Error {
		$actual = is_readable( $file ) ? hash_file( 'sha256', $file ) : false;
		if ( ! is_string( $actual ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', $expected ) || ! hash_equals( $expected, $actual ) ) {
			return new \WP_Error(
				'nflog_update_checksum',
				sprintf(
					'The Noformat Logbook %1$s package failed the SHA-256 check (manifest %2$s, download %3$s). The update was refused.',
					$version,
					substr( $expected, 0, 12 ) . '…',
					is_string( $actual ) ? substr( $actual, 0, 12 ) . '…' : 'unreadable'
				)
			);
		}
		return true;
	}

	/**
	 * Upgrader_source_selection: keep the installed folder name.
	 *
	 * @param mixed $source       Unpacked source directory.
	 * @param mixed $remoteSource Working directory the package was unpacked into.
	 * @param mixed $upgrader     WP_Upgrader.
	 * @param mixed $hookExtra    Hook extra.
	 * @return mixed
	 */
	public function sourceSelection( mixed $source, mixed $remoteSource = '', mixed $upgrader = null, mixed $hookExtra = [] ): mixed {
		unset( $upgrader );
		if ( ! is_string( $source ) || ! is_string( $remoteSource ) || ! is_array( $hookExtra ) || self::basename() !== ( $hookExtra['plugin'] ?? null ) ) {
			return $source;
		}
		global $wp_filesystem;
		try {
			return self::renameSource( $source, $remoteSource, self::folder(), $wp_filesystem );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'nflog_update_source', 'The Noformat Logbook package could not be prepared: ' . $e->getMessage() );
		}
	}

	/**
	 * Move the unpacked plugin to <remote>/<folder>/ (pure apart from the filesystem).
	 *
	 * @param string $source Unpacked source (the zip's top folder, or the working dir for a flat zip).
	 * @param string $remote Working directory.
	 * @param string $folder Target folder name.
	 * @param mixed  $fs     WP_Filesystem_Base (move, mkdir, dirlist, exists).
	 * @return string|\WP_Error New source with a trailing slash.
	 */
	public static function renameSource( string $source, string $remote, string $folder, mixed $fs ): string|\WP_Error {
		$source = rtrim( $source, '/\\' );
		$remote = rtrim( $remote, '/\\' );
		if ( ! is_object( $fs ) ) {
			return new \WP_Error( 'nflog_update_source', 'The Noformat Logbook package could not be prepared: no filesystem access.' );
		}
		if ( ! $fs->exists( $source . '/nf-logbook.php' ) ) {
			return new \WP_Error( 'nflog_update_source', 'The package does not contain nf-logbook.php; the update was refused.' );
		}
		if ( basename( $source ) === $folder ) {
			return $source . '/';
		}
		$target = $remote . '/' . $folder;
		if ( $source === $remote ) {
			// Flat zip: the files are in the working directory itself.
			$entries = $fs->dirlist( $remote );
			if ( ! is_array( $entries ) || ! $fs->mkdir( $target ) ) {
				return new \WP_Error( 'nflog_update_source', 'The Noformat Logbook package could not be prepared.' );
			}
			foreach ( array_keys( $entries ) as $name ) {
				if ( $folder !== $name && ! $fs->move( $remote . '/' . $name, $target . '/' . $name ) ) {
					return new \WP_Error( 'nflog_update_source', 'The Noformat Logbook package could not be prepared.' );
				}
			}
			return $target . '/';
		}
		if ( $fs->exists( $target ) || ! $fs->move( $source, $target ) ) {
			return new \WP_Error( 'nflog_update_source', sprintf( 'The unpacked folder could not be renamed to %s.', $folder ) );
		}
		return $target . '/';
	}

	/**
	 * Upgrader_process_complete: after our own update.
	 *
	 * @param mixed $upgrader  WP_Upgrader.
	 * @param mixed $hookExtra Hook extra.
	 */
	public function processComplete( mixed $upgrader, mixed $hookExtra = [] ): void {
		unset( $upgrader );
		try {
			if ( ! self::isOwnUpdate( $hookExtra ) ) {
				return;
			}
			$this->client->clear();
			if ( null === $this->plugin || $this->plugin->config()->isEnabled( 'PluginThemeListener' ) ) {
				return; // PluginThemeListener logs plugin.updated with the version change.
			}
			$after  = self::installedVersion();
			$before = defined( 'NOFORMAT_LOGGER_VERSION' ) ? (string) NOFORMAT_LOGGER_VERSION : null;
			$logger = $this->plugin->logger();
			$logger->log(
				Event::make( Category::PLUGIN, 'plugin.updated', Severity::NOTICE )
					->object( 'plugin', Release::SLUG )
					->summary( sprintf( 'Plugin %s updated (%s → %s)', Release::SLUG, $before ?? '?', $after ?? '?' ) )
					->change( 'version', $before, $after )
					->context(
						[
							'type'   => 'plugin',
							'action' => 'update',
							'file'   => self::basename(),
							'via'    => 'updater',
						]
					)
			);
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Whether an upgrader run updated this plugin (pure).
	 *
	 * @param mixed $hookExtra Hook extra.
	 */
	public static function isOwnUpdate( mixed $hookExtra ): bool {
		if ( ! is_array( $hookExtra ) || 'plugin' !== ( $hookExtra['type'] ?? null ) || 'update' !== ( $hookExtra['action'] ?? null ) ) {
			return false;
		}
		$plugins = is_array( $hookExtra['plugins'] ?? null ) ? $hookExtra['plugins'] : [];
		if ( isset( $hookExtra['plugin'] ) ) {
			$plugins[] = $hookExtra['plugin'];
		}
		return in_array( self::basename(), $plugins, true );
	}

	/**
	 * Version in the plugin file on disk (after an update it differs from the running constant).
	 */
	private static function installedVersion(): ?string {
		if ( ! function_exists( 'get_file_data' ) ) {
			return null;
		}
		$data = get_file_data( NOFORMAT_LOGGER_FILE, [ 'Version' => 'Version' ] );
		return is_string( $data['Version'] ?? null ) && '' !== $data['Version'] ? $data['Version'] : null;
	}

	/**
	 * Check now: clear WordPress' plugin update cache, forget our cache (ReleaseClient::recheck()), check,
	 * and let WordPress refresh its update list (our filter answers from the fresh cache).
	 *
	 * @return array<string, mixed> State.
	 */
	public function checkNow(): array {
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}
		$state = $this->client->recheck();
		if ( function_exists( 'wp_update_plugins' ) ) {
			try {
				wp_update_plugins();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return $state;
	}

	/**
	 * Status summary (pure).
	 *
	 * @param array<string, mixed> $state     ReleaseClient::state().
	 * @param string               $installed Installed version.
	 * @param string               $source    Source label.
	 * @return array{installed: string, latest: string|null, update_available: bool, source: string, checked_at: int, ok: bool, error: string}
	 */
	public static function summary( array $state, string $installed, string $source ): array {
		$release = is_array( $state['release'] ?? null ) ? $state['release'] : null;
		$latest  = null === $release ? null : (string) ( $release['version'] ?? '' );
		$checked = (int) ( $state['checked_at'] ?? 0 );
		$error   = is_string( $state['error'] ?? null ) ? $state['error'] : '';
		return [
			'installed'        => $installed,
			'latest'           => '' === (string) $latest ? null : $latest,
			'update_available' => null !== $latest && Release::isNewer( $latest, $installed ),
			'source'           => $source,
			'checked_at'       => $checked,
			'ok'               => $checked > 0 && '' === $error,
			'error'            => $error,
		];
	}

	/**
	 * One-line status ("Updates: 1.1.0 installed · latest 1.1.0 · source … · last check … · ok").
	 *
	 * @param array<string, mixed> $summary summary().
	 * @param string               $checked Formatted last-check time.
	 */
	public static function line( array $summary, string $checked ): string {
		$latest = null === $summary['latest'] ? 'latest unknown' : 'latest ' . $summary['latest'] . ( $summary['update_available'] ? ' (update available)' : '' );
		$result = 0 === (int) $summary['checked_at'] ? 'not checked yet' : ( $summary['ok'] ? 'ok' : 'error: ' . $summary['error'] );
		return sprintf(
			'Updates: %1$s installed · %2$s · source %3$s · last check %4$s · %5$s',
			$summary['installed'],
			$latest,
			$summary['source'],
			0 === (int) $summary['checked_at'] ? 'never' : $checked,
			$result
		);
	}
}
