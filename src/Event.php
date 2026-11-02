<?php
/**
 * Event value object and builder.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * One log event in the CONTRACT §1 shape.
 *
 * Listeners build events fluently and hand them to Logger::log():
 *
 *     $logger->log(
 *         Event::make( Category::CONTENT, 'post.updated', Severity::INFO )
 *             ->object( 'post', $post_id, $post_type )
 *             ->summary( 'Post #12 updated' )
 *             ->changes( $changes )
 *             ->context( [ 'revision_id' => 99 ] )
 *     );
 *
 * Request, actor and site are filled in by the Logger when not set explicitly.
 * Redaction happens in the Logger as well, so listeners may pass raw values;
 * they must still never pass names, emails, usernames, IPs or user agents.
 */
final class Event {

	public const PRODUCER_NAME = 'wp-noformat-logger';
	public const SCHEMA        = 1;

	/** Column limits (characters), mirrored from Storage\Schema. */
	public const MAX_ACTION  = 64;
	public const MAX_SUMMARY = 255;
	public const MAX_TYPE    = 32;
	public const MAX_OBJ_ID  = 64;
	public const MAX_SUBTYPE = 64;
	public const MAX_ROLE    = 64;
	public const MAX_ROUTE   = 255;

	private ?int $id          = null;
	private ?string $hash     = null;
	private ?string $prevHash = null;
	private int $occurredAtMs;
	private string $summary = '';

	/**
	 * Object reference.
	 *
	 * @var array{type: string, id: string|null, subtype: string|null, label_hash: string|null}|null
	 */
	private ?array $object = null;

	/**
	 * Actor reference.
	 *
	 * @var array{type: string, id: string|null, role: string|null}|null
	 */
	private ?array $actor = null;

	/**
	 * Request context.
	 *
	 * @var array{id: string, source: string, route: string|null, method: string|null, ip_hash: string|null, ua_hash: string|null}|null
	 */
	private ?array $request = null;

	/**
	 * Field changes.
	 *
	 * @var list<array{field: string, old: mixed, new: mixed}>
	 */
	private array $changes = [];

	/**
	 * Free-form context.
	 *
	 * @var array<string|int, mixed>
	 */
	private array $context = [];

	private ?string $fingerprint = null;
	private int $count           = 1;

	/**
	 * Site reference.
	 *
	 * @var array{id: string, url: string|null}|null
	 */
	private ?array $site = null;

	/**
	 * Use Event::make().
	 *
	 * @param Category $category Category.
	 * @param string   $action   Dotted action name, e.g. "post.updated".
	 * @param Severity $severity Severity.
	 */
	private function __construct(
		private Category $category,
		private string $action,
		private Severity $severity
	) {
		$this->action       = self::clip( strtolower( $action ), self::MAX_ACTION );
		$this->occurredAtMs = (int) floor( microtime( true ) * 1000 );
	}

	/**
	 * Start building an event.
	 *
	 * @param Category $category Category.
	 * @param string   $action   Dotted, stable, machine-readable action (≤ 64 chars).
	 * @param Severity $severity Severity, default info.
	 */
	public static function make( Category $category, string $action, Severity $severity = Severity::INFO ): self {
		return new self( $category, $action, $severity );
	}

	/**
	 * Set the object the event is about. IDs are strings in the contract.
	 *
	 * @param string          $type      Object type, e.g. "post", "user", "option", "plugin".
	 * @param string|int|null $id        Object id (numeric id, option name, plugin slug…).
	 * @param string|null     $subtype   Post type, taxonomy, meta group….
	 * @param string|null     $labelHash Optional hash of a human label (never the label itself).
	 */
	public function object( string $type, string|int|null $id = null, ?string $subtype = null, ?string $labelHash = null ): self {
		$this->object = [
			'type'       => self::clip( $type, self::MAX_TYPE ),
			'id'         => null === $id ? null : self::clip( (string) $id, self::MAX_OBJ_ID ),
			'subtype'    => null === $subtype || '' === $subtype ? null : self::clip( $subtype, self::MAX_SUBTYPE ),
			'label_hash' => $labelHash,
		];
		return $this;
	}

	/**
	 * Override the actor (normally derived from the request).
	 *
	 * @param string          $type One of user|system|cron|cli|anonymous|app.
	 * @param int|string|null $id   User id or null.
	 * @param string|null     $role Primary role or null.
	 */
	public function actor( string $type, int|string|null $id = null, ?string $role = null ): self {
		$this->actor = [
			'type' => self::clip( $type, 16 ),
			'id'   => null === $id || '' === $id || '0' === (string) $id ? null : (string) (int) $id,
			'role' => null === $role || '' === $role ? null : self::clip( $role, self::MAX_ROLE ),
		];
		return $this;
	}

