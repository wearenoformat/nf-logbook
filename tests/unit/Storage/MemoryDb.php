<?php
/**
 * In-memory emulation of the plugin's own SQL (only the statements the storage
 * classes issue), used through FakeWpdb::$handler to test flush budgets and
 * aggregation end to end without a database.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

// phpcs:ignoreFile

namespace Noformat\Logger\Tests\Storage;

final class MemoryDb {

	/** @var array<string, array{value: string, updated_at: int}> */
	public array $meta = [];

	/** @var array<string, array{fingerprint: string, last_event_id: int, window_start: int, count: int, last_seen: int}> */
	public array $fingerprints = [];

	/** @var array<int, array<string, mixed>> */
	public array $events = [];

	/** @var array<string, array{issue_key: string, fingerprint: ?string, event_id: ?int, through_id: int, resolved_at: int, resolved_by: ?int}> */
	public array $resolutions = [];

	private int $nextId = 1;

	/** Statements that touched nflog tables (or transaction control). */
	public array $log = [];

	public function query( string $sql ): int {
		$this->log[] = $sql;
		if ( preg_match( '/^(START TRANSACTION|COMMIT|ROLLBACK)$/', $sql ) ) {
			return 0;
		}
		if ( preg_match( "/^REPLACE INTO `\\w+nflog_meta` \\(meta_key, meta_value, updated_at\\) VALUES \\((.*)\\)$/s", $sql, $m ) ) {
			[ $k, $v, $t ] = self::values( $m[1] );
			$this->meta[ (string) $k ] = [ 'value' => (string) $v, 'updated_at' => (int) $t ];
			return 1;
		}
		if ( preg_match( "/^UPDATE `\\w+nflog_meta` SET meta_value = meta_value \\+ (-?\\d+), updated_at = (\\d+) WHERE meta_key = '(.*)'$/", $sql, $m ) ) {
			$k = stripslashes( $m[3] );
			if ( ! isset( $this->meta[ $k ] ) ) {
				return 0;
			}
			$this->meta[ $k ] = [ 'value' => (string) ( (int) $this->meta[ $k ]['value'] + (int) $m[1] ), 'updated_at' => (int) $m[2] ];
			return 1;
		}
		if ( preg_match( "/^REPLACE INTO `\\w+nflog_fingerprints` .* VALUES \\((.*)\\)$/s", $sql, $m ) ) {
			[ $fp, $id, $ws, $c, $ls ] = self::values( $m[1] );
			$this->fingerprints[ (string) $fp ] = [ 'fingerprint' => (string) $fp, 'last_event_id' => (int) $id, 'window_start' => (int) $ws, 'count' => (int) $c, 'last_seen' => (int) $ls ];
			return 1;
		}
		if ( preg_match( "/^UPDATE `\\w+nflog_fingerprints` SET `count` = `count` \\+ (\\d+), last_seen = (\\d+) WHERE fingerprint = '(\\w+)'$/", $sql, $m ) ) {
			if ( ! isset( $this->fingerprints[ $m[3] ] ) ) {
				return 0;
			}
			$this->fingerprints[ $m[3] ]['count']    += (int) $m[1];
			$this->fingerprints[ $m[3] ]['last_seen'] = (int) $m[2];
			return 1;
		}
		if ( preg_match( '/^UPDATE `\w+nflog_fingerprints` SET window_start = 0 WHERE last_event_id > 0 AND last_event_id <= (\d+) AND window_start > 0$/', $sql, $m ) ) {
			$n = 0;
			foreach ( $this->fingerprints as $k => $row ) {
				if ( $row['last_event_id'] > 0 && $row['last_event_id'] <= (int) $m[1] && $row['window_start'] > 0 ) {
					$this->fingerprints[ $k ]['window_start'] = 0;
					++$n;
				}
			}
			return $n;
		}
		if ( preg_match( '/^DELETE FROM `\w+nflog_fingerprints` WHERE last_seen < (\d+)$/', $sql, $m ) ) {
			$n = 0;
			foreach ( $this->fingerprints as $k => $row ) {
				if ( $row['last_seen'] < (int) $m[1] ) {
					unset( $this->fingerprints[ $k ] );
					++$n;
				}
			}
			return $n;
		}
		if ( preg_match( '/^UPDATE `\w+nflog_events` SET `count` = (\d+) WHERE id = (\d+) AND `count` < (\d+)$/', $sql, $m ) ) {
			$id = (int) $m[2];
			if ( isset( $this->events[ $id ] ) && (int) $this->events[ $id ]['count'] < (int) $m[3] ) {
				$this->events[ $id ]['count'] = (int) $m[1];
				return 1;
			}
			return 0;
		}
		if ( preg_match( '/^INSERT INTO `\w+nflog_events` \((.*?)\) VALUES (.*)$/s', $sql, $m ) ) {
			$columns = array_map( static fn( $c ) => trim( $c, '` ' ), explode( ',', $m[1] ) );
			$values  = self::values( $m[2] );
			foreach ( array_chunk( $values, count( $columns ) ) as $tuple ) {
				$row                     = array_combine( $columns, $tuple );
				$row['id']               = $this->nextId++;
				$this->events[ $row['id'] ] = $row;
			}
			return count( $values ) / count( $columns );
		}
		if ( preg_match( '/^DELETE FROM `\w+nflog_events` WHERE id <= (\d+)$/', $sql, $m ) ) {
			$n = 0;
			foreach ( array_keys( $this->events ) as $id ) {
				if ( $id <= (int) $m[1] ) {
					unset( $this->events[ $id ] );
					++$n;
				}
			}
			return $n;
		}
		if ( preg_match( '/^DELETE FROM `\w+nflog_fingerprints` WHERE last_event_id <= (\d+)$/', $sql, $m ) ) {
			$n = 0;
			foreach ( $this->fingerprints as $k => $row ) {
				if ( $row['last_event_id'] <= (int) $m[1] ) {
					unset( $this->fingerprints[ $k ] );
					++$n;
				}
			}
			return $n;
		}
		if ( preg_match( "/^REPLACE INTO `\\w+nflog_resolutions` \\(issue_key, fingerprint, event_id, through_id, resolved_at, resolved_by\\) VALUES \\((.*)\\)$/s", $sql, $m ) ) {
			[ $key, $fp, $eid, $through, $at, $by ] = self::values( $m[1] );
			$this->resolutions[ (string) $key ] = [ 'issue_key' => (string) $key, 'fingerprint' => $fp, 'event_id' => $eid, 'through_id' => (int) $through, 'resolved_at' => (int) $at, 'resolved_by' => $by ];
			return 1;
		}
		if ( preg_match( "/^DELETE FROM `\\w+nflog_resolutions` WHERE issue_key = '(.*)'$/", $sql, $m ) ) {
			$k = stripslashes( $m[1] );
			if ( ! isset( $this->resolutions[ $k ] ) ) {
				return 0;
			}
			unset( $this->resolutions[ $k ] );
			return 1;
		}
		if ( preg_match( '/^DELETE FROM `\w+nflog_resolutions` WHERE through_id <= (\d+)$/', $sql, $m ) ) {
			$n = 0;
			foreach ( $this->resolutions as $k => $row ) {
				if ( $row['through_id'] <= (int) $m[1] ) {
					unset( $this->resolutions[ $k ] );
					++$n;
				}
			}
			return $n;
		}
		if ( preg_match( "/^UPDATE `\\w+nflog_fingerprints` SET window_start = 0 WHERE fingerprint = '(\\w+)' AND window_start > 0$/", $sql, $m ) ) {
			if ( ! isset( $this->fingerprints[ $m[1] ] ) || $this->fingerprints[ $m[1] ]['window_start'] <= 0 ) {
				return 0;
			}
			$this->fingerprints[ $m[1] ]['window_start'] = 0;
			return 1;
		}
		throw new \LogicException( 'MemoryDb: unhandled query ' . $sql );
	}

	public function getVar( string $sql ): mixed {
		$this->log[] = $sql;
		if ( preg_match( "/^SELECT meta_value FROM `\\w+nflog_meta` WHERE meta_key = '(.*?)'( FOR UPDATE)?$/", $sql, $m ) ) {
			return $this->meta[ stripslashes( $m[1] ) ]['value'] ?? null;
		}
		$ids = array_keys( $this->events );
		if ( preg_match( '/^SELECT MAX\(id\) FROM `\w+nflog_events` WHERE occurred_ms < (\d+)$/', $sql, $m ) ) {
			$hit = array_filter( $this->events, static fn( $r ) => (int) $r['occurred_ms'] < (int) $m[1] );
			return [] === $hit ? null : (string) max( array_keys( $hit ) );
		}
		if ( preg_match( '/^SELECT id FROM `\w+nflog_events` ORDER BY id ASC LIMIT 1 OFFSET (\d+)$/', $sql, $m ) ) {
			sort( $ids );
			return isset( $ids[ (int) $m[1] ] ) ? (string) $ids[ (int) $m[1] ] : null;
		}
		if ( preg_match( '/^SELECT MIN\(id\) FROM `\w+nflog_events` WHERE id > (\d+) AND severity <= (\d+)$/', $sql, $m ) ) {
			$hit = array_filter( $this->events, static fn( $r ) => $r['id'] > (int) $m[1] && (int) $r['severity'] <= (int) $m[2] );
			return [] === $hit ? null : (string) min( array_keys( $hit ) );
		}
		if ( preg_match( '/^SELECT COUNT\(\*\) FROM `\w+nflog_events` WHERE id <= (\d+)$/', $sql, $m ) ) {
			return (string) count( array_filter( $ids, static fn( $id ) => $id <= (int) $m[1] ) );
		}
		if ( preg_match( '/^SELECT `hash` FROM `\w+nflog_events` WHERE id <= (\d+) ORDER BY id DESC LIMIT 1$/', $sql, $m ) ) {
			$hit = array_filter( $ids, static fn( $id ) => $id <= (int) $m[1] );
			return [] === $hit ? null : $this->events[ max( $hit ) ]['hash'];
		}
		throw new \LogicException( 'MemoryDb: unhandled get_var ' . $sql );
	}

	public function getRow( string $sql ): ?array {
		$this->log[] = $sql;
		if ( preg_match( "/^SELECT .* FROM `\\w+nflog_fingerprints` WHERE fingerprint = '(\\w+)'$/", $sql, $m ) ) {
			return $this->fingerprints[ $m[1] ] ?? null;
		}
		if ( preg_match( '/^SELECT COUNT\(\*\) AS c, MIN\(id\) AS min_id, MAX\(id\) AS max_id FROM `\w+nflog_events`$/', $sql ) ) {
			$ids = array_keys( $this->events );
			return [ 'c' => count( $ids ), 'min_id' => [] === $ids ? null : min( $ids ), 'max_id' => [] === $ids ? null : max( $ids ) ];
		}
		if ( preg_match( '/^SELECT \* FROM `\w+nflog_events` WHERE id = (\d+)$/', $sql, $m ) ) {
			return $this->events[ (int) $m[1] ] ?? null;
		}
		if ( preg_match( '/^SELECT id, (?:severity, )?fingerprint FROM `\w+nflog_events` WHERE id = (\d+)$/', $sql, $m ) ) {
			return $this->events[ (int) $m[1] ] ?? null;
		}
		if ( preg_match( "/^SELECT MAX\\(id\\) AS through_id, MIN\\(severity\\) AS top FROM `\\w+nflog_events` WHERE fingerprint = '(\\w+)'$/", $sql, $m ) ) {
			$hit = array_filter( $this->events, static fn( $r ) => ( $r['fingerprint'] ?? null ) === $m[1] );
			return [ 'through_id' => [] === $hit ? null : max( array_keys( $hit ) ), 'top' => [] === $hit ? null : min( array_map( static fn( $r ) => (int) $r['severity'], $hit ) ) ];
		}
		if ( preg_match( "/^SELECT \\* FROM `\\w+nflog_resolutions` WHERE issue_key = '(.*)'$/", $sql, $m ) ) {
			return $this->resolutions[ stripslashes( $m[1] ) ] ?? null;
		}
		throw new \LogicException( 'MemoryDb: unhandled get_row ' . $sql );
	}

	public function getResults( string $sql ): array {
		$this->log[] = $sql;
		if ( preg_match( "/^SELECT id, `hash` FROM `\\w+nflog_events` WHERE id > (\\d+) AND `hash` IN \\((.*)\\)$/", $sql, $m ) ) {
			$hashes = self::values( $m[2] );
			$out    = [];
			foreach ( $this->events as $row ) {
				if ( $row['id'] > (int) $m[1] && in_array( $row['hash'], $hashes, true ) ) {
					$out[] = [ 'id' => $row['id'], 'hash' => $row['hash'] ];
				}
			}
			return $out;
		}
		if ( preg_match( '/^SELECT last_event_id, `count` FROM `\w+nflog_fingerprints` WHERE last_event_id IN \(([\d,]+)\)$/', $sql, $m ) ) {
			$ids = array_map( 'intval', explode( ',', $m[1] ) );
			return array_values( array_filter( $this->fingerprints, static fn( $r ) => in_array( $r['last_event_id'], $ids, true ) ) );
		}
		if ( preg_match( '/^SELECT last_event_id, `count` FROM `\w+nflog_fingerprints` WHERE last_seen < (\d+) ORDER BY last_seen ASC LIMIT \d+$/', $sql, $m ) ) {
			return array_values( array_filter( $this->fingerprints, static fn( $r ) => $r['last_seen'] < (int) $m[1] ) );
		}
		if ( preg_match( '/^SELECT \* FROM `\w+nflog_events` WHERE id > (\d+) ORDER BY id ASC LIMIT (\d+)$/', $sql, $m ) ) {
			$rows = array_values( array_filter( $this->events, static fn( $r ) => $r['id'] > (int) $m[1] ) );
			return array_slice( $rows, 0, (int) $m[2] );
		}
		if ( preg_match( '/^SELECT \* FROM `\w+nflog_resolutions` WHERE issue_key IN \((.*)\)$/', $sql, $m ) ) {
			$keys = self::values( $m[1] );
			return array_values( array_filter( $this->resolutions, static fn( $r ) => in_array( $r['issue_key'], $keys, true ) ) );
		}
		throw new \LogicException( 'MemoryDb: unhandled get_results ' . $sql );
	}

	/** Statements other than transaction control that touched nflog tables. */
	public function nflogQueries(): array {
		return array_values( array_filter( $this->log, static fn( $q ) => str_contains( $q, 'nflog_' ) ) );
	}

	/** Rows of an action. */
	public function eventsOf( string $action ): array {
		return array_values( array_filter( $this->events, static fn( $r ) => $r['action'] === $action ) );
	}

	/**
	 * Parse a comma separated SQL value list ('str' with backslash escapes, NULL, numbers), tuples flattened.
	 *
	 * @return list<mixed>
	 */
	private static function values( string $list ): array {
		$out = [];
		$len = strlen( $list );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $list[ $i ];
			if ( "'" === $c ) {
				$buf = '';
				for ( $i++; $i < $len; $i++ ) {
					if ( '\\' === $list[ $i ] ) {
						$buf .= $list[ ++$i ];
						continue;
					}
					if ( "'" === $list[ $i ] ) {
						break;
					}
					$buf .= $list[ $i ];
				}
				$out[] = $buf;
			} elseif ( preg_match( '/\G(NULL|-?\d+)/A', $list, $m, 0, $i ) ) {
				$out[] = 'NULL' === $m[1] ? null : (int) $m[1];
				$i    += strlen( $m[1] ) - 1;
			}
		}
		return $out;
	}
}
