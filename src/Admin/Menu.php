<?php
/**
 * Tools → Noformat Logbook.
 *
 * @package Noformat\Logger
 */

declare(strict_types=1);

namespace Noformat\Logger\Admin;

use Noformat\Logger\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers one page under Tools (`tools.php?page=nf-logbook`) whose
 * screens are in-page tabs (Content log, System log, Status, Settings), routes
 * the load-{hook} work to the tab's page object, enqueues the stylesheet and
 * scripts on that page only and redirects the 1.0 top-level menu URLs.
 *
 * Nothing here touches the log tables: other admin screens run no extra query.
 */
final class Menu {

	/** Page slug under Tools. */
	public const PAGE = 'nf-logbook';

	/** Parent screen. */
	public const PARENT = 'tools.php';

	public const CONTENT  = 'content';
	public const SYSTEM   = 'system';
	public const STATUS   = 'status';
	public const SETTINGS = 'settings';

	/**
	 * 1.0 top-level menu slugs (admin.php?page=…) and their tab.
	 */
	public const LEGACY = [
		'noformat-logger'          => self::CONTENT,
		'noformat-logger-system'   => self::SYSTEM,
		'noformat-logger-status'   => self::STATUS,
		'noformat-logger-settings' => self::SETTINGS,
	];

	/**
	 * Page slug under Tools before the rename to Noformat Logbook (1.1.x).
	 */
	public const LEGACY_PAGE = 'noformat-logger';

	/**
	 * Page hook suffix (tools_page_nf-logbook).
	 *
	 * @var string
	 */
	private string $hook = '';

	/**
	 * Tab resolved on load-{hook}.
	 *
	 * @var string
	 */
	private string $tab = '';

	private ?ContentLogPage $content = null;

	private ?SystemLogPage $system = null;

	private ?StatusPage $status = null;

