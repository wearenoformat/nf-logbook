<?php
/**
 * Absolute path → canonical relative path and component.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Errors\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Maps absolute file paths to canonical paths relative to the WordPress root
 * ("wp-includes/…", "wp-content/plugins/<slug>/…", "wp-content/themes/<slug>/…",
 * "wp-content/mu-plugins/…") so events never carry server paths, and derives the
 * component ("core", "plugin:<slug>", "theme:<slug>", "mu-plugin:<slug>",
 * "drop-in:<file>", "wp-content", "unknown").
 *
 * Canonical prefixes are used even when WP_CONTENT_DIR / WP_PLUGIN_DIR live
 * elsewhere or a plugin is symlinked, so the component is stable across hosts.
 * Files outside every known root become "[outside]/<basename>".
 */
final class PathMapper {

	public const OUTSIDE = '[outside]/';

	/**
	 * Prefix for files inside a phar archive outside the known roots ("[phar:wp]/vendor/…").
	 */
	public const PHAR = '[phar:';

	/**
	 * Absolute prefix (with trailing slash) => canonical relative prefix, longest first.
	 *
	 * @var array<string, string>
	 */
	private array $roots = [];

	/**
	 * Absolute prefix of the logger's own src/ directory (errors there are not logged).
	 *
	 * @var string
	 */
	private string $ownSrc;

	private static ?self $shared = null;

	/**
	 * Hasher for upload file names (salted in production, see Plugin).
	 *
	 * @var \Closure(string): string|null
	 */
	private static ?\Closure $hasher = null;

	/**
	 * Set the hasher used for upload file names (8 hex).
	 *
	 * @param callable(string): string|null $hasher Hasher; null restores the unsalted fallback.
	 */
	public static function useHasher( ?callable $hasher ): void {
		self::$hasher = null === $hasher ? null : \Closure::fromCallable( $hasher );
	}

	/**
	 * Replace file names below wp-content/uploads/ by "{hash8}" plus their
	 * extension (uploads are named by the people who upload them: names, invoice
	 * numbers, …). Directory names (year/month, plugin folders) are kept.
	 *
	 * @param string $text Text with canonical relative paths.
	 */
	public static function hashUploads( string $text ): string {
		if ( ! str_contains( $text, 'wp-content/uploads/' ) ) {
			return $text;
		}
		return (string) preg_replace_callback(
			'#(wp-content/uploads/(?:[\w.\-@]+/)*)([^/\s\'"():,]+?)(\.[A-Za-z0-9]{1,8})?(?=$|[\s\'"():,])#',
			static function ( array $m ): string {
				$name = $m[2] . ( $m[3] ?? '' );
				$hash = null === self::$hasher ? substr( hash( 'sha256', 'file:' . $name ), 0, 8 ) : ( self::$hasher )( $name );
				return $m[1] . '{' . $hash . '}' . strtolower( $m[3] ?? '' );
			},
			$text
		);
	}

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $roots  Absolute prefix => canonical relative prefix ('' for the WordPress root).
	 * @param string                $ownSrc Absolute path of the plugin's src/ directory.
	 */
	public function __construct( array $roots, string $ownSrc = '' ) {
		foreach ( $roots as $abs => $rel ) {
			$abs = self::normalizeDir( (string) $abs );
			if ( '/' === $abs || '' === $abs ) {
				continue;
			}
			$this->roots[ $abs ] = '' === $rel ? '' : rtrim( $rel, '/' ) . '/';
		}
		uksort( $this->roots, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );
		$this->ownSrc = '' === $ownSrc ? '' : self::normalizeDir( $ownSrc );
	}

	/**
	 * Shared instance built from the WordPress constants (cached per request).
	 */
	public static function shared(): self {
		if ( null === self::$shared ) {
			self::$shared = self::fromEnvironment();
		}
		return self::$shared;
	}

	/**
	 * Reset the shared instance (tests).
	 */
	public static function reset(): void {
		self::$shared = null;
	}

