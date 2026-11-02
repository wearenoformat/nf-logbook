<?php
/**
 * PluginThemeListener.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Listeners;

use Noformat\Logger\Category;
use Noformat\Logger\Config;
use Noformat\Logger\Event;
use Noformat\Logger\Listener;
use Noformat\Logger\Logger;
use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Plugins and themes: activation, deactivation, install/update/delete, theme switch,
 * and the built-in file editor (PLAN §4, §5).
 *
 * File editor: the AJAX save (wp_ajax_edit-theme-plugin-file, WordPress ≥ 4.9) and
 * legacy POSTs to theme-editor.php / plugin-editor.php are observed at priority 0.
 * WordPress has no hook after a successful save, so the target file's hash is
 * taken before the save and compared again on shutdown: unchanged means
 * "attempt" (warning), changed means the file was edited (critical, PLAN §5).
 * File contents are never read into an event.
 */
final class PluginThemeListener implements Listener {

	private Logger $logger;

	/**
	 * Plugin version cache by plugin file.
	 *
	 * @var array<string, string|null>
	 */
	private array $versions = [];

	/**
	 * Versions captured before an upgrade (key "plugin:<file>" / "theme:<stylesheet>").
	 *
	 * @var array<string, string|null>
	 */
	private array $before = [];

	/**
	 * Pending file editor observation.
	 *
	 * @var array{kind: string, slug: string, file: string, path: string|null, hash: string|null, via: string}|null
	 */
	private ?array $editor = null;

	/**
	 * Availability.
	 */
	public static function isAvailable(): bool {
		return true;
	}

	/**
	 * Add hooks.
	 *
	 * @param Logger $logger Logger.
	 * @param Config $config Config.
	 */
	public function register( Logger $logger, Config $config ): void {
		unset( $config );
		$this->logger = $logger;
		add_action( 'activated_plugin', [ $this, 'onActivated' ], 10, 2 );
		add_action( 'deactivated_plugin', [ $this, 'onDeactivated' ], 10, 2 );
		add_action( 'delete_plugin', [ $this, 'onDeletingPlugin' ], 10, 1 );
		add_action( 'deleted_plugin', [ $this, 'onDeletedPlugin' ], 10, 2 );
		add_filter( 'upgrader_pre_install', [ $this, 'onPreInstall' ], 0, 2 );
		add_action( 'upgrader_process_complete', [ $this, 'onUpgraded' ], 10, 2 );
		add_action( 'switch_theme', [ $this, 'onSwitchTheme' ], 10, 3 );
		add_action( 'delete_theme', [ $this, 'onDeletingTheme' ], 10, 1 );
		add_action( 'deleted_theme', [ $this, 'onDeletedTheme' ], 10, 2 );
		add_action( 'wp_ajax_edit-theme-plugin-file', [ $this, 'onEditorAjax' ], 0, 0 );
		add_action( 'load-theme-editor.php', [ $this, 'onEditorScreen' ], 0, 0 );
		add_action( 'load-plugin-editor.php', [ $this, 'onEditorScreen' ], 0, 0 );
	}

	/**
	 * Plugin activated.
	 *
	 * @param mixed $plugin      Plugin file relative to the plugins directory.
	 * @param mixed $networkWide Network-wide.
	 */
	public function onActivated( mixed $plugin, mixed $networkWide = false ): void {
		$this->logger->guard( 'plugin.activated', fn() => $this->pluginEvent( 'plugin.activated', 'activated', (string) $plugin, (bool) $networkWide ) );
	}

	/**
	 * Plugin deactivated.
	 *
	 * @param mixed $plugin      Plugin file.
	 * @param mixed $networkWide Network-wide.
	 */
	public function onDeactivated( mixed $plugin, mixed $networkWide = false ): void {
		$this->logger->guard( 'plugin.deactivated', fn() => $this->pluginEvent( 'plugin.deactivated', 'deactivated', (string) $plugin, (bool) $networkWide ) );
	}

	/**
	 * Before a plugin is deleted: remember its version.
	 *
	 * @param mixed $plugin Plugin file.
	 */
	public function onDeletingPlugin( mixed $plugin ): void {
		$this->logger->guard( 'plugin.deleting', fn() => $this->pluginVersion( (string) $plugin ) );
	}