	/**
	 * Human-readable one-line summary (≤ 255 chars; IDs only, no personal data).
	 *
	 * @param string $summary Summary.
	 */
	public function summary( string $summary ): self {
		$this->summary = self::clip( trim( preg_replace( '/\s+/u', ' ', $summary ) ?? $summary ), self::MAX_SUMMARY );
		return $this;
	}

	/**
	 * Append one field change.
	 *
	 * @param string $field    Field name or dotted path.
	 * @param mixed  $oldValue Old value (redacted by the Logger).
	 * @param mixed  $newValue New value (redacted by the Logger).
	 */
	public function change( string $field, mixed $oldValue, mixed $newValue ): self {
		$this->changes[] = [
			'field' => $field,
			'old'   => $oldValue,
			'new'   => $newValue,
		];
		return $this;
	}

	/**
	 * Append several changes, e.g. the output of Redactor::diff().
	 *
	 * @param array<int, array{field: string, old?: mixed, new?: mixed}> $changes Changes.
	 */
	public function changes( array $changes ): self {
		foreach ( $changes as $change ) {
			if ( is_array( $change ) && isset( $change['field'] ) ) {
				$this->change( (string) $change['field'], $change['old'] ?? null, $change['new'] ?? null );
			}
		}
		return $this;
	}

	/**
	 * Merge free-form context (redacted and capped to 64 KB by the Logger).
	 *
	 * @param array<string|int, mixed> $context Context.
	 */
	public function context( array $context ): self {
		$this->context = array_merge( $this->context, $context );
		return $this;
	}

	/**
	 * Set the aggregation fingerprint from one or more stable parts.
	 * The parts are hashed; pass things like the action, file and line, never personal data.
	 *
	 * @param string|int ...$parts Stable identifying parts.
	 */
	public function fingerprint( string|int ...$parts ): self {
		$this->fingerprint = substr( hash( 'sha256', implode( "\x1f", array_map( 'strval', $parts ) ) ), 0, 32 );
		return $this;
	}

	/**
	 * Change the severity after creation (e.g. escalate to critical).
	 *
	 * @param Severity $severity Severity.
	 */
	public function severity( Severity $severity ): self {
		$this->severity = $severity;
		return $this;
	}

	/**
	 * Override the occurrence time.
	 *
	 * @param int $milliseconds Unix time in milliseconds.
	 */
	public function occurredAtMs( int $milliseconds ): self {
		$this->occurredAtMs = $milliseconds;
		return $this;
	}

	/**
	 * Set the occurrence count (aggregation).
	 *
	 * @param int $count Count ≥ 1.
	 */
	public function count( int $count ): self {
		$this->count = max( 1, $count );
		return $this;
	}

	/**
	 * Internal: set the request context.
	 *
	 * @param array{id: string, source: string, route: string|null, method: string|null, ip_hash: string|null, ua_hash: string|null} $request Request.
	 */
	public function withRequest( array $request ): self {
		$this->request = $request;
		return $this;
	}

	/**
	 * Internal: set site reference.
	 *
	 * @param string|int  $id  Blog id.
	 * @param string|null $url Home URL.
	 */
	public function withSite( string|int $id, ?string $url ): self {
		$this->site = [
			'id'  => (string) $id,
			'url' => $url,
		];
		return $this;
	}

	/**
	 * Internal: replace changes and context after redaction.
	 *
	 * @param list<array{field: string, old: mixed, new: mixed}> $changes Changes.
	 * @param array<string|int, mixed>                           $context Context.
	 */
	public function withPayload( array $changes, array $context ): self {
		$this->changes = $changes;
		$this->context = $context;
		return $this;
	}

	/**
	 * Internal: chain data after storage.
	 *
	 * @param int|null $id       Row id.
	 * @param string   $hash     Hash.
	 * @param string   $prevHash Previous hash.
	 */
	public function withChain( ?int $id, string $hash, string $prevHash ): self {
		$this->id       = $id;
		$this->hash     = $hash;
		$this->prevHash = $prevHash;
		return $this;
	}

	/**
	 * Internal: set the fingerprint verbatim (hydration).
	 *
	 * @param string|null $fingerprint 32 hex chars.
	 */
	public function withFingerprint( ?string $fingerprint ): self {
		$this->fingerprint = $fingerprint;
		return $this;
	}

	/** Getter. */
	public function getId(): ?int {
		return $this->id;
	}

	/** Getter. */
	public function getHash(): ?string {
		return $this->hash;
	}

	/** Getter. */
	public function getPrevHash(): ?string {
		return $this->prevHash;
	}

	/** Getter. */
	public function getCategory(): Category {
		return $this->category;
	}

	/** Getter. */
	public function getAction(): string {
		return $this->action;
	}

	/** Getter. */
	public function getSeverity(): Severity {
		return $this->severity;
	}

	/** Getter. */
	public function getSummary(): string {
		return $this->summary;
	}

