<?php
/**
 * Resolve and reopen issues.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger;

use Noformat\Logger\Storage\Db;
use Noformat\Logger\Storage\EventRepository;
use Noformat\Logger\Storage\FingerprintStore;
use Noformat\Logger\Storage\Resolutions;

defined( 'ABSPATH' ) || exit;

/**
 * Marks issues (a fingerprint, or a single warning/error/critical event without
 * one) resolved through the newest event id at the time, or reopens them, and
 * logs each change as `logger.issue_resolved` / `logger.issue_reopened`
 * (category system, severity notice, object `issue / <fingerprint|event:id>`;
 * the Controlroom mirrors resolutions from their context). Events are never
 * modified (docs/EVENTS.md, "Resolved issues").
 *
 * Resolving also closes the fingerprint's aggregation window, so a recurrence
 * is stored as a new event (id above the resolution) and the issue is open again.
 */
final class Issues {

	/**
	 * Constructor.
	 *
	 * @param Resolutions      $resolutions  Resolution store.
	 * @param FingerprintStore $fingerprints Fingerprint store.
	 * @param Logger           $logger       Logger.
	 */
	public function __construct( private Resolutions $resolutions, private FingerprintStore $fingerprints, private Logger $logger ) {
	}

	/**
	 * Resolve an issue through its newest event.
	 *
	 * @param string   $reference Fingerprint or `event:<id>` (an event with a fingerprint resolves its fingerprint).
	 * @param int|null $userId    Resolving user (null: none).
	 * @param int|null $now       Unix time (tests).
	 * @return array{key: string, fingerprint: string|null, event_id: int|null, through_id: int, resolved_at: int, resolved_by: int|null, changed: bool}
	 * @throws \DomainException When the reference is invalid, unknown or not a warning/error/critical issue.
	 */
	public function resolve( string $reference, ?int $userId, ?int $now = null ): array {
		$issue    = $this->locate( $reference );
		$key      = Resolutions::key( $issue['fingerprint'], $issue['event_id'] );
		$existing = $this->resolutions->find( $key );
		$userId   = null !== $userId && $userId > 0 ? $userId : null;
		if ( null !== $existing && $existing['through_id'] >= $issue['through_id'] ) {
			return [
				'key'         => $key,
				'fingerprint' => $issue['fingerprint'],
				'event_id'    => $issue['event_id'],
				'through_id'  => $existing['through_id'],
				'resolved_at' => $existing['resolved_at'],
				'resolved_by' => $existing['resolved_by'],
				'changed'     => false,
			];
		}
		$now = $now ?? time();
		$this->resolutions->save( $issue['fingerprint'], $issue['event_id'], $issue['through_id'], $now, $userId );
		if ( null !== $issue['fingerprint'] ) {
			try {
				$this->fingerprints->close( $issue['fingerprint'] );
			} catch ( \Throwable $e ) {
				$this->logger->recordFailure( 'issue_close_window', $e );
			}
		}
		$this->logger->log(
			Event::make( Category::SYSTEM, 'logger.issue_resolved', Severity::NOTICE )
				->object( 'issue', $key )
				->summary( sprintf( 'Issue %s resolved through event #%d', $key, $issue['through_id'] ) )
				->context( self::resolvedContext( $issue['fingerprint'], $issue['event_id'], $issue['through_id'] ) )
		);
		return [
			'key'         => $key,
			'fingerprint' => $issue['fingerprint'],
			'event_id'    => $issue['event_id'],
			'through_id'  => $issue['through_id'],
			'resolved_at' => $now,
			'resolved_by' => $userId,
			'changed'     => true,
		];
	}

	/**
	 * Reopen a resolved issue.
	 *
	 * @param string $reference Fingerprint or `event:<id>` (an event with a fingerprint reopens its fingerprint).
	 * @return array{key: string, fingerprint: string|null, event_id: int|null}
	 * @throws \DomainException When the reference is invalid or the issue is not resolved.
	 */
	public function reopen( string $reference ): array {
		$parsed = Resolutions::parse( $reference );
		if ( null === $parsed ) {
			throw new \DomainException( 'invalid_reference' );
		}
		$fingerprint = $parsed['fingerprint'];
		$eventId     = $parsed['event_id'];
		if ( null === $fingerprint && null !== $eventId ) {
			$row = Db::getRow( Db::prepare( 'SELECT id, fingerprint FROM %i WHERE id = %d', Db::table( 'events' ), $eventId ) );
			if ( null !== $row && is_string( $row['fingerprint'] ?? null ) && '' !== $row['fingerprint'] ) {
				$fingerprint = $row['fingerprint'];
				$eventId     = null;
			}
		}
		$key = Resolutions::key( $fingerprint, $eventId );
		if ( 0 === $this->resolutions->delete( $key ) ) {
			throw new \DomainException( 'not_resolved' );
		}
		$this->logger->log(
			Event::make( Category::SYSTEM, 'logger.issue_reopened', Severity::NOTICE )
				->object( 'issue', $key )
				->summary( sprintf( 'Issue %s reopened', $key ) )
				->context( self::reopenedContext( $fingerprint, $eventId ) )
		);
		return [
			'key'         => $key,
			'fingerprint' => $fingerprint,
			'event_id'    => $eventId,
		];
	}

