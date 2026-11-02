<?php
/**
 * Human sentences for the Content log.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a stored event into one sentence in the site language, e.g.
 * "Demo Editor published the page <em>Demo landing page</em>".
 *
 * Names and titles are resolved at render time through the Resolver (never
 * stored) and only when the viewer may see them; titles of private items
 * render as "a private item". Unknown actions fall back to the stored
 * summary. Output is HTML limited to ALLOWED (wp_kses).
 */
final class Humanizer {

	/** Markup allowed in sentences. */
	public const ALLOWED = [
		'a'      => [
			'href'  => true,
			'class' => true,
		],
		'em'     => [ 'class' => true ],
		'strong' => [ 'class' => true ],
		'span'   => [
			'class' => true,
			'title' => true,
		],
		'code'   => [ 'class' => true ],
	];

	/** Field and option names with a human label (see label()). */
	private const LABELS = [
		'title',
		'post_title',
		'slug',
		'post_name',
		'content',
		'post_content',
		'excerpt',
		'post_excerpt',
		'status',
		'post_status',
		'author_id',
		'post_author',
		'parent_id',
		'post_parent',
		'menu_order',
		'date',
		'post_date',
		'comment_status',
		'ping_status',
		'post_type',
		'password',
		'post_password',
		'caption',
		'description',
		'term_name',
		'name',
		'role',
		'roles',
		'version',
		'stylesheet',
		'template',
		'blogname',
		'blogdescription',
		'admin_email',
		'new_admin_email',
		'users_can_register',
		'default_role',
		'siteurl',
		'home',
		'timezone_string',
		'gmt_offset',
		'date_format',
		'time_format',
		'start_of_week',
		'WPLANG',
		'permalink_structure',
		'category_base',
		'tag_base',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'posts_per_page',
		'posts_per_rss',
		'rss_use_excerpt',
		'blog_public',
		'default_comment_status',
		'default_ping_status',
		'comment_moderation',
		'comment_registration',
		'comments_notify',
		'moderation_notify',
		'show_avatars',
		'active_plugins',
		'wp_user_roles',
		'template_root',
		'stylesheet_root',
		'user_url',
		'display_name',
		'user_email',
		'user_pass',
		'user_nicename',
		'user_login',
		'menu_name',
		'type',
		'object',
		'object_id',
		'position',
		'title_hash',
		'url_host',
		'url_hash',
		'target',
		'widgets',
		'custom_css',
		'thumbnail_size_w',
		'thumbnail_size_h',
		'medium_size_w',
		'medium_size_h',
		'large_size_w',
		'large_size_h',
		'uploads_use_yearmonth_folders',
		'default_category',
		'default_post_format',
		'site_icon',
		'wp_page_for_privacy_policy',
		'karma',
		'user_id',
		'post_id',
		'classes',
		'xfn',
		'attr_title',
		'nickname',
		'first_name',
		'last_name',
		'locale',
		'wpseo_title',
		'wpseo_metadesc',
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
		'_yoast_wpseo_focuskw',
	];

	/**
	 * Constructor.
	 *
	 * @param Resolver $resolver Render-time labels.
	 */
	public function __construct( private Resolver $resolver ) {
	}

	/**
	 * The sentence for an event row (HTML, limited markup).
	 *
	 * @param array<string, mixed> $row Event row.
	 */
	public function sentence( array $row ): string {
		$changes = self::decode( $row['changes'] ?? null );
		$context = self::decode( $row['context'] ?? null );
		try {
			$html = $this->build( (string) ( $row['action'] ?? '' ), $row, $changes, $context );
		} catch ( \Throwable $e ) {
			$html = null;
		}
		if ( null === $html ) {
			$html = esc_html( (string) ( $row['summary'] ?? '' ) );
		}
		return wp_kses( $html, self::ALLOWED );
	}

	/**
	 * Actor name as HTML.
	 *
	 * @param array<string, mixed> $row Event row.
	 */
	public function actor( array $row ): string {
		$actor = $this->resolver->actorInfo( $row );
		return '<strong class="nflog-actor">' . esc_html( $actor['name'] ) . '</strong>';
	}

	/**
	 * Human label of a change field or option name: "post_name" → "Slug",
	 * "blogdescription" → "Tagline", "wpseo.some_flag" → "wpseo › Some flag".
	 *
	 * @param string $field Field name (dotted for nested keys).
	 */
	public static function label( string $field ): string {
		$known = self::knownLabel( $field );
		if ( null !== $known ) {
			return $known;
		}
		if ( str_starts_with( $field, 'location.' ) ) {
			/* translators: %s: menu location slug */
			return sprintf( __( 'Menu location %s', 'noformat-logger' ), substr( $field, 9 ) );
		}
		$parts = explode( '.', $field );
		if ( count( $parts ) > 1 ) {
			$head = self::knownLabel( $parts[0] ) ?? $parts[0];
			$tail = array_map( static fn( string $p ): string => self::humanize( $p ), array_slice( $parts, 1 ) );
			return $head . ' › ' . implode( ' › ', $tail );
		}
		return self::humanize( $field );
	}

