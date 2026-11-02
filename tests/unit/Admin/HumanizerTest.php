<?php
/**
 * Content log sentences.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Tests\Admin;

use Brain\Monkey\Functions;
use Noformat\Logger\Admin\Humanizer;
use Noformat\Logger\Admin\Resolver;
use Noformat\Logger\Tests\TestCase;

final class HumanizerTest extends TestCase {

	/** @var list<string> */
	private array $caps = [ 'list_users' ];

	/** @var array<int, object> */
	private array $posts = [];

	protected function setUp(): void {
		parent::setUp();
		Functions\stubEscapeFunctions();
		Functions\stubTranslationFunctions();
		Functions\when( 'wp_kses' )->returnArg();
		Functions\when( 'number_format_i18n' )->alias( static fn( $n, $d = 0 ) => number_format( (float) $n, (int) $d ) );
		Functions\when( 'current_user_can' )->alias( fn( string $cap, mixed ...$args ): bool => in_array( $cap, $this->caps, true ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_post' )->alias( fn( $id ) => $this->posts[ (int) $id ] ?? null );
		Functions\when( 'is_post_type_viewable' )->alias( static fn( string $type ): bool => in_array( $type, [ 'post', 'page', 'attachment' ], true ) );
		Functions\when( 'get_post_type_object' )->alias(
			static fn( string $type ) => in_array( $type, [ 'post', 'page' ], true ) ? (object) [ 'labels' => (object) [ 'singular_name' => ucfirst( $type ) ] ] : null
		);
		Functions\when( 'get_edit_post_link' )->alias( static fn( int $id ): string => 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit' );
		Functions\when( 'get_userdata' )->alias( static fn( int $id ): object|false => 7 === $id ? (object) [ 'display_name' => 'Demo Editor' ] : false );
		Functions\when( 'get_edit_user_link' )->alias( static fn( int $id ): string => 'https://example.test/wp-admin/user-edit.php?user_id=' . $id );
		Functions\when( 'get_term' )->alias( static fn( int $id ): ?object => 9 === $id ? (object) [ 'name' => 'Main' ] : null );
		$this->posts = [
			5    => (object) [ 'ID' => 5, 'post_title' => 'Demo landing page', 'post_type' => 'page', 'post_status' => 'publish' ],
			1714 => (object) [ 'ID' => 1714, 'post_title' => 'Logger demo post', 'post_type' => 'post', 'post_status' => 'publish' ],
			1719 => (object) [ 'ID' => 1719, 'post_title' => 'Demo link', 'post_type' => 'nav_menu_item', 'post_status' => 'publish' ],
			40   => (object) [ 'ID' => 40, 'post_title' => 'jane@example.org', 'post_type' => 'flamingo_contact', 'post_status' => 'publish' ],
		];
	}

	/**
	 * Row fixture.
	 *
	 * @param array<string, mixed> $fields Fields.
	 * @return array<string, mixed>
	 */
	private static function row( array $fields ): array {
		return array_merge(
			[
				'action'         => 'post.updated',
				'summary'        => 'Summary',
				'object_type'    => 'post',
				'object_id'      => '1714',
				'object_subtype' => 'post',
				'actor_type'     => 'user',
				'actor_id'       => '7',
				'changes'        => '[]',
				'context'        => '[]',
			],
			$fields
		);
	}

	private function say( array $fields ): string {
		return strip_tags( ( new Humanizer( new Resolver() ) )->sentence( self::row( $fields ) ) );
	}

	public function test_post_published_links_the_title_when_editable(): void {
		$this->caps[] = 'edit_post';
		$html         = ( new Humanizer( new Resolver() ) )->sentence(
			self::row(
				[
					'action'         => 'post.published',
					'object_id'      => '5',
					'object_subtype' => 'page',
					'changes'        => '[{"field":"status","old":"draft","new":"publish"}]',
				]
			)
		);
		self::assertSame( 'Demo Editor published the page Demo landing page', strip_tags( $html ) );
		self::assertStringContainsString( '<strong class="nflog-actor">Demo Editor</strong>', $html );
		self::assertStringContainsString( 'post.php?post=5&action=edit"><em>Demo landing page</em></a>', $html );
	}

	public function test_post_updated_names_the_fields(): void {
		$changes = '[{"field":"title","old":"Logger demo","new":"Logger demo post"},{"field":"slug","old":"","new":"logger-demo-post"},{"field":"date","old":"a","new":"b"}]';
		self::assertSame( 'Demo Editor updated the title and slug of the post Logger demo post', $this->say( [ 'changes' => $changes ] ) );
		self::assertSame( 'Demo Editor edited the content of the post Logger demo post', $this->say( [ 'changes' => '[{"field":"content","old":{"sha256_8":"a","length":1},"new":{"sha256_8":"b","length":2}}]' ] ) );
		$html = ( new Humanizer( new Resolver() ) )->sentence( self::row( [ 'changes' => $changes ] ) );
		self::assertStringNotContainsString( '<a ', $html, 'no edit link without edit_post' );
	}

	public function test_post_trashed_and_created(): void {
		self::assertSame( 'Demo Editor moved the post Logger demo post to the trash', $this->say( [ 'action' => 'post.trashed' ] ) );
		self::assertSame( 'Demo Editor started a draft of the post Logger demo post', $this->say( [ 'action' => 'post.created', 'context' => '{"status":"draft"}' ] ) );
	}

	public function test_user_role_changed_by_wp_cli(): void {
		self::assertSame(
			'WP-CLI changed the role of Demo Editor from editor to administrator',
			$this->say(
				[
					'action'         => 'user.role_changed',
					'object_type'    => 'user',
					'object_id'      => '7',
					'object_subtype' => 'administrator',
					'actor_type'     => 'cli',
					'actor_id'       => null,
					'changes'        => '[{"field":"role","old":"editor","new":"administrator"}]',
				]
			)
		);
	}

	public function test_option_updated_uses_the_setting_label(): void {
		$option = [
			'action'      => 'option.updated',
			'object_type' => 'option',
			'object_id'   => 'blogdescription',
		];
		self::assertSame( 'Demo Editor changed the setting Tagline', $this->say( $option ) );
		self::assertSame( 'Demo Editor changed the option gf_dismissed_upgrades', $this->say( array_merge( $option, [ 'object_id' => 'gf_dismissed_upgrades' ] ) ) );
	}

	public function test_media_uploaded_with_type_and_size(): void {
		self::assertSame(
			'Demo Editor uploaded an image (PNG, 70 bytes)',
			$this->say(
				[
					'action'         => 'media.uploaded',
					'object_type'    => 'attachment',
					'object_id'      => '1718',
					'object_subtype' => 'image/png',
					'context'        => '{"mime":"image/png","filesize":70}',
				]
			)
		);
		self::assertSame( '1.4 MB', Humanizer::bytes( 1468006 ) );
	}

	public function test_menu_item_added_to_a_menu(): void {
		self::assertSame(
			'Demo Editor added the menu item Demo link to the menu Main',
			$this->say(
				[
					'action'         => 'menu.item_updated',
					'object_type'    => 'menu_item',
					'object_id'      => '1719',
					'object_subtype' => 'custom',
					'context'        => '{"menu_id":9,"created":true}',
				]
			)
		);
		self::assertSame(
			'Demo Editor removed a menu item from the menu #12',
			$this->say(
				[
					'action'      => 'menu.item_deleted',
					'object_type' => 'menu_item',
					'object_id'   => '999',
					'context'     => '{"menu_id":12}',
				]
			)
		);
	}

	public function test_unknown_action_falls_back_to_the_escaped_summary(): void {
		$html = ( new Humanizer( new Resolver() ) )->sentence( self::row( [ 'action' => 'commerce.order_paid', 'summary' => 'Order <b>#12</b> paid' ] ) );
		self::assertSame( 'Order &lt;b&gt;#12&lt;/b&gt; paid', $html );
	}

	public function test_hashed_or_private_titles_render_as_a_private_item(): void {
		$redacted = '[{"field":"title","old":{"redacted":true,"sha256_8":"aa"},"new":{"redacted":true,"sha256_8":"bb"}}]';
		self::assertSame( 'Demo Editor updated the title of a private item', $this->say( [ 'object_id' => '41', 'object_subtype' => 'flamingo_contact', 'changes' => $redacted ] ) );
		self::assertSame( 'Demo Editor published a private item', $this->say( [ 'action' => 'post.published', 'object_id' => '40', 'object_subtype' => 'flamingo_contact' ] ) );
		self::assertStringNotContainsString( 'jane@', $this->say( [ 'action' => 'post.published', 'object_id' => '40' ] ) );
	}

	public function test_deleted_post_and_names_without_list_users(): void {
		$this->caps = [];
		self::assertSame( 'User #7 permanently deleted the post #1800', $this->say( [ 'action' => 'post.deleted', 'object_id' => '1800' ] ) );
	}

	public function test_sentence_output_is_escaped(): void {
		$this->posts[5] = (object) [ 'ID' => 5, 'post_title' => '<script>alert(1)</script>', 'post_type' => 'page', 'post_status' => 'publish' ];
		$html           = ( new Humanizer( new Resolver() ) )->sentence( self::row( [ 'action' => 'post.published', 'object_id' => '5', 'object_subtype' => 'page' ] ) );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_field_labels(): void {
		self::assertSame( 'Slug', Humanizer::label( 'post_name' ) );
		self::assertSame( 'Tagline', Humanizer::label( 'blogdescription' ) );
		self::assertSame( 'wpseo › Dismiss configuration notice', Humanizer::label( 'wpseo.dismiss_configuration_notice' ) );
		self::assertSame( 'Demo subtitle', Humanizer::label( 'demo_subtitle' ) );
	}

	public function test_values_and_markers(): void {
		self::assertStringContainsString( 'hidden for privacy', Humanizer::value( [ 'redacted' => true, 'sha256_8' => 'ab' ] ) );
		self::assertStringContainsString( 'shortened', Humanizer::value( [ 'truncated' => true, 'length' => 900, 'sha256_8' => 'ab' ] ) );
		self::assertSame( 'Yes', Humanizer::value( true ) );
		self::assertSame( '26 → 53 characters (+27)', Humanizer::contentLine( [ 'field' => 'content', 'old' => [ 'sha256_8' => 'a', 'length' => 26 ], 'new' => [ 'sha256_8' => 'b', 'length' => 53, 'length_delta' => 27, 'changed' => true ] ] ) );
		self::assertNull( Humanizer::contentLine( [ 'field' => 'title', 'old' => 'a', 'new' => 'b' ] ) );
	}

	public function test_terms_assigned_names_the_post_not_the_taxonomy(): void {
		self::assertSame(
			'Demo Editor changed the categories of the post Logger demo post (+1 / −0)',
			$this->say(
				[
					'action'         => 'term.assigned',
					'object_subtype' => 'category',
					'context'        => '{"added":[1],"removed":[],"post_type":"post"}',
				]
			)
		);
	}

	public function test_profile_update_lists_humanised_fields(): void {
		$user = [
			'action'      => 'user.updated',
			'object_type' => 'user',
			'object_id'   => '7',
		];
		self::assertSame( 'Demo Editor updated their profile: website, bio', $this->say( $user + [ 'context' => '{"changed_fields":["user_url","description"]}' ] ) );
		self::assertSame(
			'Demo Editor updated their profile: display name, email address, password, first name, last name, language, preferences',
			$this->say( $user + [ 'context' => '{"changed_fields":["display_name","user_email","user_pass","first_name","last_name","locale","rich_editing","admin_color","show_admin_bar_front"]}' ] )
		);
		self::assertSame( 'WP-CLI updated the profile of Demo Editor: nickname', $this->say( $user + [ 'actor_type' => 'cli', 'actor_id' => null, 'context' => '{"changed_fields":["nickname"]}' ] ) );
		self::assertSame( 'Demo Editor updated their profile', $this->say( $user ) );
		self::assertSame( [ 'website', 'preferences' ], Humanizer::profileFields( [ 'user_url', 'comment_shortcuts', 'admin_color' ] ) );
	}
}