	/**
	 * Plugin deleted (or deletion failed).
	 *
	 * @param mixed $plugin  Plugin file.
	 * @param mixed $deleted Whether it was deleted.
	 */
	public function onDeletedPlugin( mixed $plugin, mixed $deleted = true ): void {
		$this->logger->guard(
			'plugin.deleted',
			function () use ( $plugin, $deleted ): void {
				$file = (string) $plugin;
				$slug = self::pluginSlug( $file );
				$ok   = (bool) $deleted;
				$this->logger->log(
					Event::make( Category::PLUGIN, 'plugin.deleted', $ok ? Severity::NOTICE : Severity::ERROR )
						->object( 'plugin', $slug )
						->summary( $ok ? sprintf( 'Plugin %s deleted', $slug ) : sprintf( 'Plugin %s could not be deleted', $slug ) )
						->context(
							[
								'file'    => $file,
								'version' => $this->versions[ $file ] ?? null,
								'deleted' => $ok,
							]
						)
				);
			}
		);
	}

	/**
	 * Before an upgrader installs a package: remember the current version.
	 *
	 * @param mixed $response  Filter value (returned unchanged).
	 * @param mixed $hookExtra Hook extra.
	 */
	public function onPreInstall( mixed $response, mixed $hookExtra = [] ): mixed {
		$this->logger->guard(
			'upgrader.pre_install',
			function () use ( $hookExtra ): void {
				if ( ! is_array( $hookExtra ) ) {
					return;
				}
				if ( isset( $hookExtra['plugin'] ) && is_string( $hookExtra['plugin'] ) ) {
					$this->before[ 'plugin:' . $hookExtra['plugin'] ] = $this->readPluginVersion( $hookExtra['plugin'] );
				}
				if ( isset( $hookExtra['theme'] ) && is_string( $hookExtra['theme'] ) ) {
					$this->before[ 'theme:' . $hookExtra['theme'] ] = self::themeVersion( $hookExtra['theme'] );
				}
			}
		);
		return $response;
	}

	/**
	 * Upgrader finished (install/update of plugins and themes; core is CoreListener's).
	 *
	 * @param mixed $upgrader  WP_Upgrader.
	 * @param mixed $hookExtra Hook extra: type, action, bulk, plugin(s), theme(s).
	 */
	public function onUpgraded( mixed $upgrader, mixed $hookExtra = [] ): void {
		$this->logger->guard(
			'upgrader.complete',
			function () use ( $upgrader, $hookExtra ): void {
				if ( ! is_array( $hookExtra ) ) {
					return;
				}
				$type   = isset( $hookExtra['type'] ) ? (string) $hookExtra['type'] : '';
				$action = isset( $hookExtra['action'] ) ? (string) $hookExtra['action'] : '';
				if ( ! in_array( $type, [ 'plugin', 'theme' ], true ) || ! in_array( $action, [ 'install', 'update' ], true ) ) {
					return;
				}
				$items = $this->upgradedItems( $type, $action, $upgrader, $hookExtra );
				$error = self::upgraderError( $upgrader );
				$auto  = self::isAutoUpdate();
				$bulk  = ! empty( $hookExtra['bulk'] );
				$verb  = 'install' === $action ? 'installed' : 'updated';
				if ( [] === $items ) {
					$items = [ '' ];
				}
				foreach ( $items as $item ) {
					$slug   = '' === $item ? null : ( 'plugin' === $type ? self::pluginSlug( $item ) : $item );
					$after  = '' === $item ? null : ( 'plugin' === $type ? $this->readPluginVersion( $item ) : self::themeVersion( $item ) );
					$before = $this->before[ $type . ':' . $item ] ?? null;
					$event  = Event::make( 'plugin' === $type ? Category::PLUGIN : Category::THEME, $type . '.' . $verb, null === $error ? Severity::NOTICE : Severity::ERROR )
						->object( $type, $slug )
						->summary(
							sprintf(
								'%s %s %s%s',
								ucfirst( $type ),
								$slug ?? '(unknown)',
								null === $error ? $verb : $action . ' failed',
								null !== $after && null === $error ? ' (' . ( null !== $before && 'update' === $action ? $before . ' → ' : '' ) . $after . ')' : ''
							)
						)
						->context(
							[
								'type'        => $type,
								'action'      => $action,
								'bulk'        => $bulk,
								'auto_update' => $auto,
								'file'        => 'plugin' === $type && '' !== $item ? $item : null,
								'error_code'  => $error,
							]
						);
					if ( 'update' === $action && null === $error ) {
						$event->change( 'version', $before, $after );
					} elseif ( null !== $after ) {
						$event->context( [ 'version' => $after ] );
					}
					$this->logger->log( $event );
				}
			}
		);
	}