	/** Getter. */
	public function getOccurredAtMs(): int {
		return $this->occurredAtMs;
	}

	/** Getter. */
	public function getFingerprint(): ?string {
		return $this->fingerprint;
	}

	/** Getter. */
	public function getCount(): int {
		return $this->count;
	}

	/**
	 * Getter.
	 *
	 * @return array{type: string, id: string|null, subtype: string|null, label_hash: string|null}|null
	 */
	public function getObject(): ?array {
		return $this->object;
	}

	/**
	 * Getter.
	 *
	 * @return array{type: string, id: string|null, role: string|null}|null
	 */
	public function getActor(): ?array {
		return $this->actor;
	}

	/**
	 * Getter.
	 *
	 * @return array{id: string, source: string, route: string|null, method: string|null, ip_hash: string|null, ua_hash: string|null}|null
	 */
	public function getRequest(): ?array {
		return $this->request;
	}

	/**
	 * Getter.
	 *
	 * @return list<array{field: string, old: mixed, new: mixed}>
	 */
	public function getChanges(): array {
		return $this->changes;
	}

	/**
	 * Getter.
	 *
	 * @return array<string|int, mixed>
	 */
	public function getContext(): array {
		return $this->context;
	}

	/**
	 * Getter.
	 *
	 * @return array{id: string, url: string|null}|null
	 */
	public function getSite(): ?array {
		return $this->site;
	}

	/**
	 * ISO-8601 UTC with millisecond precision, e.g. 2026-10-28T14:03:11.482Z.
	 */
	public function occurredAtIso(): string {
		return self::formatMs( $this->occurredAtMs, 'Y-m-d\TH:i:s' ) . 'Z';
	}

	/**
	 * Storage format "Y-m-d H:i:s" (UTC, seconds; milliseconds live in occurred_ms).
	 */
	public function occurredAtSql(): string {
		return gmdate( 'Y-m-d H:i:s', intdiv( $this->occurredAtMs, 1000 ) );
	}

	/**
	 * Format a millisecond timestamp.
	 *
	 * @param int    $ms     Milliseconds.
	 * @param string $format Date format for the seconds part.
	 */
	public static function formatMs( int $ms, string $format ): string {
		$seconds = intdiv( $ms, 1000 );
		$millis  = $ms % 1000;
		if ( $millis < 0 ) {
			$millis += 1000;
			--$seconds;
		}
		return gmdate( $format, $seconds ) . '.' . str_pad( (string) $millis, 3, '0', STR_PAD_LEFT );
	}

	/**
	 * Parse "Y-m-d H:i:s[.v]" or ISO-8601 (UTC) to milliseconds.
	 *
	 * @param string $value Date string.
	 */
	public static function parseMs( string $value ): ?int {
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?Z?$/', trim( $value ), $m ) ) {
			return null;
		}
		$seconds = strtotime( $m[1] . ' ' . $m[2] . ' UTC' );
		if ( false === $seconds ) {
			return null;
		}
		$fraction = isset( $m[3] ) ? substr( str_pad( $m[3], 3, '0' ), 0, 3 ) : '000';
		return $seconds * 1000 + (int) $fraction;
	}

	/**
	 * The CONTRACT §1 JSON shape. Key order follows the contract.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return [
			'id'          => $this->id,
			'occurred_at' => $this->occurredAtIso(),
			'severity'    => $this->severity->value,
			'category'    => $this->category->value,
			'action'      => $this->action,
			'summary'     => $this->summary,
			'object'      => $this->object,
			'actor'       => $this->actor ?? [
				'type' => 'system',
				'id'   => null,
				'role' => null,
			],
			'request'     => $this->request,
			'changes'     => $this->changes,
			'context'     => $this->context,
			'fingerprint' => $this->fingerprint,
			'count'       => $this->count,
			'site'        => $this->site,
			'producer'    => self::producer(),
			'hash'        => $this->hash,
			'prev_hash'   => $this->prevHash,
		];
	}

	/**
	 * Producer block.
	 *
	 * @return array{name: string, version: string, schema: int}
	 */
	public static function producer(): array {
		return [
			'name'    => self::PRODUCER_NAME,
			'version' => defined( 'NOFORMAT_LOGGER_VERSION' ) ? (string) NOFORMAT_LOGGER_VERSION : '0.0.0',
			'schema'  => self::SCHEMA,
		];
	}

	/**
	 * Multibyte-safe clip.
	 *
	 * @param string $value Value.
	 * @param int    $max   Max characters.
	 */
	public static function clip( string $value, int $max ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_strlen( $value, 'UTF-8' ) > $max ? mb_substr( $value, 0, $max, 'UTF-8' ) : $value;
		}
		return strlen( $value ) > $max ? substr( $value, 0, $max ) : $value;
	}
}