	/**
	 * Label of a known field or option, or null.
	 *
	 * @param string $field Field.
	 */
	private static function knownLabel( string $field ): ?string {
		if ( ! in_array( $field, self::LABELS, true ) ) {
			return null;
		}
		return match ( $field ) {
			'title', 'post_title', 'title_hash' => __( 'Title', 'noformat-logger' ),
			'slug', 'post_name'                 => __( 'Slug', 'noformat-logger' ),
			'content', 'post_content'           => __( 'Content', 'noformat-logger' ),
			'excerpt', 'post_excerpt'           => __( 'Excerpt', 'noformat-logger' ),
			'status', 'post_status'             => __( 'Status', 'noformat-logger' ),
			'author_id', 'post_author'          => __( 'Author', 'noformat-logger' ),
			'parent_id', 'post_parent'          => __( 'Parent', 'noformat-logger' ),
			'menu_order'                        => __( 'Order', 'noformat-logger' ),
			'date', 'post_date'                 => __( 'Date', 'noformat-logger' ),
			'comment_status'                    => __( 'Comments', 'noformat-logger' ),
			'ping_status'                       => __( 'Pingbacks', 'noformat-logger' ),
			'post_type'                         => __( 'Post type', 'noformat-logger' ),
			'password', 'post_password'         => __( 'Password', 'noformat-logger' ),
			'caption'                           => __( 'Caption', 'noformat-logger' ),
			'description'                       => __( 'Description', 'noformat-logger' ),
			'term_name', 'name', 'menu_name'    => __( 'Name', 'noformat-logger' ),
			'role'                              => __( 'Role', 'noformat-logger' ),
			'roles'                             => __( 'Roles', 'noformat-logger' ),
			'version'                           => __( 'Version', 'noformat-logger' ),
			'stylesheet'                        => __( 'Theme', 'noformat-logger' ),
			'template'                          => __( 'Parent theme', 'noformat-logger' ),
			'blogname'                          => __( 'Site title', 'noformat-logger' ),
			'blogdescription'                   => __( 'Tagline', 'noformat-logger' ),
			'admin_email'                       => __( 'Administration email address', 'noformat-logger' ),
			'new_admin_email'                   => __( 'New administration email address', 'noformat-logger' ),
			'users_can_register'                => __( 'Anyone can register', 'noformat-logger' ),
			'default_role'                      => __( 'New user default role', 'noformat-logger' ),
			'siteurl'                           => __( 'WordPress address (URL)', 'noformat-logger' ),
			'home'                              => __( 'Site address (URL)', 'noformat-logger' ),
			'timezone_string', 'gmt_offset'     => __( 'Timezone', 'noformat-logger' ),
			'date_format'                       => __( 'Date format', 'noformat-logger' ),
			'time_format'                       => __( 'Time format', 'noformat-logger' ),
			'start_of_week'                     => __( 'Week starts on', 'noformat-logger' ),
			'WPLANG'                            => __( 'Site language', 'noformat-logger' ),
			'permalink_structure'               => __( 'Permalink structure', 'noformat-logger' ),
			'category_base'                     => __( 'Category base', 'noformat-logger' ),
			'tag_base'                          => __( 'Tag base', 'noformat-logger' ),
			'show_on_front'                     => __( 'Your homepage displays', 'noformat-logger' ),
			'page_on_front'                     => __( 'Homepage', 'noformat-logger' ),
			'page_for_posts'                    => __( 'Posts page', 'noformat-logger' ),
			'posts_per_page'                    => __( 'Blog pages show at most', 'noformat-logger' ),
			'posts_per_rss'                     => __( 'Syndication feeds show the most recent', 'noformat-logger' ),
			'rss_use_excerpt'                   => __( 'Feed item content', 'noformat-logger' ),
			'blog_public'                       => __( 'Search engine visibility', 'noformat-logger' ),
			'default_comment_status'            => __( 'Allow comments on new posts', 'noformat-logger' ),
			'default_ping_status'               => __( 'Allow pingbacks on new posts', 'noformat-logger' ),
			'comment_moderation'                => __( 'Comment must be manually approved', 'noformat-logger' ),
			'comment_registration'              => __( 'Users must be logged in to comment', 'noformat-logger' ),
			'comments_notify'                   => __( 'Email me when anyone posts a comment', 'noformat-logger' ),
			'moderation_notify'                 => __( 'Email me when a comment is held for moderation', 'noformat-logger' ),
			'show_avatars'                      => __( 'Show avatars', 'noformat-logger' ),
			'active_plugins'                    => __( 'Active plugins', 'noformat-logger' ),
			'wp_user_roles'                     => __( 'User roles and capabilities', 'noformat-logger' ),
			'template_root', 'stylesheet_root'  => __( 'Theme directory', 'noformat-logger' ),
			'user_url'                          => __( 'Website', 'noformat-logger' ),
			'display_name'                      => __( 'Display name', 'noformat-logger' ),
			'user_email'                        => __( 'Email', 'noformat-logger' ),
			'user_pass'                         => __( 'Password', 'noformat-logger' ),
			'user_nicename'                     => __( 'Profile slug', 'noformat-logger' ),
			'user_login'                        => __( 'Username', 'noformat-logger' ),
			'type'                              => __( 'Type', 'noformat-logger' ),
			'object'                            => __( 'Links to', 'noformat-logger' ),
			'object_id'                         => __( 'Linked item', 'noformat-logger' ),
			'position'                          => __( 'Position', 'noformat-logger' ),
			'url_host'                          => __( 'Link host', 'noformat-logger' ),
			'url_hash'                          => __( 'Link', 'noformat-logger' ),
			'target'                            => __( 'Open in', 'noformat-logger' ),
			'widgets'                           => __( 'Widgets', 'noformat-logger' ),
			'custom_css'                        => __( 'Additional CSS', 'noformat-logger' ),
			'thumbnail_size_w'                  => __( 'Thumbnail width', 'noformat-logger' ),
			'thumbnail_size_h'                  => __( 'Thumbnail height', 'noformat-logger' ),
			'medium_size_w'                     => __( 'Medium width', 'noformat-logger' ),
			'medium_size_h'                     => __( 'Medium height', 'noformat-logger' ),
			'large_size_w'                      => __( 'Large width', 'noformat-logger' ),
			'large_size_h'                      => __( 'Large height', 'noformat-logger' ),
			'uploads_use_yearmonth_folders'     => __( 'Organise uploads into month- and year-based folders', 'noformat-logger' ),
			'default_category'                  => __( 'Default post category', 'noformat-logger' ),
			'default_post_format'               => __( 'Default post format', 'noformat-logger' ),
			'site_icon'                         => __( 'Site icon', 'noformat-logger' ),
			'wp_page_for_privacy_policy'        => __( 'Privacy policy page', 'noformat-logger' ),
			'karma'                             => __( 'Karma', 'noformat-logger' ),
			'user_id'                           => __( 'User', 'noformat-logger' ),
			'post_id'                           => __( 'Post', 'noformat-logger' ),
			'classes'                           => __( 'CSS classes', 'noformat-logger' ),
			'xfn'                               => __( 'Link relationship (XFN)', 'noformat-logger' ),
			'nickname'                          => __( 'Nickname', 'noformat-logger' ),
			'first_name'                        => __( 'First name', 'noformat-logger' ),
			'last_name'                         => __( 'Last name', 'noformat-logger' ),
			'locale'                            => __( 'Language', 'noformat-logger' ),
			'wpseo_title', '_yoast_wpseo_title' => __( 'SEO title', 'noformat-logger' ),
			'wpseo_metadesc', '_yoast_wpseo_metadesc' => __( 'Meta description', 'noformat-logger' ),
			'_yoast_wpseo_focuskw'              => __( 'Focus keyphrase', 'noformat-logger' ),
			default                             => __( 'Title attribute', 'noformat-logger' ), // attr_title.
		};
	}

	/**
	 * Profile field names as words for a sentence ("website", "bio", "preferences"), deduplicated.
	 *
	 * @param list<string> $fields user.updated changed_fields and profile meta keys.
	 * @return list<string>
	 */
	public static function profileFields( array $fields ): array {
		$out = [];
		foreach ( $fields as $field ) {
			$word = match ( $field ) {
				'user_url'      => __( 'website', 'noformat-logger' ),
				'description'   => __( 'bio', 'noformat-logger' ),
				'display_name'  => __( 'display name', 'noformat-logger' ),
				'user_email'    => __( 'email address', 'noformat-logger' ),
				'user_pass'     => __( 'password', 'noformat-logger' ),
				'nickname'      => __( 'nickname', 'noformat-logger' ),
				'first_name'    => __( 'first name', 'noformat-logger' ),
				'last_name'     => __( 'last name', 'noformat-logger' ),
				'locale'        => __( 'language', 'noformat-logger' ),
				'user_nicename' => __( 'profile slug', 'noformat-logger' ),
				'user_login'    => __( 'username', 'noformat-logger' ),
				'rich_editing', 'syntax_highlighting', 'admin_color', 'comment_shortcuts', 'show_admin_bar_front' => __( 'preferences', 'noformat-logger' ),
				default         => function_exists( 'mb_strtolower' ) ? mb_strtolower( self::label( $field ) ) : strtolower( self::label( $field ) ),
			};
			if ( ! in_array( $word, $out, true ) ) {
				$out[] = $word;
			}
		}
		return $out;
	}

	/**
	 * "some_flag-name" → "Some flag name".
	 *
	 * @param string $value Key.
	 */
	public static function humanize( string $value ): string {
		$value = trim( str_replace( [ '_', '-' ], ' ', $value ) );
		return '' === $value ? $value : ucfirst( $value );
	}

	/**
	 * A change value as HTML for the before/after table.
	 *
	 * Markers: `{redacted}` → lock chip "hidden for privacy"; `{truncated}` →
	 * "shortened"; content summaries → their length.
	 *
	 * @param mixed $value Stored (already redacted) value.
	 */
	public static function value( mixed $value ): string {
		if ( null === $value || '' === $value ) {
			return '<span class="nflog-empty-value">' . esc_html__( '(empty)', 'noformat-logger' ) . '</span>';
		}
		if ( is_bool( $value ) ) {
			return esc_html( $value ? __( 'Yes', 'noformat-logger' ) : __( 'No', 'noformat-logger' ) );
		}
		if ( is_scalar( $value ) ) {
			return esc_html( (string) $value );
		}
		if ( ! is_array( $value ) ) {
			return '';
		}
		if ( true === ( $value['redacted'] ?? null ) ) {
			return '<span class="nflog-lock" title="' . esc_attr( 'sha256_8 ' . self::scalar( $value['sha256_8'] ?? '' ) ) . '">' . esc_html__( 'hidden for privacy', 'noformat-logger' ) . '</span>';
		}
		if ( true === ( $value['truncated'] ?? null ) ) {
			$preview = isset( $value['preview'] ) && is_string( $value['preview'] ) ? esc_html( $value['preview'] ) . '… ' : '';
			return $preview . '<span class="nflog-marker">' . esc_html(
				/* translators: %s: original length in characters */
				sprintf( __( 'shortened, %s characters', 'noformat-logger' ), number_format_i18n( (int) ( $value['length'] ?? 0 ) ) )
			) . '</span>';
		}
		if ( self::isContentSummary( $value ) ) {
			return '<span class="nflog-marker">' . esc_html(
				/* translators: %s: length in characters */
				sprintf( _n( '%s character', '%s characters', (int) $value['length'], 'noformat-logger' ), number_format_i18n( (int) $value['length'] ) )
			) . '</span>';
		}
		if ( array_is_list( $value ) && [] !== $value && count( array_filter( $value, 'is_scalar' ) ) === count( $value ) ) {
			return esc_html( implode( ', ', array_map( static fn( mixed $v ): string => is_bool( $v ) ? ( $v ? 'true' : 'false' ) : (string) $v, $value ) ) );
		}
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return '<code class="nflog-json">' . esc_html( false === $json ? '' : $json ) . '</code>';
	}

