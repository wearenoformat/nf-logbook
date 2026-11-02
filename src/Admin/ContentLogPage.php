<?php
/**
 * Tools → Noformat Logbook → Content log.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Admin;

use Noformat\Logger\Plugin;
use Noformat\Logger\Severity;
use Noformat\Logger\Storage\EventRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Activity feed for site administrators and editors: who changed what, one
 * human sentence per story (the events of one save, see Stories), grouped by
 * day, with a before/after view.
 *
 * Shows only the content categories (Scope::content()). Instantiated on this
 * page's load-{hook} and render only, so other screens pay no query.
 */
final class ContentLogPage {

	public const PER_PAGE_OPT = 'nflog_events_per_page';

	/** Default period. */
	public const DEFAULT_WINDOW = '30d';

	/** Period chips. */
	private const PERIODS = [ 'today', '7d', '30d', 'all' ];

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {
	}

	/**
	 * Store the per-page screen option clamped to 1..200.
	 *
	 * @param mixed  $screenOption Previous filter value.
	 * @param string $option       Option name.
	 * @param mixed  $value        Submitted value.
	 * @return mixed
	 */
	public static function savePerPage( mixed $screenOption, string $option, mixed $value ): mixed {
		return self::PER_PAGE_OPT === $option ? EventFilters::perPage( $value ) : $screenOption;
	}

	/**
	 * Rows per page from the screen option, clamped.
	 */
	public static function perPage(): int {
		$value = get_user_option( self::PER_PAGE_OPT );
		return EventFilters::perPage( false === $value || '' === $value ? EventFilters::PER_PAGE_DEFAULT : $value );
	}

