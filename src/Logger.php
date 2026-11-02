<?php
/**
 * Logger.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger;

use Noformat\Logger\Context\RequestContext;
use Noformat\Logger\Privacy\Redactor;
use Noformat\Logger\Storage\EventRepository;
use Noformat\Logger\Storage\FingerprintStore;
use Noformat\Logger\Storage\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Buffers events in memory and writes them in one batch on shutdown (or
 * earlier, whenever the buffer fills up in a long CLI or cron run).
 *
 * Guarantees: never throws, never prints, never recurses into itself. Internal
 * failures increment a counter stored in the meta table (exposed in the manifest).
 *
 * Flood protection: events an anonymous client can trigger at will
 * (ANONYMOUS_ACTIONS) that would create a new row are capped site-wide at
 * `anonymous_per_minute` (120) per minute; the excess is folded into one
 * `system.flood` event per window.
 */
final class Logger {

	public const MAX_CONTEXT_BYTES = 65536;

	public const FALLBACK_FAILURE_OPTION = 'nflog_failures_fallback';

	/**
	 * Actions an unauthenticated client can produce at will (subject to the per-minute cap).
	 *
	 * @var list<string>
	 */
	public const ANONYMOUS_ACTIONS = [
		'api.auth_failed',
		'auth.login_failed',
		'probe.suspicious_404',
		'rest.users_probe',
		'xmlrpc.call',
		'auth.cookie_invalid',
		'rest.auth_error',
	];

	/** Meta / object cache key prefix of the per-minute anonymous counter (cleaned with the rate-limit buckets). */
	public const FLOOD_BUCKET = 'rl:anon:';

	/**
	 * Buffered events.
	 *
	 * @var list<Event>
	 */
	private array $buffer = [];

	/**
	 * Fingerprint => buffer index.
	 *
	 * @var array<string, int>
	 */
	private array $index = [];

	private int $overflow = 0;

	private int $pendingFailures = 0;

	private string $lastFailureWhere = '';

	private string $lastFailureClass = '';

	private bool $disabled = false;

	private bool $busy = false;

	private bool $transactional = true;

	/**
	 * Events whose request block still lacks the client hashes (filled when written).
	 *
	 * @var \WeakMap<Event, true>
	 */
	private \WeakMap $pendingHashes;

	/**
	 * Constructor.
	 *
	 * @param Config           $config       Config.
	 * @param RequestContext   $context      Request context.
	 * @param Redactor         $redactor     Redactor.
	 * @param EventRepository  $repository   Repository.
	 * @param FingerprintStore $fingerprints Fingerprint store.
	 * @param Meta             $meta         Meta store.
	 */
	public function __construct(
		private Config $config,
		private RequestContext $context,
		private Redactor $redactor,
		private EventRepository $repository,
		private FingerprintStore $fingerprints,
		private Meta $meta
	) {
		$this->pendingHashes = new \WeakMap();
	}

	/**
	 * Hook flush() on WordPress shutdown and as a PHP shutdown function fallback.
	 */
	public function registerShutdown(): void {
		$flush = function (): void {
			$this->flush();
		};
		add_action( 'shutdown', $flush, 1000, 0 );
		register_shutdown_function( $flush );
	}

	/**
	 * Queue an event. Returns false when it was dropped (filter, debug, overflow, failure).
	 *
	 * @param Event $event Event.
	 */
	public function log( Event $event ): bool {
		if ( $this->busy ) {
			return false;
		}
		$this->busy = true;
		try {
			if ( function_exists( 'apply_filters' ) ) {
				/**
				 * Filter an event before it is buffered. Return null to drop it.
				 *
				 * @param Event|null $event Event.
				 */
				$event = apply_filters( 'noformat_logger/event', $event );
			}
			if ( ! $event instanceof Event ) {
				return false;
			}
			if ( Severity::DEBUG === $event->getSeverity() && ! $this->config->bool( 'log_debug' ) ) {
				return false;
			}
			$this->prepare( $event );

			$fingerprint = $event->getFingerprint();
			if ( null !== $fingerprint && isset( $this->index[ $fingerprint ] ) ) {
				$buffered = $this->buffer[ $this->index[ $fingerprint ] ];
				$buffered->count( $buffered->getCount() + $event->getCount() );
				return true;
			}
			if ( count( $this->buffer ) >= max( 1, $this->config->int( 'buffer_max' ) ) ) {
				// Long imports and CLI runs: write what we have instead of dropping.
				if ( ! $this->disabled ) {
					$this->write( $this->transactional );
				}
				if ( $this->disabled || count( $this->buffer ) >= max( 1, $this->config->int( 'buffer_max' ) ) ) {
					++$this->overflow;
					return false;
				}
			}
			$this->buffer[] = $event;
			if ( null !== $fingerprint ) {
				$this->index[ $fingerprint ] = count( $this->buffer ) - 1;
			}
			return true;
		} catch ( \Throwable $e ) {
			$this->recordFailure( 'log', $e );
			return false;
		} finally {
			$this->busy = false;
		}
	}

