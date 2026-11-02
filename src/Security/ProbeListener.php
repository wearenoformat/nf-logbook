<?php
/**
 * ProbeListener.
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
use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Suspicious 404 probes on template_redirect (PLAN §4).
 *
 * Only 404 responses are inspected, with one preg_match over a combined pattern.
 * The event carries the pattern name, never the requested path. Events are
 * fingerprinted per pattern and ip hash and aggregate hourly: the default
 * per-action window for probe.suspicious_404 is 3600 s (Config setting
 * fingerprint_windows, filter noformat_logger/fingerprint_window).
 */
final class ProbeListener implements Listener {

	private Logger $logger;

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
		add_action( 'template_redirect', [ $this, 'onTemplateRedirect' ], 0, 0 );
	}

	/**
	 * Default probe patterns: name => regex body (matched case-insensitively
	 * against the decoded request path, without delimiters).
	 *
	 * @return array<string, string>
	 */
	public static function defaultPatterns(): array {
		return [
			'env_file'      => '(?:^|/)\.env(?:\.[a-z0-9_-]+)?$',
			'wp_config'     => 'wp-config',
			'vcs'           => '(?:^|/)\.(?:git|svn|hg)(?:/|$)',
			'sql_dump'      => '\.sql(?:\.(?:gz|zip|bz2|tar))?$',
			'backup_file'   => '\.(?:bak|old|orig|save|swp)$|~$',
			'archive'       => '(?:/wp-content/.*|^/[^/]*)\.(?:zip|tar|tgz|gz|rar|7z)$',
			'phpmyadmin'    => 'phpmyadmin|(?:^|/)(?:pma|myadmin)(?:/|$)',
			'adminer'       => 'adminer',
			'xmlrpc'        => 'xmlrpc\.php',
			'wp_login'      => 'wp-login\.php',
			'vendor'        => '/vendor/',
			'uploads_php'   => '/wp-content/uploads/.*\.(?:php\d?|phtml|phar)$',
			'wlwmanifest'   => 'wlwmanifest\.xml',
			'shell'         => '(?:^|/)(?:shell|c99|r57|wso|alfa)[^/]*\.php$',
			'cgi_bin'       => '(?:^|/)cgi-bin/',
			'server_status' => '(?:^|/)server-(?:status|info)$',
		];
	}

	/**
	 * Patterns after the noformat_logger/probe_patterns filter.
	 *
	 * @return array<string, string>
	 */
	public static function patterns(): array {
		$patterns = self::defaultPatterns();
		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the suspicious 404 patterns: name ([a-z0-9_], ≤ 32 chars) => regex body
			 * without delimiters, matched case-insensitively against the decoded path.
			 *
			 * @param array<string, string> $patterns Patterns.
			 */
			$patterns = apply_filters( 'noformat_logger/probe_patterns', $patterns );
		}
		$out = [];
		foreach ( is_array( $patterns ) ? $patterns : [] as $name => $regex ) {
			$name = substr( (string) preg_replace( '/[^a-z0-9_]/', '_', strtolower( (string) $name ) ), 0, 32 );
			if ( '' !== $name && 1 === preg_match( '/^[a-z_]/', $name ) && is_string( $regex ) && '' !== $regex ) {
				$out[ $name ] = $regex;
			}
		}
		return $out;
	}

	/**
	 * Name of the first pattern matching a path, or null.
	 *
	 * @param string                $path     Request path (query already stripped).
	 * @param array<string, string> $patterns Patterns.
	 */
	public static function match( string $path, array $patterns ): ?string {
		if ( '' === $path || [] === $patterns ) {
			return null;
		}
		$parts = [];
		foreach ( $patterns as $name => $regex ) {
			$parts[] = '(?P<' . $name . '>' . str_replace( '#', '\#', $regex ) . ')';
		}
		$path = strtolower( rawurldecode( $path ) );
		$m    = [];
		if ( 1 !== @preg_match( '#' . implode( '|', $parts ) . '#i', $path, $m ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- filtered patterns may be invalid.
			return null;
		}
		foreach ( array_keys( $patterns ) as $name ) {
			if ( isset( $m[ $name ] ) && '' !== $m[ $name ] ) {
				return $name;
			}
		}
		return null;
	}

	/**
	 * Inspect 404 responses.
	 */
	public function onTemplateRedirect(): void {
		if ( ! is_404() ) {
			return;
		}
		$this->logger->guard(
			'probe.suspicious_404',
			function (): void {
				$context = $this->logger->context();
				$pattern = self::match( (string) $context->route(), self::patterns() );
				if ( null === $pattern ) {
					return;
				}
				$ipHash  = $context->ipHash();
				$request = $context->toArray();
				// The attacker-chosen path is not kept, only the pattern name.
				$request['route'] = null;
				$this->logger->log(
					Event::make( Category::SECURITY, 'probe.suspicious_404', Severity::WARNING )
						->object( 'probe', $pattern )
						->summary( sprintf( 'Suspicious 404 request matching pattern %s', $pattern ) )
						->context(
							[
								'pattern' => $pattern,
								'method'  => $context->method(),
								'ip_hash' => $ipHash,
							]
						)
						->fingerprint( 'probe.suspicious_404', $pattern, $ipHash ?? '-' )
						->withRequest( $request )
				);
			}
		);
	}
}