	/**
	 * Content summary line ("Content changed: 26 → 53 characters (+27)") or null.
	 *
	 * @param array<string, mixed> $change One change {field, old, new}.
	 */
	public static function contentLine( array $change ): ?string {
		$old = $change['old'] ?? null;
		$new = $change['new'] ?? null;
		if ( ! is_array( $new ) || ! self::isContentSummary( $new ) ) {
			return null;
		}
		$after  = (int) $new['length'];
		$before = is_array( $old ) && self::isContentSummary( $old ) ? (int) $old['length'] : null;
		$delta  = isset( $new['length_delta'] ) && is_numeric( $new['length_delta'] ) ? (int) $new['length_delta'] : ( null === $before ? null : $after - $before );
		$sign   = null === $delta ? '' : ( $delta > 0 ? '+' : ( $delta < 0 ? '−' : '±' ) ) . number_format_i18n( abs( (int) $delta ) );
		if ( null === $before ) {
			/* translators: %s: length in characters */
			return sprintf( __( '%s characters', 'noformat-logger' ), number_format_i18n( $after ) );
		}
		/* translators: 1: old length, 2: new length, 3: signed difference */
		return sprintf( __( '%1$s → %2$s characters (%3$s)', 'noformat-logger' ), number_format_i18n( $before ), number_format_i18n( $after ), $sign );
	}

	/**
	 * Whether a value is a content summary {sha256_8, length, …}.
	 *
	 * @param array<mixed> $value Value.
	 */
	public static function isContentSummary( array $value ): bool {
		return isset( $value['sha256_8'], $value['length'] ) && is_numeric( $value['length'] ) && ! isset( $value['redacted'] ) && ! isset( $value['truncated'] );
	}

	/**
	 * Sentence for an action, or null to fall back to the summary.
	 *
	 * @param string                   $action  Action.
	 * @param array<string, mixed>     $row     Row.
	 * @param list<mixed>|array<mixed> $changes Decoded changes.
	 * @param array<mixed>             $context Decoded context.
	 */
	private function build( string $action, array $row, array $changes, array $context ): ?string {
		$who    = $this->actor( $row );
		$prefix = strstr( $action, '.', true );
		return match ( $prefix ) {
			'post'                   => $this->post( $action, $who, $row, $changes, $context ),
			'meta', 'acf'            => $this->fields( $action, $who, $row, $context ),
			'term'                   => $this->term( $action, $who, $row, $context ),
			'menu'                   => $this->menu( $action, $who, $row, $context ),
			'media'                  => $this->media( $action, $who, $row, $context ),
			'comment'                => $this->comment( $action, $who, $row, $changes ),
			'option'                 => $this->option( $action, $who, $row ),
			'plugin', 'theme'        => $this->extension( $action, $who, $row, $changes, $context ),
			'core', 'updates'        => $this->core( $action, $who, $changes, $context ),
			'user'                   => $this->user( $action, $who, $row, $changes, $context ),
			'widget', 'sidebar', 'customizer' => $this->appearance( $action, $who, $row, $context ),
			'privacy'                => $this->privacy( $action, $who, $row, $context ),
			default                  => null,
		};
	}

	/**
	 * Posts.
	 *
	 * @param string       $action  Action.
	 * @param string       $who     Actor HTML.
	 * @param array<mixed> $row     Row.
	 * @param array<mixed> $changes Changes.
	 * @param array<mixed> $context Context.
	 */
	private function post( string $action, string $who, array $row, array $changes, array $context ): ?string {
		$it = $this->postPhrase( $row, $changes );
		switch ( $action ) {
			case 'post.created':
				$status = self::scalar( $context['status'] ?? '' );
				return 'draft' === $status || 'auto-draft' === $status
					/* translators: 1: person, 2: item, e.g. the page Home */
					? sprintf( __( '%1$s started a draft of %2$s', 'noformat-logger' ), $who, $it )
					/* translators: 1: person, 2: item */
					: sprintf( __( '%1$s created %2$s', 'noformat-logger' ), $who, $it );
			case 'post.published':
				/* translators: 1: person, 2: item */
				return sprintf( __( '%1$s published %2$s', 'noformat-logger' ), $who, $it );
			case 'post.unpublished':
				/* translators: 1: person, 2: item */
				return sprintf( __( '%1$s unpublished %2$s', 'noformat-logger' ), $who, $it );
			case 'post.trashed':
				/* translators: 1: person, 2: item */
				return sprintf( __( '%1$s moved %2$s to the trash', 'noformat-logger' ), $who, $it );
			case 'post.restored':
				/* translators: 1: person, 2: item */
				return sprintf( __( '%1$s restored %2$s from the trash', 'noformat-logger' ), $who, $it );
			case 'post.deleted':
				/* translators: 1: person, 2: item */
				return sprintf( __( '%1$s permanently deleted %2$s', 'noformat-logger' ), $who, $it );
			case 'post.revision_restored':
				/* translators: 1: person, 2: item */
				return sprintf( __( '%1$s restored an earlier revision of %2$s', 'noformat-logger' ), $who, $it );
			case 'post.status_changed':
				$change = self::change( $changes, 'status' );
				if ( null !== $change ) {
					/* translators: 1: person, 2: item, 3: old status, 4: new status */
					return sprintf( __( '%1$s changed the status of %2$s from %3$s to %4$s', 'noformat-logger' ), $who, $it, self::status( $change['old'] ?? '' ), self::status( $change['new'] ?? '' ) );
				}
				/* translators: 1: person, 2: item */
				return sprintf( __( '%1$s changed the status of %2$s', 'noformat-logger' ), $who, $it );
			case 'post.updated':
				$fields = self::fieldsOf( $changes );
				if ( [ 'content' ] === $fields ) {
					/* translators: 1: person, 2: item */
					return sprintf( __( '%1$s edited the content of %2$s', 'noformat-logger' ), $who, $it );
				}
				if ( [] === $fields ) {
					/* translators: 1: person, 2: item */
					return sprintf( __( '%1$s updated %2$s', 'noformat-logger' ), $who, $it );
				}
				if ( count( $fields ) > 3 ) {
					/* translators: 1: person, 2: number of fields, 3: item */
					return sprintf( __( '%1$s updated %2$s fields of %3$s', 'noformat-logger' ), $who, number_format_i18n( count( $fields ) ), $it );
				}
				$names = array_map( static fn( string $f ): string => function_exists( 'mb_strtolower' ) ? mb_strtolower( self::label( $f ) ) : strtolower( self::label( $f ) ), $fields );
				/* translators: 1: person, 2: list of fields, e.g. title and slug, 3: item */
				return sprintf( __( '%1$s updated the %2$s of %3$s', 'noformat-logger' ), $who, esc_html( self::listing( $names ) ), $it );
		}
		return null;
	}

