<?php
/**
 * Release and manifest parsing for the updater.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Update;

defined( 'ABSPATH' ) || exit;

/**
 * Pure functions over a GitHub release, its manifest.json and the WordPress
 * update array. Invalid input throws \UnexpectedValueException with a message
 * fit for the Status tab.
 *
 * A normalized release (stored in the cache) has the keys: version, tag, name,
 * url, package, sha256, requires, requires_php, tested, released_at,
 * changelog, upgrade_notice.
 */
final class Release {

	public const SLUG     = 'nf-logbook';
	public const ZIP      = 'nf-logbook.zip';
	public const MANIFEST = 'manifest.json';

	/** Semantic version, optional pre-release / build suffix. */
	private const VERSION = '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.\-]+)?$/';

	/** WordPress / PHP version requirement ("6.4", "8.1", "7.1.2"). */
	private const REQUIREMENT = '/^\d+(?:\.\d+){0,2}$/';

	/** Longest changelog kept (bytes). */
	private const CHANGELOG_MAX = 65536;

	/**
	 * Whether a value is a version this updater understands.
	 *
	 * @param mixed $value Value.
	 */
	public static function isVersion( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( self::VERSION, $value );
	}

	/**
	 * Version of a tag ("v1.2.3" or "1.2.3"), null when the tag is not a version.
	 *
	 * @param mixed $tag Tag name.
	 */
	public static function tagVersion( mixed $tag ): ?string {
		if ( ! is_string( $tag ) ) {
			return null;
		}
		$version = 1 === preg_match( '/^v\d/', $tag ) ? substr( $tag, 1 ) : $tag;
		return self::isVersion( $version ) ? $version : null;
	}

	/**
	 * Whether $candidate is newer than $installed.
	 *
	 * @param string $candidate Release version.
	 * @param string $installed Installed version.
	 */
	public static function isNewer( string $candidate, string $installed ): bool {
		return self::isVersion( $candidate ) && version_compare( $candidate, $installed, '>' );
	}

	/**
	 * Whether a download URL may be used: HTTPS, or plain HTTP to the loopback
	 * address when $allowLoopback (self-hosted manifest on 127.0.0.1, tests).
	 *
	 * @param mixed $url           URL.
	 * @param bool  $allowLoopback Allow http://127.0.0.1 / localhost / [::1].
	 */
	public static function isAllowedUrl( mixed $url, bool $allowLoopback = false ): bool {
		if ( ! is_string( $url ) || '' === $url || strlen( $url ) > 2048 ) {
			return false;
		}
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, no WordPress loaded in unit tests.
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( 'https' === $scheme ) {
			return true;
		}
		return $allowLoopback && 'http' === $scheme && self::isLoopbackHost( (string) $parts['host'] );
	}

	/**
	 * Loopback host names.
	 *
	 * @param string $host Host.
	 */
	public static function isLoopbackHost( string $host ): bool {
		return in_array( strtolower( trim( $host, '[]' ) ), [ '127.0.0.1', 'localhost', '::1' ], true );
	}

