<?php
/**
 * Updater: release discovery, cache, manifest, update array, download check, folder rename.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Update;

use Brain\Monkey\Functions;
use Noformat\Logger\Tests\TestCase;
use Noformat\Logger\Update\Changelog;
use Noformat\Logger\Update\Release;
use Noformat\Logger\Update\ReleaseClient;
use Noformat\Logger\Update\Updater;

require_once dirname( __DIR__ ) . '/Stubs/RestRequest.php';

final class UpdaterTest extends TestCase {

	private const REPO = 'wearenoformat/nf-logbook';
	private const API  = 'https://api.github.com/repos/wearenoformat/nf-logbook/releases/latest';
	private const ZIP  = 'https://github.com/wearenoformat/nf-logbook/releases/download/v1.2.0/nf-logbook.zip';
	private const MAN  = 'https://github.com/wearenoformat/nf-logbook/releases/download/v1.2.0/manifest.json';

	/** @var array<string, mixed> */
	private array $transients = [];

	/** @var list<array{url: string, args: array<string, mixed>}> */
	private array $requests = [];

	/** @var array<string, callable|array<string, mixed>> URL => response or fn(args) */
	private array $routes = [];

	/** @var list<string> */
	private array $tmp = [];

	protected function setUp(): void {
		parent::setUp();
		$this->transients = [];
		$this->requests   = [];
		$this->routes     = [];
		Functions\when( 'get_transient' )->alias( fn( string $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ): bool {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( string $key ): bool {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'is_wp_error' )->alias( static fn( $thing ): bool => $thing instanceof \WP_Error );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn( $r ) => is_array( $r ) ? ( $r['response']['code'] ?? '' ) : '' );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn( $r ) => is_array( $r ) ? ( $r['body'] ?? '' ) : '' );
		Functions\when( 'wp_remote_retrieve_header' )->alias( static fn( $r, $name ) => is_array( $r ) ? ( $r['headers'][ strtolower( (string) $name ) ] ?? '' ) : '' );
		Functions\when( 'plugin_basename' )->justReturn( 'nf-logbook/nf-logbook.php' );
		Functions\when( 'wp_tempnam' )->alias(
			function (): string {
				$file        = (string) tempnam( sys_get_temp_dir(), 'nflog-upd' );
				$this->tmp[] = $file;
				return $file;
			}
		);
		Functions\when( 'wp_delete_file' )->alias(
			static function ( string $file ): void {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
		);
		// The pre_http_request seam: every wp_remote_get() lands here.
		Functions\when( 'wp_remote_get' )->alias(
			function ( string $url, array $args = [] ) {
				$this->requests[] = [
					'url'  => $url,
					'args' => $args,
				];
				$route = $this->routes[ $url ] ?? self::response( 404, '{"message":"Not Found"}' );
				$resp  = is_callable( $route ) ? $route( $args ) : $route;
				if ( is_array( $resp ) && ! empty( $args['stream'] ) && isset( $args['filename'] ) ) {
					file_put_contents( (string) $args['filename'], (string) ( $resp['body'] ?? '' ) );
				}
				return $resp;
			}
		);
	}

	protected function tearDown(): void {
		foreach ( $this->tmp as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		parent::tearDown();
	}

	/**
	 * @param array<string, string> $headers Lower-case header names.
	 * @return array<string, mixed>
	 */
	private static function response( int $code, string $body = '', array $headers = [] ): array {
		return [
			'response' => [ 'code' => $code ],
			'body'     => $body,
			'headers'  => $headers,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function manifest( string $version = '1.2.0', string $sha = '' ): array {
		return [
			'name'         => 'Noformat Logbook',
			'slug'         => 'nf-logbook',
			'version'      => $version,
			'requires'     => '6.4',
			'requires_php' => '8.1',
			'tested'       => '7.1',
			'released_at'  => '2026-11-02T10:00:00Z',
			'download_url' => self::ZIP,
			'sha256'       => '' === $sha ? str_repeat( 'a', 64 ) : $sha,
			'changelog'    => "### Added\n\n- **Updater** from `GitHub`.",
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function githubRelease( string $tag = 'v1.2.0' ): array {
		return [
			'tag_name'     => $tag,
			'draft'        => false,
			'prerelease'   => false,
			'html_url'     => 'https://github.com/wearenoformat/nf-logbook/releases/tag/' . $tag,
			'published_at' => '2026-11-02T10:01:00Z',
			'assets'       => [
				[
					'name'                 => 'nf-logbook.zip',
					'url'                  => 'https://api.github.com/repos/wearenoformat/nf-logbook/releases/assets/11',
					'browser_download_url' => self::ZIP,
				],
				[
					'name'                 => 'manifest.json',
					'url'                  => 'https://api.github.com/repos/wearenoformat/nf-logbook/releases/assets/12',
					'browser_download_url' => self::MAN,
				],
			],
		];
	}

	private function publicRelease( string $sha = '' ): void {
		$this->routes[ self::API ] = self::response( 200, (string) json_encode( self::githubRelease() ), [ 'etag' => 'W/"etag-1"' ] );
		$this->routes[ self::MAN ] = self::response( 200, (string) json_encode( self::manifest( '1.2.0', $sha ) ) );
	}

	public function test_version_helpers(): void {
		self::assertSame( '1.2.0', Release::tagVersion( 'v1.2.0' ) );
		self::assertSame( '1.2.0-rc.1', Release::tagVersion( '1.2.0-rc.1' ) );
		self::assertNull( Release::tagVersion( 'release-2' ) );
		self::assertNull( Release::tagVersion( 'v1.2' ) );
		self::assertTrue( Release::isNewer( '1.10.0', '1.9.9' ), 'numeric, not lexical' );
		self::assertTrue( Release::isNewer( '1.1.0', '1.0.1' ) );
		self::assertFalse( Release::isNewer( '1.1.0', '1.1.0' ) );
		self::assertFalse( Release::isNewer( '1.0.9', '1.1.0' ) );
		self::assertFalse( Release::isNewer( 'garbage', '1.0.0' ) );
	}

	public function test_url_policy_is_https_only(): void {
		self::assertTrue( Release::isAllowedUrl( 'https://github.com/x' ) );
		self::assertFalse( Release::isAllowedUrl( 'http://github.com/x' ) );
		self::assertFalse( Release::isAllowedUrl( 'http://127.0.0.1:8080/m.json' ), 'loopback only when allowed' );
		self::assertTrue( Release::isAllowedUrl( 'http://127.0.0.1:8080/m.json', true ) );
		self::assertFalse( Release::isAllowedUrl( 'http://10.0.0.5/m.json', true ) );
		self::assertFalse( Release::isAllowedUrl( 'https://user:pass@github.com/x' ) );
		self::assertFalse( Release::isAllowedUrl( 'file:///etc/passwd', true ) );
	}

	public function test_github_release_parsing(): void {
		$public = Release::parseGitHubRelease( self::githubRelease(), false );
		self::assertSame( '1.2.0', $public['version'] );
		self::assertSame( self::ZIP, $public['zip'] );
		self::assertSame( self::MAN, $public['manifest'] );
		$token = Release::parseGitHubRelease( self::githubRelease(), true );
		self::assertSame( 'https://api.github.com/repos/wearenoformat/nf-logbook/releases/assets/11', $token['zip'], 'asset API URLs with a token' );

		$draft          = self::githubRelease();
		$draft['draft'] = true;
		$this->assertRefused( static fn() => Release::parseGitHubRelease( $draft, false ), 'draft' );
		$pre               = self::githubRelease();
		$pre['prerelease'] = true;
		$this->assertRefused( static fn() => Release::parseGitHubRelease( $pre, false ), 'pre-release' );
		$this->assertRefused( static fn() => Release::parseGitHubRelease( self::githubRelease( 'latest' ), false ), 'not a version' );
		$noManifest           = self::githubRelease();
		$noManifest['assets'] = [ $noManifest['assets'][0] ];
		$this->assertRefused( static fn() => Release::parseGitHubRelease( $noManifest, false ), 'no manifest.json asset' );
		$this->assertRefused( static fn() => Release::parseGitHubRelease( 'nope', false ), 'unreadable' );
	}

	public function test_manifest_validation(): void {
		$release = Release::parseManifest( self::manifest( '1.2.0', str_repeat( 'AB', 32 ) ), '1.2.0' );
		self::assertSame( str_repeat( 'ab', 32 ), $release['sha256'], 'lower-cased' );
		self::assertSame( self::ZIP, $release['package'] );
		self::assertSame( '7.1', $release['tested'] );
		self::assertSame( 'v1.2.0', $release['tag'] );

		$bad         = self::manifest();
		$bad['slug'] = 'other-plugin';
		$this->assertRefused( static fn() => Release::parseManifest( $bad ), 'slug' );
		$bad           = self::manifest();
		$bad['sha256'] = 'abc';
		$this->assertRefused( static fn() => Release::parseManifest( $bad ), 'sha256' );
		$this->assertRefused( static fn() => Release::parseManifest( self::manifest( '1.3.0' ), '1.2.0' ), 'declares version 1.3.0' );
		$bad = self::manifest();
		unset( $bad['download_url'] );
		$this->assertRefused( static fn() => Release::parseManifest( $bad ), 'download_url' );
		$odd                 = self::manifest();
		$odd['requires_php'] = '8.1; DROP';
		self::assertSame( '', Release::parseManifest( $odd )['requires_php'], 'junk requirement dropped' );
	}

	public function test_public_repo_check_headers_and_cache(): void {
		$this->publicRelease();
		$client = new ReleaseClient( self::REPO, '', '', 'UA-test' );
		$state  = $client->state( false, 1000 );
		self::assertSame( '', $state['error'] );
		self::assertSame( '1.2.0', $state['release']['version'] ?? null );
		self::assertSame( self::ZIP, $state['release']['package'] ?? null );
		self::assertCount( 2, $this->requests, 'one API call plus the manifest' );
		$api = $this->requests[0]['args']['headers'];
		self::assertSame( 'application/vnd.github+json', $api['Accept'] );
		self::assertSame( '2022-11-28', $api['X-GitHub-Api-Version'] );
		self::assertSame( 'UA-test', $api['User-Agent'] );
		self::assertArrayNotHasKey( 'Authorization', $api );
		self::assertSame( 10, $this->requests[0]['args']['timeout'] );
		self::assertSame( self::MAN, $this->requests[1]['url'], 'public repo: manifest via browser_download_url' );

		$client->state( false, 1000 + 3600 );
		self::assertCount( 2, $this->requests, 'fresh for 6 hours: no request' );

		$this->routes[ self::API ] = function ( array $args ): array {
			self::assertSame( 'W/"etag-1"', $args['headers']['If-None-Match'] ?? null, 'ETag reused' );
			return self::response( 304 );
		};
		$state = $client->state( false, 1000 + ReleaseClient::FRESH + 1 );
		self::assertCount( 3, $this->requests, '304: no manifest download' );
		self::assertSame( '1.2.0', $state['release']['version'] ?? null, '304 keeps the cached release' );
		self::assertSame( 1000 + ReleaseClient::FRESH + 1, $state['checked_at'] );

		$other = new ReleaseClient( 'someone/else', '', '', 'UA-test' );
		self::assertNull( $other->cached(), 'another repository does not reuse the cache' );
	}

	public function test_token_header_choice_and_redirect_without_token(): void {
		$client = new ReleaseClient( self::REPO, 'github_pat_SECRET', '', 'UA-test' );
		self::assertSame( 'Bearer github_pat_SECRET', $client->headers( 'application/vnd.github+json' )['Authorization'] );
		self::assertSame( 'application/octet-stream', $client->assetHeaders()['Accept'] );
		self::assertSame( 'Bearer github_pat_SECRET', $client->assetHeaders()['Authorization'] );
		self::assertArrayNotHasKey( 'Authorization', ( new ReleaseClient( self::REPO ) )->assetHeaders() );
		self::assertArrayNotHasKey( 'Authorization', ( new ReleaseClient( self::REPO, 'tok', 'https://example.org/m.json' ) )->headers( 'application/json' ), 'never sent to a manifest host' );

		$asset                     = 'https://api.github.com/repos/wearenoformat/nf-logbook/releases/assets/12';
		$signed                    = 'https://objects.githubusercontent.com/signed?x=1';
		$this->routes[ self::API ] = self::response( 200, (string) json_encode( self::githubRelease() ) );
		$this->routes[ $asset ]    = function ( array $args ) use ( $signed ): array {
			self::assertSame( 'application/octet-stream', $args['headers']['Accept'] );
			self::assertSame( 0, $args['redirection'], 'redirect followed by hand' );
			return self::response( 302, '', [ 'location' => $signed ] );
		};
		$this->routes[ $signed ]   = function ( array $args ): array {
			self::assertArrayNotHasKey( 'Authorization', $args['headers'], 'token not sent to the storage host' );
			return self::response( 200, (string) json_encode( self::manifest() ) );
		};
		$state = $client->state( true, 5000 );
		self::assertSame( '', $state['error'] );
		self::assertSame( 'https://api.github.com/repos/wearenoformat/nf-logbook/releases/assets/11', $state['release']['package'] ?? null );
		self::assertSame( 'GitHub ' . self::REPO . ' (with token)', $client->sourceLabel() );
	}

	public function test_api_errors_degrade_and_keep_the_cached_release(): void {
		$client = new ReleaseClient( self::REPO );
		$state  = $client->state( true, 100 );
		self::assertNull( $state['release'] );
		self::assertSame( 'GitHub answered 404: the repository is private or the name is wrong (or it has no published release yet)', $state['error'] );

		$this->publicRelease();
		$client->state( true, 200 );
		$this->routes[ self::API ] = self::response( 403, '{}', [ 'x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '1793613600' ] );
		$state                     = $client->state( true, 300 );
		self::assertStringStartsWith( 'GitHub answered 403: API rate limit exceeded until ', $state['error'] );
		self::assertSame( '1.2.0', $state['release']['version'] ?? null, 'the cached answer is kept' );
		$this->routes[ self::API ] = self::response( 429 );
		self::assertStringStartsWith( 'GitHub answered 429', $client->state( true, 400 )['error'] );
		$this->routes[ self::API ] = new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' );
		$state                     = $client->state( true, 500 );
		self::assertSame( 'GitHub could not be reached: cURL error 28: timed out', $state['error'] );
		self::assertSame( '1.2.0', $state['release']['version'] ?? null );
		$this->routes[ self::API ] = static function (): array {
			throw new \RuntimeException( 'boom' );
		};
		$state = $client->state( true, 600 );
		self::assertSame( 'The update check failed: boom', $state['error'], 'exceptions never escape' );

		$this->routes[ self::API ] = self::response( 500 );
		$state                     = $client->recheck( 650 );
		self::assertSame( 'GitHub answered HTTP 500', $state['error'] );
		self::assertSame( '1.2.0', $state['release']['version'] ?? null, 'Check now clears the cache but keeps the known release when it fails' );
		self::assertSame( '', $state['etag'], 'no ETag after Check now' );

		self::assertStringContainsString( 'token cannot read it', ReleaseClient::githubReason( 404, true ) );
		self::assertStringContainsString( 'token is invalid', ReleaseClient::githubReason( 401, true ) );

		$secret = new ReleaseClient( self::REPO, 'tok_123' );
		$this->routes[ self::API ] = new \WP_Error( 'x', 'failed with tok_123 in it' );
		self::assertStringNotContainsString( 'tok_123', $secret->state( true, 700 )['error'], 'token redacted from messages' );
	}

	public function test_rate_limit_falls_back_to_the_download_link(): void {
		$latest                    = 'https://github.com/wearenoformat/nf-logbook/releases/latest/download/manifest.json';
		$limited                   = self::response( 403, '{}', [ 'x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '1793613600' ] );
		$this->routes[ self::API ] = $limited;
		$this->routes[ $latest ]   = function ( array $args ): array {
			self::assertArrayNotHasKey( 'Authorization', $args['headers'] );
			self::assertGreaterThan( 0, $args['redirection'], 'the download link redirects to storage' );
			return self::response( 200, (string) json_encode( self::manifest( '1.3.0' ) ) );
		};
		$client = new ReleaseClient( self::REPO );
		$state  = $client->recheck( 1000 );
		self::assertSame( '', $state['error'], 'rate limited API, release found through the download link' );
		self::assertSame( '1.3.0', $state['release']['version'] ?? null );
		self::assertSame( self::ZIP, $state['release']['package'] ?? null );
		self::assertSame( str_repeat( 'a', 64 ), $state['release']['sha256'] ?? null, 'the SHA-256 is still checked on download' );
		self::assertSame( 'https://github.com/wearenoformat/nf-logbook/releases/tag/v1.3.0', $state['release']['url'] ?? null );
		self::assertSame( '', $state['etag'], 'no ETag: the next check asks the API in full' );
		self::assertSame( [ self::API, $latest ], array_column( $this->requests, 'url' ) );

		$this->routes[ self::API ] = self::response( 429 );
		self::assertSame( '', $client->state( true, 2000 )['error'], '429 falls back too' );

		$this->routes[ self::API ] = self::response( 403, '{}', [ 'x-ratelimit-remaining' => '12' ] );
		$this->requests            = [];
		self::assertSame( 'GitHub answered 403: access denied', $client->state( true, 3000 )['error'], 'a plain 403 is not a rate limit: no fallback' );
		self::assertCount( 1, $this->requests );

		$evil                      = self::manifest( '1.3.0' );
		$evil['download_url']      = 'https://evil.example/nf-logbook.zip';
		$this->routes[ self::API ] = $limited;
		$this->routes[ $latest ]   = self::response( 200, (string) json_encode( $evil ) );
		$state                     = $client->state( true, 4000 );
		self::assertStringContainsString( 'points outside wearenoformat/nf-logbook releases', $state['error'] );
		self::assertSame( self::ZIP, $state['release']['package'] ?? null, 'the release known before is kept' );

		$this->routes[ $latest ] = self::response( 404 );
		$state                   = $client->state( true, 5000 );
		self::assertStringStartsWith( 'GitHub answered 403: API rate limit exceeded until ', $state['error'] );
		self::assertStringContainsString( 'the download link failed too: manifest.json could not be downloaded (HTTP 404)', $state['error'] );

		$this->requests = [];
		$tokened        = new ReleaseClient( self::REPO, 'github_pat_X' );
		$tokened->state( true, 6000 );
		self::assertSame( [ self::API ], array_column( $this->requests, 'url' ), 'with a token (private repo) there is no fallback' );
	}

	public function test_update_array_and_filter(): void {
		$this->publicRelease();
		$updater = new Updater( null, new ReleaseClient( self::REPO ) );
		self::assertSame( 'kept', $updater->updateFor( 'kept', [], 'other/other.php' ), 'other plugins untouched' );
		$update = $updater->updateFor( false, [], 'nf-logbook/nf-logbook.php' );
		self::assertIsArray( $update );
		foreach ( [ 'id', 'slug', 'version', 'url', 'package', 'requires', 'requires_php', 'tested', 'upgrade_notice' ] as $key ) {
			self::assertArrayHasKey( $key, $update );
		}
		self::assertSame( Updater::UPDATE_URI, $update['id'] );
		self::assertSame( 'nf-logbook', $update['slug'] );
		self::assertSame( '1.2.0', $update['version'] );
		self::assertSame( self::ZIP, $update['package'] );
		self::assertSame( '8.1', $update['requires_php'] );
		self::assertStringContainsString( '/releases/tag/v1.2.0', $update['url'] );

		$this->transients = [];
		$this->routes     = [];
		self::assertFalse( ( new Updater( null, new ReleaseClient( self::REPO ) ) )->updateFor( false, [], 'nf-logbook/nf-logbook.php' ), 'no release: no update, no fatal' );
	}

	public function test_details_modal(): void {
		$this->publicRelease();
		$updater = new Updater( null, new ReleaseClient( self::REPO ) );
		self::assertSame( 'x', $updater->details( 'x', 'plugin_information', (object) [ 'slug' => 'akismet' ] ) );
		self::assertSame( 'x', $updater->details( 'x', 'query_plugins', (object) [ 'slug' => 'nf-logbook' ] ) );
		$info = $updater->details( false, 'plugin_information', (object) [ 'slug' => 'nf-logbook' ] );
		self::assertIsObject( $info );
		self::assertSame( '1.2.0', $info->version );
		self::assertSame( '2026-11-02T10:00:00Z', $info->last_updated );
		self::assertStringContainsString( '<strong>Updater</strong>', $info->sections['changelog'] );
		self::assertStringContainsString( 'SHA-256', $info->sections['description'] );
		self::assertSame( self::ZIP, $info->download_link );
	}

	public function test_changelog_markdown(): void {
		$html = Changelog::toHtml( "### Fixed\n\n- A `code` fix with **bold**\n  continued line\n- [Docs](https://noformat.com) and <script>\n\nPara one\nline two" );
		self::assertSame( '<h5>Fixed</h5><ul><li>A <code>code</code> fix with <strong>bold</strong> continued line</li><li><a href="https://noformat.com">Docs</a> and &lt;script&gt;</li></ul><p>Para one line two</p>', $html );
		self::assertStringNotContainsString( 'href="javascript', Changelog::toHtml( '[x](javascript:alert(1))' ) );
	}

	public function test_download_verifies_sha256(): void {
		$zip = 'PK fake zip bytes';
		$this->publicRelease( hash( 'sha256', $zip ) );
		$this->routes[ self::ZIP ] = self::response( 200, $zip );
		$updater = new Updater( null, new ReleaseClient( self::REPO ) );
		self::assertSame( 'already', $updater->preDownload( 'already', self::ZIP ), 'another filter answered' );
		self::assertFalse( $updater->preDownload( false, 'https://downloads.wordpress.org/plugin/akismet.zip' ), 'not our package' );
		$file = $updater->preDownload( false, self::ZIP );
		self::assertIsString( $file );
		self::assertSame( $zip, file_get_contents( $file ) );
		$download = end( $this->requests );
		self::assertTrue( $download['args']['stream'] );
		self::assertSame( 300, $download['args']['timeout'] );

		$this->routes[ self::ZIP ] = self::response( 200, $zip . '!' );
		$refused                   = $updater->preDownload( false, self::ZIP );
		self::assertInstanceOf( \WP_Error::class, $refused );
		self::assertSame( 'nflog_update_checksum', $refused->get_error_code() );
		self::assertStringContainsString( 'failed the SHA-256 check', $refused->get_error_message() );
		self::assertFileDoesNotExist( (string) end( $this->tmp ), 'the refused file is deleted' );

		$this->routes[ self::ZIP ] = self::response( 404 );
		self::assertSame( 'nflog_update_download', $updater->preDownload( false, self::ZIP )->get_error_code() );

		self::assertSame( 'nflog_update_insecure', $updater->download( [ 'package' => 'http://github.com/x.zip', 'sha256' => str_repeat( 'a', 64 ), 'version' => '1.2.0' ] )->get_error_code() );
	}

	public function test_manifest_mode_allows_loopback_only_for_itself(): void {
		$url                 = 'http://127.0.0.1:8899/manifest.json';
		$m                   = self::manifest( '9.9.9' );
		$m['download_url']   = 'http://127.0.0.1:8899/nf-logbook.zip';
		$this->routes[ $url ] = self::response( 200, (string) json_encode( $m ) );
		$client              = new ReleaseClient( self::REPO, 'tok', $url );
		$state               = $client->state( true, 10 );
		self::assertSame( '', $state['error'] );
		self::assertSame( '9.9.9', $state['release']['version'] ?? null );
		self::assertArrayNotHasKey( 'Authorization', $this->requests[0]['args']['headers'] );
		self::assertSame( 'manifest ' . $url, $client->sourceLabel() );

		$remote               = 'http://updates.example.org/manifest.json';
		$this->routes[ $remote ] = self::response( 200, (string) json_encode( $m ) );
		self::assertStringContainsString( 'must be an https:// URL', ( new ReleaseClient( self::REPO, '', $remote ) )->state( true, 10 )['error'] );
		$https                  = 'https://updates.example.org/manifest.json';
		$this->routes[ $https ] = self::response( 200, (string) json_encode( $m ) );
		self::assertStringContainsString( 'download_url must be an https:// URL', ( new ReleaseClient( self::REPO, '', $https ) )->state( true, 10 )['error'], 'a remote manifest cannot point to plain HTTP' );
	}

	public function test_folder_rename(): void {
		$fs   = new FakeFilesystem();
		$root = sys_get_temp_dir() . '/nflog-src-' . bin2hex( random_bytes( 4 ) );
		mkdir( $root . '/nf-logbook-1.2.0', 0777, true );
		touch( $root . '/nf-logbook-1.2.0/nf-logbook.php' );
		self::assertSame( $root . '/nf-logbook/', Updater::renameSource( $root . '/nf-logbook-1.2.0/', $root . '/', 'nf-logbook', $fs ) );
		self::assertFileExists( $root . '/nf-logbook/nf-logbook.php' );
		self::assertSame( $root . '/nf-logbook/', Updater::renameSource( $root . '/nf-logbook/', $root, 'nf-logbook', $fs ), 'already right' );

		$flat = $root . '-flat';
		mkdir( $flat . '/src', 0777, true );
		touch( $flat . '/nf-logbook.php' );
		self::assertSame( $flat . '/nf-logbook/', Updater::renameSource( $flat, $flat, 'nf-logbook', $fs ) );
		self::assertFileExists( $flat . '/nf-logbook/nf-logbook.php' );
		self::assertDirectoryExists( $flat . '/nf-logbook/src' );

		$other = $root . '-other';
		mkdir( $other . '/something', 0777, true );
		$refused = Updater::renameSource( $other . '/something', $other, 'nf-logbook', $fs );
		self::assertInstanceOf( \WP_Error::class, $refused, 'a package without nf-logbook.php is refused' );

		$updater = new Updater( null, new ReleaseClient( self::REPO ) );
		self::assertSame( '/x/src/', $updater->sourceSelection( '/x/src/', '/x/', null, [ 'plugin' => 'akismet/akismet.php' ] ), 'other plugins untouched' );
		foreach ( [ $root, $flat, $other ] as $dir ) {
			exec( 'rm -rf ' . escapeshellarg( $dir ) );
		}
	}

	public function test_own_update_detection_and_status_line(): void {
		self::assertTrue( Updater::isOwnUpdate( [ 'type' => 'plugin', 'action' => 'update', 'plugins' => [ 'a/a.php', 'nf-logbook/nf-logbook.php' ] ] ) );
		self::assertTrue( Updater::isOwnUpdate( [ 'type' => 'plugin', 'action' => 'update', 'plugin' => 'nf-logbook/nf-logbook.php' ] ) );
		self::assertFalse( Updater::isOwnUpdate( [ 'type' => 'plugin', 'action' => 'install', 'plugin' => 'nf-logbook/nf-logbook.php' ] ) );
		self::assertFalse( Updater::isOwnUpdate( [ 'type' => 'theme', 'action' => 'update' ] ) );

		$this->transients[ ReleaseClient::TRANSIENT ] = [ 'source' => 'x' ];
		Functions\when( 'plugin_basename' )->justReturn( 'nf-logbook/nf-logbook.php' );
		( new Updater( null, new ReleaseClient( self::REPO ) ) )->processComplete( null, [ 'type' => 'plugin', 'action' => 'update', 'plugins' => [ 'nf-logbook/nf-logbook.php' ] ] );
		self::assertArrayNotHasKey( ReleaseClient::TRANSIENT, $this->transients, 'cache cleared after our own update' );

		$ok = Updater::summary(
			[
				'checked_at' => 1793613600,
				'error'      => '',
				'release'    => [ 'version' => '1.2.0' ],
			],
			'1.1.0',
			'GitHub wearenoformat/nf-logbook'
		);
		self::assertTrue( $ok['update_available'] );
		self::assertSame( 'Updates: 1.1.0 installed · latest 1.2.0 (update available) · source GitHub wearenoformat/nf-logbook · last check 2026-11-02 10:00 UTC · ok', Updater::line( $ok, '2026-11-02 10:00 UTC' ) );
		$err = Updater::summary(
			[
				'checked_at' => 5,
				'error'      => 'GitHub answered 404: x',
				'release'    => null,
			],
			'1.1.0',
			'GitHub r/r'
		);
		self::assertSame( 'Updates: 1.1.0 installed · latest unknown · source GitHub r/r · last check t · error: GitHub answered 404: x', Updater::line( $err, 't' ) );
		self::assertStringEndsWith( 'last check never · not checked yet', Updater::line( Updater::summary( [], '1.1.0', 'GitHub r/r' ), 'x' ) );
	}

	private function assertRefused( callable $fn, string $needle ): void {
		try {
			$fn();
			self::fail( 'expected a refusal mentioning ' . $needle );
		} catch ( \UnexpectedValueException $e ) {
			self::assertStringContainsString( $needle, $e->getMessage() );
		}
	}
}

/**
 * The parts of WP_Filesystem_Direct the rename uses.
 */
final class FakeFilesystem {
	public function exists( string $path ): bool {
		return file_exists( $path );
	}
	public function mkdir( string $path ): bool {
		return is_dir( $path ) || mkdir( $path, 0777, true );
	}
	public function move( string $from, string $to ): bool {
		return rename( $from, $to );
	}
	/**
	 * @return array<string, array<string, mixed>>|false
	 */
	public function dirlist( string $path ): array|false {
		$names = scandir( $path );
		if ( false === $names ) {
			return false;
		}
		$out = [];
		foreach ( array_diff( $names, [ '.', '..' ] ) as $name ) {
			$out[ $name ] = [ 'name' => $name ];
		}
		return $out;
	}
}
