<?php
/**
 * Content log stories (grouping by request, primary selection, suffix).
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Admin;

use Brain\Monkey\Functions;
use Noformat\Logger\Admin\Stories;
use Noformat\Logger\Severity;
use Noformat\Logger\Tests\TestCase;

final class StoriesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\when( 'number_format_i18n' )->alias( static fn( $n, $d = 0 ) => number_format( (float) $n, (int) $d ) );
	}

	/**
	 * Row fixture.
	 *
	 * @param int                  $id     Id.
	 * @param string               $action Action.
	 * @param array<string, mixed> $fields Overrides.
	 * @return array<string, mixed>
	 */
	private static function row( int $id, string $action, array $fields = [] ): array {
		return array_merge(
			[
				'id'          => (string) $id,
				'action'      => $action,
				'severity'    => (string) Severity::INFO->priority(),
				'request_id'  => str_repeat( 'a', 32 ),
				'occurred_ms' => (string) ( 1_700_000_000_000 + $id ),
				'object_type' => 'post',
				'object_id'   => '12',
				'actor_type'  => 'user',
				'actor_id'    => '7',
				'context'     => '[]',
				'changes'     => '[]',
			],
			$fields
		);
	}

	public function test_primary_outranks_side_effects_regardless_of_order_and_severity(): void {
		$rows = [
			self::row( 1, 'meta.updated', [ 'severity' => (string) Severity::NOTICE->priority() ] ),
			self::row( 2, 'term.assigned' ),
			self::row( 3, 'post.updated' ),
			self::row( 4, 'acf.fields_saved' ),
		];
		self::assertSame( 2, Stories::primaryIndex( $rows ) );
		$story = Stories::build( $rows );
		self::assertSame( 'post.updated', $story['primary']['action'] );
		self::assertSame( [ '1', '2', '4' ], array_column( $story['secondary'], 'id' ) );
		self::assertSame( 4, $story['events'] );
	}

	public function test_equal_rank_most_severe_then_earliest(): void {
		$rows = [
			self::row( 5, 'post.updated' ),
			self::row( 6, 'post.published', [ 'severity' => (string) Severity::NOTICE->priority() ] ),
			self::row( 7, 'post.updated', [ 'severity' => (string) Severity::NOTICE->priority() ] ),
		];
		self::assertSame( 1, Stories::primaryIndex( $rows ) );
		$only = [ self::row( 9, 'meta.updated' ), self::row( 8, 'meta.added' ) ];
		self::assertSame( 1, Stories::primaryIndex( $only ), 'only secondaries: earliest id' );
		self::assertTrue( Stories::isSecondary( 'media.metadata_updated' ) );
		self::assertFalse( Stories::isSecondary( 'media.uploaded' ) );
		self::assertFalse( Stories::isSecondary( 'menu.item_updated' ) );
	}

	public function test_group_by_request_newest_first_and_rows_without_request_alone(): void {
		$b      = str_repeat( 'b', 32 );
		$rows   = [
			self::row( 1, 'post.updated' ),
			self::row( 2, 'meta.updated', [ 'context' => '{"meta_key":"a"}' ] ),
			self::row( 3, 'meta.updated', [ 'request_id' => '' ] ),
			self::row( 4, 'meta.updated', [ 'request_id' => '' ] ),
			self::row( 5, 'user.updated', [ 'request_id' => $b ] ),
		];
		$groups = Stories::group( array_reverse( $rows ) );
		self::assertSame( [ '5', '4', '3', '1' ], array_map( static fn( array $s ): string => $s['primary']['id'], $groups ) );
		self::assertSame( [], $groups[1]['secondary'], 'a lone meta.updated keeps its own entry' );
		self::assertSame( 'and 1 field changed', Stories::suffix( $groups[3] ) );
		self::assertSame( '', Stories::suffix( $groups[0] ) );
	}

	public function test_suffix_counts_fields_terms_files_and_capped_rows(): void {
		$rows  = [
			self::row( 1, 'post.updated' ),
			self::row( 2, 'meta.updated', [ 'context' => '{"meta_key":"_yoast_wpseo_title"}' ] ),
			self::row( 3, 'meta.updated', [ 'context' => '{"meta_key":"_yoast_wpseo_metadesc"}' ] ),
			self::row( 4, 'acf.fields_saved', [ 'context' => '{"fields_changed":3}' ] ),
			self::row( 5, 'term.assigned', [ 'object_subtype' => 'category', 'context' => '{"added":[3],"removed":[]}' ] ),
			self::row( 6, 'media.metadata_updated' ),
			self::row( 7, 'post.published' ),
		];
		$story = Stories::build( $rows, 9 );
		self::assertSame( 'post.updated', $story['primary']['action'] );
		self::assertSame( [ '5 fields', '1 category', '1 file', '1 other change', '2 more changes' ], Stories::suffixParts( $story ) );
		self::assertSame( 'and 5 fields, 1 category, 1 file, 1 other change, 2 more changes', Stories::suffix( $story ) );
		self::assertSame( 'and 2 fields changed', Stories::suffix( Stories::build( array_slice( $rows, 0, 3 ) ) ) );
		self::assertSame( 'and 2 fields, 1 category changed', Stories::suffix( Stories::build( [ $rows[0], $rows[1], $rows[2], $rows[4] ] ) ) );
	}

	public function test_profile_meta_is_merged_into_user_updated(): void {
		$user  = [
			'object_type' => 'user',
			'object_id'   => '7',
		];
		$rows  = [
			self::row( 1, 'user.updated', $user + [ 'context' => '{"changed_fields":["user_url"]}', 'severity' => (string) Severity::NOTICE->priority() ] ),
			self::row( 2, 'meta.updated', $user + [ 'context' => '{"meta_key":"description"}' ] ),
			self::row( 3, 'meta.updated', $user + [ 'context' => '{"meta_key":"wpseo_title"}' ] ),
			self::row( 4, 'meta.updated', $user + [ 'context' => '{"meta_key":"wpseo_metadesc"}' ] ),
			self::row( 5, 'meta.updated', [ 'object_type' => 'user', 'object_id' => '8', 'context' => '{"meta_key":"description"}' ] ),
		];
		$story = Stories::build( $rows );
		self::assertSame( [ 'user_url', 'description' ], $story['primary']['context']['changed_fields'] );
		self::assertSame( [ 2 ], $story['absorbed'] );
		self::assertSame( 'and 3 fields changed', Stories::suffix( $story ), 'other user and SEO fields still counted' );
	}

	public function test_anonymous_login_meta_is_attributed_to_the_user_who_logged_in(): void {
		$rows = [
			self::row( 1, 'meta.updated', [ 'actor_type' => 'anonymous', 'actor_id' => null, 'object_type' => 'user', 'object_id' => '7' ] ),
			self::row( 2, 'meta.updated', [ 'actor_type' => 'anonymous', 'actor_id' => null, 'object_type' => 'user', 'object_id' => '9' ] ),
			self::row( 3, 'meta.updated', [ 'actor_type' => 'anonymous', 'actor_id' => null, 'object_type' => 'post' ] ),
		];
		self::assertSame( [ str_repeat( 'a', 32 ) ], Stories::loginCandidates( $rows ) );
		$out = Stories::attributeLogins( $rows, [ str_repeat( 'a', 32 ) => [ 7 ] ] );
		self::assertSame( [ 'user', '7' ], [ $out[0]['actor_type'], $out[0]['actor_id'] ] );
		self::assertSame( 'anonymous', $out[1]['actor_type'], 'a different user is not attributed' );
		self::assertSame( 'anonymous', $out[2]['actor_type'] );
		self::assertSame( [], Stories::loginCandidates( [ self::row( 4, 'meta.updated', [ 'actor_type' => 'anonymous', 'object_type' => 'user', 'request_id' => '' ] ) ] ) );
	}

	public function test_post_updated_of_the_published_post_counts_as_fields(): void {
		$rows  = [
			self::row( 1, 'post.updated', [ 'changes' => '[{"field":"title","old":"a","new":"b"},{"field":"date","old":"1","new":"2"}]' ] ),
			self::row( 2, 'post.published', [ 'severity' => (string) Severity::NOTICE->priority() ] ),
			self::row( 3, 'post.updated', [ 'object_id' => '99' ] ),
		];
		$story = Stories::build( $rows );
		self::assertSame( 'post.published', $story['primary']['action'] );
		self::assertSame( 'and 1 field, 1 other change', Stories::suffix( $story ) );
		self::assertSame( 'and 1 field changed', Stories::suffix( Stories::build( array_slice( $rows, 0, 2 ) ) ) );
	}
}
