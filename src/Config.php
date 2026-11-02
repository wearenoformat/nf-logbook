<?php
/**
 * Configuration.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Settings resolution: constants > option `nflog_settings` > defaults.
 *
 * Every scalar setting can be pinned with a constant named
 * NOFORMAT_LOGGER_<UPPER_KEY>, e.g. NOFORMAT_LOGGER_RETENTION_DAYS. Module toggles
 * can be pinned with NOFORMAT_LOGGER_DISABLED_MODULES (comma separated short
 * class names, e.g. "AcfListener,MailListener").
 */
final class Config {

	public const OPTION = 'nflog_settings';

	/**
	 * Resolved settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings;

	/**
	 * Names of settings pinned by a constant.
	 *
	 * @var list<string>
	 */
	private array $pinned = [];

	/**
	 * Resolved technical key patterns (technicalKeyPatterns()).
	 *
	 * @var list<string>|null
	 */
	private ?array $technicalPatterns = null;

	/**
	 * Build from explicit sources (used by tests and fromEnvironment()).
	 *
	 * @param array<string, mixed> $option    Stored option value.
	 * @param array<string, mixed> $constants Constant overrides keyed by setting name.
	 */
	public function __construct( array $option = [], array $constants = [] ) {
		$defaults = self::defaults();
		$settings = $defaults;

		foreach ( $option as $key => $value ) {
			if ( array_key_exists( $key, $defaults ) ) {
				$settings[ $key ] = self::coerce( $defaults[ $key ], $value );
			}
		}
		foreach ( $constants as $key => $value ) {
			if ( array_key_exists( $key, $defaults ) ) {
				$settings[ $key ] = self::coerce( $defaults[ $key ], $value );
				$this->pinned[]   = $key;
			}
		}
		if ( isset( $option['modules'] ) && is_array( $option['modules'] ) ) {
			$settings['modules'] = [];
			foreach ( $option['modules'] as $module => $enabled ) {
				$settings['modules'][ (string) $module ] = (bool) $enabled;
			}
		}
		if ( isset( $constants['disabled_modules'] ) ) {
			$disabled = is_array( $constants['disabled_modules'] )
				? $constants['disabled_modules']
				: explode( ',', (string) $constants['disabled_modules'] );
			foreach ( $disabled as $module ) {
				$module = trim( (string) $module );
				if ( '' !== $module ) {
					$settings['modules'][ $module ] = false;
					$this->pinned[]                 = 'module:' . $module;
				}
			}
		}

		$this->settings = $settings;
	}