	/**
	 * Build from ABSPATH, WP_CONTENT_DIR, WP_PLUGIN_DIR, WPMU_PLUGIN_DIR, theme roots
	 * and symlinked plugin paths registered by WordPress.
	 */
	public static function fromEnvironment(): self {
		$roots = [];
		$add   = static function ( string $abs, string $rel ) use ( &$roots ): void {
			if ( '' === $abs ) {
				return;
			}
			$roots[ $abs ] = $rel;
			$real          = realpath( $abs );
			if ( false !== $real && $real !== $abs ) {
				$roots[ $real ] = $rel;
			}
		};
		$add( (string) ABSPATH, '' );
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$add( (string) WP_CONTENT_DIR, 'wp-content' );
		}
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$add( (string) WP_PLUGIN_DIR, 'wp-content/plugins' );
		}
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$add( (string) WPMU_PLUGIN_DIR, 'wp-content/mu-plugins' );
		}
		global $wp_theme_directories, $wp_plugin_paths;
		if ( is_array( $wp_theme_directories ) ) {
			foreach ( $wp_theme_directories as $dir ) {
				$add( (string) $dir, 'wp-content/themes' );
			}
		}
		if ( is_array( $wp_plugin_paths ) ) {
			// Symlinked plugins: real directory => directory inside WP_PLUGIN_DIR.
			foreach ( $wp_plugin_paths as $linked => $real ) {
				$slug = basename( (string) $linked );
				$add( (string) $real, 'wp-content/plugins/' . $slug );
			}
		}
		$ownSrc = '';
		if ( defined( 'NOFORMAT_LOGGER_DIR' ) ) {
			$dir = (string) NOFORMAT_LOGGER_DIR;
			$add( $dir, 'wp-content/plugins/' . basename( rtrim( $dir, '/\\' ) ) );
			$ownSrc = rtrim( $dir, '/\\' ) . '/src/';
			$real   = realpath( $ownSrc );
			$ownSrc = false === $real ? $ownSrc : $real;
		}
		return new self( $roots, $ownSrc );
	}

	/**
	 * Canonical relative path (forward slashes, no leading slash).
	 *
	 * @param string $path Absolute or relative path.
	 */
	public function relative( string $path ): string {
		if ( '' === $path ) {
			return '';
		}
		$path = str_replace( '\\', '/', $path );
		if ( 1 === preg_match( '#^([a-z][a-z0-9+.\-]*)://(.*)$#i', $path, $m ) ) {
			return 'phar' === strtolower( $m[1] ) ? $this->pharRelative( $m[2] ) : self::OUTSIDE . basename( $path );
		}
		foreach ( $this->roots as $abs => $rel ) {
			if ( str_starts_with( $path, $abs ) ) {
				return $rel . substr( $path, strlen( $abs ) );
			}
		}
		if ( ! str_starts_with( $path, '/' ) && ! preg_match( '#^[A-Za-z]:/#', $path ) ) {
			return ltrim( $path, './' );
		}
		return self::OUTSIDE . basename( $path );
	}

	/**
	 * Replace absolute paths inside free text (error messages) by relative ones.
	 *
	 * @param string $text Text.
	 */
	public function relativeInText( string $text ): string {
		if ( '' === $text ) {
			return '';
		}
		$text = str_replace( '\\', '/', $text );
		// Phar paths first, parked behind placeholders so the generic pass below leaves them alone.
		$parked = [];
		if ( str_contains( $text, 'phar://' ) ) {
			$text = (string) preg_replace_callback(
				'#phar://(/[^\s\'"():,]+)#i',
				function ( array $m ) use ( &$parked ): string {
					$parked[] = $this->pharRelative( $m[1] );
					return "\x00" . ( count( $parked ) - 1 ) . "\x00";
				},
				$text
			);
		}
		foreach ( $this->roots as $abs => $rel ) {
			if ( str_contains( $text, $abs ) ) {
				$text = str_replace( $abs, $rel, $text );
			}
			$bare = rtrim( $abs, '/' );
			if ( '' !== $bare && str_contains( $text, $bare ) ) {
				$text = (string) preg_replace( '#' . preg_quote( $bare, '#' ) . '(?![\w.\-])#', '' === $rel ? '.' : rtrim( $rel, '/' ), $text );
			}
		}
		// Any remaining absolute path (outside the known roots): keep the basename only.
		$text = (string) preg_replace_callback(
			'#(?<![\w.:/\-])(/(?:[\w.\-@]+/)+)([\w.\-@]+)#',
			static fn( array $m ): string => self::OUTSIDE . $m[2],
			$text
		);
		foreach ( $parked as $i => $mapped ) {
			$text = str_replace( "\x00" . $i . "\x00", $mapped, $text );
		}
		return self::hashUploads( $text );
	}

	/**
	 * Canonical path of a file inside a phar archive (the part after "phar://").
	 *
	 * An archive inside a known root maps like any file there
	 * ("wp-content/plugins/x/lib.phar/src/A.php"); any other archive keeps its
	 * base name and the path inside it ("[phar:wp]/vendor/wp-cli/…"), never the
	 * directory it lives in.
	 *
	 * @param string $inner Absolute archive path plus path inside the archive.
	 */
	private function pharRelative( string $inner ): string {
		if ( 1 === preg_match( '#^(/.+?\.phar)(/.*)?$#i', $inner, $m ) ) {
			$archive = $m[1];
			$rest    = $m[2] ?? '';
		} elseif ( 1 === preg_match( '#^(/.+?)(/vendor/.*)$#', $inner, $m ) ) {
			$archive = $m[1];
			$rest    = $m[2];
		} else {
			return self::OUTSIDE . basename( $inner );
		}
		$mapped = $this->relative( $archive );
		if ( ! str_starts_with( $mapped, self::OUTSIDE ) ) {
			return $mapped . $rest;
		}
		return self::PHAR . basename( $archive ) . ']' . $rest;
	}

	/**
	 * Whether a file belongs to the logger's own src/ directory.
	 *
	 * @param string $path Absolute path.
	 */
	public function isOwn( string $path ): bool {
		if ( '' === $this->ownSrc || '' === $path ) {
			return false;
		}
		return str_starts_with( str_replace( '\\', '/', $path ), $this->ownSrc );
	}

	/**
	 * Component of a canonical relative path.
	 *
	 * @param string $relative Output of relative().
	 */
	public static function component( string $relative ): string {
		if ( '' === $relative || str_starts_with( $relative, self::OUTSIDE ) ) {
			return 'unknown';
		}
		if ( str_starts_with( $relative, self::PHAR ) ) {
			if ( str_contains( $relative, ']/vendor/wp-cli/' ) ) {
				return 'wp-cli';
			}
			$end = strpos( $relative, ']' );
			return 'phar:' . ( false === $end ? 'unknown' : substr( $relative, strlen( self::PHAR ), $end - strlen( self::PHAR ) ) );
		}
		$parts = explode( '/', $relative );
		if ( 'wp-content' !== $parts[0] ) {
			return 'core';
		}
		$group = $parts[1] ?? '';
		$item  = $parts[2] ?? '';
		if ( ! isset( $parts[2] ) ) {
			return str_ends_with( $group, '.php' ) ? 'drop-in:' . substr( $group, 0, -4 ) : 'wp-content';
		}
		return match ( $group ) {
			'plugins'    => 'plugin:' . self::slug( $item, isset( $parts[3] ) ),
			'themes'     => 'theme:' . $item,
			'mu-plugins' => 'mu-plugin:' . self::slug( $item, isset( $parts[3] ) ),
			default      => 'wp-content',
		};
	}

	/**
	 * Plugin slug: directory name, or file name without .php for single-file plugins.
	 *
	 * @param string $item  First path segment below the group.
	 * @param bool   $isDir Whether more segments follow.
	 */
	private static function slug( string $item, bool $isDir ): string {
		return ! $isDir && str_ends_with( $item, '.php' ) ? substr( $item, 0, -4 ) : $item;
	}

	/**
	 * Absolute directory with forward slashes and exactly one trailing slash.
	 *
	 * @param string $dir Directory.
	 */
	private static function normalizeDir( string $dir ): string {
		$dir = str_replace( '\\', '/', $dir );
		return rtrim( $dir, '/' ) . '/';
	}
}
