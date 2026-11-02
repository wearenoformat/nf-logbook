<?php
/**
 * Plugin wiring.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger;

use Noformat\Logger\Admin\DashboardWidget;
use Noformat\Logger\Admin\Menu;
use Noformat\Logger\Cli\Commands;
use Noformat\Logger\Context\RequestContext;
use Noformat\Logger\Cron\Scheduler;
use Noformat\Logger\Health\HealthReport;
use Noformat\Logger\Privacy\Redactor;
use Noformat\Logger\Rest\Authenticator;
use Noformat\Logger\Rest\Controller;
use Noformat\Logger\Rest\KeyStore;
use Noformat\Logger\Rest\NonceStore;
use Noformat\Logger\Rest\Responder;
use Noformat\Logger\Security\ConfigAudit;
use Noformat\Logger\Security\IntegrityCheck;
use Noformat\Logger\Security\RateLimiter;
use Noformat\Logger\Storage\EventRepository;
use Noformat\Logger\Storage\FingerprintStore;
use Noformat\Logger\Storage\Meta;
use Noformat\Logger\Storage\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Service container, listener registry, activation/deactivation, upgrades.
 */
final class Plugin {

	/** Custom capability; registered on activation, assigned to no role. Grants the event viewer and the read-only status page (PLAN D11). */
	public const CAP_VIEW_EVENTS = 'noformat_logger_view_events';

	/**
	 * Listener registry, in registration order (PLAN §4, §9 B).
	 *
	 * @var list<class-string<Listener>>
	 */
	public const LISTENERS = [
		Errors\ErrorHandler::class,
		Errors\FatalHandler::class,
		Errors\DbErrorListener::class,
		Errors\DeprecationListener::class,
		Errors\HttpApiListener::class,
		Errors\MailListener::class,
		Listeners\PostListener::class,
		Listeners\MetaListener::class,
		Listeners\TermListener::class,
		Listeners\MenuListener::class,
		Listeners\MediaListener::class,
		Listeners\CommentListener::class,
		Listeners\WidgetListener::class,
		Listeners\CustomizerListener::class,
		Listeners\AcfListener::class,
		Listeners\OptionListener::class,
		Listeners\PluginThemeListener::class,
		Listeners\CoreListener::class,
		Listeners\UserListener::class,
		Listeners\PrivacyListener::class,
		Security\AuthListener::class,
		Security\RestProbeListener::class,
		Security\XmlRpcListener::class,
		Security\ProbeListener::class,
		Security\ConfigAudit::class,
		Security\IntegrityCheck::class,
		Health\HealthReport::class,
	];

	private static ?self $instance = null;

	private Config $config;
	private Meta $meta;
	private RequestContext $context;
	private Redactor $redactor;
	private EventRepository $repository;
	private FingerprintStore $fingerprints;
	private Logger $logger;
	private KeyStore $keys;
	private NonceStore $nonces;
	private RateLimiter $limiter;
	private Authenticator $authenticator;

	/**
	 * Listener instances by class.
	 *
	 * @var array<string, Listener>
	 */
	private array $listeners = [];

	private ListenerRegistry $registry;

	/**
	 * Build services (no hooks yet).
	 */
	private function __construct() {
		$this->config        = Config::fromEnvironment();
		$this->meta          = new Meta();
		$this->context       = new RequestContext( $this->meta );
		$context             = $this->context;
		$this->redactor      = new Redactor( $this->config->sensitiveKeys(), $this->config->safeKeys(), static fn(): string => $context->salt() );
		$this->repository    = new EventRepository( $this->meta );
		$this->fingerprints  = new FingerprintStore( $this->config );
		$this->logger        = new Logger( $this->config, $this->context, $this->redactor, $this->repository, $this->fingerprints, $this->meta );
		$this->keys          = new KeyStore();
		$this->nonces        = new NonceStore();
		$this->limiter       = new RateLimiter( $this->meta );
		$this->authenticator = new Authenticator( $this->config, $this->keys, $this->nonces, $this->limiter, $this->logger, $this->meta );
		$this->registry      = new ListenerRegistry(
			$this->config,
			function ( string $listenerClass ): void {
				$listener                          = new $listenerClass();
				$this->listeners[ $listenerClass ] = $listener;
				$listener->register( $this->logger, $this->config );
			},
			function ( string $where, \Throwable $e ): void {
				$this->logger->recordFailure( $where, $e );
			}
		);
	}