	/**
	 * Meta and ACF.
	 *
	 * @param string       $action  Action.
	 * @param string       $who     Actor HTML.
	 * @param array<mixed> $row     Row.
	 * @param array<mixed> $context Context.
	 */
	private function fields( string $action, string $who, array $row, array $context ): ?string {
		$key   = self::scalar( $context['meta_key'] ?? ( $row['object_subtype'] ?? '' ) );
		$field = '<code class="nflog-key">' . esc_html( $key ) . '</code>';
		$on    = $this->targetPhrase( $row );
		switch ( $action ) {
			case 'meta.added':
				/* translators: 1: person, 2: field name, 3: item */
				return sprintf( __( '%1$s set the field %2$s on %3$s', 'noformat-logger' ), $who, $field, $on );
			case 'meta.updated':
				/* translators: 1: person, 2: field name, 3: item */
				return sprintf( __( '%1$s changed the field %2$s on %3$s', 'noformat-logger' ), $who, $field, $on );
			case 'meta.deleted':
				/* translators: 1: person, 2: field name, 3: item */
				return sprintf( __( '%1$s removed the field %2$s from %3$s', 'noformat-logger' ), $who, $field, $on );
			case 'acf.fields_saved':
				/* translators: 1: person, 2: item */
				return sprintf( __( '%1$s saved the custom fields of %2$s', 'noformat-logger' ), $who, $on );
			case 'acf.options_saved':
				/* translators: 1: person, 2: options page id */
				return sprintf( __( '%1$s saved the options page %2$s', 'noformat-logger' ), $who, '<strong>' . esc_html( self::scalar( $row['object_id'] ?? '' ) ) . '</strong>' );
			case 'acf.field_group_changed':
				$op = self::scalar( $context['operation'] ?? 'updated' );
				/* translators: 1: person, 2: what happened (updated, duplicated, trashed, and so on), 3: field group */
				return sprintf( __( '%1$s %2$s the field group %3$s', 'noformat-logger' ), $who, esc_html( self::verb( $op ) ), $this->postPhrase( $row, [], false ) );
		}
		return null;
	}

	/**
	 * Terms.
	 *
	 * @param string       $action  Action.
	 * @param string       $who     Actor HTML.
	 * @param array<mixed> $row     Row.
	 * @param array<mixed> $context Context.
	 */
	private function term( string $action, string $who, array $row, array $context ): ?string {
		$taxonomy = self::taxonomyLabel( self::scalar( $row['object_subtype'] ?? '' ) );
		if ( 'term.assigned' === $action ) {
			$added   = is_array( $context['added'] ?? null ) ? count( $context['added'] ) : 0;
			$removed = is_array( $context['removed'] ?? null ) ? count( $context['removed'] ) : 0;
			/* translators: 1: person, 2: taxonomy name (plural, e.g. categories), 3: item, 4: added count, 5: removed count */
			return sprintf( __( '%1$s changed the %2$s of %3$s (+%4$s / −%5$s)', 'noformat-logger' ), $who, esc_html( self::taxonomyLabel( self::scalar( $row['object_subtype'] ?? '' ), true ) ), $this->postPhrase( array_merge( $row, [ 'object_subtype' => self::scalar( $context['post_type'] ?? '' ) ] ), [] ), number_format_i18n( $added ), number_format_i18n( $removed ) );
		}
		$id   = Resolver::numericId( $row['object_id'] ?? null );
		$name = null === $id ? null : $this->resolver->termName( $id );
		$name = $name ?? ( is_string( $context['term_name'] ?? null ) ? $context['term_name'] : null );
		$term = null === $name
			/* translators: 1: taxonomy name, 2: term id */
			? esc_html( sprintf( __( 'the %1$s #%2$s', 'noformat-logger' ), $taxonomy, (string) $id ) )
			/* translators: 1: taxonomy name, 2: term name */
			: sprintf( __( 'the %1$s %2$s', 'noformat-logger' ), esc_html( $taxonomy ), '<em>' . esc_html( $name ) . '</em>' );
		return match ( $action ) {
			/* translators: 1: person, 2: term */
			'term.created' => sprintf( __( '%1$s created %2$s', 'noformat-logger' ), $who, $term ),
			/* translators: 1: person, 2: term */
			'term.updated' => sprintf( __( '%1$s updated %2$s', 'noformat-logger' ), $who, $term ),
			/* translators: 1: person, 2: term */
			'term.deleted' => sprintf( __( '%1$s deleted %2$s', 'noformat-logger' ), $who, $term ),
			default        => null,
		};
	}

	/**
	 * Menus.
	 *
	 * @param string       $action  Action.
	 * @param string       $who     Actor HTML.
	 * @param array<mixed> $row     Row.
	 * @param array<mixed> $context Context.
	 */
	private function menu( string $action, string $who, array $row, array $context ): ?string {
		if ( 'menu.locations_changed' === $action ) {
			/* translators: %s: person */
			return sprintf( __( '%s changed which menus appear in the theme locations', 'noformat-logger' ), $who );
		}
		if ( 'menu.item_updated' === $action || 'menu.item_deleted' === $action ) {
			$item = $this->menuItemPhrase( $row );
			$menu = $this->menuPhrase( Resolver::numericId( $context['menu_id'] ?? null ) );
			if ( 'menu.item_deleted' === $action ) {
				/* translators: 1: person, 2: menu item, 3: menu */
				return sprintf( __( '%1$s removed %2$s from %3$s', 'noformat-logger' ), $who, $item, $menu );
			}
			return true === ( $context['created'] ?? null )
				/* translators: 1: person, 2: menu item, 3: menu */
				? sprintf( __( '%1$s added %2$s to %3$s', 'noformat-logger' ), $who, $item, $menu )
				/* translators: 1: person, 2: menu item, 3: menu */
				: sprintf( __( '%1$s updated %2$s in %3$s', 'noformat-logger' ), $who, $item, $menu );
		}
		$menu = $this->menuPhrase( Resolver::numericId( $row['object_id'] ?? null ), is_string( $context['menu_name'] ?? null ) ? $context['menu_name'] : null );
		return match ( $action ) {
			/* translators: 1: person, 2: menu */
			'menu.created' => sprintf( __( '%1$s created %2$s', 'noformat-logger' ), $who, $menu ),
			/* translators: 1: person, 2: menu */
			'menu.updated' => sprintf( __( '%1$s updated %2$s', 'noformat-logger' ), $who, $menu ),
			/* translators: 1: person, 2: menu */
			'menu.deleted' => sprintf( __( '%1$s deleted %2$s', 'noformat-logger' ), $who, $menu ),
			default        => null,
		};
	}

	/**
	 * Media.
	 *
	 * @param string       $action  Action.
	 * @param string       $who     Actor HTML.
	 * @param array<mixed> $row     Row.
	 * @param array<mixed> $context Context.
	 */
	private function media( string $action, string $who, array $row, array $context ): ?string {
		$mime = self::scalar( $context['mime'] ?? ( $row['object_subtype'] ?? '' ) );
		$kind = self::mediaKind( $mime );
		$id   = Resolver::numericId( $row['object_id'] ?? null );
		$post = null === $id ? null : $this->resolver->postInfo( $id );
		$file = null === $post || $post['private'] ? null : self::linked( $post['title'], $post['url'] );
		if ( 'media.uploaded' === $action ) {
			$facts = [];
			$ext   = strtoupper( (string) substr( (string) strrchr( '/' . $mime, '/' ), 1 ) );
			if ( '' !== $ext ) {
				$facts[] = $ext;
			}
			if ( isset( $context['filesize'] ) && is_numeric( $context['filesize'] ) ) {
				$facts[] = self::bytes( (int) $context['filesize'] );
			}
			$facts = [] === $facts ? '' : ' (' . esc_html( implode( ', ', $facts ) ) . ')';
			return null === $file
				/* translators: 1: person, 2: an image, a video, a file, and so on, 3:  (PNG, 70 bytes) */
				? sprintf( __( '%1$s uploaded %2$s%3$s', 'noformat-logger' ), $who, esc_html( $kind[0] ), $facts )
				/* translators: 1: person, 2: image, video, file, and so on, 3: file title, 4:  (PNG, 70 bytes) */
				: sprintf( __( '%1$s uploaded the %2$s %3$s%4$s', 'noformat-logger' ), $who, esc_html( $kind[1] ), $file, $facts );
		}
		$it = null === $file
			/* translators: 1: image, video, file, and so on, 2: attachment id */
			? esc_html( sprintf( __( 'the %1$s #%2$s', 'noformat-logger' ), $kind[1], (string) $id ) )
			/* translators: 1: image, video, file, and so on, 2: title */
			: sprintf( __( 'the %1$s %2$s', 'noformat-logger' ), esc_html( $kind[1] ), $file );
		return match ( $action ) {
			/* translators: 1: person, 2: file */
			'media.updated'          => sprintf( __( '%1$s updated the details of %2$s', 'noformat-logger' ), $who, $it ),
			/* translators: 1: person, 2: file */
			'media.metadata_updated' => sprintf( __( '%1$s regenerated the sizes of %2$s', 'noformat-logger' ), $who, $it ),
			/* translators: 1: person, 2: file */
			'media.deleted'          => sprintf( __( '%1$s deleted %2$s', 'noformat-logger' ), $who, $it ),
			default                  => null,
		};
	}

