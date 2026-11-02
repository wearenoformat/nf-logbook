<?php
/**
 * Release discovery (GitHub Releases or a self-hosted manifest) with a cache.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Update;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the latest release of the plugin.
 *
 * GitHub mode: one `GET /repos/{repo}/releases/latest` per check (ETag reused,
 * a 304 keeps the cached answer), then the release's manifest.json, read only
 * when the release changed. With NOFORMAT_LOGGER_UPDATE_TOKEN (private repos)
 * every request carries `Authorization: Bearer` and assets are read through
 * the asset API with `Accept: application/octet-stream`.
 *
 * Manifest mode (NOFORMAT_LOGGER_UPDATE_MANIFEST): the manifest URL is the
 * release; GitHub is not contacted.
 *
 * The answer is cached in the transient `noformat_logger_update`, fresh for 6
 * hours (kept 7 days so the ETag survives). Every failure keeps the previous
 * answer and records a human reason; nothing here throws.
 */
final class ReleaseClient {

	public const TRANSIENT = 'noformat_logger_update';

	/** Freshness of a check (6 h). */
	public const FRESH = 21600;

	/** Lifetime of the transient (7 days, so the ETag is reused after the 6 h). */
	public const KEEP = 604800;

	public const TIMEOUT = 10;

	public const DEFAULT_REPO = 'wearenoformat/nf-logbook';

	private const API = 'https://api.github.com';

	/**
	 * Release kept by recheck() when the forced check fails.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $fallback = null;

	/**
	 * Constructor.
	 *
	 * @param string $repo        owner/name.
	 * @param string $token       Optional read-only token (private repositories).
	 * @param string $manifestUrl Optional self-hosted manifest URL.
	 * @param string $userAgent   User-Agent header.
	 */
	public function __construct(
		private string $repo = self::DEFAULT_REPO,
		private string $token = '',
		private string $manifestUrl = '',
		private string $userAgent = 'Noformat-Logger'
	) {
		if ( 1 !== preg_match( '#^[A-Za-z0-9_.\-]{1,100}/[A-Za-z0-9_.\-]{1,100}$#', $this->repo ) ) {
			$this->repo = self::DEFAULT_REPO;
		}
	}

	/**
	 * Client configured from the NOFORMAT_LOGGER_UPDATE_* constants.
	 */
	public static function fromEnvironment(): self {
		$constant = static fn( string $name ): string => defined( $name ) && is_string( constant( $name ) ) ? trim( (string) constant( $name ) ) : '';
		$host     = function_exists( 'home_url' ) ? (string) wp_parse_url( home_url(), PHP_URL_HOST ) : '';
		$version  = defined( 'NOFORMAT_LOGGER_VERSION' ) ? (string) NOFORMAT_LOGGER_VERSION : '0';
		$repo     = $constant( 'NOFORMAT_LOGGER_UPDATE_REPO' );
		return new self(
			'' === $repo ? self::DEFAULT_REPO : $repo,
			$constant( 'NOFORMAT_LOGGER_UPDATE_TOKEN' ),
			$constant( 'NOFORMAT_LOGGER_UPDATE_MANIFEST' ),
			sprintf( 'Noformat-Logger/%s (WordPress plugin; +https://noformat.com; site %s)', $version, '' === $host ? 'unknown' : $host )
		);
	}

	/**
	 * Repository (owner/name).
	 */
	public function repo(): string {
		return $this->repo;
	}

	/**
	 * Whether a token is configured.
	 */
	public function hasToken(): bool {
		return '' !== $this->token;
	}

	/**
	 * Whether the self-hosted manifest mode is on.
	 */
	public function isManifestMode(): bool {
		return '' !== $this->manifestUrl;
	}

