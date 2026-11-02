<?php
/**
 * GitHub emulator for tests/integration/smoke-update-private.sh (mu-plugin).
 *
 * Answers the plugin updater's requests to api.github.com, github.com and
 * release-assets.githubusercontent.com from a `pre_http_request` filter, the way
 * GitHub does for a PRIVATE repository, so the token path runs with no network
 * and no real token. Every other host is left alone (WordPress and WP-CLI still
 * talk to api.wordpress.org).
 *
 * Configuration (constants in wp-config.php, set by the smoke test):
 *   NFLOG_GH_EMU_REPO   owner/name of the emulated repository
 *   NFLOG_GH_EMU_TOKEN  the only accepted token
 *   NFLOG_GH_EMU_DIR    directory with noformat-logger.zip and manifest.json
 *   NFLOG_GH_EMU_LOG    JSON-lines log, one line per intercepted request
 *
 * Behaviour:
 *   GET /repos/{repo}/releases/latest   no auth 404, wrong token 401, matching
 *                                       If-None-Match 304, else 200 + ETag
 *   GET /repos/{repo}/releases/assets/N no/wrong auth 404; octet-stream 302 to
 *                                       release-assets.githubusercontent.com; other
 *                                       Accept 200 with JSON metadata
 *   release-assets.githubusercontent.com       400 when Authorization is sent (as S3),
 *                                       else the file (streamed when asked)
 *   github.com/{repo}/releases/download 404 (private repositories)
 *
 * @package Noformat\Logger
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'NFLOG_GH_EMU_REPO' ) || ! defined( 'NFLOG_GH_EMU_TOKEN' ) || ! defined( 'NFLOG_GH_EMU_DIR' ) ) {
	return;
}

const NFLOG_GH_EMU_ETAG = '"nflog-emu-etag-1"';

/**
 * Header value from request args, case-insensitive.
 *
 * @param array<string, mixed> $args Request args.
 * @param string               $name Header name.
 */
function nflog_gh_emu_header( array $args, string $name ): string {
	$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : [];
	foreach ( $headers as $key => $value ) {
		if ( 0 === strcasecmp( (string) $key, $name ) ) {
			return is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
	}
	if ( 0 === strcasecmp( $name, 'user-agent' ) && isset( $args['user-agent'] ) ) {
		return (string) $args['user-agent'];
	}
	return '';
}

/**
 * A response array shaped like WP_Http's.
 *
 * @param int                   $code     Status.
 * @param string                $body     Body.
 * @param array<string, string> $headers  Response headers.
 * @param array<string, mixed>  $args     Request args (stream / filename).
 * @return array<string, mixed>
 */
function nflog_gh_emu_response( int $code, string $body, array $headers, array $args ): array {
	$lower = [];
	foreach ( $headers as $key => $value ) {
		$lower[ strtolower( $key ) ] = $value;
	}
	$filename = null;
	if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) && is_string( $args['filename'] ) ) {
		// WP_Http writes the body of a streamed request to the file, whatever the status.
		file_put_contents( $args['filename'], $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture.
		$filename = $args['filename'];
		$body     = '';
	}
	return [
		'headers'       => class_exists( '\WpOrg\Requests\Utility\CaseInsensitiveDictionary' ) ? new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( $lower ) : $lower,
		'body'          => $body,
		'response'      => [
			'code'    => $code,
			'message' => get_status_header_desc( $code ),
		],
		'cookies'       => [],
		'filename'      => $filename,
		'http_response' => null,
	];
}

/**
 * JSON response.
 *
 * @param int                   $code    Status.
 * @param mixed                 $data    Payload.
 * @param array<string, mixed>  $args    Request args.
 * @param array<string, string> $headers Extra headers.
 * @return array<string, mixed>
 */
function nflog_gh_emu_json( int $code, mixed $data, array $args, array $headers = [] ): array {
	return nflog_gh_emu_response( $code, (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES ), array_merge( [ 'content-type' => 'application/json; charset=utf-8' ], $headers ), $args );
}

/**
 * The emulated answer for one request.
 *
 * @param string               $url     URL.
 * @param array<string, mixed> $args    Request args.
 * @param bool                 $hasAuth Authorization header present.
 * @param bool                 $authOk  It is the accepted Bearer token.
 * @return array<string, mixed>
 */