	/**
	 * Comments.
	 *
	 * @param string       $action  Action.
	 * @param string       $who     Actor HTML.
	 * @param array<mixed> $row     Row.
	 * @param array<mixed> $changes Changes.
	 */
	private function comment( string $action, string $who, array $row, array $changes ): ?string {
		$id     = Resolver::numericId( $row['object_id'] ?? null );
		$postId = null;
		if ( null !== $id && function_exists( 'get_comment' ) ) {
			$comment = get_comment( $id );
			$fields  = is_object( $comment ) ? get_object_vars( $comment ) : [];
			$postId  = isset( $fields['comment_post_ID'] ) && is_numeric( $fields['comment_post_ID'] ) ? (int) $fields['comment_post_ID'] : null;
		}
		$on = null === $postId ? '' : ' ' . sprintf(
			/* translators: %s: item the comment belongs to */
			__( 'on %s', 'noformat-logger' ),
			$this->postPhrase(
				[
					'object_type' => 'post',
					'object_id'   => (string) $postId,
				],
				[]
			)
		);
		switch ( $action ) {
			case 'comment.created':
				/* translators: 1: person, 2:  on the post X or empty */
				return sprintf( __( '%1$s added a comment%2$s', 'noformat-logger' ), $who, $on );
			case 'comment.updated':
				/* translators: 1: person, 2:  on the post X or empty */
				return sprintf( __( '%1$s edited a comment%2$s', 'noformat-logger' ), $who, $on );
			case 'comment.spammed':
				/* translators: 1: person, 2:  on the post X or empty */
				return sprintf( __( '%1$s marked a comment%2$s as spam', 'noformat-logger' ), $who, $on );
			case 'comment.deleted':
				/* translators: 1: person, 2:  on the post X or empty */
				return sprintf( __( '%1$s deleted a comment%2$s', 'noformat-logger' ), $who, $on );
			case 'comment.status_changed':
				$change = self::change( $changes, 'status' );
				$new    = null === $change ? '' : self::scalar( $change['new'] ?? '' );
				return match ( $new ) {
					/* translators: 1: person, 2:  on the post X or empty */
					'approved', '1' => sprintf( __( '%1$s approved a comment%2$s', 'noformat-logger' ), $who, $on ),
					/* translators: 1: person, 2:  on the post X or empty */
					'unapproved', 'hold', '0' => sprintf( __( '%1$s unapproved a comment%2$s', 'noformat-logger' ), $who, $on ),
					/* translators: 1: person, 2:  on the post X or empty */
					'trash' => sprintf( __( '%1$s moved a comment%2$s to the trash', 'noformat-logger' ), $who, $on ),
					/* translators: 1: person, 2:  on the post X or empty */
					default => sprintf( __( '%1$s changed the status of a comment%2$s', 'noformat-logger' ), $who, $on ),
				};
		}
		return null;
	}

	/**
	 * Options.
	 *
	 * @param string       $action Action.
	 * @param string       $who    Actor HTML.
	 * @param array<mixed> $row    Row.
	 */
	private function option( string $action, string $who, array $row ): ?string {
		$name    = self::scalar( $row['object_id'] ?? '' );
		$known   = self::knownLabel( $name );
		$setting = null === $known
			/* translators: %s: option name */
			? sprintf( __( 'the option %s', 'noformat-logger' ), '<code class="nflog-key">' . esc_html( $name ) . '</code>' )
			/* translators: %s: setting label, e.g. Tagline */
			: sprintf( __( 'the setting %s', 'noformat-logger' ), '<strong>' . esc_html( $known ) . '</strong>' );
		return match ( $action ) {
			/* translators: 1: person, 2: setting */
			'option.updated' => sprintf( __( '%1$s changed %2$s', 'noformat-logger' ), $who, $setting ),
			/* translators: 1: person, 2: setting */
			'option.added'   => sprintf( __( '%1$s added %2$s', 'noformat-logger' ), $who, $setting ),
			/* translators: 1: person, 2: setting */
			'option.deleted' => sprintf( __( '%1$s deleted %2$s', 'noformat-logger' ), $who, $setting ),
			default          => null,
		};
	}

	/**
	 * Plugins and themes.
	 *
	 * @param string       $action  Action.
	 * @param string       $who     Actor HTML.
	 * @param array<mixed> $row     Row.
	 * @param array<mixed> $changes Changes.
	 * @param array<mixed> $context Context.
	 */
	private function extension( string $action, string $who, array $row, array $changes, array $context ): string {
		$slug   = self::scalar( $row['object_id'] ?? '' );
		$plugin = str_starts_with( $action, 'plugin.' );
		$name   = $slug;
		if ( ! $plugin && function_exists( 'wp_get_theme' ) && '' !== $slug ) {
			$theme = wp_get_theme( $slug );
			$name  = $theme->exists() ? (string) $theme->get( 'Name' ) : $slug;
		}
		$thing = $plugin
			/* translators: %s: plugin slug */
			? sprintf( __( 'the plugin %s', 'noformat-logger' ), '<strong>' . esc_html( $name ) . '</strong>' )
			/* translators: %s: theme name */
			: sprintf( __( 'the theme %s', 'noformat-logger' ), '<strong>' . esc_html( $name ) . '</strong>' );
		$failed = isset( $context['error_code'] ) && '' !== $context['error_code'];
		$verb   = substr( $action, (int) strpos( $action, '.' ) + 1 );
		if ( $failed ) {
			/* translators: 1: person, 2: install, update, delete, 3: plugin or theme */
			return sprintf( __( '%1$s tried to %2$s %3$s, but it failed', 'noformat-logger' ), $who, esc_html( self::infinitive( $verb ) ), $thing );
		}
		if ( 'updated' === $verb ) {
			$version = self::change( $changes, 'version' );
			if ( null !== $version && is_scalar( $version['old'] ?? null ) && is_scalar( $version['new'] ?? null ) ) {
				/* translators: 1: person, 2: plugin or theme, 3: old version, 4: new version */
				return sprintf( __( '%1$s updated %2$s from %3$s to %4$s', 'noformat-logger' ), $who, $thing, esc_html( (string) $version['old'] ), esc_html( (string) $version['new'] ) );
			}
		}
		if ( 'switched' === $verb ) {
			/* translators: 1: person, 2: theme */
			return sprintf( __( '%1$s switched to %2$s', 'noformat-logger' ), $who, $thing );
		}
		/* translators: 1: person, 2: what happened (activated, installed, and so on), 3: plugin or theme */
		return sprintf( __( '%1$s %2$s %3$s', 'noformat-logger' ), $who, esc_html( self::verb( $verb ) ), $thing );
	}