	/**
	 * Build from wp-config constants and the stored option, then apply filters.
	 */
	public static function fromEnvironment(): self {
		$option = function_exists( 'get_option' ) ? get_option( self::OPTION, [] ) : [];
		if ( ! is_array( $option ) ) {
			$option = [];
		}
		$constants = [];
		foreach ( array_keys( self::defaults() ) as $key ) {
			$name = 'NOFORMAT_LOGGER_' . strtoupper( $key );
			if ( defined( $name ) ) {
				$constants[ $key ] = constant( $name );
			}
		}
		if ( defined( 'NOFORMAT_LOGGER_DISABLED_MODULES' ) ) {
			$constants['disabled_modules'] = constant( 'NOFORMAT_LOGGER_DISABLED_MODULES' );
		}
		$config = new self( $option, $constants );

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the resolved settings array.
			 *
			 * @param array<string, mixed> $settings Settings.
			 */
			$filtered = apply_filters( 'noformat_logger/settings', $config->settings );
			if ( is_array( $filtered ) ) {
				$config->settings = array_merge( $config->settings, $filtered );
			}
		}
		return $config;
	}

	/**
	 * Defaults. Types here drive coercion of option/constant values.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'retention_days'           => 90,
			'hard_max_days'            => 180,
			'max_rows'                 => 500000,
			'log_debug'                => false,
			'full_content_diff'        => false,
			'allow_http'               => false,
			'ip_allowlist'             => [],
			'rate_limit_per_minute'    => 120,
			'delete_data_on_uninstall' => false,
			'events_visible_to'        => 'administrators',
			'system_log_visible_to'    => 'administrators',
			'buffer_max'               => 200,
			'anonymous_per_minute'     => 120,
			'fingerprint_window'       => 60,
			'fingerprint_window_debug' => 3600,
			'fingerprint_windows'      => self::defaultFingerprintWindows(),
			'modules'                  => [],
			'sensitive_keys'           => self::defaultSensitiveKeys(),
			'safe_keys'                => self::defaultSafeKeys(),
			'ignored_options'          => self::defaultIgnoredOptions(),
			'ignored_meta_keys'        => self::defaultIgnoredMetaKeys(),
		];
	}

	/**
	 * Per-action aggregation windows in seconds (override the severity-based window).
	 *
	 * Slow scans and probes are aggregated over longer windows so one client
	 * probing for an hour is one stored event with a count, not 60.
	 *
	 * @return array<string, int>
	 */
	public static function defaultFingerprintWindows(): array {
		return [
			'probe.suspicious_404' => 3600,
			'rest.users_probe'     => 900,
			'xmlrpc.call'          => 900,
		];
	}

	/**
	 * Sensitive key patterns (CONTRACT §2). Case-insensitive substrings; a value
	 * wrapped in slashes is treated as a regular expression (`ip` and `*_ip` are
	 * anchored so "description" or "zip" do not match). Values under every other
	 * key are still scrubbed of e-mail addresses and IPs (Redactor).
	 *
	 * @return list<string>
	 */
	public static function defaultSensitiveKeys(): array {
		return [
			'email',
			'mail',
			'phone',
			'tel',
			'mobile',
			'fax',
			'address',
			'street',
			'zipcode',
			'zip_code',
			'postcode',
			'postal',
			'password',
			'pass',
			'pwd',
			'secret',
			'token',
			'key',
			'auth',
			'salt',
			'nonce',
			'cookie',
			'session',
			'credential',
			'private',
			'signature',
			'iban',
			'card',
			'cvv',
			'cvc',
			'ssn',
			'bank',
			'account_number',
			'dob',
			'birth',
			'name',
			'login',
			'billing',
			'shipping',
			'remote_addr',
			'client_ip',
			'remote_ip',
			'/^ip$/',
			'/_ip$/',
			'/^comment_author/',
			'adminhash',
			'user_agent',
		];
	}

	/**
	 * Keys never redacted even if they match a pattern: structural identifiers our
	 * own listeners use. Also never redacted: keys ending in _id, _ids, _hash, _count.
	 *
	 * @return list<string>
	 */
	public static function defaultSafeKeys(): array {
		return [
			'post_author',
			'author',
			'meta_key',
			'option_name',
			'post_name',
			'slug',
			'field_key',
			'field_name',
			'setting_id',
			'taxonomy',
			'menu_name',
			'term_name',
			'key_id',
			'sha256_8',
			'blogname',
			'blogdescription',
		];
	}

	/**
	 * Options never logged (glob patterns with *).
	 *
	 * @return list<string>
	 */
	public static function defaultIgnoredOptions(): array {
		return [
			'_transient_*',
			'_site_transient_*',
			'cron',
			'recently_activated',
			'*_last_checked',
			'rewrite_rules',
			'akismet_spam_count',
			'recently_edited',
			'auto_updater.lock',
			'core_updater.lock',
			'*_lock',
			'can_compress_scripts',
			'db_upgraded',
			'nflog_*',
			'action_scheduler_*',
			'_wp_suggested_policy_text_has_changed',
			'jetpack_*_last_*',
			'wp_force_deactivated_plugins',
			'auto_plugin_theme_update_emails',
			'finished_updating_comment_type',
			'theme_switched',
			'fresh_site',
			'site_logo',
			'wp_attachment_pages_enabled',
			'dismissed_update_core',
			'uninstall_plugins',
			'recovery_keys',
			'recovery_mode_email_last_sent',
			'https_detection_errors',
			'widget_recent-comments',
			'user_count',
			'health-check-site-status-result',
		];
	}

	/**
	 * Meta keys never logged (glob patterns with *).
	 *
	 * @return list<string>
	 */
	public static function defaultIgnoredMetaKeys(): array {
		return [
			'_edit_lock',
			'_edit_last',
			'_encloseme',
			'_pingme',
			'_oembed_*',
			'session_tokens',
			'wp_user-settings*',
			'*user-settings*',
			'closedpostboxes_*',
			'metaboxhidden_*',
			'meta-box-order_*',
			'manageedit-*',
			'*_capabilities',
			'*_user_level',
			'_wp_attachment_metadata',
			'_wp_old_slug',
			'_wp_old_date',
			'_wp_trash_meta_*',
			'_wp_desired_post_slug',
			'last_update',
			'community-events-location',
			'dismissed_wp_pointers',
			'_wp_attachment_backup_sizes',
			'nav_menu_recently_edited',
			'wp_dashboard_quick_press_last_post_id',
			'wp_persisted_preferences',
			'_thumbnail_id_lock',
			'nflog_*',
		];
	}

	/**
	 * Raw setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $fallback Fallback when absent.
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->settings[ $key ] ?? $fallback;
	}

	/**
	 * Integer setting.
	 *
	 * @param string $key Setting name.
	 */
	public function int( string $key ): int {
		$value = $this->settings[ $key ] ?? 0;
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Boolean setting.
	 *
	 * @param string $key Setting name.
	 */
	public function bool( string $key ): bool {
		return (bool) ( $this->settings[ $key ] ?? false );
	}

	/**
	 * All resolved settings.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		return $this->settings;
	}

	/**
	 * Whether a setting is pinned by a wp-config constant.
	 *
	 * @param string $key Setting name or "module:<ShortName>".
	 */
	public function isPinned( string $key ): bool {
		return in_array( $key, $this->pinned, true );
	}

	/**
	 * Whether a listener/module is enabled (default: yes).
	 *
	 * @param string $classOrSlug Fully-qualified class name or short name.
	 */
	public function isEnabled( string $classOrSlug ): bool {
		$slug    = self::moduleSlug( $classOrSlug );
		$modules = is_array( $this->settings['modules'] ?? null ) ? $this->settings['modules'] : [];
		return ! array_key_exists( $slug, $modules ) || (bool) $modules[ $slug ];
	}

	/**
	 * Short module name ("PostListener") from an FQCN.
	 *
	 * @param string $classOrSlug Class or slug.
	 */
	public static function moduleSlug( string $classOrSlug ): string {
		$pos = strrpos( $classOrSlug, '\\' );
		return false === $pos ? $classOrSlug : substr( $classOrSlug, $pos + 1 );
	}

	/**
	 * Sensitive key patterns after the noformat_logger/sensitive_keys filter.
	 *
	 * @return list<string>
	 */
	public function sensitiveKeys(): array {
		return $this->filteredList( 'sensitive_keys', 'noformat_logger/sensitive_keys' );
	}

	/**
	 * Safe keys after the noformat_logger/safe_keys filter.
	 *
	 * @return list<string>
	 */
	public function safeKeys(): array {
		return $this->filteredList( 'safe_keys', 'noformat_logger/safe_keys' );
	}

	/**
	 * Ignored option patterns after the noformat_logger/ignored_options filter.
	 *
	 * @return list<string>
	 */
	public function ignoredOptions(): array {
		return $this->filteredList( 'ignored_options', 'noformat_logger/ignored_options' );
	}

	/**
	 * Ignored meta key patterns after the noformat_logger/ignored_meta_keys filter.
	 *
	 * @return list<string>
	 */
	public function ignoredMetaKeys(): array {
		return $this->filteredList( 'ignored_meta_keys', 'noformat_logger/ignored_meta_keys' );
	}

	/**
	 * Whether an option name is on the ignore list.
	 *
	 * @param string $name Option name.
	 */
	public function isIgnoredOption( string $name ): bool {
		return self::matchesGlob( $name, $this->ignoredOptions() );
	}

	/**
	 * Whether a meta key is on the ignore list.
	 *
	 * @param string $key Meta key.
	 */
	public function isIgnoredMetaKey( string $key ): bool {
		return self::matchesGlob( $key, $this->ignoredMetaKeys() );
	}

	/**
	 * Default technical key patterns: option names and meta keys that are
	 * unambiguously machine-written plugin state (notification queues, version
	 * caches, batch cursors, locks, telemetry). Entries starting with `/` are
	 * regular expressions, other entries are `*` globs, entries starting with `!`
	 * are exemptions (a glob or regex that is never technical, checked first).
	 * Kept to state-like words and vendor state prefixes on purpose: settings an
	 * admin saves from a settings screen (POST) must stay `info`; writes during
	 * reads are handled by the write-on-read rule (RequestContext::machineWriteReason()).
	 *
	 * @return list<string>
	 */
	public static function defaultTechnicalKeyPatterns(): array {
		return [
			// Saved settings stay visible even under a vendor state prefix (imagify_settings, …).
			'!*_settings',
			'!*_options',
			'/(^|_)(dismissed|pointers?|version_info|usage_analytics|sticky_admin_messages|last_checked|last_run|last_update_check|transient|batch|queue|lock|telemetry|install_date|activation_time|review_notice)(_|$)/i',
			// Notification and notice queues: only as the last word (cookie_notice_options is a setting).
			'/(^|_)(notifications?|notices|admin_notice)$/i',
			'wp_yoast_*',
			'_yoast_*',
			'imagify_*',
			'gsmtp_*',
			'gform_version*',
			'rg_gforms_message',
			'_site_transient_*',
			'_transient_*',
		];
	}

	/**
	 * Technical key patterns after the noformat_logger/technical_key_patterns filter.
	 *
	 * @return list<string>
	 */
	public function technicalKeyPatterns(): array {
		if ( null === $this->technicalPatterns ) {
			$list = self::defaultTechnicalKeyPatterns();
			if ( function_exists( 'apply_filters' ) ) {
				/**
				 * Filter the technical key patterns (option names and meta keys logged at debug
				 * with context.technical). `/regex/flags`, `*` globs, `!` prefix for exemptions.
				 *
				 * @param list<string> $list Patterns.
				 */
				$list = apply_filters( 'noformat_logger/technical_key_patterns', $list );
			}
			$this->technicalPatterns = is_array( $list ) ? array_values( array_filter( array_map( 'strval', $list ), static fn( string $p ): bool => '' !== $p && '!' !== $p ) ) : [];
		}
		return $this->technicalPatterns;
	}

	/**
	 * Whether an option name or meta key is machine-written plugin state
	 * (technicalKeyPatterns(); exemptions win).
	 *
	 * @param string $key Option name or meta key.
	 */
	public function isTechnicalKey( string $key ): bool {
		if ( '' === $key ) {
			return false;
		}
		$include = [];
		foreach ( $this->technicalKeyPatterns() as $pattern ) {
			if ( str_starts_with( $pattern, '!' ) ) {
				if ( self::matchesPattern( $key, substr( $pattern, 1 ) ) ) {
					return false;
				}
				continue;
			}
			$include[] = $pattern;
		}
		foreach ( $include as $pattern ) {
			if ( self::matchesPattern( $key, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * One technical pattern: `/regex/flags` or a `*` glob. An invalid regex never matches.
	 *
	 * @param string $subject Name.
	 * @param string $pattern Pattern.
	 */
	public static function matchesPattern( string $subject, string $pattern ): bool {
		if ( str_starts_with( $pattern, '/' ) && strlen( $pattern ) > 2 ) {
			return 1 === @preg_match( $pattern, $subject ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- filtered patterns may be invalid; an invalid one never matches.
		}
		return self::matchesGlob( $subject, [ $pattern ] );
	}

	/**
	 * Plain HTTP allowed for the signed API (local development only).
	 */
	public function allowHttp(): bool {
		if ( defined( 'NOFORMAT_LOGGER_ALLOW_HTTP' ) ) {
			return (bool) constant( 'NOFORMAT_LOGGER_ALLOW_HTTP' );
		}
		return $this->bool( 'allow_http' );
	}

	/**
	 * IP allowlist entries (exact IPs or CIDR).
	 *
	 * @return list<string>
	 */
	public function ipAllowlist(): array {
		$list = $this->settings['ip_allowlist'] ?? [];
		if ( is_string( $list ) ) {
			$list = preg_split( '/[\s,]+/', $list ) ? preg_split( '/[\s,]+/', $list ) : [];
		}
		$out = [];
		foreach ( (array) $list as $entry ) {
			$entry = trim( (string) $entry );
			if ( '' !== $entry ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Glob match (only * is special), case-sensitive like option names.
	 *
	 * @param string       $subject  Name.
	 * @param list<string> $patterns Patterns.
	 */
	public static function matchesGlob( string $subject, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( $pattern === $subject ) {
				return true;
			}
			if ( str_contains( $pattern, '*' ) ) {
				$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
				if ( 1 === preg_match( $regex, $subject ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * List setting run through a filter.
	 *
	 * @param string $key    Setting.
	 * @param string $filter Filter name.
	 * @return list<string>
	 */
	private function filteredList( string $key, string $filter ): array {
		$list = $this->settings[ $key ] ?? [];
		$list = is_array( $list ) ? $list : [];
		if ( function_exists( 'apply_filters' ) ) {
			$list = apply_filters( $filter, $list ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- $filter is one of the documented noformat_logger/* names.
			$list = is_array( $list ) ? $list : [];
		}
		return array_values( array_map( 'strval', $list ) );
	}

	/**
	 * Coerce a raw value to the type of the default.
	 *
	 * @param mixed $defaultValue Default value.
	 * @param mixed $value   Raw value.
	 */
	private static function coerce( mixed $defaultValue, mixed $value ): mixed {
		if ( is_bool( $defaultValue ) ) {
			if ( is_string( $value ) ) {
				return in_array( strtolower( $value ), [ '1', 'true', 'yes', 'on' ], true );
			}
			return (bool) $value;
		}
		if ( is_int( $defaultValue ) ) {
			return is_numeric( $value ) ? max( 0, (int) $value ) : $defaultValue;
		}
		if ( is_array( $defaultValue ) ) {
			if ( is_string( $value ) ) {
				$parts = preg_split( '/[\s,]+/', $value );
				return array_values( array_filter( false === $parts ? [] : $parts, static fn( string $p ): bool => '' !== $p ) );
			}
			return is_array( $value ) ? $value : $defaultValue;
		}
		return $value;
	}
}