	/**
	 * A GitHub "latest release" response: version, tag, page URL, the zip and manifest asset URLs.
	 *
	 * @param mixed $data      Decoded JSON.
	 * @param bool  $useApiUrl Asset API URLs (token mode) instead of browser_download_url.
	 * @return array{version: string, tag: string, url: string, published_at: string, zip: string, manifest: string}
	 * @throws \UnexpectedValueException When the release is unusable.
	 */
	public static function parseGitHubRelease( mixed $data, bool $useApiUrl ): array {
		if ( ! is_array( $data ) ) {
			throw new \UnexpectedValueException( 'GitHub returned an unreadable release.' );
		}
		if ( ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			throw new \UnexpectedValueException( 'The latest GitHub release is a draft or a pre-release.' );
		}
		$tag     = is_string( $data['tag_name'] ?? null ) ? $data['tag_name'] : '';
		$version = self::tagVersion( $tag );
		if ( null === $version ) {
			throw new \UnexpectedValueException( sprintf( 'The latest release tag "%s" is not a version (expected v1.2.3).', substr( $tag, 0, 40 ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message, escaped where it is displayed.
		}
		$assets = [];
		foreach ( is_array( $data['assets'] ?? null ) ? $data['assets'] : [] as $asset ) {
			if ( ! is_array( $asset ) || ! is_string( $asset['name'] ?? null ) ) {
				continue;
			}
			$url = $useApiUrl ? ( $asset['url'] ?? null ) : ( $asset['browser_download_url'] ?? null );
			if ( self::isAllowedUrl( $url ) ) {
				$assets[ $asset['name'] ] = (string) $url;
			}
		}
		foreach ( [ self::ZIP, self::MANIFEST ] as $name ) {
			if ( ! isset( $assets[ $name ] ) ) {
				throw new \UnexpectedValueException( sprintf( 'The latest release %s has no %s asset.', $tag, $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message, escaped where it is displayed.
			}
		}
		return [
			'version'      => $version,
			'tag'          => $tag,
			'url'          => self::isAllowedUrl( $data['html_url'] ?? null ) ? (string) $data['html_url'] : '',
			'published_at' => is_string( $data['published_at'] ?? null ) ? $data['published_at'] : '',
			'zip'          => $assets[ self::ZIP ],
			'manifest'     => $assets[ self::MANIFEST ],
		];
	}

	/**
	 * Validate a manifest.json and normalize it into a release.
	 *
	 * @param mixed       $data            Decoded manifest.
	 * @param string|null $expectedVersion Version the manifest must declare (the tag's), null to skip.
	 * @return array<string, string>
	 * @throws \UnexpectedValueException When the manifest is invalid.
	 */
	public static function parseManifest( mixed $data, ?string $expectedVersion = null ): array {
		if ( ! is_array( $data ) ) {
			throw new \UnexpectedValueException( 'manifest.json is not a JSON object.' );
		}
		if ( self::SLUG !== ( $data['slug'] ?? null ) ) {
			throw new \UnexpectedValueException( 'manifest.json is invalid: slug must be "nf-logbook".' );
		}
		if ( ! self::isVersion( $data['version'] ?? null ) ) {
			throw new \UnexpectedValueException( 'manifest.json is invalid: version is missing or not a version.' );
		}
		$version = (string) $data['version'];
		if ( null !== $expectedVersion && $version !== $expectedVersion ) {
			throw new \UnexpectedValueException( sprintf( 'manifest.json declares version %s but the release is %s.', $version, $expectedVersion ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message, escaped where it is displayed.
		}
		$sha = is_string( $data['sha256'] ?? null ) ? strtolower( trim( $data['sha256'] ) ) : '';
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $sha ) ) {
			throw new \UnexpectedValueException( 'manifest.json is invalid: sha256 must be 64 hexadecimal characters.' );
		}
		$download = is_string( $data['download_url'] ?? null ) ? trim( $data['download_url'] ) : '';
		if ( '' === $download ) {
			throw new \UnexpectedValueException( 'manifest.json is invalid: download_url is missing.' );
		}
		$text = static fn( string $key, int $max ): string => is_string( $data[ $key ] ?? null ) ? substr( trim( $data[ $key ] ), 0, $max ) : '';
		$req  = static fn( string $key ): string => is_string( $data[ $key ] ?? null ) && 1 === preg_match( self::REQUIREMENT, trim( $data[ $key ] ) ) ? trim( $data[ $key ] ) : '';
		$name = $text( 'name', 100 );
		return [
			'version'        => $version,
			'tag'            => 'v' . $version,
			'name'           => '' === $name ? 'Noformat Logbook' : $name,
			'url'            => '',
			'package'        => $download,
			'sha256'         => $sha,
			'requires'       => $req( 'requires' ),
			'requires_php'   => $req( 'requires_php' ),
			'tested'         => $req( 'tested' ),
			'released_at'    => $text( 'released_at', 40 ),
			'changelog'      => $text( 'changelog', self::CHANGELOG_MAX ),
			'upgrade_notice' => trim( strip_tags( $text( 'upgrade_notice', 300 ) ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- plain text, no WordPress in unit tests.
		];
	}

	/**
	 * The array returned from update_plugins_{hostname}.
	 *
	 * @param array<string, string> $release   Normalized release.
	 * @param string                $updateUri The plugin's Update URI.
	 * @param string                $homepage  Fallback details URL.
	 * @return array<string, mixed>
	 */
	public static function updateArray( array $release, string $updateUri, string $homepage = '' ): array {
		return [
			'id'             => $updateUri,
			'slug'           => self::SLUG,
			'version'        => $release['version'],
			'url'            => '' !== ( $release['url'] ?? '' ) ? $release['url'] : $homepage,
			'package'        => $release['package'],
			'requires'       => $release['requires'] ?? '',
			'requires_php'   => $release['requires_php'] ?? '',
			'tested'         => $release['tested'] ?? '',
			'upgrade_notice' => $release['upgrade_notice'] ?? '',
			'icons'          => [],
			'banners'        => [],
			'banners_rtl'    => [],
			'translations'   => [],
		];
	}
}