	private ?SettingsPage $settings = null;

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
		add_action( 'admin_menu', [ $this, 'register' ] );
		// admin.php?page=noformat-logger-system etc. are no longer registered, and wp-admin/menu.php
		// refuses unknown pages right after admin_menu, before admin_init: redirect at the end of
		// admin_menu (admin_init too, for any screen that skips menu.php).
		add_action( 'admin_menu', [ $this, 'redirectLegacy' ], PHP_INT_MAX );
		add_action( 'admin_init', [ $this, 'redirectLegacy' ], 0 );
		add_action( 'admin_init', [ $this->settingsPage(), 'registerSettings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_filter( 'set_screen_option_' . ContentLogPage::PER_PAGE_OPT, [ ContentLogPage::class, 'savePerPage' ], 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( NOFORMAT_LOGGER_FILE ), [ self::class, 'actionLinks' ] );
		( new Ajax( $this->plugin ) )->hooks();
	}

	/**
	 * URL of a tab.
	 *
	 * @param string               $tab  Tab (CONTENT, SYSTEM, STATUS, SETTINGS).
	 * @param array<string, mixed> $args Query args.
	 */
	public static function url( string $tab, array $args = [] ): string {
		return add_query_arg(
			array_merge(
				[
					'page' => self::PAGE,
					'tab'  => $tab,
				],
				$args
			),
			admin_url( self::PARENT )
		);
	}

	/**
	 * Hidden inputs that keep a GET form on its tab.
	 *
	 * @param string $tab Tab.
	 */
	public static function formFields( string $tab ): string {
		return '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
	}

	/**
	 * Form action of the tab forms.
	 */
	public static function formAction(): string {
		return admin_url( self::PARENT );
	}

	/**
	 * The icon SVG (assets/icon.svg).
	 */
	public static function iconSvg(): string {
		$file = dirname( NOFORMAT_LOGGER_FILE ) . '/assets/icon.svg';
		$svg  = is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin asset.
		return is_string( $svg ) ? trim( $svg ) : '';
	}

	/**
	 * Tab labels.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return [
			self::CONTENT  => __( 'Content log', 'nf-logbook' ),
			self::SYSTEM   => __( 'System log', 'nf-logbook' ),
			self::STATUS   => __( 'Status', 'nf-logbook' ),
			self::SETTINGS => __( 'Settings', 'nf-logbook' ),
		];
	}

	/**
	 * Tab of a request (pure): the requested tab when visible, the first visible
	 * tab when none (or an unknown one) is asked for, null when the requested
	 * tab exists but is not visible to the user.
	 *
	 * @param mixed        $requested Raw ?tab= value.
	 * @param list<string> $visible   Access::tabs().
	 */
	public static function resolveTab( mixed $requested, array $visible ): ?string {
		$requested = is_string( $requested ) ? sanitize_key( $requested ) : '';
		if ( ! array_key_exists( $requested, self::labels() ) ) {
			return $visible[0] ?? null;
		}
		return in_array( $requested, $visible, true ) ? $requested : null;
	}

	/**
	 * Plugins screen: "Settings" (administrators) next to Deactivate.
	 *
	 * @param array<int|string, string> $links Action links.
	 * @return array<int|string, string>
	 */
	public static function actionLinks( array $links ): array {
		if ( current_user_can( 'manage_options' ) ) {
			array_unshift( $links, '<a href="' . esc_url( self::url( self::SETTINGS ) ) . '">' . esc_html__( 'Settings', 'nf-logbook' ) . '</a>' );
		}
		return $links;
	}

	/**
	 * Register Tools → Noformat Logbook.
	 */
	public function register(): void {
		if ( [] === Access::tabs( $this->plugin->config() ) ) {
			return;
		}
		$hook = add_submenu_page(
			self::PARENT,
			__( 'Noformat Logbook', 'nf-logbook' ),
			__( 'Noformat Logbook', 'nf-logbook' ),
			Access::menuCapability(),
			self::PAGE,
			[ $this, 'render' ]
		);
		if ( is_string( $hook ) && '' !== $hook ) {
			$this->hook = $hook;
			add_action( 'load-' . $hook, [ $this, 'load' ] );
		}
	}

	/**
	 * Load-{hook}: resolve the tab (403 for a tab the user may not open), then the tab's own load work.
	 */
	public function load(): void {
		$visible = Access::tabs( $this->plugin->config() );
		$tab     = self::resolveTab( self::requestedTab(), $visible );
		if ( null === $tab ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'nf-logbook' ), 403 );
		}
		$this->tab = $tab;
		match ( $tab ) {
			self::CONTENT => $this->contentPage()->load(),
			self::SYSTEM  => $this->systemPage()->load(),
			self::STATUS  => $this->statusPage()->load(),
			default       => null,
		};
	}

	/**
	 * Raw ?tab= of the request, sanitized.
	 */
	private static function requestedTab(): string {
		return isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
	}

	/**
	 * Old links go to the tabs, filters kept (301):
	 * admin.php?page=noformat-logger[-system|-status|-settings] (1.0),
	 * tools.php?page=noformat-logger[&tab=…] (1.1, before the rename) and
	 * tools.php?page=…&tab=events (0.x).
	 */
	public function redirectLegacy(): void {
		global $pagenow;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation only; the target tab checks access.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$get  = wp_unslash( $_GET );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$get = is_array( $get ) ? $get : [];
		$tab = self::legacyTab( is_string( $pagenow ) ? $pagenow : '', $page, $get );
		if ( null === $tab ) {
			return;
		}
		$args = [];
		if ( self::CONTENT === $tab || self::SYSTEM === $tab ) {
			$args = 'tools.php' === $pagenow ? EventFilters::clean( $get ) : EventFilters::fromInput( array_diff_key( $get, [ 'page' => 1 ] ) );
			if ( isset( $get['paged'] ) && is_scalar( $get['paged'] ) && 1 === preg_match( '/^[1-9]\d{0,5}$/', (string) $get['paged'] ) ) {
				$args['paged'] = (string) $get['paged'];
			}
		}
		wp_safe_redirect( self::url( $tab, $args ), 301 );
		exit;
	}

	/**
	 * Target tab of a legacy URL (pure), null when the URL is not a legacy one.
	 *
	 * @param string                   $pagenow Admin screen file.
	 * @param string                   $page    Sanitized ?page=.
	 * @param array<string|int, mixed> $get     Unslashed query.
	 */
	public static function legacyTab( string $pagenow, string $page, array $get ): ?string {
		if ( 'admin.php' === $pagenow && isset( self::LEGACY[ $page ] ) ) {
			return self::LEGACY[ $page ];
		}
		if ( 'tools.php' !== $pagenow || ( self::PAGE !== $page && self::LEGACY_PAGE !== $page ) ) {
			return null;
		}
		$tab = $get['tab'] ?? '';
		if ( 'events' === $tab ) {
			$filters = EventFilters::clean( $get );
			return isset( $filters['category'] ) && Scope::SYSTEM === Scope::of( $filters['category'] ) ? self::SYSTEM : self::CONTENT;
		}
		if ( self::LEGACY_PAGE === $page ) {
			return is_string( $tab ) && in_array( $tab, [ self::CONTENT, self::SYSTEM, self::STATUS, self::SETTINGS ], true ) ? $tab : self::CONTENT;
		}
		return null;
	}

	/**
	 * Stylesheet and scripts on Tools → Noformat Logbook only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( string $hook ): void {
		if ( '' === $this->hook || $hook !== $this->hook ) {
			return;
		}
		self::enqueueStyle();
		wp_enqueue_script( 'nflog-admin', plugins_url( 'assets/admin.js', NOFORMAT_LOGGER_FILE ), [], NOFORMAT_LOGGER_VERSION, [ 'in_footer' => true ] );
		if ( self::SYSTEM === $this->tab ) {
			wp_enqueue_script( 'nflog-system-log', plugins_url( 'assets/system-log.js', NOFORMAT_LOGGER_FILE ), [], NOFORMAT_LOGGER_VERSION, [ 'in_footer' => true ] );
		}
	}

	/**
	 * The shared stylesheet (also used by the dashboard widget).
	 */
	public static function enqueueStyle(): void {
		wp_enqueue_style( 'nflog-admin', plugins_url( 'assets/admin.css', NOFORMAT_LOGGER_FILE ), [], NOFORMAT_LOGGER_VERSION );
	}

	/**
	 * The tab bar (escaped HTML).
	 *
	 * @param string       $current Current tab.
	 * @param list<string> $visible Visible tabs.
	 */
	public static function tabBar( string $current, array $visible ): string {
		$labels = self::labels();
		$html   = '<nav class="nflog-tabs" aria-label="' . esc_attr__( 'Noformat Logbook', 'nf-logbook' ) . '">';
		$html  .= '<span class="nflog-tabs__brand">' . self::brandIcon() . '<span>' . esc_html__( 'Noformat Logbook', 'nf-logbook' ) . '</span></span>';
		$html  .= '<span class="nflog-tabs__list">';
		foreach ( $visible as $tab ) {
			$active = $tab === $current;
			$html  .= '<a class="nflog-tab' . ( $active ? ' is-active' : '' ) . '" href="' . esc_url( self::url( $tab ) ) . '"' . ( $active ? ' aria-current="page"' : '' ) . '>' . esc_html( $labels[ $tab ] ?? $tab ) . '</a>';
		}
		// Admin notices are moved after .wp-header-end: between the bar and the tab's header, never inside it.
		return $html . '</span></nav><hr class="wp-header-end">';
	}

	/**
	 * The plugin icon inline, in currentColor (empty when the asset is missing).
	 */
	private static function brandIcon(): string {
		$svg = self::iconSvg();
		if ( '' === $svg ) {
			return '';
		}
		$svg = str_replace( [ 'fill="black"', 'fill="#000"', 'fill="#000000"' ], 'fill="currentColor"', $svg );
		return (string) preg_replace( '/^<svg\b/', '<svg class="nflog-tabs__icon" width="18" height="18" aria-hidden="true" focusable="false"', $svg );
	}

	/**
	 * Page callback: tab bar and the tab.
	 */
	public function render(): void {
		$visible = Access::tabs( $this->plugin->config() );
		$tab     = '' !== $this->tab ? $this->tab : self::resolveTab( self::requestedTab(), $visible );
		if ( null === $tab ) {
			return;
		}
		$bar = self::tabBar( $tab, $visible );
		match ( $tab ) {
			self::CONTENT  => $this->contentPage()->render( $bar ),
			self::SYSTEM   => $this->systemPage()->render( $bar ),
			self::STATUS   => $this->statusPage()->render( $bar ),
			default        => $this->settingsPage()->render( $bar ),
		};
	}

	/**
	 * Content log page object.
	 */
	public function contentPage(): ContentLogPage {
		return $this->content ??= new ContentLogPage( $this->plugin );
	}

	/**
	 * System log page object.
	 */
	public function systemPage(): SystemLogPage {
		return $this->system ??= new SystemLogPage( $this->plugin );
	}

	/**
	 * Status page object.
	 */
	public function statusPage(): StatusPage {
		return $this->status ??= new StatusPage( $this->plugin );
	}

	/**
	 * Settings page object.
	 */
	public function settingsPage(): SettingsPage {
		return $this->settings ??= new SettingsPage( $this->plugin );
	}
}