	/**
	 * Entry point from the bootstrap file. Never throws.
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}
		try {
			self::$instance = new self();
			self::$instance->start();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'update_option' ) ) {
				try {
					update_option( Logger::FALLBACK_FAILURE_OPTION, (int) get_option( Logger::FALLBACK_FAILURE_OPTION, 0 ) + 1, false );
				} catch ( \Throwable $ignored ) {
					unset( $ignored );
				}
			}
		}
	}

	/**
	 * Running instance (null before boot or when boot failed).
	 */
	public static function instance(): ?self {
		return self::$instance;
	}

	/**
	 * Wire hooks.
	 */
	private function start(): void {
		$this->logger->registerShutdown();
		add_action( 'application_password_did_authenticate', [ $this->context, 'markAppPassword' ], 10, 0 );
		$redactor = $this->redactor;
		Errors\Support\PathMapper::useHasher( static fn( string $value ): string => $redactor->hash8( 'file:' . $value ) );
		$this->keys->onUnreadable( [ $this, 'keyUnreadable' ] );
		add_action( 'update_option_' . Config::OPTION, [ $this, 'settingsUpdated' ], 10, 2 );
		add_action( 'add_option_' . Config::OPTION, [ $this, 'settingsAdded' ], 10, 2 );

		$this->registerListeners();

		( new Controller( $this, $this->authenticator, new Responder( $this->repository, $this->authenticator ) ) )->hooks();
		( new Scheduler( $this ) )->hooks();
		( new Update\Updater( $this ) )->hooks();

		if ( is_admin() ) {
			( new Menu( $this ) )->hooks();
			( new DashboardWidget( $this ) )->hooks();
			add_action( 'admin_init', [ self::class, 'maybeUpgrade' ] );
		}
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) && class_exists( '\WP_CLI' ) ) {
			Commands::register( $this );
			add_action( 'init', [ self::class, 'maybeUpgrade' ] );
		}
		add_action( 'init', [ $this, 'loadTextDomain' ] );
	}

	/**
	 * Load translations.
	 */
	public function loadTextDomain(): void {
		load_plugin_textdomain( 'nf-logbook', false, dirname( plugin_basename( NOFORMAT_LOGGER_FILE ) ) . '/languages' );
	}

	/**
	 * Instantiate and register every enabled, available listener; each one guarded.
	 *
	 * Listeners unavailable at boot (their dependency is a plugin loading after
	 * this one) are re-checked on plugins_loaded at priority 0.
	 */
	private function registerListeners(): void {
		$this->registry->registerAll( self::LISTENERS );
		if ( $this->registry->hasDeferred() ) {
			if ( did_action( 'plugins_loaded' ) > 0 ) {
				$this->registry->registerDeferred();
			} else {
				add_action( 'plugins_loaded', [ $this->registry, 'registerDeferred' ], 0, 0 );
			}
		}
	}

	/**
	 * Activation: install schema, schedule cron, record the custom capability.
	 *
	 * @param bool $networkWide Network activation (handled per site in Phase 2).
	 */
	public static function activate( bool $networkWide = false ): void {
		unset( $networkWide );
		try {
			Schema::install();
			// Autoloaded (even when empty) so a normal page view needs no extra query for it.
			add_option( Config::OPTION, [], '', true );
			Scheduler::schedule();
			( new Meta() )->setJson( 'registered_capabilities', [ self::CAP_VIEW_EVENTS ] );
			self::boot();
			$plugin = self::instance();
			if ( null !== $plugin ) {
				$plugin->logger->logNow(
					Event::make( Category::SYSTEM, 'logger.activated', Severity::NOTICE )
						->object( 'plugin', 'nf-logbook' )
						->summary( 'Noformat Logbook activated' )
						->context( [ 'schema' => Schema::VERSION ] )
				);
			}
		} catch ( \Throwable $e ) {
			update_option( Logger::FALLBACK_FAILURE_OPTION, (int) get_option( Logger::FALLBACK_FAILURE_OPTION, 0 ) + 1, false );
		}
	}

	/**
	 * Deactivation: clear cron (data is kept).
	 */
	public static function deactivate(): void {
		try {
			$plugin = self::instance();
			if ( null !== $plugin ) {
				$plugin->logger->logNow(
					Event::make( Category::SYSTEM, 'logger.deactivated', Severity::WARNING )
						->object( 'plugin', 'nf-logbook' )
						->summary( 'Noformat Logbook deactivated' )
				);
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		Scheduler::unschedule();
	}

	/**
	 * Option nflog_settings updated (status page, CLI, any code): log the changed setting names.
	 *
	 * @param mixed $oldValue Old value.
	 * @param mixed $newValue New value.
	 */
	public function settingsUpdated( mixed $oldValue, mixed $newValue ): void {
		$this->logger->guard( 'settings_changed', fn() => $this->logSettingsChange( is_array( $oldValue ) ? $oldValue : [], is_array( $newValue ) ? $newValue : [] ) );
	}

	/**
	 * Option nflog_settings created.
	 *
	 * @param mixed $option Option name.
	 * @param mixed $value  Value.
	 */
	public function settingsAdded( mixed $option, mixed $value ): void {
		unset( $option );
		$this->logger->guard( 'settings_changed', fn() => $this->logSettingsChange( [], is_array( $value ) ? $value : [] ) );
	}

	/**
	 * Names of changed settings ("modules.<Module>" for module toggles), sorted (pure).
	 *
	 * @param array<string|int, mixed> $oldValue Old option value.
	 * @param array<string|int, mixed> $newValue New option value.
	 * @return list<string>
	 */
	public static function changedSettings( array $oldValue, array $newValue ): array {
		$names = [];
		foreach ( array_unique( array_merge( array_keys( $oldValue ), array_keys( $newValue ) ) ) as $key ) {
			$key = (string) $key;
			$a   = $oldValue[ $key ] ?? null;
			$b   = $newValue[ $key ] ?? null;
			if ( 'modules' === $key && ( is_array( $a ) || is_array( $b ) ) ) {
				$a = is_array( $a ) ? $a : [];
				$b = is_array( $b ) ? $b : [];
				foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $module ) {
					if ( (bool) ( $a[ $module ] ?? true ) !== (bool) ( $b[ $module ] ?? true ) ) {
						$names[] = 'modules.' . $module;
					}
				}
				continue;
			}
			if ( wp_json_encode( $a ) !== wp_json_encode( $b ) ) {
				$names[] = $key;
			}
		}
		sort( $names );
		return array_values( array_map( static fn( string $n ): string => substr( $n, 0, 64 ), $names ) );
	}

	/**
	 * Emit logger.settings_changed (names only, never values).
	 *
	 * @param array<string|int, mixed> $oldValue Old.
	 * @param array<string|int, mixed> $newValue New.
	 */
	private function logSettingsChange( array $oldValue, array $newValue ): void {
		$names = self::changedSettings( $oldValue, $newValue );
		if ( [] === $names ) {
			return;
		}
		$this->logger->log(
			Event::make( Category::SYSTEM, 'logger.settings_changed', Severity::NOTICE )
				->object( 'plugin', 'nf-logbook' )
				->summary( 'Noformat Logbook settings changed: ' . implode( ', ', array_slice( $names, 0, 10 ) ) . ( count( $names ) > 10 ? ', …' : '' ) )
				->context( [ 'settings' => $names ] )
		);
	}

	/**
	 * Emit logger.key_generated / key_rotated / key_revoked (key ids only).
	 *
	 * @param string      $what       generated|rotated|revoked.
	 * @param string|null $keyId      Key id (new key; revoked key).
	 * @param string|null $previousId Previous key id (rotate) or the other revoked key.
	 * @param bool        $all        Revoke: every key.
	 */
	public function logKeyChange( string $what, ?string $keyId, ?string $previousId = null, bool $all = false ): void {
		$this->logger->guard(
			'key_change',
			function () use ( $what, $keyId, $previousId, $all ): void {
				$context = array_filter(
					[
						'key_id'          => $keyId,
						'previous_key_id' => $previousId,
						'all'             => $all ? true : null,
					],
					static fn( $v ): bool => null !== $v
				);
					$this->logger->log(
						Event::make( Category::SECURITY, 'logger.key_' . $what, Severity::NOTICE )
						->object( 'api_key', $keyId )
						->summary( sprintf( 'Noformat Logbook API key %s%s', $what, null === $keyId ? '' : ' (' . $keyId . ')' ) )
						->context( $context )
					);
			}
		);
	}

	/**
	 * The stored (database) key cannot be decrypted, usually because the WordPress
	 * salts changed: log logger.key_unreadable at most once per hour.
	 *
	 * @param string $keyId Key id.
	 */
	public function keyUnreadable( string $keyId ): void {
		$this->logger->guard(
			'key_unreadable',
			function () use ( $keyId ): void {
				$now  = time();
				$last = $this->meta->getInt( 'key_unreadable_at' );
				if ( $now - $last < HOUR_IN_SECONDS ) {
					return;
				}
				$this->meta->set( 'key_unreadable_at', (string) $now );
				$this->logger->log(
					Event::make( Category::SECURITY, 'logger.key_unreadable', Severity::ERROR )
						->object( 'api_key', $keyId )
						->summary( sprintf( 'Stored API key %s cannot be decrypted (WordPress salts changed?)', $keyId ) )
						->context( [ 'key_id' => $keyId ] )
				);
			}
		);
	}

	/**
	 * Run migrations when the stored schema version is behind (admin, CLI only).
	 *
	 * Zero queries when up to date (the version is mirrored in an autoloaded
	 * option); otherwise at most one install attempt per 10 minutes.
	 */
	public static function maybeUpgrade(): void {
		try {
			Schema::maybeUpgrade();
		} catch ( \Throwable $e ) {
			self::instance()?->logger->recordFailure( 'upgrade', $e );
		}
	}

	/**
	 * Manifest for GET /manifest and `wp nflog status`.
	 *
	 * @param string|null $keyId Authenticated key id.
	 * @return array<string, mixed>
	 */
	public function manifest( ?string $keyId = null ): array {
		$stats     = [
			'count'  => 0,
			'min_id' => 0,
			'max_id' => 0,
		];
		$available = true;
		$head      = [
			'id'   => 0,
			'hash' => Storage\HashChain::GENESIS,
		];
		$ack       = 0;
		$served    = 0;
		$lastPull  = null;
		$lastFail  = null;
		$pruned    = null;
		try {
			$stats    = $this->repository->stats();
			$head     = $this->repository->chainHead();
			$ack      = $this->meta->getInt( Meta::ACK_CURSOR );
			$served   = $this->meta->getInt( Meta::LAST_SERVED );
			$lastPull = $this->meta->getJson( Meta::LAST_PULL );
			$lastFail = $this->meta->getJson( Meta::LAST_FAILURE );
			$pruned   = $this->meta->getJson( Meta::PRUNED_THROUGH );
		} catch ( \Throwable $e ) {
			$available = false;
		}
		$nowMs = (int) floor( microtime( true ) * 1000 );
		$key   = $this->keys->describe();

		return [
			'producer'       => Event::producer(),
			'schema'         => [
				'event'    => Event::SCHEMA,
				'database' => Schema::installedVersion(),
				'expected' => Schema::VERSION,
			],
			'site'           => [
				'id'  => (string) get_current_blog_id(),
				'url' => home_url(),
			],
			'rest_prefix'    => function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json',
			'clock'          => [
				'now'  => Event::formatMs( $nowMs, 'Y-m-d\TH:i:s' ) . 'Z',
				'unix' => intdiv( $nowMs, 1000 ),
			],
			'retention'      => [
				'retention_days' => $this->config->int( 'retention_days' ),
				'hard_max_days'  => $this->config->int( 'hard_max_days' ),
				'max_rows'       => $this->config->int( 'max_rows' ),
			],
			'buffer'         => [
				'max'     => $this->config->int( 'buffer_max' ),
				'pending' => $this->logger->pending(),
			],
			'storage'        => [
				'available'      => $available && ! $this->logger->isDisabled(),
				'events'         => $stats['count'],
				'oldest_id'      => $stats['min_id'],
				'newest_id'      => $stats['max_id'],
				'pruned_through' => $pruned,
			],
			'chain_head'     => $head,
			'ack_cursor'     => $ack,
			'last_served_id' => $served,
			'last_pull'      => $lastPull,
			'failures'       => [
				'count' => $this->logger->failures(),
				'last'  => $lastFail,
			],
			'modules'        => $this->modules(),
			'key'            => [
				'authenticated_id'     => $keyId,
				'current_id'           => $key['id'],
				'source'               => $key['source'],
				'previous_id'          => $key['previous_id'],
				'previous_valid_until' => $key['previous_valid_until'],
			],
			'settings'       => [
				'log_debug'         => $this->config->bool( 'log_debug' ),
				'full_content_diff' => $this->config->bool( 'full_content_diff' ),
				'rate_limit'        => $this->config->int( 'rate_limit_per_minute' ),
				'ip_allowlist'      => [] !== $this->config->ipAllowlist(),
			],
		];
	}

	/**
	 * Module status.
	 *
	 * @return array<string, array{enabled: bool, available: bool, registered: bool, error: string|null}>
	 */
	public function modules(): array {
		return $this->registry->modules();
	}

	/**
	 * Listener instance (registered or not) by class.
	 *
	 * @template T of Listener
	 * @param class-string<T> $className Class.
	 * @return T
	 */
	public function listener( string $className ): Listener {
		if ( ! isset( $this->listeners[ $className ] ) ) {
			$this->listeners[ $className ] = new $className();
		}
		/** Instance of the requested class. @var T $listener */
		$listener = $this->listeners[ $className ];
		return $listener;
	}

	/** Health report service. */
	public function health(): HealthReport {
		return $this->listener( HealthReport::class );
	}

	/** Integrity check service. */
	public function integrity(): IntegrityCheck {
		return $this->listener( IntegrityCheck::class );
	}

	/** Config audit service. */
	public function configAudit(): ConfigAudit {
		return $this->listener( ConfigAudit::class );
	}

	/** Config. */
	public function config(): Config {
		return $this->config;
	}

	/** Logger. */
	public function logger(): Logger {
		return $this->logger;
	}

	/** Repository. */
	public function repository(): EventRepository {
		return $this->repository;
	}

	/** Meta store. */
	public function meta(): Meta {
		return $this->meta;
	}

	/** Request context. */
	public function context(): RequestContext {
		return $this->context;
	}

	/** Redactor. */
	public function redactor(): Redactor {
		return $this->redactor;
	}

	/** Fingerprint store. */
	public function fingerprints(): FingerprintStore {
		return $this->fingerprints;
	}

	/** Key store. */
	public function keys(): KeyStore {
		return $this->keys;
	}

	/** Nonce store. */
	public function nonces(): NonceStore {
		return $this->nonces;
	}

	/** Rate limiter. */
	public function limiter(): RateLimiter {
		return $this->limiter;
	}
}
