<?php
/**
 * Event repository.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Storage;

use Noformat\Logger\Event;
use Noformat\Logger\Severity;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin tables; dynamic placeholder lists are built from constants and passed through prepare().

/**
 * Storage of events: chained batch insert, cursor queries, summary, prune, verify.
 */
final class EventRepository {

	private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

	/** Column order used for inserts. */
	public const COLUMNS = [
		'occurred_at',
		'occurred_ms',
		'severity',
		'category',
		'action',
		'object_type',
		'object_id',
		'object_subtype',
		'object_label_hash',
		'actor_type',
		'actor_id',
		'actor_role',
		'request_id',
		'source',
		'route',
		'method',
		'ip_hash',
		'ua_hash',
		'summary',
		'changes',
		'context',
		'fingerprint',
		'count',
		'site_id',
		'prev_hash',
		'hash',
	];

	/**
	 * Constructor.
	 *
	 * @param Meta $meta Meta store.
	 */
	public function __construct( private Meta $meta ) {
	}

	/**
	 * Insert events in one multi-row INSERT, computing the hash chain inside a
	 * transaction that locks the chain_head meta row.
	 *
	 * Without $transactional (fatal error and uncaught exception paths) no
	 * transaction is opened and the chain head is read without FOR UPDATE: a
	 * START TRANSACTION on the shared connection would implicitly commit a
	 * transaction the site left open when it crashed. Two requests writing at
	 * that same instant may then fork the chain (reported by verify); accepted
	 * for the crash path.
	 *
	 * @param list<Event> $events        Events (request/actor/site already filled).
	 * @param bool        $transactional Use a transaction and a row lock.
	 * @return list<Event> The same events with id, hash and prev_hash set.
	 * @throws \Throwable On storage failure (after rollback).
	 */
	public function insertBatch( array $events, bool $transactional = true ): array {
		if ( [] === $events ) {
			return [];
		}
		$table = Db::table( 'events' );
		if ( $transactional ) {
			Db::query( 'START TRANSACTION' );
		}
		try {
			$head = $this->lockChainHead( $transactional );
			$prev = $head['hash'];

			$placeholders = [];
			$args         = [ $table ];
			$hashes       = [];
			foreach ( $events as $event ) {
				self::normalize( $event );
				$hash = HashChain::hash( $prev, $event->toArray() );
				$event->withChain( null, $hash, $prev );
				$row   = self::eventToRow( $event );
				$cells = [];
				foreach ( self::COLUMNS as $column ) {
					$value = $row[ $column ];
					if ( null === $value ) {
						$cells[] = 'NULL';
					} elseif ( is_int( $value ) ) {
						$cells[] = '%d';
						$args[]  = $value;
					} else {
						$cells[] = '%s';
						$args[]  = (string) $value;
					}
				}
				$placeholders[] = '(' . implode( ',', $cells ) . ')';
				$hashes[]       = $hash;
				$prev           = $hash;
			}

			$columns = '`' . implode( '`,`', self::COLUMNS ) . '`';
			Db::query( Db::prepare( "INSERT INTO %i ({$columns}) VALUES " . implode( ',', $placeholders ), ...$args ) );

			$in   = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
			$rows = Db::getResults( Db::prepare( "SELECT id, `hash` FROM %i WHERE id > %d AND `hash` IN ({$in})", $table, $head['id'], ...$hashes ) );
			$ids  = [];
			foreach ( $rows as $row ) {
				$ids[ (string) $row['hash'] ] = (int) $row['id'];
			}
			$lastId = $head['id'];
			foreach ( $events as $event ) {
				$id = $ids[ (string) $event->getHash() ] ?? null;
				$event->withChain( $id, (string) $event->getHash(), (string) $event->getPrevHash() );
				$lastId = max( $lastId, (int) $id );
			}

			$this->meta->setJson(
				Meta::CHAIN_HEAD,
				[
					'id'   => $lastId,
					'hash' => $prev,
				]
			);
			if ( $transactional ) {
				Db::query( 'COMMIT' );
			}
		} catch ( \Throwable $e ) {
			try {
				if ( $transactional ) {
					Db::query( 'ROLLBACK' );
				}
			} catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- best effort.
				unset( $ignored );
			}
			$this->meta->flushCache();
			throw $e;
		}
		return $events;
	}

	/**
	 * Read the chain head, with a row lock (MySQL) inside the open transaction when $lock.
	 *
	 * @param bool $lock Add FOR UPDATE.
	 * @return array{id: int, hash: string}
	 */
	private function lockChainHead( bool $lock = true ): array {
		$sql = 'SELECT meta_value FROM %i WHERE meta_key = %s' . ( $lock && ! Db::isSqlite() ? ' FOR UPDATE' : '' );
		$raw = Db::getVar( Db::prepare( $sql, Db::table( 'meta' ), Meta::CHAIN_HEAD ) );
		if ( null === $raw ) {
			$head = [
				'id'   => 0,
				'hash' => HashChain::GENESIS,
			];
			$this->meta->setJson( Meta::CHAIN_HEAD, $head );
			return $head;
		}
		$decoded = json_decode( $raw, true );
		return [
			'id'   => is_array( $decoded ) ? (int) ( $decoded['id'] ?? 0 ) : 0,
			'hash' => is_array( $decoded ) && is_string( $decoded['hash'] ?? null ) ? $decoded['hash'] : HashChain::GENESIS,
		];
	}

	/**
	 * Chain head as stored in meta.
	 *
	 * @return array{id: int, hash: string}
	 */
	public function chainHead(): array {
		$this->meta->flushCache();
		$head = $this->meta->getJson( Meta::CHAIN_HEAD );
		return [
			'id'   => (int) ( $head['id'] ?? 0 ),
			'hash' => is_string( $head['hash'] ?? null ) ? $head['hash'] : HashChain::GENESIS,
		];
	}

	/**
	 * Round-trip changes/context through JSON so the hashed form equals the stored form.
	 *
	 * @param Event $event Event.
	 */
	public static function normalize( Event $event ): void {
		$changes = json_decode( self::json( $event->getChanges() ), true );
		$context = json_decode( self::json( $event->getContext() ), true );
		/** Shapes are preserved by the round trip. @var list<array{field: string, old: mixed, new: mixed}> $changes */
		$changes = is_array( $changes ) ? $changes : [];
		$context = is_array( $context ) ? $context : [];
		$event->withPayload( $changes, $context );
	}

	/**
	 * Event to column values.
	 *
	 * @param Event $event Event.
	 * @return array<string, string|int|null>
	 */
	public static function eventToRow( Event $event ): array {
		$object  = $event->getObject();
		$actor   = $event->getActor() ?? [
			'type' => 'system',
			'id'   => null,
			'role' => null,
		];
		$request = $event->getRequest();
		$site    = $event->getSite();
		return [
			'occurred_at'       => $event->occurredAtSql(),
			'occurred_ms'       => $event->getOccurredAtMs(),
			'severity'          => $event->getSeverity()->priority(),
			'category'          => $event->getCategory()->value,
			'action'            => $event->getAction(),
			'object_type'       => $object['type'] ?? null,
			'object_id'         => $object['id'] ?? null,
			'object_subtype'    => $object['subtype'] ?? null,
			'object_label_hash' => $object['label_hash'] ?? null,
			'actor_type'        => $actor['type'],
			'actor_id'          => null === $actor['id'] ? null : (int) $actor['id'],
			'actor_role'        => $actor['role'],
			'request_id'        => $request['id'] ?? '',
			'source'            => $request['source'] ?? '',
			'route'             => $request['route'] ?? null,
			'method'            => $request['method'] ?? null,
			'ip_hash'           => $request['ip_hash'] ?? null,
			'ua_hash'           => $request['ua_hash'] ?? null,
			'summary'           => $event->getSummary(),
			'changes'           => self::json( $event->getChanges() ),
			'context'           => self::json( $event->getContext() ),
			'fingerprint'       => $event->getFingerprint(),
			'count'             => $event->getCount(),
			'site_id'           => null === $site ? 1 : (int) $site['id'],
			'prev_hash'         => (string) $event->getPrevHash(),
			'hash'              => (string) $event->getHash(),
		];
	}

	/**
	 * Stored row to the CONTRACT §1 shape (must mirror Event::toArray()).
	 *
	 * @param array<string, mixed> $row     Row.
	 * @param string|null          $siteUrl Site URL for the envelope.
	 * @param bool                 $compact Omit context.
	 * @return array<string, mixed>
	 */
	public static function rowToArray( array $row, ?string $siteUrl = null, bool $compact = false ): array {
		$ms      = isset( $row['occurred_ms'] ) && (int) $row['occurred_ms'] > 0 ? (int) $row['occurred_ms'] : ( Event::parseMs( (string) $row['occurred_at'] ) ?? 0 );
		$changes = json_decode( (string) ( $row['changes'] ?? '[]' ), true );
		$context = json_decode( (string) ( $row['context'] ?? '[]' ), true );
		$array   = [
			'id'          => (int) $row['id'],
			'occurred_at' => Event::formatMs( $ms, 'Y-m-d\TH:i:s' ) . 'Z',
			'severity'    => Severity::fromPriority( (int) $row['severity'] )->value,
			'category'    => (string) $row['category'],
			'action'      => (string) $row['action'],
			'summary'     => (string) $row['summary'],
			'object'      => null === $row['object_type'] ? null : [
				'type'       => (string) $row['object_type'],
				'id'         => self::nullableString( $row['object_id'] ),
				'subtype'    => self::nullableString( $row['object_subtype'] ),
				'label_hash' => self::nullableString( $row['object_label_hash'] ),
			],
			'actor'       => [
				'type' => (string) $row['actor_type'],
				'id'   => null === $row['actor_id'] ? null : (string) (int) $row['actor_id'],
				'role' => self::nullableString( $row['actor_role'] ),
			],
			'request'     => '' === (string) $row['request_id'] ? null : [
				'id'      => (string) $row['request_id'],
				'source'  => (string) $row['source'],
				'route'   => self::nullableString( $row['route'] ),
				'method'  => self::nullableString( $row['method'] ),
				'ip_hash' => self::nullableString( $row['ip_hash'] ),
				'ua_hash' => self::nullableString( $row['ua_hash'] ),
			],
			'changes'     => is_array( $changes ) ? $changes : [],
			'context'     => is_array( $context ) ? $context : [],
			'fingerprint' => self::nullableString( $row['fingerprint'] ),
			'count'       => (int) $row['count'],
			'site'        => [
				'id'  => (string) (int) $row['site_id'],
				'url' => $siteUrl,
			],
			'producer'    => Event::producer(),
			'hash'        => (string) $row['hash'],
			'prev_hash'   => (string) $row['prev_hash'],
		];
		if ( $compact ) {
			unset( $array['context'] );
		}
		return $array;
	}

	/**
	 * Cursor query, ascending by id.
	 *
	 * @param int                  $after   Exclusive lower bound.
	 * @param int                  $limit   1..500.
	 * @param array<string, mixed> $filters min_severity (Severity), categories (list<string>), since_ms, until_ms (int).
	 * @return array{rows: list<array<string, mixed>>, has_more: bool}
	 */
	public function after( int $after, int $limit, array $filters = [] ): array {
		$limit            = max( 1, min( 500, $limit ) );
		[ $where, $args ] = $this->where( $filters );
		$sql              = 'SELECT * FROM %i WHERE id > %d' . $where . ' ORDER BY id ASC LIMIT %d';
		$rows             = Db::getResults( Db::prepare( $sql, Db::table( 'events' ), $after, ...[ ...$args, $limit + 1 ] ) );
		$more             = count( $rows ) > $limit;
		return [
			'rows'     => $this->hydrateCounts( array_slice( $rows, 0, $limit ) ),
			'has_more' => $more,
		];
	}

	/**
	 * Most recent rows (ascending), for wp nflog tail.
	 *
	 * @param int                  $limit   Rows.
	 * @param array<string, mixed> $filters Filters as in after().
	 * @return list<array<string, mixed>>
	 */
	public function latest( int $limit, array $filters = [] ): array {
		[ $where, $args ] = $this->where( $filters );
		$rows             = Db::getResults( Db::prepare( 'SELECT * FROM %i WHERE id > 0' . $where . ' ORDER BY id DESC LIMIT %d', Db::table( 'events' ), ...[ ...$args, max( 1, $limit ) ] ) );
		return $this->hydrateCounts( array_reverse( $rows ) );
	}

	/**
	 * Build a WHERE suffix.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array{0: string, 1: list<string|int>}
	 */
	private function where( array $filters ): array {
		$sql  = '';
		$args = [];
		if ( ( $filters['min_severity'] ?? null ) instanceof Severity ) {
			$sql   .= ' AND severity <= %d';
			$args[] = $filters['min_severity']->priority();
		}
		if ( ! empty( $filters['categories'] ) && is_array( $filters['categories'] ) ) {
			$sql .= ' AND category IN (' . implode( ',', array_fill( 0, count( $filters['categories'] ), '%s' ) ) . ')';
			foreach ( $filters['categories'] as $category ) {
				$args[] = (string) $category;
			}
		}
		if ( isset( $filters['since_ms'] ) && is_int( $filters['since_ms'] ) ) {
			$sql   .= ' AND occurred_ms >= %d';
			$args[] = $filters['since_ms'];
		}
		if ( isset( $filters['until_ms'] ) && is_int( $filters['until_ms'] ) ) {
			$sql   .= ' AND occurred_ms < %d';
			$args[] = $filters['until_ms'];
		}
		return [ $sql, $args ];
	}

	/**
	 * Viewer page (wp-admin Events tab, dashboard widget, export).
	 *
	 * Always bounded by LIMIT; counts hydrated from open fingerprint windows.
	 * Sorting by count uses the stored count (an open window may be higher).
	 *
	 * @param array<string, mixed> $filters Typed filters (see viewerWhere()).
	 * @param int                  $page    1-based page.
	 * @param int                  $perPage Rows per page (1..5000).
	 * @param string               $orderBy ''|time|severity|category|action|count.
	 * @param string               $order   asc|desc.
	 * @return list<array<string, mixed>>
	 */
	public function query( array $filters, int $page = 1, int $perPage = 50, string $orderBy = '', string $order = 'desc' ): array {
		$perPage          = max( 1, min( 5000, $perPage ) );
		$page             = max( 1, $page );
		[ $where, $args ] = self::viewerWhere( $filters );
		$sql              = 'SELECT * FROM %i' . $where . ' ORDER BY ' . self::viewerOrder( $orderBy, $order ) . ' LIMIT %d OFFSET %d';
		$rows             = Db::getResults( Db::prepare( $sql, Db::table( 'events' ), ...[ ...$args, $perPage, ( $page - 1 ) * $perPage ] ) );
		return $this->hydrateCounts( $rows );
	}

	/**
	 * Number of rows matching viewer filters.
	 *
	 * @param array<string, mixed> $filters Typed filters (see viewerWhere()).
	 */
	public function count( array $filters ): int {
		[ $where, $args ] = self::viewerWhere( $filters );
		return (int) Db::getVar( Db::prepare( 'SELECT COUNT(*) FROM %i' . $where, Db::table( 'events' ), ...$args ) );
	}

	/**
	 * WHERE clause for viewer filters; every value goes through a placeholder.
	 *
	 * Keys: severity (Severity) + severity_mode (min|exact), category, action_prefix,
	 * source, actor_id (int), object_type, object_id, request_id, fingerprint,
	 * since_ms / until_ms (int), search (summary or action, substring); viewer
	 * scopes: categories (list<string>), severities (list<Severity>), object_subtype,
	 * exclude_object_subtype, actor_type, after_id / before_id (int cursors).
	 * Values of the wrong type are ignored.
	 *
	 * @param array<string, mixed> $filters Filters.
	 * @return array{0: string, 1: list<string|int>}
	 */
	public static function viewerWhere( array $filters ): array {
		$clauses = [];
		$args    = [];
		$string  = static fn( string $key ): ?string => isset( $filters[ $key ] ) && is_string( $filters[ $key ] ) && '' !== $filters[ $key ] ? $filters[ $key ] : null;
		if ( ( $filters['severity'] ?? null ) instanceof Severity ) {
			$clauses[] = 'exact' === ( $filters['severity_mode'] ?? 'min' ) ? 'severity = %d' : 'severity <= %d';
			$args[]    = $filters['severity']->priority();
		}
		foreach ( [ 'category', 'source', 'object_type', 'object_id', 'request_id', 'fingerprint' ] as $column ) {
			$value = $string( $column );
			if ( null !== $value ) {
				$clauses[] = $column . ' = %s';
				$args[]    = $value;
			}
		}
		$prefix = $string( 'action_prefix' );
		if ( null !== $prefix ) {
			$clauses[] = 'action LIKE %s';
			$args[]    = Db::wpdb()->esc_like( $prefix ) . '%';
		}
		if ( isset( $filters['actor_id'] ) && is_int( $filters['actor_id'] ) && $filters['actor_id'] > 0 ) {
			$clauses[] = 'actor_id = %d';
			$args[]    = $filters['actor_id'];
		}
		if ( isset( $filters['since_ms'] ) && is_int( $filters['since_ms'] ) ) {
			$clauses[] = 'occurred_ms >= %d';
			$args[]    = $filters['since_ms'];
		}
		if ( isset( $filters['until_ms'] ) && is_int( $filters['until_ms'] ) ) {
			$clauses[] = 'occurred_ms < %d';
			$args[]    = $filters['until_ms'];
		}
		$search = $string( 'search' );
		if ( null !== $search ) {
			$like      = '%' . Db::wpdb()->esc_like( $search ) . '%';
			$clauses[] = '(summary LIKE %s OR action LIKE %s)';
			$args[]    = $like;
			$args[]    = $like;
		}
		// Viewer scopes and cursors (wp-admin Content log / System log).
		$categories = isset( $filters['categories'] ) && is_array( $filters['categories'] ) ? array_values( array_filter( $filters['categories'], 'is_string' ) ) : [];
		if ( [] !== $categories ) {
			$clauses[] = 'category IN (' . implode( ',', array_fill( 0, count( $categories ), '%s' ) ) . ')';
			array_push( $args, ...$categories );
		}
		$severities = isset( $filters['severities'] ) && is_array( $filters['severities'] ) ? array_values( array_filter( $filters['severities'], static fn( mixed $s ): bool => $s instanceof Severity ) ) : [];
		if ( [] !== $severities ) {
			$clauses[] = 'severity IN (' . implode( ',', array_fill( 0, count( $severities ), '%d' ) ) . ')';
			foreach ( $severities as $severity ) {
				$args[] = $severity->priority();
			}
		}
		foreach ( [
			'object_subtype'         => 'object_subtype = %s',
			'exclude_object_subtype' => '(object_subtype IS NULL OR object_subtype <> %s)',
			'actor_type'             => 'actor_type = %s',
		] as $key => $clause ) {
			$value = $string( $key );
			if ( null !== $value ) {
				$clauses[] = $clause;
				$args[]    = $value;
			}
		}
		foreach ( [
			'after_id'  => 'id > %d',
			'before_id' => 'id < %d',
		] as $key => $clause ) {
			if ( isset( $filters[ $key ] ) && is_int( $filters[ $key ] ) && $filters[ $key ] > 0 ) {
				$clauses[] = $clause;
				$args[]    = $filters[ $key ];
			}
		}
		return [ [] === $clauses ? '' : ' WHERE ' . implode( ' AND ', $clauses ), $args ];
	}

	/**
	 * ORDER BY for the viewer from a whitelist (default: most crucial first, then newest).
	 *
	 * @param string $orderBy ''|time|severity|category|action|count; anything else is the default.
	 * @param string $order   asc|desc.
	 */
	public static function viewerOrder( string $orderBy, string $order ): string {
		$dir = 'asc' === strtolower( $order ) ? 'ASC' : 'DESC';
		return match ( $orderBy ) {
			'time'     => "occurred_ms {$dir}, id {$dir}",
			'severity' => "severity {$dir}, occurred_ms DESC, id DESC",
			'category' => "category {$dir}, severity ASC, occurred_ms DESC, id DESC",
			'action'   => "action {$dir}, severity ASC, occurred_ms DESC, id DESC",
			'count'    => "`count` {$dir}, severity ASC, occurred_ms DESC, id DESC",
			default    => 'severity ASC, occurred_ms DESC, id DESC',
		};
	}

	/**
	 * Distinct actors matching viewer filters (wp-admin person filter), most active first.
	 *
	 * @param array<string, mixed> $filters Typed filters (see viewerWhere()).
	 * @param int                  $limit   Max actors (1..200).
	 * @return list<array{actor_type: string, actor_id: int|null, events: int}>
	 */
	public function actors( array $filters, int $limit = 50 ): array {
		[ $where, $args ] = self::viewerWhere( $filters );
		$rows             = Db::getResults( Db::prepare( 'SELECT actor_type, actor_id, COUNT(*) AS events FROM %i' . $where . ' GROUP BY actor_type, actor_id ORDER BY events DESC LIMIT %d', Db::table( 'events' ), ...[ ...$args, max( 1, min( 200, $limit ) ) ] ) );
		$out              = [];
		foreach ( $rows as $row ) {
			$out[] = [
				'actor_type' => (string) $row['actor_type'],
				'actor_id'   => null === $row['actor_id'] ? null : (int) $row['actor_id'],
				'events'     => (int) $row['events'],
			];
		}
		return $out;
	}

	/** Most rows fetched for one page of stories (a long CLI run can share one request id). */
	public const STORY_ROWS_MAX = 2000;

	/**
	 * One page of stories (Content log, dashboard widget): events grouped by
	 * request id, an event without one is a story of its own. Stories are
	 * paged in SQL, newest last event first, so pagination counts stories; a
	 * story holds its events matching the filters, ascending by id.
	 *
	 * Queries: the page of story keys, the total (when $withTotal), the events
	 * of the page (bounded by STORY_ROWS_MAX) and the count hydration.
	 *
	 * @param array<string, mixed> $filters   Typed filters (see viewerWhere()).
	 * @param int                  $page      1-based page.
	 * @param int                  $perPage   Stories per page (1..200).
	 * @param bool                 $withTotal Also count all stories.
	 * @return array{stories: list<array{rows: list<array<string, mixed>>, events: int, last_ms: int}>, total: int}
	 */
	public function storiesPage( array $filters, int $page = 1, int $perPage = 50, bool $withTotal = true ): array {
		$perPage          = max( 1, min( 200, $perPage ) );
		$page             = max( 1, $page );
		$table            = Db::table( 'events' );
		[ $where, $args ] = self::viewerWhere( $filters );
		$total            = 0;
		if ( $withTotal ) {
			$row   = Db::getRow( Db::prepare( "SELECT COUNT(DISTINCT CASE WHEN request_id <> '' THEN request_id END) AS requests, SUM(CASE WHEN request_id = '' THEN 1 ELSE 0 END) AS singles FROM %i" . $where, $table, ...$args ) );
			$total = (int) ( $row['requests'] ?? 0 ) + (int) ( $row['singles'] ?? 0 );
			if ( 0 === $total ) {
				return [
					'stories' => [],
					'total'   => 0,
				];
			}
		}
		$keys = Db::getResults(
			Db::prepare(
				"SELECT request_id, MAX(id) AS last_id, MAX(occurred_ms) AS last_ms, COUNT(*) AS events FROM %i{$where} GROUP BY request_id, CASE WHEN request_id = '' THEN id ELSE 0 END ORDER BY last_ms DESC, last_id DESC LIMIT %d OFFSET %d",
				$table,
				...[ ...$args, $perPage, ( $page - 1 ) * $perPage ]
			)
		);
		if ( [] === $keys ) {
			return [
				'stories' => [],
				'total'   => $total,
			];
		}
		$requests = [];
		$singles  = [];
		foreach ( $keys as $key ) {
			if ( '' === (string) $key['request_id'] ) {
				$singles[] = (int) $key['last_id'];
			} else {
				$requests[] = (string) $key['request_id'];
			}
		}
		$in     = [];
		$inArgs = [];
		if ( [] !== $requests ) {
			$in[]   = 'request_id IN (' . implode( ',', array_fill( 0, count( $requests ), '%s' ) ) . ')';
			$inArgs = $requests;
		}
		if ( [] !== $singles ) {
			$in[] = 'id IN (' . implode( ',', array_fill( 0, count( $singles ), '%d' ) ) . ')';
			array_push( $inArgs, ...$singles );
		}
		$sql  = 'SELECT * FROM %i' . $where . ( '' === $where ? ' WHERE ' : ' AND ' ) . '(' . implode( ' OR ', $in ) . ') ORDER BY id ASC LIMIT %d';
		$rows = $this->hydrateCounts( Db::getResults( Db::prepare( $sql, $table, ...[ ...$args, ...$inArgs, self::STORY_ROWS_MAX ] ) ) );

		$byKey = [];
		foreach ( $rows as $row ) {
			$request = (string) ( $row['request_id'] ?? '' );
			$byKey[ '' === $request ? '#' . (int) $row['id'] : $request ][] = $row;
		}
		$stories = [];
		foreach ( $keys as $key ) {
			$request = (string) $key['request_id'];
			$group   = $byKey[ '' === $request ? '#' . (int) $key['last_id'] : $request ] ?? [];
			if ( [] === $group ) {
				continue; // Beyond STORY_ROWS_MAX.
			}
			$stories[] = [
				'rows'    => $group,
				'events'  => max( count( $group ), (int) $key['events'] ),
				'last_ms' => (int) $key['last_ms'],
			];
		}
		return [
			'stories' => $stories,
			'total'   => $withTotal ? $total : count( $stories ),
		];
	}

	/**
	 * Users who logged in (auth.login) during the given requests: request id => user ids.
	 *
	 * @param list<string> $requestIds Request ids.
	 * @return array<string, list<int>>
	 */
	public function loginsInRequests( array $requestIds ): array {
		$requestIds = array_values( array_unique( array_filter( $requestIds, static fn( mixed $id ): bool => is_string( $id ) && '' !== $id ) ) );
		$out        = [];
		foreach ( array_chunk( $requestIds, 200 ) as $chunk ) {
			$sql  = 'SELECT request_id, object_id FROM %i WHERE action = %s AND object_type = %s AND request_id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ')';
			$rows = Db::getResults( Db::prepare( $sql, Db::table( 'events' ), 'auth.login', 'user', ...$chunk ) );
			foreach ( $rows as $row ) {
				if ( is_numeric( $row['object_id'] ?? null ) ) {
					$out[ (string) $row['request_id'] ][] = (int) $row['object_id'];
				}
			}
		}
		return $out;
	}

	/**
	 * Which of the given request ids also produced events in the given categories.
	 *
	 * @param list<string> $requestIds Request ids (32 hex).
	 * @param list<string> $categories Categories.
	 * @return list<string>
	 */
	public function requestsWithCategories( array $requestIds, array $categories ): array {
		$requestIds = array_values( array_unique( array_filter( $requestIds, static fn( mixed $id ): bool => is_string( $id ) && '' !== $id ) ) );
		$categories = array_values( array_filter( $categories, 'is_string' ) );
		if ( [] === $requestIds || [] === $categories ) {
			return [];
		}
		$out = [];
		foreach ( array_chunk( $requestIds, 200 ) as $chunk ) {
			$sql  = 'SELECT DISTINCT request_id FROM %i WHERE request_id IN (' . implode( ',', array_fill( 0, count( $chunk ), '%s' ) ) . ') AND category IN (' . implode( ',', array_fill( 0, count( $categories ), '%s' ) ) . ')';
			$rows = Db::getResults( Db::prepare( $sql, Db::table( 'events' ), ...[ ...$chunk, ...$categories ] ) );
			foreach ( $rows as $row ) {
				$out[] = (string) $row['request_id'];
			}
		}
		return $out;
	}

	/**
	 * Single row.
	 *
	 * @param int $id Id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$row = Db::getRow( Db::prepare( 'SELECT * FROM %i WHERE id = %d', Db::table( 'events' ), $id ) );
		return null === $row ? null : $this->hydrateCounts( [ $row ] )[0];
	}

	/**
	 * Live occurrence counts: while a fingerprint's window is open its row counts
	 * the occurrences (FingerprintStore); the event row is written back when the
	 * window is replaced. One query per call, only when a row has a fingerprint.
	 *
	 * @param list<array<string, mixed>> $rows Event rows.
	 * @return list<array<string, mixed>>
	 */
	public function hydrateCounts( array $rows ): array {
		$ids = [];
		foreach ( $rows as $row ) {
			if ( null !== ( $row['fingerprint'] ?? null ) && '' !== (string) $row['fingerprint'] ) {
				$ids[] = (int) $row['id'];
			}
		}
		if ( [] === $ids ) {
			return $rows;
		}
		$live = $this->liveCounts( $ids );
		foreach ( $rows as $i => $row ) {
			$id = (int) $row['id'];
			if ( isset( $live[ $id ] ) && $live[ $id ] > (int) $row['count'] ) {
				$rows[ $i ]['count'] = $live[ $id ];
			}
		}
		return $rows;
	}

	/**
	 * Fingerprint row counts by the event id they aggregate into.
	 *
	 * @param list<int> $ids Event ids.
	 * @return array<int, int>
	 */
	private function liveCounts( array $ids ): array {
		$out = [];
		foreach ( array_chunk( array_values( array_unique( $ids ) ), 500 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$rows = Db::getResults( Db::prepare( "SELECT last_event_id, `count` FROM %i WHERE last_event_id IN ({$in})", Db::table( 'fingerprints' ), ...$chunk ) );
			foreach ( $rows as $row ) {
				$out[ (int) $row['last_event_id'] ] = (int) $row['count'];
			}
		}
		return $out;
	}

	/**
	 * Occurrences counted on open fingerprint rows but not yet on their events, for events since a time.
	 *
	 * @param int $sinceMs Unix time in milliseconds.
	 * @return list<array{severity: int, category: string, fingerprint: string, delta: int}>
	 */
	private function openWindowDeltas( int $sinceMs ): array {
		$fps  = Db::getResults( Db::prepare( 'SELECT last_event_id, `count` FROM %i WHERE last_event_id > 0 AND `count` > 1 AND last_seen >= %d', Db::table( 'fingerprints' ), intdiv( $sinceMs, 1000 ) ) );
		$live = [];
		foreach ( $fps as $row ) {
			$live[ (int) $row['last_event_id'] ] = (int) $row['count'];
		}
		$out = [];
		foreach ( array_chunk( array_keys( $live ), 500 ) as $chunk ) {
			$in   = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			$rows = Db::getResults( Db::prepare( "SELECT id, severity, category, fingerprint, `count` FROM %i WHERE occurred_ms >= %d AND id IN ({$in})", Db::table( 'events' ), $sinceMs, ...$chunk ) );
			foreach ( $rows as $row ) {
				$delta = ( $live[ (int) $row['id'] ] ?? 0 ) - (int) $row['count'];
				if ( $delta > 0 ) {
					$out[] = [
						'severity'    => (int) $row['severity'],
						'category'    => (string) $row['category'],
						'fingerprint' => (string) $row['fingerprint'],
						'delta'       => $delta,
					];
				}
			}
		}
		return $out;
	}

	/**
	 * Stats for the manifest/status page.
	 *
	 * @return array{count: int, min_id: int, max_id: int}
	 */
	public function stats(): array {
		$row = Db::getRow( Db::prepare( 'SELECT COUNT(*) AS c, MIN(id) AS min_id, MAX(id) AS max_id FROM %i', Db::table( 'events' ) ) );
		return [
			'count'  => (int) ( $row['c'] ?? 0 ),
			'min_id' => (int) ( $row['min_id'] ?? 0 ),
			'max_id' => (int) ( $row['max_id'] ?? 0 ),
		];
	}

	/**
	 * Stored events and aggregated occurrences per severity since a point in time.
	 *
	 * One grouped query on (occurred_ms) plus the open-window correction
	 * (occurrences still counted on fingerprint rows); every severity is
	 * present, zero when none.
	 *
	 * @param int                                                                                $sinceMs Unix time in milliseconds (inclusive).
	 * @param list<array{severity: int, category: string, fingerprint: string, delta: int}>|null $deltas  Precomputed open-window deltas.
	 * @param list<string>                                                                       $categories Only these categories (empty: all).
	 * @return array<string, array{events: int, occurrences: int}>
	 */
	public function countsBySeverity( int $sinceMs, ?array $deltas = null, array $categories = [] ): array {
		$out = [];
		foreach ( Severity::cases() as $severity ) {
			$out[ $severity->value ] = [
				'events'      => 0,
				'occurrences' => 0,
			];
		}
		$categories = array_values( array_filter( $categories, 'is_string' ) );
		$in         = [] === $categories ? '' : ' AND category IN (' . implode( ',', array_fill( 0, count( $categories ), '%s' ) ) . ')';
		$rows       = Db::getResults( Db::prepare( 'SELECT severity, COUNT(*) AS events, SUM(`count`) AS occurrences FROM %i WHERE occurred_ms >= %d' . $in . ' GROUP BY severity', Db::table( 'events' ), $sinceMs, ...$categories ) );
		foreach ( $rows as $row ) {
			$out[ Severity::fromPriority( (int) $row['severity'] )->value ] = [
				'events'      => (int) $row['events'],
				'occurrences' => (int) $row['occurrences'],
			];
		}
		foreach ( $deltas ?? $this->openWindowDeltas( $sinceMs ) as $delta ) {
			if ( [] !== $categories && ! in_array( $delta['category'], $categories, true ) ) {
				continue;
			}
			$out[ Severity::fromPriority( $delta['severity'] )->value ]['occurrences'] += $delta['delta'];
		}
		return $out;
	}

	/**
	 * Counts per severity and category plus the top fingerprints (20 by default) in a window.
	 *
	 * @param int      $seconds Window length.
	 * @param int|null $nowMs   Now in ms (tests).
	 * @param int      $top     Fingerprints returned (1..20; one row lookup each).
	 * @return array<string, mixed>
	 */
	public function summary( int $seconds, ?int $nowMs = null, int $top = 20 ): array {
		$nowMs = $nowMs ?? (int) floor( microtime( true ) * 1000 );
		$since = $nowMs - $seconds * 1000;
		$table = Db::table( 'events' );

		$deltas     = $this->openWindowDeltas( $since );
		$bySeverity = $this->countsBySeverity( $since, $deltas );

		$byCategory = [];
		$rows       = Db::getResults( Db::prepare( 'SELECT category, COUNT(*) AS events, SUM(`count`) AS occurrences, MIN(severity) AS top FROM %i WHERE occurred_ms >= %d GROUP BY category ORDER BY top ASC, events DESC', $table, $since ) );
		foreach ( $rows as $row ) {
			$byCategory[ (string) $row['category'] ] = [
				'events'       => (int) $row['events'],
				'occurrences'  => (int) $row['occurrences'],
				'top_severity' => Severity::fromPriority( (int) $row['top'] )->value,
			];
		}
		$byFingerprint = [];
		foreach ( $deltas as $delta ) {
			if ( isset( $byCategory[ $delta['category'] ] ) ) {
				$byCategory[ $delta['category'] ]['occurrences'] += $delta['delta'];
			}
			$byFingerprint[ $delta['fingerprint'] ] = ( $byFingerprint[ $delta['fingerprint'] ] ?? 0 ) + $delta['delta'];
		}

		$limit = max( 1, min( 20, $top ) );
		$top   = [];
		$rows  = Db::getResults(
			Db::prepare(
				'SELECT fingerprint, MIN(severity) AS top, COUNT(*) AS events, SUM(`count`) AS occurrences, MAX(id) AS last_id FROM %i WHERE occurred_ms >= %d AND fingerprint IS NOT NULL GROUP BY fingerprint ORDER BY top ASC, occurrences DESC LIMIT 100',
				$table,
				$since
			)
		);
		foreach ( $rows as $row ) {
			$row['occurrences'] = (int) $row['occurrences'] + ( $byFingerprint[ (string) $row['fingerprint'] ] ?? 0 );
		}
		usort(
			$rows,
			static fn( array $a, array $b ): int => [ (int) $a['top'], - (int) $a['occurrences'] ] <=> [ (int) $b['top'], - (int) $b['occurrences'] ]
		);
		foreach ( array_slice( $rows, 0, $limit ) as $row ) {
			$last  = Db::getRow( Db::prepare( 'SELECT * FROM %i WHERE id = %d', $table, (int) $row['last_id'] ) );
			$top[] = [
				'fingerprint'  => (string) $row['fingerprint'],
				'severity'     => Severity::fromPriority( (int) $row['top'] )->value,
				'category'     => (string) ( $last['category'] ?? '' ),
				'action'       => (string) ( $last['action'] ?? '' ),
				'summary'      => (string) ( $last['summary'] ?? '' ),
				'events'       => (int) $row['events'],
				'occurrences'  => (int) $row['occurrences'],
				'last_id'      => (int) $row['last_id'],
				'last_seen_at' => null === $last ? null : self::rowToArray( $last )['occurred_at'],
			];
		}

		return [
			'window_seconds'   => $seconds,
			'since'            => Event::formatMs( $nowMs - $seconds * 1000, 'Y-m-d\TH:i:s' ) . 'Z',
			'by_severity'      => $bySeverity,
			'by_category'      => $byCategory,
			'top_fingerprints' => $top,
		];
	}

	/**
	 * Prune per PLAN §8. Always deletes an id prefix so the remaining chain stays contiguous.
	 *
	 * - older than retention_days AND id <= ack cursor;
	 * - older than hard_max_days regardless of ack;
	 * - the oldest rows beyond max_rows: acknowledged rows and unacknowledged
	 *   debug/info rows, but never past the first unacknowledged row of severity
	 *   notice or higher (only hard_max_days removes those). The table may then
	 *   stay above max_rows until the collector catches up.
	 *
	 * @param int      $retentionDays Retention.
	 * @param int      $hardMaxDays   Hard maximum age.
	 * @param int      $maxRows       Max rows.
	 * @param int      $ackCursor     Collector ack cursor.
	 * @param bool     $dryRun        Only count.
	 * @param int|null $nowMs     Now (tests).
	 * @return array{boundary_id: int, rows: int, dry_run: bool, rules: array<string, int>, max_rows_capped?: bool}
	 */
	public function prune( int $retentionDays, int $hardMaxDays, int $maxRows, int $ackCursor, bool $dryRun = false, ?int $nowMs = null ): array {
		$nowMs = $nowMs ?? (int) floor( microtime( true ) * 1000 );
		$table = Db::table( 'events' );
		$stats = $this->stats();

		$retention = min( $this->maxIdBefore( $nowMs - $retentionDays * 86400000 ), $ackCursor );
		$hard      = $hardMaxDays > 0 ? $this->maxIdBefore( $nowMs - $hardMaxDays * 86400000 ) : 0;
		$rows      = 0;
		$capped    = false;
		if ( $maxRows > 0 && $stats['count'] > $maxRows ) {
			$rows  = (int) Db::getVar( Db::prepare( 'SELECT id FROM %i ORDER BY id ASC LIMIT 1 OFFSET %d', $table, $stats['count'] - $maxRows - 1 ) );
			$guard = (int) Db::getVar( Db::prepare( 'SELECT MIN(id) FROM %i WHERE id > %d AND severity <= %d', $table, max( 0, $ackCursor ), Severity::NOTICE->priority() ) );
			if ( $guard > 0 && $rows >= $guard ) {
				$rows   = $guard - 1;
				$capped = true;
			}
		}
		$boundary = max( 0, $retention, $hard, $rows );
		$result   = [
			'boundary_id' => $boundary,
			'rows'        => 0,
			'dry_run'     => $dryRun,
			'rules'       => [
				'retention' => max( 0, $retention ),
				'hard_max'  => $hard,
				'max_rows'  => $rows,
			],
		];
		if ( $capped ) {
			$result['max_rows_capped'] = true;
		}
		if ( $boundary <= 0 ) {
			return $result;
		}
		$result['rows'] = (int) Db::getVar( Db::prepare( 'SELECT COUNT(*) FROM %i WHERE id <= %d', $table, $boundary ) );
		if ( $dryRun || 0 === $result['rows'] ) {
			return $result;
		}

		$anchor = Db::getVar( Db::prepare( 'SELECT `hash` FROM %i WHERE id <= %d ORDER BY id DESC LIMIT 1', $table, $boundary ) );
		$start  = $stats['min_id'];
		while ( $start <= $boundary ) {
			$end = min( $boundary, $start + 4999 );
			Db::query( Db::prepare( 'DELETE FROM %i WHERE id <= %d', $table, $end ) );
			$start = $end + 1;
		}
		Db::query( Db::prepare( 'DELETE FROM %i WHERE last_event_id <= %d', Db::table( 'fingerprints' ), $boundary ) );
		$this->meta->setJson(
			Meta::PRUNED_THROUGH,
			[
				'id'   => $boundary,
				'hash' => (string) $anchor,
				'at'   => time(),
			]
		);
		return $result;
	}

	/**
	 * Highest id with occurred_at before a time.
	 *
	 * @param int $ms Cutoff in ms.
	 */
	private function maxIdBefore( int $ms ): int {
		return (int) Db::getVar( Db::prepare( 'SELECT MAX(id) FROM %i WHERE occurred_ms < %d', Db::table( 'events' ), $ms ) );
	}

	/**
	 * Recompute the chain over [from, to] (bounded by maxRows).
	 *
	 * @param int|null $from    First id (default: oldest).
	 * @param int|null $to      Last id (default: newest).
	 * @param int      $maxRows Upper bound of rows checked per call.
	 * @return array<string, mixed>
	 */
	public function verify( ?int $from = null, ?int $to = null, int $maxRows = 50000 ): array {
		$table = Db::table( 'events' );
		$stats = $this->stats();
		$from  = max( (int) $from, $stats['min_id'], 1 );
		$to    = null === $to || $to <= 0 ? $stats['max_id'] : min( $to, $stats['max_id'] );

		$previous = Db::getVar( Db::prepare( 'SELECT `hash` FROM %i WHERE id < %d ORDER BY id DESC LIMIT 1', $table, $from ) );
		$anchor   = 'previous_row';
		if ( null === $previous ) {
			$pruned = $this->meta->getJson( Meta::PRUNED_THROUGH );
			if ( is_array( $pruned ) && ! empty( $pruned['hash'] ) ) {
				$previous = (string) $pruned['hash'];
				$anchor   = 'prune_anchor';
			} else {
				$previous = HashChain::GENESIS;
				$anchor   = 'genesis';
			}
		}

		$truncated = false;
		$lastSeen  = $from - 1;
		$rows      = function () use ( $table, $from, $to, $maxRows, &$truncated, &$lastSeen ): \Generator {
			$cursor = $from - 1;
			$seen   = 0;
			while ( $cursor < $to ) {
				$batch = Db::getResults( Db::prepare( 'SELECT * FROM %i WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT 1000', $table, $cursor, $to ) );
				if ( [] === $batch ) {
					break;
				}
				foreach ( $batch as $row ) {
					if ( $seen >= $maxRows ) {
						$truncated = true;
						return;
					}
					++$seen;
					$cursor   = (int) $row['id'];
					$lastSeen = $cursor;
					yield self::rowToArray( $row );
				}
			}
		};

		$result              = HashChain::verify( $rows(), $previous );
		$result['from']      = $from;
		$result['to']        = $to;
		$result['anchor']    = $anchor;
		$result['truncated'] = $truncated;
		$result['next_from'] = $truncated ? $lastSeen + 1 : null;

		$head = $this->chainHead();
		if ( $result['ok'] && ! $truncated && $to >= $stats['max_id'] && $result['checked'] > 0 ) {
			$result['head_ok'] = $head['id'] === $result['last_id'] && hash_equals( $head['hash'], (string) $result['last_hash'] );
			if ( ! $result['head_ok'] ) {
				$result['ok']    = false;
				$result['break'] = [
					'id'       => $result['last_id'],
					'reason'   => 'chain_head_mismatch',
					'expected' => $head['id'] . ':' . $head['hash'],
					'actual'   => $result['last_id'] . ':' . $result['last_hash'],
				];
			}
		}
		$result['chain_head'] = $head;
		return $result;
	}

	/**
	 * Storage JSON.
	 *
	 * @param array<mixed> $value Value.
	 */
	private static function json( array $value ): string {
		$json = json_encode( $value, self::JSON_FLAGS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- deterministic flags; must match decoding in rowToArray().
		return false === $json ? '[]' : $json;
	}

	/**
	 * Cast helper.
	 *
	 * @param mixed $value Value.
	 */
	private static function nullableString( mixed $value ): ?string {
		return null === $value ? null : (string) $value;
	}
}