	/**
	 * Log and write immediately; returns the stored event (null if dropped or aggregated).
	 *
	 * @param Event $event         Event.
	 * @param bool  $transactional False on the fatal/exception path: no transaction on the
	 *                             shared connection for the rest of the request (see
	 *                             EventRepository::insertBatch()).
	 */
	public function logNow( Event $event, bool $transactional = true ): ?Event {
		if ( ! $transactional ) {
			$this->transactional = false;
		}
		if ( ! $this->log( $event ) ) {
			return null;
		}
		foreach ( $this->flush() as $stored ) {
			if ( $stored === $event ) {
				return $stored;
			}
		}
		return null;
	}

	/**
	 * Write the buffer. Safe to call repeatedly; never throws.
	 *
	 * @return list<Event> Stored events.
	 */
	public function flush(): array {
		if ( $this->busy ) {
			return [];
		}
		$this->busy = true;
		try {
			return $this->write( $this->transactional );
		} finally {
			$this->busy = false;
		}
	}

	/**
	 * Write the buffer (caller holds the busy flag).
	 *
	 * @param bool $transactional Use a transaction for the insert.
	 * @return list<Event> Stored events.
	 */
	private function write( bool $transactional ): array {
		try {
			if ( [] === $this->buffer && 0 === $this->overflow ) {
				return [];
			}
			if ( $this->overflow > 0 ) {
				$overflow = Event::make( Category::SYSTEM, 'system.buffer_overflow', Severity::WARNING )
					->summary( sprintf( 'Event buffer overflow: %d events dropped in one request', $this->overflow ) )
					->context(
						[
							'dropped'    => $this->overflow,
							'buffer_max' => $this->config->int( 'buffer_max' ),
						]
					);
				$this->prepare( $overflow );
				$this->buffer[] = $overflow;
			}
			$events         = $this->buffer;
			$this->buffer   = [];
			$this->index    = [];
			$this->overflow = 0;

			if ( $this->disabled ) {
				$this->pendingFailures += count( $events );
				$this->lastFailureWhere = 'flush.disabled';
				return [];
			}

			$now      = time();
			$toInsert = [];
			foreach ( $events as $event ) {
				if ( null === $event->getActor() && $this->context->actorResolvable() ) {
					$actor = $this->context->actor();
					$event->actor( $actor['type'], $actor['id'], $actor['role'] );
				}
				if ( ! $this->aggregate( $event, $now ) ) {
					$toInsert[] = $event;
				}
			}
			$toInsert = $this->capAnonymous( $toInsert, $now );
			foreach ( $toInsert as $event ) {
				$this->fillHashes( $event );
			}

			$stored = $this->repository->insertBatch( $toInsert, $transactional );
			foreach ( $stored as $event ) {
				$fingerprint = $event->getFingerprint();
				$id          = $event->getId();
				if ( null !== $fingerprint && null !== $id ) {
					$this->fingerprints->remember( $fingerprint, $id, $event->getCount(), $now );
				}
			}
			return $stored;
		} catch ( \Throwable $e ) {
			$this->disabled = true;
			$this->recordFailure( 'flush', $e );
			return [];
		} finally {
			$this->persistFailures();
		}
	}

	/**
	 * Count a fingerprinted event on its open window instead of storing it.
	 *
	 * @param Event $event Event.
	 * @param int   $now   Unix time.
	 * @return bool Whether it was aggregated (not to be inserted).
	 */
	private function aggregate( Event $event, int $now ): bool {
		$fingerprint = $event->getFingerprint();
		if ( null === $fingerprint ) {
			return false;
		}
		$row = $this->fingerprints->lookup( $fingerprint );
		if ( null === $row || ! $this->fingerprints->shouldAggregate( $row, $now, $event->getSeverity(), $event->getAction() ) ) {
			return false;
		}
		$this->fingerprints->touch( $fingerprint, $event->getCount(), $now );
		return true;
	}