	/**
	 * WordPress core and updates.
	 *
	 * @param string       $action  Action.
	 * @param string       $who     Actor HTML.
	 * @param array<mixed> $changes Changes.
	 * @param array<mixed> $context Context.
	 */
	private function core( string $action, string $who, array $changes, array $context ): ?string {
		if ( 'core.updated' === $action ) {
			$version = self::change( $changes, 'version' );
			return null !== $version && is_scalar( $version['old'] ?? null ) && is_scalar( $version['new'] ?? null )
				/* translators: 1: person, 2: old version, 3: new version */
				? sprintf( __( '%1$s updated WordPress from %2$s to %3$s', 'noformat-logger' ), $who, esc_html( (string) $version['old'] ), esc_html( (string) $version['new'] ) )
				/* translators: %s: person */
				: sprintf( __( '%s updated WordPress', 'noformat-logger' ), $who );
		}
		if ( 'core.auto_updates_completed' === $action ) {
			/* translators: %s: person */
			return sprintf( __( '%s ran the automatic updates', 'noformat-logger' ), $who );
		}
		if ( 'updates.pending' === $action ) {
			$parts = [];
			foreach ( [
				/* translators: %s: number */
				'core'    => _n_noop( '%s WordPress update', '%s WordPress updates', 'noformat-logger' ),
				/* translators: %s: number */
				'plugins' => _n_noop( '%s plugin update', '%s plugin updates', 'noformat-logger' ),
				/* translators: %s: number */
				'themes'  => _n_noop( '%s theme update', '%s theme updates', 'noformat-logger' ),
			] as $key => $noop ) {
				$n = isset( $context[ $key ] ) && is_numeric( $context[ $key ] ) ? (int) $context[ $key ] : 0;
				if ( $n > 0 ) {
					$parts[] = sprintf( translate_nooped_plural( $noop, $n, 'noformat-logger' ), number_format_i18n( $n ) );
				}
			}
			return [] === $parts
				? esc_html__( 'Everything is up to date', 'noformat-logger' )
				/* translators: %s: list such as 13 plugin updates and 1 theme update */
				: esc_html( sprintf( __( '%s waiting to be installed', 'noformat-logger' ), self::listing( $parts ) ) );
		}
		return null;
	}

	/**
	 * Users.
	 *
	 * @param string       $action  Action.
	 * @param string       $who     Actor HTML.
	 * @param array<mixed> $row     Row.
	 * @param array<mixed> $changes Changes.
	 * @param array<mixed> $context Context.
	 */
	private function user( string $action, string $who, array $row, array $changes, array $context ): ?string {
		$id   = Resolver::numericId( $row['object_id'] ?? null );
		$self = null !== $id && 'user' === ( $row['actor_type'] ?? '' ) && $id === Resolver::numericId( $row['actor_id'] ?? null );
		$whom = $this->userPhrase( $id );
		$role = esc_html( self::role( self::scalar( $row['object_subtype'] ?? '' ) ) );
		switch ( $action ) {
			case 'user.created':
				/* translators: 1: person, 2: new user, 3: role */
				return sprintf( __( '%1$s added the user %2$s as %3$s', 'noformat-logger' ), $who, $whom, $role );
			case 'user.updated':
				$fields = is_array( $context['changed_fields'] ?? null ) ? array_values( array_filter( $context['changed_fields'], 'is_string' ) ) : [];
				$list   = esc_html( implode( _x( ', ', 'list separator', 'noformat-logger' ), self::profileFields( $fields ) ) );
				if ( $self ) {
					return [] === $fields
						/* translators: %s: person */
						? sprintf( __( '%s updated their profile', 'noformat-logger' ), $who )
						/* translators: 1: person, 2: changed fields, e.g. website, bio */
						: sprintf( __( '%1$s updated their profile: %2$s', 'noformat-logger' ), $who, $list );
				}
				return [] === $fields
					/* translators: 1: person, 2: user */
					? sprintf( __( '%1$s updated the profile of %2$s', 'noformat-logger' ), $who, $whom )
					/* translators: 1: person, 2: user, 3: changed fields, e.g. website, bio */
					: sprintf( __( '%1$s updated the profile of %2$s: %3$s', 'noformat-logger' ), $who, $whom, $list );
			case 'user.role_changed':
				$change = self::change( $changes, 'role' );
				if ( null !== $change ) {
					/* translators: 1: person, 2: user, 3: old role, 4: new role */
					return sprintf( __( '%1$s changed the role of %2$s from %3$s to %4$s', 'noformat-logger' ), $who, $whom, esc_html( self::role( self::scalar( $change['old'] ?? '' ) ) ), esc_html( self::role( self::scalar( $change['new'] ?? '' ) ) ) );
				}
				/* translators: 1: person, 2: user, 3: role */
				return sprintf( __( '%1$s changed the role of %2$s to %3$s', 'noformat-logger' ), $who, $whom, $role );
			case 'user.role_added':
				/* translators: 1: person, 2: role, 3: user */
				return sprintf( __( '%1$s gave the role %2$s to %3$s', 'noformat-logger' ), $who, esc_html( self::role( self::scalar( $context['role'] ?? ( $row['object_subtype'] ?? '' ) ) ) ), $whom );
			case 'user.role_removed':
				/* translators: 1: person, 2: role, 3: user */
				return sprintf( __( '%1$s removed the role %2$s from %3$s', 'noformat-logger' ), $who, esc_html( self::role( self::scalar( $context['role'] ?? ( $row['object_subtype'] ?? '' ) ) ) ), $whom );
			case 'user.deleted':
				/* translators: 1: person, 2: user */
				return sprintf( __( '%1$s deleted the user %2$s', 'noformat-logger' ), $who, $whom );
			case 'user.super_admin_granted':
				/* translators: 1: person, 2: user */
				return sprintf( __( '%1$s made %2$s a super admin', 'noformat-logger' ), $who, $whom );
			case 'user.super_admin_revoked':
				/* translators: 1: person, 2: user */
				return sprintf( __( '%1$s revoked super admin from %2$s', 'noformat-logger' ), $who, $whom );
			case 'user.password_reset':
				return $self
					/* translators: %s: person */
					? sprintf( __( '%s reset their password', 'noformat-logger' ), $who )
					/* translators: 1: person, 2: user */
					: sprintf( __( '%1$s reset the password of %2$s', 'noformat-logger' ), $who, $whom );
			case 'user.password_reset_requested':
				/* translators: 1: person, 2: user */
				return sprintf( __( '%1$s requested a password reset for %2$s', 'noformat-logger' ), $who, $whom );
		}
		return null;
	}

	/**
	 * Widgets, sidebars, Customizer.
	 *
	 * @param string       $action  Action.
	 * @param string       $who     Actor HTML.
	 * @param array<mixed> $row     Row.
	 * @param array<mixed> $context Context.
	 */
	private function appearance( string $action, string $who, array $row, array $context ): ?string {
		$id = '<code class="nflog-key">' . esc_html( self::scalar( $row['object_id'] ?? '' ) ) . '</code>';
		if ( 'widget.updated' === $action ) {
			$op = self::scalar( $context['operation'] ?? 'updated' );
			/* translators: 1: person, 2: added / updated / removed, 3: widget id */
			return sprintf( __( '%1$s %2$s the widget %3$s', 'noformat-logger' ), $who, esc_html( self::verb( $op ) ), $id );
		}
		if ( 'sidebar.updated' === $action ) {
			/* translators: 1: person, 2: sidebar id */
			return sprintf( __( '%1$s rearranged the widgets in %2$s', 'noformat-logger' ), $who, $id );
		}
		if ( 'customizer.saved' === $action ) {
			$setting = self::scalar( $row['object_id'] ?? '' );
			$label   = self::knownLabel( $setting );
			/* translators: 1: person, 2: Customizer setting */
			return sprintf( __( '%1$s changed %2$s in the Customizer', 'noformat-logger' ), $who, null === $label ? $id : '<strong>' . esc_html( $label ) . '</strong>' );
		}
		return null;
	}