	/**
	 * Theme switched.
	 *
	 * @param mixed $newName  New theme name (not logged).
	 * @param mixed $newTheme New WP_Theme.
	 * @param mixed $oldTheme Old WP_Theme.
	 */
	public function onSwitchTheme( mixed $newName, mixed $newTheme = null, mixed $oldTheme = null ): void {
		unset( $newName );
		$this->logger->guard(
			'theme.switched',
			function () use ( $newTheme, $oldTheme ): void {
				$new         = $newTheme instanceof \WP_Theme ? $newTheme->get_stylesheet() : (string) get_option( 'stylesheet' );
				$old         = $oldTheme instanceof \WP_Theme ? $oldTheme->get_stylesheet() : null;
				$event       = Event::make( Category::THEME, 'theme.switched', Severity::NOTICE )
					->object( 'theme', $new )
					->summary( sprintf( 'Theme switched from %s to %s', $old ?? '(none)', $new ) )
					->change( 'stylesheet', $old, $new )
					->context(
						[
							'version'     => $newTheme instanceof \WP_Theme ? (string) $newTheme->get( 'Version' ) : null,
							'old_version' => $oldTheme instanceof \WP_Theme ? (string) $oldTheme->get( 'Version' ) : null,
						]
					);
				$newTemplate = $newTheme instanceof \WP_Theme ? $newTheme->get_template() : null;
				$oldTemplate = $oldTheme instanceof \WP_Theme ? $oldTheme->get_template() : null;
				if ( $newTemplate !== $oldTemplate || $newTemplate !== $new ) {
					$event->change( 'template', $oldTemplate, $newTemplate );
				}
				$this->logger->log( $event );
			}
		);
	}

	/**
	 * Before a theme is deleted: remember its version.
	 *
	 * @param mixed $stylesheet Stylesheet.
	 */
	public function onDeletingTheme( mixed $stylesheet ): void {
		$this->logger->guard(
			'theme.deleting',
			function () use ( $stylesheet ): void {
				$this->before[ 'theme:' . (string) $stylesheet ] = self::themeVersion( (string) $stylesheet );
			}
		);
	}

	/**
	 * Theme deleted (or deletion failed).
	 *
	 * @param mixed $stylesheet Stylesheet.
	 * @param mixed $deleted    Whether it was deleted.
	 */
	public function onDeletedTheme( mixed $stylesheet, mixed $deleted = true ): void {
		$this->logger->guard(
			'theme.deleted',
			function () use ( $stylesheet, $deleted ): void {
				$slug = (string) $stylesheet;
				$ok   = (bool) $deleted;
				$this->logger->log(
					Event::make( Category::THEME, 'theme.deleted', $ok ? Severity::NOTICE : Severity::ERROR )
						->object( 'theme', $slug )
						->summary( $ok ? sprintf( 'Theme %s deleted', $slug ) : sprintf( 'Theme %s could not be deleted', $slug ) )
						->context(
							[
								'version' => $this->before[ 'theme:' . $slug ] ?? null,
								'deleted' => $ok,
							]
						)
				);
			}
		);
	}

	/**
	 * AJAX save from the plugin/theme file editor (priority 0, before WordPress handles it).
	 */
	public function onEditorAjax(): void {
		$this->logger->guard( 'editor.ajax', fn() => $this->observeEditor( 'ajax' ) );
	}

	/**
	 * Legacy POST to theme-editor.php / plugin-editor.php.
	 */
	public function onEditorScreen(): void {
		$this->logger->guard(
			'editor.screen',
			function (): void {
				if ( 'POST' !== $this->logger->context()->method() ) {
					return;
				}
				if ( defined( 'DISALLOW_FILE_EDIT' ) && constant( 'DISALLOW_FILE_EDIT' ) ) {
					return;
				}
				$this->observeEditor( 'screen' );
			}
		);
	}

	/**
	 * Shutdown after an editor request: log attempt (warning) or edit (critical).
	 */
	public function onEditorShutdown(): void {
		$this->logger->guard(
			'editor.shutdown',
			function (): void {
				$editor = $this->editor;
				if ( null === $editor ) {
					return;
				}
				$this->editor = null;
				$after        = null === $editor['path'] ? null : self::fileHash( $editor['path'] );
				$changed      = null !== $after && $after !== $editor['hash'];
				$this->logger->log(
					Event::make( Category::SECURITY, 'editor.file_edited', $changed ? Severity::CRITICAL : Severity::WARNING )
						->object( $editor['kind'], $editor['slug'] )
						->summary(
							sprintf(
								$changed ? '%s %s: file %s edited in the built-in editor' : '%s %s: attempt to edit file %s in the built-in editor',
								ucfirst( $editor['kind'] ),
								$editor['slug'],
								$editor['file']
							)
						)
						->context(
							[
								'file'    => $editor['file'],
								'changed' => $changed,
								'attempt' => ! $changed,
								'via'     => $editor['via'],
								'exists'  => null !== $after,
							]
						)
				);
			}
		);
	}