	/**
	 * Site-wide cap on new rows from anonymous, attacker-controlled events.
	 *
	 * @param list<Event> $events Events about to be inserted.
	 * @param int         $now    Unix time.
	 * @return list<Event> Events to insert (excess replaced by one system.flood event).
	 */
	private function capAnonymous( array $events, int $now ): array {
		$limit = $this->config->int( 'anonymous_per_minute' );
		if ( $limit <= 0 ) {
			return $events;
		}
		$candidates = 0;
		foreach ( $events as $event ) {
			if ( self::isAnonymous( $event ) ) {
				++$candidates;
			}
		}
		if ( 0 === $candidates ) {
			return $events;
		}
		$total   = $this->countAnonymous( $candidates, $now );
		$allowed = max( 0, $limit - ( $total - $candidates ) );
		if ( $allowed >= $candidates ) {
			return $events;
		}
		$kept    = [];
		$dropped = 0;
		$actions = [];
		foreach ( $events as $event ) {
			if ( ! self::isAnonymous( $event ) ) {
				$kept[] = $event;
				continue;
			}
			if ( $allowed > 0 ) {
				--$allowed;
				$kept[] = $event;
				continue;
			}
			$dropped                       += $event->getCount();
			$actions[ $event->getAction() ] = ( $actions[ $event->getAction() ] ?? 0 ) + $event->getCount();
		}
		arsort( $actions );
		$top = [];
		foreach ( array_slice( $actions, 0, 5, true ) as $action => $count ) {
			$top[] = [
				'action' => (string) $action,
				'count'  => $count,
			];
		}
		$flood = Event::make( Category::SECURITY, 'system.flood', Severity::WARNING )
			->summary( sprintf( 'Anonymous event flood: %d occurrences over %d per minute folded into this event', $dropped, $limit ) )
			->context(
				[
					'dropped'     => $dropped,
					'per_minute'  => $limit,
					'top_actions' => $top,
				]
			)
			->actor( 'system' )
			->count( $dropped )
			->fingerprint( 'system.flood' );
		$this->prepare( $flood );
		if ( ! $this->aggregate( $flood, $now ) ) {
			$kept[] = $flood;
		}
		return $kept;
	}

	/**
	 * Whether an event counts against the anonymous cap.
	 *
	 * @param Event $event Event.
	 */
	public static function isAnonymous( Event $event ): bool {
		if ( ! in_array( $event->getAction(), self::ANONYMOUS_ACTIONS, true ) ) {
			return false;
		}
		$actor = $event->getActor();
		return null === $actor || 'anonymous' === $actor['type'];
	}