	/**
	 * Export, import, personal data.
	 *
	 * @param string       $action  Action.
	 * @param string       $who     Actor HTML.
	 * @param array<mixed> $row     Row.
	 * @param array<mixed> $context Context.
	 */
	private function privacy( string $action, string $who, array $row, array $context ): ?string {
		$whom = 'user' === ( $row['object_type'] ?? '' ) ? $this->userPhrase( Resolver::numericId( $row['object_id'] ?? null ) ) : esc_html__( 'a person without an account', 'noformat-logger' );
		return match ( $action ) {
			/* translators: %s: person */
			'privacy.export_started'          => sprintf( __( '%s exported the site content', 'noformat-logger' ), $who ),
			/* translators: 1: person, 2: importer */
			'privacy.import_started'          => sprintf( __( '%1$s started an import (%2$s)', 'noformat-logger' ), $who, esc_html( self::scalar( $context['importer'] ?? '' ) ) ),
			/* translators: 1: person, 2: importer */
			'privacy.import_finished'         => sprintf( __( '%1$s finished an import (%2$s)', 'noformat-logger' ), $who, esc_html( self::scalar( $context['importer'] ?? '' ) ) ),
			/* translators: 1: person, 2: user */
			'privacy.personal_data_exported'  => sprintf( __( '%1$s exported the personal data of %2$s', 'noformat-logger' ), $who, $whom ),
			/* translators: 1: person, 2: user */
			'privacy.personal_data_erased'    => sprintf( __( '%1$s erased the personal data of %2$s', 'noformat-logger' ), $who, $whom ),
			default                           => null,
		};
	}

	/**
	 * "the page <em>Title</em>", "a private item", "the post #12".
	 *
	 * @param array<mixed> $row      Row (object_type post, object_id, object_subtype).
	 * @param array<mixed> $changes  Changes (a title in clear helps for deleted posts).
	 * @param bool         $withType Prefix with the post type name.
	 */
	public function postPhrase( array $row, array $changes, bool $withType = true ): string {
		$id      = Resolver::numericId( $row['object_id'] ?? null );
		$subtype = self::scalar( $row['object_subtype'] ?? '' );
		$post    = null === $id ? null : $this->resolver->postInfo( $id );
		if ( null !== $post && '' === $subtype ) {
			$subtype = $post['type'];
		}
		$type  = self::postTypeLabel( '' === $subtype ? 'post' : $subtype );
		$title = self::change( $changes, 'title' );
		$clear = null !== $title && is_string( $title['new'] ?? null ) ? $title['new'] : null;
		$coded = null !== $title && ( is_array( $title['new'] ?? null ) || is_array( $title['old'] ?? null ) );
		if ( ( null !== $post && $post['private'] ) || ( null === $post && $coded ) ) {
			return '<span class="nflog-private">' . esc_html__( 'a private item', 'noformat-logger' ) . '</span>';
		}
		if ( null === $post ) {
			if ( null !== $clear && '' !== $clear ) {
				$name = '<em>' . esc_html( $clear ) . '</em>';
			} else {
				/* translators: 1: post type name, 2: post id */
				return esc_html( sprintf( __( 'the %1$s #%2$s', 'noformat-logger' ), $type, (string) $id ) );
			}
		} else {
			$name = self::linked( $post['title'], $post['url'] );
		}
		if ( ! $withType ) {
			return $name;
		}
		/* translators: 1: post type name, e.g. page, 2: title */
		return sprintf( __( 'the %1$s %2$s', 'noformat-logger' ), esc_html( $type ), $name );
	}

	/**
	 * The item an event's meta / fields belong to.
	 *
	 * @param array<mixed> $row Row.
	 */
	private function targetPhrase( array $row ): string {
		$type = self::scalar( $row['object_type'] ?? '' );
		$id   = Resolver::numericId( $row['object_id'] ?? null );
		if ( 'user' === $type ) {
			if ( 'user' === ( $row['actor_type'] ?? '' ) && null !== $id && $id === Resolver::numericId( $row['actor_id'] ?? null ) ) {
				return esc_html__( 'their profile', 'noformat-logger' );
			}
			/* translators: %s: user */
			return sprintf( __( 'the user %s', 'noformat-logger' ), $this->userPhrase( $id ) );
		}
		if ( 'term' === $type ) {
			$name = null === $id ? null : $this->resolver->termName( $id );
			/* translators: %s: term name or id */
			return null === $name ? esc_html( sprintf( __( 'the term #%s', 'noformat-logger' ), (string) $id ) ) : sprintf( __( 'the term %s', 'noformat-logger' ), '<em>' . esc_html( $name ) . '</em>' );
		}
		if ( 'comment' === $type ) {
			/* translators: %s: comment id */
			return esc_html( sprintf( __( 'comment #%s', 'noformat-logger' ), (string) $id ) );
		}
		if ( 'post' === $type ) {
			return $this->postPhrase( [ 'object_id' => $row['object_id'] ?? null ], [] );
		}
		return '<code class="nflog-key">' . esc_html( $type . ( null === $id ? '' : ' #' . $id ) ) . '</code>';
	}

	/**
	 * "the menu item <em>Demo link</em>" or "a menu item".
	 *
	 * @param array<mixed> $row Row.
	 */
	private function menuItemPhrase( array $row ): string {
		$id   = Resolver::numericId( $row['object_id'] ?? null );
		$post = null === $id ? null : $this->resolver->postInfo( $id );
		$name = null;
		if ( null !== $post ) {
			$name = $post['title'];
			if ( function_exists( 'wp_setup_nav_menu_item' ) && function_exists( 'get_post' ) ) {
				$item = wp_setup_nav_menu_item( get_post( $id ) );
				if ( is_object( $item ) && isset( $item->title ) && '' !== trim( (string) $item->title ) ) {
					$name = (string) $item->title;
				}
			}
		}
		/* translators: %s: menu item title */
		return null === $name ? esc_html__( 'a menu item', 'noformat-logger' ) : sprintf( __( 'the menu item %s', 'noformat-logger' ), '<em>' . esc_html( $name ) . '</em>' );
	}

	/**
	 * "the menu <strong>Main</strong>" or "the menu #9".
	 *
	 * @param int|null    $id       Menu term id.
	 * @param string|null $fallback Stored name (menu.created context).
	 */
	private function menuPhrase( ?int $id, ?string $fallback = null ): string {
		$name = null === $id ? null : $this->resolver->termName( $id );
		$name = $name ?? $fallback;
		if ( null === $name ) {
			/* translators: %s: menu id */
			return null === $id ? esc_html__( 'a menu', 'noformat-logger' ) : esc_html( sprintf( __( 'the menu #%s', 'noformat-logger' ), (string) $id ) );
		}
		/* translators: %s: menu name */
		return sprintf( __( 'the menu %s', 'noformat-logger' ), '<strong>' . esc_html( $name ) . '</strong>' );
	}

	/**
	 * A user's name (with list_users) or "user #7".
	 *
	 * @param int|null $id User id.
	 */
	private function userPhrase( ?int $id ): string {
		if ( null === $id ) {
			return esc_html__( 'an unknown user', 'noformat-logger' );
		}
		$user = $this->resolver->userInfo( $id );
		return '<strong>' . esc_html( $user['name'] ) . '</strong>';
	}

	/**
	 * Escaped title, linked when a URL is known.
	 *
	 * @param string $title Title.
	 * @param string $url   Edit URL or ''.
	 */
	private static function linked( string $title, string $url ): string {
		$em = '<em>' . esc_html( $title ) . '</em>';
		return '' === $url ? $em : '<a class="nflog-object-link" href="' . esc_url( $url ) . '">' . $em . '</a>';
	}