	/**
	 * Capture the editor target and schedule the shutdown comparison.
	 *
	 * @param string $via "ajax" or "screen".
	 */
	private function observeEditor( string $via ): void {
		$target = self::editorTarget( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- observe only; WordPress verifies the nonce itself.
		if ( null === $target || null !== $this->editor ) {
			return;
		}
		$target['hash'] = null === $target['path'] ? null : self::fileHash( $target['path'] );
		$target['via']  = $via;
		$this->editor   = $target;
		add_action( 'shutdown', [ $this, 'onEditorShutdown' ], 1, 0 );
	}

	/**
	 * Resolve the editor target from request data. File paths are relative to
	 * the plugins directory (plugins) or the theme directory (themes).
	 *
	 * @param array<string|int, mixed> $post Request data.
	 * @return array{kind: string, slug: string, file: string, path: string|null}|null
	 */
	public static function editorTarget( array $post ): ?array {
		$file = isset( $post['file'] ) && is_string( $post['file'] ) ? self::cleanRelative( wp_unslash( $post['file'] ) ) : '';
		if ( '' === $file ) {
			return null;
		}
		if ( ! empty( $post['plugin'] ) && is_string( $post['plugin'] ) ) {
			$plugin = self::cleanRelative( wp_unslash( $post['plugin'] ) );
			$slug   = self::pluginSlug( '' === $plugin ? $file : $plugin );
			return [
				'kind' => 'plugin',
				'slug' => $slug,
				'file' => $file,
				'path' => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/' . $file : null,
			];
		}
		if ( ! empty( $post['theme'] ) && is_string( $post['theme'] ) ) {
			$stylesheet = self::cleanRelative( wp_unslash( $post['theme'] ) );
			if ( '' === $stylesheet || str_contains( $stylesheet, '/' ) ) {
				return null;
			}
			$path = null;
			if ( function_exists( 'wp_get_theme' ) ) {
				$theme = wp_get_theme( $stylesheet );
				$path  = $theme->exists() ? $theme->get_stylesheet_directory() . '/' . $file : null;
			}
			return [
				'kind' => 'theme',
				'slug' => $stylesheet,
				'file' => $file,
				'path' => $path,
			];
		}
		return null;
	}

	/**
	 * Relative path without traversal, control characters or leading slashes (max 191).
	 *
	 * @param mixed $value Raw value.
	 */
	public static function cleanRelative( mixed $value ): string {
		$value = is_string( $value ) ? (string) preg_replace( '/[^\x21-\x7e]/', '', $value ) : '';
		$value = ltrim( str_replace( '\\', '/', $value ), '/' );
		if ( '' === $value || str_contains( $value, '..' ) || str_contains( $value, ':' ) ) {
			return '';
		}
		return substr( $value, 0, 191 );
	}

	/**
	 * Plugin slug from its file ("akismet/akismet.php" → "akismet", "hello.php" → "hello").
	 *
	 * @param string $file Plugin file.
	 */
	public static function pluginSlug( string $file ): string {
		$file = ltrim( str_replace( '\\', '/', $file ), '/' );
		$dir  = dirname( $file );
		if ( '.' !== $dir && '' !== $dir ) {
			return explode( '/', $dir )[0];
		}
		return (string) preg_replace( '/\.php$/', '', basename( $file ) );
	}

	/**
	 * Log activation/deactivation.
	 *
	 * @param string $action      Action.
	 * @param string $verb        Verb for the summary.
	 * @param string $file        Plugin file.
	 * @param bool   $networkWide Network-wide.
	 */
	private function pluginEvent( string $action, string $verb, string $file, bool $networkWide ): void {
		$slug = self::pluginSlug( $file );
		$this->logger->log(
			Event::make( Category::PLUGIN, $action, Severity::NOTICE )
				->object( 'plugin', $slug )
				->summary( sprintf( 'Plugin %s %s%s', $slug, $verb, $networkWide ? ' network-wide' : '' ) )
				->context(
					[
						'file'         => $file,
						'version'      => $this->pluginVersion( $file ),
						'network_wide' => $networkWide,
					]
				)
		);
	}

	/**
	 * Cached plugin version.
	 *
	 * @param string $file Plugin file.
	 */
	private function pluginVersion( string $file ): ?string {
		if ( ! array_key_exists( $file, $this->versions ) ) {
			$this->versions[ $file ] = $this->readPluginVersion( $file );
		}
		return $this->versions[ $file ];
	}

	/**
	 * Read a plugin version from its header (uncached).
	 *
	 * @param string $file Plugin file.
	 */
	private function readPluginVersion( string $file ): ?string {
		if ( '' === $file || str_contains( $file, '..' ) || ! defined( 'WP_PLUGIN_DIR' ) ) {
			return null;
		}
		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( ! is_file( $path ) ) {
			return null;
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data    = get_plugin_data( $path, false, false );
		$version = isset( $data['Version'] ) ? (string) $data['Version'] : '';
		return '' === $version ? null : substr( $version, 0, 32 );
	}

	/**
	 * Theme version.
	 *
	 * @param string $stylesheet Stylesheet.
	 */
	private static function themeVersion( string $stylesheet ): ?string {
		if ( '' === $stylesheet || ! function_exists( 'wp_get_theme' ) ) {
			return null;
		}
		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return null;
		}
		$version = (string) $theme->get( 'Version' );
		return '' === $version ? null : substr( $version, 0, 32 );
	}

	/**
	 * Items affected by an upgrader run.
	 *
	 * @param string               $type      plugin|theme.
	 * @param string               $action    install|update.
	 * @param mixed                $upgrader  Upgrader.
	 * @param array<string, mixed> $hookExtra Hook extra.
	 * @return list<string> Plugin files or theme stylesheets.
	 */
	private function upgradedItems( string $type, string $action, mixed $upgrader, array $hookExtra ): array {
		$items  = [];
		$plural = $type . 's';
		if ( isset( $hookExtra[ $plural ] ) && is_array( $hookExtra[ $plural ] ) ) {
			$items = array_map( 'strval', array_values( $hookExtra[ $plural ] ) );
		} elseif ( isset( $hookExtra[ $type ] ) && is_string( $hookExtra[ $type ] ) ) {
			$items = [ $hookExtra[ $type ] ];
		} elseif ( 'install' === $action && is_object( $upgrader ) ) {
			if ( 'plugin' === $type && method_exists( $upgrader, 'plugin_info' ) ) {
				$info = $upgrader->plugin_info();
				if ( is_string( $info ) && '' !== $info ) {
					$items = [ $info ];
				}
			} elseif ( 'theme' === $type && method_exists( $upgrader, 'theme_info' ) ) {
				$info = $upgrader->theme_info();
				if ( $info instanceof \WP_Theme ) {
					$items = [ $info->get_stylesheet() ];
				}
			}
		}
		return array_slice( array_values( array_filter( $items, static fn( string $i ): bool => '' !== $i ) ), 0, 50 );
	}

	/**
	 * Error code of a failed upgrader run, null on success.
	 *
	 * @param mixed $upgrader Upgrader.
	 */
	private static function upgraderError( mixed $upgrader ): ?string {
		if ( ! is_object( $upgrader ) ) {
			return null;
		}
		$result = property_exists( $upgrader, 'result' ) ? $upgrader->result : null;
		if ( $result instanceof \WP_Error ) {
			return substr( (string) $result->get_error_code(), 0, 64 );
		}
		$skin = property_exists( $upgrader, 'skin' ) ? $upgrader->skin : null;
		if ( is_object( $skin ) && property_exists( $skin, 'result' ) && $skin->result instanceof \WP_Error ) {
			return substr( (string) $skin->result->get_error_code(), 0, 64 );
		}
		return null;
	}

	/**
	 * Whether the current run is a background (automatic) update.
	 */
	private static function isAutoUpdate(): bool {
		return wp_doing_cron() || did_action( 'pre_auto_update' ) > 0;
	}

	/**
	 * Hash of a file for change detection (files over 5 MB: size+mtime only).
	 *
	 * @param string $path Absolute path.
	 */
	private static function fileHash( string $path ): ?string {
		clearstatcache( true, $path );
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		$size = filesize( $path );
		if ( false !== $size && $size > 5 * 1024 * 1024 ) {
			return 'size:' . $size . ':' . (string) filemtime( $path );
		}
		$hash = hash_file( 'sha256', $path );
		return false === $hash ? null : $hash;
	}
}