	/**
	 * Add to this minute's anonymous counter; returns the new total.
	 *
	 * @param int $add Events to add.
	 * @param int $now Unix time.
	 */
	private function countAnonymous( int $add, int $now ): int {
		$key = self::FLOOD_BUCKET . intdiv( $now, 60 );
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_incr' ) ) {
			wp_cache_add( $key, 0, FingerprintStore::CACHE_GROUP, 120 );
			$total = wp_cache_incr( $key, $add, FingerprintStore::CACHE_GROUP );
			if ( false !== $total ) {
				return (int) $total;
			}
		}
		return $this->meta->increment( $key, $add );
	}

	/**
	 * Fill the client hashes of an event about to be stored (deferred so that
	 * aggregated occurrences never need the site salt).
	 *
	 * @param Event $event Event.
	 */
	private function fillHashes( Event $event ): void {
		if ( ! isset( $this->pendingHashes[ $event ] ) ) {
			return;
		}
		unset( $this->pendingHashes[ $event ] );
		$request = $event->getRequest();
		if ( null === $request || 'cli' === $request['source'] ) {
			return;
		}
		$request['ip_hash'] = $this->context->ipHash();
		$request['ua_hash'] = $this->context->uaHash();
		$event->withRequest( $request );
	}

	/**
	 * Count an internal failure (persisted at the end of flush()).
	 *
	 * @param string          $where     Short location label.
	 * @param \Throwable|null $exception Exception (only its class is kept).
	 */
	public function recordFailure( string $where, ?\Throwable $exception = null ): void {
		++$this->pendingFailures;
		$this->lastFailureWhere = substr( $where, 0, 64 );
		$this->lastFailureClass = null === $exception ? '' : get_class( $exception );
	}

	/**
	 * Run a callback, swallowing and counting any exception. For listener hook bodies.
	 *
	 * @param string   $where    Label.
	 * @param callable $callback Callback.
	 * @param mixed    $fallback Returned on failure.
	 */
	public function guard( string $where, callable $callback, mixed $fallback = null ): mixed {
		try {
			return $callback();
		} catch ( \Throwable $e ) {
			$this->recordFailure( $where, $e );
			return $fallback;
		}
	}

	/**
	 * Total internal failures (stored + fallback + this request).
	 */
	public function failures(): int {
		$stored = 0;
		try {
			$stored = $this->meta->getInt( Meta::FAILURES );
		} catch ( \Throwable $e ) {
			$stored = 0;
		}
		$fallback = function_exists( 'get_option' ) ? (int) get_option( self::FALLBACK_FAILURE_OPTION, 0 ) : 0;
		return $stored + $fallback + $this->pendingFailures;
	}

	/**
	 * Events waiting in the buffer.
	 */
	public function pending(): int {
		return count( $this->buffer );
	}

	/**
	 * Whether storage was found unusable in this request.
	 */
	public function isDisabled(): bool {
		return $this->disabled;
	}

	/**
	 * Config accessor for listeners.
	 */
	public function config(): Config {
		return $this->config;
	}

	/**
	 * Redactor accessor for listeners (diff(), contentSummary(), hashUsername()).
	 */
	public function redactor(): Redactor {
		return $this->redactor;
	}

	/**
	 * Request context accessor for listeners.
	 */
	public function context(): RequestContext {
		return $this->context;
	}

	/**
	 * Fill request/site, redact and cap payloads.
	 *
	 * @param Event $event Event.
	 */
	private function prepare( Event $event ): void {
		if ( null === $event->getRequest() ) {
			$event->withRequest( $this->context->toArray( false ) );
			$this->pendingHashes[ $event ] = true;
		}
		if ( null === $event->getActor() && $this->context->actorResolvable() ) {
			$actor = $this->context->actor();
			$event->actor( $actor['type'], $actor['id'], $actor['role'] );
		}
		if ( null === $event->getSite() ) {
			$event->withSite(
				function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1,
				function_exists( 'home_url' ) ? home_url() : null
			);
		}

		$changes = [];
		foreach ( $event->getChanges() as $change ) {
			$field     = (string) $change['field'];
			$changes[] = [
				'field' => substr( $field, 0, 191 ),
				'old'   => $this->redactChangeValue( $field, $change['old'] ),
				'new'   => $this->redactChangeValue( $field, $change['new'] ),
			];
		}
		$changes = array_slice( $changes, 0, Redactor::MAX_CHANGES + 1 );
		$context = self::scalarize( $this->redactor->redact( $event->getContext() ) );
		$changes = self::scalarize( $changes );

		$json = (string) wp_json_encode( $context );
		if ( strlen( $json ) > self::MAX_CONTEXT_BYTES ) {
			$context = [
				'truncated' => true,
				'length'    => strlen( $json ),
				'sha256_8'  => $this->redactor->hash8( $json ),
			];
		}
		if ( strlen( (string) wp_json_encode( $changes ) ) > self::MAX_CONTEXT_BYTES ) {
			foreach ( $changes as $i => $change ) {
				foreach ( [ 'old', 'new' ] as $side ) {
					$encoded                = (string) wp_json_encode( $change[ $side ] );
					$changes[ $i ][ $side ] = [
						'truncated' => true,
						'length'    => strlen( $encoded ),
						'sha256_8'  => $this->redactor->hash8( $encoded ),
					];
				}
			}
		}
		/** Shape preserved. @var list<array{field: string, old: mixed, new: mixed}> $changes */
		$event->withPayload( $changes, is_array( $context ) ? $context : [] );
	}

	/**
	 * Redact one side of a change; any sensitive path segment redacts the value.
	 *
	 * @param string $field Dotted field.
	 * @param mixed  $value Value.
	 */
	private function redactChangeValue( string $field, mixed $value ): mixed {
		if ( is_array( $value ) && true === ( $value['redacted'] ?? null ) ) {
			return $value;
		}
		foreach ( explode( '.', $field ) as $segment ) {
			if ( $this->redactor->isSensitiveKey( $segment ) ) {
				return null === $value ? null : $this->redactor->marker( $value );
			}
		}
		return $this->redactor->redactValue( '', $value );
	}

	/**
	 * Floats become strings so the stored, hashed and transmitted forms agree.
	 *
	 * @param mixed $value Value.
	 */
	private static function scalarize( mixed $value ): mixed {
		if ( is_float( $value ) ) {
			return is_finite( $value ) ? rtrim( rtrim( sprintf( '%.6F', $value ), '0' ), '.' ) : 'NaN';
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::scalarize( $v );
			}
		}
		if ( is_resource( $value ) ) {
			return 'resource';
		}
		return $value;
	}

	/**
	 * Persist the failure counter (meta table, fallback option).
	 */
	private function persistFailures(): void {
		if ( 0 === $this->pendingFailures ) {
			return;
		}
		$count                 = $this->pendingFailures;
		$this->pendingFailures = 0;
		try {
			$this->meta->increment( Meta::FAILURES, $count );
			$this->meta->setJson(
				Meta::LAST_FAILURE,
				[
					'at'    => time(),
					'where' => $this->lastFailureWhere,
					'class' => $this->lastFailureClass,
				]
			);
		} catch ( \Throwable $e ) {
			try {
				if ( function_exists( 'update_option' ) ) {
					update_option( self::FALLBACK_FAILURE_OPTION, (int) get_option( self::FALLBACK_FAILURE_OPTION, 0 ) + $count, false );
				}
			} catch ( \Throwable $ignored ) {
				unset( $ignored );
			}
		}
	}
}
