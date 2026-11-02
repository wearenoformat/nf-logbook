<?php
/**
 * Render-time labels for objects and actors.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves stored IDs to names for display in wp-admin only.
 *
 * Nothing here is written anywhere: the log keeps IDs and hashes; names are
 * looked up from the site's own tables while the page renders, and only when
 * the current user may see them (posts: edit link with edit_post; users:
 * display name with list_users). Every method returns escaped HTML.
 */
final class Resolver {

	/** Object types whose id is a post id. */
	private const POST_TYPES = [ 'post', 'attachment', 'menu_item' ];

	/** Object types whose id is a term id. */
	private const TERM_TYPES = [ 'term', 'menu' ];

	/** Actor types shown as words. */
	private const ACTOR_WORDS = [ 'system', 'cron', 'cli', 'anonymous', 'app' ];

	private ?bool $listUsers = null;

	/**
	 * Warm the post and user caches for a page of rows (two queries at most).
	 *
	 * @param list<array<string, mixed>> $rows Event rows.
	 */
	public function prime( array $rows ): void {
		$posts = [];
		$users = [];
		foreach ( $rows as $row ) {
			$id = self::numericId( $row['object_id'] ?? null );
			if ( null !== $id && in_array( (string) ( $row['object_type'] ?? '' ), self::POST_TYPES, true ) ) {
				$posts[] = $id;
			}
			if ( null !== $id && 'user' === ( $row['object_type'] ?? '' ) ) {
				$users[] = $id;
			}
			if ( 'user' === ( $row['actor_type'] ?? '' ) && null !== self::numericId( $row['actor_id'] ?? null ) ) {
				$users[] = (int) $row['actor_id'];
			}
		}
		if ( [] !== $posts && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_values( array_unique( $posts ) ), false, false );
		}
		if ( [] !== $users && $this->canListUsers() && function_exists( 'cache_users' ) ) {
			cache_users( array_values( array_unique( $users ) ) );
		}
	}

	/**
	 * Object cell: type / subtype and a label.
	 *
	 * @param array<string, mixed> $row Event row.
	 */
	public function object( array $row ): string {
		$type = isset( $row['object_type'] ) ? (string) $row['object_type'] : '';
		if ( '' === $type ) {
			return '<span class="nflog-muted">—</span>';
		}
		$subtype = isset( $row['object_subtype'] ) && '' !== (string) $row['object_subtype'] ? (string) $row['object_subtype'] : '';
		$rawId   = isset( $row['object_id'] ) ? (string) $row['object_id'] : '';
		$label   = $this->objectLabel( $type, $rawId );
		$meta    = esc_html( $type . ( '' === $subtype ? '' : ' (' . $subtype . ')' ) );
		return '<span class="nflog-object-type">' . $meta . '</span><br>' . $label;
	}

	/**
	 * Actor cell.
	 *
	 * @param array<string, mixed> $row Event row.
	 */
	public function actor( array $row ): string {
		$type = isset( $row['actor_type'] ) ? (string) $row['actor_type'] : 'system';
		$id   = self::numericId( $row['actor_id'] ?? null );
		$role = isset( $row['actor_role'] ) && '' !== (string) $row['actor_role'] ? (string) $row['actor_role'] : '';
		if ( 'user' === $type && null !== $id ) {
			$label = $this->userLabel( $id );
			return $label . ( '' === $role ? '' : '<br><span class="nflog-muted">' . esc_html( $role ) . '</span>' );
		}
		if ( in_array( $type, self::ACTOR_WORDS, true ) ) {
			return '<span class="nflog-actor-word">' . esc_html( $type ) . '</span>' . ( null === $id ? '' : ' #' . esc_html( (string) $id ) );
		}
		return esc_html( $type ) . ( null === $id ? '' : ' #' . esc_html( (string) $id ) );
	}

	/**
	 * Label for an object id.
	 *
	 * @param string $type  Object type.
	 * @param string $rawId Stored id.
	 */
	private function objectLabel( string $type, string $rawId ): string {
		if ( '' === $rawId ) {
			return '<span class="nflog-muted">—</span>';
		}
		$id = self::numericId( $rawId );
		if ( null !== $id && in_array( $type, self::POST_TYPES, true ) ) {
			return $this->postLabel( $id );
		}
		if ( null !== $id && 'user' === $type ) {
			return $this->userLabel( $id );
		}
		if ( null !== $id && in_array( $type, self::TERM_TYPES, true ) ) {
			$term = get_term( $id );
			if ( is_object( $term ) && ! is_wp_error( $term ) ) {
				return esc_html( (string) $term->name ) . ' <span class="nflog-muted">#' . esc_html( (string) $id ) . '</span>';
			}
		}
		if ( null === $id ) {
			// Slugs, option names, hooks, routes: stored as is (already free of personal data).
			return '<code>' . esc_html( $rawId ) . '</code>';
		}
		return '#' . esc_html( (string) $id );
	}

	/**
	 * Post title with an edit link when the post exists and the user may edit it.
	 *
	 * @param int $id Post id.
	 */
	private function postLabel( int $id ): string {
		$post = get_post( $id );
		if ( ! is_object( $post ) ) {
			return '#' . esc_html( (string) $id ) . ' <span class="nflog-muted">(' . esc_html__( 'deleted', 'noformat-logger' ) . ')</span>';
		}
		$title = '' === trim( (string) $post->post_title ) ? __( '(no title)', 'noformat-logger' ) : (string) $post->post_title;
		$text  = esc_html( $title ) . ' <span class="nflog-muted">#' . esc_html( (string) $id ) . '</span>';
		if ( current_user_can( 'edit_post', $id ) ) {
			$link = get_edit_post_link( $id, 'raw' );
			if ( is_string( $link ) && '' !== $link ) {
				return '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a> <span class="nflog-muted">#' . esc_html( (string) $id ) . '</span>';
			}
		}
		return $text;
	}

	/**
	 * User display name with a profile link when the current user may list users.
	 *
	 * @param int $id User id.
	 */
	private function userLabel( int $id ): string {
		if ( ! $this->canListUsers() ) {
			return esc_html( sprintf( /* translators: %d: user id */ __( 'user #%d', 'noformat-logger' ), $id ) );
		}
		$user = get_userdata( $id );
		if ( ! is_object( $user ) || ! isset( $user->display_name ) ) {
			return '#' . esc_html( (string) $id ) . ' <span class="nflog-muted">(' . esc_html__( 'deleted', 'noformat-logger' ) . ')</span>';
		}
		$name = (string) $user->display_name;
		$link = get_edit_user_link( $id );
		$html = '' !== $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name );
		return $html . ' <span class="nflog-muted">#' . esc_html( (string) $id ) . '</span>';
	}

	/**
	 * Actor as plain data for sentences and the console (nothing escaped).
	 *
	 * Kinds: user (resolved), user_hidden (no list_users), user_deleted, cli,
	 * cron, system, anonymous, app.
	 *
	 * @param array<string, mixed> $row Event row.
	 * @return array{kind: string, name: string, url: string, user_id: int|null}
	 */
	public function actorInfo( array $row ): array {
		$type = isset( $row['actor_type'] ) ? (string) $row['actor_type'] : 'system';
		$id   = self::numericId( $row['actor_id'] ?? null );
		if ( 'user' === $type && null !== $id ) {
			$user = $this->userInfo( $id );
			return [
				'kind'    => $user['kind'],
				'name'    => $user['name'],
				'url'     => $user['url'],
				'user_id' => $id,
			];
		}
		$words = [
			'cli'       => __( 'WP-CLI', 'noformat-logger' ),
			'cron'      => __( 'Cron', 'noformat-logger' ),
			'system'    => __( 'System', 'noformat-logger' ),
			'anonymous' => __( 'A visitor', 'noformat-logger' ),
			'app'       => __( 'An app', 'noformat-logger' ),
		];
		return [
			'kind'    => isset( $words[ $type ] ) ? $type : 'system',
			'name'    => $words[ $type ] ?? $words['system'],
			'url'     => '',
			'user_id' => null,
		];
	}

	/**
	 * User as plain data: display name only with list_users.
	 *
	 * @param int $id User id.
	 * @return array{kind: string, name: string, url: string}
	 */
	public function userInfo( int $id ): array {
		if ( ! $this->canListUsers() ) {
			return [
				'kind' => 'user_hidden',
				/* translators: %d: user id */
				'name' => sprintf( __( 'User #%d', 'noformat-logger' ), $id ),
				'url'  => '',
			];
		}
		$user = get_userdata( $id );
		if ( ! is_object( $user ) || ! isset( $user->display_name ) ) {
			return [
				'kind' => 'user_deleted',
				/* translators: %d: user id */
				'name' => sprintf( __( 'Deleted user #%d', 'noformat-logger' ), $id ),
				'url'  => '',
			];
		}
		$link = get_edit_user_link( $id );
		return [
			'kind' => 'user',
			'name' => (string) $user->display_name,
			'url'  => is_string( $link ) ? $link : '',
		];
	}

	/**
	 * Post as plain data (null when it no longer exists).
	 *
	 * Private: the post type is not publicly viewable and the current user may
	 * not edit the post, so its title stays hidden (form entries, CRM contacts).
	 *
	 * @param int $id Post id.
	 * @return array{title: string, url: string, type: string, private: bool, status: string}|null
	 */
	public function postInfo( int $id ): ?array {
		$post = get_post( $id );
		if ( ! is_object( $post ) ) {
			return null;
		}
		$fields   = get_object_vars( $post );
		$type     = isset( $fields['post_type'] ) && is_string( $fields['post_type'] ) ? $fields['post_type'] : 'post';
		$canEdit  = current_user_can( 'edit_post', $id );
		$viewable = ! function_exists( 'is_post_type_viewable' ) || 'nav_menu_item' === $type || is_post_type_viewable( $type );
		$url      = '';
		if ( $canEdit && 'nav_menu_item' !== $type ) {
			$link = get_edit_post_link( $id, 'raw' );
			$url  = is_string( $link ) ? $link : '';
		}
		$title = trim( is_scalar( $fields['post_title'] ?? null ) ? (string) $fields['post_title'] : '' );
		return [
			'title'   => '' === $title ? __( '(no title)', 'noformat-logger' ) : $title,
			'url'     => $url,
			'type'    => $type,
			'private' => ! $viewable && ! $canEdit,
			'status'  => is_scalar( $fields['post_status'] ?? null ) ? (string) $fields['post_status'] : '',
		];
	}

	/**
	 * Term name (null when the term no longer exists).
	 *
	 * @param int $id Term id.
	 */
	public function termName( int $id ): ?string {
		$term   = get_term( $id );
		$fields = is_object( $term ) && ! is_wp_error( $term ) ? get_object_vars( $term ) : [];
		return isset( $fields['name'] ) && is_scalar( $fields['name'] ) ? (string) $fields['name'] : null;
	}

	/**
	 * Cached list_users check.
	 */
	private function canListUsers(): bool {
		if ( null === $this->listUsers ) {
			$this->listUsers = current_user_can( 'list_users' );
		}
		return $this->listUsers;
	}

	/**
	 * Positive integer id or null.
	 *
	 * @param mixed $value Value.
	 */
	public static function numericId( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		return is_string( $value ) && 1 === preg_match( '/^[1-9]\d{0,18}$/', $value ) ? (int) $value : null;
	}
}