	/**
	 * Validated filters of the current request (period defaults to 30 days).
	 *
	 * @return array<string, string>
	 */
	public static function requestFilters(): array {
		$clean = EventFilters::fromInput( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters, validated against enums and patterns.
		unset( $clean['levels'] );
		if ( ! isset( $clean['window'] ) && ! isset( $clean['from'] ) && ! isset( $clean['request_id'] ) && ! isset( $clean['object_id'] ) ) {
			$clean['window'] = self::DEFAULT_WINDOW;
		}
		return $clean;
	}

	/**
	 * Page URL with filters.
	 *
	 * @param array<string, string> $args Filters.
	 */
	public static function url( array $args = [] ): string {
		return Menu::url( Menu::CONTENT, $args );
	}

	/**
	 * Load-{hook}: export download and the per-page screen option.
	 */
	public function load(): void {
		$canView = Access::canViewEvents( $this->plugin->config() );
		if ( ! $canView && isset( $_GET['nflog_export'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- refused either way.
			check_admin_referer( Export::NONCE );
			wp_die( esc_html__( 'You are not allowed to export the Noformat Logbook event log.', 'nf-logbook' ), 403 );
		}
		if ( ! $canView ) {
			return;
		}
		( new Export( $this->plugin ) )->maybeHandle( Scope::CONTENT, self::requestFilters() );
		add_screen_option(
			'per_page',
			[
				'label'   => __( 'Entries per page (max 200)', 'nf-logbook' ),
				'default' => EventFilters::PER_PAGE_DEFAULT,
				'option'  => self::PER_PAGE_OPT,
			]
		);
	}

	/**
	 * Render the page.
	 *
	 * @param string $tabs Tab bar (escaped HTML, see Menu::tabBar()).
	 */
	public function render( string $tabs = '' ): void {
		$config = $this->plugin->config();
		echo '<div class="wrap nflog-wrap"><div class="nflog-page nflog-page--content">' . $tabs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Menu::tabBar() escapes.
		if ( ! Access::canViewEvents( $config ) ) {
			echo Ui::header( __( 'Content log', 'nf-logbook' ), __( 'Who changed what on this site', 'nf-logbook' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes.
			$empty = Ui::empty(
				__( 'You do not have permission to view the Noformat Logbook event log.', 'nf-logbook' ),
				__( 'On this site the log is reserved for accounts with the noformat_logger_view_events capability.', 'nf-logbook' ),
				Ui::button( Menu::url( Menu::STATUS ), __( 'Open Status', 'nf-logbook' ) )
			);
			echo '<div class="nflog-card nflog-no-permission">' . $empty . '</div></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes.
			return;
		}
		$clean = self::requestFilters();
		Export::logViewed( $this->plugin, Scope::CONTENT, $clean );
		try {
			$this->page( $clean );
		} catch ( \Throwable $e ) {
			echo Ui::notice( __( 'The event log storage is not available.', 'nf-logbook' ), 'error' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes.
		}
		echo '</div></div>';
	}

	/**
	 * Header, attention strip, toolbar, feed, pagination.
	 *
	 * @param array<string, string> $clean Validated filters.
	 */
	private function page( array $clean ): void {
		$repo     = $this->plugin->repository();
		$resolver = new Resolver();
		$filters  = EventFilters::scoped( EventFilters::toRepository( $clean, wp_timezone() ), Scope::content() );
		$perPage  = self::perPage();
		$paged    = isset( $_GET['paged'] ) && is_numeric( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- pagination.
		$result   = self::loadStories( $repo, $filters, $paged, $perPage );
		$total    = $result['total'];
		$pages    = max( 1, (int) ceil( $total / max( 1, $perPage ) ) );
		if ( $paged > $pages ) {
			$paged  = $pages;
			$result = self::loadStories( $repo, $filters, $paged, $perPage );
		}
		$stories = $result['stories'];
		$resolver->prime( $result['rows'] );

		echo $this->header( $clean ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in header().
		if ( Access::canViewSystemLog( $this->plugin->config() ) ) {
			$this->attention();
		}
		$this->toolbar( $clean, $resolver );

		echo '<section class="nflog-card nflog-feed" aria-labelledby="nflog-feed-title">';
		$from = $total > 0 ? ( $paged - 1 ) * $perPage + 1 : 0;
		$to   = min( $total, $paged * $perPage );
		printf(
			'<div class="nflog-feed__head"><h2 class="nflog-feed__title" id="nflog-feed-title">%1$s</h2><p class="nflog-feed__count">%2$s</p></div>',
			esc_html__( 'Activity', 'nf-logbook' ),
			esc_html(
				0 === $total ? __( 'No events', 'nf-logbook' ) : sprintf(
					/* translators: 1: first shown, 2: last shown, 3: total number of entries */
					_n( '%1$s–%2$s of %3$s entry', '%1$s–%2$s of %3$s entries', $total, 'nf-logbook' ),
					number_format_i18n( $from ),
					number_format_i18n( $to ),
					number_format_i18n( $total )
				)
			)
		);
		if ( [] === $stories ) {
			$this->emptyState( $clean );
		} else {
			$this->feed( $stories, $resolver );
		}
		echo '</section>';
		$this->pagination( $clean, $paged, $pages, $total );
	}

	/**
	 * One page of built stories, with anonymous login-time meta attributed to
	 * the user who logged in during the same request (one extra query, only
	 * when such rows are on the page).
	 *
	 * @param EventRepository      $repo      Repository.
	 * @param array<string, mixed> $filters   Typed filters.
	 * @param int                  $page      Page.
	 * @param int                  $perPage   Stories per page.
	 * @param bool                 $withTotal Count all stories.
	 * @return array{stories: list<array{primary: array<string, mixed>, secondary: list<array<string, mixed>>, absorbed: list<int>, events: int, last_ms: int}>, total: int, rows: list<array<string, mixed>>}
	 */
	public static function loadStories( EventRepository $repo, array $filters, int $page, int $perPage, bool $withTotal = true ): array {
		$result = $repo->storiesPage( $filters, $page, $perPage, $withTotal );
		$all    = [];
		foreach ( $result['stories'] as $story ) {
			array_push( $all, ...$story['rows'] );
		}
		$candidates = Stories::loginCandidates( $all );
		$logins     = [] === $candidates ? [] : $repo->loginsInRequests( $candidates );
		$stories    = [];
		$rows       = [];
		foreach ( $result['stories'] as $story ) {
			$group = [] === $logins ? $story['rows'] : Stories::attributeLogins( $story['rows'], $logins );
			array_push( $rows, ...$group );
			$stories[] = Stories::build( $group, $story['events'] ) + [ 'last_ms' => $story['last_ms'] ];
		}
		return [
			'stories' => $stories,
			'total'   => $result['total'],
			'rows'    => $rows,
		];
	}

	/**
	 * Header with period chips, search and export.
	 *
	 * @param array<string, string> $clean Validated filters.
	 */
	private function header( array $clean ): string {
		$labels  = [
			'today' => __( 'Today', 'nf-logbook' ),
			'7d'    => __( '7 days', 'nf-logbook' ),
			'30d'   => __( '30 days', 'nf-logbook' ),
			'all'   => __( 'All', 'nf-logbook' ),
		];
		$current = isset( $clean['from'] ) || isset( $clean['to'] ) ? '' : ( $clean['window'] ?? self::DEFAULT_WINDOW );
		$periods = '<div class="nflog-segmented" role="group" aria-label="' . esc_attr__( 'Period', 'nf-logbook' ) . '">';
		foreach ( self::PERIODS as $period ) {
			$args           = array_diff_key( $clean, array_flip( [ 'window', 'from', 'to' ] ) );
			$args['window'] = $period;
			$periods       .= Ui::chip( self::url( $args ), $labels[ $period ], $current === $period );
		}
		$periods .= '</div>';

		$search  = '<form class="nflog-search" method="get" action="' . esc_url( Menu::formAction() ) . '" role="search">';
		$search .= Menu::formFields( Menu::CONTENT );
		foreach ( array_diff_key( $clean, [ 's' => true ] ) as $key => $value ) {
			$search .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
		}
		$search .= '<label class="screen-reader-text" for="nflog-search">' . esc_html__( 'Search the content log', 'nf-logbook' ) . '</label>';
		$search .= '<span class="nflog-input-icon">' . Ui::icon( 'search' ) . '<input class="nflog-input" type="search" id="nflog-search" name="s" value="' . esc_attr( $clean['s'] ?? '' ) . '" placeholder="' . esc_attr__( 'Search summary, action or id', 'nf-logbook' ) . '"></span></form>';

		$export = Ui::button( Export::url( Scope::CONTENT, $clean ), __( 'Export JSON', 'nf-logbook' ), 'secondary', 'download' );
		return Ui::header( __( 'Content log', 'nf-logbook' ), __( 'Who changed what on this site', 'nf-logbook' ), $periods . $search . $export );
	}

	/**
	 * "3 critical and 4 errors in the last 24 hours → System log" (only when there is something).
	 */
	private function attention(): void {
		$counts   = $this->plugin->repository()->countsBySeverity( ( time() - DAY_IN_SECONDS ) * 1000, null, Scope::system() );
		$critical = (int) ( $counts['critical']['events'] ?? 0 );
		$errors   = (int) ( $counts['error']['events'] ?? 0 );
		if ( 0 === $critical + $errors ) {
			return;
		}
		$parts = [];
		if ( $critical > 0 ) {
			/* translators: %s: number of critical events */
			$parts[] = sprintf( _n( '%s critical event', '%s critical events', $critical, 'nf-logbook' ), number_format_i18n( $critical ) );
		}
		if ( $errors > 0 ) {
			/* translators: %s: number of errors */
			$parts[] = sprintf( _n( '%s error', '%s errors', $errors, 'nf-logbook' ), number_format_i18n( $errors ) );
		}
		$url = SystemLogPage::url(
			[
				'levels' => 'critical,error',
				'window' => '24h',
			]
		);
		printf(
			'<a class="nflog-attention%1$s" href="%2$s"><span class="nflog-attention__icon">%3$s</span><span class="nflog-attention__text"><strong>%4$s</strong> %5$s</span><span class="nflog-attention__link">%6$s %7$s</span></a>',
			$critical > 0 ? ' nflog-attention--critical' : '',
			esc_url( $url ),
			Ui::icon( 'alert' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
			esc_html__( 'Needs attention:', 'nf-logbook' ),
			esc_html(
				/* translators: %s: 3 critical events and 4 errors */
				sprintf( __( '%s in the last 24 hours', 'nf-logbook' ), Humanizer::listing( $parts ) )
			),
			esc_html__( 'System log', 'nf-logbook' ),
			Ui::icon( 'arrow' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		);
	}

	/**
	 * Filter toolbar (GET form; auto-submits on change with admin.js).
	 *
	 * @param array<string, string> $clean    Validated filters.
	 * @param Resolver              $resolver Resolver.
	 */
	private function toolbar( array $clean, Resolver $resolver ): void {
		$types = [
			''           => __( 'Everything', 'nf-logbook' ),
			'pages'      => __( 'Pages', 'nf-logbook' ),
			'posts'      => __( 'Posts', 'nf-logbook' ),
			'media'      => __( 'Media', 'nf-logbook' ),
			'menus'      => __( 'Menus', 'nf-logbook' ),
			'fields'     => __( 'Fields', 'nf-logbook' ),
			'users'      => __( 'Users', 'nf-logbook' ),
			'comments'   => __( 'Comments', 'nf-logbook' ),
			'settings'   => __( 'Settings', 'nf-logbook' ),
			'extensions' => __( 'Plugins & themes', 'nf-logbook' ),
			'other'      => __( 'Other', 'nf-logbook' ),
		];
		$more  = isset( $clean['from'] ) || isset( $clean['to'] ) || isset( $clean['object_id'] ) || isset( $clean['request_id'] ) || isset( $clean['object_type'] );
		echo '<form class="nflog-card nflog-toolbar" method="get" action="' . esc_url( Menu::formAction() ) . '" data-nflog-autosubmit>';
		echo Menu::formFields( Menu::CONTENT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in formFields().
		foreach ( [ 'window', 's' ] as $keep ) {
			if ( isset( $clean[ $keep ] ) ) {
				echo '<input type="hidden" name="' . esc_attr( $keep ) . '" value="' . esc_attr( $clean[ $keep ] ) . '">';
			}
		}
		echo '<div class="nflog-toolbar__row">';
		echo '<fieldset class="nflog-chips"><legend class="nflog-label">' . esc_html__( 'Type', 'nf-logbook' ) . '</legend><div class="nflog-chips__list">';
		foreach ( $types as $value => $label ) {
			$active = ( $clean['type'] ?? '' ) === $value;
			printf(
				'<label class="nflog-chip%1$s"><input type="radio" class="nflog-chip__input" name="type" value="%2$s"%3$s><span>%4$s</span></label>',
				$active ? ' is-active' : '',
				esc_attr( $value ),
				checked( $active, true, false ),
				esc_html( $label )
			);
		}
		echo '</div></fieldset></div>';

		echo '<div class="nflog-toolbar__row nflog-toolbar__row--controls">';
		$this->personSelect( $clean, $resolver );
		echo '<div class="nflog-field"><label class="nflog-label" for="nflog-importance">' . esc_html__( 'Importance', 'nf-logbook' ) . '</label>';
		echo '<select class="nflog-select" id="nflog-importance" name="important"><option value="">' . esc_html__( 'All changes', 'nf-logbook' ) . '</option><option value="1"' . selected( $clean['important'] ?? '', '1', false ) . '>' . esc_html__( 'Important only', 'nf-logbook' ) . '</option></select></div>';
		echo '<div class="nflog-toolbar__actions">';
		printf(
			'<button type="button" class="nflog-btn nflog-btn--ghost" aria-expanded="%1$s" aria-controls="nflog-more" data-nflog-toggle>%2$s<span>%3$s</span></button>',
			$more ? 'true' : 'false',
			Ui::icon( 'filter' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
			esc_html__( 'More filters', 'nf-logbook' )
		);
		echo '<button type="submit" class="nflog-btn nflog-btn--primary">' . esc_html__( 'Apply', 'nf-logbook' ) . '</button>';
		$hasFilters = [] !== array_diff_key( $clean, [ 'window' => true ] );
		if ( $hasFilters ) {
			echo Ui::button( self::url( isset( $clean['window'] ) ? [ 'window' => $clean['window'] ] : [] ), __( 'Clear', 'nf-logbook' ), 'ghost' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes.
		}
		echo '</div></div>';

		echo '<div class="nflog-toolbar__more" id="nflog-more"' . ( $more ? '' : ' hidden' ) . '>';
		foreach ( [
			'from' => __( 'From (site time)', 'nf-logbook' ),
			'to'   => __( 'To (site time)', 'nf-logbook' ),
		] as $name => $label ) {
			$value = $clean[ $name ] ?? '';
			printf(
				'<div class="nflog-field"><label class="nflog-label" for="nflog-%1$s">%2$s</label><input class="nflog-input" type="datetime-local" id="nflog-%1$s" name="%1$s" value="%3$s"></div>',
				esc_attr( $name ),
				esc_html( $label ),
				esc_attr( 10 === strlen( $value ) ? $value . 'T00:00' : $value )
			);
		}
		printf(
			'<div class="nflog-field"><label class="nflog-label" for="nflog-object-type">%1$s</label><input class="nflog-input nflog-input--short" type="text" id="nflog-object-type" name="object_type" value="%2$s" placeholder="post"></div>',
			esc_html__( 'Object type', 'nf-logbook' ),
			esc_attr( $clean['object_type'] ?? '' )
		);
		printf(
			'<div class="nflog-field"><label class="nflog-label" for="nflog-object-id">%1$s</label><input class="nflog-input nflog-input--short" type="text" id="nflog-object-id" name="object_id" value="%2$s" inputmode="numeric"></div>',
			esc_html__( 'Object id', 'nf-logbook' ),
			esc_attr( $clean['object_id'] ?? '' )
		);
		printf(
			'<div class="nflog-field nflog-field--wide"><label class="nflog-label" for="nflog-request-id">%1$s</label><input class="nflog-input nflog-input--mono" type="text" id="nflog-request-id" name="request_id" value="%2$s" spellcheck="false"></div>',
			esc_html__( 'Request id', 'nf-logbook' ),
			esc_attr( $clean['request_id'] ?? '' )
		);
		echo '</div></form>';
	}

	/**
	 * Person select: actors seen in the period.
	 *
	 * @param array<string, string> $clean    Validated filters.
	 * @param Resolver              $resolver Resolver.
	 */
	private function personSelect( array $clean, Resolver $resolver ): void {
		$period = array_intersect_key( $clean, array_flip( [ 'window', 'from', 'to', 'type' ] ) );
		try {
			$actors = $this->plugin->repository()->actors( EventFilters::scoped( EventFilters::toRepository( $period, wp_timezone() ), Scope::content() ), 60 );
		} catch ( \Throwable $e ) {
			$actors = [];
		}
		$current = $clean['actor_id'] ?? ( $clean['actor_type'] ?? '' );
		$users   = [];
		$others  = [];
		foreach ( $actors as $actor ) {
			if ( 'user' === $actor['actor_type'] && null !== $actor['actor_id'] ) {
				$users[ (string) $actor['actor_id'] ] = $resolver->userInfo( $actor['actor_id'] )['name'];
			} elseif ( 'user' !== $actor['actor_type'] ) {
				$others[ $actor['actor_type'] ] = $resolver->actorInfo( [ 'actor_type' => $actor['actor_type'] ] )['name'];
			}
		}
		if ( isset( $clean['actor_id'] ) && ! isset( $users[ $clean['actor_id'] ] ) ) {
			$users[ $clean['actor_id'] ] = $resolver->userInfo( (int) $clean['actor_id'] )['name'];
		}
		asort( $users, SORT_NATURAL | SORT_FLAG_CASE );
		echo '<div class="nflog-field"><label class="nflog-label" for="nflog-person">' . esc_html__( 'Person', 'nf-logbook' ) . '</label><select class="nflog-select" id="nflog-person" name="person"><option value="">' . esc_html__( 'Anyone', 'nf-logbook' ) . '</option>';
		if ( [] !== $users ) {
			echo '<optgroup label="' . esc_attr__( 'People', 'nf-logbook' ) . '">';
			foreach ( $users as $id => $name ) {
				echo '<option value="' . esc_attr( (string) $id ) . '"' . selected( $current, (string) $id, false ) . '>' . esc_html( $name ) . '</option>';
			}
			echo '</optgroup>';
		}
		$words = [
			'cli'       => __( 'WP-CLI', 'nf-logbook' ),
			'cron'      => __( 'Cron', 'nf-logbook' ),
			'system'    => __( 'System', 'nf-logbook' ),
			'anonymous' => __( 'Visitors (not logged in)', 'nf-logbook' ),
			'app'       => __( 'Apps', 'nf-logbook' ),
		];
		echo '<optgroup label="' . esc_attr__( 'System / WP-CLI / Cron', 'nf-logbook' ) . '">';
		foreach ( $words as $type => $label ) {
			if ( isset( $others[ $type ] ) || $current === $type || in_array( $type, [ 'cli', 'cron', 'system' ], true ) ) {
				echo '<option value="' . esc_attr( $type ) . '"' . selected( $current, $type, false ) . '>' . esc_html( $label ) . '</option>';
			}
		}
		echo '</optgroup></select></div>';
	}

	/**
	 * Day groups of stories.
	 *
	 * @param list<array{primary: array<string, mixed>, secondary: list<array<string, mixed>>, absorbed: list<int>, events: int, last_ms: int}> $stories  Stories (newest first).
	 * @param Resolver                                                                                                                          $resolver Resolver.
	 */
	private function feed( array $stories, Resolver $resolver ): void {
		$humanizer = new Humanizer( $resolver );
		$today     = (string) wp_date( 'Y-m-d' );
		$yesterday = (string) wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
		$thisYear  = (string) wp_date( 'Y' );
		$day       = null;
		foreach ( $stories as $story ) {
			$ms  = $story['last_ms'];
			$key = Ui::localTime( $ms, 'Y-m-d' );
			if ( $key !== $day ) {
				if ( null !== $day ) {
					echo '</ol></div>';
				}
				$day   = $key;
				$label = match ( $key ) {
					$today     => __( 'Today', 'nf-logbook' ),
					$yesterday => __( 'Yesterday', 'nf-logbook' ),
					default    => Ui::localTime( $ms, Ui::localTime( $ms, 'Y' ) === $thisYear ? 'l j F' : 'l j F Y' ),
				};
				echo '<div class="nflog-day"><h3 class="nflog-day__label"><span>' . esc_html( $label ) . '</span></h3><ol class="nflog-entries">';
			}
			$this->entry( $story, $resolver, $humanizer );
		}
		if ( null !== $day ) {
			echo '</ol></div>';
		}
	}

	/**
	 * One feed entry: the primary sentence, a muted suffix for the other
	 * events of the save, and the detail panel.
	 *
	 * @param array{primary: array<string, mixed>, secondary: list<array<string, mixed>>, absorbed: list<int>, events: int, last_ms: int} $story     Story.
	 * @param Resolver                                                                                                                    $resolver  Resolver.
	 * @param Humanizer                                                                                                                   $humanizer Humanizer.
	 */
	private function entry( array $story, Resolver $resolver, Humanizer $humanizer ): void {
		$row      = $story['primary'];
		$id       = (int) $row['id'];
		$severity = Severity::fromPriority( (int) $row['severity'] );
		$actor    = $resolver->actorInfo( $row );
		$changes  = Humanizer::decode( $row['changes'] ?? null );
		$context  = Humanizer::decode( $row['context'] ?? null );
		$avatar   = '';
		if ( 'user' === $actor['kind'] && null !== $actor['user_id'] && function_exists( 'get_avatar' ) ) {
			$img    = get_avatar( $actor['user_id'], 40, '', '', [ 'class' => 'nflog-avatar nflog-avatar--img' ] );
			$avatar = is_string( $img ) ? $img : '';
		}
		if ( '' === $avatar ) {
			$avatar = Ui::monogram( $actor['name'], $actor['kind'] );
		}
		$source = (string) ( $row['source'] ?? '' );
		$type   = $this->objectTypeLabel( $row );
		$count  = (int) ( $row['count'] ?? 1 );
		$suffix = Stories::suffix( $story );

		echo '<li class="nflog-entry nflog-entry--' . esc_attr( $severity->value ) . '" id="nflog-event-' . esc_attr( (string) $id ) . '">';
		echo '<div class="nflog-entry__avatar">' . $avatar . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar() / Ui::monogram() output.
		echo '<div class="nflog-entry__body"><p class="nflog-entry__sentence">' . $humanizer->sentence( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses() in sentence().
		if ( '' !== $suffix ) {
			echo ' <span class="nflog-entry__also">' . esc_html( $suffix ) . '</span>';
		}
		if ( Severity::CRITICAL === $severity ) {
			echo ' ' . Ui::badge( $severity, __( 'Important', 'nf-logbook' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes.
		}
		echo '</p><p class="nflog-entry__meta">';
		echo Ui::time( $story['last_ms'], (string) get_option( 'time_format', 'H:i' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes.
		echo '<span class="nflog-dot-sep" aria-hidden="true">·</span><span class="nflog-source nflog-source--' . esc_attr( '' === $source ? 'none' : $source ) . '">' . esc_html( Ui::sourceLabel( $source ) ) . '</span>';
		if ( '' !== $type ) {
			echo '<span class="nflog-dot-sep" aria-hidden="true">·</span><span class="nflog-entry__type">' . esc_html( $type ) . '</span>';
		}
		if ( $count > 1 ) {
			/* translators: %s: number of times */
			echo '<span class="nflog-dot-sep" aria-hidden="true">·</span><span class="nflog-entry__count">' . esc_html( sprintf( _n( '%s time', '%s times', $count, 'nf-logbook' ), number_format_i18n( $count ) ) ) . '</span>';
		}
		$detail = 'nflog-detail-' . $id;
		printf(
			'<span class="nflog-dot-sep" aria-hidden="true">·</span><button type="button" class="nflog-expander" aria-expanded="false" aria-controls="%1$s" data-nflog-toggle><span>%2$s</span>%3$s</button>',
			esc_attr( $detail ),
			esc_html( [] === $changes && [] === $story['secondary'] ? __( 'Details', 'nf-logbook' ) : __( 'What changed', 'nf-logbook' ) ),
			Ui::icon( 'chevron' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		);
		echo '</p>';
		echo '<div class="nflog-entry__detail" id="' . esc_attr( $detail ) . '" hidden>';
		$this->diff( $changes );
		$this->secondary( $story, $resolver, $humanizer );
		$this->facts( $context );
		$this->detailFooter( $row );
		echo '</div></div>';
		if ( $severity->priority() <= Severity::NOTICE->priority() && Severity::CRITICAL !== $severity ) {
			printf(
				'<div class="nflog-entry__aside"><span class="nflog-sevdot nflog-sevdot--%1$s" title="%2$s"><span class="screen-reader-text">%2$s</span></span></div>',
				esc_attr( $severity->value ),
				esc_attr( Ui::severityLabel( $severity ) )
			);
		}
		echo '</li>';
	}

	/**
	 * The other events of the save, one compact row per change: field label,
	 * before → after (redaction markers kept).
	 *
	 * @param array{primary: array<string, mixed>, secondary: list<array<string, mixed>>, absorbed: list<int>, events: int, last_ms: int} $story     Story.
	 * @param Resolver                                                                                                                    $resolver  Resolver.
	 * @param Humanizer                                                                                                                   $humanizer Humanizer.
	 */
	private function secondary( array $story, Resolver $resolver, Humanizer $humanizer ): void {
		if ( [] === $story['secondary'] ) {
			return;
		}
		$primary = $story['primary'];
		$others  = $story['secondary'];
		// The primary's own field edits and merged profile fields first, then in id order.
		$first = static fn( array $r ): bool => Stories::isFieldEdit( $primary, $r ) || in_array( (int) $r['id'], $story['absorbed'], true );
		usort( $others, static fn( array $x, array $y ): int => [ $first( $y ), (int) $x['id'] ] <=> [ $first( $x ), (int) $y['id'] ] );
		$empty = static fn( mixed $v ): bool => null === $v || '' === $v;
		$lines = [];
		$n     = 0;
		foreach ( $others as $row ) {
			$action = (string) ( $row['action'] ?? '' );
			if ( 'term.assigned' === $action ) {
				$lines[] = $this->termLine( $row, $resolver );
				++$n;
				continue;
			}
			$edit    = Stories::isFieldEdit( $primary, $row );
			$changes = str_starts_with( $action, 'meta.' ) || 'acf.fields_saved' === $action || $edit ? Humanizer::decode( $row['changes'] ?? null ) : [];
			$changes = array_values( array_filter( $changes, static fn( mixed $c ): bool => is_array( $c ) && ! ( $empty( $c['old'] ?? null ) && $empty( $c['new'] ?? null ) ) ) );
			if ( $edit && count( $changes ) > 1 ) {
				$changes = array_values( array_filter( $changes, static fn( array $c ): bool => ! in_array( $c['field'] ?? null, [ 'date', 'modified' ], true ) ) );
			}
			if ( [] === $changes && ( str_starts_with( $action, 'meta.' ) && 'meta.deleted' !== $action ) ) {
				continue; // Empty → empty: nothing to show.
			}
			++$n;
			foreach ( $changes as $change ) {
				$lines[] = self::compactLine( $row, $change );
			}
			if ( [] === $changes ) {
				$lines[] = '<tr><td colspan="2" class="nflog-diff__content">' . $humanizer->sentence( $row ) . '</td></tr>';
			}
		}
		if ( [] === $lines ) {
			return;
		}
		/* translators: %s: number of other events */
		echo '<table class="nflog-diff nflog-diff--also"><caption>' . esc_html( sprintf( _n( 'Also in this save (%s event)', 'Also in this save (%s events)', $n, 'nf-logbook' ), number_format_i18n( $n ) ) ) . '</caption><tbody>';
		echo implode( '', $lines ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html(), Humanizer::value() and sentence().
		echo '</tbody></table>';
	}

	/**
	 * One compact row "Label  old → new" of a meta / ACF change.
	 *
	 * @param array<string, mixed> $row    Row.
	 * @param array<mixed>         $change Change {field, old, new}.
	 */
	private static function compactLine( array $row, array $change ): string {
		$field = is_scalar( $change['field'] ?? null ) ? (string) $change['field'] : Stories::metaKey( $row );
		$label = 'user' === ( $row['object_type'] ?? '' ) && 'description' === $field ? __( 'Bio', 'nf-logbook' ) : Humanizer::label( $field );
		$line  = Humanizer::contentLine( $change );
		$old   = $change['old'] ?? null;
		$new   = $change['new'] ?? null;
		if ( null !== $line ) {
			$value = esc_html( $line );
		} else {
			$value = ( null === $old || '' === $old ? Humanizer::value( $old ) : '<del>' . Humanizer::value( $old ) . '</del>' )
				. ' <span class="nflog-diff__arrow" aria-hidden="true">→</span><span class="screen-reader-text">' . esc_html__( 'changed to', 'nf-logbook' ) . '</span> '
				. ( null === $new || '' === $new ? Humanizer::value( $new ) : '<ins>' . Humanizer::value( $new ) . '</ins>' );
		}
		$key = $label !== $field && '' !== $field ? '<code class="nflog-diff__key">' . esc_html( $field ) . '</code>' : '';
		return '<tr><th scope="row"><span class="nflog-diff__label">' . esc_html( $label ) . '</span>' . $key . '</th><td>' . $value . '</td></tr>';
	}

	/**
	 * Compact row of a term.assigned event: "Categories  + News  − Events".
	 *
	 * @param array<string, mixed> $row      Row.
	 * @param Resolver             $resolver Resolver.
	 */
	private function termLine( array $row, Resolver $resolver ): string {
		$context  = Humanizer::decode( $row['context'] ?? null );
		$taxonomy = is_scalar( $row['object_subtype'] ?? null ) ? (string) $row['object_subtype'] : '';
		$names    = static function ( mixed $ids ) use ( $resolver ): string {
			$out = [];
			foreach ( is_array( $ids ) ? $ids : [] as $termId ) {
				$termId = Resolver::numericId( $termId );
				$name   = null === $termId ? null : $resolver->termName( $termId );
				$out[]  = null === $name ? '#' . (string) $termId : $name;
			}
			return implode( _x( ', ', 'list separator', 'nf-logbook' ), $out );
		};
		$added    = $names( $context['added'] ?? [] );
		$removed  = $names( $context['removed'] ?? [] );
		$value    = '' === $added ? '' : '<ins>+ ' . esc_html( $added ) . '</ins> ';
		$value   .= '' === $removed ? '' : '<del>− ' . esc_html( $removed ) . '</del>';
		return '<tr><th scope="row"><span class="nflog-diff__label">' . esc_html( ucfirst( Humanizer::taxonomyLabel( $taxonomy, true ) ) ) . '</span></th><td>' . $value . '</td></tr>';
	}

	/**
	 * Object type for the meta line ("Page", "Option", "Menu item").
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private function objectTypeLabel( array $row ): string {
		$type    = (string) ( $row['object_type'] ?? '' );
		$subtype = (string) ( $row['object_subtype'] ?? '' );
		if ( '' === $type ) {
			return '';
		}
		if ( 'post' === $type ) {
			// The subtype is the post type for post.* events only (meta key, taxonomy otherwise).
			$postType = str_starts_with( (string) ( $row['action'] ?? '' ), 'post.' ) && '' !== $subtype ? $subtype : 'post';
			return ucfirst( Humanizer::postTypeLabel( $postType ) );
		}
		return match ( $type ) {
			'attachment' => __( 'Media', 'nf-logbook' ),
			'option'     => __( 'Setting', 'nf-logbook' ),
			'menu_item'  => __( 'Menu item', 'nf-logbook' ),
			'menu'       => __( 'Menu', 'nf-logbook' ),
			'term'       => __( 'Term', 'nf-logbook' ),
			'user'       => __( 'User', 'nf-logbook' ),
			'comment'    => __( 'Comment', 'nf-logbook' ),
			'plugin'     => __( 'Plugin', 'nf-logbook' ),
			'theme'      => __( 'Theme', 'nf-logbook' ),
			'core'       => __( 'WordPress', 'nf-logbook' ),
			default      => Humanizer::humanize( $type ),
		};
	}

	/**
	 * Before/after table.
	 *
	 * @param array<mixed> $changes Decoded changes.
	 */
	private function diff( array $changes ): void {
		if ( [] === $changes ) {
			return;
		}
		echo '<table class="nflog-diff"><thead><tr><th scope="col">' . esc_html__( 'Field', 'nf-logbook' ) . '</th><th scope="col">' . esc_html__( 'Before', 'nf-logbook' ) . '</th><th scope="col">' . esc_html__( 'After', 'nf-logbook' ) . '</th></tr></thead><tbody>';
		foreach ( $changes as $change ) {
			if ( ! is_array( $change ) ) {
				continue;
			}
			$field = is_scalar( $change['field'] ?? null ) ? (string) $change['field'] : '';
			$label = Humanizer::label( $field );
			$line  = Humanizer::contentLine( $change );
			echo '<tr><th scope="row"><span class="nflog-diff__label">' . esc_html( $label ) . '</span>' . ( $label !== $field && '' !== $field ? '<code class="nflog-diff__key">' . esc_html( $field ) . '</code>' : '' ) . '</th>';
			if ( null !== $line ) {
				echo '<td colspan="2" class="nflog-diff__content">' . esc_html(
					/* translators: %s: 26 → 53 characters (+27) */
					sprintf( __( 'Content changed: %s', 'nf-logbook' ), $line )
				) . '</td></tr>';
				continue;
			}
			$old = $change['old'] ?? null;
			$new = $change['new'] ?? null;
			echo '<td class="nflog-diff__old">' . ( null === $old || '' === $old ? Humanizer::value( $old ) : '<del>' . Humanizer::value( $old ) . '</del>' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- value() escapes.
			echo '<td class="nflog-diff__new">' . ( null === $new || '' === $new ? Humanizer::value( $new ) : '<ins>' . Humanizer::value( $new ) . '</ins>' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- value() escapes.
		}
		echo '</tbody></table>';
	}

	/**
	 * Scalar context facts (status, sizes, roles …), hashes left out.
	 *
	 * @param array<mixed> $context Decoded context.
	 */
	private function facts( array $context ): void {
		$facts = [];
		foreach ( $context as $key => $value ) {
			$key = (string) $key;
			if ( str_ends_with( $key, '_hash' ) || in_array( $key, [ 'trace', 'sha256_8' ], true ) ) {
				continue;
			}
			if ( 'changed_fields' === $key && is_array( $value ) ) {
				$facts[ $key ] = esc_html( implode( _x( ', ', 'list separator', 'nf-logbook' ), Humanizer::profileFields( array_values( array_filter( $value, 'is_string' ) ) ) ) );
			} elseif ( is_array( $value ) && Humanizer::isContentSummary( $value ) ) {
				/* translators: %s: length in characters */
				$facts[ $key ] = esc_html( sprintf( __( '%s characters', 'nf-logbook' ), number_format_i18n( (int) $value['length'] ) ) );
			} elseif ( is_scalar( $value ) || null === $value || ( is_array( $value ) && array_is_list( $value ) && count( $value ) <= 12 ) ) {
				$facts[ $key ] = Humanizer::value( $value );
			}
		}
		if ( [] === $facts ) {
			return;
		}
		echo '<dl class="nflog-facts">';
		foreach ( array_slice( $facts, 0, 12, true ) as $key => $html ) {
			echo '<div><dt>' . esc_html( Humanizer::label( $key ) ) . '</dt><dd>' . $html . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- value() escapes.
		}
		echo '</dl>';
	}

	/**
	 * Request id ("show everything from this save") and event id.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private function detailFooter( array $row ): void {
		$request = (string) ( $row['request_id'] ?? '' );
		echo '<div class="nflog-entry__footer">';
		if ( '' !== $request ) {
			printf(
				'<span>%1$s <code title="%2$s">%3$s</code></span><a href="%4$s">%5$s</a>',
				esc_html__( 'Request', 'nf-logbook' ),
				esc_attr( $request ),
				esc_html( substr( $request, 0, 8 ) ),
				esc_url(
					self::url(
						[
							'request_id' => $request,
							'window'     => 'all',
						]
					)
				),
				esc_html__( 'Show everything from this save', 'nf-logbook' )
			);
		}
		$route = trim( (string) ( $row['method'] ?? '' ) . ' ' . (string) ( $row['route'] ?? '' ) );
		if ( '' !== $route ) {
			echo '<span class="nflog-entry__route"><code>' . esc_html( $route ) . '</code></span>';
		}
		/* translators: %d: event id */
		echo '<span class="nflog-entry__id">' . esc_html( sprintf( __( 'Event #%d', 'nf-logbook' ), (int) $row['id'] ) ) . '</span></div>';
	}

	/**
	 * Empty state with guidance.
	 *
	 * @param array<string, string> $clean Validated filters.
	 */
	private function emptyState( array $clean ): void {
		$active = EventFilters::describe( array_diff_key( $clean, [ 'window' => true ] ) );
		if ( [] === $active && 'all' === ( $clean['window'] ?? '' ) ) {
			echo Ui::empty( __( 'No events have been recorded yet.', 'nf-logbook' ), __( 'Changes to pages, posts, media, menus, users and settings appear here as they happen.', 'nf-logbook' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes.
			return;
		}
		$title = [] === $active
			? __( 'Nothing changed in this period.', 'nf-logbook' )
			/* translators: %s: comma separated list of active filters */
			: sprintf( __( 'No events match: %s.', 'nf-logbook' ), implode( _x( ', ', 'filter list separator', 'nf-logbook' ), $active ) );
		$args = $clean;
		unset( $args['window'] );
		echo Ui::empty( $title, __( 'Try a longer period or clear the filters.', 'nf-logbook' ), Ui::button( self::url( [ 'window' => 'all' ] ), __( 'Show everything', 'nf-logbook' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes.
	}

	/**
	 * Pagination.
	 *
	 * @param array<string, string> $clean Validated filters.
	 * @param int                   $paged Current page.
	 * @param int                   $pages Pages.
	 * @param int                   $total Total entries (stories).
	 */
	private function pagination( array $clean, int $paged, int $pages, int $total ): void {
		if ( $pages <= 1 ) {
			return;
		}
		$link = fn( int $page ): string => self::url( array_merge( $clean, [ 'paged' => (string) $page ] ) );
		echo '<nav class="nflog-pagination" aria-label="' . esc_attr__( 'Pages', 'nf-logbook' ) . '">';
		/* translators: %s: number of entries */
		echo '<span class="nflog-pagination__total">' . esc_html( sprintf( _n( '%s entry', '%s entries', $total, 'nf-logbook' ), number_format_i18n( $total ) ) ) . '</span><span class="nflog-pagination__pages">';
		echo $paged > 1 ? Ui::button( $link( $paged - 1 ), __( 'Newer', 'nf-logbook' ), 'secondary', '', 'rel="prev"' ) : '<span class="nflog-btn nflog-btn--secondary is-disabled" aria-disabled="true"><span>' . esc_html__( 'Newer', 'nf-logbook' ) . '</span></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes.
		$window = array_unique( [ 1, max( 1, $paged - 1 ), $paged, min( $pages, $paged + 1 ), $pages ] );
		sort( $window );
		$last = 0;
		foreach ( $window as $page ) {
			if ( $page - $last > 1 ) {
				echo '<span class="nflog-pagination__gap" aria-hidden="true">…</span>';
			}
			if ( $page === $paged ) {
				echo '<span class="nflog-pagination__page is-current" aria-current="page">' . esc_html( number_format_i18n( $page ) ) . '</span>';
			} else {
				/* translators: %s: page number */
				echo '<a class="nflog-pagination__page" href="' . esc_url( $link( $page ) ) . '" aria-label="' . esc_attr( sprintf( __( 'Page %s', 'nf-logbook' ), number_format_i18n( $page ) ) ) . '">' . esc_html( number_format_i18n( $page ) ) . '</a>';
			}
			$last = $page;
		}
		echo $paged < $pages ? Ui::button( $link( $paged + 1 ), __( 'Older', 'nf-logbook' ), 'secondary', '', 'rel="next"' ) : '<span class="nflog-btn nflog-btn--secondary is-disabled" aria-disabled="true"><span>' . esc_html__( 'Older', 'nf-logbook' ) . '</span></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes.
		echo '</span></nav>';
	}
}
