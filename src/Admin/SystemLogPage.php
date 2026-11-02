<?php
/**
 * Tools → Noformat Logger → System log.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Admin;

use Noformat\Logger\Plugin;
use Noformat\Logger\Severity;
use Noformat\Logger\Source;
use Noformat\Logger\Storage\EventRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Developer console over the system categories (Scope::system()): errors,
 * stability, security, HTTP, mail, cron, deploys, builds and the logger itself.
 *
 * The server renders the page chrome and the first 200 lines as JSON in a
 * <script type="application/json">; assets/system-log.js renders every line
 * from JSON (initial page, follow mode, "Load older") so there is one renderer.
 */
final class SystemLogPage {

	public const LINES          = 200;
	public const DEFAULT_WINDOW = '7d';

	/** Window select. */
	public const WINDOWS = [ '1h', '24h', '7d', '30d', 'all' ];

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( private Plugin $plugin ) {
	}

	/**
	 * Page URL with filters.
	 *
	 * @param array<string, string> $args Filters.
	 */
	public static function url( array $args = [] ): string {
		return Menu::url( Menu::SYSTEM, $args );
	}

	/**
	 * Validated filters of the current request (window defaults to 7 days).
	 *
	 * @return array<string, string>
	 */
	public static function requestFilters(): array {
		return self::withDefaults( EventFilters::fromInput( wp_unslash( $_GET ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filters, validated against enums and patterns.
	}

	/**
	 * Default window unless a date range or a request/fingerprint pins the view.
	 *
	 * @param array<string, string> $clean Validated filters.
	 * @return array<string, string>
	 */
	public static function withDefaults( array $clean ): array {
		unset( $clean['type'], $clean['important'] );
		if ( ! isset( $clean['window'] ) && ! isset( $clean['from'] ) && ! isset( $clean['request_id'] ) && ! isset( $clean['fingerprint'] ) ) {
			$clean['window'] = self::DEFAULT_WINDOW;
		}
		return $clean;
	}

	/**
	 * Load-{hook}: export download.
	 */
	public function load(): void {
		( new Export( $this->plugin ) )->maybeHandle( Scope::SYSTEM, self::requestFilters() );
	}

	/**
	 * Typed repository filters for clean filters, restricted to the system scope.
	 *
	 * @param array<string, string> $clean Validated filters.
	 * @return array<string, mixed>
	 */
	public static function repositoryFilters( array $clean ): array {
		return EventFilters::scoped( EventFilters::toRepository( $clean, wp_timezone() ), Scope::system() );
	}

	/**
	 * Render the page.
	 *
	 * @param string $tabs Tab bar (escaped HTML, see Menu::tabBar()).
	 */
	public function render( string $tabs = '' ): void {
		echo '<div class="wrap nflog-wrap"><div class="nflog-page nflog-page--system">' . $tabs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Menu::tabBar() escapes.
		if ( ! Access::canViewSystemLog( $this->plugin->config() ) ) {
			echo Ui::header( __( 'System log', 'noformat-logger' ), '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes.
			echo '<div class="nflog-card">' . Ui::empty( __( 'You do not have permission to view the System log.', 'noformat-logger' ), __( 'On this site the developer view is reserved for accounts with the noformat_logger_view_events capability.', 'noformat-logger' ) ) . '</div></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes.
			return;
		}
		$clean = self::requestFilters();
		Export::logViewed( $this->plugin, Scope::SYSTEM, $clean );
		try {
			$this->page( $clean );
		} catch ( \Throwable $e ) {
			echo Ui::notice( __( 'The event log storage is not available.', 'noformat-logger' ), 'error' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes.
		}
		echo '</div></div>';
	}

	/**
	 * Header, stat bar, console.
	 *
	 * @param array<string, string> $clean Validated filters.
	 */
	private function page( array $clean ): void {
		$repo     = $this->plugin->repository();
		$filters  = self::repositoryFilters( $clean );
		$rows     = $repo->query( $filters, 1, self::LINES + 1, 'time', 'desc' );
		$more     = count( $rows ) > self::LINES;
		$rows     = array_slice( $rows, 0, self::LINES );
		$resolver = new Resolver();
		$resolver->prime( $rows );
		$payload = [
			'lines'   => self::lines( $repo, $resolver, $rows ),
			'hasMore' => $more,
			'filters' => (object) $clean,
			'ajax'    => [
				'url'   => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( Ajax::TAIL_NONCE ),
			],
			'urls'    => [
				'self'    => self::url(),
				'content' => Access::canViewEvents( $this->plugin->config() ) ? ContentLogPage::url() : '',
			],
			'follow'  => [
				'interval' => 5000,
				'maxMs'    => 30 * MINUTE_IN_SECONDS * 1000,
			],
			'i18n'    => self::i18n(),
		];

		$back = Access::canViewEvents( $this->plugin->config() ) ? '<a class="nflog-backlink" href="' . esc_url( ContentLogPage::url() ) . '">' . esc_html__( 'Looking for who changed what? Open the Content log', 'noformat-logger' ) . ' ' . Ui::icon( 'arrow' ) . '</a>' : '';
		echo Ui::header( __( 'System log', 'noformat-logger' ), __( 'Errors, security signals, HTTP, mail, cron and deploys', 'noformat-logger' ), $back ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui escapes; $back is escaped above.
		$this->statBar( $clean );
		$this->console( $clean, $payload );
	}

	/**
	 * Severity counters for the window (clickable), totals and the chain head.
	 *
	 * @param array<string, string> $clean Validated filters.
	 */
	private function statBar( array $clean ): void {
		$repo    = $this->plugin->repository();
		$nowMs   = (int) floor( microtime( true ) * 1000 );
		$sinceMs = isset( $clean['window'] ) ? EventFilters::windowStartMs( $clean['window'], wp_timezone(), $nowMs ) : null;
		if ( isset( $clean['from'] ) ) {
			$sinceMs = EventFilters::dateToMs( $clean['from'], wp_timezone(), false );
		}
		$counts = $repo->countsBySeverity( $sinceMs ?? 0, null, Scope::system() );
		$head   = $repo->chainHead();
		$active = isset( $clean['levels'] ) ? explode( ',', $clean['levels'] ) : [];
		$events = 0;
		$occ    = 0;
		echo '<div class="nflog-stats" role="group" aria-label="' . esc_attr__( 'Events per severity in the selected window', 'noformat-logger' ) . '">';
		foreach ( Severity::cases() as $severity ) {
			$row     = $counts[ $severity->value ] ?? [
				'events'      => 0,
				'occurrences' => 0,
			];
			$events += (int) $row['events'];
			$occ    += (int) $row['occurrences'];
			$on      = in_array( $severity->value, $active, true );
			$toggle  = $on ? array_values( array_diff( $active, [ $severity->value ] ) ) : array_merge( $active, [ $severity->value ] );
			$args    = $clean;
			unset( $args['levels'] );
			if ( [] !== $toggle ) {
				$args['levels'] = implode( ',', $toggle );
			}
			printf(
				'<a class="nflog-stat nflog-stat--%1$s%2$s" href="%3$s" aria-pressed="%4$s" role="button"><span class="nflog-stat__label"><span class="nflog-sevdot nflog-sevdot--%1$s" aria-hidden="true"></span>%5$s</span><span class="nflog-stat__value">%6$s</span><span class="nflog-stat__sub">%7$s</span></a>',
				esc_attr( $severity->value ),
				$on ? ' is-active' : '',
				esc_url( self::url( $args ) ),
				$on ? 'true' : 'false',
				esc_html( Ui::severityLabel( $severity ) ),
				esc_html( number_format_i18n( (int) $row['events'] ) ),
				(int) $row['occurrences'] > (int) $row['events'] ? esc_html(
					/* translators: %s: number of occurrences */
					sprintf( __( '%s occurrences', 'noformat-logger' ), number_format_i18n( (int) $row['occurrences'] ) )
				) : '&nbsp;'
			);
		}
		printf(
			'<div class="nflog-stat nflog-stat--totals"><span class="nflog-stat__label">%1$s</span><span class="nflog-stat__value">%2$s <span class="nflog-stat__slash">/</span> %3$s</span><span class="nflog-stat__sub">%4$s <code title="%5$s">#%6$s · %7$s</code></span></div>',
			esc_html__( 'Events / occurrences', 'noformat-logger' ),
			esc_html( number_format_i18n( $events ) ),
			esc_html( number_format_i18n( $occ ) ),
			esc_html__( 'Chain head', 'noformat-logger' ),
			esc_attr( $head['hash'] ),
			esc_html( (string) $head['id'] ),
			esc_html( substr( $head['hash'], 0, 10 ) )
		);
		echo '</div>';
	}

	/**
	 * The dark console: title bar, filter bar, lines (rendered by system-log.js).
	 *
	 * @param array<string, string> $clean   Validated filters.
	 * @param array<string, mixed>  $payload JSON payload.
	 */
	private function console( array $clean, array $payload ): void {
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$query = EventFilters::toQuery( array_diff_key( $clean, array_flip( [ 'levels', 'cats', 'source', 'window' ] ) ) );
		echo '<section class="nflog-card nflog-console" id="nflog-console" aria-label="' . esc_attr__( 'System log console', 'noformat-logger' ) . '">';

		echo '<div class="nflog-console__bar">';
		echo '<span class="nflog-console__dots" aria-hidden="true"><i></i><i></i><i></i></span>';
		echo '<span class="nflog-console__title"><span class="nflog-console__host">' . esc_html( '' === $host ? 'localhost' : $host ) . '</span> — ' . esc_html__( 'System log', 'noformat-logger' ) . '<span class="nflog-console__live" hidden><span class="nflog-console__pulse" aria-hidden="true"></span>' . esc_html__( 'live', 'noformat-logger' ) . '</span></span>';
		echo '<span class="nflog-console__tools">';
		echo '<button type="button" class="nflog-cbtn" data-nflog-follow aria-pressed="false">' . esc_html__( 'Follow', 'noformat-logger' ) . '</button>';
		echo '<button type="button" class="nflog-cbtn" data-nflog-wrap aria-pressed="false">' . esc_html__( 'Wrap', 'noformat-logger' ) . '</button>';
		echo '<a class="nflog-cbtn" href="' . esc_url( Export::url( Scope::SYSTEM, $clean ) ) . '">' . esc_html__( 'Export JSON', 'noformat-logger' ) . '</a>';
		echo '<a class="nflog-cbtn" href="' . esc_url( self::url() ) . '">' . esc_html__( 'Clear filters', 'noformat-logger' ) . '</a>';
		echo '</span></div>';

		echo '<form class="nflog-console__filters" method="get" action="' . esc_url( Menu::formAction() ) . '" data-nflog-console-form>';
		echo Menu::formFields( Menu::SYSTEM ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in formFields().
		echo '<div class="nflog-console__query"><span class="nflog-console__prompt" aria-hidden="true">$</span><label class="screen-reader-text" for="nflog-q">' . esc_html__( 'Filter the system log', 'noformat-logger' ) . '</label>';
		printf(
			'<input type="text" id="nflog-q" name="q" value="%1$s" placeholder="%2$s" autocomplete="off" spellcheck="false" aria-describedby="nflog-q-help"><kbd class="nflog-console__kbd" title="%3$s">/</kbd>',
			esc_attr( $query ),
			esc_attr__( 'filter: text, action:php.warning, cat:security, src:cron, req:<id>, fp:<fingerprint>, since:24h', 'noformat-logger' ),
			esc_attr__( 'Press / to focus', 'noformat-logger' )
		);
		echo '<button type="submit" class="nflog-cbtn nflog-cbtn--apply">' . esc_html__( 'Apply', 'noformat-logger' ) . '</button>';
		echo '<span id="nflog-q-help" class="screen-reader-text">' . esc_html__( 'Free text searches summary and action. Keys: action:, cat:, src:, req:, fp:, since:, sev:, actor:, obj:. Press Enter to apply.', 'noformat-logger' ) . '</span></div>';

		echo '<div class="nflog-console__controls">';
		$levels = isset( $clean['levels'] ) ? explode( ',', $clean['levels'] ) : [];
		echo '<fieldset class="nflog-cchips"><legend class="screen-reader-text">' . esc_html__( 'Severities', 'noformat-logger' ) . '</legend>';
		foreach ( Severity::cases() as $severity ) {
			printf(
				'<label class="nflog-cchip nflog-cchip--%1$s%2$s"><input type="checkbox" name="levels[]" value="%1$s"%3$s><span>%4$s</span></label>',
				esc_attr( $severity->value ),
				in_array( $severity->value, $levels, true ) ? ' is-active' : '',
				checked( in_array( $severity->value, $levels, true ), true, false ),
				esc_html( self::tag( $severity ) )
			);
		}
		echo '</fieldset><span class="nflog-console__sep" aria-hidden="true"></span>';
		$cats = isset( $clean['cats'] ) ? explode( ',', $clean['cats'] ) : [];
		echo '<fieldset class="nflog-cchips"><legend class="screen-reader-text">' . esc_html__( 'Categories', 'noformat-logger' ) . '</legend>';
		foreach ( Scope::system() as $category ) {
			printf(
				'<label class="nflog-cchip%1$s"><input type="checkbox" name="cats[]" value="%2$s"%3$s><span>%2$s</span></label>',
				in_array( $category, $cats, true ) ? ' is-active' : '',
				esc_attr( $category ),
				checked( in_array( $category, $cats, true ), true, false )
			);
		}
		echo '</fieldset><span class="nflog-console__spacer"></span>';

		echo '<label class="screen-reader-text" for="nflog-source">' . esc_html__( 'Source', 'noformat-logger' ) . '</label><select class="nflog-cselect" id="nflog-source" name="source"><option value="">' . esc_html__( 'src: any', 'noformat-logger' ) . '</option>';
		foreach ( Source::cases() as $source ) {
			echo '<option value="' . esc_attr( $source->value ) . '"' . selected( $clean['source'] ?? '', $source->value, false ) . '>src: ' . esc_html( $source->value ) . '</option>';
		}
		echo '</select>';
		$windows = [
			'1h'  => __( 'last 1 h', 'noformat-logger' ),
			'24h' => __( 'last 24 h', 'noformat-logger' ),
			'7d'  => __( 'last 7 d', 'noformat-logger' ),
			'30d' => __( 'last 30 d', 'noformat-logger' ),
			'all' => __( 'all time', 'noformat-logger' ),
		];
		$window  = $clean['window'] ?? '';
		echo '<label class="screen-reader-text" for="nflog-window">' . esc_html__( 'Window', 'noformat-logger' ) . '</label><select class="nflog-cselect" id="nflog-window" name="window">';
		if ( '' !== $window && ! isset( $windows[ $window ] ) ) {
			/* translators: %s: window such as 15m or 3d */
			$windows = [ $window => sprintf( __( 'last %s', 'noformat-logger' ), $window ) ] + $windows;
		}
		if ( '' === $window ) {
			$windows = [ '' => __( 'custom range', 'noformat-logger' ) ] + $windows;
		}
		foreach ( $windows as $value => $label ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( $window, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '</div></form>';

		echo '<div class="nflog-console__head" aria-hidden="true"><span>' . esc_html__( 'time', 'noformat-logger' ) . '</span><span>' . esc_html__( 'lvl', 'noformat-logger' ) . '</span><span>' . esc_html__( 'action', 'noformat-logger' ) . '</span><span>' . esc_html__( 'where', 'noformat-logger' ) . '</span><span>' . esc_html__( 'message', 'noformat-logger' ) . '</span></div>';
		echo '<div class="nflog-console__lines" role="list" data-nflog-lines aria-live="polite" aria-relevant="additions"></div>';
		echo '<noscript><p class="nflog-console__empty">' . esc_html__( 'The System log needs JavaScript. Use Export JSON to read it without.', 'noformat-logger' ) . '</p></noscript>';
		echo '<div class="nflog-console__foot"><button type="button" class="nflog-cbtn" data-nflog-older hidden>' . esc_html__( 'Load older', 'noformat-logger' ) . '</button><span class="nflog-console__status" data-nflog-status></span></div>';
		echo '<script type="application/json" id="nflog-console-data">' . self::json( $payload ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with <, >, & and quotes hex-escaped.
		echo '</section>';
	}

	/**
	 * Console lines for rows: the CONTRACT §1 event plus display fields (prefixed with _).
	 *
	 * @param EventRepository            $repo     Repository.
	 * @param Resolver                   $resolver Resolver (primed).
	 * @param list<array<string, mixed>> $rows     Rows.
	 * @return list<array<string, mixed>>
	 */
	public static function lines( EventRepository $repo, Resolver $resolver, array $rows ): array {
		$requests = [];
		foreach ( $rows as $row ) {
			$requests[] = (string) ( $row['request_id'] ?? '' );
		}
		try {
			$withContent = array_flip( $repo->requestsWithCategories( $requests, Scope::content() ) );
		} catch ( \Throwable $e ) {
			$withContent = [];
		}
		$out = [];
		foreach ( $rows as $row ) {
			$out[] = self::line( $row, $resolver, isset( $withContent[ (string) ( $row['request_id'] ?? '' ) ] ) );
		}
		return $out;
	}

	/**
	 * One console line.
	 *
	 * @param array<string, mixed> $row        Row.
	 * @param Resolver             $resolver   Resolver.
	 * @param bool                 $hasContent The request also produced Content log events.
	 * @return array<string, mixed>
	 */
	public static function line( array $row, Resolver $resolver, bool $hasContent ): array {
		$event = EventRepository::rowToArray( $row );
		unset( $event['site'], $event['producer'] );
		$ms      = (int) ( $row['occurred_ms'] ?? 0 );
		$context = is_array( $event['context'] ?? null ) ? $event['context'] : [];
		$file    = is_string( $context['file'] ?? null ) ? $context['file'] : ( is_array( $context['caller'] ?? null ) && is_string( $context['caller']['file'] ?? null ) ? $context['caller']['file'] : '' );
		$line    = is_numeric( $context['line'] ?? null ) ? (int) $context['line'] : ( is_array( $context['caller'] ?? null ) && is_numeric( $context['caller']['line'] ?? null ) ? (int) $context['caller']['line'] : 0 );
		$loc     = '' === $file ? '' : $file . ( $line > 0 ? ':' . $line : '' );
		if ( '' === $loc && is_array( $event['object'] ) ) {
			$loc = (string) $event['object']['type'] . ( null === $event['object']['id'] ? '' : '/' . $event['object']['id'] );
		}
		$action  = (string) $event['action'];
		$message = (string) $event['summary'];
		if ( ( str_starts_with( $action, 'php.' ) || str_starts_with( $action, 'doing_it_wrong' ) || str_starts_with( $action, 'deprecated.' ) || 'db.error' === $action ) && is_string( $context['message'] ?? null ) && '' !== $context['message'] ) {
			$message = $context['message'];
		}
		$actor             = $resolver->actorInfo( $row );
		$event['_ms']      = $ms;
		$event['_time']    = Ui::localTime( $ms, 'H:i:s' ) . sprintf( '.%03d', $ms % 1000 );
		$event['_date']    = Ui::localTime( $ms, 'Y-m-d' );
		$event['_loc']     = $loc;
		$event['_msg']     = $message;
		$event['_actor']   = $actor['name'];
		$event['_content'] = $hasContent;
		return $event;
	}

	/**
	 * Short severity tag.
	 *
	 * @param Severity $severity Severity.
	 */
	public static function tag( Severity $severity ): string {
		return match ( $severity ) {
			Severity::CRITICAL => 'CRIT',
			Severity::ERROR    => 'ERR',
			Severity::WARNING  => 'WARN',
			Severity::NOTICE   => 'NOTE',
			Severity::INFO     => 'INFO',
			Severity::DEBUG    => 'DBG',
		};
	}

	/**
	 * JSON safe inside a script element.
	 *
	 * @param mixed $value Value.
	 */
	public static function json( mixed $value ): string {
		$json = wp_json_encode( $value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		return false === $json ? '{}' : $json;
	}

	/**
	 * Strings for system-log.js.
	 *
	 * @return array<string, string>
	 */
	private static function i18n(): array {
		return [
			'empty'       => __( '$ nothing matches — try widening the window or clearing filters', 'noformat-logger' ),
			'loading'     => __( 'loading…', 'noformat-logger' ),
			'older'       => __( 'Load older', 'noformat-logger' ),
			'noMore'      => __( 'beginning of the selected window', 'noformat-logger' ),
			'error'       => __( 'could not load events', 'noformat-logger' ),
			'copy'        => __( 'Copy as JSON', 'noformat-logger' ),
			'copied'      => __( 'Copied', 'noformat-logger' ),
			'openContent' => __( 'Open in Content log', 'noformat-logger' ),
			'changes'     => __( 'changes', 'noformat-logger' ),
			'context'     => __( 'context', 'noformat-logger' ),
			'trace'       => __( 'stack', 'noformat-logger' ),
			'event'       => __( 'event', 'noformat-logger' ),
			'fingerprint' => __( 'fingerprint', 'noformat-logger' ),
			'request'     => __( 'request', 'noformat-logger' ),
			'actor'       => __( 'actor', 'noformat-logger' ),
			'source'      => __( 'source', 'noformat-logger' ),
			'route'       => __( 'route', 'noformat-logger' ),
			'category'    => __( 'category', 'noformat-logger' ),
			'count'       => __( 'count', 'noformat-logger' ),
			'time'        => __( 'time (UTC)', 'noformat-logger' ),
			'hash'        => __( 'hash', 'noformat-logger' ),
			'prevHash'    => __( 'prev hash', 'noformat-logger' ),
			'filterBy'    => __( 'filter by this', 'noformat-logger' ),
			'live'        => __( 'live', 'noformat-logger' ),
			'stopped'     => __( 'follow stopped after 30 minutes', 'noformat-logger' ),
			/* translators: %d: number of new lines */
			'newLines'    => __( '%d new', 'noformat-logger' ),
			/* translators: %d: number of lines shown */
			'lines'       => __( '%d lines', 'noformat-logger' ),
		];
	}
}
