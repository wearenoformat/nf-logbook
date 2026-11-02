<?php
/**
 * Event tests.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests;

use Noformat\Logger\Category;
use Noformat\Logger\Event;
use Noformat\Logger\Severity;
use Noformat\Logger\Storage\EventRepository;
use Noformat\Logger\Storage\HashChain;

final class EventTest extends TestCase {

	private function event(): Event {
		return Event::make( Category::CONTENT, 'post.updated', Severity::NOTICE )
			->object( 'post', 12, 'page' )
			->actor( 'user', 3, 'administrator' )
			->summary( "Post   #12\nupdated" )
			->change( 'post_status', 'draft', 'publish' )
			->context( [ 'revision_id' => 99 ] )
			->fingerprint( 'post.updated', 12 )
			->occurredAtMs( 1793196191482 )
			->withRequest(
				[
					'id'      => str_repeat( 'a', 32 ),
					'source'  => 'admin',
					'route'   => '/wp-admin/post.php',
					'method'  => 'POST',
					'ip_hash' => str_repeat( '9', 32 ),
					'ua_hash' => null,
				]
			)
			->withSite( 1, 'https://example.com' );
	}

	public function test_to_array_has_exact_contract_shape(): void {
		$array = $this->event()->toArray();
		self::assertSame(
			[ 'id', 'occurred_at', 'severity', 'category', 'action', 'summary', 'object', 'actor', 'request', 'changes', 'context', 'fingerprint', 'count', 'site', 'producer', 'hash', 'prev_hash' ],
			array_keys( $array )
		);
		self::assertSame( '2026-10-28T14:03:11.482Z', $array['occurred_at'] );
		self::assertSame( 'notice', $array['severity'] );
		self::assertSame( 'content', $array['category'] );
		self::assertSame( 'Post #12 updated', $array['summary'] );
		self::assertSame( [ 'type' => 'post', 'id' => '12', 'subtype' => 'page', 'label_hash' => null ], $array['object'] );
		self::assertSame( [ 'type' => 'user', 'id' => '3', 'role' => 'administrator' ], $array['actor'] );
		self::assertSame( [ 'id', 'source', 'route', 'method', 'ip_hash', 'ua_hash' ], array_keys( $array['request'] ) );
		self::assertSame( [ [ 'field' => 'post_status', 'old' => 'draft', 'new' => 'publish' ] ], $array['changes'] );
		self::assertSame( 1, $array['count'] );
		self::assertSame( [ 'id' => '1', 'url' => 'https://example.com' ], $array['site'] );
		self::assertSame( [ 'name' => 'wp-noformat-logger', 'version' => '0.1.0', 'schema' => 1 ], $array['producer'] );
		self::assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', (string) $array['fingerprint'] );
		self::assertNull( $array['id'] );
	}

	public function test_default_actor_is_system(): void {
		$array = Event::make( Category::SYSTEM, 'x' )->toArray();
		self::assertSame( [ 'type' => 'system', 'id' => null, 'role' => null ], $array['actor'] );
		self::assertNull( $array['object'] );
	}

	public function test_severity_priorities_match_contract(): void {
		$expected = [ 'critical' => 0, 'error' => 1, 'warning' => 2, 'notice' => 3, 'info' => 4, 'debug' => 5 ];
		foreach ( Severity::cases() as $case ) {
			self::assertSame( $expected[ $case->value ], $case->priority() );
			self::assertSame( $case, Severity::fromPriority( $case->priority() ) );
		}
		self::assertSame( Severity::WARNING, Severity::parse( 'WARNING' ) );
		self::assertSame( Severity::ERROR, Severity::parse( '1' ) );
		self::assertNull( Severity::parse( 'loud' ) );
	}

	public function test_limits_are_applied(): void {
		$event = Event::make( Category::SYSTEM, str_repeat( 'a', 100 ) )->summary( str_repeat( 'é', 300 ) )->object( 't', str_repeat( '1', 100 ) );
		self::assertSame( 64, strlen( $event->getAction() ) );
		self::assertSame( 255, mb_strlen( $event->getSummary() ) );
		self::assertSame( 64, strlen( (string) $event->getObject()['id'] ) );
	}

	public function test_storage_round_trip_preserves_hash(): void {
		$event = $this->event();
		EventRepository::normalize( $event );
		$hash = HashChain::hash( HashChain::GENESIS, $event->toArray() );
		$event->withChain( 7, $hash, HashChain::GENESIS );

		$row       = EventRepository::eventToRow( $event );
		$row['id'] = 7;
		// Simulate the database returning strings.
		$row      = array_map( static fn( $v ) => null === $v ? null : (string) $v, $row );
		$restored = EventRepository::rowToArray( $row, 'https://example.com' );

		self::assertSame( $event->toArray(), $restored );
		self::assertSame( $hash, HashChain::hash( HashChain::GENESIS, $restored ) );
	}

	public function test_time_helpers(): void {
		self::assertSame( 1793196191482, Event::parseMs( '2026-10-28 14:03:11.482' ) );
		self::assertSame( 1793196191000, Event::parseMs( '2026-10-28T14:03:11Z' ) );
		self::assertNull( Event::parseMs( 'yesterday' ) );
		self::assertSame( '2026-10-28 14:03:11.005', Event::formatMs( 1793196191005, 'Y-m-d H:i:s' ) );
	}
}