	/**
	 * Plain HTTP is allowed only to a loopback manifest (local tests).
	 */
	public function allowsLoopback(): bool {
		if ( ! $this->isManifestMode() ) {
			return false;
		}
		$host = parse_url( $this->manifestUrl, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure.
		return is_string( $host ) && Release::isLoopbackHost( $host );
	}

	/**
	 * Cache key of the configuration: a changed constant invalidates the cache.
	 */
	public function sourceKey(): string {
		return $this->isManifestMode() ? 'manifest:' . $this->manifestUrl : 'github:' . strtolower( $this->repo ) . ( $this->hasToken() ? ':token' : '' );
	}

	/**
	 * Human source ("GitHub wearenoformat/nf-logbook").
	 */
	public function sourceLabel(): string {
		if ( $this->isManifestMode() ) {
			return 'manifest ' . $this->manifestUrl;
		}
		return 'GitHub ' . $this->repo . ( $this->hasToken() ? ' (with token)' : '' );
	}

	/**
	 * Request headers.
	 *
	 * @param string $accept Accept header.
	 * @param bool   $auth   Add the token (GitHub requests only).
	 * @return array<string, string>
	 */
	public function headers( string $accept, bool $auth = true ): array {
		$headers = [
			'Accept'     => $accept,
			'User-Agent' => $this->userAgent,
		];
		if ( ! $this->isManifestMode() ) {
			$headers['X-GitHub-Api-Version'] = '2022-11-28';
		}
		if ( $auth && $this->hasToken() && ! $this->isManifestMode() ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}
		return $headers;
	}

	/**
	 * Headers for downloading an asset (manifest or package) of the current mode.
	 *
	 * @return array<string, string>
	 */
	public function assetHeaders(): array {
		return $this->hasToken() && ! $this->isManifestMode() ? $this->headers( 'application/octet-stream' ) : $this->headers( 'application/octet-stream', false );
	}

	/**
	 * Cached state for the current configuration, null when there is none.
	 *
	 * @return array{source: string, checked_at: int, etag: string, release: array<string, string>|null, error: string}|null
	 */
	public function cached(): ?array {
		$state = get_transient( self::TRANSIENT );
		if ( ! is_array( $state ) || ( $state['source'] ?? '' ) !== $this->sourceKey() ) {
			return null;
		}
		return [
			'source'     => $this->sourceKey(),
			'checked_at' => (int) ( $state['checked_at'] ?? 0 ),
			'etag'       => is_string( $state['etag'] ?? null ) ? $state['etag'] : '',
			'release'    => is_array( $state['release'] ?? null ) ? array_map( 'strval', $state['release'] ) : null,
			'error'      => is_string( $state['error'] ?? null ) ? $state['error'] : '',
		];
	}

	/**
	 * Forget the cached answer (and its ETag).
	 */
	public function clear(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * "Check now": clear the transient (no ETag, full answer) and check. When the
	 * check fails, the release known before is kept, as for a scheduled check.
	 *
	 * @param int|null $now Clock (tests).
	 * @return array{source: string, checked_at: int, etag: string, release: array<string, string>|null, error: string}
	 */
	public function recheck( ?int $now = null ): array {
		$previous = $this->cached();
		$this->clear();
		$this->fallback = $previous['release'] ?? null;
		try {
			return $this->state( true, $now );
		} finally {
			$this->fallback = null;
		}
	}

	/**
	 * Latest release state: cached while fresh, otherwise one check.
	 *
	 * @param bool     $force Check now even when fresh.
	 * @param int|null $now   Clock (tests).
	 * @return array{source: string, checked_at: int, etag: string, release: array<string, string>|null, error: string}
	 */
	public function state( bool $force = false, ?int $now = null ): array {
		$now    = $now ?? time();
		$cached = $this->cached();
		if ( ! $force && null !== $cached && $now - $cached['checked_at'] < self::FRESH && $cached['checked_at'] <= $now ) {
			return $cached;
		}
		try {
			$state = $this->check( $cached, $now );
		} catch ( \Throwable $e ) {
			$state = $this->failed( $cached, $now, 'The update check failed: ' . $e->getMessage() );
		}
		set_transient( self::TRANSIENT, $state, self::KEEP );
		return $state;
	}

	/**
	 * One check.
	 *
	 * @param array<string, mixed>|null $cached Previous state.
	 * @param int                       $now    Clock.
	 * @return array{source: string, checked_at: int, etag: string, release: array<string, string>|null, error: string}
	 */
	private function check( ?array $cached, int $now ): array {
		if ( $this->isManifestMode() ) {
			return $this->checkManifest( $cached, $now );
		}
		$headers = $this->headers( 'application/vnd.github+json' );
		if ( null !== $cached && null !== $cached['release'] && '' !== $cached['etag'] ) {
			$headers['If-None-Match'] = $cached['etag'];
		}
		$response = $this->get( self::API . '/repos/' . $this->repo . '/releases/latest', $headers );
		if ( is_wp_error( $response ) ) {
			return $this->failed( $cached, $now, 'GitHub could not be reached: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 304 === $code && null !== $cached ) {
			return $this->ok( $cached['release'], $cached['etag'], $now );
		}
		if ( 200 !== $code ) {
			return $this->failed( $cached, $now, self::githubReason( $code, $this->hasToken(), $response ) );
		}
		try {
			$release  = Release::parseGitHubRelease( json_decode( (string) wp_remote_retrieve_body( $response ), true ), $this->hasToken() );
			$manifest = $this->fetchManifest( $release['manifest'] );
			$parsed   = Release::parseManifest( $manifest, $release['version'] );
		} catch ( \UnexpectedValueException $e ) {
			return $this->failed( $cached, $now, $e->getMessage() );
		}
		$parsed['url']     = $release['url'];
		$parsed['tag']     = $release['tag'];
		$parsed['package'] = $release['zip'];
		if ( '' === $parsed['released_at'] ) {
			$parsed['released_at'] = $release['published_at'];
		}
		return $this->ok( $parsed, (string) wp_remote_retrieve_header( $response, 'etag' ), $now );
	}

	/**
	 * Manifest mode check.
	 *
	 * @param array<string, mixed>|null $cached Previous state.
	 * @param int                       $now    Clock.
	 * @return array{source: string, checked_at: int, etag: string, release: array<string, string>|null, error: string}
	 */
	private function checkManifest( ?array $cached, int $now ): array {
		if ( ! Release::isAllowedUrl( $this->manifestUrl, true ) ) {
			return $this->failed( $cached, $now, 'NOFORMAT_LOGGER_UPDATE_MANIFEST must be an https:// URL (or http://127.0.0.1 for local tests).' );
		}
		$headers = $this->headers( 'application/json', false );
		if ( null !== $cached && null !== $cached['release'] && '' !== $cached['etag'] ) {
			$headers['If-None-Match'] = $cached['etag'];
		}
		$response = $this->get( $this->manifestUrl, $headers );
		if ( is_wp_error( $response ) ) {
			return $this->failed( $cached, $now, 'The manifest URL could not be reached: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 304 === $code && null !== $cached ) {
			return $this->ok( $cached['release'], $cached['etag'], $now );
		}
		if ( 200 !== $code ) {
			return $this->failed( $cached, $now, sprintf( 'The manifest URL answered HTTP %d.', $code ) );
		}
		try {
			$parsed = Release::parseManifest( json_decode( (string) wp_remote_retrieve_body( $response ), true ) );
		} catch ( \UnexpectedValueException $e ) {
			return $this->failed( $cached, $now, $e->getMessage() );
		}
		if ( ! Release::isAllowedUrl( $parsed['package'], $this->allowsLoopback() ) ) {
			return $this->failed( $cached, $now, 'manifest.json is invalid: download_url must be an https:// URL.' );
		}
		return $this->ok( $parsed, (string) wp_remote_retrieve_header( $response, 'etag' ), $now );
	}

	/**
	 * Download and decode a release's manifest.json.
	 *
	 * @param string $url Asset URL (browser_download_url, or the asset API URL with a token).
	 * @return mixed
	 * @throws \UnexpectedValueException When it cannot be read.
	 */
	private function fetchManifest( string $url ): mixed {
		$response = $this->get( $url, $this->assetHeaders(), $this->hasToken() );
		if ( is_wp_error( $response ) ) {
			throw new \UnexpectedValueException( 'manifest.json could not be downloaded: ' . $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message, escaped where it is displayed.
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			throw new \UnexpectedValueException( sprintf( 'manifest.json could not be downloaded (HTTP %d).', $code ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message, escaped where it is displayed.
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			throw new \UnexpectedValueException( 'manifest.json is not valid JSON.' );
		}
		return $data;
	}

	/**
	 * GET with the plugin's limits. With $stripAuthOnRedirect the redirect is
	 * followed by hand without the Authorization header (GitHub sends asset
	 * downloads to a pre-signed storage URL that must not get the token).
	 *
	 * @param string                $url                 URL.
	 * @param array<string, string> $headers             Headers.
	 * @param bool                  $stripAuthOnRedirect Follow one redirect without Authorization.
	 * @param array<string, mixed>  $extra               Extra request args (stream, filename, timeout).
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get( string $url, array $headers, bool $stripAuthOnRedirect = false, array $extra = [] ): array|\WP_Error {
		if ( ! Release::isAllowedUrl( $url, $this->allowsLoopback() ) ) {
			return new \WP_Error( 'nflog_update_insecure_url', 'Refusing a non-HTTPS URL.' );
		}
		$ua       = $headers['User-Agent'] ?? $this->userAgent;
		$args     = array_merge(
			[
				'timeout'     => self::TIMEOUT,
				'redirection' => $stripAuthOnRedirect ? 0 : 5,
				'user-agent'  => $ua,
				'headers'     => $headers,
			],
			$extra
		);
		$response = wp_remote_get( $url, $args );
		if ( ! $stripAuthOnRedirect || is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( ! in_array( $code, [ 301, 302, 303, 307, 308 ], true ) ) {
			return $response;
		}
		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		if ( ! Release::isAllowedUrl( $location ) ) {
			return new \WP_Error( 'nflog_update_insecure_url', 'GitHub redirected the download to a non-HTTPS URL.' );
		}
		if ( isset( $extra['filename'] ) && is_string( $extra['filename'] ) ) {
			wp_delete_file( $extra['filename'] );
		}
		unset( $headers['Authorization'] );
		$args['headers']     = $headers;
		$args['redirection'] = 5;
		return wp_remote_get( $location, $args );
	}

	/**
	 * Human reason for a GitHub status code.
	 *
	 * @param int                  $code     HTTP status.
	 * @param bool                 $token    Whether a token was sent.
	 * @param array<string, mixed> $response Response (rate-limit headers).
	 */
	public static function githubReason( int $code, bool $token, array $response = [] ): string {
		$remaining = function_exists( 'wp_remote_retrieve_header' ) ? wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ) : '';
		$reset     = function_exists( 'wp_remote_retrieve_header' ) ? (int) wp_remote_retrieve_header( $response, 'x-ratelimit-reset' ) : 0;
		switch ( $code ) {
			case 404:
				return $token
					? 'GitHub answered 404: the repository name is wrong, it has no published release yet, or the token cannot read it'
					: 'GitHub answered 404: the repository is private or the name is wrong (or it has no published release yet)';
			case 401:
				return 'GitHub answered 401: the token is invalid or expired';
			case 403:
			case 429:
				if ( '0' === (string) $remaining || 429 === $code ) {
					return sprintf( 'GitHub answered %d: API rate limit exceeded%s; the cached answer is kept', $code, $reset > 0 ? ' until ' . gmdate( 'H:i', $reset ) . ' UTC' : '' );
				}
				return 'GitHub answered 403: access denied' . ( $token ? ' (check the token\'s permissions)' : '' );
			default:
				return sprintf( 'GitHub answered HTTP %d', $code );
		}
	}

	/**
	 * Successful state.
	 *
	 * @param array<string, string>|null $release Release.
	 * @param string                     $etag    ETag.
	 * @param int                        $now     Clock.
	 * @return array{source: string, checked_at: int, etag: string, release: array<string, string>|null, error: string}
	 */
	private function ok( ?array $release, string $etag, int $now ): array {
		return [
			'source'     => $this->sourceKey(),
			'checked_at' => $now,
			'etag'       => substr( $etag, 0, 200 ),
			'release'    => $release,
			'error'      => null === $release ? 'No release information is cached.' : '',
		];
	}

	/**
	 * Failed state: the previous release (if any) is kept.
	 *
	 * @param array<string, mixed>|null $cached Previous state.
	 * @param int                       $now    Clock.
	 * @param string                    $reason Human reason.
	 * @return array{source: string, checked_at: int, etag: string, release: array<string, string>|null, error: string}
	 */
	private function failed( ?array $cached, int $now, string $reason ): array {
		return [
			'source'     => $this->sourceKey(),
			'checked_at' => $now,
			'etag'       => is_string( $cached['etag'] ?? null ) ? $cached['etag'] : '',
			'release'    => is_array( $cached['release'] ?? null ) ? $cached['release'] : $this->fallback,
			'error'      => $this->redact( $reason ),
		];
	}

	/**
	 * Never let the token reach a stored or printed message.
	 *
	 * @param string $text Text.
	 */
	private function redact( string $text ): string {
		return $this->hasToken() ? str_replace( $this->token, '[token]', $text ) : $text;
	}
}
