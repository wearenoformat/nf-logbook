<?php
/**
 * Dashboard widget.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Admin;

use Noformat\Logger\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * "Recent activity": the 5 latest Content log stories (one save = one line)
 * with avatars and, for users who may read the System log, one line with the
 * critical and error count of the last 24 h. Queries run only when the
 * dashboard renders the widget: the story keys, their events (plus count
 * hydration) and one grouped count.
 */
final class DashboardWidget {

	public const ID = 'nflog_events_widget';

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {
	}

	/**
	 * Hooks.
	 */
	public function hooks(): void {
		add_action( 'wp_dashboard_setup', [ $this, 'setup' ] );
	}

	/**
	 * Register the widget and its stylesheet (dashboard only).
	 */
	public function setup(): void {
		if ( ! Access::canViewEvents( $this->plugin->config() ) ) {
			return;
		}
		wp_add_dashboard_widget( self::ID, __( 'Recent activity', 'nf-logbook' ), [ $this, 'render' ] );
		Menu::enqueueStyle();
	}

	/**
	 * Widget body.
	 */
	public function render(): void {
		if ( ! Access::canViewEvents( $this->plugin->config() ) ) {
			return;
		}
		$repo = $this->plugin->repository();
		try {
			$result = ContentLogPage::loadStories( $repo, [ 'categories' => Scope::content() ], 1, 5, false );
		} catch ( \Throwable $e ) {
			echo '<p class="nflog-muted">' . esc_html__( 'The event log storage is not available.', 'nf-logbook' ) . '</p>';
			return;
		}
		$resolver = new Resolver();
		$resolver->prime( $result['rows'] );
		$humanizer = new Humanizer( $resolver );
		echo '<div class="nflog-widget">';
		if ( [] === $result['stories'] ) {
			echo '<p class="nflog-widget__empty">' . esc_html__( 'No changes recorded yet.', 'nf-logbook' ) . '</p>';
		} else {
			echo '<ul class="nflog-widget__list">';
			foreach ( $result['stories'] as $story ) {
				$row    = $story['primary'];
				$actor  = $resolver->actorInfo( $row );
				$avatar = '';
				if ( 'user' === $actor['kind'] && null !== $actor['user_id'] && function_exists( 'get_avatar' ) ) {
					$img    = get_avatar( $actor['user_id'], 32, '', '', [ 'class' => 'nflog-avatar nflog-avatar--img nflog-avatar--sm' ] );
					$avatar = is_string( $img ) ? $img : '';
				}
				if ( '' === $avatar ) {
					$avatar = Ui::monogram( $actor['name'], $actor['kind'] );
				}
				$sentence = $humanizer->sentence( $row );
				$suffix   = Stories::suffix( $story );
				$full     = trim( html_entity_decode( wp_strip_all_tags( $sentence ), ENT_QUOTES ) . ( '' === $suffix ? '' : ' ' . $suffix ) );
				printf(
					'<li class="nflog-widget__item"><span class="nflog-widget__avatar">%1$s</span><span class="nflog-widget__text"><span class="nflog-widget__sentence" title="%2$s">%3$s%4$s</span><span class="nflog-widget__time">%5$s</span></span></li>',
					$avatar, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_avatar() / Ui::monogram().
					esc_attr( $full ),
					$sentence, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_kses() in sentence().
					'' === $suffix ? '' : ' <span class="nflog-widget__also">' . esc_html( $suffix ) . '</span>',
					esc_html(
						/* translators: %s: human readable time difference */
						sprintf( __( '%s ago', 'nf-logbook' ), human_time_diff( intdiv( $story['last_ms'], 1000 ) ) )
					)
				);
			}
			echo '</ul>';
		}
		echo '<p class="nflog-widget__more"><a href="' . esc_url( ContentLogPage::url() ) . '">' . esc_html__( 'Open the Content log', 'nf-logbook' ) . ' ' . Ui::icon( 'arrow' ) . '</a></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		if ( Access::canViewSystemLog( $this->plugin->config() ) ) {
			$this->systemLine();
		}
		echo '</div>';
	}

	/**
	 * "System: 3 critical, 4 errors in 24 h → System log" (only when there is something).
	 */
	private function systemLine(): void {
		try {
			// Open-window occurrence deltas are skipped ([]): the line counts stored events.
			$counts = $this->plugin->repository()->countsBySeverity( ( time() - DAY_IN_SECONDS ) * 1000, [], Scope::system() );
		} catch ( \Throwable $e ) {
			return;
		}
		$critical = (int) ( $counts['critical']['events'] ?? 0 );
		$errors   = (int) ( $counts['error']['events'] ?? 0 );
		if ( 0 === $critical + $errors ) {
			return;
		}
		$text = sprintf(
			/* translators: 1: number of critical events, 2: number of errors */
			__( 'System: %1$s critical, %2$s errors in 24 h', 'nf-logbook' ),
			number_format_i18n( $critical ),
			number_format_i18n( $errors )
		);
		printf(
			'<a class="nflog-widget__system%1$s" href="%2$s"><span class="nflog-sevdot nflog-sevdot--%3$s" aria-hidden="true"></span><span>%4$s</span><span class="nflog-widget__system-link">%5$s %6$s</span></a>',
			$critical > 0 ? ' is-critical' : '',
			esc_url(
				SystemLogPage::url(
					[
						'levels' => 'critical,error',
						'window' => '24h',
					]
				)
			),
			$critical > 0 ? 'critical' : 'error',
			esc_html( $text ),
			esc_html__( 'System log', 'nf-logbook' ),
			Ui::icon( 'arrow' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		);
	}
}