	/**
	 * Open warning/error/critical issues matching viewer filters, newest first.
	 *
	 * @param array<string, mixed> $filters Typed viewer filters (EventRepository::viewerWhere()).
	 * @param int                  $limit   Most issues (1..BULK_MAX).
	 * @return array{issues: list<string>, more: bool} Issue keys; `more` when further open issues match.
	 */
	public function openIssues( array $filters, int $limit = Resolutions::BULK_MAX ): array {
		$limit                       = max( 1, min( Resolutions::BULK_MAX, $limit ) );
		$filters['max_priority']     = Severity::WARNING->priority();
		$filters['exclude_resolved'] = true;
		[ $where, $args ]            = EventRepository::viewerWhere( $filters );
		$and                         = '' === $where ? ' WHERE ' : ' AND ';
		$table                       = Db::table( 'events' );
		$keys                        = [];
		$rows                        = Db::getResults( Db::prepare( 'SELECT fingerprint, MAX(id) AS last_id FROM %i' . $where . $and . 'fingerprint IS NOT NULL GROUP BY fingerprint ORDER BY last_id DESC LIMIT %d', $table, ...[ ...$args, $limit + 1 ] ) );
		foreach ( $rows as $row ) {
			$keys[] = (string) $row['fingerprint'];
		}
		if ( count( $keys ) <= $limit ) {
			$rows = Db::getResults( Db::prepare( 'SELECT id FROM %i' . $where . $and . 'fingerprint IS NULL ORDER BY id DESC LIMIT %d', $table, ...[ ...$args, $limit + 1 - count( $keys ) ] ) );
			foreach ( $rows as $row ) {
				$keys[] = 'event:' . (int) $row['id'];
			}
		}
		return [
			'issues' => array_slice( $keys, 0, $limit ),
			'more'   => count( $keys ) > $limit,
		];
	}

	/**
	 * Resolve every open warning/error/critical issue matching viewer filters
	 * (at most BULK_MAX per call; one logger.issue_resolved each).
	 *
	 * @param array<string, mixed> $filters Typed viewer filters.
	 * @param int|null             $userId  Resolving user.
	 * @return array{resolved: int, failed: int, more: bool}
	 */
	public function resolveAllOpen( array $filters, ?int $userId ): array {
		$open     = $this->openIssues( $filters );
		$resolved = 0;
		$failed   = 0;
		foreach ( $open['issues'] as $key ) {
			try {
				$result    = $this->resolve( $key, $userId );
				$resolved += $result['changed'] ? 1 : 0;
			} catch ( \DomainException $e ) {
				++$failed;
			}
		}
		return [
			'resolved' => $resolved,
			'failed'   => $failed,
			'more'     => $open['more'],
		];
	}

	/**
	 * Context of logger.issue_resolved (keys read by the Controlroom collector: keep them).
	 *
	 * @param string|null $fingerprint Fingerprint.
	 * @param int|null    $eventId     Single event id.
	 * @param int         $throughId   Resolved through.
	 * @return array{fingerprint: string|null, event_id: int|null, through_id: int}
	 */
	public static function resolvedContext( ?string $fingerprint, ?int $eventId, int $throughId ): array {
		return [
			'fingerprint' => $fingerprint,
			'event_id'    => null === $fingerprint ? $eventId : null,
			'through_id'  => $throughId,
		];
	}

	/**
	 * Context of logger.issue_reopened.
	 *
	 * @param string|null $fingerprint Fingerprint.
	 * @param int|null    $eventId     Single event id.
	 * @return array{fingerprint: string|null, event_id: int|null}
	 */
	public static function reopenedContext( ?string $fingerprint, ?int $eventId ): array {
		return [
			'fingerprint' => $fingerprint,
			'event_id'    => null === $fingerprint ? $eventId : null,
		];
	}

	/**
	 * The issue a reference names and the event id to resolve it through.
	 *
	 * @param string $reference Fingerprint or `event:<id>`.
	 * @return array{fingerprint: string|null, event_id: int|null, through_id: int}
	 * @throws \DomainException Invalid, unknown, or not warning or worse.
	 */
	private function locate( string $reference ): array {
		$parsed = Resolutions::parse( $reference );
		if ( null === $parsed ) {
			throw new \DomainException( 'invalid_reference' );
		}
		$table       = Db::table( 'events' );
		$fingerprint = $parsed['fingerprint'];
		if ( null !== $parsed['event_id'] ) {
			$row = Db::getRow( Db::prepare( 'SELECT id, severity, fingerprint FROM %i WHERE id = %d', $table, $parsed['event_id'] ) );
			if ( null === $row ) {
				throw new \DomainException( 'not_found' );
			}
			if ( is_string( $row['fingerprint'] ?? null ) && '' !== $row['fingerprint'] ) {
				$fingerprint = $row['fingerprint'];
			} else {
				if ( (int) $row['severity'] > Severity::WARNING->priority() ) {
					throw new \DomainException( 'not_an_issue' );
				}
				return [
					'fingerprint' => null,
					'event_id'    => (int) $row['id'],
					'through_id'  => (int) $row['id'],
				];
			}
		}
		$row = Db::getRow( Db::prepare( 'SELECT MAX(id) AS through_id, MIN(severity) AS top FROM %i WHERE fingerprint = %s', $table, $fingerprint ) );
		if ( null === $row || null === $row['through_id'] ) {
			throw new \DomainException( 'not_found' );
		}
		if ( (int) $row['top'] > Severity::WARNING->priority() ) {
			throw new \DomainException( 'not_an_issue' );
		}
		return [
			'fingerprint' => $fingerprint,
			'event_id'    => null,
			'through_id'  => (int) $row['through_id'],
		];
	}
}