	/**
	 * Singular post type name in lower case ("page", "post", "product").
	 *
	 * @param string $type Post type.
	 */
	public static function postTypeLabel( string $type ): string {
		if ( function_exists( 'get_post_type_object' ) ) {
			$object = get_post_type_object( $type );
			if ( is_object( $object ) && isset( $object->labels->singular_name ) && '' !== (string) $object->labels->singular_name ) {
				$name = (string) $object->labels->singular_name;
				return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
			}
		}
		return match ( $type ) {
			'page'       => __( 'page', 'noformat-logger' ),
			'post'       => __( 'post', 'noformat-logger' ),
			'attachment' => __( 'file', 'noformat-logger' ),
			default      => strtolower( self::humanize( $type ) ),
		};
	}

	/**
	 * Taxonomy name ("category", "tag"; plural "categories").
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param bool   $plural   Plural form.
	 */
	public static function taxonomyLabel( string $taxonomy, bool $plural = false ): string {
		if ( function_exists( 'get_taxonomy' ) && '' !== $taxonomy ) {
			$object = get_taxonomy( $taxonomy );
			$labels = is_object( $object ) ? get_object_vars( $object->labels ) : [];
			if ( [] !== $labels ) {
				$name = (string) ( $plural ? ( $labels['name'] ?? '' ) : ( $labels['singular_name'] ?? '' ) );
				if ( '' !== $name ) {
					return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
				}
			}
		}
		return match ( $taxonomy ) {
			'category' => $plural ? __( 'categories', 'noformat-logger' ) : __( 'category', 'noformat-logger' ),
			'post_tag' => $plural ? __( 'tags', 'noformat-logger' ) : __( 'tag', 'noformat-logger' ),
			''         => $plural ? __( 'terms', 'noformat-logger' ) : __( 'term', 'noformat-logger' ),
			default    => strtolower( self::humanize( $taxonomy ) ),
		};
	}

	/**
	 * Translated role name.
	 *
	 * @param string $role Role slug(s), comma separated.
	 */
	private static function role( string $role ): string {
		if ( '' === $role ) {
			return __( 'no role', 'noformat-logger' );
		}
		$names = [];
		foreach ( explode( ',', $role ) as $slug ) {
			$slug = trim( $slug );
			$name = null;
			if ( function_exists( 'wp_roles' ) ) {
				$all  = wp_roles()->get_names();
				$name = isset( $all[ $slug ] ) && function_exists( 'translate_user_role' ) ? translate_user_role( $all[ $slug ] ) : null;
			}
			$names[] = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ?? $slug ) : strtolower( $name ?? $slug );
		}
		return implode( ', ', $names );
	}

	/**
	 * Translated post status.
	 *
	 * @param mixed $status Status.
	 */
	private static function status( mixed $status ): string {
		$status = self::scalar( $status );
		$label  = match ( $status ) {
			'publish' => __( 'published', 'noformat-logger' ),
			'draft'   => __( 'draft', 'noformat-logger' ),
			'pending' => __( 'pending review', 'noformat-logger' ),
			'private' => __( 'private', 'noformat-logger' ),
			'future'  => __( 'scheduled', 'noformat-logger' ),
			'trash'   => __( 'trash', 'noformat-logger' ),
			'new'     => __( 'new', 'noformat-logger' ),
			default   => $status,
		};
		return esc_html( $label );
	}

	/**
	 * Past-tense verb for operations stored in context.
	 *
	 * @param string $op Operation or action suffix.
	 */
	private static function verb( string $op ): string {
		return match ( $op ) {
			'added'       => __( 'added', 'noformat-logger' ),
			'removed'     => __( 'removed', 'noformat-logger' ),
			'duplicated'  => __( 'duplicated', 'noformat-logger' ),
			'trashed'     => __( 'trashed', 'noformat-logger' ),
			'restored'    => __( 'restored', 'noformat-logger' ),
			'deleted'     => __( 'deleted', 'noformat-logger' ),
			'installed'   => __( 'installed', 'noformat-logger' ),
			'activated'   => __( 'activated', 'noformat-logger' ),
			'deactivated' => __( 'deactivated', 'noformat-logger' ),
			default       => __( 'updated', 'noformat-logger' ),
		};
	}

	/**
	 * Infinitive for failed operations.
	 *
	 * @param string $op Action suffix.
	 */
	private static function infinitive( string $op ): string {
		return match ( $op ) {
			'installed' => __( 'install', 'noformat-logger' ),
			'deleted'   => __( 'delete', 'noformat-logger' ),
			default     => __( 'update', 'noformat-logger' ),
		};
	}

	/**
	 * Media kind: [indefinite phrase, noun].
	 *
	 * @param string $mime MIME type.
	 * @return array{0: string, 1: string}
	 */
	private static function mediaKind( string $mime ): array {
		$major = strtolower( (string) strstr( $mime . '/', '/', true ) );
		return match ( true ) {
			'image' === $major            => [ __( 'an image', 'noformat-logger' ), __( 'image', 'noformat-logger' ) ],
			'video' === $major            => [ __( 'a video', 'noformat-logger' ), __( 'video', 'noformat-logger' ) ],
			'audio' === $major            => [ __( 'an audio file', 'noformat-logger' ), __( 'audio file', 'noformat-logger' ) ],
			'application/pdf' === $mime   => [ __( 'a PDF', 'noformat-logger' ), __( 'PDF', 'noformat-logger' ) ],
			default                       => [ __( 'a file', 'noformat-logger' ), __( 'file', 'noformat-logger' ) ],
		};
	}

	/**
	 * File size ("70 bytes", "1.4 MB").
	 *
	 * @param int $bytes Bytes.
	 */
	public static function bytes( int $bytes ): string {
		if ( $bytes < 1024 ) {
			/* translators: %s: number of bytes */
			return sprintf( _n( '%s byte', '%s bytes', $bytes, 'noformat-logger' ), number_format_i18n( $bytes ) );
		}
		$units = [ 'KB', 'MB', 'GB', 'TB' ];
		$value = (float) $bytes;
		$unit  = 'B';
		foreach ( $units as $unit ) {
			$value /= 1024;
			if ( $value < 1024 ) {
				break;
			}
		}
		return number_format_i18n( $value, $value < 10 ? 1 : 0 ) . ' ' . $unit;
	}

	/**
	 * "a", "a and b", "a, b and c".
	 *
	 * @param list<string> $items Items.
	 */
	public static function listing( array $items ): string {
		$items = array_values( $items );
		if ( count( $items ) < 2 ) {
			return $items[0] ?? '';
		}
		$last = array_pop( $items );
		/* translators: 1: comma separated items, 2: last item */
		return sprintf( __( '%1$s and %2$s', 'noformat-logger' ), implode( _x( ', ', 'list separator', 'noformat-logger' ), $items ), $last );
	}

	/**
	 * Field names of changes, without the automatic date bump of an edit.
	 *
	 * @param array<mixed> $changes Changes.
	 * @return list<string>
	 */
	private static function fieldsOf( array $changes ): array {
		$fields = [];
		foreach ( $changes as $change ) {
			if ( is_array( $change ) && is_string( $change['field'] ?? null ) ) {
				$fields[] = $change['field'];
			}
		}
		if ( count( $fields ) > 1 ) {
			$fields = array_values( array_diff( $fields, [ 'date', 'modified' ] ) );
		}
		return $fields;
	}

	/**
	 * First change of a field.
	 *
	 * @param array<mixed> $changes Changes.
	 * @param string       $field   Field.
	 * @return array<mixed>|null
	 */
	private static function change( array $changes, string $field ): ?array {
		foreach ( $changes as $change ) {
			if ( is_array( $change ) && $field === ( $change['field'] ?? null ) ) {
				return $change;
			}
		}
		return null;
	}

	/**
	 * Scalar as string ('' otherwise).
	 *
	 * @param mixed $value Value.
	 */
	private static function scalar( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Decode a JSON column (or pass an array through).
	 *
	 * @param mixed $value Column value.
	 * @return array<mixed>
	 */
	public static function decode( mixed $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		$decoded = is_string( $value ) && '' !== $value ? json_decode( $value, true ) : null;
		return is_array( $decoded ) ? $decoded : [];
	}
}