function nflog_gh_emu_answer( string $url, array $args, bool $hasAuth, bool $authOk ): array {
	$repo   = (string) NFLOG_GH_EMU_REPO;
	$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
	$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	$dir    = rtrim( (string) NFLOG_GH_EMU_DIR, '/' );
	$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
	$assets = [
		'101' => 'noformat-logger.zip',
		'102' => 'manifest.json',
	];

	if ( 'https' !== $scheme || 'GET' !== $method ) {
		return nflog_gh_emu_json( 400, [ 'message' => 'Emulator: only HTTPS GET is served' ], $args );
	}

	if ( 'api.github.com' === $host ) {
		if ( '/repos/' . $repo . '/releases/latest' === $path ) {
			if ( ! $hasAuth ) {
				return nflog_gh_emu_json( 404, [ 'message' => 'Not Found' ], $args );
			}
			if ( ! $authOk ) {
				return nflog_gh_emu_json( 401, [ 'message' => 'Bad credentials' ], $args );
			}
			if ( NFLOG_GH_EMU_ETAG === nflog_gh_emu_header( $args, 'If-None-Match' ) ) {
				return nflog_gh_emu_response( 304, '', [ 'etag' => NFLOG_GH_EMU_ETAG ], $args );
			}
			$list = [];
			foreach ( $assets as $id => $name ) {
				$list[] = [
					'id'                   => (int) $id,
					'name'                 => $name,
					'url'                  => 'https://api.github.com/repos/' . $repo . '/releases/assets/' . $id,
					'browser_download_url' => 'https://github.com/' . $repo . '/releases/download/v9.9.9/' . $name,
				];
			}
			return nflog_gh_emu_json(
				200,
				[
					'tag_name'     => 'v9.9.9',
					'draft'        => false,
					'prerelease'   => false,
					'html_url'     => 'https://github.com/' . $repo . '/releases/tag/v9.9.9',
					'published_at' => '2026-01-01T00:00:00Z',
					'assets'       => $list,
				],
				$args,
				[ 'etag' => NFLOG_GH_EMU_ETAG ]
			);
		}
		if ( 1 === preg_match( '#^/repos/' . preg_quote( $repo, '#' ) . '/releases/assets/(\d+)$#', $path, $m ) && isset( $assets[ $m[1] ] ) ) {
			if ( ! $authOk ) {
				return nflog_gh_emu_json( 404, [ 'message' => 'Not Found' ], $args );
			}
			$name = $assets[ $m[1] ];
			if ( 'application/octet-stream' === nflog_gh_emu_header( $args, 'Accept' ) ) {
				return nflog_gh_emu_response( 302, '', [ 'location' => 'https://release-assets.githubusercontent.com/nflog-emu/' . $name . '?X-Amz-Signature=emulated' ], $args );
			}
			return nflog_gh_emu_json(
				200,
				[
					'id'   => (int) $m[1],
					'name' => $name,
					'url'  => $url,
				],
				$args
			);
		}
		return nflog_gh_emu_json( 404, [ 'message' => 'Not Found' ], $args );
	}

	if ( 'release-assets.githubusercontent.com' === $host ) {
		if ( $hasAuth ) {
			return nflog_gh_emu_response( 400, 'Only one auth mechanism allowed', [ 'content-type' => 'application/xml' ], $args );
		}
		$name = basename( $path );
		if ( 0 === strpos( $path, '/nflog-emu/' ) && in_array( $name, $assets, true ) && is_readable( $dir . '/' . $name ) ) {
			$bytes = (string) file_get_contents( $dir . '/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local fixture.
			return nflog_gh_emu_response( 200, $bytes, [ 'content-type' => 'application/octet-stream' ], $args );
		}
		return nflog_gh_emu_response( 404, 'NoSuchKey', [], $args );
	}

	// github.com: browser URLs of a private repository are not served anonymously.
	return nflog_gh_emu_response( 404, 'Not Found', [], $args );
}

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) {
		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		if ( ! in_array( $host, [ 'api.github.com', 'github.com', 'release-assets.githubusercontent.com' ], true ) ) {
			return $preempt;
		}
		$args     = is_array( $args ) ? $args : [];
		$auth     = nflog_gh_emu_header( $args, 'Authorization' );
		$hasAuth  = '' !== $auth;
		$authOk   = hash_equals( 'Bearer ' . (string) NFLOG_GH_EMU_TOKEN, $auth );
		$response = nflog_gh_emu_answer( (string) $url, $args, $hasAuth, $authOk );
		if ( defined( 'NFLOG_GH_EMU_LOG' ) && '' !== (string) NFLOG_GH_EMU_LOG ) {
			$line = [
				'url'           => (string) $url,
				'method'        => strtoupper( (string) ( $args['method'] ?? 'GET' ) ),
				'has_auth'      => $hasAuth,
				'auth_ok'       => $authOk,
				'accept'        => nflog_gh_emu_header( $args, 'Accept' ),
				'api_version'   => nflog_gh_emu_header( $args, 'X-GitHub-Api-Version' ),
				'if_none_match' => nflog_gh_emu_header( $args, 'If-None-Match' ),
				'stream'        => ! empty( $args['stream'] ),
				'status'        => $response['response']['code'],
			];
			file_put_contents( (string) NFLOG_GH_EMU_LOG, wp_json_encode( $line, JSON_UNESCAPED_SLASHES ) . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test log.
		}
		return $response;
	},
	10,
	3
);
